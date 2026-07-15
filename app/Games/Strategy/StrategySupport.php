<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Strategy;

use PurpleParlor\Games\Cards\Card;
use PurpleParlor\Games\Exceptions\GameException;

trait StrategySupport
{
    /** @param mixed $value */
    private function integerOption(mixed $value, int $minimum, int $maximum, string $name): int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new GameException("{$name} must be between {$minimum} and {$maximum}.", 'invalid_option');
        }

        return $value;
    }

    /** @param mixed $value @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $name): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new GameException("Invalid {$name}.", 'invalid_option', 422, ['allowed' => $allowed]);
        }

        return $value;
    }

    /** @param list<Card> $cards @return list<array<string, mixed>> */
    private function publicCards(array $cards): array
    {
        return array_map(static fn (Card $card): array => $card->jsonSerialize(), $cards);
    }

    /** @param list<Card> $cards @return list<string> */
    private function cardCodes(array $cards): array
    {
        return array_map(static fn (Card $card): string => $card->code(), $cards);
    }

    /** @param list<string> $codes @return list<Card> */
    private function cardsFromCodes(array $codes): array
    {
        return array_map(static fn (string $code): Card => Card::fromCode($code), $codes);
    }

    /** @return array{0:int,1:int} */
    private function rollTwoDice(): array
    {
        return [$this->random->int(1, 6), $this->random->int(1, 6)];
    }
}
