<?php

declare(strict_types=1);

namespace PurpleParlor\Games\DTO;

final class PlayerContext
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $playerId,
        public readonly int $availableBalance,
        public readonly bool $guest = false,
        public readonly string $locale = 'en-US',
        public readonly array $metadata = [],
    ) {
    }
}
