<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Strategy;

use PurpleParlor\Games\Contracts\RandomSource;
use PurpleParlor\Games\DTO\GameOutcome;
use PurpleParlor\Games\DTO\GameRound;
use PurpleParlor\Games\Exceptions\GameException;

final class StrategyRouter
{
    private InstantGameStrategy $instant;
    private CardGameStrategy $card;
    private StateGameStrategy $stateful;
    private SolitaireGameStrategy $solitaire;

    public function __construct(RandomSource $random)
    {
        $this->instant = new InstantGameStrategy($random);
        $this->card = new CardGameStrategy($random);
        $this->stateful = new StateGameStrategy($random);
        $this->solitaire = new SolitaireGameStrategy($random);
    }

    /** @param array<string, mixed> $configuration */
    public function resolve(GameRound $round, array $configuration): GameOutcome
    {
        return match ($configuration['engine'] ?? '') {
            'instant' => $this->instant->resolve($round, $configuration),
            'card' => $this->card->resolve($round, $configuration),
            'stateful' => $this->stateful->resolve($round, $configuration),
            'solitaire' => $this->solitaire->resolve($round, $configuration),
            default => throw new GameException('Game engine configuration is invalid.', 'strategy_missing', 500),
        };
    }
}
