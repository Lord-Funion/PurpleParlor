<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Database\Migrator;
use App\Http\EnvWriter;
use App\Http\View;
use App\Services\InstallerAccountService;
use Database\Seeds\DatabaseSeeder;
use Throwable;

final class InstallController
{
    private string $lockPath;
    private EnvWriter $environment;

    public function __construct(private readonly View $view, private readonly Config $config)
    {
        $this->lockPath = BASE_PATH . '/storage/installed.lock';
        $this->environment = new EnvWriter(BASE_PATH . '/.env');
    }

    public function index(Request $request): Response
    {
        if (is_file($this->lockPath)) {
            return new Response('Installer locked.', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        $step = (string) $request->input('step', 'welcome');
        if ($step !== 'welcome' && empty($_SESSION['_install_authorized'])) {
            $step = 'welcome';
        }
        $data = ['step' => $step, 'csrf_token' => csrf_token()];
        if ($step === 'requirements') {
            $data['checks'] = $this->requirements();
            $data['can_continue'] = !in_array(false, array_column($data['checks'], 'passed'), true);
        } elseif ($step === 'database') {
            $data['db_host'] = (string) $this->config->get('app.database.host', 'localhost');
            $data['db_port'] = (int) $this->config->get('app.database.port', 3306);
        } elseif ($step === 'application') {
            $data['https'] = str_starts_with((string) $this->config->get('app.url'), 'https://');
        } elseif ($step === 'email') {
            $data['mail_host_label'] = (string) ($this->config->get('app.mail.host') ?: 'Not configured');
            $data['mail_from_label'] = (string) ($this->config->get('app.mail.from_address') ?: 'Not configured');
        } elseif ($step === 'finish') {
            $data['cron_command'] = '/usr/local/bin/php -q ' . BASE_PATH . '/bin/cron.php';
            $data['writable_ready'] = $this->writableDirectory(BASE_PATH . '/storage');
        }
        return $this->render($request, $data);
    }

    public function run(Request $request): Response
    {
        if (is_file($this->lockPath)) {
            return new Response('Installer locked.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $step = (string) $request->input('step', 'welcome');
        if ($step === 'welcome') {
            return $this->authorize($request);
        }
        if (empty($_SESSION['_install_authorized']) || (int) ($_SESSION['_install_authorized_at'] ?? 0) < time() - 3600) {
            unset($_SESSION['_install_authorized'], $_SESSION['_install_authorized_at']);
            return Response::redirect('/install');
        }
        try {
            return match ($step) {
                'requirements' => Response::redirect('/install?step=database'),
                'database' => $this->database($request),
                'accounts' => $this->accounts($request),
                'application' => $this->application($request),
                'email' => $this->email($request),
                'finish' => $this->finish($request),
                default => Response::redirect('/install'),
            };
        } catch (Throwable $exception) {
            $_SESSION['_install_error'] = $this->safeError($exception);
            return Response::redirect('/install?step=' . rawurlencode($step));
        }
    }

    private function authorize(Request $request): Response
    {
        $expected = trim((string) Env::get('INSTALL_TOKEN', ''));
        $provided = (string) $request->input('installation_token');
        if (strlen($expected) < 32 || !hash_equals($expected, $provided)) {
            usleep(250000);
            $_SESSION['_install_error'] = 'The installation token was not accepted.';
            return Response::redirect('/install');
        }
        $_SESSION['_install_authorized'] = true;
        $_SESSION['_install_authorized_at'] = time();
        if (strlen((string) $this->config->get('app.key', '')) < 32) {
            $this->environment->update(['APP_KEY' => 'base64:' . base64_encode(random_bytes(32))]);
            Env::load(BASE_PATH . '/.env', true);
        }
        return Response::redirect('/install?step=requirements');
    }

    private function database(Request $request): Response
    {
        $values = [
            'connection' => 'mysql', 'host' => trim((string) $request->input('db_host')), 'port' => (int) $request->input('db_port', 3306),
            'database' => trim((string) $request->input('db_name')), 'username' => trim((string) $request->input('db_user')),
            'password' => (string) $request->input('db_password'), 'charset' => 'utf8mb4',
        ];
        $database = Database::connect($values);
        $database->fetchOne('SELECT 1 AS connected');
        $this->environment->update([
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $values['host'], 'DB_PORT' => $values['port'], 'DB_DATABASE' => $values['database'],
            'DB_USERNAME' => $values['username'], 'DB_PASSWORD' => $values['password'], 'DB_CHARSET' => 'utf8mb4',
        ]);
        Env::load(BASE_PATH . '/.env', true);
        $runtime = Config::loadDirectory(BASE_PATH . '/config');
        $runtime->set('app.database', $values);
        if ((string) $request->input('run_migrations') === '1') {
            (new Migrator($database, BASE_PATH . '/database/migrations'))->migrate();
            (new DatabaseSeeder($database, $runtime))->run();
        }
        return Response::redirect('/install?step=accounts');
    }

    private function accounts(Request $request): Response
    {
        $database = Database::connect($this->config);
        $input = [];
        foreach (['owner', 'developer'] as $prefix) {
            foreach (['name', 'email', 'password', 'password_confirmation'] as $field) {
                $input[$prefix . '_' . $field] = (string) $request->input($prefix . '_' . $field);
            }
        }
        $ids = (new InstallerAccountService($database, (string) $this->config->get('app.key', '')))
            ->createAdministrators($input);
        $_SESSION['_install_owner_id'] = $ids['owner_id'];
        return Response::redirect('/install?step=application');
    }

    private function application(Request $request): Response
    {
        $url = rtrim(trim((string) $request->input('app_url')), '/');
        $email = trim((string) $request->input('support_email'));
        $age = (int) $request->input('minimum_age', 18);
        $timezone = trim((string) $request->input('timezone'));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://') || !filter_var($email, FILTER_VALIDATE_EMAIL) || $age < 18 || !in_array($timezone, timezone_identifiers_list(), true) || (string) $request->input('confirm_demo') !== '1' || (string) $request->input('confirm_https') !== '1') {
            throw new \DomainException('Application URL, email, age, timezone, and demo-payment confirmation must be valid.');
        }
        $this->environment->update([
            'APP_ENV' => 'production', 'APP_DEBUG' => false, 'APP_URL' => $url, 'APP_SUPPORT_EMAIL' => $email,
            'APP_TIMEZONE' => $timezone, 'APP_MINIMUM_AGE' => $age, 'APP_LEGAL_POLICY_VERSION' => 1, 'APP_INDEXING_ENABLED' => false,
            'MAIL_FROM_ADDRESS' => $email, 'SESSION_SECURE' => true, 'SESSION_SAMESITE' => 'Lax',
            'PAYMENTS_ENABLED' => false, 'PAYMENT_MODE' => 'sandbox', 'PAYMENT_PROVIDER' => 'demo',
            'ADULT_OWNER_CONFIRMED' => false, 'LIVE_PAYMENT_ACTIVATION_LOCK' => true,
        ]);
        return Response::redirect('/install?step=email');
    }

    private function email(Request $request): Response
    {
        if ((string) $request->input('action') === 'test') {
            $email = trim((string) $request->input('test_email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \DomainException('Enter a valid test recipient.');
            }
            $database = Database::connect($this->config);
            $database->execute("INSERT INTO email_queue (recipient_email, recipient_name, template_key, subject, html_body, text_body, headers_json, status, attempts, available_at, created_at) VALUES (:email, :name, 'installer.test', :subject, :html, :text, '{}', 'queued', 0, :available, :created)", ['email' => $email, 'name' => 'Installer test', 'subject' => 'Purple Parlor SMTP test', 'html' => '<p>The protected installer queued this Purple Parlor SMTP test.</p>', 'text' => 'The protected installer queued this Purple Parlor SMTP test.', 'available' => gmdate('Y-m-d H:i:s'), 'created' => gmdate('Y-m-d H:i:s')]);
            $_SESSION['_install_notice'] = 'The SMTP test was queued. Run the cron dispatcher and check the recipient and private mail log.';
            return Response::redirect('/install?step=email');
        }
        return Response::redirect('/install?step=finish');
    }

    private function finish(Request $request): Response
    {
        if ((string) $request->input('security_reviewed') !== '1') {
            throw new \DomainException('Complete the final security acknowledgment.');
        }
        $contents = json_encode(['installed_at' => gmdate('c'), 'installer_version' => 1], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (!is_dir(dirname($this->lockPath))) {
            mkdir(dirname($this->lockPath), 0750, true);
        }
        if (file_put_contents($this->lockPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('The installer lock file could not be written.');
        }
        @chmod($this->lockPath, 0640);
        $this->environment->update(['INSTALL_TOKEN' => '']);
        unset($_SESSION['_install_authorized'], $_SESSION['_install_authorized_at'], $_SESSION['_install_owner_id']);
        return Response::redirect('/');
    }

    /** @return list<array{label:string,detail:string,passed:bool}> */
    private function requirements(): array
    {
        $driver = (string) $this->config->get('app.database.connection', 'mysql');
        $databaseExtension = $driver === 'sqlite' ? extension_loaded('pdo_sqlite') : extension_loaded('pdo_mysql');
        return [
            ['label' => 'PHP 8.2 or newer', 'detail' => PHP_VERSION, 'passed' => version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['label' => 'PDO database driver', 'detail' => $driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql', 'passed' => extension_loaded('pdo') && $databaseExtension],
            ['label' => 'OpenSSL', 'detail' => 'Authenticated encryption and HTTPS requests', 'passed' => extension_loaded('openssl')],
            ['label' => 'mbstring', 'detail' => 'Unicode-safe account and interface text', 'passed' => extension_loaded('mbstring')],
            ['label' => 'JSON', 'detail' => 'Configuration and API payloads', 'passed' => extension_loaded('json')],
            ['label' => 'Private storage writable', 'detail' => BASE_PATH . '/storage', 'passed' => $this->writableDirectory(is_dir(BASE_PATH . '/storage') ? BASE_PATH . '/storage' : BASE_PATH)],
            ['label' => 'Environment file writable', 'detail' => BASE_PATH . '/.env', 'passed' => $this->writableDirectory(BASE_PATH)],
        ];
    }

    /** @param array<string,mixed> $data */
    private function render(Request $request, array $data): Response
    {
        $flash = null;
        if (isset($_SESSION['_install_error'])) {
            $flash = ['type' => 'error', 'message' => (string) $_SESSION['_install_error']];
            unset($_SESSION['_install_error']);
        } elseif (isset($_SESSION['_install_notice'])) {
            $flash = ['type' => 'success', 'message' => (string) $_SESSION['_install_notice']];
            unset($_SESSION['_install_notice']);
        }
        $data['flash'] = $flash;
        $app = ['name' => (string) $this->config->get('app.name', 'The Purple Parlor'), 'creator_name' => (string) $this->config->get('app.brand', 'Lord Funion'), 'tagline' => (string) $this->config->get('app.tagline', ''), 'support_email' => (string) $this->config->get('app.support_email', 'support@lordfunion.dev'), 'url' => (string) $this->config->get('app.url', ''), 'minimum_age' => (int) $this->config->get('app.minimum_age', 18)];
        $body = $this->view->render('installer/index', ['app' => $app, 'page' => ['title' => 'Protected installer', 'description' => 'Private Purple Parlor installation wizard', 'private' => true, 'canonical' => '', 'body_class' => 'installer-page'], 'data' => $data, 'user' => null], 'layouts/app');
        return new Response($body, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive']);
    }

    private function safeError(Throwable $exception): string
    {
        if ($exception instanceof \DomainException) {
            return mb_substr(str_replace(["\r", "\n"], ' ', $exception->getMessage()), 0, 300);
        }
        return 'The protected setup step failed. Check private PHP and database logs, then verify the entered configuration.';
    }

    private function writableDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }
        $probe = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.install-write-check-' . bin2hex(random_bytes(6));
        $handle = @fopen($probe, 'xb');
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        @unlink($probe);
        return true;
    }
}
