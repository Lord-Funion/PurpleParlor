<?php

declare(strict_types=1);

namespace App\Security;

use App\Database\Database;

final class RateLimiter
{
    public function __construct(private readonly Database $database, private readonly string $appKey)
    {
    }

    public function allow(string $action, string $subject, int $maxAttempts, int $windowSeconds): bool
    {
        return $this->hit($action, $subject, $maxAttempts, $windowSeconds)['allowed'];
    }

    /** @return array{allowed:bool,remaining:int,retry_after:int} */
    public function hit(string $action, string $subject, int $maxAttempts, int $windowSeconds): array
    {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $key = hash_hmac('sha256', $action . "\0" . $subject, $this->appKey);
        $nowText = gmdate('Y-m-d H:i:s');
        $expiresText = gmdate('Y-m-d H:i:s', time() + $windowSeconds);
        $sql = $this->database->driver() === 'mysql'
            ? 'INSERT IGNORE INTO rate_limits (bucket_key, hits, window_started_at, expires_at) VALUES (:key, 0, :started, :expires)'
            : 'INSERT OR IGNORE INTO rate_limits (bucket_key, hits, window_started_at, expires_at) VALUES (:key, 0, :started, :expires)';
        $this->database->execute($sql, ['key' => $key, 'started' => $nowText, 'expires' => $expiresText]);
        return $this->database->transaction(function (Database $db) use ($key, $maxAttempts, $windowSeconds): array {
            $row = $db->fetchOne($db->forUpdate('SELECT hits, window_started_at, expires_at FROM rate_limits WHERE bucket_key = :key'), ['key' => $key]);
            $now = time();
            if ($row === null || strtotime((string) $row['expires_at']) <= $now) {
                $started = gmdate('Y-m-d H:i:s', $now);
                $expires = gmdate('Y-m-d H:i:s', $now + $windowSeconds);
                if ($row === null) {
                    $db->execute('INSERT INTO rate_limits (bucket_key, hits, window_started_at, expires_at) VALUES (:key, 1, :started, :expires)', ['key' => $key, 'started' => $started, 'expires' => $expires]);
                } else {
                    $db->execute('UPDATE rate_limits SET hits = 1, window_started_at = :started, expires_at = :expires WHERE bucket_key = :key', ['key' => $key, 'started' => $started, 'expires' => $expires]);
                }
                return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after' => 0];
            }
            $hits = (int) $row['hits'];
            if ($hits >= $maxAttempts) {
                return ['allowed' => false, 'remaining' => 0, 'retry_after' => max(1, strtotime((string) $row['expires_at']) - $now)];
            }
            $db->execute('UPDATE rate_limits SET hits = hits + 1 WHERE bucket_key = :key', ['key' => $key]);
            return ['allowed' => true, 'remaining' => max(0, $maxAttempts - $hits - 1), 'retry_after' => 0];
        });
    }

    public function clear(string $action, string $subject): void
    {
        $key = hash_hmac('sha256', $action . "\0" . $subject, $this->appKey);
        $this->database->execute('DELETE FROM rate_limits WHERE bucket_key = :key', ['key' => $key]);
    }
}
