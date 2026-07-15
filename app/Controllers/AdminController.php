<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticationException;
use App\Auth\AuthorizationException;
use App\Auth\AuthorizationService;
use App\Auth\AuthService;
use App\Core\Config;
use App\Core\Request;
use App\Core\RequestContext;
use App\Core\Response;
use App\Database\Database;
use App\Http\EnvWriter;
use App\Http\View;
use App\Models\User;
use App\Payments\HttpClient;
use App\Payments\PaymentAuditHistoryService;
use App\Payments\PaymentGate;
use App\Payments\RefundExecutionService;
use App\Security\IpHasher;
use App\Security\SecretRedactor;
use App\Services\AdultOwnerSettingsService;
use App\Services\AdminMutationService;
use App\Services\AuditService;
use App\Services\ManagedContentService;
use App\Services\SystemHealthService;
use App\Services\VirtualLedgerService;
use DomainException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Private operator surface. Every mutation in this controller is permission
 * checked by the route and again constrained to an audited service here.
 */
final class AdminController extends BaseController
{
    /** @var list<string> */
    private const THEME_SLUGS = [
        'purple-parlor', 'midnight-lavender', 'cozy-fireplace',
        'royal-plum', 'soft-daylight', 'high-contrast',
    ];

    /** @var list<string> */
    private const THEME_TOKENS = [
        'page', 'page_deep', 'panel', 'panel_raised', 'purple',
        'lavender', 'gold', 'cream', 'ink',
    ];

    public function __construct(
        View $view,
        Config $config,
        Database $database,
        AuthService $auth,
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
        private readonly VirtualLedgerService $ledger,
        private readonly PaymentGate $paymentGate,
        private readonly HttpClient $http,
        private readonly SystemHealthService $health,
        private readonly AdultOwnerSettingsService $ownerSettings,
        private readonly SecretRedactor $redactor,
        private readonly IpHasher $ipHasher,
        private readonly AdminMutationService $mutations,
        private readonly RefundExecutionService $refunds,
        private readonly PaymentAuditHistoryService $paymentAuditHistory,
        private readonly ManagedContentService $managedContent,
    ) {
        parent::__construct($view, $config, $database, $auth);
    }

    public function dashboard(Request $request): Response
    {
        $canSeePayments = $this->can('payments.summary.view');
        $monthStart = gmdate('Y-m-01 00:00:00');
        $today = gmdate('Y-m-d 00:00:00');
        $users = $this->scalar('SELECT COUNT(*) AS aggregate FROM users WHERE deleted_at IS NULL');
        $activeSubscriptions = $canSeePayments
            ? $this->scalar("SELECT COUNT(*) AS aggregate FROM subscriptions WHERE status IN ('trialing','active','in_grace_period')")
            : null;
        $mrr = $canSeePayments ? $this->scalar(
            "SELECT COALESCE(SUM(CASE WHEN billing_period = 'annual' THEN amount_cents / 12.0 ELSE amount_cents END), 0) AS aggregate
             FROM subscriptions WHERE status IN ('trialing','active','in_grace_period')",
        ) : null;
        $oneTime = $canSeePayments ? $this->scalar(
            'SELECT COALESCE(SUM(amount_cents), 0) AS aggregate FROM payments WHERE product_id IS NOT NULL AND paid_at >= :start',
            ['start' => $monthStart],
        ) : null;
        $refunds = $canSeePayments ? $this->scalar(
            "SELECT COALESCE(SUM(amount_cents), 0) AS aggregate FROM refunds WHERE created_at >= :start AND status NOT IN ('failed','canceled')",
            ['start' => $monthStart],
        ) : null;
        $disputes = $canSeePayments ? $this->scalar(
            "SELECT COUNT(*) AS aggregate FROM disputes WHERE status NOT IN ('won','lost','closed')",
        ) : null;
        $failedPayments = $canSeePayments ? $this->scalar(
            "SELECT COUNT(*) AS aggregate FROM payment_attempts WHERE status = 'failed' AND created_at >= :start",
            ['start' => $monthStart],
        ) : null;
        $rounds = $this->scalar(
            "SELECT COUNT(*) AS aggregate FROM game_rounds WHERE status = 'settled' AND settled_at >= :today",
            ['today' => $today],
        );
        $issued = $this->scalar(
            "SELECT COALESCE(SUM(amount), 0) AS aggregate FROM virtual_ledger_entries WHERE currency = 'cozy_coins' AND amount > 0",
        );
        $removed = abs($this->scalar(
            "SELECT COALESCE(SUM(amount), 0) AS aggregate FROM virtual_ledger_entries WHERE currency = 'cozy_coins' AND amount < 0",
        ));

        $restricted = 'Restricted';
        $metrics = [
            ['label' => 'Users', 'value' => number_format($users), 'trend' => 'Non-deleted accounts'],
            ['label' => 'Active subscriptions', 'value' => $activeSubscriptions === null ? $restricted : number_format($activeSubscriptions), 'trend' => $canSeePayments ? 'Current access states' : 'Payment role required'],
            ['label' => 'Monthly recurring revenue', 'value' => $mrr === null ? $restricted : $this->money($mrr), 'trend' => $canSeePayments ? 'Normalized active MRR' : 'Payment role required'],
            ['label' => 'One-time revenue', 'value' => $oneTime === null ? $restricted : $this->money($oneTime), 'trend' => $canSeePayments ? 'Paid this month' : 'Payment role required'],
            ['label' => 'Refunds', 'value' => $refunds === null ? $restricted : $this->money($refunds), 'trend' => $canSeePayments ? 'Recorded this month' : 'Payment role required'],
            ['label' => 'Open disputes', 'value' => $disputes === null ? $restricted : number_format($disputes), 'trend' => $canSeePayments ? 'Provider status' : 'Payment role required'],
            ['label' => 'Failed payments', 'value' => $failedPayments === null ? $restricted : number_format($failedPayments), 'trend' => $canSeePayments ? 'This month' : 'Payment role required'],
            ['label' => 'Completed game rounds', 'value' => number_format($rounds), 'trend' => 'Today (UTC)'],
            ['label' => 'Cozy Coins issued', 'value' => number_format($issued), 'trend' => 'Append-only ledger total'],
            ['label' => 'Cozy Coins removed', 'value' => number_format($removed), 'trend' => 'Append-only ledger total'],
        ];

        $popular = [];
        foreach ($this->database->fetchAll(
            "SELECT gd.name, COUNT(gr.id) AS rounds,
                    SUM(CASE WHEN gr.status IN ('failed','error','abandoned') THEN 1 ELSE 0 END) AS errors
             FROM game_rounds gr INNER JOIN game_definitions gd ON gd.id = gr.game_id
             WHERE gr.started_at >= :start GROUP BY gd.id, gd.name ORDER BY rounds DESC LIMIT 8",
            ['start' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)],
        ) as $row) {
            $count = (int) $row['rounds'];
            $popular[] = [
                'name' => (string) $row['name'],
                'rounds' => $count,
                'error_rate' => $count > 0 ? number_format(((int) $row['errors'] / $count) * 100, 1) . '%' : '0.0%',
            ];
        }

        $securityEvents = [];
        if ($this->can('system.health.view')) {
            foreach ($this->database->fetchAll(
                "SELECT event_type, severity, request_id, created_at FROM security_events
                 WHERE severity IN ('warning','critical') ORDER BY id DESC LIMIT 6",
            ) as $event) {
                $securityEvents[] = [
                    'type' => str_replace('_', ' ', ucfirst((string) $event['event_type'])),
                    'severity' => ucfirst((string) $event['severity']),
                    'time' => (string) $event['created_at'],
                    'request_id' => (string) ($event['request_id'] ?: 'not recorded'),
                ];
            }
        }

