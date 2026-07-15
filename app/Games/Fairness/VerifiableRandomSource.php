<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Fairness;

use InvalidArgumentException;
use PurpleParlor\Games\Contracts\RandomSource;

/**
 * Reproducible stream for committed rounds. The unrevealed server seed must be
 * stored server-side and rotated before it is ever revealed publicly.
 */
final class VerifiableRandomSource implements RandomSource
{
    private int $counter = 0;
    private string $buffer = '';

    public function __construct(
        private readonly string $serverSeed,
        private readonly string $clientSeed,
        private readonly int $nonce,
    ) {
        if ($serverSeed === '' || $clientSeed === '' || $nonce < 0) {
            throw new InvalidArgumentException('Invalid verification seed inputs.');
        }
    }

    public function int(int $minimum, int $maximum): int
    {
        if ($minimum > $maximum) {
            throw new InvalidArgumentException('Minimum cannot exceed maximum.');
        }
        $range = $maximum - $minimum + 1;
        if ($range === 1) {
            return $minimum;
        }
        $limit = intdiv(0x80000000, $range) * $range;
        do {
            $value = unpack('N', $this->bytes(4))[1] & 0x7fffffff;
        } while ($value >= $limit);

        return $minimum + ($value % $range);
    }

    public function bytes(int $length): string
    {
        if ($length < 0 || $length > 1048576) {
            throw new InvalidArgumentException('Invalid byte count.');
        }
        while (strlen($this->buffer) < $length) {
            $message = 'purple-parlor:v1|' . $this->clientSeed . '|' . $this->nonce . '|' . $this->counter++;
            $this->buffer .= hash_hmac('sha256', $message, $this->serverSeed, true);
        }
        $value = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $value;
    }

    public function shuffle(array $values): array
    {
        for ($index = count($values) - 1; $index > 0; --$index) {
            $swap = $this->int(0, $index);
            [$values[$index], $values[$swap]] = [$values[$swap], $values[$index]];
        }

        return array_values($values);
    }
}
