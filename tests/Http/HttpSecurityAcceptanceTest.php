<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Auth\AuthorizationService;
use App\Auth\AuthService;
use App\Auth\SessionManager;
use App\Auth\TwoFactorService;
use App\Controllers\WebhookController;
use App\Core\Container;
use App\Core\ExceptionHandler;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Http\Application;
use App\Http\View;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RequirePermissionMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Payments\HttpClient;
use App\Payments\PaymentAuditService;
use App\Payments\PaymentGate;
use App\Payments\PaymentProviderFactory;
use App\Payments\SubscriptionLifecycleService;
use App\Payments\WebhookProcessor;
use App\Repositories\AuthorizationRepository;
use App\Repositories\UserRepository;
use App\Security\Csrf;
use App\Security\Encryptor;
use App\Security\IpHasher;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SecretRedactor;
use App\Security\Totp;
use App\Services\EntitlementService;
use RuntimeException;
use Tests\Support\TestCase;

/**
 * HTTP-layer acceptance checks that run through the real application kernel.
 *
 * These tests deliberately use SQLite and local cryptographic providers. They
 * do not claim to exercise Apache, MySQL, cPanel, or provider networks.
 */
final class HttpSecurityAcceptanceTest extends TestCase
{
    public function testKernelRejectsMissingCsrfBeforeAStateChangingHandlerRuns(): void
    {
        $sessions = $this->sessions();
        $csrf = new Csrf();
        $handled = 0;
        $router = new Router();
        $router->post('/acceptance/mutate', static function () use (&$handled): Response {
            $handled++;
            return Response::json(['saved' => true]);
        }, [new CsrfMiddleware($csrf)]);
        $application = $this->application($router, $sessions);

        $rejected = $application->handle($this->request('POST', '/acceptance/mutate', ['value' => 'changed']));

        $this->assertSame(419, $rejected->status);
        $this->assertSame(0, $handled, 'The mutation handler ran before CSRF validation.');
        $this->assertSame('Your session token expired. Refresh and try again.', $this->json($rejected)['error'] ?? null);

        $accepted = $application->handle($this->request('POST', '/acceptance/mutate', [
            '_csrf' => $csrf->token(),
            'value' => 'changed',
        ]));
        $this->assertSame(200, $accepted->status);
        $this->assertSame(1, $handled, 'A valid same-session CSRF token should reach the handler once.');
    }

    public function testKernelEnforcesAuthenticationAndDatabaseBackedPermissionDenial(): void
    {
        $sessions = $this->sessions();
        $auth = $this->auth($sessions);
        $authorization = new AuthorizationService(new AuthorizationRepository($this->database));
        $handled = 0;
        $router = new Router();
        $router->get('/acceptance/private', static function () use (&$handled): Response {
            $handled++;
            return Response::json(['private' => true]);
        }, [new AuthMiddleware($auth)]);
        $router->get('/acceptance/admin-only', static function () use (&$handled): Response {
            $handled++;
            return Response::json(['admin' => true]);
        }, [new AuthMiddleware($auth), new RequirePermissionMiddleware($authorization, 'users.manage')]);
        $application = $this->application($router, $sessions, $auth);

        $anonymous = $application->handle($this->request('GET', '/acceptance/private'));
        $this->assertSame(401, $anonymous->status);
        $this->assertSame('Authentication required.', $this->json($anonymous)['error'] ?? null);
        $this->assertSame(0, $handled);

        $users = new UserRepository($this->database);
        $member = $users->create('kernel-member@example.test', 'KernelMember', 'not-used-by-this-test', 'active');
        $users->markVerified($member->id);
        $users->assignRole($member->id, 'member');
        $sessions->authenticate($member->id, '127.0.0.1', 'security-acceptance-test');

        $forbidden = $application->handle($this->request('GET', '/acceptance/admin-only'));
        $this->assertSame(403, $forbidden->status);
        $this->assertSame('Forbidden.', $this->json($forbidden)['error'] ?? null);
        $this->assertSame(0, $handled, 'A member without users.manage reached the protected handler.');
        $this->assertSame('private, no-store, max-age=0', $forbidden->headers['Cache-Control'] ?? null);
    }

    public function testRouterRejectsEncodedSlashBackslashAndNullTraversalParameters(): void
    {
        $handled = 0;
        $router = new Router();
        $router->get('/acceptance/files/{name}', static function (Request $request) use (&$handled): Response {
            $handled++;
            return Response::json(['name' => $request->attribute('name'), 'secret' => 'must-not-render']);
        });
        $application = $this->application($router, $this->sessions());

        foreach ([
            '/acceptance/files/%2e%2e%2f.env',
            '/acceptance/files/%2e%2e%5c.env',
            '/acceptance/files/report%00.sql',
        ] as $path) {
            $response = $application->handle($this->request('GET', $path));
            $this->assertTrue(
                in_array($response->status, [404, 405], true),
                'Encoded traversal path was not rejected: ' . $path . ' (HTTP ' . $response->status . ')',
            );
            $this->assertFalse(str_contains($response->body, 'must-not-render'));
        }
        $this->assertSame(0, $handled, 'A rejected route parameter reached its handler.');
    }

