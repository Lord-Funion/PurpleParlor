<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Models\LedgerEntry;

final class LedgerRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string, mixed>|null */
    public function lockWallet(int $userId, string $currency): ?array
    {
        return $this->database->fetchOne(
            $this->database->forUpdate('SELECT id, user_id, currency, balance, version FROM virtual_wallets WHERE user_id = :user AND currency = :currency'),
            ['user' => $userId, 'currency' => $currency],
        );
    }

    public function ensureWallet(int $userId, string $currency): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = $this->database->driver() === 'mysql'
            ? 'INSERT IGNORE INTO virtual_wallets (user_id, currency, balance, version, created_at, updated_at) VALUES (:user, :currency, 0, 0, :created_at, :updated_at)'
            : 'INSERT OR IGNORE INTO virtual_wallets (user_id, currency, balance, version, created_at, updated_at) VALUES (:user, :currency, 0, 0, :created_at, :updated_at)';
        $this->database->execute($sql, ['user' => $userId, 'currency' => $currency, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function updateWallet(int $walletId, int $expectedVersion, int $balance): bool
    {
        return $this->database->execute(
            'UPDATE virtual_wallets SET balance = :balance, version = version + 1, updated_at = :now WHERE id = :id AND version = :version',
            ['balance' => $balance, 'now' => gmdate('Y-m-d H:i:s'), 'id' => $walletId, 'version' => $expectedVersion],
        ) === 1;
    }

    /** @param array<string, mixed> $fields */
    public function insertEntry(array $fields): LedgerEntry
    {
        $id = $this->database->insert(
            'INSERT INTO virtual_ledger_entries
             (user_id, currency, amount, balance_before, balance_after, reason_code, related_game_round_id,
              related_achievement_id, related_mission_id, administrator_id, idempotency_key, previous_hash,
              entry_hash, metadata_json, created_at)
             VALUES (:user_id, :currency, :amount, :balance_before, :balance_after, :reason_code, :related_game_round_id,
              :related_achievement_id, :related_mission_id, :administrator_id, :idempotency_key, :previous_hash,
              :entry_hash, :metadata_json, :created_at)',
            $fields,
        );
        return new LedgerEntry($id, (int) $fields['user_id'], (string) $fields['currency'], (int) $fields['amount'],
            (int) $fields['balance_before'], (int) $fields['balance_after'], (string) $fields['reason_code'],
            (string) $fields['idempotency_key'], (string) $fields['entry_hash'], (string) $fields['created_at']);
    }

    /** @return array<string, mixed>|null */
    public function lastEntryForUpdate(int $userId, string $currency): ?array
    {
        return $this->database->fetchOne($this->database->forUpdate(
            'SELECT * FROM virtual_ledger_entries WHERE user_id = :user AND currency = :currency ORDER BY id DESC LIMIT 1'
        ), ['user' => $userId, 'currency' => $currency]);
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->database->fetchOne('SELECT * FROM virtual_ledger_entries WHERE idempotency_key = :key', ['key' => $key]);
    }

    public function hydrate(array $row): LedgerEntry
    {
        return new LedgerEntry((int) $row['id'], (int) $row['user_id'], (string) $row['currency'], (int) $row['amount'],
            (int) $row['balance_before'], (int) $row['balance_after'], (string) $row['reason_code'],
            (string) $row['idempotency_key'], (string) $row['entry_hash'], (string) $row['created_at']);
    }
}
