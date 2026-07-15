<?php

declare(strict_types=1);

namespace App\Payments;

use App\Database\Database;
use DomainException;

/**
 * Owns provider idempotency keys on the server. One database row represents
 * the current checkout intent for a user/item/provider/environment tuple, so browser
 * resubmits and ambiguous network retries cannot silently create a new charge.
 */
final class CheckoutIntentService
{
    private const LIFETIME_SECONDS = 86400;

    public function __construct(private readonly Database $database)
    {
    }

    public function acquire(int $userId, string $type, string $itemKey, string $billingPeriod, string $provider, string $providerEnvironment): CheckoutIntent
    {
        $type = strtolower(trim($type));
        $itemKey = strtolower(trim($itemKey));
        $billingPeriod = strtolower(trim($billingPeriod));
        $provider = strtolower(trim($provider));
        $providerEnvironment = strtolower(trim($providerEnvironment));
        $this->assertTuple($userId, $type, $itemKey, $billingPeriod, $provider, $providerEnvironment);

        $intentKey = hash('sha256', implode('|', [$userId, $type, $itemKey, $billingPeriod, $provider, $providerEnvironment]));
        return $this->database->transaction(function (Database $db) use ($intentKey, $userId, $type, $itemKey, $billingPeriod, $provider, $providerEnvironment): CheckoutIntent {
            $row = $db->fetchOne($db->forUpdate('SELECT * FROM checkout_intents WHERE intent_key = :intent'), ['intent' => $intentKey]);
            $now = gmdate('Y-m-d H:i:s');
            if ($row === null) {
                $candidate = $this->newIdempotencyKey();
                $sql = $db->driver() === 'mysql'
                    ? 'INSERT IGNORE INTO checkout_intents (intent_key, user_id, checkout_type, item_key, billing_period, provider, provider_environment, idempotency_key, status, expires_at, created_at, updated_at) VALUES (:intent, :user, :type, :item, :period, :provider, :environment, :idempotency, :status, :expires, :now, :now)'
                    : 'INSERT OR IGNORE INTO checkout_intents (intent_key, user_id, checkout_type, item_key, billing_period, provider, provider_environment, idempotency_key, status, expires_at, created_at, updated_at) VALUES (:intent, :user, :type, :item, :period, :provider, :environment, :idempotency, :status, :expires, :now, :now)';
                $db->execute($sql, [
                    'intent' => $intentKey, 'user' => $userId, 'type' => $type, 'item' => $itemKey,
                    'period' => $billingPeriod, 'provider' => $provider, 'environment' => $providerEnvironment, 'idempotency' => $candidate,
                    'status' => 'processing', 'expires' => gmdate('Y-m-d H:i:s', time() + self::LIFETIME_SECONDS), 'now' => $now,
                ]);
                $row = $db->fetchOne($db->forUpdate('SELECT * FROM checkout_intents WHERE intent_key = :intent'), ['intent' => $intentKey]);
                if ($row === null) {
                    throw new DomainException('Checkout could not be safely initialized.');
                }
                return new CheckoutIntent((string) $row['idempotency_key'], (string) $row['status'], !hash_equals($candidate, (string) $row['idempotency_key']));
            }

            $expired = (string) $row['expires_at'] <= $now;
            $retryableTerminal = in_array((string) $row['status'], ['failed', 'canceled'], true);
            $completedTerminal = (string) $row['status'] === 'completed';
            if ($expired && !$retryableTerminal && !$completedTerminal) {
                // Once the provider's normal idempotency-retention window may
                // have elapsed, retrying an unknown outcome could charge twice.
                // Preserve the original key and require provider reconciliation.
                throw new DomainException('This checkout has an unresolved provider outcome. Contact billing support with the request reference before trying again.');
            }
            if ($retryableTerminal || ($expired && $completedTerminal)) {
                $key = $this->newIdempotencyKey();
                $db->execute('UPDATE checkout_intents SET idempotency_key = :idempotency, status = :status, provider_external_id = NULL, terminal_at = NULL, expires_at = :expires, updated_at = :now WHERE intent_key = :intent', [
                    'idempotency' => $key, 'status' => 'processing',
                    'expires' => gmdate('Y-m-d H:i:s', time() + self::LIFETIME_SECONDS),
                    'now' => $now, 'intent' => $intentKey,
                ]);
                return new CheckoutIntent($key, 'processing', false);
            }

            $db->execute('UPDATE checkout_intents SET updated_at = :now WHERE intent_key = :intent', ['now' => $now, 'intent' => $intentKey]);
            return new CheckoutIntent((string) $row['idempotency_key'], (string) $row['status'], true);
        });
    }

    public function recordResult(string $idempotencyKey, CheckoutResult $result): void
    {
        $status = strtolower($result->status);
        $terminal = false;
        if (!$result->successful) {
            $status = str_starts_with($status, 'cancel') ? 'canceled' : 'failed';
            $terminal = true;
        } elseif (in_array($status, ['completed', 'active', 'trialing', 'approved', 'paid'], true)) {
            $status = 'completed';
            $terminal = true;
        } else {
            $status = 'pending';
        }
        $this->database->execute('UPDATE checkout_intents SET status = :status, provider_external_id = :external, terminal_at = :terminal, updated_at = :now WHERE idempotency_key = :idempotency', [
            'status' => $status,
            'external' => $result->externalId,
            'terminal' => $terminal ? gmdate('Y-m-d H:i:s') : null,
            'now' => gmdate('Y-m-d H:i:s'),
            'idempotency' => $idempotencyKey,
        ]);
    }

    public function recordValidationFailure(string $idempotencyKey): void
    {
        $this->database->execute("UPDATE checkout_intents SET status = 'failed', terminal_at = :now, updated_at = :now WHERE idempotency_key = :idempotency", [
            'now' => gmdate('Y-m-d H:i:s'), 'idempotency' => $idempotencyKey,
        ]);
    }

    public function recordAmbiguousFailure(string $idempotencyKey): void
    {
        $this->database->execute("UPDATE checkout_intents SET status = 'ambiguous', terminal_at = NULL, updated_at = :now WHERE idempotency_key = :idempotency", [
            'now' => gmdate('Y-m-d H:i:s'), 'idempotency' => $idempotencyKey,
        ]);
    }

    private function assertTuple(int $userId, string $type, string $itemKey, string $billingPeriod, string $provider, string $providerEnvironment): void
    {
        if ($userId <= 0
            || !in_array($type, ['product', 'subscription'], true)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{1,99}$/', $itemKey)
            || !in_array($provider, ['demo', 'paypal', 'square'], true)
            || !in_array($providerEnvironment, ['demo', 'sandbox', 'live'], true)
            || ($provider === 'demo' && $providerEnvironment !== 'demo')
            || ($provider !== 'demo' && $providerEnvironment === 'demo')
            || ($type === 'subscription' && !in_array($billingPeriod, ['monthly', 'annual'], true))
            || ($type === 'product' && $billingPeriod !== '')) {
            throw new DomainException('Invalid checkout request.');
        }
    }

    private function newIdempotencyKey(): string
    {
        // Provider request DTOs cap this at 45 characters; 128 random bits is
        // ample while the database tuple supplies the durable association.
        return 'checkout:' . bin2hex(random_bytes(16));
    }
}
