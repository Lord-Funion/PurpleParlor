<?php

declare(strict_types=1);

return [
    // Fixed, once-per-UTC-day play-money grants. Values are deliberately
    // bounded again inside MembershipBonusService before any ledger write.
    'daily_coin_bonuses' => [
        'cozy_club' => env_int('COZY_CLUB_DAILY_COINS', 1000),
        'cozy_club_plus' => env_int('COZY_CLUB_PLUS_DAILY_COINS', 2500),
    ],
];
