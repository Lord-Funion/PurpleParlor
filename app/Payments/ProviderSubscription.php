<?php

declare(strict_types=1);

namespace App\Payments;

final readonly class ProviderSubscription
{
    public function __construct(
        public string $externalId,
        public string $status,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public bool $cancelAtPeriodEnd = false,
        public array $providerData = [],
    ) {
    }
}
