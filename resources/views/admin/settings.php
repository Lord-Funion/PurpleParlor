<?php
$settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : [];
$themes = [
    'purple-parlor' => 'Purple Parlor',
    'midnight-lavender' => 'Midnight Lavender',
    'cozy-fireplace' => 'Cozy Fireplace',
    'royal-plum' => 'Royal Plum',
    'soft-daylight' => 'Soft Daylight',
    'high-contrast' => 'High Contrast',
];
?>
<div class="admin-heading">
    <div>
        <p class="eyebrow">Configuration</p>
        <h1 class="heading-admin">Site settings</h1>
        <p class="muted">Changes are validated, permission-checked, and audited. Sensitive merchant fields live in Adult Owner controls.</p>
    </div>
</div>
<form class="card settings-section" method="post" action="<?= $url('admin.settings.update', [], '/admin/settings') ?>">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
    <h2>Brand & public site</h2>
    <div class="form-grid">
        <label class="field"><span>Site name</span><input name="site_name" value="<?= $esc((string)($settings['site_name'] ?? $app['name'] ?? 'The Purple Parlor')) ?>" maxlength="80" required></label>
        <label class="field"><span>Creator display name</span><input name="creator_name" value="<?= $esc((string)($settings['creator_name'] ?? $app['creator_name'] ?? 'Lord Funion')) ?>" maxlength="80" required></label>
        <label class="field"><span>Public URL</span><input type="url" name="app_url" value="<?= $esc((string)($settings['app_url'] ?? $app['url'] ?? '')) ?>" maxlength="500" required></label>
        <label class="field"><span>Support email</span><input type="email" name="support_email" value="<?= $esc((string)($settings['support_email'] ?? $app['support_email'] ?? '')) ?>" maxlength="254" required></label>
        <label class="field"><span>Minimum age</span><input type="number" name="minimum_age" min="18" max="25" value="<?= $esc((string)($settings['minimum_age'] ?? 18)) ?>" required><small>Every age-policy change is Adult Owner controlled and creates a new policy version.</small></label>
        <label class="field"><span>Default theme</span><select name="default_theme"><?php foreach ($themes as $slug => $label): ?><option value="<?= $esc($slug) ?>"<?= ($settings['default_theme'] ?? 'purple-parlor') === $slug ? ' selected' : '' ?>><?= $esc($label) ?></option><?php endforeach; ?></select></label>
    </div>
    <?php if (!empty($user['is_adult_owner'])): ?>
        <label class="checkbox-row"><input type="checkbox" name="confirm_age_lowering" value="1"><span>I explicitly approve lowering the configured minimum age if this submission lowers it. I understand this creates a new age-policy version.</span></label>
    <?php endif; ?>
    <label class="switch-row"><span><strong>Public indexing</strong><small>Enabling indexing requires a recently reauthenticated Adult Owner and an HTTPS public URL.</small></span><input type="checkbox" name="public_indexing" value="1" role="switch"<?= !empty($settings['public_indexing']) ? ' checked' : '' ?>></label>
    <label class="switch-row"><span><strong>Maintenance mode</strong><small>Show the branded maintenance page to non-administrators.</small></span><input type="checkbox" name="maintenance_mode" value="1" role="switch"<?= !empty($settings['maintenance_mode']) ? ' checked' : '' ?>></label>
    <label class="field"><span>Reason for change</span><textarea name="reason" minlength="5" maxlength="500" required></textarea></label>
    <button class="button button-gold">Save audited settings</button>
</form>
