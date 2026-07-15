<?php $record = isset($data['theme_record']) && is_array($data['theme_record']) ? $data['theme_record'] : []; ?>
<div class="admin-heading">
    <div><p class="eyebrow">Same-origin preview</p><h1 class="heading-admin"><?= $esc((string)($record['name'] ?? 'Theme')) ?></h1><p class="muted">This preview uses the validated generated stylesheet. It does not inject inline CSS, HTML, JavaScript, or URLs from theme input.</p></div>
    <a class="button button-ghost" href="<?= $url('admin.section', ['section' => 'themes'], '/admin/themes') ?>?theme=<?= $esc(rawurlencode((string)($record['slug'] ?? 'purple-parlor'))) ?>">Back to editor</a>
</div>
<section class="two-column">
    <article class="card card-pad stack">
        <p class="eyebrow">Sample room</p>
        <h2 class="heading-card">A quiet table by the fire</h2>
        <p class="muted">Primary text, muted copy, panel surfaces, borders, focus states, and gold accents should remain clear together.</p>
        <div class="button-row"><button class="button button-primary" type="button">Primary action</button><button class="button button-gold" type="button">Gold action</button><button class="button button-ghost" type="button">Quiet action</button></div>
        <div class="notice notice-warning">Contrast validation checks text against page, panel, and raised-panel surfaces before publication.</div>
    </article>
    <article class="card card-pad stack">
        <h2 class="heading-card">Validated tokens</h2>
        <dl class="stack"><?php foreach ($record as $key => $value): ?><?php if (!in_array($key, ['name', 'slug'], true)): ?><div class="split"><dt><?= $esc(str_replace('_', ' ', ucfirst((string)$key))) ?></dt><dd><code><?= $esc((string)$value) ?></code></dd></div><?php endif; ?><?php endforeach; ?></dl>
    </article>
</section>
