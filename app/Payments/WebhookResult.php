<?php

declare(strict_types=1);

namespace App\Payments;

final readonly class WebhookResult
{
    public function __construct(
        public bool $accepted,
        public bool $duplicate,
        public string $status,
        public ?string $eventId = null,
    ) {
    }
}
