<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Contracts;

interface RandomSource
{
    public function int(int $minimum, int $maximum): int;

    public function bytes(int $length): string;

    /**
     * @template T
     * @param list<T> $values
     * @return list<T>
     */
    public function shuffle(array $values): array;
}
