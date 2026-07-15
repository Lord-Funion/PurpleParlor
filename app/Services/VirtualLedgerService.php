<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Models\LedgerEntry;
use App\Repositories\LedgerRepository;
use DomainException;
use RuntimeException;

final class VirtualLedgerService
{
    public const COZY_COINS = 'cozy_coins';
    public const PARLOR_STARS = 'parlor_stars';
    private const CURRENCIES = [self::COZY_COINS, self::PARLOR_STARS];
    private const MAX_ABSOLUTE_ADJUSTMENT = 9_000_000_000_000_000;

    public function __construct(
        private readonly Database $database,
        private readonly LedgerRepository $repository,
        private readonly string $integrityKey,
    ) {
        if (strlen($integrityKey) < 32) {
            throw new RuntimeException('A strong APP_KEY is required for ledger integrity metadata.');
        }
    }

    public function balance(int $userId, string $currency): int
    {
        $this->assertCurrency($currency);
        $row = $this->database->fetchOne('SELECT balance FROM virtual_wallets WHERE user_id = :user AND currency = :currency', ['user' => $userId, 'currency' => $currency]);
        return (int) ($row['balance'] ?? 0);
    }

    /**
     * All virtual-currency mutations enter through this transaction-safe, idempotent method.
     * @param array{game_round_id?:int,achievement_id?:int,mission_id?:int,administrator_id?:int,metadata?:array<string,mixed>} $context
     */
    public function apply(int $userId, string $currency, int $amount, string $reasonCode, string $idempotencyKey, array $context = []): LedgerEntry
    {
        $this->assertCurrency($currency);
        if ($userId <= 0 || $amount === 0 || abs($amount) > self::MAX_ABSOLUTE_ADJUSTMENT) {
            throw new DomainException('Invalid virtual-currency adjustment.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{7,190}$/', $idempotencyKey)) {
            throw new DomainException('Idempotency key must be 8–191 safe characters.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,99}$/', $reasonCode)) {
            throw new DomainException('Invalid ledger reason code.');
        }
        $metadata = $context['metadata'] ?? [];
        if (!is_array($metadata)) {
            throw new DomainException('Ledger metadata must be an object.');
        }

        return $this->database->transaction(function () use ($userId, $currency, $amount, $reasonCode, $idempotencyKey, $context, $metadata): LedgerEntry {
            $existing = $this->repository->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                if ((int) $existing['user_id'] !== $userId || (string) $existing['currency'] !== $currency
                    || (int) $existing['amount'] !== $amount || (string) $existing['reason_code'] !== $reasonCode) {
                    throw new DomainException('An idempotency key was reused with different ledger data.');
                }
                return $this->repository->hydrate($existing);
            }
            $this->repository->ensureWallet($userId, $currency);
            $wallet = $this->repository->lockWallet($userId, $currency);
            if ($wallet === null) {
                throw new RuntimeException('Virtual wallet could not be locked.');
            }
            $before = (int) $wallet['balance'];
            if ($amount > 0 && $before > PHP_INT_MAX - $amount) {
                throw new DomainException('Virtual balance would overflow.');
            }
            $after = $before + $amount;
            if ($after < 0) {
                throw new InsufficientVirtualBalance('Insufficient virtual balance.');
            }
            $previous = $this->repository->lastEntryForUpdate($userId, $currency);
            $previousHash = (string) ($previous['entry_hash'] ?? str_repeat('0', 64));
            $createdAt = gmdate('Y-m-d H:i:s');
            $canonical = implode('|', [
                $userId, $currency, $amount, $before, $after, $reasonCode,
                (int) ($context['game_round_id'] ?? 0), (int) ($context['achievement_id'] ?? 0),
                (int) ($context['mission_id'] ?? 0), (int) ($context['administrator_id'] ?? 0),
                $idempotencyKey, $previousHash, $createdAt,
            ]);
            $entryHash = hash_hmac('sha256', $canonical, $this->key());
            if (!$this->repository->updateWallet((int) $wallet['id'], (int) $wallet['version'], $after)) {
                throw new RuntimeException('Concurrent wallet update detected.');
            }
            return $this->repository->insertEntry([
                'user_id' => $userId,
                'currency' => $currency,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason_code' => $reasonCode,
                'related_game_round_id' => $context['game_round_id'] ?? null,
                'related_achievement_id' => $context['achievement_id'] ?? null,
                'related_mission_id' => $context['mission_id'] ?? null,
                'administrator_id' => $context['administrator_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'previous_hash' => $previousHash,
                'entry_hash' => $entryHash,
                'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $createdAt,
            ]);
        });
    }

