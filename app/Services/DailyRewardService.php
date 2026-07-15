<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Models\LedgerEntry;

final class DailyRewardService
{
    public function __construct(private readonly Database $database, private readonly VirtualLedgerService $ledger)
    {
    }

    public function claim(int $userId, int $amount = 1000): LedgerEntry
    {
        if ($amount <= 0 || $amount > 100_000) {
            throw new \DomainException('Daily reward amount is outside the safe configured range.');
        }
        $date = gmdate('Y-m-d');
        return $this->database->transaction(function (Database $db) use ($userId, $amount, $date): LedgerEntry {
            $existing = $db->fetchOne($db->forUpdate('SELECT id FROM daily_reward_claims WHERE user_id = :user AND reward_date = :date'), ['user' => $userId, 'date' => $date]);
            $idempotency = 'daily:' . $userId . ':' . $date;
            if ($existing !== null) {
                return $this->ledger->apply($userId, VirtualLedgerService::COZY_COINS, $amount, 'reward.daily', $idempotency);
            }
            $entry = $this->ledger->apply($userId, VirtualLedgerService::COZY_COINS, $amount, 'reward.daily', $idempotency);
            $db->execute('INSERT INTO daily_reward_claims (user_id, reward_date, amount, idempotency_key, claimed_at) VALUES (:user, :date, :amount, :key, :now)',
                ['user' => $userId, 'date' => $date, 'amount' => $amount, 'key' => $idempotency, 'now' => gmdate('Y-m-d H:i:s')]);
            return $entry;
        });
    }
}
