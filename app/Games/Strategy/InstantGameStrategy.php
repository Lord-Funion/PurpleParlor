<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Strategy;

use PurpleParlor\Games\Cards\Deck;
use PurpleParlor\Games\Cards\HandEvaluator;
use PurpleParlor\Games\Contracts\RandomSource;
use PurpleParlor\Games\DTO\GameOutcome;
use PurpleParlor\Games\DTO\GameRound;
use PurpleParlor\Games\Exceptions\GameException;

final class InstantGameStrategy implements StrategyInterface
{
    use StrategySupport;

    public function __construct(private readonly RandomSource $random)
    {
    }

    public function resolve(GameRound $round, array $configuration): GameOutcome
    {
        if (!in_array($round->action, ['play', 'spin', 'deal', 'draw', 'roll'], true)) {
            throw new GameException('This game starts with the play action.');
        }

        return match ($round->slug) {
            'plinko' => $this->plinko($round),
            'classic-three-reel-slots' => $this->threeReelSlots(),
            'five-reel-video-slots' => $this->fiveReelSlots(),
            'european-roulette', 'american-roulette' => $this->roulette($round),
            'baccarat' => $this->baccarat($round),
            'three-card-poker' => $this->threeCardPoker($round),
            'pai-gow-poker' => $this->paiGow($round),
            'teen-patti-practice' => $this->teenPatti(),
            'sic-bo' => $this->sicBo($round),
            'keno' => $this->keno($round),
            'bingo' => $this->bingo(),
            'over-under-dice' => $this->overUnder($round),
            'coin-flip' => $this->coinFlip($round),
            'prize-wheel' => $this->prizeWheel(),
            'number-draw' => $this->numberDraw($round),
            'pachinko' => $this->pachinko(),
            'horse-racing' => $this->horseRace($round),
            'scratch-cards' => $this->scratchCard(),
            'gem-drop' => $this->gemDrop(),
            'lucky-cups' => $this->luckyCups($round),
            default => throw new GameException('No instant-game strategy is registered for this game.', 'strategy_missing', 500),
        };
    }

    private function plinko(GameRound $round): GameOutcome
    {
        $rows = $this->integerOption($round->input['rows'] ?? 12, 8, 16, 'rows');
        $risk = $this->choice($round->input['risk'] ?? 'medium', ['low', 'medium', 'high'], 'risk');
        $rights = 0;
        $path = [];
        for ($row = 0; $row < $rows; ++$row) {
            $right = $this->random->int(0, 1) === 1;
            $rights += $right ? 1 : 0;
            $path[] = $right ? 'R' : 'L';
        }

        $expectedRaw = 0.0;
        for ($bin = 0; $bin <= $rows; ++$bin) {
            $binDistance = abs($bin - ($rows / 2)) / ($rows / 2);
            $expectedRaw += $this->binomialCoefficient($rows, $bin) / (2 ** $rows) * $this->plinkoRawMultiplier($binDistance, $risk);
        }
        $multipliersBps = [];
        for ($bin = 0; $bin <= $rows; ++$bin) {
            $binDistance = abs($bin - ($rows / 2)) / ($rows / 2);
            $multipliersBps[] = max(0, (int) round($this->plinkoRawMultiplier($binDistance, $risk) * (0.94 / $expectedRaw) * 10000));
        }
        $bps = $multipliersBps[$rights];

        return $this->complete('landed', ['bin' => $rights, 'rows' => $rows, 'risk' => $risk, 'path' => $path, 'multipliersBps' => $multipliersBps], $bps);
    }

