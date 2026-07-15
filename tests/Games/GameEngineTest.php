<?php

declare(strict_types=1);

namespace Tests\Games;

use Tests\Support\TestCase;

final class GameEngineTest extends TestCase
{
    public function testCompleteStandaloneGameRuleSuite(): void
    {
        require_once __DIR__ . '/run.php';
        $this->assertSame(0, \run_game_tests(), 'One or more focused game rules failed.');
    }
}
