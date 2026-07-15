<?php

declare(strict_types=1);

namespace PurpleParlor\Games\DTO;

final class GameRound
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $serverState
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $action,
        public readonly int $wager,
        public readonly array $input,
        public readonly array $serverState,
        public readonly PlayerContext $player,
        public readonly string $clientSeed,
        public readonly string $idempotencyKey,
        public readonly string $createdAt,
    ) {
    }
}
