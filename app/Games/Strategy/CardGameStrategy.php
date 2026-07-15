<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Strategy;

use PurpleParlor\Games\Cards\Card;
use PurpleParlor\Games\Cards\Deck;
use PurpleParlor\Games\Cards\HandEvaluator;
use PurpleParlor\Games\Contracts\RandomSource;
use PurpleParlor\Games\DTO\GameOutcome;
use PurpleParlor\Games\DTO\GameRound;
use PurpleParlor\Games\Exceptions\GameException;

final class CardGameStrategy implements StrategyInterface
{
    use StrategySupport;

    public function __construct(private readonly RandomSource $random)
    {
    }

    public function resolve(GameRound $round, array $configuration): GameOutcome
    {
        return match ($round->slug) {
            'blackjack' => $this->blackjack($round),
            'video-poker' => $this->videoPoker($round),
            'texas-holdem' => $this->holdem($round),
            'caribbean-stud' => $this->caribbeanStud($round),
            'casino-war' => $this->casinoWar($round),
            'red-dog' => $this->redDog($round),
            'let-it-ride' => $this->letItRide($round),
            'hi-lo' => $this->hiLo($round),
            'five-card-draw' => $this->fiveCardDraw($round),
            'parlor-switch' => $this->parlorSwitch($round),
            default => throw new GameException('No card-game strategy is registered for this game.', 'strategy_missing', 500),
        };
    }

    private function blackjack(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $deck = new Deck($this->random);
            $player = [$deck->draw(), $deck->draw()];
            $dealer = [$deck->draw(), $deck->draw()];
            $playerValue = HandEvaluator::blackjack($player);
            $dealerValue = HandEvaluator::blackjack($dealer);
            $state = $this->baseState($round) + ['deck' => $deck->remainingCodes(), 'player' => $this->cardCodes($player), 'dealer' => $this->cardCodes($dealer)];
            if ($playerValue['blackjack'] || $dealerValue['blackjack']) {
                $code = $playerValue['blackjack'] && $dealerValue['blackjack'] ? 'push' : ($playerValue['blackjack'] ? 'blackjack' : 'dealer_blackjack');
                $bps = $code === 'blackjack' ? 25000 : ($code === 'push' ? 10000 : 0);
                return $this->cardComplete($code, ['playerCards' => $this->publicCards($player), 'dealerCards' => $this->publicCards($dealer), 'playerTotal' => $playerValue['total'], 'dealerTotal' => $dealerValue['total']], $bps);
            }

            return $this->pending('player_turn', ['playerCards' => $this->publicCards($player), 'dealerCards' => [$dealer[0]->jsonSerialize(), ['hidden' => true]], 'playerTotal' => $playerValue['total']], ['hit', 'stand'], $state, ['playerTotal' => $playerValue['total'], 'dealerUpCard' => $dealer[0]->jsonSerialize()]);
        }

        $state = $this->requireState($round);
        $deck = Deck::fromCodes($this->random, $state['deck']);
        $player = $this->cardsFromCodes($state['player']);
        $dealer = $this->cardsFromCodes($state['dealer']);
        if ($round->action === 'hit') {
            $player[] = $deck->draw();
            $value = HandEvaluator::blackjack($player);
            $state['deck'] = $deck->remainingCodes();
            $state['player'] = $this->cardCodes($player);
            if ($value['bust']) {
                return $this->cardComplete('bust', ['playerCards' => $this->publicCards($player), 'dealerCards' => $this->publicCards($dealer), 'playerTotal' => $value['total'], 'dealerTotal' => HandEvaluator::blackjack($dealer)['total']], 0);
            }
            if ($value['total'] === 21) {
                return $this->resolveBlackjackStand($player, $dealer, $deck);
            }
            return $this->pending('player_turn', ['playerCards' => $this->publicCards($player), 'dealerCards' => [$dealer[0]->jsonSerialize(), ['hidden' => true]], 'playerTotal' => $value['total']], ['hit', 'stand'], $state, ['playerTotal' => $value['total'], 'dealerUpCard' => $dealer[0]->jsonSerialize()]);
        }
        if ($round->action !== 'stand') {
            throw new GameException('Blackjack accepts hit or stand during a round.');
        }

