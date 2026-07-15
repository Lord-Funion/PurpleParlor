<?php

declare(strict_types=1);

namespace App\Models;

final readonly class LedgerEntry
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $currency,
        public int $amount,
        public int $balanceBefore,
        public int $balanceAfter,
        public string $reasonCode,
        public string $idempotencyKey,
        public string $entryHash,
        public string $createdAt,
    ) {
    }
}