    private function threeReelSlots(): GameOutcome
    {
        $strip = ['onion', 'lavender', 'crown', 'onion', 'book', 'onion', 'fireplace', 'lavender', 'crown', 'onion', 'book', 'lavender', 'onion', 'crown', 'seven'];
        $reels = [];
        for ($reel = 0; $reel < 3; ++$reel) {
            $reels[] = $strip[$this->random->int(0, count($strip) - 1)];
        }
        $paytable = ['onion' => 8, 'lavender' => 14, 'book' => 22, 'fireplace' => 33, 'crown' => 55, 'seven' => 136];
        $bps = count(array_unique($reels)) === 1 ? $paytable[$reels[0]] * 10000 : 0;

        return $this->complete($bps > 0 ? 'line_win' : 'no_win', ['reels' => $reels, 'winningLine' => $bps > 0 ? 0 : null], $bps);
    }

    private function fiveReelSlots(): GameOutcome
    {
        $symbols = ['onion', 'lavender', 'book', 'ember', 'moon', 'crown', 'wild', 'scatter'];
        $weights = [25, 22, 18, 14, 10, 6, 3, 2];
        $grid = [];
        for ($reel = 0; $reel < 5; ++$reel) {
            $column = [];
            for ($row = 0; $row < 3; ++$row) {
                $column[] = $this->weighted($symbols, $weights);
            }
            $grid[] = $column;
        }
        $lines = [[0, 0, 0, 0, 0], [1, 1, 1, 1, 1], [2, 2, 2, 2, 2], [0, 1, 2, 1, 0], [2, 1, 0, 1, 2]];
        $symbolPay = ['onion' => [3 => 1, 4 => 2, 5 => 5], 'lavender' => [3 => 1, 4 => 3, 5 => 7], 'book' => [3 => 2, 4 => 5, 5 => 12], 'ember' => [3 => 3, 4 => 8, 5 => 20], 'moon' => [3 => 4, 4 => 12, 5 => 30], 'crown' => [3 => 8, 4 => 25, 5 => 75]];
        $lineWins = [];
        $totalBps = 0;
        foreach ($lines as $lineIndex => $line) {
            $lineSymbols = [];
            foreach ($line as $reel => $row) {
                $lineSymbols[] = $grid[$reel][$row];
            }
            $base = null;
            foreach ($lineSymbols as $symbol) {
                if (!in_array($symbol, ['wild', 'scatter'], true)) {
                    $base = $symbol;
                    break;
                }
            }
            if ($base === null || !isset($symbolPay[$base])) {
                continue;
            }
            $count = 0;
            foreach ($lineSymbols as $symbol) {
                if ($symbol === $base || $symbol === 'wild') {
                    ++$count;
                } else {
                    break;
                }
            }
            if ($count >= 3) {
                $lineBps = (int) (($symbolPay[$base][$count] ?? 0) * 2000); // five equal-cost paylines
                $totalBps += $lineBps;
                $lineWins[] = ['line' => $lineIndex, 'symbol' => $base, 'count' => $count, 'multiplierBps' => $lineBps];
            }
        }
        $scatters = 0;
        foreach ($grid as $column) {
            $scatters += count(array_filter($column, static fn (string $symbol): bool => $symbol === 'scatter'));
        }
        if ($scatters >= 3) {
            $totalBps += [3 => 20000, 4 => 100000, 5 => 500000][$scatters] ?? 500000;
        }

        // The raw five-line table is normalized to its disclosed 94.8% model.
        $totalBps = intdiv($totalBps * 699, 100);
        return $this->complete($totalBps > 0 ? 'reel_win' : 'no_win', ['grid' => $grid, 'lineWins' => $lineWins, 'scatters' => $scatters], $totalBps);
    }

