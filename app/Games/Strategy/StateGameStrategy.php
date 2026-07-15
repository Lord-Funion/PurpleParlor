<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Strategy;

use PurpleParlor\Games\Cards\Card;
use PurpleParlor\Games\Cards\Deck;
use PurpleParlor\Games\Contracts\RandomSource;
use PurpleParlor\Games\DTO\GameOutcome;
use PurpleParlor\Games\DTO\GameRound;
use PurpleParlor\Games\Exceptions\GameException;

final class StateGameStrategy implements StrategyInterface
{
    use StrategySupport;

    public function __construct(private readonly RandomSource $random)
    {
    }

    public function resolve(GameRound $round, array $configuration): GameOutcome
    {
        return match ($round->slug) {
            'craps' => $this->craps($round),
            'mines' => $this->mines($round),
            'memory-match' => $this->memory($round),
            'higher-lower-streak' => $this->higherLower($round),
            'treasure-tiles' => $this->treasureTiles($round),
            default => throw new GameException('No state-game strategy is registered for this game.', 'strategy_missing', 500),
        };
    }

    private function craps(GameRound $round): GameOutcome
    {
        if ($round->serverState === []) {
            if (!in_array($round->action, ['play', 'roll'], true)) { throw new GameException('Start Craps by rolling the dice.'); }
            $state = $this->baseState($round) + ['point' => null, 'rolls' => 0];
        } else {
            if ($round->action !== 'roll') { throw new GameException('Roll the dice while a point is active.'); }
            $state = $this->requireState($round);
        }
        [$die1, $die2] = $this->rollTwoDice(); $sum = $die1 + $die2; ++$state['rolls'];
        if ($state['point'] === null) {
            if (in_array($sum, [7, 11], true)) return $this->complete('natural', ['dice' => [$die1, $die2], 'sum' => $sum, 'point' => null, 'rolls' => $state['rolls']], 20000);
            if (in_array($sum, [2, 3, 12], true)) return $this->complete('craps_out', ['dice' => [$die1, $die2], 'sum' => $sum, 'point' => null, 'rolls' => $state['rolls']], 0);
            $state['point'] = $sum;
            return $this->pending('point_set', ['dice' => [$die1, $die2], 'sum' => $sum, 'point' => $sum], ['roll'], $state, ['point' => $sum, 'rolls' => $state['rolls']]);
        }
        if ($sum === $state['point']) return $this->complete('point_made', ['dice' => [$die1, $die2], 'sum' => $sum, 'point' => $state['point'], 'rolls' => $state['rolls']], 20000);
        if ($sum === 7) return $this->complete('seven_out', ['dice' => [$die1, $die2], 'sum' => $sum, 'point' => $state['point'], 'rolls' => $state['rolls']], 0);
        return $this->pending('point_active', ['dice' => [$die1, $die2], 'sum' => $sum, 'point' => $state['point']], ['roll'], $state, ['point' => $state['point'], 'rolls' => $state['rolls']]);
    }

    private function mines(GameRound $round): GameOutcome
    {
        if ($round->serverState === []) {
            if ($round->action !== 'play') throw new GameException('Start Mines with play.');
            $count = $this->integerOption($round->input['mines'] ?? 5, 1, 10, 'mine count');
            $minePositions = array_slice($this->random->shuffle(range(0, 24)), 0, $count);
            sort($minePositions, SORT_NUMERIC);
            $state = $this->baseState($round) + ['mines' => $minePositions, 'mineCount' => $count, 'revealed' => []];
            return $this->pending('board_ready', ['tileCount' => 25, 'mineCount' => $count], ['reveal'], $state, ['tileCount' => 25, 'mineCount' => $count, 'revealed' => [], 'currentMultiplierBps' => 0]);
        }
        $state = $this->requireState($round);
        if ($round->action === 'cashout') {
            if (count($state['revealed']) < 1) throw new GameException('Reveal at least one safe tile before cashing out.');
            $bps = $this->minesMultiplier(25, (int) $state['mineCount'], count($state['revealed']));
            return $this->complete('cashed_out', ['revealed' => $state['revealed'], 'mineCount' => $state['mineCount'], 'mines' => $state['mines']], $bps);
        }
        if ($round->action !== 'reveal') throw new GameException('Mines accepts reveal or cashout.');
        $tile = $this->integerOption($round->input['tile'] ?? null, 0, 24, 'tile');
        if (in_array($tile, $state['revealed'], true)) throw new GameException('That tile has already been revealed.', 'duplicate_action', 409);
        if (in_array($tile, $state['mines'], true)) {
            return $this->complete('mine', ['tile' => $tile, 'revealed' => $state['revealed'], 'mines' => $state['mines']], 0);
        }
        $state['revealed'][] = $tile; sort($state['revealed'], SORT_NUMERIC);
        $safeTotal = 25 - (int) $state['mineCount'];
        if (count($state['revealed']) === $safeTotal) {
            return $this->complete('cleared', ['tile' => $tile, 'revealed' => $state['revealed'], 'mines' => $state['mines']], $this->minesMultiplier(25, (int) $state['mineCount'], $safeTotal));
        }
        $bps = $this->minesMultiplier(25, (int) $state['mineCount'], count($state['revealed']));
        return $this->pending('safe', ['tile' => $tile, 'revealed' => $state['revealed'], 'currentMultiplierBps' => $bps], ['reveal', 'cashout'], $state, ['tileCount' => 25, 'mineCount' => $state['mineCount'], 'revealed' => $state['revealed'], 'currentMultiplierBps' => $bps]);
    }

