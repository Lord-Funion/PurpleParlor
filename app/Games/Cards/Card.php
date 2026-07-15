<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Cards;

use InvalidArgumentException;

final class Card implements \JsonSerializable
{
    public const SUITS = ['clubs', 'diamonds', 'hearts', 'spades'];
    public const SYMBOLS = ['clubs' => 'C', 'diamonds' => 'D', 'hearts' => 'H', 'spades' => 'S'];

    public function __construct(public readonly int $rank, public readonly string $suit)
    {
        if ($rank < 2 || $rank > 14 || !in_array($suit, self::SUITS, true)) {
            throw new InvalidArgumentException('Invalid playing card.');
        }
    }

    public static function fromCode(string $code): self
    {
        if (!preg_match('/^(10|[2-9JQKA])([CDHS])$/', strtoupper($code), $matches)) {
            throw new InvalidArgumentException('Invalid card code.');
        }
        $ranks = ['J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14];
        $suits = ['C' => 'clubs', 'D' => 'diamonds', 'H' => 'hearts', 'S' => 'spades'];
        $rank = $ranks[$matches[1]] ?? (int) $matches[1];

        return new self($rank, $suits[$matches[2]]);
    }

    public function code(): string
    {
        $rank = match ($this->rank) {
            11 => 'J', 12 => 'Q', 13 => 'K', 14 => 'A', default => (string) $this->rank,
        };

        return $rank . self::SYMBOLS[$this->suit];
    }

    public function label(): string
    {
        $rank = match ($this->rank) {
            11 => 'Jack', 12 => 'Queen', 13 => 'King', 14 => 'Ace', default => (string) $this->rank,
        };

        return $rank . ' of ' . ucfirst($this->suit);
    }

    public function jsonSerialize(): array
    {
        return ['code' => $this->code(), 'rank' => $this->rank, 'suit' => $this->suit, 'label' => $this->label()];
    }
}
