<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Http\View;
use App\Models\User;
use App\Services\AdvertisingService;
use App\Services\EntitlementService;
use App\Services\ThemeAccessService;
use Throwable;

abstract class BaseController
{
    public function __construct(
        protected readonly View $view,
        protected readonly Config $config,
        protected readonly Database $database,
        protected readonly AuthService $auth,
    ) {
    }

    /** @param array<string, mixed> $data */
    protected function page(Request $request, string $view, string $title, array $data = [], bool $private = false, string $layout = 'layouts/app', int $status = 200): Response
    {
        $app = $this->appData();
        $user = $this->userData();
        $page = [
            'title' => $title,
            'description' => (string) ($data['description'] ?? $app['tagline']),
            'private' => $private,
            'canonical' => $private ? '' : rtrim((string) $app['url'], '/') . $request->uri,
            'body_class' => (string) ($data['body_class'] ?? ''),
        ];
        $data['flash'] ??= $this->pullFlash();
        $data['show_cookie_consent'] ??= !in_array(
            (string) ($request->cookies['purple_parlor_consent'] ?? ''),
            ['essential', 'analytics'],
            true,
        );
        if (!array_key_exists('advertisement', $data)) {
            try {
                $data['advertisement'] = (new AdvertisingService(
                    $this->database,
                    $this->config,
                    new EntitlementService($this->database),
                ))->placementFor($request, isset($user['id']) ? (int) $user['id'] : null);
            } catch (Throwable) {
                // Advertising is optional and must never break a public page.
                $data['advertisement'] = null;
            }
        }
        $body = $this->view->render($view, [
            'app' => $app,
            'page' => $page,
            'data' => $data,
            'user' => $user,
        ], $layout);
        return new Response($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @return array<string, mixed> */
    protected function appData(): array
    {
        $settings = $this->publicSystemSettings();
        $url = (string) ($settings['site.app_url'] ?? $this->config->get('app.url', 'http://127.0.0.1:8080'));
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
            $url = (string) $this->config->get('app.url', 'http://127.0.0.1:8080');
        }
        $themes = ['purple-parlor','midnight-lavender','cozy-fireplace','royal-plum','soft-daylight','high-contrast'];
        $defaultTheme = (string) ($settings['site.default_theme'] ?? 'purple-parlor');
        if (!in_array($defaultTheme, $themes, true)) {
            $defaultTheme = 'purple-parlor';
        }
        $stylesheet = (string) ($settings['theme.generated_stylesheet'] ?? '');
        if (preg_match('#^/assets/css/generated-theme-[a-f0-9]{64}\.css$#', $stylesheet) !== 1
            || !is_file(dirname(__DIR__, 2) . '/public' . $stylesheet)) {
            $stylesheet = '';
        }
        $themeAccess = new ThemeAccessService(new EntitlementService($this->database));
        return [
            'name' => (string) ($settings['site.name'] ?? $this->config->get('app.name', 'The Purple Parlor')),
            'creator_name' => (string) ($settings['site.creator_name'] ?? $this->config->get('app.creator_name', $this->config->get('app.brand', 'Lord Funion'))),
            'tagline' => (string) $this->config->get('app.tagline', 'A cozy, play-money social casino and casino-game arcade.'),
            'support_email' => (string) ($settings['site.support_email'] ?? $this->config->get('app.support_email', $this->config->get('app.mail.from_address', 'support@lordfunion.dev'))),
            'url' => rtrim($url, '/'),
            'minimum_age' => (int) ($settings['site.minimum_age'] ?? $this->config->get('app.minimum_age', 18)),
            'legal_policy_version' => (string) ($settings['site.age_policy_version'] ?? $this->config->get('app.legal_policy_version', 1)),
            'age_exit_url' => (string) $this->config->get('app.age_exit_url', 'https://www.google.com'),
            'indexing_enabled' => (bool) ($settings['site.public_indexing'] ?? $this->config->get('app.indexing_enabled', false)),
            'maintenance_mode' => (bool) ($settings['site.maintenance_mode'] ?? false),
            'default_theme' => $defaultTheme,
            'theme_stylesheet' => $stylesheet,
            'theme_catalog' => $themeAccess->catalog(null),
            'available_themes' => $themeAccess->availableSlugs(null),
            'cozy_club_daily_coins' => max(0, min(100000, (int) $this->config->get('membership.daily_coin_bonuses.cozy_club', 1000))),
            'cozy_club_plus_daily_coins' => max(0, min(100000, (int) $this->config->get('membership.daily_coin_bonuses.cozy_club_plus', 2500))),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function userData(): ?array
    {
        $user = $this->auth->currentUser();
        if (!$user instanceof User) {
            return null;
        }
        $profile = $this->database->fetchOne(
            'SELECT display_name, bio, avatar_key, is_public, stats_public FROM user_profiles WHERE user_id = :user',
            ['user' => $user->id],
        ) ?? [];
        $settingsRow = $this->database->fetchOne('SELECT settings_json FROM user_settings WHERE user_id = :user', ['user' => $user->id]);
        $settings = json_decode((string) ($settingsRow['settings_json'] ?? '{}'), true);
        $balances = ['cozy_coins' => 0, 'parlor_stars' => 0];
        foreach ($this->database->fetchAll('SELECT currency, balance FROM virtual_wallets WHERE user_id = :user', ['user' => $user->id]) as $wallet) {
            if (array_key_exists((string) $wallet['currency'], $balances)) {
                $balances[(string) $wallet['currency']] = (int) $wallet['balance'];
            }
        }
        $membership = $this->database->fetchOne(
            "SELECT mp.name, mp.plan_key FROM subscriptions s INNER JOIN membership_plans mp ON mp.id = s.plan_id WHERE s.user_id = :user AND s.status IN ('trialing','active','in_grace_period') ORDER BY s.updated_at DESC LIMIT 1",
            ['user' => $user->id],
        );
        $twoFactor = $this->database->fetchOne('SELECT enabled FROM two_factor_secrets WHERE user_id = :user', ['user' => $user->id]);
        $preferences = is_array($settings) ? $settings : [];
        $themeAccess = new ThemeAccessService(new EntitlementService($this->database));
        $entitlements = new EntitlementService($this->database);
        $availableThemes = $themeAccess->availableSlugs($user->id);
        if (!in_array((string) ($preferences['theme'] ?? 'purple-parlor'), $availableThemes, true)) {
            $preferences['theme'] = 'purple-parlor';
        }
        return [
            'id' => $user->id,
            'email' => $user->email,
            'username' => $user->username,
            'display_name' => (string) ($profile['display_name'] ?? $user->username),
            'bio' => (string) ($profile['bio'] ?? ''),
            'avatar_key' => (string) ($profile['avatar_key'] ?? 'onion'),
            'public_profile' => (bool) ($profile['is_public'] ?? false),
            'statistics_public' => (bool) ($profile['stats_public'] ?? false),
            'privacy' => [
                'public_profile' => (bool) ($profile['is_public'] ?? false),
                'leaderboards' => (bool) ($preferences['leaderboards'] ?? false),
                'show_favorites' => (bool) ($preferences['show_favorites'] ?? false),
                'show_achievements' => (bool) ($preferences['show_achievements'] ?? false),
            ],
            'roles' => $user->roles,
            'is_admin' => $user->isAdministrator(),
            'is_adult_owner' => in_array('adult_owner', $user->roles, true),
            'is_developer_admin' => in_array('developer_admin', $user->roles, true),
            'two_factor_enabled' => (bool) ($twoFactor['enabled'] ?? false),
            'membership_name' => (string) ($membership['name'] ?? 'Free member'),
            'membership_slug' => (string) ($membership['plan_key'] ?? 'free'),
            'cozy_coins' => $balances['cozy_coins'],
            'parlor_stars' => $balances['parlor_stars'],
            'preferences' => $preferences,
            'theme_catalog' => $themeAccess->catalog($user->id),
            'available_themes' => $availableThemes,
            'cosmetics' => $themeAccess->cosmetics($user->id),
            'entitlements' => [
                'statistics.advanced' => $entitlements->hasEntitlement($user->id, 'statistics.advanced'),
                'statistics.export' => $entitlements->hasEntitlement($user->id, 'statistics.export'),
                'layout.expanded' => $entitlements->hasEntitlement($user->id, 'layout.expanded'),
                'ads.disabled' => $entitlements->hasEntitlement($user->id, 'ads.disabled'),
            ],
        ];
    }

    protected function userId(): ?int
    {
        return $this->auth->currentUser()?->id;
    }

    protected function flash(string $message, string $type = 'info'): void
    {
        $_SESSION['_flash'] = ['message' => $message, 'type' => $type];
    }

    /** @return array<string, string>|null */
    private function pullFlash(): ?array
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return is_array($flash) ? $flash : null;
    }

    protected function rememberInput(array $input): void
    {
        $safe = $input;
        foreach (array_keys($safe) as $key) {
            if (preg_match('/password|secret|token|code/i', (string) $key)) {
                unset($safe[$key]);
            }
        }
        $_SESSION['_old'] = $safe;
    }

    protected function clearOldInput(): void
    {
        unset($_SESSION['_old']);
    }

    protected function redirectRoute(string $name, array $parameters = []): Response
    {
        return Response::redirect(route($name, $parameters));
    }

    /** @return array<string,mixed> */
    private function publicSystemSettings(): array
    {
        try {
            $rows = $this->database->fetchAll(
                "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (
                    'site.name','site.creator_name','site.app_url','site.support_email','site.minimum_age',
                    'site.age_policy_version','site.default_theme','site.public_indexing','site.maintenance_mode',
                    'theme.generated_stylesheet'
                ) AND is_sensitive = 0",
            );
        } catch (\Throwable) {
            return [];
        }
        $settings = [];
        foreach ($rows as $row) {
            $value = json_decode((string) $row['setting_value'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $settings[(string) $row['setting_key']] = $value;
            }
        }
        return $settings;
    }
}
