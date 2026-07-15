<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Strategy;

use PurpleParlor\Games\DTO\GameOutcome;
use PurpleParlor\Games\DTO\GameRound;

interface StrategyInterface
{
    /** @param array<string, mixed> $configuration */
    public function resolve(GameRound $round, array $configuration): GameOutcome;
}