    private function roulette(GameRound $round): GameOutcome
    {
        $american = $round->slug === 'american-roulette';
        $pockets = $american ? array_merge(['0', '00'], array_map('strval', range(1, 36))) : array_map('strval', range(0, 36));
        $pocket = $pockets[$this->random->int(0, count($pockets) - 1)];
        $bet = $this->choice($round->input['bet'] ?? 'red', ['straight', 'red', 'black', 'odd', 'even', 'low', 'high', 'dozen1', 'dozen2', 'dozen3'], 'bet');
        $selection = (string) ($round->input['selection'] ?? '17');
        if ($bet === 'straight' && !in_array($selection, $pockets, true)) {
            throw new GameException('The selected roulette pocket does not exist.', 'invalid_option');
        }
        $reds = ['1','3','5','7','9','12','14','16','18','19','21','23','25','27','30','32','34','36'];
        $number = ctype_digit($pocket) ? (int) $pocket : 0;
        $win = match ($bet) {
            'straight' => $pocket === $selection,
            'red' => in_array($pocket, $reds, true),
            'black' => $number > 0 && !in_array($pocket, $reds, true),
            'odd' => $number > 0 && $number % 2 === 1,
            'even' => $number > 0 && $number % 2 === 0,
            'low' => $number >= 1 && $number <= 18,
            'high' => $number >= 19 && $number <= 36,
            'dozen1' => $number >= 1 && $number <= 12,
            'dozen2' => $number >= 13 && $number <= 24,
            'dozen3' => $number >= 25 && $number <= 36,
        };
        $bps = $win ? ($bet === 'straight' ? 360000 : (str_starts_with($bet, 'dozen') ? 30000 : 20000)) : 0;

        return $this->complete($win ? 'win' : 'loss', ['pocket' => $pocket, 'bet' => $bet, 'selection' => $selection, 'color' => in_array($pocket, $reds, true) ? 'red' : ($number > 0 ? 'black' : 'green')], $bps);
    }

    private function baccarat(GameRound $round): GameOutcome
    {
        $bet = $this->choice($round->input['bet'] ?? 'player', ['player', 'banker', 'tie'], 'bet');
        $deck = new Deck($this->random);
        $player = [$deck->draw(), $deck->draw()];
        $banker = [$deck->draw(), $deck->draw()];
        $playerTotal = $this->baccaratTotal($player);
        $bankerTotal = $this->baccaratTotal($banker);
        if ($playerTotal < 8 && $bankerTotal < 8) {
            $third = null;
            if ($playerTotal <= 5) {
                $third = $deck->draw();
                $player[] = $third;
                $playerTotal = $this->baccaratTotal($player);
            }
            $thirdValue = $third ? min($third->rank, 10) % 10 : null;
            $bankerDraws = $thirdValue === null
                ? $bankerTotal <= 5
                : match ($bankerTotal) { 0,1,2 => true, 3 => $thirdValue !== 8, 4 => $thirdValue >= 2 && $thirdValue <= 7, 5 => $thirdValue >= 4 && $thirdValue <= 7, 6 => $thirdValue === 6 || $thirdValue === 7, default => false };
            if ($bankerDraws) {
                $banker[] = $deck->draw();
                $bankerTotal = $this->baccaratTotal($banker);
            }
        }
        $winner = $playerTotal === $bankerTotal ? 'tie' : ($playerTotal > $bankerTotal ? 'player' : 'banker');
        $bps = $winner === 'tie' && $bet !== 'tie'
            ? 10000
            : ($winner !== $bet ? 0 : match ($winner) { 'tie' => 90000, 'banker' => 19500, default => 20000 });

        return $this->complete($winner, ['playerCards' => $this->publicCards($player), 'bankerCards' => $this->publicCards($banker), 'playerTotal' => $playerTotal, 'bankerTotal' => $bankerTotal, 'bet' => $bet], $bps);
    }

    private function threeCardPoker(GameRound $round): GameOutcome
    {
        $decision = $this->choice($round->input['decision'] ?? 'play', ['play', 'fold'], 'decision');
        $deck = new Deck($this->random);
        $player = $deck->drawMany(3);
        $dealer = $deck->drawMany(3);
        $playerRank = $this->threeCardRank($player);
        $dealerRank = $this->threeCardRank($dealer);
        $qualifies = $dealerRank['category'] > 0 || $dealerRank['kickers'][0] >= 12;
        $comparison = $playerRank['score'] <=> $dealerRank['score'];
        $bps = 0;
        if ($decision === 'play') {
            $bps = !$qualifies ? 15000 : ($comparison > 0 ? 20000 : ($comparison === 0 ? 10000 : 0));
            if ($comparison > 0 && $playerRank['category'] >= 3) {
                $bps += [3 => 10000, 4 => 30000, 5 => 40000][$playerRank['category']] ?? 0;
            }
        }

        return $this->complete($decision === 'fold' ? 'fold' : ($comparison > 0 ? 'win' : ($comparison === 0 ? 'push' : 'loss')), ['playerCards' => $this->publicCards($player), 'dealerCards' => $this->publicCards($dealer), 'playerHand' => $playerRank['name'], 'dealerHand' => $dealerRank['name'], 'dealerQualifies' => $qualifies], $bps);
    }

