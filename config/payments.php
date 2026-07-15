<?php

declare(strict_types=1);

return [
    'enabled' => env_bool('PAYMENTS_ENABLED', false),
    'mode' => env('PAYMENT_MODE', 'sandbox'),
    'provider' => env('PAYMENT_PROVIDER', 'demo'),
    'adult_owner_confirmed' => env_bool('ADULT_OWNER_CONFIRMED', false),
    'live_activation_lock' => env_bool('LIVE_PAYMENT_ACTIVATION_LOCK', true),
    'currency' => strtoupper((string) env('PAYMENT_CURRENCY', 'USD')),
    'webhook_retention_days' => env_int('PAYMENT_WEBHOOK_RETENTION_DAYS', 90),
    'subscription_grace_days' => env_int('SUBSCRIPTION_GRACE_DAYS', 7),
    'paypal' => [
        'enabled' => env_bool('PAYPAL_ENABLED', false),
        'environment' => env('PAYPAL_ENVIRONMENT', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID', ''),
        'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID', ''),
        'product_id' => env('PAYPAL_PRODUCT_ID', ''),
        'plans' => [
            'cozy.monthly' => env('PAYPAL_COZY_MONTHLY_PLAN_ID', ''),
            'cozy.annual' => env('PAYPAL_COZY_ANNUAL_PLAN_ID', ''),
            'plus.monthly' => env('PAYPAL_PLUS_MONTHLY_PLAN_ID', ''),
            'plus.annual' => env('PAYPAL_PLUS_ANNUAL_PLAN_ID', ''),
        ],
    ],
    'square' => [
        'enabled' => env_bool('SQUARE_ENABLED', false),
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        'application_id' => env('SQUARE_APPLICATION_ID', ''),
        'access_token' => env('SQUARE_ACCESS_TOKEN', ''),
        'location_id' => env('SQUARE_LOCATION_ID', ''),
        'signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY', ''),
        'api_version' => env('SQUARE_API_VERSION', '2026-05-20'),
        'webhook_url' => env('SQUARE_WEBHOOK_URL', ''),
        'plans' => [
            'cozy.monthly' => env('SQUARE_COZY_MONTHLY_PLAN_VARIATION_ID', ''),
            'cozy.annual' => env('SQUARE_COZY_ANNUAL_PLAN_VARIATION_ID', ''),
            'plus.monthly' => env('SQUARE_PLUS_MONTHLY_PLAN_VARIATION_ID', ''),
            'plus.annual' => env('SQUARE_PLUS_ANNUAL_PLAN_VARIATION_ID', ''),
        ],
    ],
];
