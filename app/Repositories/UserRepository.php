<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Models\User;
use InvalidArgumentException;

final class UserRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(string $email, string $username, string $passwordHash, string $status = 'pending_verification'): User
    {
        $email = trim($email);
        $username = trim($username);
        $normalizedEmail = self::normalizeEmail($email);
        $normalizedUsername = self::normalizeUsername($username);
        $now = gmdate('Y-m-d H:i:s');
        $id = $this->database->insert(
            'INSERT INTO users (email, email_normalized, username, username_normalized, password_hash, status, created_at, updated_at)
             VALUES (:email, :email_normalized, :username, :username_normalized, :password_hash, :status, :created_at, :updated_at)',
            [
                'email' => $email,
                'email_normalized' => $normalizedEmail,
                'username' => $username,
                'username_normalized' => $normalizedUsername,
                'password_hash' => $passwordHash,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $this->database->execute(
            'INSERT INTO user_profiles (user_id, display_name, created_at, updated_at) VALUES (:id, :name, :created_at, :updated_at)',
            ['id' => $id, 'name' => $username, 'created_at' => $now, 'updated_at' => $now],
        );
        $this->database->execute('INSERT INTO user_settings (user_id, settings_json, updated_at) VALUES (:id, :settings, :now)', ['id' => $id, 'settings' => '{}', 'now' => $now]);
        return $this->findById($id) ?? throw new \RuntimeException('New account could not be loaded.');
    }

    public function findById(int $id): ?User
    {
        $row = $this->database->fetchOne('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->database->fetchOne('SELECT * FROM users WHERE email_normalized = :email AND deleted_at IS NULL', ['email' => self::normalizeEmail($email)]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function findByUsername(string $username): ?User
    {
        $row = $this->database->fetchOne('SELECT * FROM users WHERE username_normalized = :username AND deleted_at IS NULL', ['username' => self::normalizeUsername($username)]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function markVerified(int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->database->execute(
            "UPDATE users SET email_verified_at = :verified_at, status = CASE WHEN status = 'pending_verification' THEN 'active' ELSE status END, updated_at = :updated_at WHERE id = :id",
            ['verified_at' => $now, 'updated_at' => $now, 'id' => $userId],
        );
    }

    public function recordSuccessfulLogin(int $userId, string $passwordHash = ''): void
    {
        $params = ['id' => $userId, 'now' => gmdate('Y-m-d H:i:s')];
        $sql = 'UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = :last_login_at, updated_at = :updated_at';
        $params['last_login_at'] = $params['now'];
        $params['updated_at'] = $params['now'];
        unset($params['now']);
        if ($passwordHash !== '') {
            $sql .= ', password_hash = :password_hash';
            $params['password_hash'] = $passwordHash;
        }
        $this->database->execute($sql . ' WHERE id = :id', $params);
    }

    public function recordFailedLogin(int $userId, int $lockAfter = 8, int $lockMinutes = 30): void
    {
        $user = $this->findById($userId);
        if ($user === null) {
            return;
        }
        $count = $user->failedLoginCount + 1;
        $locked = $count >= $lockAfter ? gmdate('Y-m-d H:i:s', time() + ($lockMinutes * 60)) : null;
        $this->database->execute('UPDATE users SET failed_login_count = :count, locked_until = :locked, updated_at = :now WHERE id = :id', ['count' => $count, 'locked' => $locked, 'now' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
    }

    public function updatePassword(int $userId, string $hash): void
    {
        $this->database->execute('UPDATE users SET password_hash = :hash, failed_login_count = 0, locked_until = NULL, updated_at = :now WHERE id = :id', ['hash' => $hash, 'now' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
    }

    public function setPendingEmail(int $userId, string $email): string
    {
        $normalized = self::normalizeEmail($email);
        if ($this->database->fetchOne('SELECT id FROM users WHERE email_normalized = :email AND id <> :id', ['email' => $normalized, 'id' => $userId]) !== null) {
            throw new InvalidArgumentException('That email address cannot be used.');
        }
        $this->database->execute('UPDATE users SET pending_email = :email, updated_at = :now WHERE id = :id', ['email' => trim($email), 'now' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
        return trim($email);
    }

    public function finalizeEmailChange(int $userId, string $email): void
    {
        $normalized = self::normalizeEmail($email);
        $this->database->execute('UPDATE users SET email = :email, email_normalized = :normalized, pending_email = NULL, email_verified_at = :now, updated_at = :now WHERE id = :id',
            ['email' => trim($email), 'normalized' => $normalized, 'now' => gmdate('Y-m-d H:i:s'), 'id' => $userId]);
    }

    public function changeUsername(int $userId, string $username, int $cooldownDays = 30): void
    {
        $user = $this->database->fetchOne('SELECT username_changed_at FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $userId]);
        if ($user === null) {
            throw new InvalidArgumentException('Account was not found.');
        }
        if ($user['username_changed_at'] !== null && strtotime((string) $user['username_changed_at']) > time() - max(1, $cooldownDays) * 86400) {
            throw new InvalidArgumentException('Username is still in its change cooldown period.');
        }
        $normalized = self::normalizeUsername($username);
        $now = gmdate('Y-m-d H:i:s');
        $this->database->execute('UPDATE users SET username = :username, username_normalized = :normalized, username_changed_at = :now, updated_at = :now WHERE id = :id',
            ['username' => trim($username), 'normalized' => $normalized, 'now' => $now, 'id' => $userId]);
        $this->database->execute('UPDATE user_profiles SET display_name = :username, updated_at = :now WHERE user_id = :id', ['username' => trim($username), 'now' => $now, 'id' => $userId]);
    }

    /** @return list<string> */
    public function roles(int $userId): array
    {
        $rows = $this->database->fetchAll('SELECT r.name FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :id ORDER BY r.name', ['id' => $userId]);
        return array_values(array_map(static fn (array $row): string => (string) $row['name'], $rows));
    }

    public function assignRole(int $userId, string $roleName, ?int $grantedBy = null): void
    {
        $role = $this->database->fetchOne('SELECT id FROM roles WHERE name = :name', ['name' => $roleName]);
        if ($role === null) {
            throw new InvalidArgumentException('Unknown role.');
        }
        $sql = $this->database->driver() === 'mysql'
            ? 'INSERT IGNORE INTO user_roles (user_id, role_id, granted_by, created_at) VALUES (:user, :role, :granted, :now)'
            : 'INSERT OR IGNORE INTO user_roles (user_id, role_id, granted_by, created_at) VALUES (:user, :role, :granted, :now)';
        $this->database->execute($sql, ['user' => $userId, 'role' => $role['id'], 'granted' => $grantedBy, 'now' => gmdate('Y-m-d H:i:s')]);
    }

    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new InvalidArgumentException('A valid email address is required.');
        }
        return strtolower($email);
    }

    public static function normalizeUsername(string $username): string
    {
        $username = trim($username);
        $length = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);
        if ($length < 3 || $length > 30 || !preg_match('/^[\pL\pN][\pL\pN_.-]*$/u', $username)) {
            throw new InvalidArgumentException('Username must be 3–30 letters, numbers, periods, underscores, or hyphens.');
        }
        return function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'], (string) $row['email'], (string) $row['username'], (string) $row['password_hash'],
            (string) $row['status'], $row['email_verified_at'] === null ? null : (string) $row['email_verified_at'],
            $row['locked_until'] === null ? null : (string) $row['locked_until'], (int) $row['failed_login_count'],
            $this->roles((int) $row['id']),
        );
    }
}
