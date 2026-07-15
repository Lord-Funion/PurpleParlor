<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AdvertisingService;
use App\Services\EntitlementService;
use App\Services\ThemeAccessService;
use DomainException;
use Tests\Support\TestCase;

final class EntitlementAdvertisingTest extends TestCase
{
    public function testSeededPlacementsAreFixedAndDisabledByDefault(): void
    {
        $rows = $this->database->fetchAll('SELECT slot_key, provider, enabled, configuration_json FROM advertising_slots ORDER BY slot_key');
        $this->assertSame(2, count($rows));
        $this->assertSame(['game_lobby_footer', 'home_lounge_footer'], array_column($rows, 'slot_key'));
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row['enabled']);
            $this->assertSame('placeholder', $row['provider']);
            $configuration = json_decode((string) $row['configuration_json'], true);
            $this->assertSame(1, (int) ($configuration['frequency_cap_per_session'] ?? 0));
        }
    }

    public function testAdvertisementRequiresEveryGateAndAPlacementSafeRoute(): void
    {
        $_SESSION = [];
        $entitlements = new EntitlementService($this->database);
        $service = new AdvertisingService($this->database, $this->config, $entitlements);
        $consentedHome = new Request('GET', '/', [], [], ['purple_parlor_consent' => 'analytics']);

        $this->assertSame(null, $service->placementFor($consentedHome, null), 'Global advertising must default off.');
        $this->config->set('advertising.enabled', true);
        $this->config->set('advertising.provider', 'placeholder');
        $this->assertSame(null, $service->placementFor($consentedHome, null), 'A disabled database slot must not render.');

        $this->database->execute("UPDATE advertising_slots SET enabled = 1 WHERE slot_key = 'home_lounge_footer'");
        $this->assertSame(null, $service->placementFor(new Request('GET', '/'), null), 'Missing consent must suppress advertising.');
        $this->assertSame(null, $service->placementFor(new Request('GET', '/', [], [], ['purple_parlor_consent' => 'essential']), null), 'Necessary-only consent must suppress advertising.');
        foreach (['/login', '/billing', '/billing/checkout', '/games/plinko', '/api/games/plinko/action'] as $unsafePath) {
            $this->assertSame(null, $service->placementFor(new Request('GET', $unsafePath, [], [], ['purple_parlor_consent' => 'analytics']), null), 'Advertising leaked onto a protected or interactive path.');
        }

        $placement = $service->placementFor($consentedHome, null);
        $this->assertTrue(is_array($placement));
        $this->assertSame('home_lounge_footer', $placement['slot_key']);
        $this->assertSame(null, $service->placementFor($consentedHome, null), 'The per-session frequency cap must suppress a repeat impression.');
    }

    public function testAdFreeEntitlementSuppressesAnOtherwiseEligiblePlacement(): void
    {
        $_SESSION = [];
        $this->config->set('advertising.enabled', true);
        $this->config->set('advertising.provider', 'placeholder');
        $this->database->execute("UPDATE advertising_slots SET enabled = 1 WHERE slot_key = 'home_lounge_footer'");
        $user = (new UserRepository($this->database))->create('ad-free@example.test', 'AdFreeMember', (new PasswordHasher())->hash('a sufficiently long test password!'), 'active');
        $entitlements = new EntitlementService($this->database);
        $entitlements->grant($user->id, 'ads.disabled', 'test', 'ad-free-suite');
        $service = new AdvertisingService($this->database, $this->config, $entitlements);

        $placement = $service->placementFor(new Request('GET', '/', [], [], ['purple_parlor_consent' => 'analytics']), $user->id);
        $this->assertSame(null, $placement);
        $this->assertSame(null, $_SESSION['_advertising_impressions']['home_lounge_footer'] ?? null, 'A suppressed ad must not consume an impression.');
    }

    public function testPremiumThemeRequiresAnActiveServerEntitlement(): void
    {
        $user = (new UserRepository($this->database))->create('theme-owner@example.test', 'ThemeOwner', (new PasswordHasher())->hash('a sufficiently long test password!'), 'active');
        $entitlements = new EntitlementService($this->database);
        $themes = new ThemeAccessService($entitlements);

        $this->assertTrue($themes->canSelect($user->id, 'purple-parlor'));
        $this->assertFalse($themes->canSelect($user->id, 'royal-plum'));
        $this->expectException(DomainException::class, fn () => $themes->assertCanSelect($user->id, 'royal-plum'));

        $entitlements->grant($user->id, 'theme.purple_premium', 'test', 'theme-suite');
        $this->assertTrue($themes->canSelect($user->id, 'royal-plum'));
        $themes->assertCanSelect($user->id, 'royal-plum');
    }
}
