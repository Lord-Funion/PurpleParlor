<?php
$game = isset($data['game']) && is_array($data['game']) ? $data['game'] : ['slug'=>'plinko','name'=>'Plinko','category'=>'Arcade','description'=>'A cozy play-money game.'];
$slug = preg_replace('/[^a-z0-9-]/', '', (string)($game['slug'] ?? 'game')) ?: 'game';
$rules = isset($game['rules']) && is_array($game['rules']) ? $game['rules'] : ['Choose a fictional wager within the displayed limits.','Start the round; the PHP server securely determines the outcome.','Any fictional payout is recorded in the Cozy Coin ledger.'];
$paytable = isset($game['paytable']) && is_array($game['paytable']) ? $game['paytable'] : [];
$probability = isset($game['probability']) && is_array($game['probability']) ? $game['probability'] : [];
$tutorial = isset($game['tutorial']) && is_array($game['tutorial'])
    ? $game['tutorial']
    : [(string)($game['tutorial'] ?? 'Review the rules, choose a small fictional wager, and start one round at a time.')];
?>
<section class="game-shell shell">
    <div class="game-topbar">
        <div class="cluster"><a class="button button-small button-ghost" href="<?= $url('games.index', [], '/games') ?>">← Lobby</a><div><p class="eyebrow"><?= $esc((string)($game['category'] ?? 'Game')) ?></p><h1 class="heading-game"><?= $esc((string)($game['name'] ?? 'Parlor game')) ?></h1></div></div>
        <div class="cluster"><button class="button button-small button-ghost" type="button" data-audio-toggle aria-pressed="false" aria-label="Mute all audio">Sound</button><button class="button button-small button-ghost" type="button" data-game-fullscreen aria-pressed="false">Fullscreen</button></div>
    </div>
    <div class="callout callout-gold u-mb"><strong>Fictional play only:</strong> this game uses Cozy Coins with no cash value. The server—not this browser—selects every outcome.</div>
    <?php if(!empty($data['next_server_seed_hash'])): ?><details class="card card-pad u-mb"><summary>Pre-published next-round commitment</summary><p class="fine-print">This SHA-256 hash was published before your next fictional wager is accepted. Its rotated server seed is revealed only after that round settles.</p><code class="code-wrap"><?= $esc((string)$data['next_server_seed_hash']) ?></code></details><?php endif; ?>
    <div class="game-frame" data-game-container data-game-client data-game-slug="<?= $esc($slug) ?>" data-game-name="<?= $esc((string)($game['name'] ?? 'Parlor game')) ?>" data-api-base="<?= $esc((string)($app['base_path'] ?? '')) ?>" tabindex="0">
        <div class="game-stage" id="game-stage" data-game-root>
            <div class="game-visual-mount" data-game-outcome>
                <div class="game-placeholder" data-game-placeholder>
                    <svg viewBox="0 0 64 64" aria-hidden="true"><use href="<?= $assetUrl('icons/game-icons.svg') ?>#<?= $esc($slug) ?>"></use></svg>
                    <h2>Preparing <?= $esc((string)($game['name'] ?? 'the table')) ?>…</h2>
                    <p class="muted">Loading the illustrated game table. Every result still comes from the server.</p>
                    <noscript><p class="notice notice-warning">JavaScript is required for the interactive game table. Rules and probability information remain available below.</p></noscript>
                </div>
            </div>
            <p class="sr-only" data-game-announcer aria-live="polite" aria-atomic="true">The game table is ready.</p>
            <button class="button button-small game-skip-animation" type="button" data-game-skip-animation hidden>Skip animation</button>
        </div>
        <aside class="game-side" aria-label="Game controls and account summary">
            <form class="card form-stack" method="post" action="<?= $url('api.games.round', ['slug'=>$slug], '/api/games/' . rawurlencode($slug) . '/round') ?>" data-wager-form data-game-controls>
                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                <input type="hidden" name="idempotency_key" value="<?= $esc((string)($data['idempotency_key'] ?? '')) ?>">
                <div><p class="eyebrow">Practice round</p><h2 class="heading-sm">Fictional wager</h2></div>
                <label class="field"><span>Cozy Coins</span><input name="wager" type="number" inputmode="numeric" min="<?= $esc((string)($game['min_wager'] ?? 1)) ?>" max="<?= $esc((string)($game['max_wager'] ?? 100)) ?>" step="<?= $esc((string)($game['wager_step'] ?? 1)) ?>" value="<?= $esc((string)($game['default_wager'] ?? 5)) ?>" required></label>
                <div data-game-actions><button class="button button-gold button-wide" type="submit" value="play">Start round</button></div>
                <p class="fine-print" data-game-status aria-live="polite">Ready for a server-authoritative round.</p>
                <p class="fine-print">Autoplay is off. Every new round requires an action from you.</p>
            </form>
            <div class="card"><p class="eyebrow">Your session</p><div class="stats-grid u-grid-one"><div class="stat"><strong><?= $esc(number_format((int)($user['cozy_coins'] ?? $data['guest_balance'] ?? 1000))) ?></strong><span>Cozy Coins</span></div><div class="stat"><strong><?= $esc((string)($data['session_minutes'] ?? 0)) ?> min</strong><span>Time in this session</span></div></div><a class="button button-small button-ghost button-wide" href="<?= $url('take-a-break', [], '/take-a-break') ?>">Take a break</a></div>
            <div class="card"><p class="eyebrow">Need a hand?</p><div class="stack u-mt-sm"><button class="button button-small button-ghost" type="button" data-dialog-open="tutorial-dialog">Quick tutorial</button><a class="button button-small button-ghost" href="#game-information">Rules & odds</a></div></div>
        </aside>
    </div>
    <section class="game-footer-tabs card card-pad" id="game-information">
        <div class="subnav" role="tablist" aria-label="Game information"><button type="button" role="tab" id="tab-rules" aria-selected="true" aria-controls="panel-rules" data-tab>Rules</button><button type="button" role="tab" id="tab-paytable" aria-selected="false" aria-controls="panel-paytable" tabindex="-1" data-tab>Paytable</button><button type="button" role="tab" id="tab-probability" aria-selected="false" aria-controls="panel-probability" tabindex="-1" data-tab>Probabilities</button><button type="button" role="tab" id="tab-history" aria-selected="false" aria-controls="panel-history" tabindex="-1" data-tab>Recent rounds</button></div>
        <div id="panel-rules" role="tabpanel" aria-labelledby="tab-rules" tabindex="0" class="prose"><h2>How to play</h2><ol><?php foreach($rules as $rule): ?><li><?= $esc(is_array($rule) ? ($rule['text'] ?? '') : $rule) ?></li><?php endforeach; ?></ol><p><?= $esc((string)($game['responsible_reminder'] ?? 'Choose comfortable limits, take breaks, and stop whenever play is no longer fun.')) ?></p></div>
        <div id="panel-paytable" role="tabpanel" aria-labelledby="tab-paytable" tabindex="0" hidden><h2>Paytable</h2><?php if($paytable): ?><div class="data-table-wrap"><table class="data-table"><thead><tr><th>Outcome</th><th>Fictional return</th></tr></thead><tbody><?php foreach($paytable as $row): ?><tr><td><?= $esc((string)($row['label'] ?? $row[0] ?? '')) ?></td><td><?= $esc((string)($row['payout'] ?? $row[1] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="muted">The current game configuration’s complete paytable is supplied by the server.</p><?php endif; ?></div>
        <div id="panel-probability" role="tabpanel" aria-labelledby="tab-probability" tabindex="0" hidden class="prose"><h2>Probability disclosure</h2><p><?= $esc((string)($probability['summary'] ?? 'Each valid outcome is generated on the server according to the published game rules. Paying never changes probabilities or theoretical return.')) ?></p><p><strong>Theoretical return:</strong> <?= $esc((string)($probability['theoretical_return'] ?? 'See the game-specific configuration before wagering.')) ?></p><a href="<?= $url('fairness.verify', [], '/fairness/verify') ?>">Reproducible outcome-verification information</a></div>
        <div id="panel-history" role="tabpanel" aria-labelledby="tab-history" tabindex="0" hidden><h2>Recent rounds</h2><p class="muted">Only completed fictional rounds from this account or guest session appear here.</p><div data-round-history></div></div>
    </section>
</section>
<dialog class="preferences-dialog" id="tutorial-dialog" aria-labelledby="tutorial-title"><form method="dialog" class="dialog-close-form"><button class="icon-button" aria-label="Close tutorial">×</button></form><div class="dialog-heading"><p class="eyebrow">A quick tour</p><h2 id="tutorial-title"><?= $esc((string)($game['name'] ?? 'Game')) ?> tutorial</h2></div><div class="prose"><ol><?php foreach($tutorial as $step): ?><li><?= $esc(is_array($step) ? (string)($step['text'] ?? '') : (string)$step) ?></li><?php endforeach; ?></ol><p>Keyboard controls are shown beside interactive controls. Touch targets are sized for phones and tablets.</p></div><div class="dialog-actions"><button class="button button-gold" type="button" data-dialog-close="tutorial-dialog">Ready</button></div></dialog>
<script type="module" src="<?= $assetUrl('js/games/game-client.js') ?>"></script>
