<?php
$advertisement = isset($data['advertisement']) && is_array($data['advertisement']) ? $data['advertisement'] : [];
$adDestination = (string) ($advertisement['destination_url'] ?? '/legal/advertising');
$isExternalAd = str_starts_with($adDestination, 'https://');
?>
<aside class="advertising-placement shell" aria-label="Advertisement" data-ad-slot="<?= $esc((string) ($advertisement['slot_key'] ?? '')) ?>" data-frequency-cap="<?= $esc((string) ($advertisement['frequency_cap'] ?? 1)) ?>">
    <div class="advertising-placement-inner">
        <div class="advertising-disclosure"><span>Advertisement</span><a href="<?= $url('legal.advertising', [], '/legal/advertising') ?>">Why am I seeing this?</a></div>
        <div class="advertising-copy"><strong><?= $esc((string) ($advertisement['headline'] ?? '')) ?></strong><p><?= $esc((string) ($advertisement['body'] ?? '')) ?></p></div>
        <a class="button button-small button-ghost" href="<?= $esc($adDestination) ?>" rel="sponsored nofollow<?= $isExternalAd ? ' noopener noreferrer' : '' ?>"<?= $isExternalAd ? ' target="_blank"' : '' ?>><?= $esc((string) ($advertisement['cta_label'] ?? 'Learn more')) ?></a>
    </div>
</aside>
