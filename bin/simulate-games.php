<?php

declare(strict_types=1);

use PurpleParlor\Games\Cards\Card;
use PurpleParlor\Games\CatalogGame;
use PurpleParlor\Games\Contracts\GameInterface;
use PurpleParlor\Games\DTO\GameOutcome;
use PurpleParlor\Games\DTO\GameRequest;
use PurpleParlor\Games\DTO\PlayerContext;
use PurpleParlor\Games\Random\SeededRandomSource;

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'PurpleParlor\\Games\\';
        if (str_starts_with($class, $prefix)) {
            $path = $root . '/app/Games/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) require $path;
        }
    });
}

$options = getopt('', ['mode::', 'rounds::', 'seed::', 'game::', 'csv::', 'format::', 'warning::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
The Purple Parlor isolated game-math simulator

  php bin/simulate-games.php --mode=fast|standard|extended
      [--rounds=N] [--seed=TEXT] [--game=SLUG] [--csv=PATH]
      [--format=human|csv|json] [--warning=0.02]

fast=1,000, standard=100,000, extended=1,000,000 rounds per paid game.
This command uses deterministic in-memory RNG and never opens a database or
changes a user balance. Simulation does not prove perfect randomness.
TXT);
    exit(0);
}

$mode = (string) ($options['mode'] ?? 'fast');
$modeRounds = ['fast' => 1000, 'standard' => 100000, 'extended' => 1000000];
if (!isset($modeRounds[$mode])) {
    fwrite(STDERR, "Unknown mode. Use fast, standard, or extended.\n");
    exit(2);
}
$rounds = isset($options['rounds']) ? filter_var($options['rounds'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000000]]) : $modeRounds[$mode];
if ($rounds === false) {
    fwrite(STDERR, "--rounds must be between 1 and 10,000,000.\n");
    exit(2);
}
$seed = (string) ($options['seed'] ?? 'purple-parlor-validation-v1');
if ($seed === '' || strlen($seed) > 256) {
    fwrite(STDERR, "--seed must contain 1 to 256 characters.\n");
    exit(2);
}
$format = (string) ($options['format'] ?? 'human');
if (!in_array($format, ['human', 'csv', 'json'], true)) {
    fwrite(STDERR, "--format must be human, csv, or json.\n");
    exit(2);
}
$warningThreshold = isset($options['warning']) ? (float) $options['warning'] : 0.02;
if ($warningThreshold < 0.001 || $warningThreshold > 0.5) {
    fwrite(STDERR, "--warning must be between 0.001 and 0.5.\n");
    exit(2);
}

$configuration = require $root . '/config/games.php';
$selected = isset($options['game']) ? (array) $options['game'] : array_keys($configuration);
foreach ($selected as $slug) {
    if (!isset($configuration[$slug])) {
        fwrite(STDERR, "Unknown game slug: {$slug}\n");
        exit(2);
    }
}

$results = [];
foreach ($selected as $slug) {
    $config = $configuration[$slug];
    $expected = $config['theoretical_rtp'];
    if ($expected === null || !($config['simulation']['enabled'] ?? false)) {
        $game = new CatalogGame($config, new SeededRandomSource($seed . '|' . $slug));
        $smoke = smokeFreeGame($game, $slug);
        $results[] = [
            'slug' => $slug, 'rounds' => 1, 'wagered' => 0, 'returned' => 0,
            'expected_rtp' => null, 'observed_rtp' => null, 'ci_low' => null, 'ci_high' => null,
            'standard_error' => null, 'errors' => $smoke ? 0 : 1, 'status' => $smoke ? 'PRACTICE_SMOKE_OK' : 'ERROR',
            'note' => 'Wager-free game: state/action smoke check only.',
        ];
        continue;
    }

    $game = new CatalogGame($config, new SeededRandomSource($seed . '|' . $slug));
    $player = new PlayerContext('simulation-player', PHP_INT_MAX, true);
    // Use 100 units so fractional multipliers expressed in basis points are not
    // dominated by whole-coin payout rounding during mathematical validation.
    $wager = min((int) $config['wager']['maximum'], max(100, (int) $config['wager']['minimum']));
    $count = 0; $mean = 0.0; $m2 = 0.0; $totalPayout = 0; $errors = 0;
    for ($iteration = 0; $iteration < $rounds; ++$iteration) {
        try {
            $payout = simulatePaidRound($game, $slug, $wager, $player, $iteration);
            $return = $payout / $wager;
            ++$count;
            $delta = $return - $mean;
            $mean += $delta / $count;
            $m2 += $delta * ($return - $mean);
            $totalPayout += $payout;
        } catch (Throwable $error) {
            ++$errors;
            if ($errors <= 3 && $format === 'human') fwrite(STDERR, "{$slug} round {$iteration}: {$error->getMessage()}\n");
        }
    }
    $variance = $count > 1 ? $m2 / ($count - 1) : 0.0;
    $standardError = $count > 0 ? sqrt($variance / $count) : INF;
    $observed = $count > 0 ? $mean : 0.0;
    $ciLow = max(0.0, $observed - 1.96 * $standardError);
    $ciHigh = $observed + 1.96 * $standardError;
    $tolerance = max($warningThreshold, 3 * $standardError);
    $strategyDependent = (bool) ($config['probability']['strategyDependent'] ?? false);
    $deviation = abs($observed - (float) $expected);
    $status = $errors > 0 ? 'ERRORS' : ($deviation > $tolerance ? ($strategyDependent ? 'STRATEGY_WARNING' : 'RTP_WARNING') : 'OK');
    $note = $strategyDependent ? 'Expected RTP assumes an appropriate player strategy; simulator uses a documented simple policy.' : '';
    $results[] = [
        'slug' => $slug, 'rounds' => $count, 'wagered' => $count * $wager, 'returned' => $totalPayout,
        'expected_rtp' => (float) $expected, 'observed_rtp' => $observed, 'ci_low' => $ciLow, 'ci_high' => $ciHigh,
        'standard_error' => $standardError, 'errors' => $errors, 'status' => $status, 'note' => $note,
    ];
}

