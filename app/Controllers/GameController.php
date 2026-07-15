<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Http\GuestWallet;
use App\Http\View;
use App\Services\GamePlayService;
use App\Services\EngagementProgressService;
use PurpleParlor\Games\Fairness\SeedVerifier;

final class GameController extends BaseController
{
    public function __construct(View $view, Config $config, Database $database, AuthService $auth, private readonly GamePlayService $games, private readonly GuestWallet $guests, private readonly EngagementProgressService $engagement)
    {
        parent::__construct($view, $config, $database, $auth);
    }

    public function index(Request $request): Response
    {
        return $this->page($request, 'games/index', 'Game lobby', ['games' => $this->catalogForUser(), 'catalog_mode' => 'all']);
    }

    public function category(Request $request): Response
    {
        $category = mb_strtolower(trim((string) $request->attribute('category', '')));
        $games = array_values(array_filter($this->catalogForUser(), static fn (array $game): bool => mb_strtolower((string) $game['category']) === $category));
        return $this->page($request, 'games/category', ucwords(str_replace(['-', '_'], ' ', $category)) . ' games', ['games' => $games, 'category' => $category]);
    }

    public function search(Request $request): Response
    {
        $query = mb_substr(trim((string) $request->input('q', '')), 0, 80);
        $needle = mb_strtolower($query);
        $games = $needle === '' ? $this->catalogForUser() : array_values(array_filter(
            $this->catalogForUser(),
            static fn (array $game): bool => str_contains(mb_strtolower(implode(' ', [(string) $game['name'], (string) $game['description'], (string) $game['category']])), $needle),
        ));
        return $this->page($request, 'games/search', 'Search games', ['games' => $games, 'query' => $query]);
    }

    public function favorites(Request $request): Response
    {
        $games = array_values(array_filter($this->catalogForUser(), static fn (array $game): bool => (bool) ($game['favorite'] ?? false)));
        return $this->page($request, 'games/favorites', 'Your favorites', ['games' => $games], true);
    }

    public function recent(Request $request): Response
    {
        $catalog = $this->catalogForUser();
        $positions = [];
        if (($userId = $this->userId()) !== null) {
            foreach ($this->database->fetchAll('SELECT gd.slug, rg.last_played_at FROM recent_games rg INNER JOIN game_definitions gd ON gd.id = rg.game_id WHERE rg.user_id = :user ORDER BY rg.last_played_at DESC LIMIT 40', ['user' => $userId]) as $index => $row) {
                $positions[(string) $row['slug']] = ['position' => $index, 'label' => $this->relativeTime((string) $row['last_played_at'])];
            }
        } else {
            foreach ($this->guests->recentRounds() as $index => $row) {
                $slug = (string) ($row['slug'] ?? '');
                $positions[$slug] ??= ['position' => $index, 'label' => 'This session'];
            }
        }
        $catalog = array_values(array_filter($catalog, static fn (array $game): bool => isset($positions[$game['slug']])));
        usort($catalog, static fn (array $a, array $b): int => $positions[$a['slug']]['position'] <=> $positions[$b['slug']]['position']);
        foreach ($catalog as &$game) {
            $game['recent_label'] = $positions[$game['slug']]['label'];
        }
        unset($game);
        return $this->page($request, 'games/recent', 'Recently played', ['games' => $catalog], $this->userId() !== null);
    }

    public function show(Request $request): Response
    {
        $game = $this->findGame((string) $request->attribute('slug'));
        if ($game === null) {
            return $this->page($request, 'errors/404', 'Game not found', [], false, 'layouts/app', 404);
        }
        return $this->page($request, 'games/show', (string) $game['name'], [
            'game' => $game,
            'body_class' => 'game-page game-page--' . preg_replace('/[^a-z0-9-]/', '', (string) $game['slug']),
            'stylesheets' => ['css/game-visuals.css', 'css/plinko-board.css'],
            'next_server_seed_hash' => $this->games->precommit($this->userId()),
            'guest_balance' => $this->userId() === null ? $this->guests->balance() : null,
            'csrf_token' => csrf_token(),
            'idempotency_key' => 'form:' . bin2hex(random_bytes(18)),
            'session_minutes' => isset($_SESSION['_parlor_started_at']) ? max(0, intdiv(time() - (int) $_SESSION['_parlor_started_at'], 60)) : 0,
        ], true);
    }

    public function favorite(Request $request): Response
    {
        $userId = (int) $this->userId();
        $slug = (string) $request->attribute('slug');
        $game = $this->database->fetchOne('SELECT id FROM game_definitions WHERE slug = :slug AND active = 1', ['slug' => $slug]);
        if ($game === null) {
            return Response::json(['error' => 'Game not found.'], 404);
        }
        $existing = $this->database->fetchOne('SELECT 1 FROM game_favorites WHERE user_id = :user AND game_id = :game', ['user' => $userId, 'game' => $game['id']]);
        if ($existing === null) {
            $this->database->execute('INSERT INTO game_favorites (user_id, game_id, created_at) VALUES (:user, :game, :created)', ['user' => $userId, 'game' => $game['id'], 'created' => gmdate('Y-m-d H:i:s')]);
            $favorite = true;
        } else {
            $this->database->execute('DELETE FROM game_favorites WHERE user_id = :user AND game_id = :game', ['user' => $userId, 'game' => $game['id']]);
            $favorite = false;
        }
        if ($request->expectsJson()) {
            return Response::json(['favorite' => $favorite]);
        }
        $this->flash($favorite ? 'Added to your favorites.' : 'Removed from your favorites.', 'success');
        return Response::redirect((string) ($request->header('referer') ?: '/games'));
    }

