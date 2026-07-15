<?php

declare(strict_types=1);

namespace PurpleParlor\Games;

use PurpleParlor\Games\Contracts\GameInterface;
use PurpleParlor\Games\Contracts\RandomSource;
use PurpleParlor\Games\DTO\GameOutcome;
use PurpleParlor\Games\DTO\GameRequest;
use PurpleParlor\Games\DTO\GameRound;
use PurpleParlor\Games\DTO\PlayerContext;
use PurpleParlor\Games\DTO\ValidationResult;
use PurpleParlor\Games\Exceptions\GameException;
use PurpleParlor\Games\Strategy\StrategyRouter;

final class CatalogGame implements GameInterface
{
    private StrategyRouter $strategies;

    /** @param array<string, mixed> $configuration */
    public function __construct(private readonly array $configuration, RandomSource $random)
    {
        foreach (['slug', 'name', 'category', 'wager', 'rules', 'paytable', 'probability', 'theoretical_rtp', 'engine'] as $required) {
            if (!array_key_exists($required, $configuration)) {
                throw new \InvalidArgumentException("Game configuration is missing {$required}.");
            }
        }
        $this->strategies = new StrategyRouter($random);
    }

    public function getSlug(): string { return (string) $this->configuration['slug']; }

    public function getConfiguration(): array { return $this->configuration; }

    public function validateWager(GameRequest $request): ValidationResult
    {
        $wager = $this->configuration['wager'];
        $minimum = (int) $wager['minimum']; $maximum = (int) $wager['maximum']; $increment = (int) $wager['increment'];
        $errors = [];
        if ($request->wager < $minimum || $request->wager > $maximum) {
            $errors[] = ['field' => 'wager', 'code' => 'wager_out_of_range', 'message' => "Wager must be between {$minimum} and {$maximum} Cozy Coins."];
        } elseif ($increment > 0 && ($request->wager - $minimum) % $increment !== 0) {
            $errors[] = ['field' => 'wager', 'code' => 'invalid_increment', 'message' => "Wager must use increments of {$increment} Cozy Coins."];
        }
        if ($request->serverState !== []) {
            if ($request->roundId === null) {
                $errors[] = ['field' => 'round_id', 'code' => 'round_required', 'message' => 'A stored round ID is required for game actions.'];
            }
            if (isset($request->serverState['wager']) && $request->serverState['wager'] !== $request->wager) {
                $errors[] = ['field' => 'wager', 'code' => 'wager_changed', 'message' => 'The wager cannot change during a round.'];
            }
        }

        return ValidationResult::fromErrors($errors);
    }

    public function createRound(GameRequest $request, PlayerContext $player): GameRound
    {
        $validation = $this->validateWager($request);
        if (!$validation->isValid()) {
            throw new GameException('The wager or round request is invalid.', 'validation_failed', 422, ['errors' => $validation->errors()]);
        }
        $isNew = $request->serverState === [];
        if ($isNew && $request->wager > $player->availableBalance) {
            throw new GameException('The fictional balance is too low for this wager.', 'insufficient_virtual_balance', 409);
        }
        $roundId = $isNew ? 'rnd_' . bin2hex(random_bytes(16)) : (string) $request->roundId;

        return new GameRound(
            $roundId,
            $this->getSlug(),
            $request->action,
            $request->wager,
            $request->options,
            $request->serverState,
            $player,
            $request->clientSeed,
            $request->idempotencyKey,
            gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function calculateOutcome(GameRound $round): GameOutcome
    {
        if ($round->slug !== $this->getSlug()) {
            throw new GameException('Round was sent to the wrong game module.', 'state_conflict', 409);
        }
        return $this->strategies->resolve($round, $this->configuration);
    }

    public function calculatePayout(GameRound $round, GameOutcome $outcome): int
    {
        if (!$outcome->complete) return 0;
        $basisPoints = $outcome->result['payoutMultiplierBps'] ?? null;
        if (!is_int($basisPoints) || $basisPoints < 0 || $basisPoints > 500000000) {
            throw new GameException('Game returned an invalid payout multiplier.', 'invalid_payout', 500);
        }
        // Decompose before multiplying so the configured jackpot ceiling also
        // works on unusual 32-bit PHP builds without an intermediate overflow.
        $whole = intdiv($basisPoints, 10000) * $round->wager;
        $fraction = intdiv(($basisPoints % 10000) * $round->wager, 10000);
        return $whole + $fraction;
    }

    public function getPublicRules(): array
    {
        return [
            'summary' => $this->configuration['description'],
            'rules' => $this->configuration['rules'],
            'tutorial' => $this->configuration['tutorial'],
            'paytable' => $this->configuration['paytable'],
            'wager' => $this->configuration['wager'],
            'responsiblePlay' => 'Fictional play only. Take breaks whenever play stops feeling fun.',
            'currencyNotice' => 'Virtual currency has no cash value and cannot be purchased, transferred, redeemed, withdrawn, or exchanged for anything of value.',
        ];
    }

    public function getProbabilityDisclosure(): array
    {
        return [
            'probability' => $this->configuration['probability'],
            'theoreticalRtp' => $this->configuration['theoretical_rtp'],
            'method' => $this->configuration['rtp_method'] ?? 'Mathematical model and deterministic simulation validation',
            'notice' => 'Short sessions can vary greatly from the theoretical return. Reproducible checks do not prove perfect randomness.',
        ];
    }
}