if (isset($options['csv']) && (string) $options['csv'] !== '') {
    $csvPath = (string) $options['csv'];
    $directory = dirname($csvPath);
    if (!is_dir($directory) || !is_writable($directory)) {
        fwrite(STDERR, "CSV destination directory is not writable: {$directory}\n");
        exit(2);
    }
    $handle = fopen($csvPath, 'xb');
    if ($handle === false) {
        fwrite(STDERR, "CSV file already exists or cannot be created: {$csvPath}\n");
        exit(2);
    }
    writeCsv($handle, $results);
    fclose($handle);
}

if ($format === 'json') {
    fwrite(STDOUT, json_encode(['mode' => $mode, 'seed' => $seed, 'roundsPerPaidGame' => $rounds, 'databaseUsed' => false, 'results' => $results], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} elseif ($format === 'csv') {
    writeCsv(STDOUT, $results);
} else {
    fwrite(STDOUT, "The Purple Parlor game-math simulation\n");
    fwrite(STDOUT, "Mode: {$mode}; rounds per paid game: " . number_format($rounds) . "; seed: {$seed}\n");
    fwrite(STDOUT, "Isolated run: no database connection and no balance changes. Simulation does not prove perfect randomness.\n\n");
    fwrite(STDOUT, sprintf("%-31s %10s %10s %23s %-18s\n", 'Game', 'Expected', 'Observed', '95% confidence interval', 'Status'));
    fwrite(STDOUT, str_repeat('-', 98) . "\n");
    foreach ($results as $result) {
        $expectedText = $result['expected_rtp'] === null ? 'n/a' : number_format($result['expected_rtp'] * 100, 3) . '%';
        $observedText = $result['observed_rtp'] === null ? 'n/a' : number_format($result['observed_rtp'] * 100, 3) . '%';
        $ciText = $result['ci_low'] === null ? 'n/a' : number_format($result['ci_low'] * 100, 3) . '%–' . number_format($result['ci_high'] * 100, 3) . '%';
        fwrite(STDOUT, sprintf("%-31s %10s %10s %23s %-18s\n", $result['slug'], $expectedText, $observedText, $ciText, $result['status']));
        if ($result['note'] !== '') fwrite(STDOUT, "  {$result['note']}\n");
    }
    $warnings = count(array_filter($results, static fn (array $result): bool => in_array($result['status'], ['RTP_WARNING','STRATEGY_WARNING','ERRORS','ERROR'], true)));
    fwrite(STDOUT, "\nWarnings/errors: {$warnings}. RTP_WARNING means observed return differs beyond max(configured threshold, three standard errors); STRATEGY_WARNING means the simple simulation policy differs from the disclosed strategy-dependent model. Investigate either warning; neither proves bias.\n");
}

exit(count(array_filter($results, static fn (array $result): bool => in_array($result['status'], ['ERRORS','ERROR'], true))) > 0 ? 1 : 0);

/** @return int Gross fictional payout for one completed round. */
function simulatePaidRound(GameInterface $game, string $slug, int $wager, PlayerContext $player, int $iteration): int
{
    $action = 'play'; $options = defaultOptions($slug); $state = []; $roundId = null; $outcome = null;
    for ($step = 0; $step < 150; ++$step) {
        $request = new GameRequest($action, $wager, $options, sprintf('sim:%s:%010d:%03d', substr(hash('sha256', $slug), 0, 16), $iteration, $step), 'simulation-client', $roundId, $state);
        $round = $game->createRound($request, $player);
        $outcome = $game->calculateOutcome($round);
        if ($outcome->complete) return $game->calculatePayout($round, $outcome);
        $state = $outcome->serverState(); $roundId = $round->id;
        [$action, $options] = policy($slug, $outcome, $state);
    }
    throw new RuntimeException('Simulation policy exceeded 150 actions without completion.');
}

/** @return array<string, mixed> */
function defaultOptions(string $slug): array
{
    return match ($slug) {
        'plinko' => ['rows' => 12, 'risk' => 'medium'],
        'european-roulette', 'american-roulette' => ['bet' => 'red'],
        'baccarat' => ['bet' => 'player'],
        'three-card-poker' => ['decision' => 'play'],
        'sic-bo' => ['bet' => 'small', 'selection' => 4],
        'keno' => ['picks' => [7,14,21,28,35]],
        'over-under-dice' => ['choice' => 'under', 'target' => 50],
        'coin-flip' => ['guess' => 'heads'],
        'number-draw' => ['mode' => 'exact', 'guess' => 17],
        'horse-racing' => ['horse' => 0],
        'lucky-cups' => ['cup' => 1],
        'mines' => ['mines' => 5],
        'video-poker' => ['variant' => 'jacks_or_better'],
        'texas-holdem', 'five-card-draw' => ['difficulty' => 'regular'],
        default => [],
    };
}

/** @param array<string,mixed> $state @return array{0:string,1:array<string,mixed>} */
function policy(string $slug, GameOutcome $outcome, array $state): array
{
    return match ($slug) {
        'blackjack' => [((int) ($outcome->result['playerTotal'] ?? 17)) < 17 ? 'hit' : 'stand', []],
        'video-poker' => ['draw', ['holds' => pokerHolds($state['hand'] ?? [])]],
        'texas-holdem' => ['check', []],
        'caribbean-stud' => ['raise', []],
        'casino-war' => ['war', []],
        'red-dog' => ['draw', []],
        'let-it-ride' => ['ride', []],
        'hi-lo' => [(Card::fromCode((string) $state['current'])->rank >= 8 ? 'lower' : 'higher'), []],
        'five-card-draw' => ['draw', ['holds' => pokerHolds($state['player'] ?? [])]],
        'parlor-switch' => ['keep', []],
        'craps' => ['roll', []],
        'mines' => count($state['revealed'] ?? []) >= 1 ? ['cashout', []] : ['reveal', ['tile' => firstUnrevealed($state['revealed'] ?? [], 25)]],
        'higher-lower-streak' => (int) ($state['streak'] ?? 0) >= 1 ? ['cashout', []] : [(Card::fromCode((string) $state['current'])->rank >= 8 ? 'lower' : 'higher'), []],
        'treasure-tiles' => (int) ($state['score'] ?? 0) >= 1 ? ['cashout', []] : ['reveal', ['tile' => firstUnrevealed($state['revealed'] ?? [], 20)]],
        default => [($outcome->nextActions[0] ?? 'play'), []],
    };
}

/** @param list<string> $codes @return list<int> */
function pokerHolds(array $codes): array
{
    $cards = array_map(static fn (string $code): Card => Card::fromCode($code), $codes);
    $counts = array_count_values(array_map(static fn (Card $card): int => $card->rank, $cards));
    $holds = [];
    foreach ($cards as $index => $card) if (($counts[$card->rank] ?? 0) >= 2) $holds[] = $index;
    if ($holds !== []) return $holds;
    foreach ($cards as $index => $card) if ($card->rank >= 11) $holds[] = $index;
    return $holds;
}

/** @param list<int> $revealed */
function firstUnrevealed(array $revealed, int $count): int
{
    for ($index = 0; $index < $count; ++$index) if (!in_array($index, $revealed, true)) return $index;
    return 0;
}

function smokeFreeGame(GameInterface $game, string $slug): bool
{
    try {
        $player = new PlayerContext('simulation-practice', 0, true);
        $request = new GameRequest('play', 0, [], 'sim:practice:' . substr(hash('sha256', $slug), 0, 32));
        $round = $game->createRound($request, $player);
        $outcome = $game->calculateOutcome($round);
        return $outcome->complete || $outcome->nextActions !== [];
    } catch (Throwable) {
        return false;
    }
}

/** @param resource $handle @param list<array<string,mixed>> $results */
function writeCsv($handle, array $results): void
{
    fputcsv($handle, ['slug','rounds','wagered','returned','expected_rtp','observed_rtp','ci_low','ci_high','standard_error','errors','status','note'], ',', '"', '');
    foreach ($results as $result) fputcsv($handle, $result, ',', '"', '');
}
