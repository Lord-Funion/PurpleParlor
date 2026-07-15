<?php

declare(strict_types=1);

use PurpleParlor\Games\Cards\Card;
use PurpleParlor\Games\Cards\Deck;
use PurpleParlor\Games\Cards\HandEvaluator;
use PurpleParlor\Games\CatalogGame;
use PurpleParlor\Games\Contracts\RandomSource;
use PurpleParlor\Games\DTO\GameRequest;
use PurpleParlor\Games\DTO\PlayerContext;
use PurpleParlor\Games\Exceptions\GameException;
use PurpleParlor\Games\Fairness\SeedCommitment;
use PurpleParlor\Games\Fairness\SeedVerifier;
use PurpleParlor\Games\GameEngine;
use PurpleParlor\Games\GameRegistry;
use PurpleParlor\Games\Random\SeededRandomSource;
use PurpleParlor\Games\Security\OutcomeSigner;

if (!function_exists('run_game_tests')) {
    function run_game_tests(): int
    {
        game_tests_autoload();
        $root = dirname(__DIR__, 2);
        $configuration = require $root . '/config/games.php';
        $player = new PlayerContext('test-player', 1000000, true);

        $tests = [];
        $tests['catalog contains exactly 40 unique launchable slugs'] = static function () use ($configuration): void {
            game_assert_same(40, count($configuration));
            game_assert_same(40, count(array_unique(array_column($configuration, 'slug'))));
            foreach ($configuration as $slug => $game) {
                game_assert_same($slug, $game['slug']);
                foreach (['name','category','description','rules','tutorial','wager','paytable','probability','theoretical_rtp','configuration_schema','client_renderer','animation_controller','sound_manifest','statistics','simulation'] as $field) {
                    game_assert(array_key_exists($field, $game), "{$slug} is missing {$field}");
                }
            }
        };

        $tests['every catalog game launches through its server strategy'] = static function () use ($configuration, $player): void {
            foreach ($configuration as $slug => $config) {
                $game = new CatalogGame($config, new SeededRandomSource('launch-test|' . $slug));
                $wager = (int) $config['wager']['minimum'];
                $request = new GameRequest('play', $wager, game_default_options($slug), 'test:launch:' . substr(hash('sha256', $slug), 0, 32));
                $round = $game->createRound($request, $player);
                $outcome = $game->calculateOutcome($round);
                game_assert($outcome->complete || $outcome->nextActions !== [], "{$slug} returned an unplayable state");
                game_assert($outcome->complete || $outcome->serverState() !== [], "{$slug} did not preserve its server state");
            }
        };

        $tests['deterministic RNG is reproducible and bounded'] = static function (): void {
            $first = new SeededRandomSource('same-seed'); $second = new SeededRandomSource('same-seed');
            $a = []; $b = [];
            for ($i = 0; $i < 100; ++$i) { $a[] = $first->int(7, 19); $b[] = $second->int(7, 19); }
            game_assert_same($a, $b);
            game_assert(min($a) >= 7 && max($a) <= 19);
            game_assert(count(array_unique($a)) > 8, 'Seeded stream did not exercise enough values');
        };

        $tests['seed commitment and published test vector reproduce'] = static function (): void {
            $vector = SeedVerifier::testVector();
            game_assert_same($vector['serverSeedHash'], SeedCommitment::commit($vector['serverSeed']));
            game_assert(SeedVerifier::commitmentMatches($vector['serverSeedHash'], $vector['serverSeed']));
            game_assert(!SeedVerifier::commitmentMatches($vector['serverSeedHash'], $vector['serverSeed'] . '-tampered'));
            $values = SeedVerifier::reproduce($vector['serverSeedHash'], $vector['serverSeed'], $vector['clientSeed'], $vector['nonce'], $vector['range'][0], $vector['range'][1], 10);
            game_assert_same($vector['firstTen'], $values);
        };

        $tests['standard deck contains 52 unique cards'] = static function (): void {
            $deck = new Deck(new SeededRandomSource('deck-test'));
            $codes = $deck->remainingCodes();
            game_assert_same(52, count($codes));
            game_assert_same(52, count(array_unique($codes)));
            game_assert_same(4, count(array_unique(array_map(static fn (string $code): string => substr($code, -1), $codes))));
        };

        $tests['blackjack ace totals and natural recognition are correct'] = static function (): void {
            $soft21 = HandEvaluator::blackjack([Card::fromCode('AS'), Card::fromCode('AH'), Card::fromCode('9C')]);
            game_assert_same(21, $soft21['total']); game_assert($soft21['soft']); game_assert(!$soft21['blackjack']);
            $natural = HandEvaluator::blackjack([Card::fromCode('AS'), Card::fromCode('KH')]);
            game_assert_same(21, $natural['total']); game_assert($natural['blackjack']);
            $bust = HandEvaluator::blackjack([Card::fromCode('KS'), Card::fromCode('QH'), Card::fromCode('2C')]);
            game_assert($bust['bust']);
        };

        $tests['poker hand ranking orders all major categories'] = static function (): void {
            $hands = [
                ['2C','5D','7H','9S','JC'], ['9C','9D','3H','5S','7C'], ['9C','9D','3H','3S','7C'],
                ['9C','9D','9H','5S','7C'], ['2C','3D','4H','5S','6C'], ['2C','5C','7C','9C','JC'],
                ['9C','9D','9H','5S','5C'], ['9C','9D','9H','9S','7C'], ['10H','JH','QH','KH','AH'],
            ];
            $scores = array_map(static fn (array $codes): int => HandEvaluator::poker(array_map([Card::class, 'fromCode'], $codes))['score'], $hands);
            $sorted = $scores; sort($sorted, SORT_NUMERIC); game_assert_same($sorted, $scores);
            game_assert_same('Royal Flush', HandEvaluator::poker(array_map([Card::class, 'fromCode'], $hands[8]))['name']);
            game_assert_same('Straight', HandEvaluator::poker(array_map([Card::class, 'fromCode'], ['AS','2D','3H','4C','5S']))['name']);
        };

        $tests['wager validation rejects range increments and insufficient balance'] = static function () use ($configuration): void {
            $game = new CatalogGame($configuration['coin-flip'], new SeededRandomSource('wager'));
            $bad = new GameRequest('play', 1001, [], 'test:wager:outside:0001');
            game_assert(!$game->validateWager($bad)->isValid());
            game_expect_exception(static fn () => $game->createRound(new GameRequest('play', 10, [], 'test:wager:balance:0001'), new PlayerContext('poor', 9)), GameException::class, 'insufficient_virtual_balance');
        };

        $tests['European roulette red wager pays exactly two times'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['european-roulette'], new GameQueueRandom([1]));
            [$round, $outcome] = game_start($game, $player, 10, ['bet' => 'red']);
            game_assert_same('win', $outcome->code); game_assert_same('1', $outcome->result['pocket']); game_assert_same(20, $game->calculatePayout($round, $outcome));
        };

        $tests['American double-zero exists and reduces outside-wager return'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['american-roulette'], new GameQueueRandom([1]));
            [$round, $outcome] = game_start($game, $player, 10, ['bet' => 'red']);
            game_assert_same('00', $outcome->result['pocket']); game_assert_same(0, $game->calculatePayout($round, $outcome));
        };

        $tests['classic slot matching payline uses disclosed onion payout'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['classic-three-reel-slots'], new GameQueueRandom([0,0,0]));
            [$round, $outcome] = game_start($game, $player, 10);
            game_assert_same(['onion','onion','onion'], $outcome->result['reels']); game_assert_same(80, $game->calculatePayout($round, $outcome));
        };

        $tests['Plinko path and bin are calculated only from server bounces'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['plinko'], new GameQueueRandom(array_fill(0, 8, 1)));
            [$round, $outcome] = game_start($game, $player, 100, ['rows' => 8, 'risk' => 'medium']);
            game_assert_same(8, $outcome->result['bin']); game_assert_same(array_fill(0, 8, 'R'), $outcome->result['path']);
            game_assert_same(9, count($outcome->result['multipliersBps']));
            game_assert_same($outcome->result['payoutMultiplierBps'], $outcome->result['multipliersBps'][8], 'The animated winning bin must use the authoritative payout multiplier.');
            game_assert($game->calculatePayout($round, $outcome) > 100);
        };

        $tests['mechanical games disclose the exact ordered visual multiplier strips'] = static function () use ($configuration, $player): void {
            $wheel = new CatalogGame($configuration['prize-wheel'], new GameQueueRandom([3]));
            [, $wheelOutcome] = game_start($wheel, $player, 10);
            game_assert_same(16, count($wheelOutcome->result['segmentMultipliersBps']));
            game_assert_same($wheelOutcome->result['payoutMultiplierBps'], $wheelOutcome->result['segmentMultipliersBps'][$wheelOutcome->result['segment']]);

            $pachinko = new CatalogGame($configuration['pachinko'], new GameQueueRandom(array_fill(0, 15, 1)));
            [, $pachinkoOutcome] = game_start($pachinko, $player, 10);
            game_assert_same(16, count($pachinkoOutcome->result['multipliersBps']));
            game_assert_same($pachinkoOutcome->result['payoutMultiplierBps'], $pachinkoOutcome->result['multipliersBps'][$pachinkoOutcome->result['slot']]);
        };

        $tests['Over Under D100 boundary and probability-adjusted payout are exact'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['over-under-dice'], new GameQueueRandom([4999]));
            [$round, $outcome] = game_start($game, $player, 100, ['choice' => 'under', 'target' => 50]);
            game_assert_same('win', $outcome->code); game_assert_same(49.99, $outcome->result['roll']); game_assert_same(196, $game->calculatePayout($round, $outcome));
        };

        $tests['craps point transition persists and resolves'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['craps'], new GameQueueRandom([2,2,2,2]));
            [$round, $first] = game_start($game, $player, 10);
            game_assert(!$first->complete); game_assert_same(4, $first->serverState()['point']); game_assert_same(['roll'], $first->nextActions);
            $request = new GameRequest('roll', 10, [], 'test:craps:point:000002', 'test', $round->id, $first->serverState());
            $nextRound = $game->createRound($request, $player); $final = $game->calculateOutcome($nextRound);
            game_assert($final->complete); game_assert_same('point_made', $final->code); game_assert_same(20, $game->calculatePayout($nextRound, $final));
        };

        $tests['baccarat fixed drawing table deals player third card correctly'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['baccarat'], new GamePrefixCardRandom(['2C','3C','4C','3D','2D']));
            [$round, $outcome] = game_start($game, $player, 1, ['bet' => 'banker']);
            game_assert_same(3, count($outcome->result['playerCards']));
            game_assert_same(2, count($outcome->result['bankerCards']));
            game_assert_same(7, $outcome->result['playerTotal']);
            game_assert_same(7, $outcome->result['bankerTotal']);
            game_assert_same(1, $game->calculatePayout($round, $outcome), 'Player/Banker wagers must push when Baccarat ties');
        };

        $tests['video poker hold-all straight flush pays disclosed amount'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['video-poker'], new GameQueueRandom([], true));
            [$round, $deal] = game_start($game, $player, 1, ['variant' => 'jacks_or_better']);
            game_assert(!$deal->complete); game_assert_same(['draw'], $deal->nextActions);
            $request = new GameRequest('draw', 1, ['holds' => [0,1,2,3,4]], 'test:video:poker:000002', 'test', $round->id, $deal->serverState());
            $drawRound = $game->createRound($request, $player); $outcome = $game->calculateOutcome($drawRound);
            game_assert_same('Straight Flush', $outcome->result['hand']); game_assert_same(50, $game->calculatePayout($drawRound, $outcome));
        };

        $tests['Keno draws unique numbers and calculates five-spot matches'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['keno'], new GameQueueRandom([], true));
            [$round, $outcome] = game_start($game, $player, 1, ['picks' => [1,2,3,4,5]]);
            game_assert_same(20, count(array_unique($outcome->result['drawn']))); game_assert_same(5, $outcome->result['matchCount']); game_assert_same(492, $game->calculatePayout($round, $outcome));
            game_assert_same(20, count(array_unique($outcome->result['drawOrder'])));
            $sortedOrder = $outcome->result['drawOrder']; sort($sortedOrder, SORT_NUMERIC);
            game_assert_same($outcome->result['drawn'], $sortedOrder, 'The animated Keno call order must contain exactly the authoritative drawn numbers.');
            $maximumGame = new CatalogGame($configuration['keno'], new GameQueueRandom([], true));
            [$maximumRound, $maximumOutcome] = game_start($maximumGame, $player, 1, ['picks' => range(1, 10)]);
            game_assert_same(32800, $maximumGame->calculatePayout($maximumRound, $maximumOutcome));
        };

        $tests['Sic Bo triples correctly lose a Small wager'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['sic-bo'], new GameQueueRandom([1,1,1]));
            [$round, $outcome] = game_start($game, $player, 10, ['bet' => 'small', 'selection' => 4]);
            game_assert($outcome->result['triple']); game_assert_same('loss', $outcome->code); game_assert_same(0, $game->calculatePayout($round, $outcome));
        };

        $tests['Hi-Lo ties return the fictional wager'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['hi-lo'], new GamePrefixCardRandom(['5C','5D']));
            [$round, $deal] = game_start($game, $player, 10);
            $request = new GameRequest('higher', 10, [], 'test:hilo:tie:000002', 'test', $round->id, $deal->serverState());
            $nextRound = $game->createRound($request, $player); $outcome = $game->calculateOutcome($nextRound);
            game_assert_same('tie', $outcome->code); game_assert_same(10, $game->calculatePayout($nextRound, $outcome));
        };

        $tests['Mines hidden positions never serialize to public JSON'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['mines'], new SeededRandomSource('mines-hidden'));
            [$round, $outcome] = game_start($game, $player, 10, ['mines' => 5]);
            game_assert(isset($outcome->serverState()['mines']));
            $public = json_encode($outcome, JSON_THROW_ON_ERROR);
            game_assert(!str_contains($public, '"mines":'), 'Hidden mine positions leaked into JSON');
            game_assert_same($round->id, $outcome->serverState()['roundId']);
        };

        $tests['Mines validates duplicate tile actions and cashout transition'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['mines'], new GamePrefixPositionRandom([24,23,22]));
            [$round, $start] = game_start($game, $player, 10, ['mines' => 3]);
            $revealRequest = new GameRequest('reveal', 10, ['tile' => 0], 'test:mines:reveal:0001', 'test', $round->id, $start->serverState());
            $revealRound = $game->createRound($revealRequest, $player); $safe = $game->calculateOutcome($revealRound);
            game_assert_same('safe', $safe->code); game_assert(in_array('cashout', $safe->nextActions, true));
            $duplicate = new GameRequest('reveal', 10, ['tile' => 0], 'test:mines:reveal:0002', 'test', $round->id, $safe->serverState());
            game_expect_exception(static fn () => $game->calculateOutcome($game->createRound($duplicate, $player)), GameException::class, 'duplicate_action');
            $cashout = new GameRequest('cashout', 10, [], 'test:mines:cashout:001', 'test', $round->id, $safe->serverState());
            $cashRound = $game->createRound($cashout, $player); $final = $game->calculateOutcome($cashRound);
            game_assert($final->complete); game_assert($game->calculatePayout($cashRound, $final) >= 10);
        };

        $tests['invalid stateful actions fail without producing a payout'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['craps'], new GameQueueRandom([2,2]));
            [$round, $outcome] = game_start($game, $player, 10);
            game_assert(!$outcome->complete);
            $invalid = new GameRequest('dance', 10, [], 'test:craps:invalid:0001', 'test', $round->id, $outcome->serverState());
            game_expect_exception(static fn () => $game->calculateOutcome($game->createRound($invalid, $player)), GameException::class, 'invalid_game_action');
        };

        $tests['Pyramid Solitaire treats Ace as one in a legal Queen-Ace pair'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['pyramid-solitaire'], new GameQueueRandom([], true));
            [$round, $deal] = game_start($game, $player, 0);
            $remove = new GameRequest('remove', 0, ['indices' => [23,25]], 'test:pyramid:remove:0001', 'test', $round->id, $deal->serverState());
            $removeRound = $game->createRound($remove, $player); $outcome = $game->calculateOutcome($removeRound);
            game_assert_same('removed', $outcome->code); game_assert_same([23,25], $outcome->result['indices']);
        };

        $tests['solitaire wins publish each actual post-move board without retaining private state'] = static function () use ($configuration, $player): void {
            $rankCodes = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];
            $foundation = static fn (string $suit, int $cards = 13): array => array_map(
                static fn (string $rank): string => $rank . $suit,
                array_slice($rankCodes, 0, $cards),
            );
            $foundations = [
                'clubs' => $foundation('C'),
                'diamonds' => $foundation('D'),
                'hearts' => $foundation('H'),
                'spades' => $foundation('S', 12),
            ];
            $resolve = static function (string $slug, string $action, array $options, array $state) use ($configuration, $player): \PurpleParlor\Games\DTO\GameOutcome {
                $game = new CatalogGame($configuration[$slug], new GameQueueRandom([], true));
                $request = new GameRequest($action, 0, $options, 'test:solitaire:win:' . $slug, 'test', $state['roundId'], $state);
                $round = $game->createRound($request, $player);
                return $game->calculateOutcome($round);
            };

            $klondike = $resolve('klondike-solitaire', 'move', ['from' => 'tableau', 'to' => 'foundation', 'sourceColumn' => 0, 'destination' => 3], [
                'slug' => 'klondike-solitaire', 'roundId' => 'rnd_klondike_win', 'wager' => 0, 'version' => 1,
                'tableau' => [['KS'], [], [], [], [], [], []], 'faceUp' => [1,1,1,1,1,1,1], 'stock' => [], 'waste' => [],
                'foundations' => $foundations, 'moves' => 51,
            ]);
            game_assert($klondike->complete); game_assert_same('solved', $klondike->code);
            game_assert_same(array_fill(0, 7, []), $klondike->publicState['tableau']);
            game_assert_same(['clubs' => 13, 'diamonds' => 13, 'hearts' => 13, 'spades' => 13], $klondike->publicState['foundations']);
            game_assert_same(52, $klondike->publicState['moves']);

            $pyramidTableau = array_fill(0, 28, '2C'); $pyramidTableau[27] = 'KS';
            $pyramid = $resolve('pyramid-solitaire', 'remove', ['indices' => [27]], [
                'slug' => 'pyramid-solitaire', 'roundId' => 'rnd_pyramid_win', 'wager' => 0, 'version' => 1,
                'tableau' => $pyramidTableau, 'removed' => range(0, 26), 'stock' => ['QD'], 'waste' => ['5C'], 'moves' => 27,
            ]);
            game_assert($pyramid->complete); game_assert_same('pyramid_cleared', $pyramid->code);
            game_assert_same(array_fill(0, 28, null), $pyramid->publicState['tableau']);
            game_assert_same(range(0, 27), $pyramid->publicState['removed']);
            game_assert_same(1, $pyramid->publicState['stockCount']);

            $tripeaksTableau = array_fill(0, 28, '2C'); $tripeaksTableau[27] = '6S';
            $tripeaks = $resolve('tripeaks-solitaire', 'take', ['index' => 27], [
                'slug' => 'tripeaks-solitaire', 'roundId' => 'rnd_tripeaks_win', 'wager' => 0, 'version' => 1,
                'tableau' => $tripeaksTableau, 'removed' => range(0, 26), 'stock' => ['QD'], 'waste' => ['5H'], 'moves' => 27,
            ]);
            game_assert($tripeaks->complete); game_assert_same('peaks_cleared', $tripeaks->code);
            game_assert_same(array_fill(0, 28, null), $tripeaks->publicState['tableau']);
            game_assert_same('6S', $tripeaks->publicState['wasteTop']['code']);
            game_assert_same(range(0, 27), $tripeaks->publicState['removed']);

            $freecell = $resolve('freecell', 'move', ['from' => 'column', 'to' => 'foundation', 'fromIndex' => 0, 'toIndex' => 3], [
                'slug' => 'freecell', 'roundId' => 'rnd_freecell_win', 'wager' => 0, 'version' => 1,
                'columns' => [['KS'], [], [], [], [], [], [], []], 'freecells' => [null, null, null, null],
                'foundations' => $foundations, 'moves' => 51,
            ]);
            game_assert($freecell->complete); game_assert_same('freecell_solved', $freecell->code);
            game_assert_same(array_fill(0, 8, []), $freecell->publicState['columns']);
            game_assert_same(['clubs' => 13, 'diamonds' => 13, 'hearts' => 13, 'spades' => 13], $freecell->publicState['foundations']);

            foreach ([$klondike, $pyramid, $tripeaks, $freecell] as $outcome) {
                game_assert_same([], $outcome->serverState(), 'A completed solitaire round must not retain private server state.');
                game_assert($outcome->publicState !== [], 'A completed solitaire round must publish its post-move board.');
                game_assert(!str_contains(json_encode($outcome, JSON_THROW_ON_ERROR), '"QD"'), 'A hidden stock card leaked into a completed solitaire payload.');
            }
        };

        $tests['round state cannot be replayed under a different round id'] = static function () use ($configuration, $player): void {
            $game = new CatalogGame($configuration['blackjack'], new SeededRandomSource('state-mismatch'));
            [$round, $outcome] = game_start($game, $player, 10);
            if ($outcome->complete) return;
            $request = new GameRequest('stand', 10, [], 'test:state:mismatch:0001', 'test', 'rnd_wrong_identifier', $outcome->serverState());
            game_expect_exception(static fn () => $game->calculateOutcome($game->createRound($request, $player)), GameException::class, 'state_conflict');
        };

        $tests['outcome signer is canonical and detects tampering'] = static function (): void {
            $signer = new OutcomeSigner(str_repeat('k', 32));
            $first = ['roundId' => 'r', 'result' => ['b' => 2, 'a' => 1]];
            $second = ['result' => ['a' => 1, 'b' => 2], 'roundId' => 'r'];
            $signature = $signer->sign($first); game_assert_same($signature, $signer->sign($second)); game_assert($signer->verify($second, $signature));
            $second['result']['a'] = 7; game_assert(!$signer->verify($second, $signature));
        };

        $tests['engine response excludes private state and includes transition integrity'] = static function () use ($configuration, $player): void {
            $registry = new GameRegistry(['mines' => $configuration['mines']], new GamePrefixPositionRandom([24,23,22,21,20]));
            $engine = new GameEngine($registry, new OutcomeSigner(str_repeat('s', 32)), ['algorithm' => 'HMAC-SHA256-PP-V1', 'serverSeedHash' => str_repeat('a', 64), 'clientSeed' => 'test', 'nonce' => 1]);
            $request = new GameRequest('play', 10, ['mines' => 5], 'test:engine:mines:0001');
            $result = $engine->execute('mines', $request, $player); $json = json_encode($result, JSON_THROW_ON_ERROR);
            game_assert(!str_contains($json, '"mines":')); game_assert(str_contains($json, '"signature"')); game_assert_same(1, $result->transition['revision']); game_assert(isset($result->serverState()['mines']));
            $nextRequest = new GameRequest('reveal', 10, ['tile' => 0], 'test:engine:mines:0002', 'test', (string) $result->payload()['roundId'], $result->serverState());
            $next = $engine->execute('mines', $nextRequest, $player);
            game_assert_same(2, $next->transition['revision']);
            game_assert_same($result->transition['stateHash'], $next->transition['previousStateHash']);
        };

        $passed = 0; $failed = 0;
        foreach ($tests as $name => $test) {
            try { $test(); ++$passed; fwrite(STDOUT, "PASS  {$name}\n"); }
            catch (Throwable $error) { ++$failed; fwrite(STDOUT, "FAIL  {$name}: {$error->getMessage()}\n"); }
        }
        fwrite(STDOUT, "Game tests: {$passed} passed, {$failed} failed, " . count($tests) . " total.\n");
        return $failed === 0 ? 0 : 1;
    }
}

