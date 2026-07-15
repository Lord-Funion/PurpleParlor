<?php
declare(strict_types=1);

/**
 * Primary Purple Parlor layout.
 *
 * Expected globals: $app, $user, $page, $data and $contentView. The renderer may
 * also pass an already-rendered $content string. Every value has a conservative
 * fallback so branded error and installer pages can render during partial setup.
 */
$app = isset($app) && is_array($app) ? $app : [];
$data = isset($data) && is_array($data) ? $data : [];
$page = isset($page) && is_array($page) ? $page : [];
$user = isset($user) && is_array($user) ? $user : null;

$esc = static fn (mixed $value): string => function_exists('e')
    ? e((string) $value)
    : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = static function (string $name, array $parameters = [], string $fallback = '/') use ($esc): string {
    if (function_exists('route')) {
        try {
            return $esc(route($name, $parameters));
        } catch (Throwable) {
            // Installation and error pages remain usable before routing is ready.
        }
    }
    return $esc($fallback);
};
$assetUrl = static function (string $path) use ($esc): string {
    if (function_exists('asset')) {
        return $esc(asset($path));
    }
    return $esc('/assets/' . ltrim($path, '/'));
};

$siteName = (string) ($app['name'] ?? 'The Purple Parlor');
$creatorName = (string) ($app['creator_name'] ?? 'Lord Funion');
$tagline = (string) ($app['tagline'] ?? 'A cozy, play-money social casino and casino-game arcade.');
$supportEmail = (string) ($app['support_email'] ?? 'support@lordfunion.dev');
$pageTitle = (string) ($page['title'] ?? $siteName);
$description = (string) ($page['description'] ?? $tagline);
$canonical = (string) ($page['canonical'] ?? ($app['url'] ?? ''));
$isPrivate = (bool) ($page['private'] ?? false);
$bodyClass = trim((string) ($page['body_class'] ?? ''));
$installedThemes = ['purple-parlor', 'midnight-lavender', 'cozy-fireplace', 'royal-plum', 'soft-daylight', 'high-contrast'];
$themeCatalog = isset($user['theme_catalog']) && is_array($user['theme_catalog'])
    ? $user['theme_catalog']
    : (isset($app['theme_catalog']) && is_array($app['theme_catalog']) ? $app['theme_catalog'] : []);
$allowedThemes = isset($user['available_themes']) && is_array($user['available_themes'])
    ? $user['available_themes']
    : (isset($app['available_themes']) && is_array($app['available_themes']) ? $app['available_themes'] : ['purple-parlor']);
$dataTheme = is_string($data['theme_slug'] ?? null)
    ? $data['theme_slug']
    : (is_string($data['theme'] ?? null) ? $data['theme'] : ($app['default_theme'] ?? 'purple-parlor'));