    public function testProductionKernelExceptionResponseHidesSqlStackSecretsAndPaths(): void
    {
        $this->config->set('app.env', 'production');
        $this->config->set('app.debug', true);
        $router = new Router();
        $router->get('/acceptance/failure', static function (): never {
            throw new RuntimeException(
                'SQLSTATE[HY000]: SELECT password_hash FROM users at C:\\hosting\\private\\Database.php:77 token=super-secret',
            );
        });
        $application = $this->application($router, $this->sessions());

        $response = $application->handle($this->request('GET', '/acceptance/failure', [], [
            'x-request-id' => 'acceptance-request-1234',
        ]));
        $payload = $this->json($response);

        $this->assertSame(500, $response->status);
        $this->assertSame('An unexpected error occurred.', $payload['error'] ?? null);
        $this->assertSame('acceptance-request-1234', $payload['request_id'] ?? null);
        $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        foreach (['SQLSTATE', 'SELECT password_hash', 'Database.php', 'C:\\hosting', 'super-secret', 'RuntimeException', '#0', 'stack'] as $leak) {
            $this->assertFalse(str_contains($response->body, $leak), 'Production response leaked: ' . $leak);
        }
    }

    public function testActualProfileTemplateEscapesHostileProfileText(): void
    {
        $attack = '\"><script>alert(1)</script><img src=x onerror=alert(2)>';
        $view = new View(dirname(__DIR__, 2) . '/resources/views');
        $router = new Router();
        $router->get('/acceptance/render/{username}', static function () use ($view, $attack): Response {
            $body = $view->render('profile/public', [
                'data' => ['profile' => [
                    'display_name' => $attack,
                    'username' => $attack,
                    'bio' => $attack,
                    'membership_name' => $attack,
                ]],
                'esc' => static fn (mixed $value): string => e((string) $value),
                'url' => static fn (): string => '#',
            ], null);
            return new Response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        });
        $application = $this->application($router, $this->sessions());

        $response = $application->handle($this->request('GET', '/acceptance/render/hostile'));

        $this->assertSame(200, $response->status);
        $this->assertTrue(str_contains($response->body, '&lt;script&gt;alert(1)&lt;/script&gt;'));
        $this->assertTrue(str_contains($response->body, '&lt;img src=x onerror=alert(2)&gt;'));
        $this->assertFalse(str_contains($response->body, '<script'));
        $this->assertFalse(str_contains($response->body, '<img src=x'));
        $this->assertFalse(str_contains($response->body, $attack));
    }

