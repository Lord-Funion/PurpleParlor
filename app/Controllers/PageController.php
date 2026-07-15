<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Http\View;
use App\Services\GamePlayService;
use App\Services\ManagedContentService;

final class PageController extends BaseController
{
    private readonly ManagedContentService $managedContent;

    public function __construct(View $view, Config $config, Database $database, AuthService $auth, private readonly GamePlayService $games)
    {
        parent::__construct($view, $config, $database, $auth);
        $this->managedContent = new ManagedContentService($database, $config);
    }

    public function home(Request $request): Response
    {
        return $this->page($request, 'home', 'The Purple Parlor', ['featured_games' => array_slice($this->catalog(), 0, 8)]);
    }

    public function age(Request $request): Response
    {
        return $this->page($request, 'age-gate', 'Age confirmation', [
            'minimum_age' => (int) $this->config->get('app.minimum_age', 18),
            'policy_version' => (int) $this->config->get('app.legal_policy_version', 1),
        ], true);
    }

    public function help(Request $request): Response
    {
        return $this->page($request, 'help/index', 'Help center');
    }

    public function rules(Request $request): Response
    {
        return $this->page($request, 'help/rules', 'Game rules', ['games' => $this->catalog()]);
    }

    public function probabilities(Request $request): Response
    {
        return $this->page($request, 'help/probabilities', 'Probabilities and paytables', ['games' => $this->catalog()]);
    }

    public function responsiblePlay(Request $request): Response
    {
        return $this->page($request, 'help/responsible-play', 'Healthy and responsible play');
    }

    public function takeBreak(Request $request): Response
    {
        $_SESSION['break_started_at'] = time();
        return $this->page($request, 'help/take-break', 'Take a break', [], true);
    }

    public function contact(Request $request): Response
    {
        return $this->page($request, 'contact', 'Contact The Purple Parlor');
    }

    public function sponsor(Request $request): Response
    {
        return $this->page($request, 'business/sponsorship', 'Sponsor The Purple Parlor');
    }

    public function licensing(Request $request): Response
    {
        return $this->page($request, 'business/licensing', 'Software licensing and custom games');
    }

    public function legal(Request $request): Response
    {
        $slug = basename($request->uri);
        $views = [
            'privacy' => ['legal/privacy', 'Privacy Policy', 'privacy'],
            'terms' => ['legal/terms', 'Terms of Service', 'terms'],
            'subscription-terms' => ['legal/subscription-terms', 'Subscription Terms', 'subscription-terms'],
            'refunds' => ['legal/refund', 'Refund Policy', 'refund'],
            'virtual-currency' => ['legal/virtual-currency', 'Virtual Currency Policy', 'virtual-currency'],
            'cookies' => ['legal/cookies', 'Cookie Policy', 'cookies'],
            'advertising' => ['legal/advertising', 'Advertising Disclosure', 'advertising'],
            'acceptable-use' => ['legal/acceptable-use', 'Acceptable Use Policy', 'acceptable-use'],
            'copyright' => ['legal/copyright', 'Copyright Notice', 'copyright'],
            'attributions' => ['legal/attributions', 'Asset Attributions', 'attributions'],
        ];
        if (!isset($views[$slug])) {
            return $this->page($request, 'errors/404', 'Page not found', [], false, 'layouts/app', 404);
        }
        [$view, $title, $contentKey] = $views[$slug];
        return $this->page($request, $view, $title, [
            'legal_slug' => $slug,
            'managed_document' => $this->managedContent->published('legal', $contentKey),
        ]);
    }

    public function maintenance(Request $request): Response
    {
        return $this->page($request, 'maintenance', 'The Parlor is being prepared', [], true, 'layouts/app', 503);
    }

    /** @return list<array<string, mixed>> */
    private function catalog(): array
    {
        $configured = $this->config->get('games.games', $this->config->get('games', []));
        if (!is_array($configured) || $configured === []) {
            $fallback = BASE_PATH . '/resources/views/partials/default-games.php';
            return is_file($fallback) ? (array) require $fallback : [];
        }
        $runtime = [];
        foreach ($this->games->catalog() as $game) {
            $runtime[(string) $game['slug']] = $game;
        }
        $catalog = [];
        foreach ($configured as $key => $game) {
            if (!is_array($game)) {
                continue;
            }
            $game['slug'] ??= is_string($key) ? $key : '';
            if (!isset($runtime[(string) $game['slug']])) {
                continue;
            }
            $game['name'] ??= ucwords(str_replace('-', ' ', (string) $game['slug']));
            $game['description'] ??= 'A cozy play-money game with server-authoritative outcomes.';
            $game['category'] ??= 'Arcade';
            $game['available'] ??= true;
            $game['wager'] = $runtime[(string) $game['slug']]['wager'];
            $catalog[] = $game;
        }
        return $catalog;
    }
}
