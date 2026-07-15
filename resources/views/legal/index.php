<?php
$policies=[
 ['name'=>'Terms of Service','summary'=>'Account, game, ownership, and service rules.','route'=>'legal.terms','path'=>'/legal/terms'],
 ['name'=>'Privacy Policy','summary'=>'Information collection, use, security, retention, and choices.','route'=>'legal.privacy','path'=>'/legal/privacy'],
 ['name'=>'Subscription Terms','summary'=>'Renewal, cancellation, grace periods, and entitlements.','route'=>'legal.subscription','path'=>'/legal/subscription-terms'],
 ['name'=>'Refund Policy','summary'=>'How legitimate membership and fixed-product refund requests are reviewed.','route'=>'legal.refunds','path'=>'/legal/refund-policy'],
 ['name'=>'Virtual Currency Policy','summary'=>'The exact no-cash-value rules for Cozy Coins and Parlor Stars.','route'=>'legal.virtual-currency','path'=>'/legal/virtual-currency'],
 ['name'=>'Cookie Policy','summary'=>'Necessary, preference, analytics, and advertising storage.','route'=>'legal.cookies','path'=>'/legal/cookies'],
 ['name'=>'Advertising Disclosure','summary'=>'Clear sponsor labels, placement standards, and restricted categories.','route'=>'legal.advertising','path'=>'/legal/advertising-disclosure'],
 ['name'=>'Acceptable Use Policy','summary'=>'Respectful conduct, security, fictional-balance, and IP rules.','route'=>'legal.acceptable-use','path'=>'/legal/acceptable-use'],
 ['name'=>'Copyright Notice','summary'=>'Proprietary rights and third-party license boundaries.','route'=>'legal.copyright','path'=>'/legal/copyright'],
 ['name'=>'Asset Attributions','summary'=>'Original artwork and third-party asset records.','route'=>'legal.attributions','path'=>'/legal/asset-attributions'],
];
?>
<header class="page-hero"><div class="shell page-hero-inner"><p class="eyebrow">Clear rules, editable records</p><h1>Policies & disclosures</h1><p class="lede">Plain-language information about play-money use, privacy, optional purchases, advertising, and ownership.</p></div></header>
<section class="section shell"><div class="notice notice-warning legal-template-label"><div><strong>Launch review required.</strong><p>These project documents are editable templates, not legal advice. The Adult Owner must complete and approve them, with qualified professional review where appropriate, before publishing.</p></div></div><div class="help-grid"><?php foreach($policies as $policy): ?><a class="help-card card" href="<?= $url($policy['route'], [], $policy['path']) ?>"><span aria-hidden="true">§</span><h2 class="heading-md"><?= $esc($policy['name']) ?></h2><p><?= $esc($policy['summary']) ?></p><span class="fine-print">Read policy →</span></a><?php endforeach; ?></div></section>
