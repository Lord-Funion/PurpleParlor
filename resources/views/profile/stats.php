<?php
$profileSection = 'stats';
$gameStats = isset($data['game_stats']) && is_array($data['game_stats']) ? $data['game_stats'] : [];
$advanced = !empty($data['advanced_statistics']);
?>
<header class="page-hero"><div class="shell page-hero-inner"><p class="eyebrow">Fictional activity only</p><h1>Personal statistics</h1><p class="lede">See your play patterns clearly. Statistics are entertainment records, not earnings or investment performance.</p></div></header>
<section class="section shell profile-layout">
    <?php require __DIR__ . '/_sidebar.php'; ?>
    <div class="profile-content">
        <section class="card card-pad">
            <div class="section-heading"><div><h2>At a glance</h2><p><?= $esc((string) ($data['period_label'] ?? 'All recorded activity')) ?></p></div></div>
            <div class="stats-grid"><div class="stat"><strong><?= $esc(number_format((int) ($data['rounds'] ?? 0))) ?></strong><span>Rounds completed</span></div><div class="stat"><strong><?= $esc((string) ($data['time_label'] ?? 'Not tracked precisely')) ?></strong><span>Time in games</span></div><div class="stat"><strong><?= $esc(number_format((int) ($data['fictional_net'] ?? 0))) ?></strong><span>Net fictional Coins</span></div><div class="stat"><strong><?= $esc((string) ($data['break_count'] ?? 0)) ?></strong><span>Breaks taken</span></div></div>
        </section>
        <section class="card card-pad">
            <div class="section-heading"><div><h2>By game</h2><p>Net means fictional payouts minus fictional wagers. It has no cash value.</p></div><?php if (!empty($user['entitlements']['statistics.export'])): ?><a class="button button-ghost" href="<?= $url('profile.stats-export', [], '/profile/statistics/export') ?>">Export statistics</a><?php endif; ?></div>
            <?php if ($advanced): ?>
                <?php if ($gameStats === []): ?><div class="empty-state"><p>No per-game statistics have been recorded yet.</p></div><?php else: ?><div class="data-table-wrap"><table class="data-table"><thead><tr><th>Game</th><th>Rounds</th><th>Net fictional Coins</th><th>Time</th></tr></thead><tbody><?php foreach ($gameStats as $stat): ?><tr><td><?= $esc($stat['name']) ?></td><td><?= $esc((string) $stat['rounds']) ?></td><td><?= $esc(number_format((int) $stat['net'])) ?></td><td><?= $esc($stat['time']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            <?php else: ?>
                <div class="callout"><strong>Advanced per-game breakdowns are optional.</strong><p>Cozy Club unlocks this deeper private view. Your total rounds and fictional net remain available above on the free plan.</p><a href="<?= $url('membership.index', [], '/memberships') ?>">Compare fixed membership benefits</a></div>
            <?php endif; ?>
        </section>
        <section class="card card-pad"><h2>Healthy pattern check</h2><p class="muted">You can add reminders, a daily limit, cooldown mode, or self-exclusion at any time.</p><div class="button-row u-mt"><a class="button button-gold" href="<?= $url('responsible-play', [], '/responsible-play') ?>">Review play controls</a><a class="button button-ghost" href="<?= $url('take-a-break', [], '/take-a-break') ?>">Take a break now</a></div></section>
    </div>
</section>