if (!function_exists('game_tests_autoload')) {
    function game_tests_autoload(): void
    {
        $root = dirname(__DIR__, 2);
        if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; return; }
        spl_autoload_register(static function (string $class) use ($root): void {
            $prefix = 'PurpleParlor\\Games\\';
            if (str_starts_with($class, $prefix)) { $path = $root . '/app/Games/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; if (is_file($path)) require $path; }
        });
    }
}

if (!function_exists('game_assert')) { function game_assert(bool $condition, string $message = 'Assertion failed'): void { if (!$condition) throw new RuntimeException($message); } }
if (!function_exists('game_assert_same')) { function game_assert_same(mixed $expected, mixed $actual, string $message = ''): void { if ($expected !== $actual) throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }
if (!function_exists('game_expect_exception')) {
    function game_expect_exception(callable $callback, string $class, ?string $code = null): void {
        try { $callback(); } catch (Throwable $error) { game_assert($error instanceof $class, 'Unexpected exception class ' . $error::class); if ($code !== null) game_assert_same($code, $error->errorCode ?? null); return; }
        throw new RuntimeException('Expected exception was not thrown.');
    }
}

/** @return array{0:\PurpleParlor\Games\DTO\GameRound,1:\PurpleParlor\Games\DTO\GameOutcome} */
function game_start(CatalogGame $game, PlayerContext $player, int $wager, array $options = []): array
{
    $request = new GameRequest('play', $wager, $options, 'test:start:round:000001'); $round = $game->createRound($request, $player); return [$round, $game->calculateOutcome($round)];
}

/** @return array<string,mixed> */
function game_default_options(string $slug): array
{
    return match ($slug) {
        'plinko' => ['rows' => 12, 'risk' => 'medium'], 'european-roulette','american-roulette' => ['bet' => 'red'], 'baccarat' => ['bet' => 'player'],
        'three-card-poker' => ['decision' => 'play'], 'sic-bo' => ['bet' => 'small', 'selection' => 4], 'keno' => ['picks' => [7,14,21,28,35]],
        'over-under-dice' => ['choice' => 'under', 'target' => 50], 'coin-flip' => ['guess' => 'heads'], 'number-draw' => ['mode' => 'exact', 'guess' => 17],
        'horse-racing' => ['horse' => 0], 'lucky-cups' => ['cup' => 1], 'mines' => ['mines' => 5], 'video-poker' => ['variant' => 'jacks_or_better'],
        'texas-holdem','five-card-draw' => ['difficulty' => 'regular'], default => [],
    };
}

// Register before the fixture classes below are declared; their interfaces live
// in app/Games and must be resolvable even when Composer is not installed.
game_tests_autoload();

final class GameQueueRandom implements RandomSource
{
    public function __construct(private array $integers = [], private readonly bool $identityShuffle = false) {}
    public function int(int $minimum, int $maximum): int { $value = $this->integers === [] ? $minimum : (int) array_shift($this->integers); if ($value < $minimum || $value > $maximum) throw new RuntimeException("Queued random value {$value} outside {$minimum}..{$maximum}"); return $value; }
    public function bytes(int $length): string { return str_repeat("\0", $length); }
    public function shuffle(array $values): array { if ($this->identityShuffle) return array_values($values); for ($i=count($values)-1;$i>0;--$i){$j=$this->int(0,$i);[$values[$i],$values[$j]]=[$values[$j],$values[$i]];} return array_values($values); }
}

final class GamePrefixCardRandom implements RandomSource
{
    public function __construct(private readonly array $prefix) {}
    public function int(int $minimum, int $maximum): int { return $minimum; }
    public function bytes(int $length): string { return str_repeat("\1", $length); }
    public function shuffle(array $values): array { if ($values !== [] && $values[0] instanceof Card) { $map=[]; foreach($values as $card)$map[$card->code()]=$card; $ordered=[]; foreach($this->prefix as $code){$ordered[]=$map[$code];unset($map[$code]);} return array_merge($ordered,array_values($map)); } return array_values($values); }
}

final class GamePrefixPositionRandom implements RandomSource
{
    public function __construct(private readonly array $prefix) {}
    public function int(int $minimum, int $maximum): int { return $minimum; }
    public function bytes(int $length): string { return str_repeat("\2", $length); }
    public function shuffle(array $values): array { $rest=array_values(array_diff($values,$this->prefix)); return array_values(array_merge($this->prefix,$rest)); }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) exit(run_game_tests());
