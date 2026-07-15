<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class UserSettingsService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string,mixed> */
    public function get(int $userId): array
    {
        $row = $this->database->fetchOne('SELECT settings_json FROM user_settings WHERE user_id = :user', ['user' => $userId]);
        $settings = $row === null ? [] : json_decode((string) $row['settings_json'], true);
        return is_array($settings) ? $settings : [];
    }

    /** @param array<string,mixed> $changes */
    public function update(int $userId, array $changes): array
    {
        $allowed = ['theme','appearance','audio','animation','accessibility','privacy','notifications','confirmations','session_reminders'];
        $filtered = array_intersect_key($changes, array_flip($allowed));
        $settings = array_replace_recursive($this->get($userId), $filtered);
        $encoded = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 65_535) {
            throw new \InvalidArgumentException('Settings payload is too large.');
        }
        $this->database->execute('UPDATE user_settings SET settings_json = :settings, updated_at = :now WHERE user_id = :user',
            ['settings' => $encoded, 'now' => gmdate('Y-m-d H:i:s'), 'user' => $userId]);
        return $settings;
    }
}
