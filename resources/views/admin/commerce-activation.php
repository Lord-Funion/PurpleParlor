<?php
$guards = isset($data['environment_guards']) && is_array($data['environment_guards']) ? $data['environment_guards'] : [];
$locked = !empty($data['activation_lock']);
?>
<div class="admin-heading">
    <div><p class="eyebrow">Adult Owner authority</p><h1 class="heading-admin">Live payment activation</h1><p class="muted">This control changes only the final production lock. Provider identity, banking, tax, and merchant verification remain entirely inside the provider account.</p></div>
    <span class="tag <?= $locked ? 'tag-gold' : '' ?>">Lock: <?= $locked ? 'ON' : 'OFF' ?></span>
</div>
<section class="two-column">
    <article class="card card-pad stack">
        <h2 class="heading-card">Fail-closed environment guards</h2>
        <ul class="check-list"><?php foreach ($guards as $label => $passed): ?><li><span><?= $esc($label) ?></span><span class="status status-<?= $passed ? 'success' : 'warning' ?>"><?= $passed ? 'Ready' : 'Required' ?></span></li><?php endforeach; ?></ul>
        <div class="notice notice-warning">No client secret, access token, bank detail, tax record, identity document, or payout destination is displayed or accepted on this screen.</div>
    </article>
    <article class="card settings-section">
        <?php if ($locked): ?>
            <h2>Release production lock</h2>
            <p class="muted">All guards must pass. The change requires recent Adult Owner reauthentication and is written permanently to the audit chain.</p>
            <form class="stack" method="post" action="<?= $url('admin.payments.lock', [], '/admin/payments/activation-lock') ?>">
                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                <input type="hidden" name="action" value="unlock">
                <label class="field"><span>Type UNLOCK LIVE PAYMENTS</span><input name="confirmation" autocomplete="off" required></label>
                <label class="field"><span>Reason and approval reference</span><textarea name="reason" minlength="10" maxlength="500" required></textarea></label>
                <button class="button button-danger"<?= empty($data['can_unlock']) ? ' disabled' : '' ?>>Release production lock</button>
            </form>
        <?php else: ?>
            <h2>Restore production lock</h2>
            <p class="muted">Locking is always permitted and takes effect fail-closed before the database audit transaction runs.</p>
            <form class="stack" method="post" action="<?= $url('admin.payments.lock', [], '/admin/payments/activation-lock') ?>">
                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                <input type="hidden" name="action" value="lock">
                <label class="field"><span>Reason</span><textarea name="reason" minlength="10" maxlength="500" required></textarea></label>
                <button class="button button-gold">Lock production payments</button>
            </form>
        <?php endif; ?>
    </article>
</section>
