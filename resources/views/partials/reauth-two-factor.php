<?php if (!empty($user['two_factor_enabled'])): ?>
<label class="field">
    <span>Authenticator or recovery code</span>
    <input name="two_factor_code" autocomplete="one-time-code" inputmode="text" maxlength="64" required>
    <small>Use the six-digit authenticator code or one unused recovery code.</small>
</label>
<?php endif; ?>