$theme = $user !== null ? (string) ($user['preferences']['theme'] ?? $dataTheme) : (string) $dataTheme;
if ($user !== null && !in_array($theme, $allowedThemes, true)) {
    $theme = in_array((string) $dataTheme, $installedThemes, true) ? (string) $dataTheme : 'purple-parlor';
} elseif (!in_array($theme, $installedThemes, true)) {
    $theme = 'purple-parlor';
}
$appearance = (string) ($user['preferences']['appearance'] ?? $data['appearance'] ?? 'dark');
$appearance = in_array($appearance, ['dark', 'light', 'system'], true) ? $appearance : 'dark';
$currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$displayName = (string) ($user['display_name'] ?? $user['username'] ?? 'Guest');
$coins = number_format((int) ($user['cozy_coins'] ?? $data['guest_balance'] ?? 0));
$stars = number_format((int) ($user['parlor_stars'] ?? 0));
$isAdmin = $user !== null && !empty($user['is_admin']);
$cosmetics = isset($user['cosmetics']) && is_array($user['cosmetics']) ? $user['cosmetics'] : [];
$accountPreferenceJson = null;
if ($user !== null) {
    $storedPreferences = isset($user['preferences']) && is_array($user['preferences']) ? $user['preferences'] : [];
    $accountPreferences = [];
    foreach ([
        'theme', 'appearance', 'animations', 'particles', 'reduced_motion', 'large_text',
        'compact', 'high_contrast', 'colorblind_suits', 'confirm_wagers',
        'effects_volume', 'music_volume',
    ] as $preferenceKey) {
        if (array_key_exists($preferenceKey, $storedPreferences) && is_scalar($storedPreferences[$preferenceKey])) {
            $accountPreferences[$preferenceKey] = $storedPreferences[$preferenceKey];
        }
    }
    $playControls = isset($storedPreferences['play_controls']) && is_array($storedPreferences['play_controls'])
        ? $storedPreferences['play_controls']
        : [];
    if (isset($playControls['session_reminder_minutes']) && is_scalar($playControls['session_reminder_minutes'])) {
        $accountPreferences['session_reminder_minutes'] = $playControls['session_reminder_minutes'];
    }
    $accountPreferenceJson = json_encode(
        $accountPreferences,
        JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE,
    );
}
?>
<!doctype html>
<html lang="en" data-theme="<?= $esc($theme) ?>" data-appearance="<?= $esc($appearance) ?>" data-allowed-themes="<?= $esc(implode(',', $allowedThemes)) ?>" data-layout-expanded="<?= !empty($user['entitlements']['layout.expanded']) ? 'true' : 'false' ?>"<?php if ($accountPreferenceJson !== null): ?> data-user-preferences="<?= $esc($accountPreferenceJson) ?>"<?php endif; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="<?= $esc($description) ?>">
    <meta name="theme-color" content="#241331">
    <meta name="color-scheme" content="dark light">
    <?php if (!empty($data['csrf_token'])): ?>
        <meta name="csrf-token" content="<?= $esc($data['csrf_token']) ?>">
    <?php endif; ?>
    <?php if ($isPrivate || empty($app['indexing_enabled'])): ?>
        <meta name="robots" content="noindex, nofollow, noarchive">
    <?php endif; ?>
    <?php if ($canonical !== ''): ?>
        <link rel="canonical" href="<?= $esc($canonical) ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= $esc($siteName) ?>">
    <meta property="og:title" content="<?= $esc($pageTitle) ?>">
    <meta property="og:description" content="<?= $esc($description) ?>">
    <title><?= $esc($pageTitle === $siteName ? $siteName : $pageTitle . ' · ' . $siteName) ?></title>
    <link rel="icon" href="<?= $assetUrl('icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="manifest" href="<?= $esc('/manifest.webmanifest') ?>">
    <link rel="stylesheet" href="<?= $assetUrl('css/parlor.css') ?>">
    <?php foreach ((array) ($data['stylesheets'] ?? []) as $stylesheet): ?>
        <?php if (is_string($stylesheet) && preg_match('#^[a-z0-9/_-]+\.css$#i', $stylesheet) === 1): ?>
            <link rel="stylesheet" href="<?= $assetUrl($stylesheet) ?>">
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!empty($app['theme_stylesheet']) && str_starts_with((string) $app['theme_stylesheet'], '/')): ?>
        <link rel="stylesheet" href="<?= $esc($app['theme_stylesheet']) ?>">
    <?php endif; ?>
    <script type="module" src="<?= $assetUrl('js/parlor.js') ?>"></script>
</head>
<body class="<?= $esc($bodyClass) ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="ambient-particles" aria-hidden="true" data-particles></div>

<header class="site-header" data-site-header>
    <div class="header-inner shell">
        <a class="brand" href="<?= $url('home', [], '/') ?>" aria-label="<?= $esc($siteName) ?> home">
            <img src="<?= $assetUrl('images/purple-parlor-logo.svg') ?>" width="252" height="72" alt="<?= $esc($siteName) ?> by <?= $esc($creatorName) ?>">
        </a>
        <button class="icon-button menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-controls="primary-navigation">
            <span class="icon icon-menu" aria-hidden="true"></span><span class="sr-only">Open navigation</span>
        </button>
        <nav class="primary-nav" id="primary-navigation" aria-label="Primary" data-menu>
            <a href="<?= $url('games.index', [], '/games') ?>"<?= str_starts_with($currentPath, '/games') ? ' aria-current="page"' : '' ?>>Games</a>
            <a href="<?= $url('rewards.index', [], '/rewards') ?>"<?= str_starts_with($currentPath, '/rewards') ? ' aria-current="page"' : '' ?>>Rewards</a>
            <a href="<?= $url('membership.index', [], '/memberships') ?>"<?= str_starts_with($currentPath, '/memberships') ? ' aria-current="page"' : '' ?>>Memberships</a>
            <a href="<?= $url('help.index', [], '/help') ?>"<?= str_starts_with($currentPath, '/help') ? ' aria-current="page"' : '' ?>>Help</a>
        </nav>
        <div class="header-actions">
            <?php if ($user !== null): ?>
                <a class="wallet-pill" href="<?= $url('profile.stats', [], '/profile/statistics') ?>" aria-label="Wallet: <?= $esc($coins) ?> Cozy Coins and <?= $esc($stars) ?> Parlor Stars">
                    <span aria-hidden="true">◉</span> <?= $esc($coins) ?><span class="wallet-secondary">✦ <?= $esc($stars) ?></span>
                </a>
                <a class="profile-chip<?= !empty($cosmetics['royal_frame']) ? ' cosmetic-royal-frame' : '' ?>" href="<?= $url('profile.show', [], '/profile') ?>">
                    <span class="avatar-mini" aria-hidden="true"><?= $esc(mb_strtoupper(mb_substr($displayName, 0, 1))) ?></span>
                    <span><?= $esc($displayName) ?><?php if (!empty($cosmetics['animated_crown'])): ?><span class="cosmetic-crown" aria-label="Animated crown cosmetic">♛</span><?php endif; ?><?= !empty($cosmetics['founder_badge']) ? ' ◆' : (!empty($cosmetics['supporter_badge']) ? ' ✦' : '') ?></span>
                </a>
            <?php else: ?>
                <a class="button button-ghost header-login" href="<?= $url('auth.login', [], '/login') ?>">Log in</a>
                <a class="button button-gold header-register" href="<?= $url('auth.register', [], '/register') ?>">Join free</a>
            <?php endif; ?>
            <button class="icon-button global-audio-button" type="button" data-audio-toggle aria-pressed="false" aria-label="Mute all audio"><span aria-hidden="true">♫</span></button>
            <button class="icon-button" type="button" data-dialog-open="preferences-dialog" aria-label="Open display and sound preferences">
                <span class="icon icon-sliders" aria-hidden="true"></span>
            </button>
        </div>
    </div>
