<?php

declare(strict_types=1);

namespace App\Database;

use PDOException;

final class Schema
{
    public static function sql(string $driver = 'mysql'): string
    {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('Unsupported schema driver.');
        }
        $statements = array_merge(self::tables($driver), self::indexes(), self::appendOnlyTriggers($driver));
        $preamble = $driver === 'mysql'
            ? "-- Generated from App\\Database\\Schema. Use UTF-8.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n"
            : "-- Generated from App\\Database\\Schema.\nPRAGMA foreign_keys=ON;\n\n";
        $migrationTable = "CREATE TABLE IF NOT EXISTS schema_migrations (\n  migration VARCHAR(255) PRIMARY KEY,\n  checksum VARCHAR(64) NOT NULL,\n  applied_at DATETIME NOT NULL\n);\n\n";
        return $preamble . $migrationTable . implode(";\n\n", $statements) . ";\n" . ($driver === 'mysql' ? "\nSET FOREIGN_KEY_CHECKS=1;\n" : '');
    }

    public static function install(Database $database): void
    {
        foreach (self::tables($database->driver()) as $sql) {
            $database->pdo()->exec($sql);
        }
        foreach (self::indexes() as $sql) {
            try {
                $database->pdo()->exec($sql);
            } catch (PDOException $e) {
                // Indexes are declared deterministically; an existing index is safe on a resumed install.
                if (!str_contains(strtolower($e->getMessage()), 'already exists')
                    && !str_contains(strtolower($e->getMessage()), 'duplicate key name')) {
                    throw $e;
                }
            }
        }
        foreach (self::appendOnlyTriggers($database->driver()) as $sql) {
            try {
                $database->pdo()->exec($sql);
            } catch (PDOException $e) {
                if (!str_contains(strtolower($e->getMessage()), 'already exists')) {
                    throw $e;
                }
            }
        }
    }

    /** @return list<string> */
    private static function tables(string $driver): array
    {
        $id = $driver === 'mysql'
            ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $big = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';
        $int = 'INTEGER';
        $bool = $driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER';
        $text = 'TEXT';
        $json = $driver === 'mysql' ? 'JSON' : 'TEXT';
        $money = $driver === 'mysql' ? 'BIGINT' : 'INTEGER';
        $table = static fn (string $name, array $columns): string =>
            'CREATE TABLE IF NOT EXISTS ' . $name . " (\n  " . implode(",\n  ", $columns) . "\n)" . ($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '');

        return [
            $table('users', [
                "id {$id}", 'email VARCHAR(254) NOT NULL', 'email_normalized VARCHAR(254) NOT NULL',
                'username VARCHAR(50) NOT NULL', 'username_normalized VARCHAR(50) NOT NULL', 'password_hash VARCHAR(255) NOT NULL',
                "status VARCHAR(32) NOT NULL DEFAULT 'pending_verification'", "email_verified_at DATETIME NULL",
                "pending_email VARCHAR(254) NULL", "failed_login_count {$int} NOT NULL DEFAULT 0", 'locked_until DATETIME NULL',
                'username_changed_at DATETIME NULL', 'last_login_at DATETIME NULL', 'created_at DATETIME NOT NULL',
                'updated_at DATETIME NOT NULL', 'deleted_at DATETIME NULL',
                'UNIQUE (email_normalized)', 'UNIQUE (username_normalized)',
            ]),
            $table('user_profiles', [
                "user_id {$big} PRIMARY KEY", 'display_name VARCHAR(80) NULL', 'bio VARCHAR(500) NULL',
                "avatar_key VARCHAR(100) NULL", "is_public {$bool} NOT NULL DEFAULT 0", "stats_public {$bool} NOT NULL DEFAULT 0",
                'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            ]),
            $table('user_settings', [
                "user_id {$big} PRIMARY KEY", "settings_json {$json} NOT NULL", 'updated_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            ]),
            $table('roles', ["id {$id}", 'name VARCHAR(64) NOT NULL UNIQUE', 'description VARCHAR(255) NULL', 'created_at DATETIME NOT NULL']),
            $table('permissions', ["id {$id}", 'name VARCHAR(100) NOT NULL UNIQUE', 'description VARCHAR(255) NULL', 'created_at DATETIME NOT NULL']),
            $table('role_permissions', [
                "role_id {$big} NOT NULL", "permission_id {$big} NOT NULL", 'PRIMARY KEY (role_id, permission_id)',
                'FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE',
                'FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE',
            ]),
            $table('user_roles', [
                "user_id {$big} NOT NULL", "role_id {$big} NOT NULL", "granted_by {$big} NULL", 'created_at DATETIME NOT NULL',
                'PRIMARY KEY (user_id, role_id)', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
                'FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE', 'FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL',
            ]),
            $table('sessions', [
                "id {$id}", "user_id {$big} NOT NULL", 'selector VARCHAR(32) NOT NULL UNIQUE', 'token_hash VARCHAR(64) NOT NULL',
                'php_session_id VARCHAR(128) NULL', 'ip_hash VARCHAR(64) NOT NULL', 'user_agent VARCHAR(255) NULL',
                'last_seen_at DATETIME NOT NULL', 'expires_at DATETIME NOT NULL', 'revoked_at DATETIME NULL', 'created_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            ]),
            $table('remember_tokens', [
                "id {$id}", "user_id {$big} NOT NULL", 'selector VARCHAR(32) NOT NULL UNIQUE', 'token_hash VARCHAR(64) NOT NULL',
                'expires_at DATETIME NOT NULL', 'last_used_at DATETIME NULL', 'created_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            ]),
            $table('email_verifications', [
                "id {$id}", "user_id {$big} NOT NULL", 'token_hash VARCHAR(64) NOT NULL UNIQUE', 'new_email VARCHAR(254) NULL',
                'expires_at DATETIME NOT NULL', 'used_at DATETIME NULL', 'created_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            ]),
            $table('password_resets', [
                "id {$id}", "user_id {$big} NOT NULL", 'token_hash VARCHAR(64) NOT NULL UNIQUE', 'expires_at DATETIME NOT NULL',
                'used_at DATETIME NULL', 'created_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            ]),
            $table('two_factor_secrets', [
                "user_id {$big} PRIMARY KEY", "encrypted_secret {$text} NOT NULL", "enabled {$bool} NOT NULL DEFAULT 0",
                'confirmed_at DATETIME NULL', 'last_counter BIGINT NULL', 'updated_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            ]),
            $table('recovery_codes', [
                "id {$id}", "user_id {$big} NOT NULL", 'code_hash VARCHAR(255) NOT NULL', 'used_at DATETIME NULL', 'created_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            ]),
            $table('login_attempts', [
                "id {$id}", 'email_hash VARCHAR(64) NOT NULL', 'ip_hash VARCHAR(64) NOT NULL', "successful {$bool} NOT NULL DEFAULT 0",
                'attempted_at DATETIME NOT NULL',
            ]),
            $table('rate_limits', [
                'bucket_key VARCHAR(191) PRIMARY KEY', "hits {$int} NOT NULL", 'window_started_at DATETIME NOT NULL', 'expires_at DATETIME NOT NULL',
            ]),
            $table('security_events', [
                "id {$id}", "user_id {$big} NULL", 'event_type VARCHAR(100) NOT NULL', 'severity VARCHAR(20) NOT NULL',
                'ip_hash VARCHAR(64) NULL', 'request_id VARCHAR(64) NULL', "metadata_json {$json} NULL", 'created_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
            ]),
            $table('age_confirmations', [
                "id {$id}", 'policy_version VARCHAR(32) NOT NULL', 'confirmed_at DATETIME NOT NULL',
            ]),
            $table('legal_acceptances', [
                "id {$id}", "user_id {$big} NOT NULL", 'document_key VARCHAR(100) NOT NULL', 'document_version VARCHAR(32) NOT NULL',
                'ip_hash VARCHAR(64) NULL', 'accepted_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'UNIQUE (user_id, document_key, document_version)',
            ]),
            $table('virtual_wallets', [
                "id {$id}", "user_id {$big} NOT NULL", 'currency VARCHAR(32) NOT NULL', "balance {$money} NOT NULL DEFAULT 0",
                'version INTEGER NOT NULL DEFAULT 0', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'UNIQUE (user_id, currency)', 'CHECK (balance >= 0)',
            ]),
            $table('virtual_ledger_entries', [
                "id {$id}", "user_id {$big} NOT NULL", 'currency VARCHAR(32) NOT NULL', "amount {$money} NOT NULL",
                "balance_before {$money} NOT NULL", "balance_after {$money} NOT NULL", 'reason_code VARCHAR(100) NOT NULL',
                "related_game_round_id {$big} NULL", "related_achievement_id {$big} NULL", "related_mission_id {$big} NULL",
                "administrator_id {$big} NULL", 'idempotency_key VARCHAR(191) NOT NULL', 'previous_hash VARCHAR(64) NOT NULL',
                'entry_hash VARCHAR(64) NOT NULL', "metadata_json {$json} NULL", 'created_at DATETIME NOT NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT', 'FOREIGN KEY (administrator_id) REFERENCES users(id) ON DELETE SET NULL',
                'UNIQUE (idempotency_key)', 'CHECK (balance_after >= 0)', 'CHECK (balance_after = balance_before + amount)',
            ]),
            $table('game_definitions', [
                "id {$id}", 'slug VARCHAR(100) NOT NULL UNIQUE', 'name VARCHAR(100) NOT NULL', 'category VARCHAR(64) NOT NULL',
                "active {$bool} NOT NULL DEFAULT 1", "configuration_json {$json} NULL", 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL',
            ]),
            $table('game_configurations', [
                "id {$id}", "game_id {$big} NOT NULL", 'version INTEGER NOT NULL', "configuration_json {$json} NOT NULL", "active {$bool} NOT NULL DEFAULT 0",
                'created_at DATETIME NOT NULL', 'FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE CASCADE', 'UNIQUE (game_id, version)',
            ]),
            $table('game_rounds', [
                "id {$id}", "user_id {$big} NOT NULL", "game_id {$big} NOT NULL", 'public_id VARCHAR(64) NOT NULL UNIQUE',
                'status VARCHAR(32) NOT NULL', "wager_amount {$money} NOT NULL", "payout_amount {$money} NULL", 'currency VARCHAR(32) NOT NULL',
                'client_seed VARCHAR(128) NULL', 'server_seed_hash VARCHAR(64) NOT NULL', 'server_seed_encrypted TEXT NOT NULL',
                'request_id VARCHAR(64) NOT NULL', 'idempotency_key VARCHAR(191) NOT NULL UNIQUE', 'started_at DATETIME NOT NULL', 'settled_at DATETIME NULL',
                'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT', 'FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE RESTRICT',
            ]),
            $table('game_round_actions', [
                "id {$id}", "round_id {$big} NOT NULL", "sequence_number {$int} NOT NULL", 'action_type VARCHAR(64) NOT NULL',
                "action_json {$json} NOT NULL", "result_json {$json} NULL", 'idempotency_key VARCHAR(191) NOT NULL UNIQUE', 'created_at DATETIME NOT NULL',
                'FOREIGN KEY (round_id) REFERENCES game_rounds(id) ON DELETE CASCADE', 'UNIQUE (round_id, sequence_number)',
            ]),
            $table('game_seed_commits', [
                "id {$id}", "round_id {$big} NOT NULL UNIQUE", 'commit_hash VARCHAR(64) NOT NULL', 'server_seed VARCHAR(191) NULL',
                'revealed_at DATETIME NULL', 'created_at DATETIME NOT NULL', 'FOREIGN KEY (round_id) REFERENCES game_rounds(id) ON DELETE CASCADE',
            ]),
            $table('game_favorites', ["user_id {$big} NOT NULL", "game_id {$big} NOT NULL", 'created_at DATETIME NOT NULL', 'PRIMARY KEY (user_id, game_id)', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE CASCADE']),
            $table('recent_games', ["user_id {$big} NOT NULL", "game_id {$big} NOT NULL", 'last_played_at DATETIME NOT NULL', 'PRIMARY KEY (user_id, game_id)', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE CASCADE']),
            $table('player_statistics', ["id {$id}", "user_id {$big} NOT NULL", "game_id {$big} NULL", 'stat_key VARCHAR(100) NOT NULL', 'stat_value BIGINT NOT NULL DEFAULT 0', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE CASCADE', 'UNIQUE (user_id, game_id, stat_key)']),
            $table('daily_reward_claims', ["id {$id}", "user_id {$big} NOT NULL", 'reward_date DATE NOT NULL', "amount {$money} NOT NULL", 'idempotency_key VARCHAR(191) NOT NULL UNIQUE', 'claimed_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'UNIQUE (user_id, reward_date)']),
            $table('achievements', ["id {$id}", 'achievement_key VARCHAR(100) NOT NULL UNIQUE', 'name VARCHAR(100) NOT NULL', 'description VARCHAR(500) NOT NULL', "reward_coins {$money} NOT NULL DEFAULT 0", "reward_stars {$money} NOT NULL DEFAULT 0", "active {$bool} NOT NULL DEFAULT 1", 'created_at DATETIME NOT NULL']),
            $table('user_achievements', ["user_id {$big} NOT NULL", "achievement_id {$big} NOT NULL", 'unlocked_at DATETIME NOT NULL', 'PRIMARY KEY (user_id, achievement_id)', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE']),
            $table('missions', ["id {$id}", 'mission_key VARCHAR(100) NOT NULL UNIQUE', 'name VARCHAR(100) NOT NULL', 'description VARCHAR(500) NOT NULL', 'target_value INTEGER NOT NULL', "reward_coins {$money} NOT NULL DEFAULT 0", "reward_stars {$money} NOT NULL DEFAULT 0", 'starts_at DATETIME NOT NULL', 'ends_at DATETIME NOT NULL', "active {$bool} NOT NULL DEFAULT 1"]),
            $table('mission_progress', ["user_id {$big} NOT NULL", "mission_id {$big} NOT NULL", 'progress_value INTEGER NOT NULL DEFAULT 0', 'completed_at DATETIME NULL', 'rewarded_at DATETIME NULL', 'updated_at DATETIME NOT NULL', 'PRIMARY KEY (user_id, mission_id)', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE']),
            $table('leaderboards', ["id {$id}", 'leaderboard_key VARCHAR(100) NOT NULL UNIQUE', 'name VARCHAR(100) NOT NULL', "configuration_json {$json} NOT NULL", 'starts_at DATETIME NULL', 'ends_at DATETIME NULL', "active {$bool} NOT NULL DEFAULT 1"]),
            $table('leaderboard_entries', ["leaderboard_id {$big} NOT NULL", "user_id {$big} NOT NULL", 'score BIGINT NOT NULL', 'updated_at DATETIME NOT NULL', 'PRIMARY KEY (leaderboard_id, user_id)', 'FOREIGN KEY (leaderboard_id) REFERENCES leaderboards(id) ON DELETE CASCADE', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE']),
            $table('membership_plans', ["id {$id}", 'plan_key VARCHAR(100) NOT NULL UNIQUE', 'name VARCHAR(100) NOT NULL', 'description TEXT NOT NULL', "benefits_json {$json} NOT NULL", "active {$bool} NOT NULL DEFAULT 1", 'sort_order INTEGER NOT NULL DEFAULT 0', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL']),
            $table('membership_plan_prices', ["id {$id}", "plan_id {$big} NOT NULL", 'billing_period VARCHAR(16) NOT NULL', "amount_cents {$money} NOT NULL", 'currency VARCHAR(3) NOT NULL', 'provider VARCHAR(32) NOT NULL', 'provider_plan_id VARCHAR(191) NULL', "active {$bool} NOT NULL DEFAULT 1", 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE CASCADE', 'UNIQUE (plan_id, billing_period, provider, currency)', 'CHECK (amount_cents >= 0)']),
            $table('payment_provider_customers', ["id {$id}", "user_id {$big} NOT NULL", 'provider VARCHAR(32) NOT NULL', 'external_customer_id VARCHAR(191) NOT NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'UNIQUE (provider, external_customer_id)', 'UNIQUE (user_id, provider)']),
            $table('subscriptions', ["id {$id}", "user_id {$big} NOT NULL", "plan_id {$big} NOT NULL", 'provider VARCHAR(32) NOT NULL', 'external_id VARCHAR(191) NOT NULL', "status VARCHAR(32) NOT NULL DEFAULT 'pending'", 'billing_period VARCHAR(16) NOT NULL', 'currency VARCHAR(3) NOT NULL', "amount_cents {$money} NOT NULL", 'current_period_start DATETIME NULL', 'current_period_end DATETIME NULL', 'grace_ends_at DATETIME NULL', "cancel_at_period_end {$bool} NOT NULL DEFAULT 0", 'canceled_at DATETIME NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT', 'FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE RESTRICT', 'UNIQUE (provider, external_id)']),
            $table('subscription_events', ["id {$id}", "subscription_id {$big} NOT NULL", 'provider_event_id VARCHAR(191) NOT NULL', 'event_type VARCHAR(100) NOT NULL', 'status_before VARCHAR(32) NULL', 'status_after VARCHAR(32) NOT NULL', "event_json {$json} NULL", 'occurred_at DATETIME NOT NULL', 'created_at DATETIME NOT NULL', 'FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE', 'UNIQUE (provider_event_id)']),
            $table('products', ["id {$id}", 'product_key VARCHAR(100) NOT NULL UNIQUE', 'provider_id VARCHAR(191) NULL', 'name VARCHAR(150) NOT NULL', 'description TEXT NOT NULL', 'preview_path VARCHAR(255) NULL', "fixed_contents_json {$json} NOT NULL", 'entitlement_key VARCHAR(100) NOT NULL', 'refund_policy_reference VARCHAR(100) NOT NULL', "active {$bool} NOT NULL DEFAULT 1", 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL']),
            $table('product_prices', ["id {$id}", "product_id {$big} NOT NULL", "amount_cents {$money} NOT NULL", 'currency VARCHAR(3) NOT NULL', 'provider VARCHAR(32) NOT NULL', 'provider_price_id VARCHAR(191) NULL', "active {$bool} NOT NULL DEFAULT 1", 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE', 'UNIQUE (product_id, provider, currency)', 'CHECK (amount_cents >= 0)']),
            $table('product_entitlements', ["product_id {$big} NOT NULL", 'entitlement_key VARCHAR(100) NOT NULL', 'PRIMARY KEY (product_id, entitlement_key)', 'FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE']),
            $table('payments', ["id {$id}", "user_id {$big} NOT NULL", "product_id {$big} NULL", "subscription_id {$big} NULL", 'provider VARCHAR(32) NOT NULL', 'external_id VARCHAR(191) NOT NULL', 'status VARCHAR(32) NOT NULL', "amount_cents {$money} NOT NULL", 'currency VARCHAR(3) NOT NULL', 'receipt_url VARCHAR(500) NULL', "provider_metadata_json {$json} NULL", 'paid_at DATETIME NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT', 'FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT', 'FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL', 'UNIQUE (provider, external_id)', 'CHECK (amount_cents >= 0)']),
            $table('payment_attempts', ["id {$id}", "user_id {$big} NOT NULL", "payment_id {$big} NULL", 'provider VARCHAR(32) NOT NULL', 'idempotency_key VARCHAR(191) NOT NULL UNIQUE', 'status VARCHAR(32) NOT NULL', 'failure_code VARCHAR(100) NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT', 'FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL']),
            $table('payment_idempotency_keys', ['idempotency_key VARCHAR(191) PRIMARY KEY', 'operation VARCHAR(100) NOT NULL', 'request_hash VARCHAR(64) NOT NULL', "response_json {$json} NULL", 'status VARCHAR(32) NOT NULL', 'expires_at DATETIME NOT NULL', 'created_at DATETIME NOT NULL']),
            $table('checkout_intents', ['intent_key VARCHAR(64) PRIMARY KEY', "user_id {$big} NOT NULL", 'checkout_type VARCHAR(32) NOT NULL', 'item_key VARCHAR(100) NOT NULL', "billing_period VARCHAR(16) NOT NULL DEFAULT ''", 'provider VARCHAR(32) NOT NULL', 'provider_environment VARCHAR(16) NOT NULL', 'idempotency_key VARCHAR(191) NOT NULL UNIQUE', "status VARCHAR(32) NOT NULL DEFAULT 'processing'", 'provider_external_id VARCHAR(191) NULL', 'expires_at DATETIME NOT NULL', 'terminal_at DATETIME NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'UNIQUE (user_id, checkout_type, item_key, billing_period, provider, provider_environment)']),
            $table('refunds', ["id {$id}", "payment_id {$big} NOT NULL", 'provider VARCHAR(32) NOT NULL', 'external_id VARCHAR(191) NOT NULL', "amount_cents {$money} NOT NULL", 'status VARCHAR(32) NOT NULL', 'reason VARCHAR(255) NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT', 'UNIQUE (provider, external_id)', 'CHECK (amount_cents >= 0)']),
            $table('refund_requests', [
                "id {$id}", "payment_id {$big} NOT NULL", "requested_amount_cents {$money} NOT NULL",
                "status VARCHAR(32) NOT NULL DEFAULT 'requested'", 'request_reason VARCHAR(500) NOT NULL',
                'resolution_note VARCHAR(500) NULL', "requested_by {$big} NULL", "updated_by {$big} NULL",
                'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL',
                'FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT',
                'FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL',
                'FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL',
                'CHECK (requested_amount_cents > 0)',
            ]),
            $table('refund_executions', [
                "id {$id}", "refund_request_id {$big} NOT NULL", "payment_id {$big} NOT NULL",
                'provider VARCHAR(32) NOT NULL', 'idempotency_key VARCHAR(191) NOT NULL',
                "amount_cents {$money} NOT NULL", "status VARCHAR(32) NOT NULL DEFAULT 'not_started'",
                'provider_refund_id VARCHAR(191) NULL', 'provider_status VARCHAR(64) NULL',
                "attempt_count {$int} NOT NULL DEFAULT 0", 'last_attempt_at DATETIME NULL',
                'confirmed_at DATETIME NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL',
                'FOREIGN KEY (refund_request_id) REFERENCES refund_requests(id) ON DELETE RESTRICT',
                'FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT',
                'UNIQUE (refund_request_id)', 'UNIQUE (idempotency_key)',
                'CHECK (amount_cents > 0)',
            ]),
            $table('disputes', ["id {$id}", "payment_id {$big} NULL", 'provider VARCHAR(32) NOT NULL', 'external_id VARCHAR(191) NOT NULL', 'status VARCHAR(32) NOT NULL', "amount_cents {$money} NULL", 'reason VARCHAR(255) NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL', 'UNIQUE (provider, external_id)']),
            $table('user_entitlements', ["id {$id}", "user_id {$big} NOT NULL", 'entitlement_key VARCHAR(100) NOT NULL', 'source_type VARCHAR(32) NOT NULL', 'source_id VARCHAR(191) NOT NULL', 'starts_at DATETIME NOT NULL', 'ends_at DATETIME NULL', 'revoked_at DATETIME NULL', 'revocation_reason VARCHAR(100) NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'UNIQUE (user_id, entitlement_key, source_type, source_id)']),
            $table('invoices', ["id {$id}", "user_id {$big} NOT NULL", "payment_id {$big} NULL", 'provider VARCHAR(32) NOT NULL', 'external_id VARCHAR(191) NULL', 'status VARCHAR(32) NOT NULL', "amount_cents {$money} NOT NULL", 'currency VARCHAR(3) NOT NULL', 'issued_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT', 'FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL']),
            $table('webhook_events', ["id {$id}", 'provider VARCHAR(32) NOT NULL', 'provider_event_id VARCHAR(191) NOT NULL', 'event_type VARCHAR(150) NOT NULL', 'signature_valid INTEGER NOT NULL', 'status VARCHAR(32) NOT NULL', 'payload_hash VARCHAR(64) NOT NULL', "payload_json {$json} NULL", 'error_message VARCHAR(500) NULL', 'received_at DATETIME NOT NULL', 'processed_at DATETIME NULL', 'expires_at DATETIME NULL', 'UNIQUE (provider, provider_event_id)']),
            $table('payment_audit_logs', ["id {$id}", "actor_user_id {$big} NULL", 'action VARCHAR(100) NOT NULL', 'target_type VARCHAR(64) NOT NULL', 'target_id VARCHAR(191) NULL', "details_json {$json} NULL", 'request_id VARCHAR(64) NULL', 'entry_hash VARCHAR(64) NOT NULL', 'previous_hash VARCHAR(64) NOT NULL', 'created_at DATETIME NOT NULL', 'FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL']),
            $table('advertising_slots', ["id {$id}", 'slot_key VARCHAR(100) NOT NULL UNIQUE', 'provider VARCHAR(50) NOT NULL', "enabled {$bool} NOT NULL DEFAULT 0", "configuration_json {$json} NULL", 'updated_at DATETIME NOT NULL']),
            $table('sponsors', ["id {$id}", 'name VARCHAR(150) NOT NULL', 'website_url VARCHAR(500) NULL', 'status VARCHAR(32) NOT NULL', 'starts_at DATETIME NULL', 'ends_at DATETIME NULL', "metadata_json {$json} NULL", 'created_at DATETIME NOT NULL']),
            $table('licensing_inquiries', ["id {$id}", 'name VARCHAR(100) NOT NULL', 'business_name VARCHAR(150) NULL', 'email VARCHAR(254) NOT NULL', 'service_requested VARCHAR(100) NOT NULL', 'budget_range VARCHAR(100) NULL', 'message TEXT NOT NULL', "consent {$bool} NOT NULL", 'spam_score INTEGER NOT NULL DEFAULT 0', 'status VARCHAR(32) NOT NULL DEFAULT \'new\'', 'submitted_at DATETIME NOT NULL']),
            $table('contact_messages', ["id {$id}", "user_id {$big} NULL", 'name VARCHAR(100) NOT NULL', 'email VARCHAR(254) NOT NULL', 'subject VARCHAR(150) NOT NULL', 'message TEXT NOT NULL', 'status VARCHAR(32) NOT NULL DEFAULT \'new\'', 'spam_score INTEGER NOT NULL DEFAULT 0', 'submitted_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL']),
            $table('support_tickets', ["id {$id}", "user_id {$big} NOT NULL", 'subject VARCHAR(150) NOT NULL', 'status VARCHAR(32) NOT NULL', 'priority VARCHAR(16) NOT NULL', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT']),
            $table('email_queue', ["id {$id}", 'recipient_email VARCHAR(254) NOT NULL', 'recipient_name VARCHAR(100) NULL', 'template_key VARCHAR(100) NOT NULL', 'subject VARCHAR(255) NOT NULL', 'html_body TEXT NOT NULL', 'text_body TEXT NOT NULL', "headers_json {$json} NULL", 'status VARCHAR(32) NOT NULL DEFAULT \'queued\'', 'attempts INTEGER NOT NULL DEFAULT 0', 'available_at DATETIME NOT NULL', 'locked_at DATETIME NULL', 'sent_at DATETIME NULL', 'last_error VARCHAR(500) NULL', 'created_at DATETIME NOT NULL']),
            $table('notifications', ["id {$id}", "user_id {$big} NOT NULL", 'type VARCHAR(100) NOT NULL', "data_json {$json} NOT NULL", 'read_at DATETIME NULL', 'created_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE']),
            $table('managed_content_revisions', [
                "id {$id}", 'content_type VARCHAR(32) NOT NULL', 'content_key VARCHAR(100) NOT NULL',
                'version_label VARCHAR(32) NOT NULL', 'title_text VARCHAR(200) NOT NULL', 'body_text TEXT NOT NULL',
                "placeholders_json {$json} NOT NULL", "status VARCHAR(32) NOT NULL DEFAULT 'draft'",
                "based_on_revision_id {$big} NULL", "created_by {$big} NOT NULL", "approved_by {$big} NULL", "published_by {$big} NULL",
                'created_at DATETIME NOT NULL', 'approved_at DATETIME NULL', 'published_at DATETIME NULL',
                'UNIQUE (content_type, content_key, version_label)',
                'FOREIGN KEY (based_on_revision_id) REFERENCES managed_content_revisions(id) ON DELETE SET NULL',
                'FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT',
                'FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL',
                'FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL',
            ]),
            $table('system_settings', ['setting_key VARCHAR(191) PRIMARY KEY', "setting_value {$json} NOT NULL", "is_sensitive {$bool} NOT NULL DEFAULT 0", "updated_by {$big} NULL", 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL']),
            $table('admin_audit_logs', ["id {$id}", "administrator_id {$big} NULL", 'action VARCHAR(100) NOT NULL', 'target_type VARCHAR(100) NOT NULL', 'target_id VARCHAR(191) NULL', "previous_json {$json} NULL", "new_json {$json} NULL", 'reason VARCHAR(500) NULL', 'request_id VARCHAR(64) NOT NULL', 'ip_hash VARCHAR(64) NULL', 'previous_hash VARCHAR(64) NOT NULL', 'entry_hash VARCHAR(64) NOT NULL', 'created_at DATETIME NOT NULL', 'FOREIGN KEY (administrator_id) REFERENCES users(id) ON DELETE SET NULL']),
            $table('cron_runs', ["id {$id}", 'run_key VARCHAR(100) NOT NULL', 'status VARCHAR(32) NOT NULL', 'started_at DATETIME NOT NULL', 'finished_at DATETIME NULL', "details_json {$json} NULL"]),
            $table('cron_locks', ['lock_key VARCHAR(100) PRIMARY KEY', 'owner_token VARCHAR(64) NOT NULL', 'expires_at DATETIME NOT NULL', 'acquired_at DATETIME NOT NULL']),
            $table('backup_records', ["id {$id}", 'filename VARCHAR(255) NOT NULL', 'path_hash VARCHAR(64) NOT NULL', 'checksum_sha256 VARCHAR(64) NOT NULL', 'size_bytes BIGINT NOT NULL', 'status VARCHAR(32) NOT NULL', 'created_at DATETIME NOT NULL']),
            $table('analytics_events', ["id {$id}", 'event_type VARCHAR(64) NOT NULL', 'visitor_hash VARCHAR(64) NULL', "user_id {$big} NULL", 'device_category VARCHAR(32) NULL', "duration_ms {$int} NULL", "metadata_json {$json} NULL", 'occurred_at DATETIME NOT NULL', 'expires_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL']),
            $table('data_export_requests', ["id {$id}", "user_id {$big} NOT NULL", 'status VARCHAR(32) NOT NULL', 'file_path VARCHAR(500) NULL', 'expires_at DATETIME NULL', 'created_at DATETIME NOT NULL', 'completed_at DATETIME NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE']),
            $table('responsible_play_controls', ["user_id {$big} PRIMARY KEY", "session_reminder_minutes {$int} NULL", "daily_limit_minutes {$int} NULL", 'cooldown_until DATETIME NULL', 'self_excluded_until DATETIME NULL', "autoplay_allowed {$bool} NOT NULL DEFAULT 0", 'updated_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE']),
            $table('guest_conversions', ["id {$id}", 'guest_token_hash VARCHAR(64) NOT NULL UNIQUE', "user_id {$big} NOT NULL", 'idempotency_key VARCHAR(191) NOT NULL UNIQUE', 'converted_at DATETIME NOT NULL', 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE']),
            $table('game_seed_queue', ['owner_key VARCHAR(191) PRIMARY KEY', 'commit_hash VARCHAR(64) NOT NULL', 'server_seed_encrypted TEXT NOT NULL', "version {$int} NOT NULL DEFAULT 1", 'created_at DATETIME NOT NULL', 'consumed_at DATETIME NULL']),
        ];
    }

    /** @return list<string> */
    private static function indexes(): array
    {
        return [
            'CREATE INDEX idx_users_status ON users(status)',
            'CREATE INDEX idx_sessions_user_active ON sessions(user_id, revoked_at, expires_at)',
            'CREATE INDEX idx_login_attempts_lookup ON login_attempts(email_hash, ip_hash, attempted_at)',
            'CREATE INDEX idx_security_events_created ON security_events(event_type, created_at)',
            'CREATE INDEX idx_ledger_user_currency ON virtual_ledger_entries(user_id, currency, id)',
            'CREATE INDEX idx_game_rounds_user_status ON game_rounds(user_id, status, started_at)',
            'CREATE INDEX idx_subscriptions_user_status ON subscriptions(user_id, status)',
            'CREATE INDEX idx_payments_user_created ON payments(user_id, created_at)',
            'CREATE INDEX idx_refund_requests_status ON refund_requests(status, updated_at)',
            'CREATE INDEX idx_refund_executions_payment_status ON refund_executions(payment_id, status)',
            'CREATE INDEX idx_entitlements_active ON user_entitlements(user_id, entitlement_key, revoked_at, ends_at)',
            'CREATE INDEX idx_webhook_status ON webhook_events(status, received_at)',
            'CREATE INDEX idx_email_queue_due ON email_queue(status, available_at)',
            'CREATE INDEX idx_managed_content_lookup ON managed_content_revisions(content_type, content_key, status, id)',
            'CREATE INDEX idx_audit_created ON admin_audit_logs(created_at)',
            'CREATE INDEX idx_cron_runs_key ON cron_runs(run_key, started_at)',
            'CREATE INDEX idx_analytics_expiry ON analytics_events(expires_at)',
        ];
    }

    /** @return list<string> */
    private static function appendOnlyTriggers(string $driver): array
    {
        $tables = ['virtual_ledger_entries', 'admin_audit_logs', 'payment_audit_logs', 'subscription_events'];
        $triggers = [];
        foreach ($tables as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = 'protect_' . $table . '_' . strtolower($operation);
                $triggers[] = $driver === 'mysql'
                    ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table'"
                    : "CREATE TRIGGER IF NOT EXISTS {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'append-only table'); END";
            }
        }
        return $triggers;
    }
}
