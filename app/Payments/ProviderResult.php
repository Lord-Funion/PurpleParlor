<?php

declare(strict_types=1);

namespace App\Payments;

final readonly class ProviderResult
{
    public function __construct(
        public bool $successful,
        public string $status,
        public ?string $externalId = null,
        public ?string $errorCode = null,
        public array $providerData = [],
    ) {
    }
}
