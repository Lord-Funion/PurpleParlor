<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Narrow, audited write boundary for day-to-day administration.
 *
 * Provider credentials and provider-side payment operations deliberately do
 * not belong here. The service changes local catalog/workflow state only and
 * never mutates append-only ledger or provider audit records.
 */
final class AdminMutationService
{
    /** @var list<string> */
    private const ADMIN_ROLES = ['adult_owner', 'developer_admin', 'support_admin', 'content_manager', 'moderator'];

    /** @var list<string> */
    private const OWNER_ONLY_PERMISSIONS = [
        'users.roles.manage', 'payments.audit.view', 'payments.refund', 'payments.live.configure',
        'merchant.identity.manage', 'payout.settings.manage', 'admins.manage',
        'monetization.disable', 'backups.manage',
    ];

    /** @var array<string,list<string>> */
    private const REQUEST_STATUSES = [
        'contact_message' => ['new', 'in_review', 'replied', 'closed', 'spam'],
        'licensing_inquiry' => ['new', 'qualified', 'contacted', 'negotiation', 'closed', 'rejected', 'spam'],
        'support_ticket' => ['open', 'in_progress', 'waiting_on_customer', 'resolved', 'closed'],
    ];

    /** @var array<string,list<string>> */
    private const WORKFLOW_KEYS = [
        'email_template' => [
            'receipt', 'reset-password', 'security-alert', 'subscription-status',
            'support-received', 'verify-email-change', 'verify-email', 'welcome',
        ],
        'content' => ['home', 'about', 'contact', 'help', 'press', 'sponsorship'],
        'legal' => [
            'acceptable-use', 'advertising', 'attributions', 'cookies', 'copyright',
            'privacy', 'refund', 'subscription-terms', 'terms', 'virtual-currency',
        ],
    ];

    public function __construct(
        private readonly Database $database,
        private readonly AuditService $audit,
    ) {
    }

    public function updateUserStatus(
        int $actorId,
        int $userId,
        string $status,
        string $reason,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): void {
        $reason = $this->reason($reason, 8);
        $status = strtolower(trim($status));
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new DomainException('Account status must be active or suspended.');
        }

