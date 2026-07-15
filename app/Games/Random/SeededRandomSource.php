<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Random;

use InvalidArgumentException;
use PurpleParlor\Games\Contracts\RandomSource;

/**
 * Portable deterministic HMAC-SHA-256 stream for tests and simulations.
 * It is deliberately separate from the production CryptoRandomSource.
 */
final class SeededRandomSource implements RandomSource
{
    private int $counter = 0;
    private string $buffer = '';

    public function __construct(private readonly string $seed, private readonly string $domain = 'purple-parlor-simulation-v1')
    {
        if ($seed === '') {
            throw new InvalidArgumentException('Simulation seed cannot be empty.');
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

        // 31-bit rejection sampling avoids modulo bias and works identically on
        // 32-bit shared-hosting PHP builds.
        $limit = intdiv(0x80000000, $range) * $range;
        do {
            $bytes = $this->bytes(4);
            $value = unpack('N', $bytes)[1] & 0x7fffffff;
        } while ($value >= $limit);

        return $minimum + ($value % $range);
    }

    public function bytes(int $length): string
    {
        if ($length < 0 || $length > 1048576) {
            throw new InvalidArgumentException('Invalid byte count.');
        }

        while (strlen($this->buffer) < $length) {
            $message = $this->domain . '|' . str_pad((string) $this->counter++, 20, '0', STR_PAD_LEFT);
            $this->buffer .= hash_hmac('sha256', $message, $this->seed, true);
        }

        $result = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $result;
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
