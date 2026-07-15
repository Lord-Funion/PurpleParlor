-- Generated from App\Database\Schema. Use UTF-8.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS schema_migrations (
  migration VARCHAR(255) PRIMARY KEY,
  checksum VARCHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  email_normalized VARCHAR(254) NOT NULL,
  username VARCHAR(50) NOT NULL,
  username_normalized VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending_verification',
  email_verified_at DATETIME NULL,
  pending_email VARCHAR(254) NULL,
  failed_login_count INTEGER NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  username_changed_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  UNIQUE (email_normalized),
  UNIQUE (username_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  display_name VARCHAR(80) NULL,
  bio VARCHAR(500) NULL,
  avatar_key VARCHAR(100) NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  stats_public TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_settings (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  settings_json JSON NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  selector VARCHAR(32) NOT NULL UNIQUE,
  token_hash VARCHAR(64) NOT NULL,
  php_session_id VARCHAR(128) NULL,
  ip_hash VARCHAR(64) NOT NULL,
  user_agent VARCHAR(255) NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  selector VARCHAR(32) NOT NULL UNIQUE,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,
  new_email VARCHAR(254) NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS two_factor_secrets (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  encrypted_secret TEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  confirmed_at DATETIME NULL,
  last_counter BIGINT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recovery_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_hash VARCHAR(64) NOT NULL,
  ip_hash VARCHAR(64) NOT NULL,
  successful TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key VARCHAR(191) PRIMARY KEY,
  hits INTEGER NOT NULL,
  window_started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(100) NOT NULL,
  severity VARCHAR(20) NOT NULL,
  ip_hash VARCHAR(64) NULL,
  request_id VARCHAR(64) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS age_confirmations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  policy_version VARCHAR(32) NOT NULL,
  confirmed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legal_acceptances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  document_key VARCHAR(100) NOT NULL,
  document_version VARCHAR(32) NOT NULL,
  ip_hash VARCHAR(64) NULL,
  accepted_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE (user_id, document_key, document_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_wallets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  currency VARCHAR(32) NOT NULL,
  balance BIGINT NOT NULL DEFAULT 0,
  version INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE (user_id, currency),
  CHECK (balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_ledger_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  currency VARCHAR(32) NOT NULL,
  amount BIGINT NOT NULL,
  balance_before BIGINT NOT NULL,
  balance_after BIGINT NOT NULL,
  reason_code VARCHAR(100) NOT NULL,
  related_game_round_id BIGINT UNSIGNED NULL,
  related_achievement_id BIGINT UNSIGNED NULL,
  related_mission_id BIGINT UNSIGNED NULL,
  administrator_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  previous_hash VARCHAR(64) NOT NULL,
  entry_hash VARCHAR(64) NOT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (administrator_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE (idempotency_key),
  CHECK (balance_after >= 0),
  CHECK (balance_after = balance_before + amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_definitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  category VARCHAR(64) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  configuration_json JSON NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_configurations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  version INTEGER NOT NULL,
  configuration_json JSON NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE CASCADE,
  UNIQUE (game_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_rounds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  public_id VARCHAR(64) NOT NULL UNIQUE,
  status VARCHAR(32) NOT NULL,
  wager_amount BIGINT NOT NULL,
  payout_amount BIGINT NULL,
  currency VARCHAR(32) NOT NULL,
  client_seed VARCHAR(128) NULL,
  server_seed_hash VARCHAR(64) NOT NULL,
  server_seed_encrypted TEXT NOT NULL,
  request_id VARCHAR(64) NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL UNIQUE,
  started_at DATETIME NOT NULL,
  settled_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_round_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  round_id BIGINT UNSIGNED NOT NULL,
  sequence_number INTEGER NOT NULL,
  action_type VARCHAR(64) NOT NULL,
  action_json JSON NOT NULL,
  result_json JSON NULL,
  idempotency_key VARCHAR(191) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (round_id) REFERENCES game_rounds(id) ON DELETE CASCADE,
  UNIQUE (round_id, sequence_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_seed_commits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  round_id BIGINT UNSIGNED NOT NULL UNIQUE,
  commit_hash VARCHAR(64) NOT NULL,
  server_seed VARCHAR(191) NULL,
  revealed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (round_id) REFERENCES game_rounds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_favorites (
  user_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, game_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recent_games (
  user_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  last_played_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, game_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_statistics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NULL,
  stat_key VARCHAR(100) NOT NULL,
  stat_value BIGINT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (game_id) REFERENCES game_definitions(id) ON DELETE CASCADE,
  UNIQUE (user_id, game_id, stat_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_reward_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  reward_date DATE NOT NULL,
  amount BIGINT NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL UNIQUE,
  claimed_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE (user_id, reward_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS achievements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  achievement_key VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(500) NOT NULL,
  reward_coins BIGINT NOT NULL DEFAULT 0,
  reward_stars BIGINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_achievements (
  user_id BIGINT UNSIGNED NOT NULL,
  achievement_id BIGINT UNSIGNED NOT NULL,
  unlocked_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, achievement_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS missions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mission_key VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(500) NOT NULL,
  target_value INTEGER NOT NULL,
  reward_coins BIGINT NOT NULL DEFAULT 0,
  reward_stars BIGINT NOT NULL DEFAULT 0,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mission_progress (
  user_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NOT NULL,
  progress_value INTEGER NOT NULL DEFAULT 0,
  completed_at DATETIME NULL,
  rewarded_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, mission_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leaderboards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  leaderboard_key VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  configuration_json JSON NOT NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leaderboard_entries (
  leaderboard_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  score BIGINT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (leaderboard_id, user_id),
  FOREIGN KEY (leaderboard_id) REFERENCES leaderboards(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS membership_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_key VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description TEXT NOT NULL,
  benefits_json JSON NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS membership_plan_prices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  billing_period VARCHAR(16) NOT NULL,
  amount_cents BIGINT NOT NULL,
  currency VARCHAR(3) NOT NULL,
  provider VARCHAR(32) NOT NULL,
  provider_plan_id VARCHAR(191) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE CASCADE,
  UNIQUE (plan_id, billing_period, provider, currency),
  CHECK (amount_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_provider_customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  external_customer_id VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE (provider, external_customer_id),
  UNIQUE (user_id, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  billing_period VARCHAR(16) NOT NULL,
  currency VARCHAR(3) NOT NULL,
  amount_cents BIGINT NOT NULL,
  current_period_start DATETIME NULL,
  current_period_end DATETIME NULL,
  grace_ends_at DATETIME NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  canceled_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE RESTRICT,
  UNIQUE (provider, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subscription_id BIGINT UNSIGNED NOT NULL,
  provider_event_id VARCHAR(191) NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  status_before VARCHAR(32) NULL,
  status_after VARCHAR(32) NOT NULL,
  event_json JSON NULL,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
  UNIQUE (provider_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_key VARCHAR(100) NOT NULL UNIQUE,
  provider_id VARCHAR(191) NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  preview_path VARCHAR(255) NULL,
  fixed_contents_json JSON NOT NULL,
  entitlement_key VARCHAR(100) NOT NULL,
  refund_policy_reference VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_prices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  amount_cents BIGINT NOT NULL,
  currency VARCHAR(3) NOT NULL,
  provider VARCHAR(32) NOT NULL,
  provider_price_id VARCHAR(191) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE (product_id, provider, currency),
  CHECK (amount_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_entitlements (
  product_id BIGINT UNSIGNED NOT NULL,
  entitlement_key VARCHAR(100) NOT NULL,
  PRIMARY KEY (product_id, entitlement_key),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  subscription_id BIGINT UNSIGNED NULL,
  provider VARCHAR(32) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  status VARCHAR(32) NOT NULL,
  amount_cents BIGINT NOT NULL,
  currency VARCHAR(3) NOT NULL,
  receipt_url VARCHAR(500) NULL,
  provider_metadata_json JSON NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
  UNIQUE (provider, external_id),
  CHECK (amount_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  provider VARCHAR(32) NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL UNIQUE,
  status VARCHAR(32) NOT NULL,
  failure_code VARCHAR(100) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_idempotency_keys (
  idempotency_key VARCHAR(191) PRIMARY KEY,
  operation VARCHAR(100) NOT NULL,
  request_hash VARCHAR(64) NOT NULL,
  response_json JSON NULL,
  status VARCHAR(32) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkout_intents (
  intent_key VARCHAR(64) PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  checkout_type VARCHAR(32) NOT NULL,
  item_key VARCHAR(100) NOT NULL,
  billing_period VARCHAR(16) NOT NULL DEFAULT '',
  provider VARCHAR(32) NOT NULL,
  provider_environment VARCHAR(16) NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL UNIQUE,
  status VARCHAR(32) NOT NULL DEFAULT 'processing',
  provider_external_id VARCHAR(191) NULL,
  expires_at DATETIME NOT NULL,
  terminal_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE (user_id, checkout_type, item_key, billing_period, provider, provider_environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  amount_cents BIGINT NOT NULL,
  status VARCHAR(32) NOT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  UNIQUE (provider, external_id),
  CHECK (amount_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refund_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  requested_amount_cents BIGINT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'requested',
  request_reason VARCHAR(500) NOT NULL,
  resolution_note VARCHAR(500) NULL,
  requested_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (requested_amount_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refund_executions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  refund_request_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  amount_cents BIGINT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'not_started',
  provider_refund_id VARCHAR(191) NULL,
  provider_status VARCHAR(64) NULL,
  attempt_count INTEGER NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (refund_request_id) REFERENCES refund_requests(id) ON DELETE RESTRICT,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  UNIQUE (refund_request_id),
  UNIQUE (idempotency_key),
  CHECK (amount_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disputes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NULL,
  provider VARCHAR(32) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  status VARCHAR(32) NOT NULL,
  amount_cents BIGINT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  UNIQUE (provider, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_entitlements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  entitlement_key VARCHAR(100) NOT NULL,
  source_type VARCHAR(32) NOT NULL,
  source_id VARCHAR(191) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  revoked_at DATETIME NULL,
  revocation_reason VARCHAR(100) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE (user_id, entitlement_key, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  provider VARCHAR(32) NOT NULL,
  external_id VARCHAR(191) NULL,
  status VARCHAR(32) NOT NULL,
  amount_cents BIGINT NOT NULL,
  currency VARCHAR(3) NOT NULL,
  issued_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(32) NOT NULL,
  provider_event_id VARCHAR(191) NOT NULL,
  event_type VARCHAR(150) NOT NULL,
  signature_valid INTEGER NOT NULL,
  status VARCHAR(32) NOT NULL,
  payload_hash VARCHAR(64) NOT NULL,
  payload_json JSON NULL,
  error_message VARCHAR(500) NULL,
  received_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  expires_at DATETIME NULL,
  UNIQUE (provider, provider_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_id VARCHAR(191) NULL,
  details_json JSON NULL,
  request_id VARCHAR(64) NULL,
  entry_hash VARCHAR(64) NOT NULL,
  previous_hash VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advertising_slots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slot_key VARCHAR(100) NOT NULL UNIQUE,
  provider VARCHAR(50) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  configuration_json JSON NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  website_url VARCHAR(500) NULL,
  status VARCHAR(32) NOT NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licensing_inquiries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  business_name VARCHAR(150) NULL,
  email VARCHAR(254) NOT NULL,
  service_requested VARCHAR(100) NOT NULL,
  budget_range VARCHAR(100) NULL,
  message TEXT NOT NULL,
  consent TINYINT(1) NOT NULL,
  spam_score INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'new',
  submitted_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL,
  subject VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'new',
  spam_score INTEGER NOT NULL DEFAULT 0,
  submitted_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(150) NOT NULL,
  status VARCHAR(32) NOT NULL,
  priority VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipient_email VARCHAR(254) NOT NULL,
  recipient_name VARCHAR(100) NULL,
  template_key VARCHAR(100) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  html_body TEXT NOT NULL,
  text_body TEXT NOT NULL,
  headers_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  attempts INTEGER NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL,
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(100) NOT NULL,
  data_json JSON NOT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS managed_content_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  content_type VARCHAR(32) NOT NULL,
  content_key VARCHAR(100) NOT NULL,
  version_label VARCHAR(32) NOT NULL,
  title_text VARCHAR(200) NOT NULL,
  body_text TEXT NOT NULL,
  placeholders_json JSON NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  based_on_revision_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  published_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  approved_at DATETIME NULL,
  published_at DATETIME NULL,
  UNIQUE (content_type, content_key, version_label),
  FOREIGN KEY (based_on_revision_id) REFERENCES managed_content_revisions(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(191) PRIMARY KEY,
  setting_value JSON NOT NULL,
  is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  administrator_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  target_type VARCHAR(100) NOT NULL,
  target_id VARCHAR(191) NULL,
  previous_json JSON NULL,
  new_json JSON NULL,
  reason VARCHAR(500) NULL,
  request_id VARCHAR(64) NOT NULL,
  ip_hash VARCHAR(64) NULL,
  previous_hash VARCHAR(64) NOT NULL,
  entry_hash VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (administrator_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_key VARCHAR(100) NOT NULL,
  status VARCHAR(32) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  details_json JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_locks (
  lock_key VARCHAR(100) PRIMARY KEY,
  owner_token VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  acquired_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  path_hash VARCHAR(64) NOT NULL,
  checksum_sha256 VARCHAR(64) NOT NULL,
  size_bytes BIGINT NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(64) NOT NULL,
  visitor_hash VARCHAR(64) NULL,
  user_id BIGINT UNSIGNED NULL,
  device_category VARCHAR(32) NULL,
  duration_ms INTEGER NULL,
  metadata_json JSON NULL,
  occurred_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_export_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL,
  file_path VARCHAR(500) NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS responsible_play_controls (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  session_reminder_minutes INTEGER NULL,
  daily_limit_minutes INTEGER NULL,
  cooldown_until DATETIME NULL,
  self_excluded_until DATETIME NULL,
  autoplay_allowed TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_conversions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  guest_token_hash VARCHAR(64) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL UNIQUE,
  converted_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_seed_queue (
  owner_key VARCHAR(191) PRIMARY KEY,
  commit_hash VARCHAR(64) NOT NULL,
  server_seed_encrypted TEXT NOT NULL,
  version INTEGER NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  consumed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_users_status ON users(status);

CREATE INDEX idx_sessions_user_active ON sessions(user_id, revoked_at, expires_at);

CREATE INDEX idx_login_attempts_lookup ON login_attempts(email_hash, ip_hash, attempted_at);

CREATE INDEX idx_security_events_created ON security_events(event_type, created_at);

CREATE INDEX idx_ledger_user_currency ON virtual_ledger_entries(user_id, currency, id);

CREATE INDEX idx_game_rounds_user_status ON game_rounds(user_id, status, started_at);

CREATE INDEX idx_subscriptions_user_status ON subscriptions(user_id, status);

CREATE INDEX idx_payments_user_created ON payments(user_id, created_at);

CREATE INDEX idx_refund_requests_status ON refund_requests(status, updated_at);

CREATE INDEX idx_refund_executions_payment_status ON refund_executions(payment_id, status);

CREATE INDEX idx_entitlements_active ON user_entitlements(user_id, entitlement_key, revoked_at, ends_at);

CREATE INDEX idx_webhook_status ON webhook_events(status, received_at);

CREATE INDEX idx_email_queue_due ON email_queue(status, available_at);

CREATE INDEX idx_managed_content_lookup ON managed_content_revisions(content_type, content_key, status, id);

CREATE INDEX idx_audit_created ON admin_audit_logs(created_at);

CREATE INDEX idx_cron_runs_key ON cron_runs(run_key, started_at);

CREATE INDEX idx_analytics_expiry ON analytics_events(expires_at);

CREATE TRIGGER protect_virtual_ledger_entries_update BEFORE UPDATE ON virtual_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table';

CREATE TRIGGER protect_virtual_ledger_entries_delete BEFORE DELETE ON virtual_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table';

CREATE TRIGGER protect_admin_audit_logs_update BEFORE UPDATE ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table';

CREATE TRIGGER protect_admin_audit_logs_delete BEFORE DELETE ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table';

CREATE TRIGGER protect_payment_audit_logs_update BEFORE UPDATE ON payment_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table';

CREATE TRIGGER protect_payment_audit_logs_delete BEFORE DELETE ON payment_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table';

CREATE TRIGGER protect_subscription_events_update BEFORE UPDATE ON subscription_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table';

CREATE TRIGGER protect_subscription_events_delete BEFORE DELETE ON subscription_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table';

SET FOREIGN_KEY_CHECKS=1;
