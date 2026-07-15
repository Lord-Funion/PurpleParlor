<?php
$games = isset($data['games']) && is_array($data['games']) ? $data['games'] : require dirname(__DIR__) . '/partials/default-games.php';
$mode = (string) ($data['catalog_mode'] ?? 'all');
$titles = ['all'=>'Game lobby','search'=>'Search games','favorites'=>'Your favorites','recent'=>'Recently played','category'=>'Game category'];
$title = (string) ($data['heading'] ?? ($titles[$mode] ?? 'Game lobby'));
$description = (string) ($data['description'] ?? 'Browse the complete collection of cozy play-money games.');
$categories = array_values(array_unique(array_map(static fn(array $g): string => (string)($g['category'] ?? 'Other'), $games)));
sort($categories);
?>
<header class="page-hero">
    <div class="shell page-hero-inner">
        <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="<?= $url('home', [], '/') ?>">Parlor</a><span aria-hidden="true">/</span><span><?= $esc($title) ?></span></nav>
        <p class="eyebrow">Forty ways to unwind</p>
        <h1><?= $esc($title) ?></h1>
        <p class="lede"><?= $esc($description) ?></p>
        <nav class="subnav" aria-label="Game collections">
            <a href="<?= $url('games.index', [], '/games') ?>"<?= $mode === 'all' ? ' aria-current="page"' : '' ?>>All games</a>
            <a href="<?= $url('games.favorites', [], '/games/favorites') ?>"<?= $mode === 'favorites' ? ' aria-current="page"' : '' ?>>Favorites</a>
            <a href="<?= $url('games.recent', [], '/games/recent') ?>"<?= $mode === 'recent' ? ' aria-current="page"' : '' ?>>Recently played</a>
            <a href="<?= $url('games.category', ['category'=>'table'], '/games/category/table') ?>">Table</a>
            <a href="<?= $url('games.category', ['category'=>'casual'], '/games/category/casual') ?>">Casual</a>
            <a href="<?= $url('probabilities.index', [], '/probabilities') ?>">Odds & paytables</a>
        </nav>
    </div>
</header>
<div class="catalog-toolbar">
    <div class="shell toolbar-inner">
        <label class="search-control"><span class="sr-only">Search this game list</span><input type="search" value="<?= $esc((string)($data['query'] ?? '')) ?>" placeholder="Search games, rooms, or styles…" data-catalog-search autocomplete="off"></label>
        <div class="filter-row">
            <label><span class="sr-only">Filter by category</span><select data-catalog-category><option value="all">All categories</option><?php foreach($categories as $category): ?><option value="<?= $esc(mb_strtolower($category)) ?>"><?= $esc($category) ?></option><?php endforeach; ?></select></label>
            <label><span class="sr-only">Sort games</span><select data-catalog-sort><option value="featured">Featured first</option><option value="name">Name A–Z</option><option value="recent">Recently played</option></select></label>
        </div>
    </div>
</div>
<section class="section-tight shell" aria-labelledby="catalog-results-heading">
    <div class="split u-mb"><h2 id="catalog-results-heading" class="sr-only">Game results</h2><p class="muted" data-catalog-count><?= $esc((string) count($games)) ?> games</p><?php if($user === null): ?><p class="fine-print">Guest progress stays on this device.</p><?php endif; ?></div>
    <?php if ($games !== []): ?>
        <div class="game-grid"><?php foreach($games as $game){ require dirname(__DIR__) . '/partials/game-card.php'; } ?></div>
        <div class="empty-state is-hidden" data-catalog-empty><span class="empty-icon" aria-hidden="true">⌕</span><h2>No games match that search.</h2><p>Try a broader name or choose all categories.</p></div>
    <?php else: ?>
        <div class="empty-state"><img class="empty-icon" src="<?= $assetUrl('images/onion-mark.svg') ?>" alt=""><h2><?= $mode === 'favorites' ? 'Your favorite chair is still open.' : 'Nothing here yet.' ?></h2><p><?= $mode === 'favorites' ? 'Tap the heart on any game to keep it close.' : 'Play a game and it will appear here.' ?></p><a class="button button-gold" href="<?= $url('games.index', [], '/games') ?>">Browse the lobby</a></div>
    <?php endif; ?>
</section>
