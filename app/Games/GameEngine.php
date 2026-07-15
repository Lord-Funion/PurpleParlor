<?php

declare(strict_types=1);

namespace PurpleParlor\Games;

use PurpleParlor\Games\DTO\EngineResult;
use PurpleParlor\Games\DTO\GameRequest;
use PurpleParlor\Games\DTO\PlayerContext;
use PurpleParlor\Games\Security\OutcomeSigner;

final class GameEngine
{
    /** @param array<string, mixed> $fairnessEnvelope */
    public function __construct(
        private readonly GameRegistry $registry,
        private readonly ?OutcomeSigner $signer = null,
        private readonly array $fairnessEnvelope = [],
    ) {}

    public function execute(string $slug, GameRequest $request, PlayerContext $player): EngineResult
    {
        $game = $this->registry->get($slug);
        $round = $game->createRound($request, $player);
        $outcome = $game->calculateOutcome($round);
        $payout = $game->calculatePayout($round, $outcome);
        $outcome = $outcome->withPayout($payout)->withFairness($this->fairnessEnvelope);
        $transition = $this->transition($round->serverState, $outcome->serverState(), $round->action, $round->id, $round->idempotencyKey);
        $serverState = $outcome->serverState();
        if (!$outcome->complete) {
            $serverState['_transition'] = $transition;
            $outcome = $outcome->withServerState($serverState);
        }
        $charged = $round->serverState === [];
        $payload = [
            'roundId' => $round->id,
            'slug' => $round->slug,
            'action' => $round->action,
            'wager' => $round->wager,
            'wagerCharged' => $charged,
            'payout' => $payout,
            'net' => $outcome->complete ? $payout - $round->wager : -$round->wager,
            'outcome' => $outcome->code,
            'result' => $outcome->result,
            'display' => $outcome->display,
            'nextActions' => $outcome->nextActions,
            'state' => $outcome->publicState,
            'complete' => $outcome->complete,
            'fairness' => $outcome->fairness,
            'transition' => $transition,
            'serverTime' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $signature = $this->signer?->sign($payload);

        return new EngineResult($payload, $serverState, $transition, $signature);
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return array<string,mixed> */
    private function transition(array $before, array $after, string $action, string $roundId, string $idempotencyKey): array
    {
        $revision = (int) (($before['_transition']['revision'] ?? 0)) + 1;
        // The transition envelope is metadata, not game state. Excluding it
        // makes this action's stateHash equal the next action's previous hash.
        unset($before['_transition'], $after['_transition']);
        $beforeHash = hash('sha256', json_encode($this->canonicalize($before), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $afterHash = hash('sha256', json_encode($this->canonicalize($after), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return [
            'revision' => $revision,
            'roundId' => $roundId,
            'action' => $action,
            'idempotencyKeyHash' => hash('sha256', $idempotencyKey),
            'previousStateHash' => $beforeHash,
            'stateHash' => $afterHash,
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = $this->canonicalize($child);
        return $value;
    }
}
