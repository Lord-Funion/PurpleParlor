<?php
$days = isset($data['calendar']) && is_array($data['calendar']) ? $data['calendar'] : [
    ['day'=>'Day 1','amount'=>100,'state'=>'claimed'],['day'=>'Day 2','amount'=>125,'state'=>'claimed'],['day'=>'Day 3','amount'=>150,'state'=>'current'],['day'=>'Day 4','amount'=>175,'state'=>'upcoming'],['day'=>'Day 5','amount'=>200,'state'=>'upcoming'],['day'=>'Day 6','amount'=>225,'state'=>'upcoming'],['day'=>'Day 7','amount'=>300,'state'=>'upcoming'],
];
$missions = isset($data['missions']) && is_array($data['missions']) ? $data['missions'] : [
    ['icon'=>'♠','name'=>'Table sampler','description'=>'Complete 3 rounds across any table games.','progress'=>2,'goal'=>3,'reward'=>'40 Parlor Stars'],
    ['icon'=>'✦','name'=>'Puzzle pause','description'=>'Finish one no-wager casual game.','progress'=>0,'goal'=>1,'reward'=>'Cozy lantern badge'],
    ['icon'=>'◉','name'=>'Know before you play','description'=>'Read one complete probability disclosure.','progress'=>1,'goal'=>1,'reward'=>'25 Parlor Stars'],
];
?>
<header class="page-hero"><div class="shell page-hero-inner"><nav class="breadcrumbs" aria-label="Breadcrumb"><a href="<?= $url('home', [], '/') ?>">Parlor</a><span aria-hidden="true">/</span><span>Rewards</span></nav><p class="eyebrow">Always free, never urgent</p><h1>Daily rewards</h1><p class="lede">A forgiving calendar of free fictional Cozy Coins. Missing a day never wipes out everything you earned.</p><nav class="subnav" aria-label="Rewards sections"><a aria-current="page" href="<?= $url('rewards.index', [], '/rewards') ?>">Daily reward</a><a href="<?= $url('missions.index', [], '/missions') ?>">Missions</a><a href="<?= $url('achievements.index', [], '/achievements') ?>">Achievements</a><a href="<?= $url('leaderboards.index', [], '/leaderboards') ?>">Leaderboards</a></nav></div></header>
<section class="section shell">
    <div class="reward-hero">
        <article class="card card-pad">
            <div class="section-heading"><div><p class="eyebrow">Seven-day path</p><h2>Your cozy calendar</h2><p>Claims use server time and are protected against repeat requests.</p></div><span class="tag tag-gold"><?= $esc((string)($data['server_date_label'] ?? 'Today')) ?></span></div>
            <div class="reward-calendar">
                <?php foreach($days as $day): $state=(string)($day['state'] ?? 'upcoming'); ?><div class="reward-day<?= $state === 'claimed' ? ' is-claimed' : ($state === 'current' ? ' is-current' : '') ?>"><small><?= $esc((string)$day['day']) ?></small><span aria-hidden="true"><?= $state === 'claimed' ? '✓' : '◉' ?></span><strong><?= $esc(number_format((int)$day['amount'])) ?></strong><small>Coins</small></div><?php endforeach; ?>
            </div>
            <form method="post" action="<?= $url('rewards.claim', [], '/rewards/claim') ?>" class="form-actions u-mt-lg">
                <?= function_exists('csrf_field') ? csrf_field() : '' ?><input type="hidden" name="claim_key" value="<?= $esc((string)($data['claim_key'] ?? '')) ?>">
                <button class="button button-gold"<?= !empty($data['claimed_today']) ? ' disabled' : '' ?>><?= !empty($data['claimed_today']) ? 'Claimed for today' : 'Claim today’s free reward' ?></button>
                <span class="fine-print">No purchase, wager, or streak payment required.</span>
            </form>
        </article>
        <aside class="card card-pad stack-lg"><div><p class="eyebrow">Your progress</p><h2>Milestones stay cozy</h2></div><div class="stats-grid"><div class="stat"><strong><?= $esc((string)($data['current_day'] ?? 3)) ?></strong><span>Current milestone</span></div><div class="stat"><strong><?= $esc((string)($data['best_day'] ?? 7)) ?></strong><span>Best milestone kept</span></div><div class="stat"><strong><?= $esc(number_format((int)($data['coins_claimed'] ?? 1175))) ?></strong><span>Free Coins claimed</span></div></div><div class="callout"><strong>No streak punishment.</strong><p class="fine-print">If you miss a day, resume from the latest protected milestone instead of losing your entire history.</p></div><a class="button button-ghost" href="<?= $url('responsible-play', [], '/responsible-play') ?>">Set a daily time limit</a></aside>
    </div>
</section>
<section class="section-tight shell"><div class="section-heading"><div><p class="eyebrow">This week</p><h2>Mission highlights</h2><p>Optional goals reward fictional points or fixed cosmetics—never randomized paid items.</p></div><a class="button button-ghost" href="<?= $url('missions.index', [], '/missions') ?>">All missions</a></div><div class="mission-list"><?php foreach($missions as $mission): $percent=min(100,(int)round(((int)$mission['progress']/max(1,(int)$mission['goal']))*100)); ?><article class="mission-card card"><span class="mission-icon" aria-hidden="true"><?= $esc($mission['icon']) ?></span><div class="stack"><div class="split"><div><h3><?= $esc($mission['name']) ?></h3><p class="muted"><?= $esc($mission['description']) ?></p></div><span class="tag"><?= $esc($mission['reward']) ?></span></div><progress class="progress-native" aria-label="<?= $esc($mission['name']) ?> progress" max="<?= $esc((string)$mission['goal']) ?>" value="<?= $esc((string)$mission['progress']) ?>"><?= $esc((string)$mission['progress']) ?> of <?= $esc((string)$mission['goal']) ?></progress><p class="fine-print"><?= $esc((string)$mission['progress']) ?> of <?= $esc((string)$mission['goal']) ?> complete</p></div><a class="button button-small button-ghost" href="<?= $url('missions.show', ['id'=>$mission['name']], '/missions') ?>">View</a></article><?php endforeach; ?></div></section>
