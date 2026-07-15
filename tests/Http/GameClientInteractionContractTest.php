<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\TestCase;

final class GameClientInteractionContractTest extends TestCase
{
    private function clientSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/games/game-client.js');
    }

    public function testEscapeSkipsVisualMotionWithoutAbortingAnAcceptedWagerRequest(): void
    {
        $client = $this->clientSource();
        $keydownStart = strpos($client, "this.root.addEventListener('keydown'");
        $keydownEnd = strpos($client, "this.controls?.addEventListener('change'", (int) $keydownStart);
        $keydownHandler = substr($client, (int) $keydownStart, (int) $keydownEnd - (int) $keydownStart);

        $this->assertTrue($keydownStart !== false && $keydownEnd !== false, 'The keyboard interaction handler must remain present.');
        $this->assertTrue(str_contains($keydownHandler, 'this.outcome.skip()'), 'Escape must still finish an active reveal.');
        $this->assertFalse(str_contains($keydownHandler, '.abort()'), 'Escape must never abort a wager request whose server status may be ambiguous.');
        $this->assertFalse(str_contains($client, 'new AbortController()'), 'The game controller must not attach an Escape-cancellable signal to wager requests.');
    }

    public function testSolitaireIndexLimitsTrackSelectedPileTypes(): void
    {
        $client = $this->clientSource();

        foreach ([
            "to === 'foundation' ? 4 : 7",
            "from === 'column' ? 8 : 4",
            "to === 'column' ? 8 : 4",
            "options.to === 'foundation' ? 3 : 6",
            "options.from === 'column' ? 7 : 3",
            "options.to === 'column' ? 7 : 3",
        ] as $constraint) {
            $this->assertTrue(str_contains($client, $constraint), "Missing dynamic solitaire constraint: {$constraint}");
        }
        $this->assertTrue(str_contains($client, "show(sourceColumn, from === 'tableau')"), 'Klondike must hide its irrelevant source-column field when moving from waste.');
    }

    public function testPyramidUiAndSubmissionBothEnforceTwoCardMaximum(): void
    {
        $client = $this->clientSource();

        $this->assertTrue(str_contains($client, 'fieldset.dataset.pyramidSelection'), 'Pyramid choices need a dedicated selection group.');
        $this->assertTrue(str_contains($client, 'selectedCount >= 2 && !input.checked'), 'After two choices, the remaining Pyramid cards must be unavailable.');
        $this->assertTrue(str_contains($client, 'indices.length < 1 || indices.length > 2'), 'Pyramid submission must reject empty and oversized selections before the request.');
    }
}
