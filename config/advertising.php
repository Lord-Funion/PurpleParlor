<?php

declare(strict_types=1);

return [
    // Advertising is fail-closed and remains disabled in every fresh package.
    'enabled' => env_bool('ADS_ENABLED', false),
    'provider' => strtolower(trim((string) env('ADS_PROVIDER', 'placeholder'))),
    'client_id' => trim((string) env('ADSENSE_CLIENT_ID', '')),
    'default_frequency_cap' => 1,
];
