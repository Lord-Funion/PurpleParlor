<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Cards;

use InvalidArgumentException;

final class HandEvaluator
{
    /** @param list<Card> $cards @return array{total:int,soft:bool,bust:bool,blackjack:bool} */
    public static function blackjack(array $cards): array
    {
        $total = 0;
        $aces = 0;
        foreach ($cards as $card) {
            if ($card->rank === 14) {
                $total += 1;
                ++$aces;
            } else {
                $total += min($card->rank, 10);
            }
        }
        $soft = false;
        while ($aces > 0 && $total + 10 <= 21) {
            $total += 10;
            $soft = true;
            --$aces;
        }

        return ['total' => $total, 'soft' => $soft, 'bust' => $total > 21, 'blackjack' => count($cards) === 2 && $total === 21];
    }

    /**
     * Evaluates the best five-card poker hand from 5-7 cards.
     * @param list<Card> $cards
     * @return array{category:int,name:string,score:int,kickers:list<int>,cards:list<string>}
     */
    public static function poker(array $cards): array
    {
        if (count($cards) < 5 || count($cards) > 7) {
            throw new InvalidArgumentException('Poker evaluation requires five to seven cards.');
        }
        $best = null;
        foreach (self::combinations($cards, 5) as $combination) {
            $evaluated = self::evaluateFive($combination);
            if ($best === null || $evaluated['score'] > $best['score']) {
                $best = $evaluated;
            }
        }

        return $best;
    }

    /** @param list<Card> $cards @return array{category:int,name:string,score:int,kickers:list<int>,cards:list<string>} */
    private static function evaluateFive(array $cards): array
    {
        $ranks = array_map(static fn (Card $card): int => $card->rank, $cards);
        rsort($ranks, SORT_NUMERIC);
        $counts = array_count_values($ranks);
        $groups = [];
        foreach ($counts as $rank => $count) {
            $groups[] = ['rank' => (int) $rank, 'count' => $count];
        }
        usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $b['rank'] <=> $a['rank']);

        $flush = count(array_unique(array_map(static fn (Card $card): string => $card->suit, $cards))) === 1;
        $unique = array_values(array_unique($ranks));
        if ($unique === [14, 5, 4, 3, 2]) {
            $straightHigh = 5;
        } else {
            $straightHigh = count($unique) === 5 && $unique[0] - $unique[4] === 4 ? $unique[0] : 0;
        }

        if ($flush && $straightHigh > 0) {
            [$category, $name, $kickers] = [8, $straightHigh === 14 ? 'Royal Flush' : 'Straight Flush', [$straightHigh]];
        } elseif ($groups[0]['count'] === 4) {
            [$category, $name, $kickers] = [7, 'Four of a Kind', [$groups[0]['rank'], $groups[1]['rank']]];
        } elseif ($groups[0]['count'] === 3 && $groups[1]['count'] === 2) {
            [$category, $name, $kickers] = [6, 'Full House', [$groups[0]['rank'], $groups[1]['rank']]];
        } elseif ($flush) {
            [$category, $name, $kickers] = [5, 'Flush', $ranks];
        } elseif ($straightHigh > 0) {
            [$category, $name, $kickers] = [4, 'Straight', [$straightHigh]];
        } elseif ($groups[0]['count'] === 3) {
            $rest = array_map(static fn (array $group): int => $group['rank'], array_slice($groups, 1));
            rsort($rest, SORT_NUMERIC);
            [$category, $name, $kickers] = [3, 'Three of a Kind', array_merge([$groups[0]['rank']], $rest)];
        } elseif ($groups[0]['count'] === 2 && $groups[1]['count'] === 2) {
            $pairs = [$groups[0]['rank'], $groups[1]['rank']];
            rsort($pairs, SORT_NUMERIC);
            [$category, $name, $kickers] = [2, 'Two Pair', array_merge($pairs, [$groups[2]['rank']])];
        } elseif ($groups[0]['count'] === 2) {
            $rest = array_map(static fn (array $group): int => $group['rank'], array_slice($groups, 1));
            rsort($rest, SORT_NUMERIC);
            [$category, $name, $kickers] = [1, 'One Pair', array_merge([$groups[0]['rank']], $rest)];
        } else {
            [$category, $name, $kickers] = [0, 'High Card', $ranks];
        }

        $padded = array_pad(array_slice($kickers, 0, 5), 5, 0);
        $score = $category;
        foreach ($padded as $kicker) {
            $score = $score * 15 + $kicker;
        }

        return [
            'category' => $category,
            'name' => $name,
            'score' => $score,
            'kickers' => $kickers,
            'cards' => array_map(static fn (Card $card): string => $card->code(), $cards),
        ];
    }

    /** @template T @param list<T> $items @return list<list<T>> */
    private static function combinations(array $items, int $size): array
    {
        if ($size === 0) {
            return [[]];
        }
        $result = [];
        for ($index = 0; $index <= count($items) - $size; ++$index) {
            $head = $items[$index];
            foreach (self::combinations(array_slice($items, $index + 1), $size - 1) as $tail) {
                $result[] = array_merge([$head], $tail);
            }
        }

        return $result;
    }
}
