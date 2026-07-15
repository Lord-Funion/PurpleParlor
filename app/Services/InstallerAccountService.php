<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Repositories\LedgerRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use DomainException;

final class InstallerAccountService
{
    private UserRepository $users;
    private PasswordHasher $passwords;
    private VirtualLedgerService $ledger;

    public function __construct(private readonly Database $database, string $integrityKey)
    {
        $this->users = new UserRepository($database);
        $this->passwords = new PasswordHasher();
        $this->ledger = new VirtualLedgerService($database, new LedgerRepository($database), $integrityKey);
    }

    /**
     * @param array{
     *   owner_name:string,
     *   owner_email:string,
     *   owner_password:string,
     *   owner_password_confirmation:string,
     *   developer_name:string,
     *   developer_email:string,
     *   developer_password:string,
     *   developer_password_confirmation:string
     * } $input
     * @return array{owner_id:int,developer_id:int}
     */
    public function createAdministrators(array $input): array
    {
        $accounts = [];
        foreach ([['owner', 'adult_owner'], ['developer', 'developer_admin']] as [$prefix, $role]) {
            $name = $this->validatedName($input[$prefix . '_name'] ?? '');
            $email = trim($input[$prefix . '_email'] ?? '');
            $password = $input[$prefix . '_password'] ?? '';
            $confirmation = $input[$prefix . '_password_confirmation'] ?? '';

            if (!hash_equals($password, $confirmation)) {
                throw new DomainException('Each administrator password and confirmation must match.');
            }
            $this->passwords->assertAcceptable($password);

            $accounts[$prefix] = [
                'name' => $name,
                'email' => $email,
                'email_normalized' => UserRepository::normalizeEmail($email),
                'password' => $password,
                'role' => $role,
            ];
        }

        if (hash_equals($accounts['owner']['email_normalized'], $accounts['developer']['email_normalized'])) {
            throw new DomainException('The Adult Owner and Developer Administrator must use different email addresses.');
        }
        if (hash_equals($accounts['owner']['password'], $accounts['developer']['password'])) {
            throw new DomainException('The Adult Owner and Developer Administrator must use different passwords.');
        }

        foreach ($accounts as $account) {
            if ($this->database->fetchOne('SELECT 1 FROM users WHERE email_normalized = :email', ['email' => $account['email_normalized']]) !== null) {
                throw new DomainException('Each administrator must use an email address not already assigned to an account.');
            }
        }

        $reservedUsernames = [];
        foreach ($accounts as $prefix => &$account) {
            $username = $this->availableUsername($account['name'], $prefix, $reservedUsernames);
            $normalized = UserRepository::normalizeUsername($username);
            if (isset($reservedUsernames[$normalized])) {
                throw new DomainException('The administrator usernames must be different.');
            }
            $reservedUsernames[$normalized] = true;
            $account['username'] = $username;
            $account['username_normalized'] = $normalized;
        }
        unset($account);

        if (count($reservedUsernames) !== count($accounts)) {
            throw new DomainException('The administrator usernames must be different.');
        }

        // Hash only after every submitted field and cross-account invariant passes.
        foreach ($accounts as &$account) {
            $account['password_hash'] = $this->passwords->hash($account['password']);
            unset($account['password']);
        }
        unset($account);

        return $this->database->transaction(function () use ($accounts): array {
            $ids = [];
            foreach ($accounts as $prefix => $account) {
                $user = $this->users->create($account['email'], $account['username'], $account['password_hash'], 'active');
                $this->users->markVerified($user->id);
                $this->users->assignRole($user->id, $account['role'], $prefix === 'developer' ? $ids['owner'] : null);
                $this->database->execute(
                    'UPDATE user_profiles SET display_name = :name, is_public = 0, stats_public = 0, updated_at = :updated WHERE user_id = :user',
                    ['name' => $account['name'], 'updated' => gmdate('Y-m-d H:i:s'), 'user' => $user->id],
                );
                $this->ledger->apply($user->id, VirtualLedgerService::COZY_COINS, 10_000, 'account.initial_grant', 'installer:coins:' . $user->id);
                $this->ledger->apply($user->id, VirtualLedgerService::PARLOR_STARS, 100, 'account.initial_grant', 'installer:stars:' . $user->id);
                $ids[$prefix] = $user->id;
            }

            return ['owner_id' => $ids['owner'], 'developer_id' => $ids['developer']];
        });
    }

    private function validatedName(string $name): string
    {
        $name = trim($name);
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $validEncoding = !function_exists('mb_check_encoding') || mb_check_encoding($name, 'UTF-8');
        if (!$validEncoding || $length < 1 || $length > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new DomainException('Administrator names must be between 1 and 80 characters without control characters.');
        }
        return $name;
    }

    /** @param array<string,bool> $reserved */
    private function availableUsername(string $name, string $fallback, array $reserved): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $name));
        $base = trim(substr($base, 0, 24), '_') ?: $fallback . '_admin';
        if (strlen($base) < 3) {
            $base .= '_admin';
        }

        for ($attempt = 0; ; $attempt++) {
            $suffix = $attempt === 0 ? '' : '_' . $attempt;
            $candidate = substr($base, 0, 30 - strlen($suffix)) . $suffix;
            $normalized = UserRepository::normalizeUsername($candidate);
            $alreadyStored = $this->database->fetchOne(
                'SELECT 1 FROM users WHERE username_normalized = :name',
                ['name' => $normalized],
            ) !== null;
            if (!$alreadyStored && !isset($reserved[$normalized])) {
                return $candidate;
            }
        }
    }
}
