<?php

declare(strict_types=1);

namespace App\Payments;

use App\Database\Database;
use App\Services\AuditService;
use App\Services\EntitlementService;
use DomainException;
use RuntimeException;
use Throwable;

/**
 * Adult Owner provider-refund workflow. A local review request must reach
 * provider_action_required before this service can reserve a deterministic,
 * retry-safe provider operation.
 */
final class RefundExecutionService
{
    /** @var list<string> */
    private const CONFIRMED = ['refunded', 'completed', 'succeeded', 'success', 'approved'];
    /** @var list<string> */
    private const PENDING = ['pending', 'processing', 'requested', 'submitted'];

    public function __construct(
        private readonly Database $database,
        private readonly PaymentProviderFactory $providers,
        private readonly AuditService $adminAudit,
        private readonly PaymentAuditService $paymentAudit,
        private readonly EntitlementService $entitlements,
    ) {
    }

    /** @return array{status:string,duplicate:bool,full_refund:bool,provider_status:string} */
    public function execute(
        int $actorId,
        int $refundRequestId,
        string $confirmation,
        string $reason,
        ?string $ipHash = null,
    ): array {
        if ($actorId <= 0 || $refundRequestId <= 0) {
            throw new DomainException('Select a valid reviewed refund request.');
        }
        $expected = 'REFUND REQUEST #' . $refundRequestId;
        if (!hash_equals($expected, trim($confirmation))) {
            throw new DomainException('Type the exact refund request confirmation shown on the form.');
        }
        $reason = $this->reason($reason);
        $reservation = $this->reserve($actorId, $refundRequestId, $reason, $ipHash);
        if (($reservation['duplicate'] ?? false) === true) {
            return [
                'status' => 'confirmed', 'duplicate' => true,
                'full_refund' => (bool) ($reservation['full_refund'] ?? false),
                'provider_status' => (string) ($reservation['provider_status'] ?? 'refunded'),
            ];
        }

        $providerResult = null;
        try {
            $providerResult = $this->providers->make((string) $reservation['provider'])->refundPayment(
                (string) $reservation['payment_external_id'],
                (int) $reservation['amount_cents'],
                (string) $reservation['idempotency_key'],
            );
        } catch (Throwable $exception) {
            $this->markAmbiguous($actorId, $reservation, null, null, $reason, $ipHash);
            throw new RuntimeException('The provider outcome could not be confirmed safely.', 0, $exception);
        }

        if (!$providerResult->successful) {
            $this->markFailed($actorId, $reservation, $reason, $ipHash);
            throw new DomainException('The provider did not confirm the refund. No local payment or entitlement state was changed.');
        }
        $providerRefundId = trim((string) ($providerResult->externalId ?? ''));
        $providerStatus = strtolower(trim($providerResult->status));
        if (preg_match('/^[A-Za-z0-9_-]{3,191}$/', $providerRefundId) !== 1
            || (!in_array($providerStatus, self::CONFIRMED, true) && !in_array($providerStatus, self::PENDING, true))) {
            $this->markAmbiguous($actorId, $reservation, $providerRefundId === '' ? null : $providerRefundId,
                $providerStatus === '' ? null : $providerStatus, $reason, $ipHash);
            throw new RuntimeException('The provider response could not be confirmed safely.');
        }

        try {
            return $this->finalize($actorId, $reservation, $providerRefundId, $providerStatus, $reason, $ipHash);
        } catch (Throwable $exception) {
            // The provider may have accepted the request even when the local
            // commit failed. Preserve the same key and known reference so a
            // retry can reconcile without creating a second provider refund.
            $this->markAmbiguous($actorId, $reservation, $providerRefundId, $providerStatus, $reason, $ipHash);
            throw new RuntimeException('The provider outcome requires safe reconciliation.', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function reserve(int $actorId, int $requestId, string $reason, ?string $ipHash): array
    {
        return $this->database->transaction(function (Database $db) use ($actorId, $requestId, $reason, $ipHash): array {
            $request = $db->fetchOne($db->forUpdate(
                'SELECT rr.id, rr.payment_id, rr.requested_amount_cents, rr.status AS request_status,
                        p.user_id, p.product_id, p.subscription_id, p.provider, p.external_id AS payment_external_id,
                        p.status AS payment_status, p.amount_cents AS payment_amount_cents
                 FROM refund_requests rr INNER JOIN payments p ON p.id = rr.payment_id WHERE rr.id = :id'
            ), ['id' => $requestId]);
            if ($request === null) {
                throw new DomainException('The reviewed refund request was not found.');
            }
            $execution = $db->fetchOne($db->forUpdate('SELECT * FROM refund_executions WHERE refund_request_id = :request'), ['request' => $requestId]);
            if ($execution !== null && (string) $execution['status'] === 'confirmed') {
                return array_replace($request, $execution, [
                    'refund_request_id' => $requestId,
                    'execution_id' => (int) $execution['id'],
                    'duplicate' => true,
                    'full_refund' => $this->confirmedRefundTotal((int) $request['payment_id']) >= (int) $request['payment_amount_cents'],
                ]);
            }
            if ((string) $request['request_status'] !== 'provider_action_required') {
                throw new DomainException('The internal refund request must reach provider action required before submission.');
            }
            if (!in_array((string) $request['provider'], ['demo', 'paypal', 'square'], true)) {
                throw new DomainException('That payment provider is not supported by the reviewed refund workflow.');
            }
            if ((string) $request['payment_status'] !== 'completed') {
                throw new DomainException('Only a completed payment may enter provider refund execution.');
            }
            $amount = (int) $request['requested_amount_cents'];
            $paymentAmount = (int) $request['payment_amount_cents'];
            if ($amount <= 0 || $amount > $paymentAmount) {
                throw new DomainException('The reviewed amount is outside the original payment amount.');
            }

            $currentProviderReference = $execution === null ? '' : (string) ($execution['provider_refund_id'] ?? '');
            $refundParams = ['payment' => $request['payment_id']];
            $exclude = '';
            if ($currentProviderReference !== '') {
                $exclude = ' AND external_id <> :current_external';
                $refundParams['current_external'] = $currentProviderReference;
            }
            $recorded = $db->fetchOne(
                "SELECT COALESCE(SUM(amount_cents), 0) AS aggregate FROM refunds
                 WHERE payment_id = :payment AND status NOT IN ('failed','canceled','cancelled'){$exclude}",
                $refundParams,
            );
            $reserved = $db->fetchOne(
                "SELECT COALESCE(SUM(amount_cents), 0) AS aggregate FROM refund_executions
                 WHERE payment_id = :payment AND refund_request_id <> :request AND status IN ('processing','ambiguous')",
                ['payment' => $request['payment_id'], 'request' => $requestId],
            );
            if ((int) ($recorded['aggregate'] ?? 0) + (int) ($reserved['aggregate'] ?? 0) + $amount > $paymentAmount) {
                throw new DomainException('This submission would exceed the original payment after recorded or ambiguous refunds.');
            }

            $now = gmdate('Y-m-d H:i:s');
            $idempotency = 'admin-refund-' . substr(hash('sha256', implode('|', [
                $requestId, $request['payment_id'], $request['provider'], $request['payment_external_id'], $amount,
            ])), 0, 48);
            if ($execution === null) {
                $executionId = $db->insert("INSERT INTO refund_executions
                    (refund_request_id, payment_id, provider, idempotency_key, amount_cents, status, attempt_count, last_attempt_at, created_at, updated_at)
                    VALUES (:request, :payment, :provider, :key, :amount, 'processing', 1, :attempted, :created, :updated)", [
                    'request' => $requestId, 'payment' => $request['payment_id'], 'provider' => $request['provider'],
                    'key' => $idempotency, 'amount' => $amount, 'attempted' => $now, 'created' => $now, 'updated' => $now,
                ]);
                $execution = ['id' => $executionId, 'idempotency_key' => $idempotency, 'attempt_count' => 1, 'status' => 'processing'];
            } else {
                if (!hash_equals((string) $execution['idempotency_key'], $idempotency)
                    || (int) $execution['payment_id'] !== (int) $request['payment_id']
                    || (int) $execution['amount_cents'] !== $amount) {
                    throw new RuntimeException('Refund execution reservation integrity failed.');
                }
                if ((string) $execution['status'] === 'processing'
                    && strtotime((string) $execution['last_attempt_at']) > time() - 120) {
                    throw new DomainException('This refund submission is already being processed. Wait before retrying.');
                }
                $db->execute("UPDATE refund_executions SET status = 'processing', attempt_count = attempt_count + 1,
                    last_attempt_at = :attempted, updated_at = :updated WHERE id = :id", [
                    'attempted' => $now, 'updated' => $now, 'id' => $execution['id'],
                ]);
                $execution['attempt_count'] = (int) $execution['attempt_count'] + 1;
                $execution['status'] = 'processing';
            }
            $snapshot = [
                'refund_request_id' => $requestId, 'payment_id' => (int) $request['payment_id'],
                'provider' => (string) $request['provider'], 'amount_cents' => $amount,
                'attempt' => (int) $execution['attempt_count'], 'execution_status' => 'processing',
            ];
            $this->adminAudit->record($actorId, 'refund.provider_submission_started', 'refund_request', (string) $requestId,
                null, $snapshot, $reason, $ipHash);
            $this->paymentAudit->record('refund.provider_submission_started', 'refund_request', (string) $requestId, $snapshot, $actorId);
            return array_replace($request, $execution, $snapshot, [
                'refund_request_id' => $requestId, 'execution_id' => (int) $execution['id'],
                'duplicate' => false, 'idempotency_key' => $idempotency,
            ]);
        });
    }

    /** @param array<string,mixed> $reservation @return array{status:string,duplicate:bool,full_refund:bool,provider_status:string} */
    private function finalize(int $actorId, array $reservation, string $providerRefundId, string $providerStatus, string $reason, ?string $ipHash): array
    {
        return $this->database->transaction(function (Database $db) use ($actorId, $reservation, $providerRefundId, $providerStatus, $reason, $ipHash): array {
            $execution = $db->fetchOne($db->forUpdate('SELECT * FROM refund_executions WHERE id = :id'), ['id' => $reservation['execution_id']]);
            $payment = $db->fetchOne($db->forUpdate('SELECT * FROM payments WHERE id = :id'), ['id' => $reservation['payment_id']]);
            if ($execution === null || $payment === null) {
                throw new RuntimeException('Reserved refund records could not be locked.');
            }
            if ((string) $execution['status'] === 'confirmed') {
                return [
                    'status' => 'confirmed', 'duplicate' => true,
                    'full_refund' => $this->confirmedRefundTotal((int) $payment['id']) >= (int) $payment['amount_cents'],
                    'provider_status' => (string) ($execution['provider_status'] ?? 'refunded'),
                ];
            }
            $existingRefund = $db->fetchOne('SELECT * FROM refunds WHERE provider = :provider AND external_id = :external', [
                'provider' => $reservation['provider'], 'external' => $providerRefundId,
            ]);
            $storedStatus = in_array($providerStatus, self::CONFIRMED, true) ? 'refunded' : 'pending';
            $now = gmdate('Y-m-d H:i:s');
            if ($existingRefund === null) {
                $db->execute('INSERT INTO refunds (payment_id, provider, external_id, amount_cents, status, reason, created_at, updated_at)
                    VALUES (:payment, :provider, :external, :amount, :status, :reason, :created, :updated)', [
                    'payment' => $payment['id'], 'provider' => $reservation['provider'], 'external' => $providerRefundId,
                    'amount' => $reservation['amount_cents'], 'status' => $storedStatus,
                    'reason' => 'approved_refund_request_' . $reservation['refund_request_id'], 'created' => $now, 'updated' => $now,
                ]);
            } elseif ((int) $existingRefund['payment_id'] !== (int) $payment['id']
                || (int) $existingRefund['amount_cents'] !== (int) $reservation['amount_cents']) {
                throw new RuntimeException('Provider refund reference conflicts with a local refund record.');
            } else {
                $db->execute('UPDATE refunds SET status = :status, updated_at = :updated WHERE id = :id', [
                    'status' => $storedStatus, 'updated' => $now, 'id' => $existingRefund['id'],
                ]);
            }

            $confirmed = $storedStatus === 'refunded';
            $executionStatus = $confirmed ? 'confirmed' : 'pending_confirmation';
            $db->execute('UPDATE refund_executions SET status = :status, provider_refund_id = :external,
                provider_status = :provider_status, confirmed_at = :confirmed, updated_at = :updated WHERE id = :id', [
                'status' => $executionStatus, 'external' => $providerRefundId, 'provider_status' => $providerStatus,
                'confirmed' => $confirmed ? $now : null, 'updated' => $now, 'id' => $execution['id'],
            ]);
            $fullRefund = false;
            if ($confirmed) {
                $total = $this->confirmedRefundTotal((int) $payment['id']);
                if ($total > (int) $payment['amount_cents']) {
                    throw new RuntimeException('Confirmed provider refunds exceed the local payment total.');
                }
                $fullRefund = $total === (int) $payment['amount_cents'];
                if ($fullRefund) {
                    $this->applyFullRefund($payment, $now);
                }
                $db->execute("UPDATE refund_requests SET status = 'closed', resolution_note = COALESCE(resolution_note, :note),
                    updated_by = :actor, updated_at = :updated WHERE id = :id", [
                    'note' => 'Provider refund confirmed.', 'actor' => $actorId, 'updated' => $now,
                    'id' => $reservation['refund_request_id'],
                ]);
            }
            $snapshot = [
                'refund_request_id' => (int) $reservation['refund_request_id'], 'payment_id' => (int) $payment['id'],
                'provider' => (string) $reservation['provider'], 'amount_cents' => (int) $reservation['amount_cents'],
                'execution_status' => $executionStatus, 'provider_status' => $providerStatus,
                'provider_refund_id' => $providerRefundId, 'full_refund' => $fullRefund,
            ];
            $this->adminAudit->record($actorId, 'refund.provider_submission_' . ($confirmed ? 'confirmed' : 'pending'),
                'refund_request', (string) $reservation['refund_request_id'], ['execution_status' => 'processing'], $snapshot, $reason, $ipHash);
            $this->paymentAudit->record('refund.provider_submission_' . ($confirmed ? 'confirmed' : 'pending'),
                'refund_request', (string) $reservation['refund_request_id'], $snapshot, $actorId);
            return ['status' => $executionStatus, 'duplicate' => false, 'full_refund' => $fullRefund, 'provider_status' => $providerStatus];
        });
    }

    /** @param array<string,mixed> $payment */
    private function applyFullRefund(array $payment, string $now): void
    {
        $this->database->execute("UPDATE payments SET status = 'refunded', updated_at = :updated WHERE id = :id", [
            'updated' => $now, 'id' => $payment['id'],
        ]);
        if ($payment['product_id'] !== null) {
            $this->entitlements->revokeSource((int) $payment['user_id'], 'purchase', (string) $payment['id'], 'payment_refunded');
        }
        if ($payment['subscription_id'] !== null) {
            $this->database->execute("UPDATE subscriptions SET status = 'refunded', updated_at = :updated WHERE id = :id", [
                'updated' => $now, 'id' => $payment['subscription_id'],
            ]);
            $this->entitlements->revokeSource((int) $payment['user_id'], 'subscription', (string) $payment['subscription_id'], 'subscription_refunded');
        }
    }

    /** @param array<string,mixed> $reservation */
    private function markAmbiguous(int $actorId, array $reservation, ?string $providerRefundId, ?string $providerStatus, string $reason, ?string $ipHash): void
    {
        try {
            $this->database->transaction(function () use ($actorId, $reservation, $providerRefundId, $providerStatus, $reason, $ipHash): void {
                $this->database->execute("UPDATE refund_executions SET status = 'ambiguous', provider_refund_id = COALESCE(:external, provider_refund_id),
                    provider_status = COALESCE(:provider_status, provider_status), updated_at = :updated WHERE id = :id AND status <> 'confirmed'", [
                    'external' => $providerRefundId, 'provider_status' => $providerStatus,
                    'updated' => gmdate('Y-m-d H:i:s'), 'id' => $reservation['execution_id'],
                ]);
                $snapshot = [
                    'refund_request_id' => (int) $reservation['refund_request_id'], 'payment_id' => (int) $reservation['payment_id'],
                    'provider' => (string) $reservation['provider'], 'amount_cents' => (int) $reservation['amount_cents'],
                    'execution_status' => 'ambiguous', 'provider_reference_received' => $providerRefundId !== null,
                ];
                $this->adminAudit->record($actorId, 'refund.provider_submission_ambiguous', 'refund_request',
                    (string) $reservation['refund_request_id'], ['execution_status' => 'processing'], $snapshot, $reason, $ipHash);
                $this->paymentAudit->record('refund.provider_submission_ambiguous', 'refund_request',
                    (string) $reservation['refund_request_id'], $snapshot, $actorId);
            });
        } catch (Throwable) {
            // Preserve the original generic failure. A processing reservation
            // still prevents a second non-idempotent operation until timeout.
        }
    }

    /** @param array<string,mixed> $reservation */
    private function markFailed(int $actorId, array $reservation, string $reason, ?string $ipHash): void
    {
        $this->database->transaction(function () use ($actorId, $reservation, $reason, $ipHash): void {
            $this->database->execute("UPDATE refund_executions SET status = 'failed', updated_at = :updated WHERE id = :id AND status <> 'confirmed'", [
                'updated' => gmdate('Y-m-d H:i:s'), 'id' => $reservation['execution_id'],
            ]);
            $snapshot = [
                'refund_request_id' => (int) $reservation['refund_request_id'], 'payment_id' => (int) $reservation['payment_id'],
                'provider' => (string) $reservation['provider'], 'amount_cents' => (int) $reservation['amount_cents'],
                'execution_status' => 'failed',
            ];
            $this->adminAudit->record($actorId, 'refund.provider_submission_failed', 'refund_request',
                (string) $reservation['refund_request_id'], ['execution_status' => 'processing'], $snapshot, $reason, $ipHash);
            $this->paymentAudit->record('refund.provider_submission_failed', 'refund_request',
                (string) $reservation['refund_request_id'], $snapshot, $actorId);
        });
    }

    private function confirmedRefundTotal(int $paymentId): int
    {
        $row = $this->database->fetchOne(
            "SELECT COALESCE(SUM(amount_cents), 0) AS aggregate FROM refunds
             WHERE payment_id = :payment AND status IN ('refunded','completed','succeeded','success','approved')",
            ['payment' => $paymentId],
        );
        return (int) ($row['aggregate'] ?? 0);
    }

    private function reason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason) === 1) {
            throw new DomainException('A plain-text reason between 10 and 500 characters is required.');
        }
        return $reason;
    }
}