    private function memory(GameRound $round): GameOutcome
    {
        if ($round->serverState === []) {
            if ($round->action !== 'play') throw new GameException('Start Memory Match with play.');
            $cards = $this->random->shuffle(array_merge(range(0, 7), range(0, 7)));
            $state = $this->baseState($round) + ['cards' => $cards, 'matched' => [], 'pending' => null, 'moves' => 0];
            return $this->pending('board_ready', ['cardCount' => 16], ['flip'], $state, ['cardCount' => 16, 'matched' => [], 'faceUp' => [], 'moves' => 0]);
        }
        if ($round->action !== 'flip') throw new GameException('Choose a card to flip.');
        $state = $this->requireState($round); $index = $this->integerOption($round->input['index'] ?? null, 0, 15, 'card index');
        if (in_array($index, $state['matched'], true) || $state['pending'] === $index) throw new GameException('That card is already face up.', 'duplicate_action', 409);
        if ($state['pending'] === null) {
            $state['pending'] = $index;
            return $this->pending('first_flip', ['index' => $index, 'symbol' => $state['cards'][$index]], ['flip'], $state, ['cardCount' => 16, 'matched' => $state['matched'], 'faceUp' => [$index => $state['cards'][$index]], 'moves' => $state['moves']]);
        }
        $first = (int) $state['pending']; ++$state['moves']; $match = $state['cards'][$first] === $state['cards'][$index];
        if ($match) { $state['matched'][] = $first; $state['matched'][] = $index; sort($state['matched'], SORT_NUMERIC); }
        $state['pending'] = null;
        if (count($state['matched']) === 16) {
            return $this->complete('board_cleared', ['flipped' => [$first => $state['cards'][$first], $index => $state['cards'][$index]], 'moves' => $state['moves'], 'practiceScore' => max(100, 1000 - $state['moves'] * 25)], 0);
        }
        return $this->pending($match ? 'match' : 'no_match', ['flipped' => [$first => $state['cards'][$first], $index => $state['cards'][$index]], 'match' => $match], ['flip'], $state, ['cardCount' => 16, 'matched' => $state['matched'], 'faceUp' => [], 'moves' => $state['moves'], 'lastPair' => [$first, $index]]);
    }

    private function higherLower(GameRound $round): GameOutcome
    {
        if ($round->serverState === []) {
            if ($round->action !== 'play') throw new GameException('Start the streak with play.');
            $deck = new Deck($this->random); $current = $deck->draw();
            $state = $this->baseState($round) + ['deck' => $deck->remainingCodes(), 'current' => $current->code(), 'streak' => 0, 'survival' => 1.0];
            return $this->pending('guess', ['currentCard' => $current->jsonSerialize(), 'streak' => 0], ['higher', 'lower'], $state, ['currentCard' => $current->jsonSerialize(), 'streak' => 0, 'cashoutMultiplierBps' => 0]);
        }
        $state = $this->requireState($round);
        if ($round->action === 'cashout') {
            if ((int) $state['streak'] < 1) throw new GameException('Win at least one guess before cashing out.');
            return $this->complete('cashed_out', ['streak' => $state['streak'], 'currentCard' => Card::fromCode($state['current'])->jsonSerialize()], $this->streakMultiplier((float) $state['survival']));
        }
        if (!in_array($round->action, ['higher', 'lower'], true)) throw new GameException('Guess higher, lower, or cash out.');
        $deck = Deck::fromCodes($this->random, $state['deck']);
        if ($deck->remaining() === 0) return $this->complete('deck_cleared', ['streak' => $state['streak']], $this->streakMultiplier((float) $state['survival']));
        $current = Card::fromCode($state['current']); $next = $deck->draw(); $comparison = $next->rank <=> $current->rank;
        $remainingCards = $this->cardsFromCodes($state['deck']);
        $safeCount = count(array_filter($remainingCards, static fn (Card $card): bool => $round->action === 'higher' ? $card->rank >= $current->rank : $card->rank <= $current->rank));
        $win = $comparison === 0 || ($round->action === 'higher' && $comparison > 0) || ($round->action === 'lower' && $comparison < 0);
        if (!$win) return $this->complete('streak_ended', ['currentCard' => $current->jsonSerialize(), 'nextCard' => $next->jsonSerialize(), 'guess' => $round->action, 'streak' => $state['streak']], 0);
        ++$state['streak']; $state['survival'] *= $safeCount / max(1, count($remainingCards)); $state['current'] = $next->code(); $state['deck'] = $deck->remainingCodes(); $bps = $this->streakMultiplier((float) $state['survival']);
        return $this->pending($comparison === 0 ? 'tie_continues' : 'correct', ['currentCard' => $current->jsonSerialize(), 'nextCard' => $next->jsonSerialize(), 'streak' => $state['streak'], 'cashoutMultiplierBps' => $bps], ['higher', 'lower', 'cashout'], $state, ['currentCard' => $next->jsonSerialize(), 'streak' => $state['streak'], 'cashoutMultiplierBps' => $bps]);
    }

