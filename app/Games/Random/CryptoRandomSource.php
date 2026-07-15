<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Random;

use PurpleParlor\Games\Contracts\RandomSource;

final class CryptoRandomSource implements RandomSource
{
    public function int(int $minimum, int $maximum): int
    {
        return random_int($minimum, $maximum);
    }

    public function bytes(int $length): string
    {
        return random_bytes($length);
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
