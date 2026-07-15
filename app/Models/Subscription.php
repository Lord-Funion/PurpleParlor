<?php

declare(strict_types=1);

namespace App\Models;

final readonly class Subscription
{
    public const STATUSES = ['pending', 'trialing', 'active', 'past_due', 'in_grace_period', 'paused', 'suspended', 'canceled', 'expired', 'refunded', 'disputed'];

    public function __construct(
        public int $id,
        public int $userId,
        public int $planId,
        public string $provider,
        public string $externalId,
        public string $status,
        public ?string $periodEnd,
        public ?string $graceEndsAt,
    ) {
    }
}