    private function treasureTiles(GameRound $round): GameOutcome
    {
        if ($round->serverState === []) {
            if ($round->action !== 'play') throw new GameException('Start Treasure Tiles with play.');
            $positions = $this->random->shuffle(range(0, 19));
            $traps = array_slice($positions, 0, 3); $treasures = [];
            foreach (array_slice($positions, 3, 10) as $index => $position) $treasures[$position] = ($index % 5) + 1;
            $state = $this->baseState($round) + ['traps' => $traps, 'treasures' => $treasures, 'revealed' => [], 'score' => 0];
            return $this->pending('map_ready', ['tileCount' => 20, 'trapCount' => 3], ['reveal'], $state, ['tileCount' => 20, 'revealed' => [], 'score' => 0, 'cashoutMultiplierBps' => 0]);
        }
        $state = $this->requireState($round);
        if ($round->action === 'cashout') {
            if ((int) $state['score'] < 1) throw new GameException('Find treasure before cashing out.');
            return $this->complete('treasure_banked', ['revealed' => $state['revealed'], 'score' => $state['score'], 'traps' => $state['traps'], 'treasures' => $state['treasures']], 7200 + (int) $state['score'] * 1700);
        }
        if ($round->action !== 'reveal') throw new GameException('Reveal a tile or cash out.');
        $tile = $this->integerOption($round->input['tile'] ?? null, 0, 19, 'tile');
        if (in_array($tile, $state['revealed'], true)) throw new GameException('That tile was already explored.', 'duplicate_action', 409);
        if (in_array($tile, $state['traps'], true)) return $this->complete('trap', ['tile' => $tile, 'revealed' => $state['revealed'], 'traps' => $state['traps'], 'treasures' => $state['treasures']], 0);
        $state['revealed'][] = $tile; $value = (int) ($state['treasures'][$tile] ?? 0); $state['score'] += $value;
        $remaining = count($state['revealed']) < 17;
        if (!$remaining) return $this->complete('map_cleared', ['revealed' => $state['revealed'], 'score' => $state['score'], 'traps' => $state['traps'], 'treasures' => $state['treasures']], 7200 + (int) $state['score'] * 1700);
        return $this->pending($value > 0 ? 'treasure' : 'empty', ['tile' => $tile, 'value' => $value, 'score' => $state['score']], ['reveal', 'cashout'], $state, ['tileCount' => 20, 'revealed' => $state['revealed'], 'score' => $state['score'], 'cashoutMultiplierBps' => 7200 + (int) $state['score'] * 1700]);
    }

    private function minesMultiplier(int $tiles, int $mines, int $reveals): int
    {
        $survival = 1.0;
        for ($index = 0; $index < $reveals; ++$index) $survival *= (($tiles - $mines - $index) / ($tiles - $index));
        return (int) floor(9700 / max($survival, 0.000001));
    }

    private function streakMultiplier(float $survivalProbability): int
    {
        return min(5000000, (int) floor(9500 / max(0.000001, $survivalProbability)));
    }

    /** @return array<string, mixed> */
    private function baseState(GameRound $round): array { return ['slug' => $round->slug, 'roundId' => $round->id, 'wager' => $round->wager, 'version' => 1]; }

    /** @return array<string, mixed> */
    private function requireState(GameRound $round): array
    {
        $state = $round->serverState;
        if (($state['slug'] ?? null) !== $round->slug || ($state['roundId'] ?? null) !== $round->id || ($state['wager'] ?? null) !== $round->wager) throw new GameException('The stored round state does not match this action.', 'state_conflict', 409);
        return $state;
    }

    /** @param array<string,mixed> $result */
    private function complete(string $code, array $result, int $bps): GameOutcome { $result['payoutMultiplierBps'] = max(0, $bps); return new GameOutcome($code, $result, $result, [], [], [], true); }

    /** @param array<string,mixed> $result @param list<string> $actions @param array<string,mixed> $state @param array<string,mixed> $public */
    private function pending(string $code, array $result, array $actions, array $state, array $public): GameOutcome { $result['payoutMultiplierBps'] = 0; return new GameOutcome($code, $result, $result, $actions, $state, $public, false); }
}
