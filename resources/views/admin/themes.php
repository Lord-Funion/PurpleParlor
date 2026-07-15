<?php
$themeSettings=isset($data['theme'])&&is_array($data['theme'])?$data['theme']:['name'=>'Purple Parlor','slug'=>'purple-parlor','page'=>'#160c1f','page_deep'=>'#0e0815','panel'=>'#281432','panel_raised'=>'#351b40','purple'=>'#8e51b2','lavender'=>'#d9b8ef','gold'=>'#e8b85d','cream'=>'#fff3d2','ink'=>'#fff9e8'];
?>
<div class="admin-heading"><div><p class="eyebrow">Configurable design tokens</p><h1 class="heading-admin">Themes</h1><p class="muted">Theme values are stored as validated configuration and published to a same-origin generated stylesheet. No unsafe inline style is required.</p></div><a class="button button-ghost" href="<?= $url('admin.themes.preview', ['slug'=>$themeSettings['slug']], '/admin/themes/'.rawurlencode((string)$themeSettings['slug']).'/preview') ?>">Open safe preview</a></div>
<section class="two-column">
    <form class="card settings-section" method="post" action="<?= $url('admin.themes.update', ['slug'=>$themeSettings['slug']], '/admin/themes/'.rawurlencode((string)$themeSettings['slug'])) ?>">
        <?= function_exists('csrf_field')?csrf_field():'' ?>
        <div><h2>Theme identity</h2><p class="muted">Purple Parlor, Midnight Lavender, Cozy Fireplace, Royal Plum, Soft Daylight, and High Contrast are the installed presets.</p></div>
        <div class="form-grid"><label class="field"><span>Name</span><input name="name" value="<?= $esc($themeSettings['name']) ?>" maxlength="60" required></label><label class="field"><span>Slug</span><input name="slug" value="<?= $esc($themeSettings['slug']) ?>" pattern="[a-z0-9-]+" maxlength="60" required></label></div>
        <fieldset class="form-grid"><legend>Core color tokens</legend>
            <label class="field"><span>Page background</span><input type="color" name="page" value="<?= $esc($themeSettings['page']) ?>" required></label>
            <label class="field"><span>Deep background</span><input type="color" name="page_deep" value="<?= $esc($themeSettings['page_deep']) ?>" required></label>
            <label class="field"><span>Panel</span><input type="color" name="panel" value="<?= $esc($themeSettings['panel']) ?>" required></label>
            <label class="field"><span>Raised panel</span><input type="color" name="panel_raised" value="<?= $esc($themeSettings['panel_raised']) ?>" required></label>
            <label class="field"><span>Primary purple</span><input type="color" name="purple" value="<?= $esc($themeSettings['purple']) ?>" required></label>
            <label class="field"><span>Lavender</span><input type="color" name="lavender" value="<?= $esc($themeSettings['lavender']) ?>" required></label>
            <label class="field"><span>Warm gold</span><input type="color" name="gold" value="<?= $esc($themeSettings['gold']) ?>" required></label>
            <label class="field"><span>Cream</span><input type="color" name="cream" value="<?= $esc($themeSettings['cream']) ?>" required></label>
            <label class="field"><span>Primary text</span><input type="color" name="ink" value="<?= $esc($themeSettings['ink']) ?>" required></label>
        </fieldset>
        <label class="field"><span>Reason for change</span><textarea name="reason" maxlength="500" required></textarea></label>
        <div class="callout"><strong>Accessibility validation required.</strong><p>The server must reject unsafe formats and flag color pairs that do not meet WCAG 2.2 AA contrast targets. High Contrast cannot be disabled while it is the only sufficient option.</p></div>
        <button class="button button-gold">Save and rebuild theme stylesheet</button>
    </form>
    <aside class="stack"><article class="card card-pad stack"><p class="eyebrow">Installed presets</p><h2 class="heading-card">Six complete rooms</h2><ul class="benefit-list"><li>Purple Parlor — default dark lounge</li><li>Midnight Lavender — cool blue-purple</li><li>Cozy Fireplace — ember and warm plum</li><li>Royal Plum — rich magenta-plum</li><li>Soft Daylight — low-glare light appearance</li><li>High Contrast — strong white, gold, and cyan focus</li></ul></article><div class="callout callout-gold"><strong>Generated output only.</strong><p>Publish a versioned, same-origin CSS file containing validated custom properties. Never place user-entered CSS, HTML, JavaScript, URLs, or unescaped values into the stylesheet.</p></div></aside>
</section>
