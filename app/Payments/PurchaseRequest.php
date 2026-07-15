<?php

declare(strict_types=1);

namespace App\Payments;

use DomainException;

final readonly class PurchaseRequest
{
    public function __construct(
        public int $userId,
        public string $productKey,
        public int $amountCents,
        public string $currency,
        public string $idempotencyKey,
        public string $returnUrl,
        public string $cancelUrl,
        public array $metadata = [],
        public ?string $paymentSourceToken = null,
    ) {
        if ($userId <= 0 || $amountCents <= 0 || $amountCents > 100_000_000) {
            throw new DomainException('Invalid fixed-price purchase request.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency) || !preg_match('/^[A-Za-z0-9._:-]{8,45}$/', $idempotencyKey)) {
            throw new DomainException('Invalid purchase currency or idempotency key.');
        }
    }
}
