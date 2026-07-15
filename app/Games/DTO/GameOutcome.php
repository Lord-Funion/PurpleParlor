<?php

declare(strict_types=1);

namespace PurpleParlor\Games\DTO;

final class GameOutcome implements \JsonSerializable
{
    /**
     * $serverState can contain hidden cards, mines, or future draws and must be
     * persisted by the server only. jsonSerialize intentionally excludes it.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $display
     * @param list<string> $nextActions
     * @param array<string, mixed> $serverState
     * @param array<string, mixed> $publicState
     * @param array<string, mixed> $fairness
     */
    public function __construct(
        public readonly string $code,
        public readonly array $result,
        public readonly array $display = [],
        public readonly array $nextActions = [],
        private readonly array $serverState = [],
        public readonly array $publicState = [],
        public readonly bool $complete = true,
        public readonly int $payout = 0,
        public readonly array $fairness = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function serverState(): array
    {
        return $this->serverState;
    }

    public function withPayout(int $payout): self
    {
        return new self(
            $this->code,
            $this->result,
            $this->display,
            $this->nextActions,
            $this->serverState,
            $this->publicState,
            $this->complete,
            $payout,
            $this->fairness,
        );
    }

    /** @param array<string, mixed> $serverState */
    public function withServerState(array $serverState): self
    {
        return new self(
            $this->code,
            $this->result,
            $this->display,
            $this->nextActions,
            $serverState,
            $this->publicState,
            $this->complete,
            $this->payout,
            $this->fairness,
        );
    }

    /** @param array<string, mixed> $fairness */
    public function withFairness(array $fairness): self
    {
        return new self(
            $this->code,
            $this->result,
            $this->display,
            $this->nextActions,
            $this->serverState,
            $this->publicState,
            $this->complete,
            $this->payout,
            $fairness,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'outcome' => $this->code,
            'result' => $this->result,
            'payout' => $this->payout,
            'display' => $this->display,
            'nextActions' => $this->nextActions,
            'state' => $this->publicState,
            'complete' => $this->complete,
            'fairness' => $this->fairness,
        ];
    }
}
