<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * Authoritative catalog for account-selectable visual themes and profile cosmetics.
 * Accessibility themes remain free; paid benefits never affect game rules or odds.
 */
final class ThemeAccessService
{
    /** @var array<string,array{label:string,entitlement:?string}> */
    private const THEMES = [
        'purple-parlor' => ['label' => 'Purple Parlor', 'entitlement' => null],
        'midnight-lavender' => ['label' => 'Midnight Lavender', 'entitlement' => null],
        'cozy-fireplace' => ['label' => 'Cozy Fireplace', 'entitlement' => 'theme.fireplace'],
        'royal-plum' => ['label' => 'Royal Plum', 'entitlement' => 'theme.purple_premium'],
        'soft-daylight' => ['label' => 'Soft Daylight', 'entitlement' => null],
        'high-contrast' => ['label' => 'High Contrast', 'entitlement' => null],
    ];

    /** @var array<string,string> */
    private const COSMETICS = [
        'royal_frame' => 'profile.royal_frame',
        'animated_crown' => 'profile.animated_crown',
        'supporter_badge' => 'supporter.badge',
        'founder_badge' => 'founder.badge',
    ];

    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    /** @return list<array{slug:string,label:string,available:bool,required_entitlement:?string}> */
    public function catalog(?int $userId): array
    {
        $catalog = [];
        foreach (self::THEMES as $slug => $theme) {
            $required = $theme['entitlement'];
            $catalog[] = [
                'slug' => $slug,
                'label' => $theme['label'],
                'available' => $required === null || ($userId !== null && $this->entitlements->hasEntitlement($userId, $required)),
                'required_entitlement' => $required,
            ];
        }
        return $catalog;
    }

    /** @return list<string> */
    public function availableSlugs(?int $userId): array
    {
        return array_values(array_map(
            static fn (array $theme): string => $theme['slug'],
            array_filter($this->catalog($userId), static fn (array $theme): bool => $theme['available']),
        ));
    }

    public function canSelect(?int $userId, string $slug): bool
    {
        if (!isset(self::THEMES[$slug])) {
            return false;
        }
        $required = self::THEMES[$slug]['entitlement'];
        return $required === null || ($userId !== null && $this->entitlements->hasEntitlement($userId, $required));
    }

    public function assertCanSelect(int $userId, string $slug): void
    {
        if (!isset(self::THEMES[$slug])) {
            throw new DomainException('Select an installed theme.');
        }
        if (!$this->canSelect($userId, $slug)) {
            throw new DomainException('That theme is locked. An active membership or permanent theme entitlement is required.');
        }
    }

    /** @return array<string,bool> */
    public function cosmetics(?int $userId): array
    {
        $result = [];
        foreach (self::COSMETICS as $name => $entitlement) {
            $result[$name] = $userId !== null && $this->entitlements->hasEntitlement($userId, $entitlement);
        }
        return $result;
    }
}
