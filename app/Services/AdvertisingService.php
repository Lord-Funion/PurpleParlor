<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Request;
use App\Database\Database;

/**
 * Fail-closed advertising gate. It renders structured, escaped creative data only;
 * provider scripts and arbitrary administrator HTML are deliberately unsupported.
 */
final class AdvertisingService
{
    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly EntitlementService $entitlements,
    ) {
    }

    /** @return array<string,string|int>|null */
    public function placementFor(Request $request, ?int $userId): ?array
    {
        if ($this->config->get('advertising.enabled', false) !== true
            || $request->method !== 'GET'
            || ($request->cookies['purple_parlor_consent'] ?? '') !== 'analytics'
            || ($userId !== null && $this->entitlements->hasEntitlement($userId, 'ads.disabled'))) {
            return null;
        }

        $slotKey = $this->safeSlotForPath($request->uri);
        if ($slotKey === null) {
            return null;
        }
        $row = $this->database->fetchOne(
            'SELECT slot_key, provider, configuration_json FROM advertising_slots WHERE slot_key = :slot AND enabled = 1 LIMIT 1',
            ['slot' => $slotKey],
        );
        $configuredProvider = strtolower(trim((string) $this->config->get('advertising.provider', 'placeholder')));
        if ($row === null || $configuredProvider === '' || !hash_equals($configuredProvider, strtolower((string) $row['provider']))) {
            return null;
        }

        $creative = json_decode((string) ($row['configuration_json'] ?? ''), true);
        if (!is_array($creative)) {
            return null;
        }
        $headline = $this->plainText($creative['headline'] ?? null, 100);
        $body = $this->plainText($creative['body'] ?? null, 240);
        $cta = $this->plainText($creative['cta_label'] ?? null, 40);
        $destination = $this->safeDestination($creative['destination_url'] ?? null);
        if ($headline === null || $body === null || $cta === null || $destination === null) {
            return null;
        }

        $defaultCap = max(1, min(3, (int) $this->config->get('advertising.default_frequency_cap', 1)));
        $frequencyCap = max(1, min(3, (int) ($creative['frequency_cap_per_session'] ?? $defaultCap)));
        $impressions = isset($_SESSION['_advertising_impressions']) && is_array($_SESSION['_advertising_impressions'])
            ? $_SESSION['_advertising_impressions']
            : [];
        $seen = max(0, (int) ($impressions[$slotKey] ?? 0));
        if ($seen >= $frequencyCap) {
            return null;
        }
        $impressions[$slotKey] = $seen + 1;
        $_SESSION['_advertising_impressions'] = $impressions;

        return [
            'slot_key' => $slotKey,
            'provider' => $configuredProvider,
            'headline' => $headline,
            'body' => $body,
            'cta_label' => $cta,
            'destination_url' => $destination,
            'frequency_cap' => $frequencyCap,
        ];
    }

    private function safeSlotForPath(string $uri): ?string
    {
        $path = rtrim((string) (parse_url($uri, PHP_URL_PATH) ?: '/'), '/') ?: '/';
        if ($path === '/') {
            return 'home_lounge_footer';
        }
        if ($path === '/games' || $path === '/games/all' || $path === '/games/recent'
            || $path === '/games/search' || str_starts_with($path, '/games/category/')) {
            return 'game_lobby_footer';
        }
        // No placements are permitted on authentication, account, billing,
        // checkout, administration, or individual game/action pages.
        return null;
    }

    private function plainText(mixed $value, int $maximum): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return null;
        }
        return $value;
    }

    private function safeDestination(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1) {
            return null;
        }
        $value = trim($value);
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            $parts = parse_url($value);
            return $parts !== false && !isset($parts['scheme'], $parts['host']) ? $value : null;
        }
        $parts = parse_url($value);
        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && trim((string) ($parts['host'] ?? '')) !== ''
            ? $value
            : null;
    }
}
