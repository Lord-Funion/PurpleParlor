<?php

declare(strict_types=1);

namespace PurpleParlor\Games\DTO;

use InvalidArgumentException;

final class GameRequest
{
    /**
     * Server state must be loaded from the round store, never accepted directly
     * from an untrusted request body.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $serverState
     */
    public function __construct(
        public readonly string $action,
        public readonly int $wager,
        public readonly array $options,
        public readonly string $idempotencyKey,
        public readonly string $clientSeed = 'parlor-guest',
        public readonly ?string $roundId = null,
        public readonly array $serverState = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $action)) {
            throw new InvalidArgumentException('Invalid game action.');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }
        if (strlen($clientSeed) < 1 || strlen($clientSeed) > 128) {
            throw new InvalidArgumentException('Client seed must contain 1 to 128 characters.');
        }
    }

    /**
     * Builds a request from validated HTTP data. $serverState is supplied by the
     * application round repository, not the browser.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $serverState
     */
    public static function fromArray(array $data, array $serverState = []): self
    {
        $wager = $data['wager'] ?? null;
        if (is_string($wager) && preg_match('/^\d+$/', $wager)) {
            $wager = (int) $wager;
        }
        if (!is_int($wager) || $wager < 0) {
            throw new InvalidArgumentException('Wager must be a non-negative whole number.');
        }

        $options = $data['options'] ?? [];
        if (!is_array($options)) {
            throw new InvalidArgumentException('Options must be an object.');
        }

        return new self(
            (string) ($data['action'] ?? 'play'),
            $wager,
            $options,
            (string) ($data['idempotency_key'] ?? ''),
            (string) ($data['client_seed'] ?? 'parlor-guest'),
            isset($data['round_id']) ? (string) $data['round_id'] : null,
            $serverState,
        );
    }
}