    public function debitWager(int $userId, int $roundId, int $amount, string $idempotencyKey): LedgerEntry
    {
        if ($amount <= 0) {
            throw new DomainException('Wager must be positive.');
        }
        return $this->apply($userId, self::COZY_COINS, -$amount, 'game.wager', $idempotencyKey, ['game_round_id' => $roundId]);
    }

    public function creditPayout(int $userId, int $roundId, int $amount, string $idempotencyKey): LedgerEntry
    {
        if ($amount <= 0) {
            throw new DomainException('Payout must be positive.');
        }
        return $this->apply($userId, self::COZY_COINS, $amount, 'game.payout', $idempotencyKey, ['game_round_id' => $roundId]);
    }

    /** @return array{checked:int,mismatches:list<array<string,mixed>>,integrity_errors:list<int>} */
    public function reconcile(?int $userId = null): array
    {
        $params = [];
        $where = '';
        if ($userId !== null) {
            $where = ' WHERE user_id = :user';
            $params['user'] = $userId;
        }
        $wallets = $this->database->fetchAll('SELECT user_id, currency, balance FROM virtual_wallets' . $where . ' ORDER BY user_id, currency', $params);
        $mismatches = [];
        $integrityErrors = [];
        foreach ($wallets as $wallet) {
            $entries = $this->database->fetchAll('SELECT * FROM virtual_ledger_entries WHERE user_id = :user AND currency = :currency ORDER BY id', ['user' => $wallet['user_id'], 'currency' => $wallet['currency']]);
            $computed = 0;
            $previousHash = str_repeat('0', 64);
            foreach ($entries as $entry) {
                $canonical = implode('|', [(int) $entry['user_id'], $entry['currency'], (int) $entry['amount'], (int) $entry['balance_before'], (int) $entry['balance_after'], $entry['reason_code'],
                    (int) ($entry['related_game_round_id'] ?? 0), (int) ($entry['related_achievement_id'] ?? 0), (int) ($entry['related_mission_id'] ?? 0), (int) ($entry['administrator_id'] ?? 0),
                    $entry['idempotency_key'], $previousHash, $entry['created_at']]);
                if ((int) $entry['balance_before'] !== $computed || (int) $entry['balance_after'] !== $computed + (int) $entry['amount']
                    || !hash_equals($previousHash, (string) $entry['previous_hash'])
                    || !hash_equals(hash_hmac('sha256', $canonical, $this->key()), (string) $entry['entry_hash'])) {
                    $integrityErrors[] = (int) $entry['id'];
                }
                $computed += (int) $entry['amount'];
                $previousHash = (string) $entry['entry_hash'];
            }
            if ($computed !== (int) $wallet['balance']) {
                $mismatches[] = ['user_id' => (int) $wallet['user_id'], 'currency' => $wallet['currency'], 'stored' => (int) $wallet['balance'], 'computed' => $computed];
            }
        }
        return ['checked' => count($wallets), 'mismatches' => $mismatches, 'integrity_errors' => array_values(array_unique($integrityErrors))];
    }

    private function assertCurrency(string $currency): void
    {
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new DomainException('Unsupported virtual currency.');
        }
    }

    private function key(): string
    {
        if (str_starts_with($this->integrityKey, 'base64:')) {
            $decoded = base64_decode(substr($this->integrityKey, 7), true);
            return $decoded === false ? $this->integrityKey : $decoded;
        }
        return $this->integrityKey;
    }
}