        $this->database->transaction(function (Database $db) use ($actorId, $userId, $status, $reason, $ipHash, $ownerAuthorized): void {
            $user = $db->fetchOne($db->forUpdate('SELECT id, username, status, email_verified_at FROM users WHERE id = :id AND deleted_at IS NULL'), ['id' => $userId]);
            if ($user === null) {
                throw new DomainException('The selected account was not found.');
            }
            $roles = $this->rolesFor($userId);
            $isAdministrator = array_intersect($roles, self::ADMIN_ROLES) !== [];
            if ($isAdministrator && !$ownerAuthorized) {
                throw new DomainException('Only a reauthenticated Adult Owner may change an administrator account status.');
            }
            if ($actorId === $userId && $status !== 'active') {
                throw new DomainException('You cannot suspend your own signed-in account.');
            }
            if ($status === 'active' && $user['email_verified_at'] === null) {
                throw new DomainException('An unverified account cannot be activated by this workflow.');
            }
            if ((string) $user['status'] === $status) {
                throw new DomainException('The account already has that status.');
            }
            if ($status === 'suspended' && in_array('adult_owner', $roles, true)) {
                $this->assertAnotherActiveOwner($userId);
            }
            $now = gmdate('Y-m-d H:i:s');
            $db->execute('UPDATE users SET status = :status, updated_at = :now WHERE id = :id', ['status' => $status, 'now' => $now, 'id' => $userId]);
            if ($status === 'suspended') {
                $db->execute('UPDATE sessions SET revoked_at = :now WHERE user_id = :user AND revoked_at IS NULL', ['now' => $now, 'user' => $userId]);
                $db->execute('DELETE FROM remember_tokens WHERE user_id = :user', ['user' => $userId]);
            }
            $this->record($actorId, 'user.status_changed', 'user', (string) $userId,
                ['status' => (string) $user['status'], 'roles' => $roles],
                ['status' => $status, 'roles' => $roles], $reason, $ipHash);
        });
    }

    public function updateUserRole(
        int $actorId,
        int $userId,
        string $roleName,
        string $action,
        string $reason,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): void {
        $this->assertOwner($ownerAuthorized);
        $reason = $this->reason($reason, 10);
        $roleName = strtolower(trim($roleName));
        $action = strtolower(trim($action));
        if (!in_array($action, ['grant', 'revoke'], true)) {
            throw new DomainException('Role action must be grant or revoke.');
        }

        $this->database->transaction(function (Database $db) use ($actorId, $userId, $roleName, $action, $reason, $ipHash): void {
            $user = $db->fetchOne($db->forUpdate('SELECT id, status, email_verified_at FROM users WHERE id = :id AND deleted_at IS NULL'), ['id' => $userId]);
            $role = $db->fetchOne('SELECT id, name FROM roles WHERE name = :name', ['name' => $roleName]);
            if ($user === null || $role === null) {
                throw new DomainException('The selected account or role was not found.');
            }
            $before = $this->rolesFor($userId);
            $hasRole = in_array($roleName, $before, true);
            if (($action === 'grant') === $hasRole) {
                throw new DomainException($action === 'grant' ? 'The account already has that role.' : 'The account does not have that role.');
            }
            if ($action === 'grant' && in_array($roleName, self::ADMIN_ROLES, true)) {
                $twoFactor = $db->fetchOne('SELECT enabled FROM two_factor_secrets WHERE user_id = :user', ['user' => $userId]);
                if ((string) $user['status'] !== 'active' || $user['email_verified_at'] === null || (int) ($twoFactor['enabled'] ?? 0) !== 1) {
                    throw new DomainException('Administrative roles require an active, verified account with two-factor authentication enabled.');
                }
            }
            if ($action === 'revoke' && $actorId === $userId && in_array($roleName, self::ADMIN_ROLES, true)) {
                throw new DomainException('You cannot remove one of your own administrator roles.');
            }
            if ($action === 'revoke' && $roleName === 'adult_owner') {
                $this->assertAnotherActiveOwner($userId);
            }

            if ($action === 'grant') {
                $sql = $db->driver() === 'mysql'
                    ? 'INSERT IGNORE INTO user_roles (user_id, role_id, granted_by, created_at) VALUES (:user, :role, :actor, :now)'
                    : 'INSERT OR IGNORE INTO user_roles (user_id, role_id, granted_by, created_at) VALUES (:user, :role, :actor, :now)';
                $db->execute($sql, ['user' => $userId, 'role' => $role['id'], 'actor' => $actorId, 'now' => gmdate('Y-m-d H:i:s')]);
            } else {
                $db->execute('DELETE FROM user_roles WHERE user_id = :user AND role_id = :role', ['user' => $userId, 'role' => $role['id']]);
            }
            $after = $this->rolesFor($userId);
            $this->record($actorId, 'user.role_' . ($action === 'grant' ? 'granted' : 'revoked'), 'user', (string) $userId,
                ['roles' => $before], ['roles' => $after], $reason, $ipHash);
        });
    }

    public function updateRolePermission(
        int $actorId,
        string $roleName,
        string $permissionName,
        string $action,
        string $reason,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): void {
        $this->assertOwner($ownerAuthorized);
        $reason = $this->reason($reason, 10);
        $roleName = strtolower(trim($roleName));
        $permissionName = strtolower(trim($permissionName));
        $action = strtolower(trim($action));
        if (!in_array($action, ['grant', 'revoke'], true)) {
            throw new DomainException('Permission action must be grant or revoke.');
        }
        if ($roleName === 'adult_owner' || !in_array($roleName, self::ADMIN_ROLES, true)) {
            throw new DomainException('Only non-owner administrator roles have an editable permission set. Public member and guest roles remain fixed.');
        }
        if ($action === 'grant' && in_array($permissionName, self::OWNER_ONLY_PERMISSIONS, true)) {
            throw new DomainException('Adult Owner-only permissions cannot be delegated to another role.');
        }

        $this->database->transaction(function (Database $db) use ($actorId, $roleName, $permissionName, $action, $reason, $ipHash): void {
            $role = $db->fetchOne('SELECT id FROM roles WHERE name = :name', ['name' => $roleName]);
            $permission = $db->fetchOne('SELECT id FROM permissions WHERE name = :name', ['name' => $permissionName]);
            if ($role === null || $permission === null) {
                throw new DomainException('The selected role or permission was not found.');
            }
            $existing = $db->fetchOne('SELECT 1 FROM role_permissions WHERE role_id = :role AND permission_id = :permission', ['role' => $role['id'], 'permission' => $permission['id']]) !== null;
            if (($action === 'grant') === $existing) {
                throw new DomainException($action === 'grant' ? 'The role already has that permission.' : 'The role does not have that permission.');
            }
            if ($action === 'grant') {
                $db->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)', ['role' => $role['id'], 'permission' => $permission['id']]);
            } else {
                $db->execute('DELETE FROM role_permissions WHERE role_id = :role AND permission_id = :permission', ['role' => $role['id'], 'permission' => $permission['id']]);
            }
            $this->record($actorId, 'role.permission_' . ($action === 'grant' ? 'granted' : 'revoked'), 'role', $roleName,
                ['permission' => $permissionName, 'assigned' => $existing],
                ['permission' => $permissionName, 'assigned' => !$existing], $reason, $ipHash);
        });
    }

    public function updateGame(
        int $actorId,
        int $gameId,
        string $availability,
        mixed $minimum,
        mixed $maximum,
        mixed $increment,
        string $reason,
        ?string $ipHash,
    ): void {
        $reason = $this->reason($reason, 8);
        $active = match (strtolower(trim($availability))) {
            'active' => 1,
            'disabled' => 0,
            default => throw new DomainException('Game availability must be active or disabled.'),
        };
        $minimum = $this->integer($minimum, 'Minimum wager', 0, 1_000_000);
        $maximum = $this->integer($maximum, 'Maximum wager', 0, 1_000_000);
        $increment = $this->integer($increment, 'Wager increment', 0, 1_000_000);
        if ($maximum === 0) {
            if ($minimum !== 0 || $increment !== 0) {
                throw new DomainException('A wager-free game must use zero for minimum, maximum, and increment.');
            }
        } elseif ($minimum < 1 || $maximum < $minimum || $increment < 1 || $increment > $maximum) {
            throw new DomainException('Wager limits must have minimum 1 or more, maximum at least the minimum, and a positive increment no larger than the maximum.');
        }

        $this->database->transaction(function (Database $db) use ($actorId, $gameId, $active, $minimum, $maximum, $increment, $reason, $ipHash): void {
            $row = $db->fetchOne($db->forUpdate('SELECT id, slug, active, configuration_json FROM game_definitions WHERE id = :id'), ['id' => $gameId]);
            if ($row === null) {
                throw new DomainException('The selected game was not found.');
            }
            $configuration = json_decode((string) ($row['configuration_json'] ?? ''), true);
            if (!is_array($configuration) || (string) ($configuration['slug'] ?? '') !== (string) $row['slug']) {
                throw new DomainException('The stored game configuration is invalid and cannot be changed safely.');
            }
            $beforeWager = is_array($configuration['wager'] ?? null) ? $configuration['wager'] : [];
            $configuration['wager'] = [
                'minimum' => $minimum, 'maximum' => $maximum, 'increment' => $increment,
                'currency' => 'cozy_coins', 'cash_value' => false,
            ];
            $configuration['wager_increments'] = $increment === 0 ? [0] : array_values(array_unique(array_filter([
                $minimum, $increment, min($maximum, $increment * 5), min($maximum, $increment * 10),
            ], static fn (int $value): bool => $value >= $minimum && $value <= $maximum)));
            if ($configuration['wager_increments'] === []) {
                $configuration['wager_increments'] = [$minimum];
            }
            $now = gmdate('Y-m-d H:i:s');
            $db->execute('UPDATE game_definitions SET active = :active, configuration_json = :configuration, updated_at = :now WHERE id = :id', [
                'active' => $active, 'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'now' => $now, 'id' => $gameId,
            ]);
            $this->record($actorId, 'game.configuration_updated', 'game_definition', (string) $gameId,
                ['active' => (bool) $row['active'], 'wager' => $beforeWager],
                ['active' => (bool) $active, 'wager' => $configuration['wager']], $reason, $ipHash);
        });
    }

    public function updateAchievement(int $actorId, int $id, array $input, string $reason, ?string $ipHash): void
    {
        $reason = $this->reason($reason, 8);
        $name = $this->plain($input['name'] ?? '', 'Achievement name', 2, 100);
        $description = $this->plain($input['description'] ?? '', 'Achievement description', 5, 500);
        $coins = $this->integer($input['reward_coins'] ?? null, 'Coin reward', 0, 100_000);
        $stars = $this->integer($input['reward_stars'] ?? null, 'Star reward', 0, 10_000);
        $active = $this->boolean($input['active'] ?? null, 'Achievement status');
        $this->updateDefinition('achievements', 'achievement', $actorId, $id, [
            'name' => $name, 'description' => $description, 'reward_coins' => $coins,
            'reward_stars' => $stars, 'active' => $active,
        ], $reason, $ipHash);
    }

    public function updateMission(int $actorId, int $id, array $input, string $reason, ?string $ipHash): void
    {
        $reason = $this->reason($reason, 8);
        $starts = $this->date($input['starts_at'] ?? null, 'Mission start');
        $ends = $this->date($input['ends_at'] ?? null, 'Mission end');
        if (strtotime($ends) <= strtotime($starts)) {
            throw new DomainException('Mission end must be after its start.');
        }
        $this->updateDefinition('missions', 'mission', $actorId, $id, [
            'name' => $this->plain($input['name'] ?? '', 'Mission name', 2, 100),
            'description' => $this->plain($input['description'] ?? '', 'Mission description', 5, 500),
            'target_value' => $this->integer($input['target_value'] ?? null, 'Mission target', 1, 1_000_000),
            'reward_coins' => $this->integer($input['reward_coins'] ?? null, 'Coin reward', 0, 100_000),
            'reward_stars' => $this->integer($input['reward_stars'] ?? null, 'Star reward', 0, 10_000),
            'starts_at' => $starts, 'ends_at' => $ends,
            'active' => $this->boolean($input['active'] ?? null, 'Mission status'),
        ], $reason, $ipHash);
    }

    public function updateDailyReward(int $actorId, mixed $amount, string $reason, ?string $ipHash): void
    {
        $reason = $this->reason($reason, 8);
        $amount = $this->integer($amount, 'Daily reward', 1, 100_000);
        $before = $this->setting('rewards.daily.amount', 1000);
        $this->database->transaction(function () use ($actorId, $amount, $before, $reason, $ipHash): void {
            $this->writeSetting('rewards.daily.amount', $amount, $actorId);
            $this->record($actorId, 'reward.daily_configuration_updated', 'system_setting', 'rewards.daily.amount',
                ['amount' => (int) $before], ['amount' => $amount], $reason, $ipHash);
        });
    }

    public function updateCatalogItem(
        int $actorId,
        string $type,
        int $id,
        array $input,
        string $reason,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): void {
        $this->assertOwner($ownerAuthorized);
        $reason = $this->reason($reason, 10);
        $type = strtolower(trim($type));
        if (!in_array($type, ['plan', 'product'], true)) {
            throw new DomainException('Catalog type must be plan or product.');
        }
        $table = $type === 'plan' ? 'membership_plans' : 'products';
        $row = $this->database->fetchOne('SELECT * FROM ' . $table . ' WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new DomainException('The selected catalog item was not found.');
        }
        $active = $this->boolean($input['active'] ?? null, 'Catalog status');
        $changes = [
            'name' => $this->plain($input['name'] ?? '', 'Catalog name', 2, $type === 'plan' ? 100 : 150),
            'description' => $this->plain($input['description'] ?? '', 'Catalog description', 10, 2_000),
            'active' => $active,
        ];
        if ($type === 'plan') {
            $changes['sort_order'] = $this->integer($input['sort_order'] ?? null, 'Sort order', 0, 10_000);
            $changes['benefits_json'] = json_encode($this->entitlementKeys((string) ($input['benefits'] ?? '')), JSON_THROW_ON_ERROR);
        } else {
            $keys = $this->entitlementKeys((string) ($input['entitlements'] ?? ''));
            if ($keys === []) {
                throw new DomainException('A product must retain at least one fixed entitlement.');
            }
            $changes['entitlement_key'] = $keys[0];
            $changes['fixed_contents_json'] = json_encode($keys, JSON_THROW_ON_ERROR);
            $changes['refund_policy_reference'] = $this->key((string) ($input['refund_policy_reference'] ?? ''), 'Refund policy reference');
        }
        if ($active === 1 && (string) ($row[$type === 'plan' ? 'plan_key' : 'product_key'] ?? '') !== 'free') {
            $priceTable = $type === 'plan' ? 'membership_plan_prices' : 'product_prices';
            $foreign = $type === 'plan' ? 'plan_id' : 'product_id';
            if ($this->database->fetchOne("SELECT 1 FROM {$priceTable} WHERE {$foreign} = :id AND active = 1 LIMIT 1", ['id' => $id]) === null) {
                throw new DomainException('A paid catalog item cannot be activated without an active configured price.');
            }
        }

        $this->database->transaction(function (Database $db) use ($actorId, $type, $table, $id, $row, $changes, $reason, $ipHash): void {
            $assignments = [];
            $parameters = ['id' => $id, 'updated_at' => gmdate('Y-m-d H:i:s')];
            foreach ($changes as $column => $value) {
                $assignments[] = $column . ' = :' . $column;
                $parameters[$column] = $value;
            }
            $db->execute('UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ', updated_at = :updated_at WHERE id = :id', $parameters);
            if ($type === 'product') {
                $keys = json_decode((string) $changes['fixed_contents_json'], true, 8, JSON_THROW_ON_ERROR);
                $db->execute('DELETE FROM product_entitlements WHERE product_id = :id', ['id' => $id]);
                foreach ($keys as $key) {
                    $db->execute('INSERT INTO product_entitlements (product_id, entitlement_key) VALUES (:id, :key)', ['id' => $id, 'key' => $key]);
                }
            }
            $before = array_intersect_key($row, $changes);
            $this->record($actorId, 'commerce.' . $type . '_updated', $table, (string) $id, $before, $changes, $reason, $ipHash);
        });
    }

    public function updateEntitlement(
        int $actorId,
        int $userId,
        string $entitlementKey,
        string $action,
        ?string $endsAt,
        string $reason,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): void {
        $this->assertOwner($ownerAuthorized);
        $reason = $this->reason($reason, 10);
        $entitlementKey = $this->key($entitlementKey, 'Entitlement key');
        $action = strtolower(trim($action));
        if (!in_array($action, ['grant', 'revoke'], true)) {
            throw new DomainException('Entitlement action must be grant or revoke.');
        }
        if ($this->database->fetchOne('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $userId]) === null) {
            throw new DomainException('The selected account was not found.');
        }
        $known = $this->database->fetchOne('SELECT 1 FROM product_entitlements WHERE entitlement_key = :key LIMIT 1', ['key' => $entitlementKey]);
        if ($known === null) {
            foreach ($this->database->fetchAll('SELECT benefits_json FROM membership_plans') as $plan) {
                $benefits = json_decode((string) $plan['benefits_json'], true);
                if (is_array($benefits) && in_array($entitlementKey, $benefits, true)) {
                    $known = ['found' => 1];
                    break;
                }
            }
        }
        if ($known === null) {
            throw new DomainException('Only a known catalog entitlement may be granted.');
        }
        $end = trim((string) $endsAt) === '' ? null : $this->date($endsAt, 'Entitlement end');
        if ($end !== null && strtotime($end) <= time()) {
            throw new DomainException('An entitlement end must be in the future.');
        }
        $sourceId = 'manual:' . $entitlementKey;
        $this->database->transaction(function (Database $db) use ($actorId, $userId, $entitlementKey, $action, $end, $sourceId, $reason, $ipHash): void {
            $existing = $db->fetchOne($db->forUpdate("SELECT * FROM user_entitlements WHERE user_id = :user AND entitlement_key = :key AND source_type = 'admin' AND source_id = :source"), [
                'user' => $userId, 'key' => $entitlementKey, 'source' => $sourceId,
            ]);
            $now = gmdate('Y-m-d H:i:s');
            if ($action === 'grant') {
                if ($existing === null) {
                    $db->execute("INSERT INTO user_entitlements (user_id, entitlement_key, source_type, source_id, starts_at, ends_at, revoked_at, revocation_reason, created_at, updated_at)
                        VALUES (:user, :key, 'admin', :source, :starts, :ends, NULL, NULL, :created, :updated)", [
                        'user' => $userId, 'key' => $entitlementKey, 'source' => $sourceId, 'starts' => $now,
                        'ends' => $end, 'created' => $now, 'updated' => $now,
                    ]);
                } else {
                    $db->execute('UPDATE user_entitlements SET starts_at = :starts, ends_at = :ends, revoked_at = NULL, revocation_reason = NULL, updated_at = :updated WHERE id = :id', [
                        'starts' => $now, 'ends' => $end, 'updated' => $now, 'id' => $existing['id'],
                    ]);
                }
            } else {
                if ($existing === null || $existing['revoked_at'] !== null) {
                    throw new DomainException('No active administrator-granted entitlement was found. Purchase and subscription entitlements are never revoked here.');
                }
                $db->execute('UPDATE user_entitlements SET revoked_at = :revoked, revocation_reason = :reason, updated_at = :updated WHERE id = :id', [
                    'revoked' => $now, 'reason' => 'admin_revoked', 'updated' => $now, 'id' => $existing['id'],
                ]);
            }
            $this->record($actorId, 'entitlement.' . ($action === 'grant' ? 'granted' : 'revoked'), 'user_entitlement', $userId . ':' . $entitlementKey,
                $existing === null ? null : ['source_type' => 'admin', 'ends_at' => $existing['ends_at'], 'revoked_at' => $existing['revoked_at']],
                ['source_type' => 'admin', 'ends_at' => $end, 'revoked' => $action === 'revoke'], $reason, $ipHash);
        });
    }

    public function trackRefundRequest(
        int $actorId,
        int $requestId,
        int $paymentId,
        mixed $amountCents,
        string $status,
        string $reason,
        string $note,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): void {
        $this->assertOwner($ownerAuthorized);
        $reason = $this->reason($reason, 10);
        $status = strtolower(trim($status));
        $allowed = ['requested', 'under_review', 'provider_action_required', 'declined', 'closed'];
        if (!in_array($status, $allowed, true)) {
            throw new DomainException('Select a valid internal refund-request status.');
        }
        $note = trim($note) === '' ? '' : $this->plain($note, 'Resolution note', 3, 500);

        $this->database->transaction(function (Database $db) use ($actorId, $requestId, $paymentId, $amountCents, $status, $reason, $note, $ipHash): void {
            $now = gmdate('Y-m-d H:i:s');
            if ($requestId <= 0) {
                if ($status !== 'requested') {
                    throw new DomainException('A new refund request must begin in requested status.');
                }
                $payment = $db->fetchOne('SELECT id, amount_cents, status FROM payments WHERE id = :id', ['id' => $paymentId]);
                if ($payment === null) {
                    throw new DomainException('The selected local payment record was not found.');
                }
                $amount = $this->integer($amountCents, 'Requested refund amount', 1, (int) $payment['amount_cents']);
                $id = $db->insert('INSERT INTO refund_requests (payment_id, requested_amount_cents, status, request_reason, resolution_note, requested_by, updated_by, created_at, updated_at)
                    VALUES (:payment, :amount, :status, :reason, :note, :actor, :actor, :created, :updated)', [
                    'payment' => $paymentId, 'amount' => $amount, 'status' => $status, 'reason' => $reason,
                    'note' => $note === '' ? null : $note, 'actor' => $actorId, 'created' => $now, 'updated' => $now,
                ]);
                $this->record($actorId, 'refund_request.created', 'refund_request', (string) $id, null,
                    ['payment_id' => $paymentId, 'amount_cents' => $amount, 'status' => $status], $reason, $ipHash);
                return;
            }
            $existing = $db->fetchOne($db->forUpdate('SELECT * FROM refund_requests WHERE id = :id'), ['id' => $requestId]);
            if ($existing === null) {
                throw new DomainException('The selected refund request was not found.');
            }
            if (in_array((string) $existing['status'], ['declined', 'closed'], true)) {
                throw new DomainException('A terminal refund request cannot be reopened. Create a new internal request if review is needed.');
            }
            $transitions = [
                'requested' => ['under_review', 'declined', 'closed'],
                'under_review' => ['provider_action_required', 'declined', 'closed'],
                'provider_action_required' => ['under_review', 'closed'],
            ];
            if (!in_array($status, $transitions[(string) $existing['status']] ?? [], true)) {
                throw new DomainException('That refund workflow transition is not allowed.');
            }
            $db->execute('UPDATE refund_requests SET status = :status, resolution_note = :note, updated_by = :actor, updated_at = :updated WHERE id = :id', [
                'status' => $status, 'note' => $note === '' ? $existing['resolution_note'] : $note,
                'actor' => $actorId, 'updated' => $now, 'id' => $requestId,
            ]);
            $this->record($actorId, 'refund_request.status_changed', 'refund_request', (string) $requestId,
                ['status' => $existing['status']], ['status' => $status], $reason, $ipHash);
        });
    }

    public function updateMonetization(
        int $actorId,
        string $type,
        int $id,
        string $status,
        string $reason,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): void {
        $this->assertOwner($ownerAuthorized);
        $reason = $this->reason($reason, 10);
        $type = strtolower(trim($type));
        $status = strtolower(trim($status));
        $this->database->transaction(function (Database $db) use ($actorId, $type, $id, $status, $reason, $ipHash): void {
            if ($type === 'advertising_slot') {
                if (!in_array($status, ['enabled', 'disabled'], true)) {
                    throw new DomainException('Advertising status must be enabled or disabled.');
                }
                $row = $db->fetchOne($db->forUpdate('SELECT id, slot_key, enabled FROM advertising_slots WHERE id = :id'), ['id' => $id]);
                if ($row === null) {
                    throw new DomainException('The selected advertising slot was not found.');
                }
                $enabled = $status === 'enabled' ? 1 : 0;
                $db->execute('UPDATE advertising_slots SET enabled = :enabled, updated_at = :updated WHERE id = :id', ['enabled' => $enabled, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
                $this->record($actorId, 'advertising.slot_status_changed', 'advertising_slot', (string) $id,
                    ['enabled' => (bool) $row['enabled']], ['enabled' => (bool) $enabled], $reason, $ipHash);
                return;
            }
            if ($type !== 'sponsor') {
                throw new DomainException('Unsupported monetization record type.');
            }
            $allowed = ['draft', 'pending_review', 'approved', 'active', 'paused', 'ended', 'rejected'];
            if (!in_array($status, $allowed, true)) {
                throw new DomainException('Select a valid sponsor status.');
            }
            $row = $db->fetchOne($db->forUpdate('SELECT id, status, website_url, starts_at, ends_at FROM sponsors WHERE id = :id'), ['id' => $id]);
            if ($row === null) {
                throw new DomainException('The selected sponsor was not found.');
            }
            if ($status === 'active') {
                if ($row['website_url'] === null || filter_var((string) $row['website_url'], FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower((string) $row['website_url']), 'https://')) {
                    throw new DomainException('An active sponsor requires a valid HTTPS destination.');
                }
                if ($row['starts_at'] !== null && $row['ends_at'] !== null && strtotime((string) $row['ends_at']) <= strtotime((string) $row['starts_at'])) {
                    throw new DomainException('Sponsor dates are invalid and must be corrected outside activation.');
                }
            }
            $db->execute('UPDATE sponsors SET status = :status WHERE id = :id', ['status' => $status, 'id' => $id]);
            $this->record($actorId, 'sponsor.status_changed', 'sponsor', (string) $id,
                ['status' => $row['status']], ['status' => $status], $reason, $ipHash);
        });
    }

    public function updateRequestStatus(int $actorId, string $type, int $id, string $status, string $reason, ?string $ipHash): void
    {
        $reason = $this->reason($reason, 5);
        $type = strtolower(trim($type));
        $status = strtolower(trim($status));
        if (!isset(self::REQUEST_STATUSES[$type]) || !in_array($status, self::REQUEST_STATUSES[$type], true)) {
            throw new DomainException('Select a valid request type and workflow status.');
        }
        [$table, $action] = match ($type) {
            'contact_message' => ['contact_messages', 'contact_message.status_changed'],
            'licensing_inquiry' => ['licensing_inquiries', 'licensing_inquiry.status_changed'],
            'support_ticket' => ['support_tickets', 'support_ticket.status_changed'],
        };
        $this->database->transaction(function (Database $db) use ($actorId, $table, $action, $type, $id, $status, $reason, $ipHash): void {
            $row = $db->fetchOne($db->forUpdate('SELECT id, status FROM ' . $table . ' WHERE id = :id'), ['id' => $id]);
            if ($row === null) {
                throw new DomainException('The selected request record was not found.');
            }
            if ((string) $row['status'] === $status) {
                throw new DomainException('The request already has that workflow status.');
            }
            $parameters = ['status' => $status, 'id' => $id];
            $updated = $type === 'support_ticket' ? ', updated_at = :updated' : '';
            if ($updated !== '') {
                $parameters['updated'] = gmdate('Y-m-d H:i:s');
            }
            $db->execute('UPDATE ' . $table . ' SET status = :status' . $updated . ' WHERE id = :id', $parameters);
            $this->record($actorId, $action, $table, (string) $id,
                ['status' => $row['status']], ['status' => $status], $reason, $ipHash);
        });
    }

    public function updateWorkflow(
        int $actorId,
        string $type,
        string $key,
        string $status,
        string $version,
        string $reason,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): void {
        $reason = $this->reason($reason, 8);
        $type = strtolower(trim($type));
        $key = strtolower(trim($key));
        if (!isset(self::WORKFLOW_KEYS[$type]) || !in_array($key, self::WORKFLOW_KEYS[$type], true)) {
            throw new DomainException('Select an installed workflow record.');
        }
        $statuses = $type === 'legal'
            ? ['draft', 'counsel_review', 'owner_approved', 'published', 'retired']
            : ['draft', 'review', 'approved', 'published', 'retired'];
        $status = strtolower(trim($status));
        if (!in_array($status, $statuses, true)) {
            throw new DomainException('Select a valid review or publication status.');
        }
        if ($type === 'legal') {
            $this->assertOwner($ownerAuthorized);
            if ($status === 'published' && !in_array((string) ($this->workflow($type, $key)['status'] ?? 'draft'), ['owner_approved', 'published'], true)) {
                throw new DomainException('Legal material must be marked owner approved before it can be marked published.');
            }
        }
        $version = trim($version);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $version) !== 1) {
            throw new DomainException('Version must be 1–32 letters, numbers, periods, underscores, or hyphens.');
        }
        $settingKey = 'workflow.' . $type . '.' . $key;
        $before = $this->workflow($type, $key);
        $after = ['status' => $status, 'version' => $version, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $actorId];
        $this->database->transaction(function () use ($actorId, $settingKey, $type, $key, $before, $after, $reason, $ipHash): void {
            $this->writeSetting($settingKey, $after, $actorId);
            $this->record($actorId, 'workflow.' . $type . '_status_updated', $type . '_workflow', $key, $before, $after, $reason, $ipHash);
        });
    }

    /** @return array{status:string,version:string,updated_at:?string,updated_by:?int} */
    public function workflow(string $type, string $key): array
    {
        $stored = $this->setting('workflow.' . $type . '.' . $key, null);
        if (!is_array($stored)) {
            return ['status' => 'draft', 'version' => 'draft', 'updated_at' => null, 'updated_by' => null];
        }
        return [
            'status' => (string) ($stored['status'] ?? 'draft'),
            'version' => (string) ($stored['version'] ?? 'draft'),
            'updated_at' => isset($stored['updated_at']) ? (string) $stored['updated_at'] : null,
            'updated_by' => isset($stored['updated_by']) ? (int) $stored['updated_by'] : null,
        ];
    }

    /** @return array<string,list<string>> */
    public static function workflowKeys(): array
    {
        return self::WORKFLOW_KEYS;
    }

    /** @param array<string,mixed> $changes */
    private function updateDefinition(string $table, string $type, int $actorId, int $id, array $changes, string $reason, ?string $ipHash): void
    {
        $this->database->transaction(function (Database $db) use ($table, $type, $actorId, $id, $changes, $reason, $ipHash): void {
            $row = $db->fetchOne($db->forUpdate('SELECT * FROM ' . $table . ' WHERE id = :id'), ['id' => $id]);
            if ($row === null) {
                throw new DomainException('The selected ' . $type . ' was not found.');
            }
            $assignments = [];
            $parameters = ['id' => $id];
            foreach ($changes as $column => $value) {
                $assignments[] = $column . ' = :' . $column;
                $parameters[$column] = $value;
            }
            $db->execute('UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' WHERE id = :id', $parameters);
            $this->record($actorId, $type . '.configuration_updated', $table, (string) $id,
                array_intersect_key($row, $changes), $changes, $reason, $ipHash);
        });
    }

    /** @return list<string> */
    private function rolesFor(int $userId): array
    {
        return array_values(array_map(static fn (array $row): string => (string) $row['name'], $this->database->fetchAll(
            'SELECT r.name FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :user ORDER BY r.name',
            ['user' => $userId],
        )));
    }

    private function assertAnotherActiveOwner(int $excludedUserId): void
    {
        $row = $this->database->fetchOne(
            "SELECT COUNT(*) AS aggregate FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id
             WHERE r.name = 'adult_owner' AND u.status = 'active' AND u.deleted_at IS NULL AND u.id <> :excluded",
            ['excluded' => $excludedUserId],
        );
        if ((int) ($row['aggregate'] ?? 0) < 1) {
            throw new DomainException('The last active Adult Owner cannot be suspended or removed.');
        }
    }

    private function assertOwner(bool $authorized): void
    {
        if (!$authorized) {
            throw new DomainException('A reauthenticated Adult Owner is required for this action.');
        }
    }

    private function reason(string $reason, int $minimum): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < $minimum || mb_strlen($reason) > 500) {
            throw new DomainException("A reason between {$minimum} and 500 characters is required.");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason) === 1) {
            throw new DomainException('The reason contains unsupported control characters.');
        }
        return $reason;
    }

    private function plain(mixed $value, string $label, int $minimum, int $maximum): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if (mb_strlen($value) < $minimum || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new DomainException("{$label} must be {$minimum}–{$maximum} plain-text characters.");
        }
        return $value;
    }

    private function integer(mixed $value, string $label, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new DomainException("{$label} must be a whole number from {$minimum} to {$maximum}.");
        }
        return $value;
    }

    private function boolean(mixed $value, string $label): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        $value = strtolower(trim((string) $value));
        return match ($value) {
            '1', 'true', 'active', 'enabled', 'published' => 1,
            '0', 'false', 'disabled', 'inactive', 'draft' => 0,
            default => throw new DomainException("{$label} must be active or disabled."),
        };
    }

    private function date(mixed $value, string $label): string
    {
        $value = trim((string) $value);
        $timezone = new DateTimeZone('UTC');
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format($format) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new DomainException("{$label} must be a valid UTC date and time.");
    }

    private function key(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $value) !== 1) {
            throw new DomainException("{$label} must be a safe key of 1–100 lowercase letters, numbers, periods, underscores, or hyphens.");
        }
        return $value;
    }

    /** @return list<string> */
    private function entitlementKeys(string $value): array
    {
        $raw = preg_split('/[\s,]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keys = [];
        foreach ($raw as $item) {
            $keys[] = $this->key($item, 'Entitlement key');
        }
        $keys = array_values(array_unique($keys));
        if (count($keys) > 30) {
            throw new DomainException('No more than 30 entitlement keys may be assigned to one catalog item.');
        }
        return $keys;
    }

    private function setting(string $key, mixed $default): mixed
    {
        $row = $this->database->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = :key', ['key' => $key]);
        if ($row === null) {
            return $default;
        }
        $value = json_decode((string) $row['setting_value'], true);
        return json_last_error() === JSON_ERROR_NONE ? $value : $default;
    }

    private function writeSetting(string $key, mixed $value, int $actorId): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $existing = $this->database->fetchOne('SELECT setting_key FROM system_settings WHERE setting_key = :key', ['key' => $key]);
        if ($existing === null) {
            $this->database->execute('INSERT INTO system_settings (setting_key, setting_value, is_sensitive, updated_by, updated_at) VALUES (:key, :value, 0, :actor, :updated)', [
                'key' => $key, 'value' => $encoded, 'actor' => $actorId, 'updated' => gmdate('Y-m-d H:i:s'),
            ]);
            return;
        }
        $this->database->execute('UPDATE system_settings SET setting_value = :value, is_sensitive = 0, updated_by = :actor, updated_at = :updated WHERE setting_key = :key', [
            'value' => $encoded, 'actor' => $actorId, 'updated' => gmdate('Y-m-d H:i:s'), 'key' => $key,
        ]);
    }

    private function record(int $actorId, string $action, string $targetType, string $targetId, mixed $before, mixed $after, string $reason, ?string $ipHash): void
    {
        $this->audit->record($actorId, $action, $targetType, $targetId, $before, $after, $reason, $ipHash);
    }
}