    private function paiGow(GameRound $round): GameOutcome
    {
        $deck = new Deck($this->random);
        $player = $deck->drawMany(7);
        $dealer = $deck->drawMany(7);
        [$playerHigh, $playerLow] = $this->paiGowArrange($player);
        [$dealerHigh, $dealerLow] = $this->paiGowArrange($dealer);
        $high = HandEvaluator::poker($playerHigh)['score'] <=> HandEvaluator::poker($dealerHigh)['score'];
        $low = $this->twoCardScore($playerLow) <=> $this->twoCardScore($dealerLow);
        $win = $high > 0 && $low > 0;
        $loss = $high < 0 && $low < 0;
        $bps = $win ? 19500 : ($loss ? 0 : 10000);

        return $this->complete($win ? 'win' : ($loss ? 'loss' : 'push'), [
            'playerHigh' => $this->publicCards($playerHigh), 'playerLow' => $this->publicCards($playerLow),
            'dealerHigh' => $this->publicCards($dealerHigh), 'dealerLow' => $this->publicCards($dealerLow),
            'highResult' => $high, 'lowResult' => $low,
        ], $bps);
    }

    private function teenPatti(): GameOutcome
    {
        $deck = new Deck($this->random);
        $player = $deck->drawMany(3);
        $bot = $deck->drawMany(3);
        $playerRank = $this->threeCardRank($player);
        $botRank = $this->threeCardRank($bot);
        $compare = $playerRank['score'] <=> $botRank['score'];

        return $this->complete($compare > 0 ? 'win' : ($compare === 0 ? 'push' : 'loss'), ['playerCards' => $this->publicCards($player), 'botCards' => $this->publicCards($bot), 'playerHand' => $playerRank['name'], 'botHand' => $botRank['name']], $compare > 0 ? 19500 : ($compare === 0 ? 10000 : 0));
    }

    private function sicBo(GameRound $round): GameOutcome
    {
        $bet = $this->choice($round->input['bet'] ?? 'small', ['small', 'big', 'odd', 'even', 'sum', 'triple'], 'bet');
        $selection = $this->integerOption($round->input['selection'] ?? ($bet === 'sum' ? 10 : 4), $bet === 'sum' ? 4 : 1, $bet === 'sum' ? 17 : 6, 'selection');
        $dice = [$this->random->int(1, 6), $this->random->int(1, 6), $this->random->int(1, 6)];
        $sum = array_sum($dice);
        $triple = count(array_unique($dice)) === 1;
        $win = match ($bet) {
            'small' => !$triple && $sum >= 4 && $sum <= 10,
            'big' => !$triple && $sum >= 11 && $sum <= 17,
            'odd' => !$triple && $sum % 2 === 1,
            'even' => !$triple && $sum % 2 === 0,
            'sum' => $sum === $selection,
            'triple' => $triple && $dice[0] === $selection,
        };
        $sumPays = [4 => 610000, 5 => 310000, 6 => 180000, 7 => 120000, 8 => 80000, 9 => 70000, 10 => 60000, 11 => 60000, 12 => 70000, 13 => 80000, 14 => 120000, 15 => 180000, 16 => 310000, 17 => 610000];
        $bps = !$win ? 0 : match ($bet) { 'sum' => $sumPays[$selection], 'triple' => 1810000, default => 20000 };

        return $this->complete($win ? 'win' : 'loss', ['dice' => $dice, 'sum' => $sum, 'triple' => $triple, 'bet' => $bet, 'selection' => $selection], $bps);
    }

