<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class GuestConversionService
{
    public function __construct(private readonly Database $database, private readonly string $appKey)
    {
    }

    /** @param array<string,int> $settledRoundsBySlug */
    public function convert(string $serverIssuedGuestToken, int $userId, string $idempotencyKey, array $settledRoundsBySlug = []): void
    {
        if (strlen($serverIssuedGuestToken) < 32 || !preg_match('/^[A-Za-z0-9:._-]{8,191}$/', $idempotencyKey)) {
            throw new \DomainException('Invalid guest conversion request.');
        }
        $guestHash = hash_hmac('sha256', $serverIssuedGuestToken, $this->appKey);
        $this->database->transaction(function (Database $db) use ($guestHash, $userId, $idempotencyKey, $settledRoundsBySlug): void {
            $existing = $db->fetchOne($db->forUpdate('SELECT user_id, idempotency_key FROM guest_conversions WHERE guest_token_hash = :hash OR idempotency_key = :key'), ['hash' => $guestHash, 'key' => $idempotencyKey]);
            if ($existing !== null) {
                if ((int) $existing['user_id'] !== $userId || !hash_equals((string) $existing['idempotency_key'], $idempotencyKey)) {
                    throw new \DomainException('Guest state has already been converted.');
                }
                return;
            }
            // Client-side guest balances are deliberately ignored. Registration already created the one fixed
            // account grant, so conversion never duplicates rewards or trusts local progress as currency.
            $db->execute('INSERT INTO guest_conversions (guest_token_hash, user_id, idempotency_key, converted_at) VALUES (:hash, :user, :key, :now)',
                ['hash' => $guestHash, 'user' => $userId, 'key' => $idempotencyKey, 'now' => gmdate('Y-m-d H:i:s')]);
            foreach ($settledRoundsBySlug as $slug => $rounds) {
                if (!is_string($slug) || !preg_match('/^[a-z0-9-]{2,100}$/', $slug) || $rounds < 1 || $rounds > 50) {
                    continue;
                }
                $game = $db->fetchOne('SELECT id FROM game_definitions WHERE slug = :slug AND active = 1', ['slug' => $slug]);
                if ($game === null) {
                    continue;
                }
                $existingStat = $db->fetchOne('SELECT id FROM player_statistics WHERE user_id = :user AND game_id = :game AND stat_key = :key', [
                    'user' => $userId, 'game' => $game['id'], 'key' => 'rounds',
                ]);
                if ($existingStat === null) {
                    $db->execute('INSERT INTO player_statistics (user_id, game_id, stat_key, stat_value, updated_at) VALUES (:user, :game, :key, :value, :updated)', [
                        'user' => $userId, 'game' => $game['id'], 'key' => 'rounds', 'value' => $rounds, 'updated' => gmdate('Y-m-d H:i:s'),
                    ]);
                } else {
                    $db->execute('UPDATE player_statistics SET stat_value = stat_value + :value, updated_at = :updated WHERE id = :id', [
                        'value' => $rounds, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $existingStat['id'],
                    ]);
                }
            }
        });
    }
}
