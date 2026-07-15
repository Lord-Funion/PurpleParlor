<?php if (!empty($data['show_cookie_consent'])): ?>
<aside class="cookie-consent card card-pad" aria-labelledby="cookie-consent-title">
    <div><p class="eyebrow">Your privacy choices</p><h2 id="cookie-consent-title" class="heading-card">Cookies & local storage</h2><p>Strictly necessary storage supports security, age confirmation, and requested features. Optional first-party analytics and advertising storage stay off unless you choose them.</p></div>
    <form method="post" action="<?= $url('privacy.consent', [], '/privacy/consent') ?>" class="form-stack">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <div class="button-row"><button class="button button-gold" name="choice" value="essential">Necessary only</button><button class="button button-ghost" name="choice" value="analytics">Allow configured optional storage</button><a class="button button-ghost" href="<?= $url('legal.cookies', [], '/legal/cookies') ?>">Cookie details</a></div>
    </form>
</aside>
<?php endif; ?>