    private function keno(GameRound $round): GameOutcome
    {
        $picks = $round->input['picks'] ?? [7, 14, 21, 28, 35];
        if (!is_array($picks) || count($picks) < 1 || count($picks) > 10) {
            throw new GameException('Choose between one and ten Keno numbers.', 'invalid_option');
        }
        $picks = array_values(array_unique(array_map(fn ($value): int => $this->integerOption($value, 1, 80, 'Keno number'), $picks)));
        if (count($picks) !== count($round->input['picks'] ?? [7, 14, 21, 28, 35])) {
            throw new GameException('Keno picks must be unique.', 'invalid_option');
        }
        $drawOrder = array_slice($this->random->shuffle(range(1, 80)), 0, 20);
        $drawn = $drawOrder;
        sort($drawn, SORT_NUMERIC);
        $matches = array_values(array_intersect($picks, $drawn));
        $count = count($matches);
        $spots = count($picks);
        $payouts = [
            1 => [1 => 35000], 2 => [2 => 120000], 3 => [2 => 20000, 3 => 430000],
            4 => [2 => 10000, 3 => 50000, 4 => 1000000], 5 => [3 => 20000, 4 => 150000, 5 => 3000000],
            6 => [3 => 10000, 4 => 50000, 5 => 700000, 6 => 10000000], 7 => [4 => 20000, 5 => 150000, 6 => 2000000, 7 => 20000000],
            8 => [5 => 50000, 6 => 500000, 7 => 5000000, 8 => 50000000], 9 => [5 => 20000, 6 => 200000, 7 => 1500000, 8 => 10000000, 9 => 100000000],
            10 => [5 => 10000, 6 => 100000, 7 => 500000, 8 => 3000000, 9 => 20000000, 10 => 200000000],
        ];
        $bps = intdiv(($payouts[$spots][$count] ?? 0) * 164, 100);

        return $this->complete($bps > 0 ? 'win' : 'loss', ['picks' => $picks, 'drawn' => $drawn, 'drawOrder' => $drawOrder, 'matches' => $matches, 'matchCount' => $count], $bps);
    }

    private function bingo(): GameOutcome
    {
        $card = [];
        for ($column = 0; $column < 5; ++$column) {
            $numbers = array_slice($this->random->shuffle(range($column * 15 + 1, $column * 15 + 15)), 0, 5);
            for ($row = 0; $row < 5; ++$row) {
                $card[$row][$column] = $column === 2 && $row === 2 ? 0 : $numbers[$row];
            }
        }
        $drawn = array_slice($this->random->shuffle(range(1, 75)), 0, 30);
        $marked = [];
        foreach ($card as $row => $values) {
            foreach ($values as $column => $value) {
                $marked[$row][$column] = $value === 0 || in_array($value, $drawn, true);
            }
        }
        $lines = 0;
        for ($i = 0; $i < 5; ++$i) {
            $lines += count(array_filter($marked[$i])) === 5 ? 1 : 0;
            $columnMarks = array_column($marked, $i);
            $lines += count(array_filter($columnMarks)) === 5 ? 1 : 0;
        }
        $lines += count(array_filter([0,1,2,3,4], static fn (int $i): bool => $marked[$i][$i])) === 5 ? 1 : 0;
        $lines += count(array_filter([0,1,2,3,4], static fn (int $i): bool => $marked[$i][4-$i])) === 5 ? 1 : 0;
        $bps = match (true) { $lines >= 3 => 1110000, $lines === 2 => 222000, $lines === 1 => 44400, default => 0 };

        return $this->complete($lines > 0 ? 'bingo' : 'no_bingo', ['card' => $card, 'drawn' => $drawn, 'marked' => $marked, 'lines' => $lines], $bps);
    }

