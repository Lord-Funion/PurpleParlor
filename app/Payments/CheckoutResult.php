<?php

declare(strict_types=1);

namespace App\Payments;

final readonly class CheckoutResult
{
    public function __construct(
        public bool $successful,
        public string $status,
        public ?string $externalId = null,
        public ?string $approvalUrl = null,
        public ?string $errorCode = null,
        public array $providerData = [],
    ) {
    }
}