    public function testForgedSquareWebhookIsRejectedByTheRealEndpointFlow(): void
    {
        $this->config->set('payments.square.signature_key', 'acceptance-signature-key');
        $this->config->set('payments.square.webhook_url', 'https://example.test/api/webhooks/square');
        $gate = new PaymentGate($this->config, $this->database);
        $providers = new PaymentProviderFactory($this->config, $gate, new HttpClient(), $this->database);
        $lifecycle = new SubscriptionLifecycleService($this->database, new EntitlementService($this->database), 7);
        $processor = new WebhookProcessor(
            $this->database,
            $providers,
            $lifecycle,
            new PaymentAuditService($this->database, self::KEY),
            $this->config,
        );
        $controller = new WebhookController($processor);
        $router = new Router();
        $router->post('/api/webhooks/square', [$controller, 'square'], [
            new RateLimitMiddleware(new RateLimiter($this->database, self::KEY), 'webhook', 300, 60),
        ]);
        $application = $this->application($router, $this->sessions());
        $body = '{"event_id":"forged-event","type":"payment.updated","data":{"object":{"payment":{"id":"payment-1","status":"COMPLETED"}}}}';

        $response = $application->handle($this->request('POST', '/api/webhooks/square', [], [
            'x-square-hmacsha256-signature' => base64_encode(str_repeat('forged', 6)),
        ], $body));
        $payload = $this->json($response);

        $this->assertSame(401, $response->status);
        $this->assertSame(false, $payload['accepted'] ?? null);
        $this->assertSame('invalid_signature', $payload['status'] ?? null);
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) AS count FROM webhook_events')['count']);
    }

    public function testHtaccessContractsDenyIndexesSecretsAndPrivateTrees(): void
    {
        $root = dirname(__DIR__, 2);
        $projectRules = file_get_contents($root . '/.htaccess');
        $publicRules = file_get_contents($root . '/public/.htaccess');
        $this->assertTrue(is_string($projectRules) && is_string($publicRules));

        foreach ([$projectRules, $publicRules] as $rules) {
            $this->assertTrue(preg_match('/Options\s+-Indexes/i', $rules) === 1, 'Directory indexing is not disabled.');
        }
        foreach (['app', 'config', 'database', 'resources', 'storage', 'tests', 'vendor'] as $directory) {
            $this->assertTrue(str_contains($projectRules, $directory), 'Project-root rules omit private directory ' . $directory . '.');
            $this->assertTrue(str_contains($publicRules, $directory), 'Public rules omit private directory ' . $directory . '.');
        }
        $projectFilesMatch = $this->filesMatchDenyPatterns($projectRules);
        foreach (['.env', '.env.example', '.gitignore', 'composer.json', 'composer.lock', 'dump.sql', 'site.sqlite', 'archive.zip'] as $artifact) {
            $this->assertTrue(
                $this->matchesAny($projectFilesMatch, $artifact),
                'Project-root FilesMatch does not deny ' . $artifact . '.',
            );
        }
        $publicRewriteDenials = $this->rewriteDenyPatterns($publicRules);
        foreach (['.env', '.env.production', 'composer.lock', 'dump.sql', 'site.sqlite', 'storage/private.txt', 'vendor/autoload.php'] as $artifact) {
            $this->assertTrue(
                $this->matchesAny($publicRewriteDenials, $artifact),
                'Public rewrite rules do not deny ' . $artifact . '.',
            );
        }
        $this->assertTrue(str_contains($projectRules, 'Require all denied'));
        $this->assertTrue(str_contains($projectRules, '- [F,L,NC]'));
        $this->assertTrue(str_contains($publicRules, 'Require all denied'));
        $this->assertTrue(str_contains($publicRules, '- [F,L,NC]'));
        $this->assertTrue(str_contains($publicRules, 'Header unset X-Powered-By'));
        $this->assertTrue(str_contains($publicRules, '(?!well-known(?:/|$))'), 'ACME exception must remain narrowly scoped to .well-known.');
        $this->assertFalse(
            $this->matchesAny($publicRewriteDenials, '.well-known/acme-challenge/example-token'),
            'The explicit ACME challenge exception is shadowed by a deny rule.',
        );
    }

    private function sessions(): SessionManager
    {
        return new SessionManager($this->database, $this->config, new IpHasher(self::KEY));
    }

    private function auth(SessionManager $sessions): AuthService
    {
        $users = new UserRepository($this->database);
        $ipHasher = new IpHasher(self::KEY);
        return new AuthService(
            $this->database,
            $users,
            new PasswordHasher(),
            $sessions,
            new TwoFactorService($this->database, new Encryptor(self::KEY), new Totp()),
            new RateLimiter($this->database, self::KEY),
            $ipHasher,
            self::KEY,
        );
    }

    private function application(Router $router, SessionManager $sessions, ?AuthService $auth = null): Application
    {
        $container = new Container();
        $container->singleton(\App\Database\Database::class, $this->database);
        $container->singleton(SessionManager::class, $sessions);
        if ($auth !== null) {
            $container->singleton(AuthService::class, $auth);
        }
        $exceptions = new ExceptionHandler(
            new Logger(sys_get_temp_dir(), 'critical', 1, new SecretRedactor()),
            $this->config,
        );
        return new Application(
            $container,
            $router,
            $exceptions,
            new SecurityHeadersMiddleware($this->config),
        );
    }

    /** @param array<string,mixed> $body @param array<string,string> $headers */
    private function request(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
        string $rawBody = '',
    ): Request {
        $headers = array_change_key_case(['accept' => 'application/json'] + $headers, CASE_LOWER);
        return new Request(
            $method,
            $uri,
            [],
            $body,
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => '80'],
            $headers,
            $rawBody,
        );
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        $this->assertTrue(is_array($decoded), 'Expected a JSON response, got: ' . substr($response->body, 0, 120));
        return $decoded;
    }

    /** @return list<string> */
    private function filesMatchDenyPatterns(string $rules): array
    {
        preg_match_all('/<FilesMatch\s+"([^"]+)">\s*.*?Require\s+all\s+denied\s*.*?<\/FilesMatch>/is', $rules, $matches);
        return array_values(array_filter($matches[1] ?? [], 'is_string'));
    }

    /** @return list<string> */
    private function rewriteDenyPatterns(string $rules): array
    {
        preg_match_all('/RewriteRule\s+(?:"([^"]+)"|(\S+))\s+-\s+\[F,L(?:,NC)?\]/i', $rules, $matches, PREG_SET_ORDER);
        $patterns = [];
        foreach ($matches as $match) {
            $pattern = (string) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }
        return $patterns;
    }

    /** @param list<string> $patterns */
    private function matchesAny(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match('#' . str_replace('#', '\\#', $pattern) . '#i', $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
