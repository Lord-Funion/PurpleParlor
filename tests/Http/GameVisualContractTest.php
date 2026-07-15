<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\TestCase;

final class GameVisualContractTest extends TestCase
{
    public function testEveryCatalogGameHasAnExplicitPolishedVisualContract(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = require $root . '/config/games.php';
        $visuals = (string) file_get_contents($root . '/public/assets/js/games/game-visuals.js');
        $plinko = (string) file_get_contents($root . '/public/assets/js/games/plinko-board.js');

        foreach (array_keys($catalog) as $slug) {
            $implemented = $slug === 'plinko'
                ? str_contains($plinko, 'export class PlinkoBoard')
                : str_contains($visuals, "'{$slug}':");
            $this->assertTrue($implemented, "Game {$slug} must have an explicit visual archetype.");
        }
    }

    public function testVisualsAnimateOnlyAuthoritativeServerFields(): void
    {
        $root = dirname(__DIR__, 2);
        $visuals = (string) file_get_contents($root . '/public/assets/js/games/game-visuals.js');
        $plinko = (string) file_get_contents($root . '/public/assets/js/games/plinko-board.js');
        $strategy = (string) file_get_contents($root . '/app/Games/Strategy/InstantGameStrategy.php');

        $this->assertFalse(str_contains($visuals, 'Math.random('), 'Cosmetic renderers must never invent a second outcome.');
        $this->assertFalse(str_contains($plinko, 'Math.random('), 'The Plinko drop must follow the returned path exactly.');
        foreach (['path', 'bin', 'rows', 'risk', 'multipliersBps'] as $field) {
            $this->assertTrue(str_contains($plinko, $field), "Plinko must consume authoritative {$field} metadata.");
        }
        $this->assertTrue(str_contains($strategy, "'multipliersBps' => \$multipliersBps"), 'The server must disclose the exact Plinko multiplier strip used by the visual.');
    }

    public function testAnimationHasSkipReducedMotionAndQuietAnnouncements(): void
    {
        $root = dirname(__DIR__, 2);
        $client = (string) file_get_contents($root . '/public/assets/js/games/game-client.js');
        $renderer = (string) file_get_contents($root . '/public/assets/js/games/outcome-renderer.js');
        $view = (string) file_get_contents($root . '/resources/views/games/show.php');

        $this->assertTrue(
            str_contains($client, 'const reveal = this.outcome.render') && str_contains($client, 'await reveal'),
            'Controls must remain locked until a reveal settles.',
        );
        $this->assertTrue(str_contains($renderer, 'this.plinko?.skip?.()'), 'Long motion must be skippable.');
        $this->assertTrue(str_contains($renderer, 'reducedMotion'), 'Visuals must honor reduced-motion preferences.');
        $this->assertTrue(str_contains($view, 'data-game-announcer') && str_contains($view, 'aria-atomic="true"'), 'Results need a concise dedicated live announcer.');
        $this->assertFalse(str_contains($view, 'data-game-outcome aria-live='), 'The animated scene itself must not become a noisy live region.');
    }
}
