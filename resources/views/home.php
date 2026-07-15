<?php
$games = isset($data['featured_games']) && is_array($data['featured_games']) ? $data['featured_games'] : array_slice(require __DIR__ . '/partials/default-games.php', 0, 8);
$minimumAge = (int) ($app['minimum_age'] ?? 18);
?>
<section class="hero">
    <div class="shell hero-grid">
        <div class="hero-copy">
            <p class="eyebrow">The cozy side of play-money games</p>
            <h1>Pull up a chair at <em>The Purple Parlor.</em></h1>
            <p class="lede">A warm, polished arcade of card, table, wheel, and casual games—all played with fictional Cozy Coins that can never be bought or cashed out.</p>
            <div class="button-row">
                <a class="button button-gold" href="<?= $url('games.index', [], '/games') ?>">Enter the game lobby</a>
                <a class="button button-ghost" href="<?= $url('guest.start', [], '/guest/start') ?>">Try guest mode</a>
            </div>
            <div class="hero-trust" aria-label="Key site facts">
                <span>No real-money wagers</span><span>All <?= $esc((string) count(require __DIR__ . '/partials/default-games.php')) ?> games included</span><span>Healthy-play controls</span>
            </div>
        </div>
        <div class="lounge-scene" aria-label="An illustrated Purple Parlor game table">
            <img class="lounge-logo" src="<?= $assetUrl('images/onion-mark.svg') ?>" alt="">
            <span class="lounge-card lounge-card-one" aria-hidden="true">A♠</span>
            <span class="lounge-card lounge-card-two" aria-hidden="true">K♥</span>
            <span class="lounge-chip lounge-chip-a" aria-hidden="true"></span>
            <span class="lounge-chip lounge-chip-b" aria-hidden="true"></span>
        </div>
    </div>
</section>

<section class="feature-band" aria-label="How The Purple Parlor works">
    <div class="shell feature-grid">
        <article class="feature-item"><span aria-hidden="true">01</span><h3>Play for fun</h3><p>Cozy Coins and Parlor Stars are fictional progress markers—not money, prizes, or investments.</p></article>
        <article class="feature-item"><span aria-hidden="true">02</span><h3>Know the odds</h3><p>Rules, probabilities, paytables, and theoretical returns are shown clearly before each round.</p></article>
        <article class="feature-item"><span aria-hidden="true">03</span><h3>Stay comfortable</h3><p>Session reminders, cooldowns, animation controls, and a Take a Break option are always close by.</p></article>
    </div>
</section>

<section class="section shell" aria-labelledby="featured-games-title">
    <div class="section-heading">
        <div><p class="eyebrow">Tonight in the lounge</p><h2 id="featured-games-title">Featured games</h2><p>Original presentation, server-authoritative outcomes, and clear play-money rules.</p></div>
        <a class="button button-ghost" href="<?= $url('games.index', [], '/games') ?>">Browse all games</a>
    </div>
    <div class="game-grid">
        <?php foreach ($games as $game) { require __DIR__ . '/partials/game-card.php'; } ?>
    </div>
</section>

<section class="section-tight shell" aria-labelledby="find-room-title">
    <div class="section-heading">
        <div><p class="eyebrow">Find your corner</p><h2 id="find-room-title">Rooms for every mood</h2></div>
    </div>
    <div class="category-cards">
        <a class="category-card" href="<?= $url('games.category', ['category'=>'table'], '/games/category/table') ?>"><span aria-hidden="true">♠</span><h3>Table Room</h3><p>Twenty-one, baccarat, roulette, dice, and guided practice variants.</p></a>
        <a class="category-card" href="<?= $url('games.category', ['category'=>'slots'], '/games/category/slots') ?>"><span aria-hidden="true">♛</span><h3>Velvet Reels</h3><p>Original three- and five-reel designs with complete paytables.</p></a>
        <a class="category-card" href="<?= $url('games.category', ['category'=>'casual'], '/games/category/casual') ?>"><span aria-hidden="true">✦</span><h3>Casual Corner</h3><p>Puzzles, solitaire, memory games, and free daily diversions.</p></a>
        <a class="category-card" href="<?= $url('games.category', ['category'=>'poker'], '/games/category/poker') ?>"><span aria-hidden="true">♦</span><h3>Poker Alcove</h3><p>Video poker and clearly labeled computer-opponent practice tables.</p></a>
    </div>
</section>

<section class="section shell">
    <div class="cta-panel card">
        <div class="stack">
            <p class="eyebrow">Membership comfort perks</p>
            <h2>Make the lounge feel more like yours.</h2>
            <p class="lede">Optional memberships add themes, profile cosmetics, layout choices, deeper statistics, and a fixed daily Cozy Coin grant. They never change odds, payout rules, or healthy-play controls.</p>
        </div>
        <a class="button button-gold" href="<?= $url('membership.index', [], '/memberships') ?>">Compare memberships</a>
    </div>
</section>

<section class="section-tight shell text-center">
    <div class="stack">
        <p class="eyebrow">Adults <?= $esc((string) $minimumAge) ?>+ only</p>
        <h2>A social-casino style arcade, without real-money gambling.</h2>
        <p class="lede no-margin u-centered">The Purple Parlor offers no real-money wagers, cash prizes, withdrawals, balance transfers, or cash-out. Membership daily grants are fictional, nontransferable counters with no monetary value. Age confirmation is not identity verification.</p>
        <div class="button-row u-justify-center"><a class="button button-ghost" href="<?= $url('responsible-play', [], '/responsible-play') ?>">Read our healthy-play approach</a><a class="button button-ghost" href="<?= $url('probabilities.index', [], '/probabilities') ?>">Explore odds and paytables</a></div>
    </div>
</section>
