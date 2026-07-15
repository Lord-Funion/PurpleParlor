<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Config;
use App\Database\Database;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;

final class DatabaseSeeder
{
    public function __construct(private readonly Database $database, private readonly Config $config)
    {
    }

    public function run(): array
    {
        $counts = [];
        $this->database->transaction(function () use (&$counts): void {
            $counts['permissions'] = $this->seedPermissions();
            $counts['roles'] = $this->seedRoles();
            $counts['plans'] = $this->seedPlans();
            $counts['products'] = $this->seedProducts();
            $counts['games'] = $this->seedGames();
            $counts['achievements'] = $this->seedAchievements();
            $counts['missions'] = $this->seedMissions();
            $counts['leaderboards'] = $this->seedLeaderboards();
            $counts['advertising_slots'] = $this->seedAdvertisingSlots();
            $counts['settings'] = $this->seedSettings();
            $counts['development_accounts'] = $this->seedDevelopmentAccount();
        });
        return $counts;
    }

    private function seedPermissions(): int
    {
        $permissions = (array) $this->config->get('permissions.permissions', []);
        $count = 0;
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                continue;
            }
            $count += $this->insertIgnore('permissions', ['name' => $permission, 'description' => ucwords(str_replace(['.', '_'], ' ', $permission)), 'created_at' => gmdate('Y-m-d H:i:s')], ['name']);
        }
        return $count;
    }

    private function seedRoles(): int
    {
        $roles = (array) $this->config->get('permissions.roles', []);
        $count = 0;
        foreach ($roles as $role => $permissions) {
            $count += $this->insertIgnore('roles', ['name' => $role, 'description' => ucwords(str_replace('_', ' ', (string) $role)), 'created_at' => gmdate('Y-m-d H:i:s')], ['name']);
            $roleRow = $this->database->fetchOne('SELECT id FROM roles WHERE name = :name', ['name' => $role]);
            foreach ((array) $permissions as $permission) {
                $permissionRow = $this->database->fetchOne('SELECT id FROM permissions WHERE name = :name', ['name' => $permission]);
                if ($roleRow !== null && $permissionRow !== null) {
                    $this->insertIgnore('role_permissions', ['role_id' => $roleRow['id'], 'permission_id' => $permissionRow['id']], ['role_id', 'permission_id']);
                }
            }
        }
        return $count;
    }

    private function seedPlans(): int
    {
        $plans = [
            ['free', 'Free', 'All standard games and core account features.', []],
            ['cozy_club', 'Cozy Club', 'Ad-free lounge membership with premium themes, statistics, profile perks, and a fixed daily Cozy Coin grant.', ['ads.disabled', 'theme.purple_premium', 'theme.fireplace', 'statistics.advanced', 'supporter.badge']],
            ['cozy_club_plus', 'Cozy Club Plus', 'Expanded lounge membership with every premium interface perk and a larger fixed daily Cozy Coin grant.', ['ads.disabled', 'theme.purple_premium', 'theme.fireplace', 'statistics.advanced', 'statistics.export', 'layout.expanded', 'profile.animated_crown', 'supporter.badge']],
        ];
        $count = 0;
        foreach ($plans as $sort => [$key, $name, $description, $benefits]) {
            $count += $this->insertIgnore('membership_plans', ['plan_key' => $key, 'name' => $name, 'description' => $description,
                'benefits_json' => json_encode($benefits, JSON_THROW_ON_ERROR), 'active' => 1, 'sort_order' => $sort, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')], ['plan_key']);
            $this->database->execute('UPDATE membership_plans SET name = :name, description = :description, benefits_json = :benefits, sort_order = :sort, updated_at = :updated WHERE plan_key = :key', [
                'name' => $name,
                'description' => $description,
                'benefits' => json_encode($benefits, JSON_THROW_ON_ERROR),
                'sort' => $sort,
                'updated' => gmdate('Y-m-d H:i:s'),
                'key' => $key,
            ]);
        }
        $prices = [
            ['cozy_club', 'monthly', 299, 'demo', 'demo_cozy_monthly'], ['cozy_club', 'annual', 2999, 'demo', 'demo_cozy_annual'],
            ['cozy_club_plus', 'monthly', 599, 'demo', 'demo_plus_monthly'], ['cozy_club_plus', 'annual', 5999, 'demo', 'demo_plus_annual'],
        ];
        foreach (['paypal', 'square'] as $provider) {
            $providerMap = (array) $this->config->get("payments.{$provider}.plans", []);
            foreach ([['cozy_club', 'monthly', 299, 'cozy.monthly'], ['cozy_club', 'annual', 2999, 'cozy.annual'],
                ['cozy_club_plus', 'monthly', 599, 'plus.monthly'], ['cozy_club_plus', 'annual', 5999, 'plus.annual']] as [$plan, $period, $amount, $mapKey]) {
                if (trim((string) ($providerMap[$mapKey] ?? '')) !== '') {
                    $prices[] = [$plan, $period, $amount, $provider, (string) $providerMap[$mapKey]];
                }
            }
        }
        foreach ($prices as [$planKey, $period, $amount, $provider, $providerId]) {
            $plan = $this->database->fetchOne('SELECT id FROM membership_plans WHERE plan_key = :key', ['key' => $planKey]);
            if ($plan !== null) {
                $this->insertIgnore('membership_plan_prices', ['plan_id' => $plan['id'], 'billing_period' => $period, 'amount_cents' => $amount,
                    'currency' => 'USD', 'provider' => $provider, 'provider_plan_id' => $providerId, 'active' => 1,
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')], ['plan_id', 'billing_period', 'provider', 'currency']);
            }
        }
        return $count;
    }

    private function seedProducts(): int
    {
        $products = [
            ['purple_theme_pack', 'Royal Plum Theme', 'Permanent premium Royal Plum visual theme.', 'theme.purple_premium', 399],
            ['royal_onion_profile_pack', 'Royal Onion Profile Frame', 'Permanent fixed royal frame for the member profile.', 'profile.royal_frame', 299],
            ['cozy_fireplace_soundtrack', 'Cozy Fireplace Theme', 'Permanent cozy fireplace visual theme.', 'theme.fireplace', 399],
            ['animated_crown_decoration', 'Animated Crown Decoration', 'A fixed profile decoration.', 'profile.animated_crown', 249],
            ['lifetime_ad_free', 'Lifetime Ad-Free Upgrade', 'Permanently disables display advertising for this account.', 'ads.disabled', 1999],
            ['founder_supporter_badge', 'Founder Supporter Badge', 'A fixed supporter profile badge.', 'founder.badge', 499],
        ];
        $count = 0;
        foreach ($products as [$key, $name, $description, $entitlement, $price]) {
            $count += $this->insertIgnore('products', ['product_key' => $key, 'name' => $name, 'description' => $description, 'fixed_contents_json' => json_encode([$entitlement], JSON_THROW_ON_ERROR),
                'entitlement_key' => $entitlement, 'refund_policy_reference' => 'refund-policy-v1', 'active' => 1,
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')], ['product_key']);
            $product = $this->database->fetchOne('SELECT id FROM products WHERE product_key = :key', ['key' => $key]);
            if ($product !== null) {
                $this->insertIgnore('product_entitlements', ['product_id' => $product['id'], 'entitlement_key' => $entitlement], ['product_id', 'entitlement_key']);
                foreach (['demo', 'square'] as $provider) {
                    $this->insertIgnore('product_prices', ['product_id' => $product['id'], 'amount_cents' => $price, 'currency' => 'USD', 'provider' => $provider,
                        'active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')], ['product_id', 'provider', 'currency']);
                }
            }
        }
        return $count;
    }

    private function seedGames(): int
    {
        $games = (array) $this->config->get('games', []);
        if (count($games) !== 40) {
            throw new \RuntimeException('The authoritative game configuration must contain exactly 40 games before seeding.');
        }
        $count = 0;
        foreach ($games as $configuredSlug => $game) {
            if (!is_array($game) || ($game['slug'] ?? null) !== $configuredSlug
                || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $configuredSlug)
                || trim((string) ($game['name'] ?? '')) === '' || trim((string) ($game['category'] ?? '')) === '') {
                throw new \RuntimeException('A game configuration is malformed or its array key does not match its slug.');
            }
            $count += $this->insertIgnore('game_definitions', [
                'slug' => $configuredSlug,
                'name' => (string) $game['name'],
                'category' => (string) $game['category'],
                'active' => 1,
                'configuration_json' => json_encode($game, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['slug']);
        }
        return $count;
    }

    private function seedAchievements(): int
    {
        $items = [['welcome','Welcome to the Parlor','Create and verify an account.',0,25], ['first_round','First Round','Complete any game round.',250,10], ['rules_reader','Rules Reader','Read a game rules page.',100,5]];
        $count = 0;
        foreach ($items as [$key,$name,$description,$coins,$stars]) {
            $count += $this->insertIgnore('achievements', ['achievement_key'=>$key,'name'=>$name,'description'=>$description,'reward_coins'=>$coins,'reward_stars'=>$stars,'active'=>1,'created_at'=>gmdate('Y-m-d H:i:s')], ['achievement_key']);
        }
        return $count;
    }

    private function seedMissions(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $end = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
        $items = [['weekly_variety','Parlor Sampler','Complete rounds in several games.',10,500,20], ['weekly_rules','Know the Tables','Visit five rules pages.',5,250,15]];
        $count = 0;
        foreach ($items as [$key,$name,$description,$target,$coins,$stars]) {
            $count += $this->insertIgnore('missions', ['mission_key'=>$key,'name'=>$name,'description'=>$description,'target_value'=>$target,'reward_coins'=>$coins,'reward_stars'=>$stars,'starts_at'=>$now,'ends_at'=>$end,'active'=>1], ['mission_key']);
        }
        return $count;
    }

    private function seedLeaderboards(): int
    {
        return $this->insertIgnore('leaderboards', [
            'leaderboard_key' => 'fictional_achievement_points',
            'name' => 'Fictional Achievement Points',
            'configuration_json' => json_encode([
                'metric' => 'server_recorded_fictional_points',
                'cash_value' => false,
                'privacy_required' => true,
            ], JSON_THROW_ON_ERROR),
            'starts_at' => null,
            'ends_at' => null,
            'active' => 1,
        ], ['leaderboard_key']);
    }

    private function seedAdvertisingSlots(): int
    {
        $slots = [
            ['home_lounge_footer', [
                'headline' => 'Sponsor a thoughtfully designed lounge placement',
                'body' => 'Partnerships are reviewed by the Adult Owner and are always labeled and separated from play controls.',
                'cta_label' => 'Sponsorship details',
                'destination_url' => '/sponsor',
                'frequency_cap_per_session' => 1,
            ]],
            ['game_lobby_footer', [
                'headline' => 'Support original play-money entertainment',
                'body' => 'Learn about fixed, clearly disclosed lounge sponsorships with no effect on games, odds, or fictional payouts.',
                'cta_label' => 'Advertising policy',
                'destination_url' => '/legal/advertising',
                'frequency_cap_per_session' => 1,
            ]],
        ];
        $count = 0;
        foreach ($slots as [$slotKey, $configuration]) {
            $count += $this->insertIgnore('advertising_slots', [
                'slot_key' => $slotKey,
                'provider' => 'placeholder',
                'enabled' => 0,
                'configuration_json' => json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['slot_key']);
        }
        return $count;
    }

    private function seedSettings(): int
    {
        $settings = [
            'site.brand' => ['name' => 'The Purple Parlor', 'creator' => 'Lord Funion'],
            'site.theme' => ['default' => 'purple-parlor', 'available' => ['purple-parlor','midnight-lavender','cozy-fireplace','royal-plum','soft-daylight','high-contrast']],
            'virtual_currency.disclaimer' => 'Virtual currency has no cash value, is not sold separately, and cannot be transferred, redeemed, withdrawn, or exchanged for anything of value.',
            'payments.production_locked' => true,
            'payments.demo_scenarios' => ['success','failed','pending','canceled','renewal','failed_renewal','grace','expiration','refund','dispute','duplicate','invalid_signature'],
        ];
        $count = 0;
        foreach ($settings as $key => $value) {
            $count += $this->insertIgnore('system_settings', ['setting_key' => $key, 'setting_value' => json_encode($value, JSON_THROW_ON_ERROR), 'is_sensitive' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')], ['setting_key']);
            if ($key === 'virtual_currency.disclaimer') {
                $this->database->execute('UPDATE system_settings SET setting_value = :value, updated_at = :updated WHERE setting_key = :key', [
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'updated' => gmdate('Y-m-d H:i:s'),
                    'key' => $key,
                ]);
            }
        }
        return $count;
    }

    private function seedDevelopmentAccount(): int
    {
        if ($this->config->get('app.env') === 'production') {
            return 0;
        }
        $email = trim((string) env('DEV_SEED_EMAIL', ''));
        $username = trim((string) env('DEV_SEED_USERNAME', ''));
        $password = (string) env('DEV_SEED_PASSWORD', '');
        if ($email === '' || $username === '' || $password === '') {
            return 0;
        }
        if ($this->database->fetchOne('SELECT id FROM users WHERE email_normalized = :email', ['email' => strtolower($email)]) !== null) {
            return 0;
        }
        $users = new UserRepository($this->database);
        $user = $users->create($email, $username, (new PasswordHasher())->hash($password), 'active');
        $users->markVerified($user->id);
        $users->assignRole($user->id, 'member');
        return 1;
    }

    /** @param array<string,mixed> $data @param list<string> $unique */
    private function insertIgnore(string $table, array $data, array $unique): int
    {
        $where = [];
        $params = [];
        foreach ($unique as $column) {
            $where[] = $column . ' = :u_' . $column;
            $params['u_' . $column] = $data[$column];
        }
        if ($this->database->fetchOne('SELECT 1 AS found FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1', $params) !== null) {
            return 0;
        }
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':v_' . $column, $columns);
        $values = [];
        foreach ($data as $column => $value) {
            $values['v_' . $column] = $value;
        }
        $this->database->execute('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')', $values);
        return 1;
    }
}