    public function rules(Request $request): Response
    {
        $game = $this->findGame((string) $request->attribute('slug'));
        if ($game !== null && ($userId = $this->userId()) !== null) {
            $this->engagement->recordRulesRead($userId, (string) $game['slug']);
        }
        return $game === null
            ? $this->page($request, 'errors/404', 'Rules not found', [], false, 'layouts/app', 404)
            : $this->page($request, 'help/rule-show', (string) $game['name'] . ' rules', ['game' => $game]);
    }

    public function probability(Request $request): Response
    {
        $game = $this->findGame((string) $request->attribute('slug'));
        return $game === null
            ? $this->page($request, 'errors/404', 'Probability guide not found', [], false, 'layouts/app', 404)
            : $this->page($request, 'help/probability-show', (string) $game['name'] . ' probabilities', ['game' => $game]);
    }

    public function verify(Request $request): Response
    {
        return $this->page($request, 'help/fairness', 'Outcome verification', ['test_vector' => SeedVerifier::testVector()]);
    }

    public function verifyResult(Request $request): Response
    {
        try {
            $values = SeedVerifier::reproduce(
                (string) $request->input('server_seed_hash'),
                (string) $request->input('server_seed'),
                (string) $request->input('client_seed'),
                (int) $request->input('nonce', 0),
                (int) $request->input('minimum', 0),
                (int) $request->input('maximum', 36),
                min(100, (int) $request->input('count', 10)),
            );
            return $this->page($request, 'help/fairness', 'Outcome verification', ['values' => $values, 'test_vector' => SeedVerifier::testVector()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->page($request, 'help/fairness', 'Outcome verification', ['error' => $exception->getMessage(), 'test_vector' => SeedVerifier::testVector()], false, 'layouts/app', 422);
        } catch (\Throwable) {
            return $this->page($request, 'help/fairness', 'Outcome verification', ['error' => 'The verification request could not be processed safely. Try again with the disclosed values.', 'test_vector' => SeedVerifier::testVector()], false, 'layouts/app', 422);
        }
    }

    /** @return list<array<string,mixed>> */
    private function catalogForUser(): array
    {
        $favorites = [];
        if (($userId = $this->userId()) !== null) {
            foreach ($this->database->fetchAll('SELECT gd.slug FROM game_favorites gf INNER JOIN game_definitions gd ON gd.id = gf.game_id WHERE gf.user_id = :user', ['user' => $userId]) as $row) {
                $favorites[(string) $row['slug']] = true;
            }
        }
        return array_map(function (array $game) use ($favorites): array {
            $normalized = $this->normalizeGame($game);
            $normalized['favorite'] = isset($favorites[$normalized['slug']]);
            return $normalized;
        }, $this->games->catalog());
    }

    /** @return array<string,mixed>|null */
    private function findGame(string $slug): ?array
    {
        foreach ($this->catalogForUser() as $game) {
            if (hash_equals((string) $game['slug'], $slug)) {
                $configuration = $this->config->get('games.' . $slug, []);
                // Public catalog values include validated database availability
                // and fictional wager overrides; they must win over the static
                // detail definition while rules/odds/strategies stay code-backed.
                return $this->normalizeGame(is_array($configuration) ? array_replace($configuration, $game) : $game) + ['favorite' => $game['favorite'] ?? false];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $game @return array<string,mixed> */
    private function normalizeGame(array $game): array
    {
        $wager = is_array($game['wager'] ?? null) ? $game['wager'] : [];
        $paytable = [];
        foreach ((array) ($game['paytable'] ?? []) as $label => $payout) {
            $paytable[] = ['label' => is_string($label) ? $label : 'Outcome', 'payout' => is_scalar($payout) ? (string) $payout : json_encode($payout, JSON_UNESCAPED_SLASHES)];
        }
        $probability = (array) ($game['probability'] ?? []);
        $normalized = array_replace([
            'slug' => '', 'name' => 'Parlor game', 'category' => 'arcade', 'description' => 'A cozy play-money game.',
            'rules' => [], 'tutorial' => 'Review the rules and begin one fictional round at a time.',
        ], $game);
        return array_replace($normalized, [
            'min_wager' => (int) ($wager['minimum'] ?? 0), 'max_wager' => (int) ($wager['maximum'] ?? 0),
            'wager_step' => max(1, (int) ($wager['increment'] ?? 1)), 'default_wager' => (int) ($wager['minimum'] ?? 0),
            'paytable' => $paytable,
            'probability' => [
                'summary' => 'The PHP server samples from the disclosed outcome model. Membership never changes the odds.',
                'details' => json_encode($probability, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'theoretical_return' => isset($game['theoretical_rtp']) && $game['theoretical_rtp'] !== null ? number_format((float) $game['theoretical_rtp'] * 100, 2) . '%' : 'Not applicable to this wager-free practice game.',
            ],
        ]);
    }

    private function relativeTime(string $timestamp): string
    {
        $seconds = max(0, time() - (strtotime($timestamp) ?: time()));
        return $seconds < 3600 ? max(1, intdiv($seconds, 60)) . ' min ago' : ($seconds < 86400 ? intdiv($seconds, 3600) . ' hr ago' : intdiv($seconds, 86400) . ' days ago');
    }
}