    private function overUnder(GameRound $round): GameOutcome
    {
        $choice = $this->choice($round->input['choice'] ?? 'under', ['under', 'over'], 'choice');
        $target = $this->integerOption($round->input['target'] ?? 50, 2, 98, 'target');
        $roll = $this->random->int(0, 9999);
        $win = $choice === 'under' ? $roll < $target * 100 : $roll >= $target * 100;
        $winningUnits = $choice === 'under' ? $target : 100 - $target;
        $bps = $win ? intdiv(980000, $winningUnits) : 0;

        return $this->complete($win ? 'win' : 'loss', ['roll' => $roll / 100, 'rollBasisPoints' => $roll, 'target' => $target, 'choice' => $choice], $bps);
    }

    private function coinFlip(GameRound $round): GameOutcome
    {
        $guess = $this->choice($round->input['guess'] ?? 'heads', ['heads', 'tails'], 'guess');
        $result = $this->random->int(0, 1) === 0 ? 'heads' : 'tails';

        return $this->complete($result === $guess ? 'win' : 'loss', ['side' => $result, 'guess' => $guess], $result === $guess ? 19800 : 0);
    }

    private function prizeWheel(): GameOutcome
    {
        $segments = [0, 2500, 7500, 7500, 10000, 15000, 0, 20000, 2500, 7500, 0, 25000, 5000, 10000, 0, 40000];
        $index = $this->random->int(0, count($segments) - 1);

        return $this->complete($segments[$index] > 0 ? 'prize' : 'no_prize', ['segment' => $index, 'segments' => count($segments), 'label' => $segments[$index] / 10000 . 'x', 'segmentMultipliersBps' => $segments], $segments[$index]);
    }

    private function numberDraw(GameRound $round): GameOutcome
    {
        $mode = $this->choice($round->input['mode'] ?? 'exact', ['exact', 'last_digit', 'range'], 'mode');
        $guess = $this->integerOption($round->input['guess'] ?? 17, 0, $mode === 'last_digit' ? 9 : 99, 'guess');
        $number = $this->random->int(0, 99);
        $win = match ($mode) { 'exact' => $number === $guess, 'last_digit' => $number % 10 === $guess, 'range' => intdiv($number, 10) === intdiv($guess, 10) };
        $bps = $win ? ($mode === 'exact' ? 950000 : 95000) : 0;

        return $this->complete($win ? 'win' : 'loss', ['number' => $number, 'guess' => $guess, 'mode' => $mode], $bps);
    }

    private function pachinko(): GameOutcome
    {
        $rights = 0;
        $path = [];
        for ($pin = 0; $pin < 15; ++$pin) {
            $right = $this->random->int(0, 1) === 1;
            $rights += $right ? 1 : 0;
            $path[] = $right ? 1 : -1;
        }
        $multipliers = [500000, 200000, 80000, 40000, 20000, 12000, 7000, 4300, 4300, 7000, 12000, 20000, 40000, 80000, 200000, 500000];

        return $this->complete('landed', ['slot' => $rights, 'path' => $path, 'multipliersBps' => $multipliers], $multipliers[$rights]);
    }

    private function horseRace(GameRound $round): GameOutcome
    {
        $horses = ['Lavender Bolt', 'Golden Onion', 'Velvet Comet', 'Cozy Ember', 'Moonlit Crown', 'Royal Whisker'];
        $weights = [25, 22, 19, 15, 11, 8];
        $pick = $this->integerOption($round->input['horse'] ?? 0, 0, 5, 'horse');
        $winnerName = $this->weighted($horses, $weights);
        $winner = array_search($winnerName, $horses, true);
        $finish = $this->random->shuffle(array_keys($horses));
        $winnerIndex = array_search($winner, $finish, true);
        if ($winnerIndex !== false) {
            unset($finish[$winnerIndex]);
            array_unshift($finish, $winner);
            $finish = array_values($finish);
        }
        $win = $pick === $winner;
        $bps = $win ? intdiv(950000, $weights[$winner]) : 0;

        return $this->complete($win ? 'win' : 'loss', ['pick' => $pick, 'winner' => $winner, 'finish' => $finish, 'horses' => $horses], $bps);
    }

