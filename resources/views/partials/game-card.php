<?php
$game = isset($game) && is_array($game) ? $game : [];
$slug = (string) ($game['slug'] ?? 'game');
$name = (string) ($game['name'] ?? 'Parlor Game');
$category = (string) ($game['category'] ?? 'Arcade');
$description = (string) ($game['description'] ?? 'A cozy play-money game.');
$icon = (string) ($game['icon'] ?? $slug);
$favorite = (bool) ($game['favorite'] ?? false);
$recent = $game['recent_label'] ?? null;
$available = !array_key_exists('available', $game) || (bool) $game['available'];
?>
<article class="game-card<?= $available ? '' : ' is-unavailable' ?>" data-game-card data-search-text="<?= $esc(mb_strtolower($name . ' ' . $category . ' ' . $description)) ?>">
    <div class="game-art game-art-<?= $esc($slug) ?>" aria-hidden="true">
        <svg viewBox="0 0 64 64" role="img"><use href="<?= $assetUrl('icons/game-icons.svg') ?>#<?= $esc($icon) ?>"></use></svg>
        <span class="game-shine"></span>
    </div>
    <div class="game-card-body">
        <div class="game-card-topline"><span class="tag"><?= $esc($category) ?></span><?php if ($recent): ?><span class="muted"><?= $esc($recent) ?></span><?php endif; ?></div>
        <h3><a href="<?= $url('games.show', ['slug' => $slug], '/games/' . rawurlencode($slug)) ?>"><?= $esc($name) ?></a></h3>
        <p><?= $esc($description) ?></p>
        <div class="game-card-actions">
            <a class="button button-small button-primary" href="<?= $url('games.show', ['slug' => $slug], '/games/' . rawurlencode($slug)) ?>"><?= $available ? 'Play' : 'Learn more' ?><span class="sr-only"> <?= $esc($name) ?></span></a>
            <form method="post" action="<?= $url('favorites.toggle', ['slug' => $slug], '/favorites/' . rawurlencode($slug)) ?>">
                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                <button class="icon-button favorite-button<?= $favorite ? ' is-favorite' : '' ?>" aria-label="<?= $favorite ? 'Remove ' : 'Add ' ?><?= $esc($name) ?> <?= $favorite ? 'from' : 'to' ?> favorites" aria-pressed="<?= $favorite ? 'true' : 'false' ?>">♥</button>
            </form>
        </div>
    </div>
</article>
