<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class AnalyticsService
{
    private const EVENTS = ['page_view','game_launch','round_completed','application_error','subscription_conversion','feature_used','performance_timing'];

    public function __construct(private readonly Database $database, private readonly string $appKey, private readonly int $retentionDays = 90)
    {
    }

    public function record(string $eventType, ?int $userId, string $ip, ?string $deviceCategory = null, ?int $durationMs = null, array $metadata = []): void
    {
        if (!in_array($eventType, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unsupported aggregate analytics event.');
        }
        if ($userId !== null && $this->optedOut($userId)) {
            return;
        }
        $deviceCategory = in_array($deviceCategory, ['mobile','tablet','desktop','other'], true) ? $deviceCategory : null;
        $durationMs = $durationMs === null ? null : max(0, min(600_000, $durationMs));
        $safe = [];
        foreach (['page_key','game_slug','feature_key','error_code'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) && preg_match('/^[A-Za-z0-9:._-]{1,100}$/', $metadata[$key])) {
                $safe[$key] = $metadata[$key];
            }
        }
        // The pseudonym rotates daily and is used only for aggregate deduplication, never precise location/fingerprinting.
        $visitorHash = hash_hmac('sha256', gmdate('Y-m-d') . '|' . $this->networkPrefix($ip), $this->key());
        $this->database->execute('INSERT INTO analytics_events (event_type, visitor_hash, user_id, device_category, duration_ms, metadata_json, occurred_at, expires_at)
            VALUES (:type,:visitor,:user,:device,:duration,:metadata,:now,:expires)', [
            'type' => $eventType, 'visitor' => $visitorHash, 'user' => $userId, 'device' => $deviceCategory, 'duration' => $durationMs,
            'metadata' => $safe === [] ? null : json_encode($safe, JSON_THROW_ON_ERROR), 'now' => gmdate('Y-m-d H:i:s'),
            'expires' => gmdate('Y-m-d H:i:s', time() + max(1, $this->retentionDays) * 86400),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function totals(string $fromUtc, string $toUtc): array
    {
        return $this->database->fetchAll('SELECT event_type, COUNT(*) AS total, AVG(duration_ms) AS average_duration_ms FROM analytics_events
            WHERE occurred_at >= :from AND occurred_at < :to GROUP BY event_type ORDER BY event_type', ['from' => $fromUtc, 'to' => $toUtc]);
    }

    private function optedOut(int $userId): bool
    {
        $row = $this->database->fetchOne('SELECT settings_json FROM user_settings WHERE user_id = :user', ['user' => $userId]);
        $settings = $row === null ? [] : json_decode((string) $row['settings_json'], true);
        return is_array($settings) && (($settings['analytics_opt_out'] ?? false) === true
            || ($settings['privacy']['analytics_opt_out'] ?? false) === true);
    }

    private function networkPrefix(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return 'invalid';
        }
        if (strlen($packed) === 4) {
            return bin2hex(substr($packed, 0, 3));
        }
        return bin2hex(substr($packed, 0, 6));
    }

    private function key(): string
    {
        if (str_starts_with($this->appKey, 'base64:')) {
            $decoded = base64_decode(substr($this->appKey, 7), true);
            return $decoded === false ? $this->appKey : $decoded;
        }
        return $this->appKey;
    }
}