    private function scratchCard(): GameOutcome
    {
        $symbols = ['onion', 'crown', 'teacup', 'book', 'ember', 'star'];
        $grid = [];
        for ($index = 0; $index < 9; ++$index) {
            $grid[] = $symbols[$this->random->int(0, count($symbols) - 1)];
        }
        $counts = array_count_values($grid);
        arsort($counts);
        $bestSymbol = (string) array_key_first($counts);
        $matches = (int) reset($counts);
        $points = match (true) { $matches >= 5 => 100, $matches === 4 => 40, $matches === 3 => 10, default => 0 };

        return $this->complete($points > 0 ? 'match' : 'no_match', ['grid' => $grid, 'matchedSymbol' => $bestSymbol, 'matches' => $matches, 'practicePoints' => $points], 0);
    }

    private function gemDrop(): GameOutcome
    {
        $gems = ['amethyst', 'ruby', 'topaz', 'pearl', 'emerald'];
        $grid = [];
        for ($row = 0; $row < 6; ++$row) {
            for ($column = 0; $column < 6; ++$column) {
                $grid[$row][$column] = $gems[$this->random->int(0, 4)];
            }
        }
        $clusters = [];
        for ($row = 0; $row < 6; ++$row) {
            $run = 1;
            for ($column = 1; $column <= 6; ++$column) {
                if ($column < 6 && $grid[$row][$column] === $grid[$row][$column - 1]) {
                    ++$run;
                } else {
                    if ($run >= 3) {
                        $clusters[] = ['row' => $row, 'endColumn' => $column - 1, 'length' => $run, 'gem' => $grid[$row][$column - 1]];
                    }
                    $run = 1;
                }
            }
        }
        $bps = array_sum(array_map(static fn (array $cluster): int => intdiv(($cluster['length'] - 2) * 5000 * 193, 100), $clusters));

        return $this->complete($bps > 0 ? 'cluster_win' : 'no_cluster', ['grid' => $grid, 'clusters' => $clusters], $bps);
    }

    private function luckyCups(GameRound $round): GameOutcome
    {
        $guess = $this->integerOption($round->input['cup'] ?? 1, 1, 3, 'cup');
        $winner = $this->random->int(1, 3);
        $shuffle = $this->random->shuffle([1, 2, 3]);

        return $this->complete($guess === $winner ? 'found' : 'missed', ['guess' => $guess, 'winner' => $winner, 'shuffleAnimation' => $shuffle], $guess === $winner ? 28500 : 0);
    }

    /** @param list<string> $values @param list<int> $weights */
    private function weighted(array $values, array $weights): string
    {
        $roll = $this->random->int(1, array_sum($weights));
        foreach ($weights as $index => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $values[$index];
            }
        }