</header>

<?php require dirname(__DIR__) . '/partials/flash.php'; ?>

<main id="main-content" class="site-main" tabindex="-1">
    <?php
    if (isset($content) && is_string($content)) {
        echo $content; // Rendered by the trusted server-side view engine.
    } elseif (isset($contentView) && is_string($contentView)) {
        $viewRoot = dirname(__DIR__);
        $candidate = $contentView;
        if (!str_ends_with($candidate, '.php')) {
            $candidate = str_replace(['.', '/'], DIRECTORY_SEPARATOR, $candidate) . '.php';
        }
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $candidate)) {
            $candidate = $viewRoot . DIRECTORY_SEPARATOR . ltrim($candidate, DIRECTORY_SEPARATOR);
        }
        $resolved = realpath($candidate);
        $rootResolved = realpath($viewRoot);
        if ($resolved !== false && $rootResolved !== false && str_starts_with($resolved, $rootResolved . DIRECTORY_SEPARATOR)) {
            require $resolved;
        } else {
            http_response_code(500);
            echo '<section class="shell section"><div class="empty-state"><h1>We could not prepare this room.</h1><p>Please try again shortly.</p></div></section>';
        }
    }
    ?>
</main>

<?php if (isset($data['advertisement']) && is_array($data['advertisement'])): ?>
    <?php require dirname(__DIR__) . '/partials/advertisement.php'; ?>
<?php endif; ?>
<?php require dirname(__DIR__) . '/partials/cookie-consent.php'; ?>
<?php require dirname(__DIR__) . '/partials/currency-disclaimer.php'; ?>

<footer class="site-footer">
    <div class="shell footer-grid">
        <div>
            <img class="footer-mark" src="<?= $assetUrl('images/onion-mark.svg') ?>" width="54" height="54" alt="">
            <p><strong><?= $esc($siteName) ?></strong><br><span>Created by <?= $esc($creatorName) ?></span></p>
            <p class="fine-print">Play-money entertainment for adults <?= $esc((string) ($app['minimum_age'] ?? 18)) ?>+. No real-money wagering or prizes.</p>
        </div>
        <nav aria-label="Explore">
            <h2>Explore</h2>
            <a href="<?= $url('games.index', [], '/games') ?>">Game lobby</a>
            <a href="<?= $url('rewards.index', [], '/rewards') ?>">Daily rewards</a>
            <a href="<?= $url('leaderboards.index', [], '/leaderboards') ?>">Fictional leaderboards</a>
            <a href="<?= $url('shop.index', [], '/shop') ?>">Cosmetic shop</a>
        </nav>
        <nav aria-label="Support">
            <h2>Support</h2>
            <a href="<?= $url('help.index', [], '/help') ?>">Help center</a>
            <a href="<?= $url('responsible-play', [], '/responsible-play') ?>">Healthy play</a>
            <a href="<?= $url('contact.index', [], '/contact') ?>">Contact</a>
            <a href="mailto:<?= $esc($supportEmail) ?>"><?= $esc($supportEmail) ?></a>
        </nav>
        <nav aria-label="Policies">
            <h2>Policies</h2>
            <a href="<?= $url('legal.terms', [], '/legal/terms') ?>">Terms</a>
            <a href="<?= $url('legal.privacy', [], '/legal/privacy') ?>">Privacy</a>
            <a href="<?= $url('legal.virtual-currency', [], '/legal/virtual-currency') ?>">Virtual currency</a>
            <a href="<?= $url('legal.cookies', [], '/legal/cookies') ?>">Cookies</a>
        </nav>
    </div>
    <div class="shell footer-bottom">
        <p>© <?= $esc((string) date('Y')) ?> <?= $esc($creatorName) ?>. All rights reserved.</p>
        <p>No purchase is required to play.</p>
    </div>
</footer>

<?php require dirname(__DIR__) . '/partials/preferences-dialog.php'; ?>
</body>
</html>
