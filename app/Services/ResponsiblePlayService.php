<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use DomainException;

final class ResponsiblePlayService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function configure(int $userId, ?int $reminderMinutes, ?int $dailyLimitMinutes): void
    {
        if (($reminderMinutes !== null && ($reminderMinutes < 10 || $reminderMinutes > 240))
            || ($dailyLimitMinutes !== null && ($dailyLimitMinutes < 10 || $dailyLimitMinutes > 1440))) {
            throw new DomainException('Responsible-play limits are outside the supported range.');
        }
        $this->upsert($userId, $reminderMinutes, $dailyLimitMinutes, null, null);
    }

    public function takeBreak(int $userId, int $hours): void
    {
        if ($hours < 1 || $hours > 24 * 365) {
            throw new DomainException('Break duration is outside the supported range.');
        }
        $existing = $this->get($userId);
        $until = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        if ($existing !== null && $existing['cooldown_until'] !== null && strtotime((string) $existing['cooldown_until']) > strtotime($until)) {
            $until = (string) $existing['cooldown_until'];
        }
        $this->upsert($userId, $existing['session_reminder_minutes'] ?? null, $existing['daily_limit_minutes'] ?? null, $until, $existing['self_excluded_until'] ?? null);
    }

    public function selfExclude(int $userId, int $days): void
    {
        if ($days < 1 || $days > 3650) {
            throw new DomainException('Self-exclusion duration is outside the supported range.');
        }
        $existing = $this->get($userId);
        $until = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        if ($existing !== null && $existing['self_excluded_until'] !== null && strtotime((string) $existing['self_excluded_until']) > strtotime($until)) {
            $until = (string) $existing['self_excluded_until'];
        }
        $this->upsert($userId, $existing['session_reminder_minutes'] ?? null, $existing['daily_limit_minutes'] ?? null, $existing['cooldown_until'] ?? null, $until);
    }

    public function mayPlay(int $userId): bool
    {
        $record = $this->get($userId);
        return $record === null
            || (($record['cooldown_until'] === null || strtotime((string) $record['cooldown_until']) <= time())
                && ($record['self_excluded_until'] === null || strtotime((string) $record['self_excluded_until']) <= time()));
    }

    private function get(int $userId): ?array
    {
        return $this->database->fetchOne('SELECT * FROM responsible_play_controls WHERE user_id = :user', ['user' => $userId]);
    }

    private function upsert(int $userId, ?int $reminder, ?int $daily, ?string $cooldown, ?string $excluded): void
    {
        $params = ['user' => $userId, 'reminder' => $reminder, 'daily' => $daily, 'cooldown' => $cooldown, 'excluded' => $excluded, 'now' => gmdate('Y-m-d H:i:s')];
        $sql = $this->database->driver() === 'mysql'
            ? 'INSERT INTO responsible_play_controls (user_id, session_reminder_minutes, daily_limit_minutes, cooldown_until, self_excluded_until, autoplay_allowed, updated_at)
               VALUES (:user,:reminder,:daily,:cooldown,:excluded,0,:now) ON DUPLICATE KEY UPDATE session_reminder_minutes=VALUES(session_reminder_minutes),daily_limit_minutes=VALUES(daily_limit_minutes),cooldown_until=VALUES(cooldown_until),self_excluded_until=VALUES(self_excluded_until),autoplay_allowed=0,updated_at=VALUES(updated_at)'
            : 'INSERT INTO responsible_play_controls (user_id, session_reminder_minutes, daily_limit_minutes, cooldown_until, self_excluded_until, autoplay_allowed, updated_at)
               VALUES (:user,:reminder,:daily,:cooldown,:excluded,0,:now) ON CONFLICT(user_id) DO UPDATE SET session_reminder_minutes=excluded.session_reminder_minutes,daily_limit_minutes=excluded.daily_limit_minutes,cooldown_until=excluded.cooldown_until,self_excluded_until=excluded.self_excluded_until,autoplay_allowed=0,updated_at=excluded.updated_at';
        $this->database->execute($sql, $params);
    }
}
