<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Contracts;

use PurpleParlor\Games\DTO\GameOutcome;
use PurpleParlor\Games\DTO\GameRequest;
use PurpleParlor\Games\DTO\GameRound;
use PurpleParlor\Games\DTO\PlayerContext;
use PurpleParlor\Games\DTO\ValidationResult;

interface GameInterface
{
    public function getSlug(): string;

    /** @return array<string, mixed> */
    public function getConfiguration(): array;

    public function validateWager(GameRequest $request): ValidationResult;

    public function createRound(GameRequest $request, PlayerContext $player): GameRound;

    public function calculateOutcome(GameRound $round): GameOutcome;

    public function calculatePayout(GameRound $round, GameOutcome $outcome): int;

    /** @return array<string, mixed> */
    public function getPublicRules(): array;

    /** @return array<string, mixed> */
    public function getProbabilityDisclosure(): array;
}
