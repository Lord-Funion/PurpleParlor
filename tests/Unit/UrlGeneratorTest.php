<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\UrlGenerator;
use Tests\Support\TestCase;

final class UrlGeneratorTest extends TestCase
{
    public function testUnusedRouteParametersBecomeAnEncodedQueryString(): void
    {
        UrlGenerator::configure([
            ['GET', '/billing/checkout', 'billing.checkout'],
            ['GET', '/games/{slug}', 'games.show'],
        ]);

        $this->assertSame(
            '/billing/checkout?product=cozy_club&period=annual',
            UrlGenerator::route('billing.checkout', ['product' => 'cozy_club', 'period' => 'annual']),
        );
        $this->assertSame(
            '/games/plinko?tab=probability%20details',
            UrlGenerator::route('games.show', ['slug' => 'plinko', 'tab' => 'probability details']),
        );
    }
}
