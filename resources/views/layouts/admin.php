<?php
declare(strict_types=1);
$page = isset($page) && is_array($page) ? $page : [];
$page['private'] = true;
$page['body_class'] = trim(($page['body_class'] ?? '') . ' admin-page');
$adminContentView = $contentView ?? null;
ob_start();
?>
<div class="admin-shell shell section">
    <aside class="admin-nav card" aria-label="Administration">
        <p class="eyebrow">Administration</p>
        <nav>
            <a href="<?= function_exists('route') ? e(route('admin.dashboard')) : '/admin' ?>">Overview</a>
            <a href="<?= function_exists('route') ? e(route('admin.users')) : '/admin/users' ?>">Users & access</a>
            <a href="<?= function_exists('route') ? e(route('admin.games')) : '/admin/games' ?>">Games</a>
            <a href="<?= function_exists('route') ? e(route('admin.rewards')) : '/admin/rewards' ?>">Rewards</a>
            <a href="<?= function_exists('route') ? e(route('admin.commerce')) : '/admin/commerce' ?>">Commerce</a>
            <a href="<?= function_exists('route') ? e(route('admin.content')) : '/admin/content' ?>">Content</a>
            <a href="<?= function_exists('route') ? e(route('admin.section', ['section' => 'themes'])) : '/admin/themes' ?>">Themes</a>
            <a href="<?= function_exists('route') ? e(route('admin.health')) : '/admin/health' ?>">System health</a>
            <a href="<?= function_exists('route') ? e(route('admin.audit')) : '/admin/audit' ?>">Audit log</a>
            <a href="<?= function_exists('route') ? e(route('admin.settings')) : '/admin/settings' ?>">Settings</a>
        </nav>
    </aside>
    <div class="admin-workspace">
        <?php
        if (is_string($adminContentView)) {
            $viewRoot = dirname(__DIR__);
            $candidate = $adminContentView;
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $candidate)) {
                $candidate = $viewRoot . DIRECTORY_SEPARATOR
                    . str_replace(['.', '/', '\\'], DIRECTORY_SEPARATOR, preg_replace('/\.php$/', '', $candidate))
                    . '.php';
            }
            $resolved = realpath($candidate);
            $root = realpath($viewRoot);
            if ($resolved !== false && $root !== false && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                require $resolved;
            }
        }
        ?>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
unset($contentView);
require __DIR__ . '/app.php';