        $healthChecks = $this->health->check();
        return $this->page($request, 'admin/dashboard', 'Administration', [
            'metrics' => $metrics,
            'health' => $this->dashboardHealth($healthChecks),
            'health_checked_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'payment_mode' => (string) $this->config->get('payments.mode', 'sandbox'),
            'live_payment_lock' => $this->activationLocked(),
            'popular_games' => $popular,
            'security_events' => $securityEvents,
        ], true, 'layouts/admin');
    }

    public function section(Request $request): Response
    {
        $section = strtolower((string) $request->attribute('section', ''));
        if ($section === '') {
            $section = strtolower(trim((string) preg_replace('#^/admin/?#', '', $request->uri), '/'));
        }
        return match ($section) {
            '', 'overview' => $this->dashboard($request),
            'commerce' => $this->commercePage($request),
            'health' => $this->healthPage($request),
            'audit' => $this->auditPage($request),
            'settings' => $this->settingsPage($request),
            'themes' => $this->themesPage($request),
            'users', 'games', 'rewards', 'content', 'security', 'logs', 'backups' => $this->resourcePage($request, $section),
            default => $this->page($request, 'errors/404', 'Admin section not found', [
                'request_id' => RequestContext::id(),
            ], true, 'layouts/admin', 404),
        };
    }

    public function settings(Request $request): Response
    {
        $actor = $this->auth->currentUser();
        if ($actor === null) {
            return Response::redirect('/login');
        }

        try {
            $current = $this->settingsData();
            $reason = $this->requiredReason((string) $request->input('reason'));
            $siteName = $this->plainText((string) $request->input('site_name'), 'Site name', 2, 80);
            $creator = $this->plainText((string) $request->input('creator_name'), 'Creator display name', 2, 80);
            $appUrl = $this->validatedAppUrl((string) $request->input('app_url'));
            $supportEmail = strtolower(trim((string) $request->input('support_email')));
            if (filter_var($supportEmail, FILTER_VALIDATE_EMAIL) === false || strlen($supportEmail) > 254) {
                throw new DomainException('Enter a valid support email address.');
            }
            $minimumAge = (int) $request->input('minimum_age', $current['minimum_age']);
            if ($minimumAge < 18 || $minimumAge > 25) {
                throw new DomainException('The minimum age must remain between 18 and 25.');
            }
            $theme = strtolower(str_replace(' ', '-', trim((string) $request->input('default_theme', $current['default_theme']))));
            if (!in_array($theme, self::THEME_SLUGS, true)) {
                throw new DomainException('Select an installed theme.');
            }
            $indexing = $request->input('public_indexing') !== null;
            $maintenance = $request->input('maintenance_mode') !== null;
            $ageChanged = $minimumAge !== (int) $current['minimum_age'];
            $indexingEnabled = $indexing && !(bool) $current['public_indexing'];

            if ($ageChanged || $indexingEnabled) {
                $this->requireAdultOwner();
                $this->auth->requirePrivilegedSession();
            }
            if ($minimumAge < (int) $current['minimum_age'] && (string) $request->input('confirm_age_lowering') !== '1') {
                throw new DomainException('Lowering the minimum age requires the explicit confirmation checkbox.');
            }
            if (($indexing || $this->config->get('app.env') === 'production') && !str_starts_with($appUrl, 'https://')) {
                throw new DomainException('Production and indexed sites require an HTTPS application URL.');
            }

            $predictedPolicyVersion = (string) $current['policy_version'];
            if ($ageChanged) {
                $digits = (int) preg_replace('/\D/', '', $predictedPolicyVersion);
                $predictedPolicyVersion = (string) ($digits + 1);
            }
            $environment = [
                'APP_NAME' => $siteName,
                'APP_BRAND' => $creator,
                'APP_CREATOR_NAME' => $creator,
                'APP_URL' => $appUrl,
                'APP_SUPPORT_EMAIL' => $supportEmail,
                'APP_INDEXING_ENABLED' => $indexing,
            ];
            if ($ageChanged) {
                $environment['APP_MINIMUM_AGE'] = $minimumAge;
                $environment['APP_LEGAL_POLICY_VERSION'] = $predictedPolicyVersion;
            }
            (new EnvWriter($this->root() . '/.env'))->update($environment);

            if ($ageChanged) {
                $agePolicy = $this->ownerSettings->updateMinimumAge(
                    $minimumAge,
                    $minimumAge >= (int) $current['minimum_age'] || (string) $request->input('confirm_age_lowering') === '1',
                    $reason,
                    $this->ipHasher->hash($request->clientIp()),
                );
                $predictedPolicyVersion = (string) $agePolicy['policy_version'];
            }

            $new = [
                'site_name' => $siteName,
                'creator_name' => $creator,
                'app_url' => $appUrl,
                'support_email' => $supportEmail,
                'minimum_age' => $minimumAge,
                'policy_version' => $predictedPolicyVersion,
                'default_theme' => $theme,
                'public_indexing' => $indexing,
                'maintenance_mode' => $maintenance,
            ];
            $this->database->transaction(function () use ($actor, $current, $new, $reason, $request): void {
                foreach ([
                    'site.name' => $new['site_name'],
                    'site.creator_name' => $new['creator_name'],
                    'site.app_url' => $new['app_url'],
                    'site.support_email' => $new['support_email'],
                    'site.default_theme' => $new['default_theme'],
                    'site.public_indexing' => $new['public_indexing'],
                    'site.maintenance_mode' => $new['maintenance_mode'],
                ] as $key => $value) {
                    $this->writeSetting($key, $value, $actor->id);
                }
                $this->audit->record(
                    $actor->id,
                    'system.settings_updated',
                    'system_settings',
                    'site',
                    $current,
                    $new,
                    $reason,
                    $this->ipHasher->hash($request->clientIp()),
                );
            });

            $this->config->set('app.name', $siteName);
            $this->config->set('app.brand', $creator);
            $this->config->set('app.creator_name', $creator);
            $this->config->set('app.url', $appUrl);
            $this->config->set('app.support_email', $supportEmail);
            $this->config->set('app.minimum_age', $minimumAge);
            $this->config->set('app.legal_policy_version', $predictedPolicyVersion);
            $this->config->set('app.indexing_enabled', $indexing);
            $this->publishRobots($indexing);
            $this->flash('Audited site settings were saved.', 'success');
        } catch (AuthenticationException) {
            return Response::redirect('/confirm-password?return=' . rawurlencode('/admin/settings'));
        } catch (DomainException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash('The settings update could not be completed. No secret values were displayed.', 'error');
        }
        return Response::redirect('/admin/settings');
    }

    /** A provider-readiness test that performs no checkout, order, capture, refund, or charge. */
    public function testPayment(Request $request): Response
    {
        $provider = strtolower((string) $request->attribute('provider'));
        $actor = $this->auth->currentUser();
        if ($actor === null || !in_array('adult_owner', $actor->roles, true)) {
            return $this->forbidden($request);
        }
        $this->auth->requirePrivilegedSession();
        if (!in_array($provider, ['paypal', 'square'], true)) {
            throw new DomainException('Unsupported payment provider.');
        }

        $environment = (string) $this->config->get("payments.{$provider}.environment", 'sandbox');
        $success = false;
        try {
            if (!$this->paymentGate->providerConfigured($provider, $environment)) {
                throw new RuntimeException('Provider configuration is incomplete.');
            }
            if ($provider === 'paypal') {
                $base = $environment === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
                $credentials = (string) $this->config->get('payments.paypal.client_id') . ':' . (string) $this->config->get('payments.paypal.client_secret');
                $result = $this->http->request('POST', $base . '/v1/oauth2/token', [
                    'Accept: application/json',
                    'Accept-Language: en_US',
                    'Authorization: Basic ' . base64_encode($credentials),
                    'Content-Type: application/x-www-form-urlencoded',
                ], 'grant_type=client_credentials', 15);
                $success = $result['status'] >= 200 && $result['status'] < 300
                    && is_string($result['json']['access_token'] ?? null)
                    && (string) $result['json']['access_token'] !== '';
            } else {
                $base = $environment === 'live' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
                $result = $this->http->request('GET', $base . '/v2/locations', [
                    'Accept: application/json',
                    'Authorization: Bearer ' . (string) $this->config->get('payments.square.access_token'),
                    'Square-Version: ' . (string) $this->config->get('payments.square.api_version'),
                ], null, 15);
                $success = $result['status'] >= 200 && $result['status'] < 300
                    && is_array($result['json']['locations'] ?? null);
            }
            if (!$success) {
                throw new RuntimeException('The provider rejected the read-only readiness request.');
            }
        } catch (Throwable) {
            $success = false;
        }

        $snapshot = ['successful' => $success, 'environment' => $environment, 'tested_at' => gmdate('Y-m-d H:i:s')];
        $this->database->transaction(function () use ($actor, $provider, $snapshot, $request): void {
            $this->writeSetting('payments.connection_test.' . $provider, $snapshot, $actor->id);
            $this->audit->record(
                $actor->id,
                $snapshot['successful'] ? 'payments.connection_test_succeeded' : 'payments.connection_test_failed',
                'payment_provider',
                $provider,
                null,
                $snapshot,
                'Read-only provider readiness test; no payment operation was requested.',
                $this->ipHasher->hash($request->clientIp()),
            );
        });
        $this->flash(
            $success
                ? ucfirst($provider) . ' accepted the read-only connection test. No charge was created.'
                : ucfirst($provider) . ' did not pass the read-only connection test. Review the private configuration and try again.',
            $success ? 'success' : 'error',
        );
        return Response::redirect('/admin/commerce');
    }

    public function paymentLock(Request $request): Response
    {
        $actor = $this->auth->currentUser();
        if ($actor === null || !in_array('adult_owner', $actor->roles, true)) {
            return $this->forbidden($request);
        }
        $this->auth->requirePrivilegedSession();
        try {
            $reason = $this->requiredReason((string) $request->input('reason'), 10);
            $unlock = strtolower((string) $request->input('action', 'lock')) === 'unlock';
            if ($unlock) {
                if ((string) $request->input('confirmation') !== 'UNLOCK LIVE PAYMENTS') {
                    throw new DomainException('Type the exact live-payment confirmation phrase before unlocking.');
                }
                $provider = strtolower((string) $this->config->get('payments.provider', 'demo'));
                $safe = $this->config->get('payments.enabled') === true
                    && $this->config->get('payments.mode') === 'live'
                    && $this->config->get('payments.adult_owner_confirmed') === true
                    && in_array($provider, ['paypal', 'square'], true)
                    && $this->paymentGate->providerConfigured($provider, 'live')
                    && str_starts_with((string) $this->config->get('app.url', ''), 'https://');
                if (!$safe) {
                    throw new DomainException('The production safeguards, HTTPS URL, and selected live provider must all be ready before unlocking.');
                }
                (new EnvWriter($this->root() . '/.env'))->update(['LIVE_PAYMENT_ACTIVATION_LOCK' => false]);
                $this->config->set('payments.live_activation_lock', false);
                // The database lock remains fail-closed if this step cannot commit.
                $this->ownerSettings->confirmLiveActivationLock(false, $reason, $this->ipHasher->hash($request->clientIp()));
                $this->flash('The Adult Owner released the production activation lock. The permanent audit entry was recorded.', 'success');
            } else {
                // Lock the environment first so any later failure remains fail-closed.
                (new EnvWriter($this->root() . '/.env'))->update(['LIVE_PAYMENT_ACTIVATION_LOCK' => true]);
                $this->config->set('payments.live_activation_lock', true);
                $this->ownerSettings->confirmLiveActivationLock(true, $reason, $this->ipHasher->hash($request->clientIp()));
                $this->flash('Production payment activation is locked.', 'success');
            }
        } catch (DomainException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash('The activation lock was not changed. It remains fail-closed.', 'error');
        }
        return Response::redirect('/admin/commerce/activation');
    }

    public function paymentLockRedirect(Request $request): Response
    {
        // This URL is intentionally mutation-only for POST. A bookmark,
        // refresh, or stale browser navigation must return to the protected
        // review form instead of exposing a raw 405 response. No state changes
        // are permitted through this GET fallback.
        return Response::redirect('/admin/commerce/activation');
    }

    public function adjustLedger(Request $request): Response
    {
        $actor = $this->auth->currentUser();
        if ($actor === null) {
            return Response::redirect('/login');
        }
        try {
            $userId = (int) $request->input('user_id');
            $amount = filter_var($request->input('amount'), FILTER_VALIDATE_INT);
            $currency = strtolower((string) $request->input('currency', VirtualLedgerService::COZY_COINS));
            $reason = $this->requiredReason((string) $request->input('reason'), 8);
            if ($userId <= 0 || $this->database->fetchOne('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $userId]) === null) {
                throw new DomainException('Select an active account for the adjustment.');
            }
            if (!is_int($amount) || $amount === 0 || abs($amount) > 1_000_000) {
                throw new DomainException('An adjustment must be a non-zero integer no larger than 1,000,000 units per action.');
            }
            if (!in_array($currency, [VirtualLedgerService::COZY_COINS, VirtualLedgerService::PARLOR_STARS], true)) {
                throw new DomainException('Unsupported virtual currency.');
            }
            $suppliedKey = trim((string) ($request->header('idempotency-key') ?? $request->input('idempotency_key', '')));
            $key = preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{7,190}$/', $suppliedKey) === 1
                ? $suppliedKey
                : 'admin-adjustment:' . $actor->id . ':' . hash('sha256', implode('|', [
                    $userId, $currency, $amount, $reason, (int) floor(time() / 300),
                ]));
            $entry = $this->database->transaction(function () use ($actor, $userId, $currency, $amount, $key, $reason, $request) {
                $entry = $this->ledger->apply($userId, $currency, $amount, 'admin.adjustment', $key, [
                    'administrator_id' => $actor->id,
                    'metadata' => ['reason' => $this->redactor->redactText($reason), 'request_id' => RequestContext::id()],
                ]);
                $this->audit->record(
                    $actor->id,
                    'virtual_currency.adjusted',
                    'user_wallet',
                    (string) $userId . ':' . $currency,
                    ['balance' => $entry->balanceBefore],
                    ['balance' => $entry->balanceAfter, 'amount' => $amount, 'ledger_entry_id' => $entry->id],
                    $reason,
                    $this->ipHasher->hash($request->clientIp()),
                );
                return $entry;
            });
            $this->flash('The virtual-currency adjustment was committed to the append-only ledger.', 'success');
        } catch (DomainException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash('The ledger adjustment could not be completed.', 'error');
        }
        return Response::redirect('/admin/users');
    }

    public function updateUserStatus(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/users', 'users.manage', false,
            function (User $actor) use ($request): string {
                $userId = (int) $request->attribute('id');
                $ownerAuthorized = $this->isAdultOwner();
                if ($this->targetIsAdministrator($userId)) {
                    $this->requireAdultOwner();
                    $this->auth->requirePrivilegedSession();
                    $ownerAuthorized = true;
                }
                $this->mutations->updateUserStatus(
                    $actor->id, $userId, (string) $request->input('status'), (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()), $ownerAuthorized,
                );
                return 'The account status was changed and permanently audited.';
            });
    }

    public function updateUserRole(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/users', 'users.roles.manage', true,
            function (User $actor) use ($request): string {
                $this->mutations->updateUserRole(
                    $actor->id, (int) $request->attribute('id'), (string) $request->input('role'),
                    (string) $request->input('action'), (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()), true,
                );
                return 'The role assignment was changed and permanently audited.';
            });
    }

    public function updateRolePermission(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/users', 'users.roles.manage', true,
            function (User $actor) use ($request): string {
                $this->mutations->updateRolePermission(
                    $actor->id, (string) $request->attribute('role'), (string) $request->input('permission'),
                    (string) $request->input('action'), (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()), true,
                );
                return 'The role permission was changed and permanently audited.';
            });
    }

    public function updateGame(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/games', 'games.manage', false,
            function (User $actor) use ($request): string {
                $this->mutations->updateGame(
                    $actor->id, (int) $request->attribute('id'), (string) $request->input('availability'),
                    $request->input('minimum'), $request->input('maximum'), $request->input('increment'),
                    (string) $request->input('reason'), $this->ipHasher->hash($request->clientIp()),
                );
                return 'Game availability and fictional wager limits are now active at runtime.';
            });
    }

    public function updateAchievement(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/rewards', 'achievements.manage', false,
            function (User $actor) use ($request): string {
                $this->mutations->updateAchievement($actor->id, (int) $request->attribute('id'), $request->body,
                    (string) $request->input('reason'), $this->ipHasher->hash($request->clientIp()));
                return 'The achievement definition was updated. Existing ledger awards remain immutable.';
            });
    }

    public function updateMission(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/rewards', 'missions.manage', false,
            function (User $actor) use ($request): string {
                $this->mutations->updateMission($actor->id, (int) $request->attribute('id'), $request->body,
                    (string) $request->input('reason'), $this->ipHasher->hash($request->clientIp()));
                return 'The mission definition was updated for the public mission flow.';
            });
    }

    public function updateDailyReward(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/rewards', 'missions.manage', false,
            function (User $actor) use ($request): string {
                $this->mutations->updateDailyReward($actor->id, $request->input('amount'),
                    (string) $request->input('reason'), $this->ipHasher->hash($request->clientIp()));
                return 'The daily fictional reward was updated for future claims.';
            });
    }

    public function updateCatalog(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/commerce', 'payments.live.configure', true,
            function (User $actor) use ($request): string {
                $this->mutations->updateCatalogItem(
                    $actor->id, (string) $request->attribute('type'), (int) $request->attribute('id'), $request->body,
                    (string) $request->input('reason'), $this->ipHasher->hash($request->clientIp()), true,
                );
                return 'The local commerce catalog was updated. No provider operation was performed.';
            });
    }

    public function updateEntitlement(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/commerce', 'payments.live.configure', true,
            function (User $actor) use ($request): string {
                $this->mutations->updateEntitlement(
                    $actor->id, (int) $request->input('user_id'), (string) $request->input('entitlement_key'),
                    (string) $request->input('action'), (string) $request->input('ends_at'),
                    (string) $request->input('reason'), $this->ipHasher->hash($request->clientIp()), true,
                );
                return 'The administrator-only entitlement source was updated. Paid sources were not changed.';
            });
    }

    public function trackRefund(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/commerce', 'payments.refund', true,
            function (User $actor) use ($request): string {
                $this->mutations->trackRefundRequest(
                    $actor->id, (int) $request->attribute('id'), (int) $request->input('payment_id'),
                    $request->input('amount_cents'), (string) $request->input('status'),
                    (string) $request->input('reason'), (string) $request->input('note'),
                    $this->ipHasher->hash($request->clientIp()), true,
                );
                return 'The internal refund request was tracked. No provider refund was submitted.';
            });
    }

    public function executeRefund(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/commerce', 'payments.refund', true,
            function (User $actor) use ($request): string {
                $result = $this->refunds->execute(
                    $actor->id,
                    (int) $request->attribute('id'),
                    (string) $request->input('confirmation'),
                    (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()),
                );
                if ($result['duplicate']) {
                    return 'This provider refund was already confirmed. No duplicate refund was created.';
                }
                if ($result['status'] === 'pending_confirmation') {
                    return 'The provider accepted the request, but confirmation is still pending. Retry only through this reviewed request.';
                }
                return $result['full_refund']
                    ? 'The provider confirmed the full refund. Payment and paid entitlement state were updated.'
                    : 'The provider confirmed a partial refund. The paid entitlement remains active.';
            });
    }

    public function updateMonetization(Request $request): Response
    {
        return $this->handleAdminMutation($request, '/admin/content', 'ads.manage', true,
            function (User $actor) use ($request): string {
                $this->mutations->updateMonetization(
                    $actor->id, (string) $request->attribute('type'), (int) $request->attribute('id'),
                    (string) $request->input('status'), (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()), true,
                );
                return 'The advertising or sponsor status was updated and audited.';
            });
    }

    public function updateRequestStatus(Request $request): Response
    {
        $type = (string) $request->attribute('type');
        $permission = $type === 'support_ticket' ? 'support.manage' : 'content.manage';
        return $this->handleAdminMutation($request, '/admin/content', $permission, false,
            function (User $actor) use ($request, $type): string {
                $this->mutations->updateRequestStatus(
                    $actor->id, $type, (int) $request->attribute('id'), (string) $request->input('status'),
                    (string) $request->input('reason'), $this->ipHasher->hash($request->clientIp()),
                );
                return 'The request workflow status was updated and audited.';
            });
    }

    public function updateWorkflow(Request $request): Response
    {
        $type = (string) $request->attribute('type');
        $permission = $type === 'legal' ? 'legal.manage' : 'content.manage';
        $owner = $type === 'legal';
        return $this->handleAdminMutation($request, '/admin/content', $permission, $owner,
            function (User $actor) use ($request, $type, $owner): string {
                $this->mutations->updateWorkflow(
                    $actor->id, $type, (string) $request->attribute('key'), (string) $request->input('status'),
                    (string) $request->input('version'), (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()), $owner,
                );
                return 'The review/publication registry was updated. Source files were not edited at runtime.';
            });
    }

    public function saveManagedContentDraft(Request $request): Response
    {
        $type = strtolower((string) $request->attribute('type'));
        return $this->handleAdminMutation($request, '/admin/content', $this->managedContentPermission($type), false,
            function (User $actor) use ($request, $type): string {
                $this->managedContent->createDraft(
                    $actor->id,
                    $type,
                    (string) $request->attribute('key'),
                    (string) $request->input('version'),
                    (string) $request->input('title'),
                    (string) $request->input('body'),
                    (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()),
                );
                return 'A new immutable draft revision was created and audited. Public pages and queued email still use the published revision.';
            });
    }

    public function approveManagedLegal(Request $request): Response
    {
        $type = strtolower((string) $request->attribute('type'));
        return $this->handleAdminMutation($request, '/admin/content', 'legal.manage', true,
            function (User $actor) use ($request, $type): string {
                if ($type !== 'legal') {
                    throw new DomainException('Only legal revisions use the Adult Owner approval step.');
                }
                $this->managedContent->approveLegal(
                    $actor->id,
                    (string) $request->attribute('key'),
                    (int) $request->attribute('id'),
                    (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()),
                    true,
                );
                return 'The legal draft was approved by the reauthenticated Adult Owner. It is not public until separately published.';
            });
    }

    public function publishManagedContent(Request $request): Response
    {
        $type = strtolower((string) $request->attribute('type'));
        $ownerRequired = $type === 'legal';
        return $this->handleAdminMutation($request, '/admin/content', $this->managedContentPermission($type), $ownerRequired,
            function (User $actor) use ($request, $type, $ownerRequired): string {
                $this->managedContent->publish(
                    $actor->id,
                    $type,
                    (string) $request->attribute('key'),
                    (int) $request->attribute('id'),
                    (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()),
                    $ownerRequired,
                );
                return 'The selected plain-text revision is now published. The prior published revision was retained as history.';
            });
    }

    public function rollbackManagedContent(Request $request): Response
    {
        $type = strtolower((string) $request->attribute('type'));
        $ownerRequired = $type === 'legal';
        return $this->handleAdminMutation($request, '/admin/content', $this->managedContentPermission($type), $ownerRequired,
            function (User $actor) use ($request, $type, $ownerRequired): string {
                $this->managedContent->rollback(
                    $actor->id,
                    $type,
                    (string) $request->attribute('key'),
                    (int) $request->attribute('id'),
                    (string) $request->input('reason'),
                    $this->ipHasher->hash($request->clientIp()),
                    $ownerRequired,
                );
                return 'A new published rollback revision was created from the selected historical version and audited.';
            });
    }

    public function previewManagedContent(Request $request): Response
    {
        $actor = $this->auth->currentUser();
        if ($actor === null) {
            return Response::redirect('/login');
        }
        $type = strtolower((string) $request->attribute('type'));
        try {
            $this->authorization->authorize($actor->id, $this->managedContentPermission($type));
            $revision = $this->managedContent->revision(
                $type,
                (string) $request->attribute('key'),
                (int) $request->attribute('id'),
            );
        } catch (AuthorizationException) {
            return $this->forbidden($request);
        } catch (DomainException) {
            return $this->page($request, 'errors/404', 'Content revision not found', [], true, 'layouts/admin', 404);
        }
        return $this->page($request, 'admin/content-preview', 'Managed content preview', [
            'revision' => $revision,
            'placeholders' => ManagedContentService::placeholdersFor($type, (string) $revision['content_key']),
        ], true, 'layouts/admin');
    }

    public function auditExport(Request $request): Response
    {
        $rows = $this->authorizedAuditRows($request, 2_000);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Audit export could not be prepared.');
        }
        fputcsv($stream, ['Time (UTC)', 'Administrator', 'Action', 'Target', 'Request ID', 'Reason']);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (mixed $value): string => $this->csvCell($value), [
                $row['created_at'], $row['actor'], $row['action'], $row['target'], $row['request_id'], $row['reason'],
            ]));
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new RuntimeException('Audit export could not be read.');
        }
        return new Response($contents, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="purple-parlor-audit-' . gmdate('Ymd-His') . '.csv"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function clearCache(Request $request): Response
    {
        $cache = realpath($this->root() . '/storage/cache');
        if ($cache === false || !is_dir($cache)) {
            $this->flash('The cache directory is unavailable.', 'error');
            return Response::redirect('/admin/health');
        }
        $removed = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink() || str_starts_with($item->getFilename(), '.')) {
                continue;
            }
            $resolved = $item->getRealPath();
            if ($resolved !== false && str_starts_with($resolved, $cache . DIRECTORY_SEPARATOR) && @unlink($resolved)) {
                $removed++;
            }
        }
        $actor = $this->auth->currentUser();
        $this->audit->record(
            $actor?->id,
            'system.cache_cleared',
            'cache',
            'application',
            null,
            ['files_removed' => $removed],
            'Authorized application cache clear.',
            $this->ipHasher->hash($request->clientIp()),
        );
        $this->flash("Application cache cleared ({$removed} generated files removed).", 'success');
        return Response::redirect('/admin/health');
    }

    public function commerceActivation(Request $request): Response
    {
        if (!$this->isAdultOwner()) {
            return $this->forbidden($request);
        }
        $this->auth->requirePrivilegedSession();
        $data = $this->commerceData();
        $data['environment_guards'] = [
            'Payments enabled' => $this->config->get('payments.enabled') === true,
            'Payment mode is live' => $this->config->get('payments.mode') === 'live',
            'Adult Owner confirmed' => $this->config->get('payments.adult_owner_confirmed') === true,
            'Public URL uses HTTPS' => str_starts_with((string) $this->config->get('app.url', ''), 'https://'),
            'Selected provider has complete live configuration' => $this->selectedLiveProviderReady(),
        ];
        $data['can_unlock'] = !in_array(false, $data['environment_guards'], true);
        return $this->page($request, 'admin/commerce-activation', 'Live payment activation', $data, true, 'layouts/admin');
    }

    public function runHealth(Request $request): Response
    {
        $checks = $this->health->check();
        $actor = $this->auth->currentUser();
        $snapshot = [
            'checked_at' => gmdate('Y-m-d H:i:s'),
            'passed' => count(array_filter($checks, static fn (array $check): bool => $check['ok'])),
            'total' => count($checks),
            'checks' => $checks,
        ];
        $this->database->transaction(function () use ($actor, $snapshot, $request): void {
            $this->writeSetting('system.health.last_snapshot', $snapshot, $actor?->id);
            $this->audit->record(
                $actor?->id,
                'system.health_checks_run',
                'system_health',
                'application',
                null,
                ['checked_at' => $snapshot['checked_at'], 'passed' => $snapshot['passed'], 'total' => $snapshot['total']],
                'Authorized health check.',
                $this->ipHasher->hash($request->clientIp()),
            );
        });
        $_SESSION['admin_health_snapshot'] = $snapshot;
        $this->flash("System checks complete: {$snapshot['passed']} of {$snapshot['total']} passed.", $snapshot['passed'] === $snapshot['total'] ? 'success' : 'warning');
        return Response::redirect('/admin/health');
    }

    public function previewTheme(Request $request): Response
    {
        $slug = strtolower((string) $request->attribute('slug'));
        if (!in_array($slug, self::THEME_SLUGS, true)) {
            return $this->page($request, 'errors/404', 'Theme not found', ['request_id' => RequestContext::id()], true, 'layouts/admin', 404);
        }
        return $this->page($request, 'admin/theme-preview', 'Theme preview', [
            'theme_record' => $this->themeRecord($slug),
            'theme_slug' => $slug,
        ], true, 'layouts/admin');
    }

    public function updateTheme(Request $request): Response
    {
        $slug = strtolower((string) $request->attribute('slug'));
        $actor = $this->auth->currentUser();
        try {
            if ($actor === null || !in_array($slug, self::THEME_SLUGS, true)) {
                throw new DomainException('The requested installed theme was not found.');
            }
            $reason = $this->requiredReason((string) $request->input('reason'));
            $theme = $this->validatedTheme($request, $slug);
            $previous = $this->themeRecord($slug);
            $themes = $this->allThemeRecords();
            $themes[$slug] = $theme;
            $stylesheet = $this->publishThemeStylesheet($themes);
            $this->database->transaction(function () use ($actor, $slug, $previous, $theme, $stylesheet, $reason, $request): void {
                $this->writeSetting('theme.preset.' . $slug, $theme, $actor->id);
                $this->writeSetting('theme.generated_stylesheet', $stylesheet, $actor->id);
                $this->audit->record(
                    $actor->id,
                    'theme.updated',
                    'theme',
                    $slug,
                    $previous,
                    $theme + ['stylesheet' => (string) $this->setting('theme.generated_stylesheet', '')],
                    $reason,
                    $this->ipHasher->hash($request->clientIp()),
                );
            });
            $this->flash('The theme passed format and contrast validation. A versioned same-origin stylesheet was published.', 'success');
        } catch (DomainException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash('The theme could not be published. The existing stylesheet remains active.', 'error');
        }
        return Response::redirect('/admin/themes?theme=' . rawurlencode($slug));
    }

    private function commercePage(Request $request): Response
    {
        if (!$this->can('payments.summary.view')) {
            return $this->forbidden($request);
        }
        return $this->page($request, 'admin/commerce', 'Commerce status', $this->commerceData(), true, 'layouts/admin');
    }

    /** @return array<string,mixed> */
    private function commerceData(): array
    {
        $mode = (string) $this->config->get('payments.mode', 'sandbox');
        $providers = [];
        foreach (['paypal' => 'PayPal', 'square' => 'Square'] as $key => $name) {
            $environment = (string) $this->config->get("payments.{$key}.environment", 'sandbox');
            $publicId = (string) $this->config->get($key === 'paypal' ? 'payments.paypal.client_id' : 'payments.square.application_id', '');
            $webhook = (string) $this->config->get($key === 'paypal' ? 'payments.paypal.webhook_id' : 'payments.square.webhook_url', '');
            $providers[] = [
                'name' => $name,
                'status' => $this->paymentGate->providerConfigured($key, $environment) ? 'ready' : 'not_configured',
                'public_id' => $this->maskedIdentifier($publicId),
                'webhook' => trim($webhook) === '' ? 'Missing' : 'Configured',
            ];
        }
        $recentVerifiedWebhook = $this->database->fetchOne(
            'SELECT received_at FROM webhook_events WHERE signature_valid = 1 ORDER BY id DESC LIMIT 1',
        );
        $lock = $this->activationLocked();
        $checks = [
            'Adult Owner identity verified' => $this->config->get('payments.adult_owner_confirmed') === true,
            'Merchant agreements accepted' => (bool) $this->setting('payments.merchant_agreements_accepted', false),
            'Live credentials stored' => $this->selectedLiveProviderReady(),
            'HTTPS confirmed' => str_starts_with((string) $this->config->get('app.url', ''), 'https://'),
            'Webhook signatures verified' => $recentVerifiedWebhook !== null,
            'Refund and cancellation tested' => (bool) $this->setting('payments.refund_cancellation_tested', false),
            'Legal pages reviewed' => (bool) $this->setting('legal.launch_reviewed', false),
            'Live activation approved' => !$lock,
        ];
        return [
            'payment_mode' => $mode,
            'activation_lock' => $lock,
            'providers' => $providers,
            'activation_checks' => $checks,
            'plans' => $this->database->fetchAll('SELECT id, plan_key, name, description, benefits_json, active, sort_order, updated_at FROM membership_plans ORDER BY sort_order, id'),
            'products' => $this->database->fetchAll('SELECT id, product_key, name, description, fixed_contents_json, entitlement_key, refund_policy_reference, active, updated_at FROM products ORDER BY id'),
            'manual_entitlements' => $this->database->fetchAll("SELECT ue.id, ue.user_id, u.username, ue.entitlement_key, ue.ends_at, ue.revoked_at, ue.updated_at FROM user_entitlements ue INNER JOIN users u ON u.id = ue.user_id WHERE ue.source_type = 'admin' ORDER BY ue.id DESC LIMIT 100"),
            'refund_requests' => $this->database->fetchAll('SELECT rr.id, rr.payment_id, rr.requested_amount_cents, rr.status, rr.resolution_note, rr.created_at, rr.updated_at,
                    p.provider, p.status AS payment_status, re.status AS execution_status, re.provider_status,
                    re.attempt_count, re.confirmed_at
                 FROM refund_requests rr INNER JOIN payments p ON p.id = rr.payment_id
                 LEFT JOIN refund_executions re ON re.refund_request_id = rr.id
                 ORDER BY rr.id DESC LIMIT 100'),
            'recent_payments' => $this->database->fetchAll('SELECT id, user_id, provider, status, amount_cents, currency, created_at FROM payments ORDER BY id DESC LIMIT 100'),
            'known_entitlements' => $this->knownEntitlementKeys(),
            'can_manage_catalog' => $this->isAdultOwner() && $this->can('payments.live.configure'),
            'can_track_refunds' => $this->isAdultOwner() && $this->can('payments.refund'),
            'payment_audit' => $this->paymentAuditHistory->snapshot(
                $this->isAdultOwner() && $this->can('payments.audit.view')
            ),
        ];
    }

    private function healthPage(Request $request): Response
    {
        if (!$this->can('system.health.view')) {
            return $this->forbidden($request);
        }
        $snapshot = $_SESSION['admin_health_snapshot'] ?? $this->setting('system.health.last_snapshot', null);
        $checks = is_array($snapshot) && is_array($snapshot['checks'] ?? null)
            ? $snapshot['checks']
            : $this->health->check();
        return $this->page($request, 'admin/health', 'System health', [
            'checks' => $this->healthRows($checks),
            'health_checked_at' => is_array($snapshot) ? (string) ($snapshot['checked_at'] ?? 'not persisted') : 'current request',
        ], true, 'layouts/admin');
    }

    private function auditPage(Request $request): Response
    {
        if (!$this->can('audit.view')) {
            return $this->forbidden($request);
        }
        $events = array_map(static fn (array $row): array => [
            'time' => $row['created_at'],
            'actor' => $row['actor'],
            'action' => $row['action'],
            'target' => $row['target'],
            'request_id' => $row['request_id'],
            'url' => '/admin/audit?event=' . rawurlencode((string) $row['id']),
        ], $this->authorizedAuditRows($request, 200));
        return $this->page($request, 'admin/audit', 'Audit log', [
            'events' => $events,
            'query' => mb_substr(trim((string) $request->input('q', '')), 0, 100),
        ], true, 'layouts/admin');
    }

    private function settingsPage(Request $request): Response
    {
        if (!$this->can('system.settings.manage')) {
            return $this->forbidden($request);
        }
        return $this->page($request, 'admin/settings', 'Site settings', [
            'settings' => $this->settingsData(),
        ], true, 'layouts/admin');
    }

    private function themesPage(Request $request): Response
    {
        if (!$this->can('system.settings.manage')) {
            return $this->forbidden($request);
        }
        $default = (string) $this->setting('site.default_theme', 'purple-parlor');
        $slug = strtolower((string) $request->input('theme', $default));
        if (!in_array($slug, self::THEME_SLUGS, true)) {
            $slug = 'purple-parlor';
        }
        return $this->page($request, 'admin/themes', 'Themes', [
            'theme' => $this->themeRecord($slug),
            'theme_slug' => $slug,
        ], true, 'layouts/admin');
    }

    private function resourcePage(Request $request, string $section): Response
    {
        $requirements = [
            'users' => 'users.view', 'games' => 'games.manage', 'rewards' => 'missions.manage',
            'content' => 'content.manage', 'security' => 'system.health.view',
            'logs' => 'system.health.view', 'backups' => 'backups.manage',
        ];
        if (!$this->can($requirements[$section])) {
            return $this->forbidden($request);
        }
        $query = mb_strtolower(mb_substr(trim((string) $request->input('q', '')), 0, 100));
        $data = match ($section) {
            'users' => $this->usersResource($query),
            'games' => $this->gamesResource($query),
            'rewards' => $this->rewardsResource($query),
            'content' => $this->contentResource(),
            'security' => $this->securityResource($query),
            'logs' => $this->logsResource(),
            'backups' => $this->backupsResource(),
        };
        $data['query'] = $query;
        $view = in_array($section, ['users', 'games', 'rewards', 'content'], true)
            ? 'admin/' . $section
            : 'admin/resource-table';
        return $this->page($request, $view, (string) $data['resource'], $data, true, 'layouts/admin');
    }

    /** @return array<string,mixed> */
    private function usersResource(string $query): array
    {
        $rows = [];
        foreach ($this->database->fetchAll(
            'SELECT id, username, status, created_at, last_login_at FROM users WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 200',
        ) as $user) {
            if ($query !== '' && !str_contains(mb_strtolower((string) $user['username'] . ' ' . (string) $user['status']), $query)) {
                continue;
            }
            $roles = $this->database->fetchAll(
                'SELECT r.name FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :user ORDER BY r.name',
                ['user' => $user['id']],
            );
            $rows[] = [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'status' => ucfirst((string) $user['status']), 'status_value' => (string) $user['status'],
                'roles' => implode(', ', array_column($roles, 'name')), 'role_names' => array_values(array_column($roles, 'name')),
                'created' => (string) $user['created_at'],
                'last_login' => (string) ($user['last_login_at'] ?? 'Never'),
                'edit_url' => '/admin/users?review=' . rawurlencode((string) $user['id']),
            ];
        }
        return [
            'resource' => 'Users & access',
            'description' => 'Role-filtered account status. Email addresses and authentication material are not displayed in this list.',
            'columns' => ['username' => 'Username', 'status' => 'Status', 'roles' => 'Roles', 'created' => 'Created', 'last_login' => 'Last login'],
            'rows' => $rows,
            'role_options' => array_column($this->database->fetchAll('SELECT name FROM roles ORDER BY name'), 'name'),
            'permission_options' => array_column($this->database->fetchAll('SELECT name FROM permissions ORDER BY name'), 'name'),
            'role_permissions' => $this->database->fetchAll('SELECT r.name AS role_name, p.name AS permission_name FROM role_permissions rp INNER JOIN roles r ON r.id = rp.role_id INNER JOIN permissions p ON p.id = rp.permission_id ORDER BY r.name, p.name'),
            'can_manage_roles' => $this->isAdultOwner() && $this->can('users.roles.manage'),
        ];
    }

    /** @return array<string,mixed> */
    private function gamesResource(string $query): array
    {
        $rows = [];
        foreach ($this->database->fetchAll('SELECT id, name, slug, category, active, configuration_json, updated_at FROM game_definitions ORDER BY name LIMIT 200') as $game) {
            if ($query !== '' && !str_contains(mb_strtolower((string) $game['name'] . ' ' . (string) $game['slug'] . ' ' . (string) $game['category']), $query)) {
                continue;
            }
            $configuration = json_decode((string) ($game['configuration_json'] ?? ''), true);
            $wager = is_array($configuration) && is_array($configuration['wager'] ?? null) ? $configuration['wager'] : [];
            $rows[] = [
                'id' => (int) $game['id'],
                'name' => (string) $game['name'], 'slug' => (string) $game['slug'], 'category' => (string) $game['category'],
                'status' => (bool) $game['active'] ? 'Active' : 'Disabled', 'active' => (bool) $game['active'],
                'minimum' => (int) ($wager['minimum'] ?? 0), 'maximum' => (int) ($wager['maximum'] ?? 0),
                'increment' => (int) ($wager['increment'] ?? 0), 'updated' => (string) $game['updated_at'],
                'edit_url' => '/admin/games?review=' . rawurlencode((string) $game['id']),
            ];
        }
        return [
            'resource' => 'Games', 'description' => 'Availability and configuration metadata for the play-money catalog.',
            'columns' => ['name' => 'Name', 'slug' => 'Slug', 'category' => 'Category', 'status' => 'Status', 'updated' => 'Updated'], 'rows' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    private function rewardsResource(string $query): array
    {
        $rows = [];
        foreach ($this->database->fetchAll('SELECT id, mission_key, name, description, target_value, reward_coins, reward_stars, active, starts_at, ends_at FROM missions ORDER BY id DESC LIMIT 100') as $mission) {
            if ($query === '' || str_contains(mb_strtolower((string) $mission['name']), $query)) {
                $rows[] = $mission + ['type' => 'Mission', 'status' => $mission['active'] ? 'Active' : 'Disabled', 'window' => $mission['starts_at'] . ' to ' . $mission['ends_at'], 'edit_url' => '/admin/rewards?mission=' . $mission['id']];
            }
        }
        foreach ($this->database->fetchAll('SELECT id, achievement_key, name, description, reward_coins, reward_stars, active, created_at FROM achievements ORDER BY id DESC LIMIT 100') as $achievement) {
            if ($query === '' || str_contains(mb_strtolower((string) $achievement['name']), $query)) {
                $rows[] = $achievement + ['type' => 'Achievement', 'status' => $achievement['active'] ? 'Active' : 'Disabled', 'window' => 'Created ' . $achievement['created_at'], 'edit_url' => '/admin/rewards?achievement=' . $achievement['id']];
            }
        }
        $daily = $this->setting('rewards.daily.amount', 1000);
        return [
            'resource' => 'Rewards', 'description' => 'Mission and achievement definitions. Currency awards still pass through the ledger.',
            'columns' => ['type' => 'Type', 'name' => 'Name', 'status' => 'Status', 'window' => 'Window'], 'rows' => $rows,
            'daily_amount' => is_int($daily) ? $daily : 1000,
        ];
    }

    /** @return array<string,mixed> */
    private function contentResource(): array
    {
        $definitions = [
            ['Contact messages', 'contact_messages', 'submitted_at'],
            ['Licensing inquiries', 'licensing_inquiries', 'submitted_at'],
            ['Support tickets', 'support_tickets', 'created_at'],
            ['Sponsors', 'sponsors', 'created_at'],
            ['Email queue', 'email_queue', 'created_at'],
        ];
        $rows = [];
        foreach ($definitions as [$name, $table, $dateColumn]) {
            $aggregate = $this->database->fetchOne("SELECT COUNT(*) AS total, MAX({$dateColumn}) AS latest FROM {$table}");
            $rows[] = ['resource_name' => $name, 'status' => number_format((int) ($aggregate['total'] ?? 0)) . ' records', 'updated' => (string) ($aggregate['latest'] ?? 'No activity'), 'edit_url' => '/admin/content?resource=' . rawurlencode($table)];
        }
        $requests = [];
        foreach ($this->database->fetchAll('SELECT id, subject AS summary, status, submitted_at AS received_at FROM contact_messages ORDER BY id DESC LIMIT 50') as $row) {
            $requests[] = $row + ['type' => 'contact_message', 'label' => 'Contact message'];
        }
        foreach ($this->database->fetchAll('SELECT id, service_requested AS summary, status, submitted_at AS received_at FROM licensing_inquiries ORDER BY id DESC LIMIT 50') as $row) {
            $requests[] = $row + ['type' => 'licensing_inquiry', 'label' => 'Licensing inquiry'];
        }
        if ($this->can('support.manage')) {
            foreach ($this->database->fetchAll('SELECT id, subject AS summary, status, created_at AS received_at FROM support_tickets ORDER BY id DESC LIMIT 50') as $row) {
                $requests[] = $row + ['type' => 'support_ticket', 'label' => 'Support ticket'];
            }
        }
        usort($requests, static fn (array $first, array $second): int => strcmp((string) $second['received_at'], (string) $first['received_at']));

        $workflows = [];
        foreach ((AdminMutationService::workflowKeys()['content'] ?? []) as $key) {
            $workflows[] = ['type' => 'content', 'key' => $key] + $this->mutations->workflow('content', $key);
        }
        $managedContent = array_values(array_filter($this->managedContent->registry(),
            fn (array $item): bool => $item['type'] !== 'legal' || $this->can('legal.manage')));
        return [
            'resource' => 'Content & requests', 'description' => 'Aggregate content queues. User-submitted personal details are shown only inside an authorized review workflow.',
            'columns' => ['resource_name' => 'Resource', 'status' => 'Count', 'updated' => 'Latest activity'], 'rows' => $rows,
            'requests' => array_slice($requests, 0, 100),
            'advertising_slots' => $this->database->fetchAll('SELECT id, slot_key, provider, enabled, updated_at FROM advertising_slots ORDER BY slot_key'),
            'sponsors' => $this->database->fetchAll('SELECT id, name, status, starts_at, ends_at FROM sponsors ORDER BY id DESC LIMIT 100'),
            'workflows' => $workflows,
            'managed_content' => $managedContent,
            'can_ads' => $this->isAdultOwner() && $this->can('ads.manage'),
            'can_legal' => $this->can('legal.manage'),
            'can_publish_legal' => $this->isAdultOwner() && $this->can('legal.manage'),
            'can_support' => $this->can('support.manage'),
        ];
    }

    /** @return array<string,mixed> */
    private function securityResource(string $query): array
    {
        $rows = [];
        foreach ($this->database->fetchAll('SELECT id, event_type, severity, request_id, created_at FROM security_events ORDER BY id DESC LIMIT 200') as $event) {
            if ($query !== '' && !str_contains(mb_strtolower((string) $event['event_type'] . ' ' . (string) $event['severity']), $query)) {
                continue;
            }
            $rows[] = [
                'event' => str_replace('_', ' ', (string) $event['event_type']), 'severity' => ucfirst((string) $event['severity']),
                'request' => (string) ($event['request_id'] ?: 'Not recorded'), 'created' => (string) $event['created_at'],
                'edit_url' => '/admin/security?event=' . rawurlencode((string) $event['id']),
            ];
        }
        return [
            'resource' => 'Security events', 'description' => 'Safe event types and correlation IDs. Network identifiers remain minimized and hidden.',
            'columns' => ['event' => 'Event', 'severity' => 'Severity', 'request' => 'Request ID', 'created' => 'Created'], 'rows' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    private function logsResource(): array
    {
        $rows = [];
        foreach (glob($this->root() . '/storage/logs/*.log') ?: [] as $path) {
            $real = realpath($path);
            $root = realpath($this->root() . '/storage/logs');
            if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $rows[] = [
                'name' => basename($real), 'status' => number_format((int) filesize($real)) . ' bytes',
                'updated' => gmdate('Y-m-d H:i:s', (int) filemtime($real)), 'edit_url' => '/admin/logs?file=' . rawurlencode(basename($real)),
            ];
        }
        return [
            'resource' => 'Redacted logs', 'description' => 'Only safe file metadata appears here. Tokens, secrets, cookies, and card-like values are never rendered.',
            'columns' => ['name' => 'Log file', 'status' => 'Size', 'updated' => 'Updated (UTC)'], 'rows' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    private function backupsResource(): array
    {
        $rows = array_map(static fn (array $row): array => [
            'name' => (string) $row['filename'], 'status' => ucfirst((string) $row['status']),
            'size' => number_format((int) $row['size_bytes']) . ' bytes', 'created' => (string) $row['created_at'],
            'edit_url' => '/admin/backups?review=' . rawurlencode((string) $row['id']),
        ], $this->database->fetchAll('SELECT id, filename, size_bytes, status, created_at FROM backup_records ORDER BY id DESC LIMIT 100'));
        return [
            'resource' => 'Backup status', 'description' => 'Backup metadata only. Paths, restoration controls, and database contents are never exposed here.',
            'columns' => ['name' => 'Archive', 'status' => 'Status', 'size' => 'Size', 'created' => 'Created (UTC)'], 'rows' => $rows,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function authorizedAuditRows(Request $request, int $limit): array
    {
        $query = mb_strtolower(mb_substr(trim((string) $request->input('q', '')), 0, 100));
        $severity = mb_strtolower(trim((string) $request->input('severity', 'all')));
        $full = $this->isAdultOwner() && $this->can('payments.audit.view');
        $raw = $this->database->fetchAll(
            'SELECT al.id, al.action, al.target_type, al.target_id, al.reason, al.request_id, al.created_at,
                    COALESCE(up.display_name, u.username, :system) AS actor
             FROM admin_audit_logs al
             LEFT JOIN users u ON u.id = al.administrator_id
             LEFT JOIN user_profiles up ON up.user_id = u.id
             ORDER BY al.id DESC LIMIT ' . max(1, min(5000, $limit * 3)),
            ['system' => 'System'],
        );
        $rows = [];
        foreach ($raw as $row) {
            $action = (string) $row['action'];
            if (!$full && preg_match('/(?:payment|merchant|payout|refund|dispute|credential|backup)/i', $action)) {
                continue;
            }
            $haystack = mb_strtolower(implode(' ', [$row['actor'], $action, $row['target_type'], $row['target_id']]));
            if ($query !== '' && !str_contains($haystack, $query)) {
                continue;
            }
            $category = $this->auditCategory($action);
            if (in_array($severity, ['security', 'payment', 'content'], true) && $category !== $severity) {
                continue;
            }
            $target = (string) $row['target_type'] . ($row['target_id'] !== null ? ':' . (string) $row['target_id'] : '');
            $rows[] = [
                'id' => (int) $row['id'], 'created_at' => (string) $row['created_at'],
                'actor' => $this->redactor->redactText((string) $row['actor']),
                'action' => $this->redactor->redactText($action),
                'target' => $this->redactor->redactText($target),
                'request_id' => (string) $row['request_id'],
                'reason' => $this->redactor->redactText((string) ($row['reason'] ?? '')),
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    /** @param array<string,array{ok:bool,detail:string}> $checks @return list<array{name:string,status:string,detail:string}> */
    private function healthRows(array $checks): array
    {
        $requiredExtensions = array_filter($checks, static fn (string $key): bool => str_starts_with($key, 'extension_'), ARRAY_FILTER_USE_KEY);
        $writable = array_filter($checks, static fn (string $key): bool => str_starts_with($key, 'writable_'), ARRAY_FILTER_USE_KEY);
        $https = str_starts_with((string) $this->config->get('app.url', ''), 'https://');
        $webhookReady = $this->webhookConfigurationReady();
        $connection = $this->latestConnectionTest();
        $definitions = [
            ['PHP version', (bool) ($checks['php_version']['ok'] ?? false), (string) ($checks['php_version']['detail'] ?? 'unknown')],
            ['Required extensions', $this->allHealthy($requiredExtensions), $this->allHealthy($requiredExtensions) ? 'All required extensions loaded' : 'One or more required extensions are missing'],
            ['Database connectivity', (bool) ($checks['database']['ok'] ?? false), (string) ($checks['database']['detail'] ?? 'not checked')],
            ['Writable storage', $this->allHealthy($writable), $this->allHealthy($writable) ? 'All private storage directories writable' : 'A private storage directory is not writable'],
            ['HTTPS & secure cookies', $https && (bool) ($checks['secure_session_cookie']['ok'] ?? false), $https ? (string) ($checks['secure_session_cookie']['detail'] ?? 'checked') : 'Application URL is not HTTPS'],
            ['Cron freshness', (bool) ($checks['cron_freshness']['ok'] ?? false), (string) ($checks['cron_freshness']['detail'] ?? 'never')],
            ['SMTP configuration', (bool) ($checks['mail_configuration']['ok'] ?? false), (string) ($checks['mail_configuration']['detail'] ?? 'not checked')],
            ['Payment sandbox connectivity', $connection['ok'], $connection['detail']],
            ['Payment webhooks', $webhookReady, $webhookReady ? 'Signature configuration present' : 'Signature configuration incomplete'],
            ['Backup freshness', (bool) ($checks['backup_freshness']['ok'] ?? false), (string) ($checks['backup_freshness']['detail'] ?? 'never')],
            ['Disk space', (bool) ($checks['disk_space']['ok'] ?? false), (string) ($checks['disk_space']['detail'] ?? 'unavailable')],
            ['Production debug mode', (bool) ($checks['debug']['ok'] ?? false), (string) ($checks['debug']['detail'] ?? 'unknown')],
            ['Application key', (bool) ($checks['app_key']['ok'] ?? false), (string) ($checks['app_key']['detail'] ?? 'unknown')],
        ];
        return array_map(static fn (array $check): array => [
            'name' => $check[0], 'status' => $check[1] ? 'success' : 'warning', 'detail' => $check[2],
        ], $definitions);
    }

    /** @param array<string,array{ok:bool,detail:string}> $checks @return list<array{name:string,status:string,label:string}> */
    private function dashboardHealth(array $checks): array
    {
        $rows = $this->healthRows($checks);
        $wanted = ['Database connectivity', 'Cron freshness', 'SMTP configuration', 'Payment webhooks', 'Backup freshness', 'Production debug mode'];
        $output = [];
        foreach ($wanted as $name) {
            foreach ($rows as $row) {
                if ($row['name'] === $name) {
                    $output[] = ['name' => $name, 'status' => $row['status'], 'label' => $row['status'] === 'success' ? 'Ready' : 'Review'];
                    break;
                }
            }
        }
        return $output;
    }

    /** @return array{ok:bool,detail:string} */
    private function latestConnectionTest(): array
    {
        $latest = null;
        foreach (['paypal', 'square'] as $provider) {
            $record = $this->setting('payments.connection_test.' . $provider, null);
            if (is_array($record) && (bool) ($record['successful'] ?? false)) {
                $time = strtotime((string) ($record['tested_at'] ?? '')) ?: 0;
                if ($latest === null || $time > $latest['time']) {
                    $latest = ['provider' => $provider, 'time' => $time, 'at' => (string) $record['tested_at']];
                }
            }
        }
        if ($latest === null) {
            return ['ok' => false, 'detail' => 'No successful read-only provider test recorded'];
        }
        return ['ok' => $latest['time'] >= time() - 7 * 86400, 'detail' => ucfirst($latest['provider']) . ' tested ' . $latest['at'] . ' UTC'];
    }

    /** @return array<string,mixed> */
    private function settingsData(): array
    {
        $age = $this->ownerSettings->agePolicy();
        return [
            'site_name' => (string) $this->setting('site.name', $this->config->get('app.name', 'The Purple Parlor')),
            'creator_name' => (string) $this->setting('site.creator_name', $this->config->get('app.creator_name', $this->config->get('app.brand', 'Lord Funion'))),
            'app_url' => (string) $this->setting('site.app_url', $this->config->get('app.url', '')),
            'support_email' => (string) $this->setting('site.support_email', $this->config->get('app.support_email', '')),
            'minimum_age' => (int) $age['minimum_age'],
            'policy_version' => (string) $age['policy_version'],
            'default_theme' => (string) $this->setting('site.default_theme', 'purple-parlor'),
            'public_indexing' => (bool) $this->setting('site.public_indexing', $this->config->get('app.indexing_enabled', false)),
            'maintenance_mode' => (bool) $this->setting('site.maintenance_mode', false),
        ];
    }

    private function publishRobots(bool $indexing): void
    {
        $content = $indexing
            ? "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /install\nDisallow: /account/\nDisallow: /profile/\nDisallow: /settings\nDisallow: /billing/\nDisallow: /api/\nDisallow: /storage/\nDisallow: /docs/\n"
            : "User-agent: *\nDisallow: /\n\n# Indexing is disabled until the Adult Owner approves launch.\n";
        $path = $this->root() . '/public/robots.txt';
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $content, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The safe robots.txt policy could not be published. Indexing remains blocked until this is corrected.');
        }
        @chmod($path, 0644);
    }

    /** @return array<string,array<string,string>> */
    private function defaultThemes(): array
    {
        return [
            'purple-parlor' => ['name' => 'Purple Parlor', 'slug' => 'purple-parlor', 'page' => '#160c1f', 'page_deep' => '#0e0815', 'panel' => '#281432', 'panel_raised' => '#351b40', 'purple' => '#8e51b2', 'lavender' => '#d9b8ef', 'gold' => '#e8b85d', 'cream' => '#fff3d2', 'ink' => '#fff9e8'],
            'midnight-lavender' => ['name' => 'Midnight Lavender', 'slug' => 'midnight-lavender', 'page' => '#0b1026', 'page_deep' => '#060919', 'panel' => '#181d3c', 'panel_raised' => '#22284c', 'purple' => '#7176d3', 'lavender' => '#c9c8ff', 'gold' => '#edc66f', 'cream' => '#fff3d2', 'ink' => '#fff9e8'],
            'cozy-fireplace' => ['name' => 'Cozy Fireplace', 'slug' => 'cozy-fireplace', 'page' => '#1e0d14', 'page_deep' => '#10070a', 'panel' => '#35151c', 'panel_raised' => '#47201e', 'purple' => '#a44557', 'lavender' => '#f1b3ad', 'gold' => '#efad4d', 'cream' => '#fff3d2', 'ink' => '#fff9e8'],
            'royal-plum' => ['name' => 'Royal Plum', 'slug' => 'royal-plum', 'page' => '#21091d', 'page_deep' => '#130510', 'panel' => '#3c102f', 'panel_raised' => '#51163e', 'purple' => '#b13c88', 'lavender' => '#f0aed9', 'gold' => '#e6c06c', 'cream' => '#fff3d2', 'ink' => '#fff9e8'],
            'soft-daylight' => ['name' => 'Soft Daylight', 'slug' => 'soft-daylight', 'page' => '#f8f0f5', 'page_deep' => '#eee1eb', 'panel' => '#fffaf1', 'panel_raised' => '#ffffff', 'purple' => '#7c3c83', 'lavender' => '#6f3b77', 'gold' => '#a86813', 'cream' => '#3a2136', 'ink' => '#2e1831'],
            'high-contrast' => ['name' => 'High Contrast', 'slug' => 'high-contrast', 'page' => '#050505', 'page_deep' => '#000000', 'panel' => '#111111', 'panel_raised' => '#191919', 'purple' => '#d17bff', 'lavender' => '#f2d6ff', 'gold' => '#ffdb59', 'cream' => '#ffffff', 'ink' => '#ffffff'],
        ];
    }

    /** @return array<string,string> */
    private function themeRecord(string $slug): array
    {
        $defaults = $this->defaultThemes();
        $fallback = $defaults[$slug] ?? $defaults['purple-parlor'];
        $stored = $this->setting('theme.preset.' . $slug, null);
        if (!is_array($stored) || (string) ($stored['slug'] ?? '') !== $slug) {
            return $fallback;
        }
        $name = trim((string) ($stored['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            return $fallback;
        }
        $theme = ['name' => $name, 'slug' => $slug];
        foreach (self::THEME_TOKENS as $token) {
            $color = strtolower((string) ($stored[$token] ?? ''));
            if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
                return $fallback;
            }
            $theme[$token] = $color;
        }
        return $theme;
    }

    /** @return array<string,array<string,string>> */
    private function allThemeRecords(): array
    {
        $records = [];
        foreach (self::THEME_SLUGS as $slug) {
            $records[$slug] = $this->themeRecord($slug);
        }
        return $records;
    }

    /** @return array<string,string> */
    private function validatedTheme(Request $request, string $routeSlug): array
    {
        $postedSlug = strtolower(trim((string) $request->input('slug')));
        if ($postedSlug !== $routeSlug || !in_array($postedSlug, self::THEME_SLUGS, true)) {
            throw new DomainException('Installed theme slugs cannot be renamed through this form.');
        }
        $theme = [
            'name' => $this->plainText((string) $request->input('name'), 'Theme name', 2, 60),
            'slug' => $routeSlug,
        ];
        foreach (self::THEME_TOKENS as $token) {
            $value = strtolower(trim((string) $request->input($token)));
            if (preg_match('/^#[0-9a-f]{6}$/', $value) !== 1) {
                throw new DomainException('Every theme token must be a six-digit hexadecimal color.');
            }
            $theme[$token] = $value;
        }
        $pairs = [
            ['ink', 'page', 4.5], ['ink', 'panel', 4.5], ['ink', 'panel_raised', 4.5],
            ['gold', 'page', 3.0], ['gold', 'panel', 3.0], ['purple', 'page', 3.0],
        ];
        foreach ($pairs as [$foreground, $background, $minimum]) {
            $ratio = $this->contrastRatio($theme[$foreground], $theme[$background]);
            if ($ratio + 0.0001 < $minimum) {
                throw new DomainException(sprintf(
                    'Theme contrast failed: %s against %s is %.2f:1; at least %.1f:1 is required.',
                    str_replace('_', ' ', $foreground),
                    str_replace('_', ' ', $background),
                    $ratio,
                    $minimum,
                ));
            }
        }
        return $theme;
    }

    /** @param array<string,array<string,string>> $themes */
    private function publishThemeStylesheet(array $themes): string
    {
        ksort($themes);
        $css = "@layer theme {\n";
        foreach ($themes as $slug => $theme) {
            if (!in_array($slug, self::THEME_SLUGS, true)) {
                continue;
            }
            $declarations = [];
            foreach (self::THEME_TOKENS as $token) {
                $value = (string) ($theme[$token] ?? '');
                if (preg_match('/^#[0-9a-f]{6}$/', $value) !== 1) {
                    throw new RuntimeException('A stored theme token failed publication validation.');
                }
                $declarations[] = '    --' . str_replace('_', '-', $token) . ': ' . $value . ';';
            }
            $css .= '  [data-theme="' . $slug . "\"] {\n" . implode("\n", $declarations) . "\n  }\n";
        }
        $css .= "}\n";
        $hash = hash('sha256', $css);
        $directory = realpath($this->root() . '/public/assets/css');
        $public = realpath($this->root() . '/public');
        if ($directory === false || $public === false || !str_starts_with($directory, $public . DIRECTORY_SEPARATOR) || !is_writable($directory)) {
            throw new RuntimeException('The same-origin stylesheet directory is not writable.');
        }
        $filename = 'generated-theme-' . $hash . '.css';
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($target)) {
            $temporary = $directory . DIRECTORY_SEPARATOR . '.theme-' . bin2hex(random_bytes(8)) . '.tmp';
            if (file_put_contents($temporary, $css, LOCK_EX) === false) {
                throw new RuntimeException('The generated stylesheet could not be written.');
            }
            @chmod($temporary, 0644);
            if (!@rename($temporary, $target)) {
                @unlink($temporary);
                if (!is_file($target)) {
                    throw new RuntimeException('The generated stylesheet could not be published atomically.');
                }
            }
        }
        return '/assets/css/' . $filename;
    }

    private function contrastRatio(string $first, string $second): float
    {
        $one = $this->relativeLuminance($first);
        $two = $this->relativeLuminance($second);
        return (max($one, $two) + 0.05) / (min($one, $two) + 0.05);
    }

    private function relativeLuminance(string $color): float
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $channel = hexdec(substr($color, $offset, 2)) / 255;
            $channels[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function webhookConfigurationReady(): bool
    {
        $paypalEnabled = $this->config->get('payments.paypal.enabled') === true;
        $squareEnabled = $this->config->get('payments.square.enabled') === true;
        if (!$paypalEnabled && !$squareEnabled) {
            return $this->config->get('payments.enabled') !== true;
        }
        $paypal = !$paypalEnabled || trim((string) $this->config->get('payments.paypal.webhook_id', '')) !== '';
        $square = !$squareEnabled
            || (trim((string) $this->config->get('payments.square.signature_key', '')) !== ''
                && str_starts_with((string) $this->config->get('payments.square.webhook_url', ''), 'https://'));
        return $paypal && $square;
    }

    private function selectedLiveProviderReady(): bool
    {
        $provider = strtolower((string) $this->config->get('payments.provider', 'demo'));
        return in_array($provider, ['paypal', 'square'], true) && $this->paymentGate->providerConfigured($provider, 'live');
    }

    private function activationLocked(): bool
    {
        return $this->config->get('payments.live_activation_lock', true) === true
            || (bool) $this->setting('payments.production_locked', true);
    }

    private function maskedIdentifier(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Missing';
        }
        if (strlen($value) <= 10) {
            return 'Configured';
        }
        return substr($value, 0, 5) . '…' . substr($value, -4);
    }

    private function requiredReason(string $reason, int $minimum = 5): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < $minimum || mb_strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason)) {
            throw new DomainException("Provide a clear reason between {$minimum} and 500 characters.");
        }
        return $this->redactor->redactText($reason);
    }

    private function plainText(string $value, string $label, int $minimum, int $maximum): string
    {
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new DomainException("{$label} must be {$minimum}–{$maximum} characters and cannot contain control characters.");
        }
        return $value;
    }

    private function validatedAppUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new DomainException('Enter a valid application URL.');
        }
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new DomainException('The application URL must be an HTTP(S) base URL without credentials, a query, or a fragment.');
        }
        return $url;
    }

    private function requireAdultOwner(): void
    {
        if (!$this->isAdultOwner()) {
            throw new RuntimeException('Only an Adult Owner may change the minimum-age policy or approve public indexing.');
        }
    }

    /** @param callable(User):string $callback */
    private function handleAdminMutation(
        Request $request,
        string $redirect,
        string $permission,
        bool $adultOwner,
        callable $callback,
    ): Response {
        $actor = $this->auth->currentUser();
        if ($actor === null) {
            return Response::redirect('/login');
        }
        try {
            $this->authorization->authorize($actor->id, $permission);
            if ($adultOwner) {
                $this->requireAdultOwner();
                $this->auth->requirePrivilegedSession();
            }
            $this->flash($callback($actor), 'success');
        } catch (AuthenticationException) {
            return Response::redirect('/confirm-password?return=' . rawurlencode($redirect));
        } catch (AuthorizationException) {
            return $this->forbidden($request);
        } catch (DomainException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash('The administrative change could not be completed. No partial provider or ledger action was performed.', 'error');
        }
        return Response::redirect($redirect);
    }

    private function targetIsAdministrator(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return $this->database->fetchOne(
            "SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user AND r.name IN ('adult_owner','developer_admin','support_admin','content_manager','moderator') LIMIT 1",
            ['user' => $userId],
        ) !== null;
    }

    private function isAdultOwner(): bool
    {
        $user = $this->auth->currentUser();
        return $user !== null && in_array('adult_owner', $user->roles, true);
    }

    private function can(string $permission): bool
    {
        return $this->authorization->can($this->userId(), $permission);
    }

    private function managedContentPermission(string $type): string
    {
        return match ($type) {
            'legal' => 'legal.manage',
            'email_template' => 'content.manage',
            // Invalid types are rejected by the service inside the mutation
            // boundary, where the error is safely flashed instead of escaping.
            default => 'content.manage',
        };
    }

    private function forbidden(Request $request): Response
    {
        return $this->page($request, 'errors/403', 'Access denied', [
            'request_id' => RequestContext::id(),
        ], true, 'layouts/admin', 403);
    }

    private function scalar(string $sql, array $parameters = []): int
    {
        $row = $this->database->fetchOne($sql, $parameters);
        return (int) round((float) ($row['aggregate'] ?? 0));
    }

    /** @return list<string> */
    private function knownEntitlementKeys(): array
    {
        $keys = array_map(static fn (array $row): string => (string) $row['entitlement_key'], $this->database->fetchAll('SELECT DISTINCT entitlement_key FROM product_entitlements ORDER BY entitlement_key'));
        foreach ($this->database->fetchAll('SELECT benefits_json FROM membership_plans') as $plan) {
            $benefits = json_decode((string) $plan['benefits_json'], true);
            if (is_array($benefits)) {
                foreach ($benefits as $benefit) {
                    if (is_string($benefit)) {
                        $keys[] = $benefit;
                    }
                }
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return $keys;
    }

    private function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    /** @param array<string,array{ok:bool,detail:string}> $checks */
    private function allHealthy(array $checks): bool
    {
        return $checks !== [] && !in_array(false, array_map(static fn (array $check): bool => (bool) $check['ok'], $checks), true);
    }

    private function auditCategory(string $action): string
    {
        if (preg_match('/(?:payment|merchant|payout|refund|dispute|subscription)/i', $action)) {
            return 'payment';
        }
        if (preg_match('/(?:login|security|role|permission|two_factor|session|password)/i', $action)) {
            return 'security';
        }
        return 'content';
    }

    private function csvCell(mixed $value): string
    {
        $cell = $this->redactor->redactText((string) $value);
        return preg_match('/^[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell;
    }

    private function setting(string $key, mixed $default): mixed
    {
        $row = $this->database->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = :key', ['key' => $key]);
        if ($row === null) {
            return $default;
        }
        $decoded = json_decode((string) $row['setting_value'], true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function writeSetting(string $key, mixed $value, ?int $actor): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{2,190}$/', $key) !== 1) {
            throw new RuntimeException('Invalid system setting key.');
        }
        $params = [
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'actor' => $actor,
            'now' => gmdate('Y-m-d H:i:s'),
        ];
        $sql = $this->database->driver() === 'mysql'
            ? 'INSERT INTO system_settings (setting_key, setting_value, is_sensitive, updated_by, updated_at) VALUES (:key,:value,0,:actor,:now)
               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_sensitive=0,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            : 'INSERT INTO system_settings (setting_key, setting_value, is_sensitive, updated_by, updated_at) VALUES (:key,:value,0,:actor,:now)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,is_sensitive=0,updated_by=excluded.updated_by,updated_at=excluded.updated_at';
        $this->database->execute($sql, $params);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
