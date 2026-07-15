<?php

declare(strict_types=1);

$all = [
    'admin.access', 'users.view', 'users.manage', 'users.roles.manage', 'content.manage',
    'games.manage', 'virtual_currency.view', 'virtual_currency.adjust', 'missions.manage',
    'achievements.manage', 'support.manage', 'system.health.view', 'system.settings.manage',
    'payments.summary.view', 'payments.audit.view', 'payments.refund', 'payments.live.configure',
    'merchant.identity.manage', 'payout.settings.manage', 'admins.manage', 'monetization.disable',
    'audit.view', 'backups.manage', 'legal.manage', 'ads.manage', 'billing.self.manage',
    'profile.self.manage', 'games.play',
];

return [
    'permissions' => $all,
    'roles' => [
        'adult_owner' => $all,
        'developer_admin' => [
            'admin.access', 'users.view', 'users.manage', 'content.manage', 'games.manage',
            'virtual_currency.view', 'virtual_currency.adjust', 'missions.manage',
            'achievements.manage', 'support.manage', 'system.health.view', 'system.settings.manage',
            'payments.summary.view', 'audit.view', 'legal.manage', 'profile.self.manage', 'games.play',
        ],
        'support_admin' => ['admin.access', 'users.view', 'support.manage', 'payments.summary.view', 'profile.self.manage', 'games.play'],
        'content_manager' => ['admin.access', 'content.manage', 'games.manage', 'missions.manage', 'achievements.manage', 'profile.self.manage', 'games.play'],
        'moderator' => ['admin.access', 'users.view', 'support.manage', 'profile.self.manage', 'games.play'],
        'member' => ['billing.self.manage', 'profile.self.manage', 'games.play'],
        'guest' => ['games.play'],
    ],
    'adult_owner_only' => [
        'users.roles.manage', 'payments.audit.view', 'payments.refund', 'payments.live.configure',
        'merchant.identity.manage', 'payout.settings.manage', 'admins.manage',
        'monetization.disable', 'backups.manage',
    ],
];
