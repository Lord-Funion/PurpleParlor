<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\AuthService;
use App\Auth\AuthorizationService;
use App\Auth\SessionManager;
use App\Auth\TwoFactorService;
use App\Database\Database;
use App\Mail\LogMailTransport;
use App\Mail\MailQueue;
use App\Mail\MailTransportInterface;
use App\Mail\PhpMailerTransport;
use App\Payments\HttpClient;
use App\Payments\CheckoutIntentService;
use App\Payments\PaymentAuditService;
use App\Payments\PaymentGate;
use App\Payments\PaymentManager;
use App\Payments\PaymentPlanCatalog;
use App\Payments\PaymentProviderFactory;
use App\Payments\SubscriptionLifecycleService;
use App\Payments\SubscriptionReconciler;
use App\Payments\WebhookProcessor;
use App\Repositories\AuthorizationRepository;
use App\Repositories\LedgerRepository;
use App\Repositories\UserRepository;
use App\Security\Csrf;
use App\Security\Encryptor;
use App\Security\IpHasher;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SecretRedactor;
use App\Security\Totp;
use App\Services\AgeConfirmationService;
use App\Services\AccountService;
use App\Services\AdultOwnerSettingsService;
use App\Services\AnalyticsService;
use App\Services\AuditService;
use App\Services\CronDispatcher;
use App\Services\DatabaseBackupService;
use App\Services\DailyRewardService;
use App\Services\EntitlementService;
use App\Services\GuestConversionService;
use App\Services\ManagedContentService;
use App\Services\MembershipBonusService;
use App\Services\ResponsiblePlayService;
use App\Services\SystemHealthService;
use App\Services\UserSettingsService;
use App\Services\VirtualLedgerService;
use RuntimeException;