        return $this->resolveBlackjackStand($player, $dealer, $deck);
    }

    /** @param list<Card> $player @param list<Card> $dealer */
    private function resolveBlackjackStand(array $player, array $dealer, Deck $deck): GameOutcome
    {
        do {
            $dealerValue = HandEvaluator::blackjack($dealer);
            if ($dealerValue['total'] >= 17) {
                break;
            }
            $dealer[] = $deck->draw();
        } while (true);
        $playerValue = HandEvaluator::blackjack($player);
        $dealerValue = HandEvaluator::blackjack($dealer);
        if ($dealerValue['bust'] || $playerValue['total'] > $dealerValue['total']) {
            $code = 'win'; $bps = 20000;
        } elseif ($playerValue['total'] === $dealerValue['total']) {
            $code = 'push'; $bps = 10000;
        } else {
            $code = 'loss'; $bps = 0;
        }

        return $this->cardComplete($code, ['playerCards' => $this->publicCards($player), 'dealerCards' => $this->publicCards($dealer), 'playerTotal' => $playerValue['total'], 'dealerTotal' => $dealerValue['total']], $bps);
    }

    private function videoPoker(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $variant = $this->choice($round->input['variant'] ?? 'jacks_or_better', ['jacks_or_better', 'deuces_wild', 'bonus_poker'], 'variant');
            $deck = new Deck($this->random);
            $hand = $deck->drawMany(5);
            $state = $this->baseState($round) + ['deck' => $deck->remainingCodes(), 'hand' => $this->cardCodes($hand), 'variant' => $variant];
            return $this->pending('choose_holds', ['cards' => $this->publicCards($hand), 'variant' => $variant], ['draw'], $state, ['cards' => $this->publicCards($hand), 'variant' => $variant]);
        }
        if ($round->action !== 'draw') {
            throw new GameException('Choose cards to hold, then draw.');
        }
        $state = $this->requireState($round);
        $holds = $round->input['holds'] ?? [];
        if (!is_array($holds)) {
            throw new GameException('Held cards must be an array of indexes.', 'invalid_option');
        }
        $holds = array_values(array_unique(array_map(fn ($index): int => $this->integerOption($index, 0, 4, 'hold index'), $holds)));
        $deck = Deck::fromCodes($this->random, $state['deck']);
        $hand = $this->cardsFromCodes($state['hand']);
        foreach ($hand as $index => $_card) {
            if (!in_array($index, $holds, true)) {
                $hand[$index] = $deck->draw();
            }
        }
        $evaluation = HandEvaluator::poker($hand);
        $bps = $this->videoPokerPayout($hand, $evaluation, (string) $state['variant']);

        return $this->cardComplete($bps > 0 ? 'win' : 'loss', ['cards' => $this->publicCards($hand), 'hand' => $evaluation['name'], 'variant' => $state['variant'], 'held' => $holds], $bps);
    }

    private function holdem(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $difficulty = $this->choice($round->input['difficulty'] ?? 'friendly', ['friendly', 'regular', 'sharp'], 'difficulty');
            $deck = new Deck($this->random);
            $player = $deck->drawMany(2);
            $bot = $deck->drawMany(2);
            $flop = $deck->drawMany(3);
            $state = $this->baseState($round) + ['deck' => $deck->remainingCodes(), 'player' => $this->cardCodes($player), 'bot' => $this->cardCodes($bot), 'community' => $this->cardCodes($flop), 'stage' => 'flop', 'difficulty' => $difficulty];
            $botDisplay = $this->holdemBotDisplay($bot, $difficulty);
            $botHint = $this->holdemBotHint($bot, $flop, $difficulty);
            return $this->pending('flop', ['playerCards' => $this->publicCards($player), 'communityCards' => $this->publicCards($flop), 'botCards' => $botDisplay, 'botHint' => $botHint, 'difficulty' => $difficulty], ['check', 'fold'], $state, ['stage' => 'flop', 'playerCards' => $this->publicCards($player), 'communityCards' => $this->publicCards($flop), 'botCards' => $botDisplay, 'botHint' => $botHint, 'difficulty' => $difficulty]);
        }
        $state = $this->requireState($round);
        if ($round->action === 'fold') {
            return $this->cardComplete('fold', ['communityCards' => $this->publicCards($this->cardsFromCodes($state['community']))], 0);
        }
        if ($round->action !== 'check') {
            throw new GameException('Hold’em accepts check or fold.');
        }
        $deck = Deck::fromCodes($this->random, $state['deck']);
        $community = $this->cardsFromCodes($state['community']);
        if ($state['stage'] === 'flop') {
            $community[] = $deck->draw();
            $state['community'] = $this->cardCodes($community); $state['deck'] = $deck->remainingCodes(); $state['stage'] = 'turn';
            $botDisplay = $this->holdemBotDisplay($this->cardsFromCodes($state['bot']), (string) $state['difficulty']);
            $botHint = $this->holdemBotHint($this->cardsFromCodes($state['bot']), $community, (string) $state['difficulty']);
            return $this->pending('turn', ['playerCards' => $this->publicCards($this->cardsFromCodes($state['player'])), 'communityCards' => $this->publicCards($community), 'botCards' => $botDisplay, 'botHint' => $botHint], ['check', 'fold'], $state, ['stage' => 'turn', 'playerCards' => $this->publicCards($this->cardsFromCodes($state['player'])), 'communityCards' => $this->publicCards($community), 'botCards' => $botDisplay, 'botHint' => $botHint, 'difficulty' => $state['difficulty']]);
        }
        if ($state['stage'] !== 'turn') {
            throw new GameException('The Hold’em round is not in a playable stage.', 'state_conflict', 409);
        }
        $community[] = $deck->draw();
        $player = $this->cardsFromCodes($state['player']);
        $bot = $this->cardsFromCodes($state['bot']);
        $playerEval = HandEvaluator::poker(array_merge($player, $community));
        $botEval = HandEvaluator::poker(array_merge($bot, $community));
        $compare = $playerEval['score'] <=> $botEval['score'];

        return $this->cardComplete($compare > 0 ? 'win' : ($compare === 0 ? 'split' : 'loss'), ['playerCards' => $this->publicCards($player), 'botCards' => $this->publicCards($bot), 'communityCards' => $this->publicCards($community), 'playerHand' => $playerEval['name'], 'botHand' => $botEval['name'], 'botType' => 'computer'], $compare > 0 ? 20000 : ($compare === 0 ? 10000 : 0));
    }

    private function caribbeanStud(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $deck = new Deck($this->random);
            $player = $deck->drawMany(5); $dealer = $deck->drawMany(5);
            $state = $this->baseState($round) + ['player' => $this->cardCodes($player), 'dealer' => $this->cardCodes($dealer)];
            return $this->pending('decision', ['playerCards' => $this->publicCards($player), 'dealerCards' => [$dealer[0]->jsonSerialize(), ['hidden' => true], ['hidden' => true], ['hidden' => true], ['hidden' => true]]], ['raise', 'fold'], $state, ['playerCards' => $this->publicCards($player), 'dealerUpCard' => $dealer[0]->jsonSerialize()]);
        }
        $state = $this->requireState($round);
        if ($round->action === 'fold') {
            return $this->cardComplete('fold', [], 0);
        }
        if ($round->action !== 'raise') {
            throw new GameException('Caribbean Stud accepts raise or fold.');
        }
        $player = $this->cardsFromCodes($state['player']); $dealer = $this->cardsFromCodes($state['dealer']);
        $p = HandEvaluator::poker($player); $d = HandEvaluator::poker($dealer);
        $dealerRanks = $d['kickers'];
        $qualifies = $d['category'] >= 1 || ($d['category'] === 0 && $dealerRanks[0] === 14 && ($dealerRanks[1] ?? 0) >= 13);
        $compare = $p['score'] <=> $d['score'];
        $bonus = [1 => 10000, 2 => 20000, 3 => 30000, 4 => 40000, 5 => 50000, 6 => 80000, 7 => 210000, 8 => 510000][$p['category']] ?? 0;
        $bps = !$qualifies ? 10000 : ($compare > 0 ? 19000 + intdiv($bonus, 2) : ($compare === 0 ? 10000 : 0));

        return $this->cardComplete(!$qualifies ? 'dealer_not_qualified' : ($compare > 0 ? 'win' : ($compare === 0 ? 'push' : 'loss')), ['playerCards' => $this->publicCards($player), 'dealerCards' => $this->publicCards($dealer), 'playerHand' => $p['name'], 'dealerHand' => $d['name'], 'dealerQualifies' => $qualifies], $bps);
    }

    private function casinoWar(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $deck = new Deck($this->random);
            $player = $deck->draw(); $dealer = $deck->draw();
            if ($player->rank !== $dealer->rank) {
                $win = $player->rank > $dealer->rank;
                return $this->cardComplete($win ? 'win' : 'loss', ['playerCard' => $player->jsonSerialize(), 'dealerCard' => $dealer->jsonSerialize()], $win ? 20000 : 0);
            }
            $state = $this->baseState($round) + ['deck' => $deck->remainingCodes(), 'firstPlayer' => $player->code(), 'firstDealer' => $dealer->code()];
            return $this->pending('tie_decision', ['playerCard' => $player->jsonSerialize(), 'dealerCard' => $dealer->jsonSerialize()], ['war', 'surrender'], $state, ['tiedRank' => $player->rank]);
        }
        $state = $this->requireState($round);
        if ($round->action === 'surrender') {
            return $this->cardComplete('surrender', ['returnedFraction' => 0.5], 5000);
        }
        if ($round->action !== 'war') {
            throw new GameException('Choose war or surrender after a tie.');
        }
        $deck = Deck::fromCodes($this->random, $state['deck']);
        $burn = $deck->drawMany(min(3, $deck->remaining() - 2));
        $player = $deck->draw(); $dealer = $deck->draw();
        $win = $player->rank >= $dealer->rank;
        // The initial wager was not doubled for War, so a War win returns that
        // wager (1x gross) rather than crediting an unfunded second stake.
        return $this->cardComplete($win ? 'war_win' : 'war_loss', ['burnedCards' => count($burn), 'playerCard' => $player->jsonSerialize(), 'dealerCard' => $dealer->jsonSerialize()], $win ? 10000 : 0);
    }

    private function redDog(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $deck = new Deck($this->random);
            $cards = $deck->drawMany(2);
            usort($cards, static fn (Card $a, Card $b): int => $a->rank <=> $b->rank);
            $spread = $cards[1]->rank - $cards[0]->rank - 1;
            if ($spread === 0) {
                return $this->cardComplete('push', ['cards' => $this->publicCards($cards), 'spread' => 0], 10000);
            }
            $state = $this->baseState($round) + ['deck' => $deck->remainingCodes(), 'cards' => $this->cardCodes($cards), 'spread' => $spread];
            return $this->pending('draw_decision', ['cards' => $this->publicCards($cards), 'spread' => $spread], ['draw'], $state, ['cards' => $this->publicCards($cards), 'spread' => $spread]);
        }
        if ($round->action !== 'draw') {
            throw new GameException('Draw the third Red Dog card.');
        }
        $state = $this->requireState($round); $deck = Deck::fromCodes($this->random, $state['deck']);
        $cards = $this->cardsFromCodes($state['cards']); $third = $deck->draw(); $spread = (int) $state['spread'];
        if ($spread === -1) {
            $match = $third->rank === $cards[0]->rank;
            return $this->cardComplete($match ? 'three_of_rank' : 'pair_push', ['cards' => $this->publicCards($cards), 'thirdCard' => $third->jsonSerialize(), 'spread' => $spread], $match ? 120000 : 10000);
        }
        $win = $third->rank > $cards[0]->rank && $third->rank < $cards[1]->rank;
        $bps = $win ? match ($spread) { 1 => 60000, 2 => 50000, 3 => 30000, default => 20000 } : 0;
        return $this->cardComplete($win ? 'win' : 'loss', ['cards' => $this->publicCards($cards), 'thirdCard' => $third->jsonSerialize(), 'spread' => $spread], $bps);
    }

    private function letItRide(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $deck = new Deck($this->random); $player = $deck->drawMany(3); $community = $deck->drawMany(2);
            $state = $this->baseState($round) + ['player' => $this->cardCodes($player), 'community' => $this->cardCodes($community), 'stage' => 0, 'units' => 3];
            return $this->pending('first_decision', ['playerCards' => $this->publicCards($player), 'communityCards' => [['hidden' => true], ['hidden' => true]], 'unitsRiding' => 3], ['ride', 'pull'], $state, ['stage' => 0, 'playerCards' => $this->publicCards($player), 'communityCards' => [['hidden' => true], ['hidden' => true]], 'unitsRiding' => 3]);
        }
        if (!in_array($round->action, ['ride', 'pull'], true)) {
            throw new GameException('Choose ride or pull.');
        }
        $state = $this->requireState($round); $player = $this->cardsFromCodes($state['player']); $community = $this->cardsFromCodes($state['community']);
        if ($round->action === 'pull') { $state['units'] = max(1, (int) $state['units'] - 1); }
        ++$state['stage'];
        if ($state['stage'] === 1) {
            return $this->pending('second_decision', ['playerCards' => $this->publicCards($player), 'communityCards' => [$community[0]->jsonSerialize(), ['hidden' => true]], 'unitsRiding' => $state['units']], ['ride', 'pull'], $state, ['stage' => 1, 'playerCards' => $this->publicCards($player), 'communityCards' => [$community[0]->jsonSerialize(), ['hidden' => true]], 'unitsRiding' => $state['units']]);
        }
        $evaluation = HandEvaluator::poker(array_merge($player, $community));
        $profitOdds = match ($evaluation['category']) { 8 => $evaluation['kickers'][0] === 14 ? 1000 : 200, 7 => 50, 6 => 11, 5 => 8, 4 => 5, 3 => 3, 2 => 2, 1 => ($evaluation['kickers'][0] >= 10 ? 1 : 0), default => -1 };
        $ridingUnits = (int) $state['units']; $pulledUnits = 3 - $ridingUnits;
        $grossRiding = $profitOdds >= 0 ? $ridingUnits * ($profitOdds + 1) : 0;
        $bps = (int) round((($pulledUnits + $grossRiding) / 3) * 10000);
        return $this->cardComplete($bps > 0 ? 'win' : 'loss', ['playerCards' => $this->publicCards($player), 'communityCards' => $this->publicCards($community), 'hand' => $evaluation['name'], 'unitsRiding' => $state['units']], $bps);
    }

    private function hiLo(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $deck = new Deck($this->random); $current = $deck->draw();
            $state = $this->baseState($round) + ['deck' => $deck->remainingCodes(), 'current' => $current->code()];
            return $this->pending('guess', ['currentCard' => $current->jsonSerialize()], ['higher', 'lower'], $state, ['currentCard' => $current->jsonSerialize()]);
        }
        if (!in_array($round->action, ['higher', 'lower'], true)) { throw new GameException('Guess higher or lower.'); }
        $state = $this->requireState($round); $current = Card::fromCode($state['current']); $deck = Deck::fromCodes($this->random, $state['deck']); $next = $deck->draw();
        $comparison = $next->rank <=> $current->rank;
        $win = ($round->action === 'higher' && $comparison > 0) || ($round->action === 'lower' && $comparison < 0);
        $favorable = $round->action === 'higher' ? (14 - $current->rank) * 4 : ($current->rank - 2) * 4;
        $grossBps = $favorable > 0 ? (int) floor((0.95 - (3 / 51)) / ($favorable / 51) * 10000) : 0;
        $bps = $comparison === 0 ? 10000 : ($win ? $grossBps : 0);
        return $this->cardComplete($comparison === 0 ? 'tie' : ($win ? 'win' : 'loss'), ['currentCard' => $current->jsonSerialize(), 'nextCard' => $next->jsonSerialize(), 'guess' => $round->action], $bps);
    }

    private function fiveCardDraw(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $difficulty = $this->choice($round->input['difficulty'] ?? 'regular', ['friendly', 'regular', 'sharp'], 'difficulty');
            $deck = new Deck($this->random); $player = $deck->drawMany(5); $bot = $deck->drawMany(5);
            $state = $this->baseState($round) + ['deck' => $deck->remainingCodes(), 'player' => $this->cardCodes($player), 'bot' => $this->cardCodes($bot), 'difficulty' => $difficulty];
            return $this->pending('draw_decision', ['playerCards' => $this->publicCards($player), 'botCards' => 5, 'difficulty' => $difficulty], ['draw'], $state, ['playerCards' => $this->publicCards($player), 'botCards' => 5, 'difficulty' => $difficulty]);
        }
        if ($round->action !== 'draw') { throw new GameException('Choose cards to hold, then draw.'); }
        $state = $this->requireState($round); $deck = Deck::fromCodes($this->random, $state['deck']); $player = $this->cardsFromCodes($state['player']); $bot = $this->cardsFromCodes($state['bot']);
        $holds = $round->input['holds'] ?? [];
        if (!is_array($holds)) { throw new GameException('Held cards must be an array.', 'invalid_option'); }
        $holds = array_values(array_unique(array_map(fn ($index): int => $this->integerOption($index, 0, 4, 'hold index'), $holds)));
        foreach ($player as $index => $_card) if (!in_array($index, $holds, true)) { $player[$index] = $deck->draw(); }
        $botHolds = $this->botDrawHolds($bot, (string) $state['difficulty']);
        foreach ($bot as $index => $_card) if (!in_array($index, $botHolds, true)) { $bot[$index] = $deck->draw(); }
        $p = HandEvaluator::poker($player); $b = HandEvaluator::poker($bot); $compare = $p['score'] <=> $b['score'];
        return $this->cardComplete($compare > 0 ? 'win' : ($compare === 0 ? 'push' : 'loss'), ['playerCards' => $this->publicCards($player), 'botCards' => $this->publicCards($bot), 'playerHand' => $p['name'], 'botHand' => $b['name'], 'botType' => 'computer'], $compare > 0 ? 20000 : ($compare === 0 ? 10000 : 0));
    }

    private function parlorSwitch(GameRound $round): GameOutcome
    {
        if ($this->isStart($round)) {
            $deck = new Deck($this->random); $hands = [[$deck->draw(), $deck->draw()], [$deck->draw(), $deck->draw()]]; $dealer = [$deck->draw(), $deck->draw()];
            $state = $this->baseState($round) + ['deck' => $deck->remainingCodes(), 'hands' => [$this->cardCodes($hands[0]), $this->cardCodes($hands[1])], 'dealer' => $this->cardCodes($dealer)];
            return $this->pending('switch_decision', ['hands' => [$this->publicCards($hands[0]), $this->publicCards($hands[1])], 'dealerCards' => [$dealer[0]->jsonSerialize(), ['hidden' => true]]], ['switch', 'keep'], $state, ['hands' => [$this->publicCards($hands[0]), $this->publicCards($hands[1])], 'dealerUpCard' => $dealer[0]->jsonSerialize()]);
        }
        if (!in_array($round->action, ['switch', 'keep'], true)) { throw new GameException('Choose switch or keep.'); }
        $state = $this->requireState($round); $hands = [$this->cardsFromCodes($state['hands'][0]), $this->cardsFromCodes($state['hands'][1])]; $dealer = $this->cardsFromCodes($state['dealer']); $deck = Deck::fromCodes($this->random, $state['deck']);
        if ($round->action === 'switch') { [$hands[0][1], $hands[1][1]] = [$hands[1][1], $hands[0][1]]; }
        foreach ($hands as &$hand) { while (HandEvaluator::blackjack($hand)['total'] < 17) { $hand[] = $deck->draw(); } } unset($hand);
        while (HandEvaluator::blackjack($dealer)['total'] < 17) { $dealer[] = $deck->draw(); }
        $dealerValue = HandEvaluator::blackjack($dealer); $returns = 0; $results = [];
        foreach ($hands as $hand) {
            $value = HandEvaluator::blackjack($hand);
            if ($value['bust']) { $result = 'loss'; $part = 0; }
            elseif ($dealerValue['total'] === 22) { $result = 'push_22'; $part = 10000; }
            elseif ($dealerValue['bust'] || $value['total'] > $dealerValue['total']) { $result = 'win'; $part = 20000; }
            elseif ($value['total'] === $dealerValue['total']) { $result = 'push'; $part = 10000; }
            else { $result = 'loss'; $part = 0; }
            $returns += $part; $results[] = ['result' => $result, 'total' => $value['total']];
        }
        $bps = intdiv($returns, 2);
        return $this->cardComplete($bps > 10000 ? 'win' : ($bps === 10000 ? 'push' : 'loss'), ['hands' => [$this->publicCards($hands[0]), $this->publicCards($hands[1])], 'dealerCards' => $this->publicCards($dealer), 'dealerTotal' => $dealerValue['total'], 'handResults' => $results, 'switched' => $round->action === 'switch'], $bps);
    }

    private function isStart(GameRound $round): bool
    {
        return $round->serverState === [] && in_array($round->action, ['play', 'deal'], true);
    }

    /** @return array<string, mixed> */
    private function baseState(GameRound $round): array
    {
        return ['slug' => $round->slug, 'roundId' => $round->id, 'wager' => $round->wager, 'version' => 1];
    }

    /** @return array<string, mixed> */
    private function requireState(GameRound $round): array
    {
        $state = $round->serverState;
        if (($state['slug'] ?? null) !== $round->slug || ($state['roundId'] ?? null) !== $round->id || ($state['wager'] ?? null) !== $round->wager) {
            throw new GameException('The stored round state does not match this action.', 'state_conflict', 409);
        }
        return $state;
    }

    /** @param array<string, mixed> $result */
    private function cardComplete(string $code, array $result, int $bps): GameOutcome
    {
        $result['payoutMultiplierBps'] = max(0, $bps);
        return new GameOutcome($code, $result, $result, [], [], [], true);
    }

    /** @param array<string, mixed> $result @param list<string> $actions @param array<string, mixed> $state @param array<string, mixed> $publicState */
    private function pending(string $code, array $result, array $actions, array $state, array $publicState): GameOutcome
    {
        $result['payoutMultiplierBps'] = 0;
        return new GameOutcome($code, $result, $result, $actions, $state, $publicState, false);
    }

    /** @param list<Card> $hand @param array<string, mixed> $evaluation */
    private function videoPokerPayout(array $hand, array $evaluation, string $variant): int
    {
        $deuces = count(array_filter($hand, static fn (Card $card): bool => $card->rank === 2));
        if ($variant === 'deuces_wild' && $deuces > 0) {
            return match ($deuces) { 4 => 2000000, 3 => 250000, 2 => 100000, 1 => max(10000, [0 => 0, 1 => 10000, 2 => 20000, 3 => 40000, 4 => 100000, 5 => 150000, 6 => 250000, 7 => 1250000, 8 => 2500000][$evaluation['category']] ?? 0), default => 0 };
        }
        if ($variant === 'bonus_poker' && $evaluation['category'] === 7) {
            $rank = $evaluation['kickers'][0];
            return in_array($rank, [2, 3, 4], true) ? 800000 : (in_array($rank, [14, 2, 3, 4], true) ? 800000 : 400000);
        }
        return match ($evaluation['category']) {
            8 => $evaluation['kickers'][0] === 14 ? 8000000 : 500000,
            7 => 250000, 6 => 90000, 5 => 60000, 4 => 40000, 3 => 30000, 2 => 20000,
            1 => $evaluation['kickers'][0] >= 11 ? 10000 : 0,
            default => 0,
        };
    }

    /** @param list<Card> $cards @return list<int> */
    private function botDrawHolds(array $cards, string $difficulty): array
    {
        $counts = array_count_values(array_map(static fn (Card $card): int => $card->rank, $cards));
        $holds = [];
        foreach ($cards as $index => $card) {
            if (($counts[$card->rank] ?? 0) >= 2) { $holds[] = $index; }
        }
        if ($holds === [] && $difficulty === 'sharp') {
            $suitCounts = array_count_values(array_map(static fn (Card $card): string => $card->suit, $cards));
            $flushSuit = array_search(4, $suitCounts, true);
            if (is_string($flushSuit)) foreach ($cards as $index => $card) if ($card->suit === $flushSuit) $holds[] = $index;
        }
        if ($holds === [] && $difficulty !== 'friendly') {
            $highest = max(array_map(static fn (Card $card): int => $card->rank, $cards));
            foreach ($cards as $index => $card) if ($card->rank === $highest) { $holds[] = $index; break; }
        }
        return $holds;
    }

    /** @param list<Card> $cards @return list<array<string,mixed>> */
    private function holdemBotDisplay(array $cards, string $difficulty): array
    {
        return $difficulty === 'friendly'
            ? [$cards[0]->jsonSerialize(), ['hidden' => true]]
            : [['hidden' => true], ['hidden' => true]];
    }

    /** @param list<Card> $bot @param list<Card> $community */
    private function holdemBotHint(array $bot, array $community, string $difficulty): ?string
    {
        if ($difficulty !== 'regular') return null;
        $evaluation = HandEvaluator::poker(array_merge($bot, $community));
        return $evaluation['category'] === 0 ? 'High-card range' : $evaluation['name'] . ' range';
    }
}