        return $values[array_key_last($values)];
    }

    private function plinkoRawMultiplier(float $distance, string $risk): float
    {
        return match ($risk) {
            'low' => 0.55 + 2.45 * ($distance ** 2.4),
            'high' => 0.08 + 14.92 * ($distance ** 4.2),
            default => 0.3 + 5.7 * ($distance ** 3.1),
        };
    }

    private function binomialCoefficient(int $n, int $k): int
    {
        $k = min($k, $n - $k); $value = 1;
        for ($index = 1; $index <= $k; ++$index) $value = intdiv($value * ($n - $k + $index), $index);
        return $value;
    }

    /** @param list<\PurpleParlor\Games\Cards\Card> $cards */
    private function baccaratTotal(array $cards): int
    {
        return array_sum(array_map(static fn ($card): int => $card->rank === 14 ? 1 : ($card->rank >= 10 ? 0 : $card->rank), $cards)) % 10;
    }

    /** @param list<\PurpleParlor\Games\Cards\Card> $cards @return array{name:string,category:int,score:int,kickers:list<int>} */
    private function threeCardRank(array $cards): array
    {
        $ranks = array_map(static fn ($card): int => $card->rank, $cards);
        rsort($ranks, SORT_NUMERIC);
        $counts = array_count_values($ranks);
        arsort($counts);
        $flush = count(array_unique(array_map(static fn ($card): string => $card->suit, $cards))) === 1;
        $unique = array_values(array_unique($ranks));
        if ($unique === [14, 3, 2]) {
            $straightHigh = 3;
        } else {
            $straightHigh = count($unique) === 3 && $unique[0] - $unique[2] === 2 ? $unique[0] : 0;
        }
        $topCount = (int) reset($counts);
        if ($topCount === 3) {
            $category = 5; $name = 'Trail'; $kickers = [(int) array_key_first($counts)];
        } elseif ($straightHigh && $flush) {
            $category = 4; $name = 'Pure Sequence'; $kickers = [$straightHigh];
        } elseif ($straightHigh) {
            $category = 3; $name = 'Sequence'; $kickers = [$straightHigh];
        } elseif ($flush) {
            $category = 2; $name = 'Color'; $kickers = $ranks;
        } elseif ($topCount === 2) {
            $category = 1; $name = 'Pair'; $pair = (int) array_key_first($counts); $kickers = [$pair, (int) array_key_last($counts)];
        } else {
            $category = 0; $name = 'High Card'; $kickers = $ranks;
        }
        $score = $category;
        foreach (array_pad($kickers, 3, 0) as $rank) {
            $score = $score * 15 + $rank;
        }

        return compact('name', 'category', 'score', 'kickers');
    }

    /** @param list<\PurpleParlor\Games\Cards\Card> $cards @return array{0:list<\PurpleParlor\Games\Cards\Card>,1:list<\PurpleParlor\Games\Cards\Card>} */
    private function paiGowArrange(array $cards): array
    {
        $bestHigh = null;
        $bestLow = null;
        $bestHighScore = -1;
        // Deterministic house-way approximation: choose the strongest five-card
        // hand whose remaining two-card hand is still valid (low <= high).
        foreach ($this->fiveCardCombinations($cards) as $high) {
            $codes = $this->cardCodes($high);
            $low = array_values(array_filter($cards, static fn ($card): bool => !in_array($card->code(), $codes, true)));
            $highEval = HandEvaluator::poker($high);
            if ($this->twoCardScore($low) <= $highEval['score'] && $highEval['score'] > $bestHighScore) {
                $bestHigh = $high; $bestLow = $low; $bestHighScore = $highEval['score'];
            }
        }

        return [$bestHigh ?? array_slice($cards, 0, 5), $bestLow ?? array_slice($cards, 5, 2)];
    }

    /** @param list<\PurpleParlor\Games\Cards\Card> $cards */
    private function twoCardScore(array $cards): int
    {
        $ranks = array_map(static fn ($card): int => $card->rank, $cards);
        rsort($ranks, SORT_NUMERIC);
        return $ranks[0] === $ranks[1] ? 10000 + $ranks[0] : $ranks[0] * 15 + $ranks[1];
    }

    /** @param list<\PurpleParlor\Games\Cards\Card> $cards @return list<list<\PurpleParlor\Games\Cards\Card>> */
    private function fiveCardCombinations(array $cards): array
    {
        $result = [];
        for ($a = 0; $a < 3; ++$a) for ($b = $a + 1; $b < 4; ++$b) for ($c = $b + 1; $c < 5; ++$c) for ($d = $c + 1; $d < 6; ++$d) for ($e = $d + 1; $e < 7; ++$e) {
            $result[] = [$cards[$a], $cards[$b], $cards[$c], $cards[$d], $cards[$e]];
        }
        return $result;
    }

    /** @param array<string, mixed> $result */
    private function complete(string $code, array $result, int $multiplierBps): GameOutcome
    {
        $result['payoutMultiplierBps'] = max(0, $multiplierBps);
        return new GameOutcome($code, $result, $result, [], [], [], true);
    }
}