final class Bootstrap
{
    public static function create(string $root): Container
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        Env::load($root . '/.env');
        $config = Config::loadDirectory($root . '/config');
        $timezone = (string) $config->get('app.timezone', 'UTC');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException('APP_TIMEZONE is invalid.');
        }
        date_default_timezone_set($timezone);
        $appKey = (string) $config->get('app.key', '');
        // A fresh shared-hosting upload intentionally has no .env or APP_KEY.
        // Permit only the protected installer to boot in that state; once the
        // installer lock exists, production always fails closed on a weak key.
        if ($config->get('app.env') === 'production' && is_file($root . '/storage/installed.lock') && self::keyBytes($appKey) < 32) {
            throw new RuntimeException('A strong APP_KEY is mandatory in production.');
        }
        foreach (['storage/logs', 'storage/cache', 'storage/sessions', 'storage/temporary', 'storage/backups'] as $directory) {
            $path = $root . '/' . $directory;
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }
        }
        if (trim((string) $config->get('app.session.storage_path', '')) === '') {
            $config->set('app.session.storage_path', $root . '/storage/sessions');
        }

        $container = new Container();
        $container->singleton(Config::class, $config);
        $container->singleton(Container::class, $container);
        $container->singleton(Database::class, static fn () => Database::connect($config));
        $container->singleton(SecretRedactor::class, new SecretRedactor());
        $container->singleton(Logger::class, static fn (Container $c) => new Logger($root . '/storage/logs', (string) $config->get('app.logging.level', 'warning'),
            (int) $config->get('app.logging.max_files', 14), $c->get(SecretRedactor::class)));
        $container->singleton(IpHasher::class, static fn () => new IpHasher($appKey));
        $container->singleton(PasswordHasher::class, new PasswordHasher());
        $container->singleton(Csrf::class, new Csrf());
        $container->singleton(RateLimiter::class, static fn (Container $c) => new RateLimiter($c->get(Database::class), $appKey));
        $container->singleton(Encryptor::class, static fn () => new Encryptor($appKey));
        $container->singleton(Totp::class, new Totp());
        $container->singleton(UserRepository::class, static fn (Container $c) => new UserRepository($c->get(Database::class)));
        $container->singleton(AuthorizationRepository::class, static fn (Container $c) => new AuthorizationRepository($c->get(Database::class)));
        $container->singleton(SessionManager::class, static fn (Container $c) => new SessionManager($c->get(Database::class), $config, $c->get(IpHasher::class)));
        $container->singleton(TwoFactorService::class, static fn (Container $c) => new TwoFactorService($c->get(Database::class), $c->get(Encryptor::class), $c->get(Totp::class)));
        $container->singleton(AuthorizationService::class, static fn (Container $c) => new AuthorizationService($c->get(AuthorizationRepository::class)));
        $container->singleton(AuthService::class, static fn (Container $c) => new AuthService($c->get(Database::class), $c->get(UserRepository::class), $c->get(PasswordHasher::class),
            $c->get(SessionManager::class), $c->get(TwoFactorService::class), $c->get(RateLimiter::class), $c->get(IpHasher::class), $appKey, $c->get(VirtualLedgerService::class)));
        $container->singleton(LedgerRepository::class, static fn (Container $c) => new LedgerRepository($c->get(Database::class)));
        $container->singleton(VirtualLedgerService::class, static fn (Container $c) => new VirtualLedgerService($c->get(Database::class), $c->get(LedgerRepository::class), $appKey));
        $container->singleton(AuditService::class, static fn (Container $c) => new AuditService($c->get(Database::class), $appKey));
        $container->singleton(ManagedContentService::class, static fn (Container $c) => new ManagedContentService(
            $c->get(Database::class), $config, $c->get(AuditService::class),
        ));
        $container->singleton(EntitlementService::class, static fn (Container $c) => new EntitlementService($c->get(Database::class)));
        $container->singleton(DailyRewardService::class, static fn (Container $c) => new DailyRewardService($c->get(Database::class), $c->get(VirtualLedgerService::class)));
        $container->singleton(MembershipBonusService::class, static fn (Container $c) => new MembershipBonusService(
            $c->get(Database::class), $c->get(VirtualLedgerService::class), $config,
        ));
        $container->singleton(AgeConfirmationService::class, static fn () => new AgeConfirmationService($appKey));
        $container->singleton(HttpClient::class, new HttpClient());
        $container->singleton(PaymentGate::class, static fn (Container $c) => new PaymentGate($config, $c->get(Database::class)));
        $container->singleton(PaymentProviderFactory::class, static fn (Container $c) => new PaymentProviderFactory($config, $c->get(PaymentGate::class), $c->get(HttpClient::class), $c->get(Database::class)));
        $container->singleton(CheckoutIntentService::class, static fn (Container $c) => new CheckoutIntentService($c->get(Database::class)));
        $container->singleton(PaymentPlanCatalog::class, static fn (Container $c) => new PaymentPlanCatalog($c->get(Database::class), $config));
        $container->singleton(PaymentAuditService::class, static fn (Container $c) => new PaymentAuditService($c->get(Database::class), $appKey));
        $container->singleton(SubscriptionLifecycleService::class, static fn (Container $c) => new SubscriptionLifecycleService($c->get(Database::class), $c->get(EntitlementService::class),
            (int) $config->get('payments.subscription_grace_days', 7)));
        $container->singleton(PaymentManager::class, static fn (Container $c) => new PaymentManager($c->get(Database::class), $c->get(PaymentProviderFactory::class),
            $c->get(PaymentGate::class), $c->get(EntitlementService::class), $c->get(SubscriptionLifecycleService::class),
            $c->get(CheckoutIntentService::class), $c->get(PaymentPlanCatalog::class)));
        $container->singleton(WebhookProcessor::class, static fn (Container $c) => new WebhookProcessor($c->get(Database::class), $c->get(PaymentProviderFactory::class),
            $c->get(SubscriptionLifecycleService::class), $c->get(PaymentAuditService::class), $config));
        $container->singleton(SubscriptionReconciler::class, static fn (Container $c) => new SubscriptionReconciler($c->get(Database::class), $c->get(PaymentProviderFactory::class),
            $c->get(SubscriptionLifecycleService::class), $c->get(EntitlementService::class)));
        $container->singleton(MailTransportInterface::class, static function () use ($config, $root): MailTransportInterface {
            return $config->get('app.mail.driver') === 'log' && $config->get('app.env') !== 'production'
                ? new LogMailTransport($root . '/storage/logs')
                : new PhpMailerTransport($config);
        });
        $container->singleton(MailQueue::class, static fn (Container $c) => new MailQueue($c->get(Database::class), $c->get(MailTransportInterface::class), $config));
        $container->singleton(CronDispatcher::class, static fn (Container $c) => new CronDispatcher($c->get(Database::class), $c->get(MailQueue::class),
            $c->get(SubscriptionReconciler::class), $c->get(EntitlementService::class), $c->get(MembershipBonusService::class), $c->get(WebhookProcessor::class),
            $c->get(DatabaseBackupService::class), $c->get(SystemHealthService::class), $config, $root));
        $container->singleton(DatabaseBackupService::class, static fn (Container $c) => new DatabaseBackupService($c->get(Database::class), $config, $root));
        $container->singleton(SystemHealthService::class, static fn (Container $c) => new SystemHealthService($config, $c->get(Database::class), $root));
        $container->singleton(AccountService::class, static fn (Container $c) => new AccountService($c->get(Database::class), $c->get(AuthService::class),
            $c->get(UserRepository::class), $c->get(AuditService::class)));
        $container->singleton(ResponsiblePlayService::class, static fn (Container $c) => new ResponsiblePlayService($c->get(Database::class)));
        $container->singleton(GuestConversionService::class, static fn (Container $c) => new GuestConversionService($c->get(Database::class), $appKey));
        $container->singleton(AdultOwnerSettingsService::class, static fn (Container $c) => new AdultOwnerSettingsService($c->get(Database::class), $config,
            $c->get(AuthService::class), $c->get(AuditService::class)));
        $container->singleton(AnalyticsService::class, static fn (Container $c) => new AnalyticsService($c->get(Database::class), $appKey));
        $container->singleton(UserSettingsService::class, static fn (Container $c) => new UserSettingsService($c->get(Database::class)));
        $container->singleton(Router::class, static fn (Container $c) => new Router($c));
        $container->singleton(ExceptionHandler::class, static fn (Container $c) => new ExceptionHandler($c->get(Logger::class), $config));
        return $container;
    }

    private static function keyBytes(string $key): int
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            return $decoded === false ? 0 : strlen($decoded);
        }
        return strlen($key);
    }
}
