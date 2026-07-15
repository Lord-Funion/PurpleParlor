<?php

declare(strict_types=1);

namespace App\Payments;

use DomainException;

final readonly class PaymentEvent
{
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $type,
        public string $status,
        public ?string $externalPaymentId = null,
        public ?string $externalSubscriptionId = null,
        public ?string $externalRefundId = null,
        public ?string $externalDisputeId = null,
        public ?int $amountCents = null,
        public ?string $currency = null,
        public ?string $occurredAt = null,
        public array $data = [],
    ) {
        if ($provider === '' || $eventId === '' || $type === '') {
            throw new DomainException('Malformed payment event.');
        }
    }
}
