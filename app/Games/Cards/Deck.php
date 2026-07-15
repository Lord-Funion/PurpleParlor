<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Cards;

use PurpleParlor\Games\Contracts\RandomSource;
use UnderflowException;

final class Deck
{
    /** @var list<Card> */
    private array $cards;

    /** @param list<Card>|null $cards */
    public function __construct(RandomSource $random, ?array $cards = null, bool $shuffle = true)
    {
        $cards ??= self::standardCards();
        $this->cards = $shuffle ? $random->shuffle($cards) : array_values($cards);
    }

    /** @return list<Card> */
    public static function standardCards(): array
    {
        $cards = [];
        foreach (Card::SUITS as $suit) {
            for ($rank = 2; $rank <= 14; ++$rank) {
                $cards[] = new Card($rank, $suit);
            }
        }

        return $cards;
    }

    /** @param list<string> $codes */
    public static function fromCodes(RandomSource $random, array $codes): self
    {
        return new self($random, array_map(static fn (string $code): Card => Card::fromCode($code), $codes), false);
    }

    public function draw(): Card
    {
        $card = array_shift($this->cards);
        if (!$card instanceof Card) {
            throw new UnderflowException('The deck is empty.');
        }

        return $card;
    }

    /** @return list<Card> */
    public function drawMany(int $count): array
    {
        if ($count < 0 || $count > count($this->cards)) {
            throw new UnderflowException('Not enough cards remain in the deck.');
        }
        $cards = [];
        for ($index = 0; $index < $count; ++$index) {
            $cards[] = $this->draw();
        }

        return $cards;
    }

    public function remaining(): int
    {
        return count($this->cards);
    }

    /** @return list<string> */
    public function remainingCodes(): array
    {
        return array_map(static fn (Card $card): string => $card->code(), $this->cards);
    }
}
