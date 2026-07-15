<?php

declare(strict_types=1);

namespace Tests\Admin;

use App\Controllers\AdminController;
use App\Core\Request;
use App\Http\View;
use DomainException;
use ReflectionClass;
use Tests\Support\TestCase;

final class AdminControllerTest extends TestCase
{
    public function testNestedAdminLayoutReceivesHelpersBeforeRenderingContent(): void
    {
        $view = new View(dirname(__DIR__, 2) . '/resources/views');
        $html = $view->render('admin/commerce-activation', [
            'app' => ['name' => 'The Purple Parlor', 'creator_name' => 'Lord Funion', 'url' => 'https://example.test'],
            'page' => ['title' => 'Live payment activation', 'private' => true],
            'user' => ['username' => 'Owner', 'display_name' => 'Owner', 'is_admin' => true, 'preferences' => []],
            'data' => [
                'activation_lock' => true,
                'can_unlock' => false,
                'environment_guards' => ['Payments enabled' => false],
            ],
        ], 'layouts/admin');

        $this->assertTrue(str_contains($html, 'Live payment activation'));
        $this->assertTrue(str_contains($html, '/admin/payments/activation-lock'));
        $this->assertFalse(str_contains($html, 'Value of type null is not callable'));
    }

    public function testRequiredAdminRouteContractsExist(): void
    {
        $manifest = require dirname(__DIR__, 2) . '/config/routes.php';
        $names = array_column($manifest, 2);
        foreach ([
            'admin.audit.export', 'admin.cache.clear', 'admin.commerce.activation',
            'admin.payments.lock.redirect',
            'admin.health.run', 'admin.themes.preview', 'admin.themes.update',
            'admin.users.status', 'admin.users.roles', 'admin.roles.permissions',
            'admin.games.update', 'admin.achievements.update', 'admin.missions.update', 'admin.rewards.daily',
            'admin.catalog.update', 'admin.entitlements.update', 'admin.refunds.track', 'admin.refunds.execute',
            'admin.monetization.update', 'admin.requests.status', 'admin.workflows.update',
            'admin.managed-content.preview', 'admin.managed-content.draft', 'admin.managed-content.approve',
            'admin.managed-content.publish', 'admin.managed-content.rollback',
        ] as $name) {
            $this->assertTrue(in_array($name, $names, true), 'Missing route contract: ' . $name);
        }
    }

    public function testManagedContentWritesRequireCsrfAndTwoFactor(): void
    {
        $manifest = require dirname(__DIR__, 2) . '/config/routes.php';
        foreach ($manifest as $route) {
            if (!str_starts_with((string) ($route[2] ?? ''), 'admin.managed-content.') || ($route[0] ?? '') === 'GET') {
                continue;
            }
            $middleware = $route[4] ?? [];
            $this->assertTrue(in_array('auth', $middleware, true));
            $this->assertTrue(in_array('2fa', $middleware, true));
            $this->assertTrue(in_array('csrf', $middleware, true));
        }
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AdminController.php');
        $this->assertTrue(str_contains((string) $source, '$ownerRequired = $type === \'legal\''));
        $this->assertTrue(str_contains((string) $source, "handleAdminMutation(\$request, '/admin/content', 'legal.manage', true"));
    }

    public function testThemeTokensRequireSafeHexAndAccessibleContrast(): void
    {
        $reflection = new ReflectionClass(AdminController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $validator = $reflection->getMethod('validatedTheme');
        $valid = [
            'name' => 'Purple Parlor', 'slug' => 'purple-parlor',
            'page' => '#160c1f', 'page_deep' => '#0e0815', 'panel' => '#281432',
            'panel_raised' => '#351b40', 'purple' => '#8e51b2', 'lavender' => '#d9b8ef',
            'gold' => '#e8b85d', 'cream' => '#fff3d2', 'ink' => '#fff9e8',
        ];
        $result = $validator->invoke($controller, new Request('POST', '/', [], $valid), 'purple-parlor');
        $this->assertSame('#fff9e8', $result['ink']);

        $unsafe = $valid;
        $unsafe['ink'] = '#160c1f';
        $this->expectException(DomainException::class, static fn () => $validator->invoke(
            $controller,
            new Request('POST', '/', [], $unsafe),
            'purple-parlor',
        ));
    }

    public function testUnexpectedRuntimeFailuresAreNeverFlashedVerbatim(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AdminController.php');
        $this->assertTrue(is_string($source));
        $this->assertFalse(str_contains($source, 'catch (DomainException|RuntimeException'));
        $this->assertTrue(str_contains($source, 'catch (DomainException $exception)'));
        $this->assertTrue(str_contains($source, 'catch (Throwable)'));
    }
}
