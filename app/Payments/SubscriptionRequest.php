<?php

declare(strict_types=1);

namespace App\Payments;

use DomainException;

final readonly class SubscriptionRequest
{
    public function __construct(
        public int $userId,
        public string $planKey,
        public string $providerPlanId,
        public string $billingPeriod,
        public int $amountCents,
        public string $currency,
        public string $idempotencyKey,
        public string $returnUrl,
        public string $cancelUrl,
        public array $metadata = [],
        public ?string $paymentSourceToken = null,
        public ?string $customerEmail = null,
    ) {
        if ($userId <= 0 || !in_array($billingPeriod, ['monthly', 'annual'], true) || $amountCents <= 0) {
            throw new DomainException('Invalid subscription request.');
        }
        if ($providerPlanId === '' || !preg_match('/^[A-Z]{3}$/', $currency) || !preg_match('/^[A-Za-z0-9._:-]{8,45}$/', $idempotencyKey)) {
            throw new DomainException('Invalid subscription provider mapping.');
        }
        if ($paymentSourceToken !== null && (strlen($paymentSourceToken) < 3 || strlen($paymentSourceToken) > 500 || preg_match('/[\x00-\x1F\x7F]/', $paymentSourceToken))) {
            throw new DomainException('Invalid subscription payment token.');
        }
        if ($customerEmail !== null && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('A valid customer email is required for this subscription.');
        }
    }
}
