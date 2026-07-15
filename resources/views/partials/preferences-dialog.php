<dialog class="preferences-dialog" id="preferences-dialog" aria-labelledby="preferences-title">
    <form method="dialog" class="dialog-close-form">
        <button class="icon-button" aria-label="Close preferences">×</button>
    </form>
    <div class="dialog-heading">
        <p class="eyebrow">Make the room yours</p>
        <h2 id="preferences-title">Comfort & accessibility</h2>
        <p>These device preferences take effect immediately. Account settings can save them everywhere.</p>
    </div>
    <form class="preferences-form" data-preferences-form>
        <label class="field">
            <span>Theme</span>
            <select name="theme" data-pref="theme">
                <?php foreach ($themeCatalog as $themeOption): ?>
                    <option value="<?= $esc((string) ($themeOption['slug'] ?? '')) ?>"<?= empty($themeOption['available']) ? ' disabled' : '' ?>><?= $esc((string) ($themeOption['label'] ?? 'Theme')) ?><?= empty($themeOption['available']) ? ' — membership or theme pack required' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <fieldset class="segmented-field">
            <legend>Appearance</legend>
            <label><input type="radio" name="appearance" value="dark" data-pref="appearance"> Dark</label>
            <label><input type="radio" name="appearance" value="light" data-pref="appearance"> Light</label>
            <label><input type="radio" name="appearance" value="system" data-pref="appearance"> System</label>
        </fieldset>
        <div class="preference-grid">
            <label class="switch-row"><span><strong>Animation</strong><small>Transitions and celebrations</small></span><input type="checkbox" name="animations" data-pref="animations" role="switch"></label>
            <label class="switch-row"><span><strong>Ambient particles</strong><small>Gentle lounge sparkle</small></span><input type="checkbox" name="particles" data-pref="particles" role="switch"></label>
            <label class="switch-row"><span><strong>Reduced motion</strong><small>Minimize all movement</small></span><input type="checkbox" name="reducedMotion" data-pref="reducedMotion" role="switch"></label>
            <label class="switch-row"><span><strong>Large text</strong><small>Increase interface type</small></span><input type="checkbox" name="largeText" data-pref="largeText" role="switch"></label>
            <label class="switch-row"><span><strong>Compact layout</strong><small>Fit more cards on screen</small></span><input type="checkbox" name="compact" data-pref="compact" role="switch"></label>
            <label class="switch-row"><span><strong>High contrast</strong><small>Stronger edges and labels</small></span><input type="checkbox" name="highContrast" data-pref="highContrast" role="switch"></label>
            <label class="switch-row"><span><strong>Colorblind card suits</strong><small>Shapes plus distinct suit colors</small></span><input type="checkbox" name="colorblindSuits" data-pref="colorblindSuits" role="switch"></label>
            <label class="switch-row"><span><strong>Confirm fictional wagers</strong><small>Ask before each round</small></span><input type="checkbox" name="confirmWagers" data-pref="confirmWagers" role="switch"></label>
        </div>
        <div class="range-grid">
            <label class="field"><span>Sound effects <output data-volume-output="effects">70%</output></span><input type="range" min="0" max="100" value="70" name="effectsVolume" data-pref="effectsVolume"></label>
            <label class="field"><span>Music <output data-volume-output="music">35%</output></span><input type="range" min="0" max="100" value="35" name="musicVolume" data-pref="musicVolume"></label>
        </div>
        <label class="field"><span>Session reminder</span><select name="sessionReminder" data-pref="sessionReminder"><option value="off">Off</option><option value="30">Every 30 minutes</option><option value="45">Every 45 minutes</option><option value="60">Every 60 minutes</option></select></label>
        <div class="dialog-actions">
            <button type="button" class="button button-ghost" data-preferences-reset>Reset device settings</button>
            <button type="button" class="button button-gold" data-dialog-close="preferences-dialog">Done</button>
        </div>
    </form>
</dialog>
