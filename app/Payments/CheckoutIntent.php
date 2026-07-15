<?php

declare(strict_types=1);

namespace App\Payments;

final readonly class CheckoutIntent
{
    public function __construct(
        public string $idempotencyKey,
        public string $status,
        public bool $reused,
    ) {
    }
}
