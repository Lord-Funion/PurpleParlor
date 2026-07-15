<?php
$document = isset($data['document']) && is_array($data['document'])
    ? $data['document']
    : ['title' => 'Policy', 'summary' => 'Policy information for this website.', 'sections' => []];
$managed = isset($data['managed_document']) && is_array($data['managed_document']) ? $data['managed_document'] : null;
if ($managed !== null) {
    $document = [
        'title' => (string) ($managed['title_text'] ?? 'Policy'),
        'version' => (string) ($managed['version_label'] ?? 'published'),
        'effective_date' => (string) ($app['legal_effective_date'] ?? '{{EFFECTIVE_DATE}}'),
        'summary' => 'A published policy of The Purple Parlor.',
        'body_text' => (string) ($managed['body_text'] ?? ''),
        'sections' => [],
    ];
}
$tokens = [
    '{{SITE_NAME}}' => (string) ($app['name'] ?? 'The Purple Parlor'),
    '{{OWNER_NAME}}' => (string) ($app['legal_owner_name'] ?? '[Adult Owner legal name]'),
    '{{CREATOR_NAME}}' => (string) ($app['creator_name'] ?? 'Lord Funion'),
    '{{SUPPORT_EMAIL}}' => (string) ($app['support_email'] ?? 'support@lordfunion.dev'),
    '{{SITE_URL}}' => (string) ($app['url'] ?? 'https://purpleparlor.lordfunion.dev'),
    '{{MINIMUM_AGE}}' => (string) ($app['minimum_age'] ?? 18),
    '{{JURISDICTION}}' => (string) ($app['legal_jurisdiction'] ?? '[Applicable jurisdiction]'),
    '{{EFFECTIVE_DATE}}' => (string) ($app['legal_effective_date'] ?? '[Set before launch]'),
    '{{COZY_CLUB_DAILY_COINS}}' => number_format((int) ($app['cozy_club_daily_coins'] ?? 1000)),
    '{{COZY_CLUB_PLUS_DAILY_COINS}}' => number_format((int) ($app['cozy_club_plus_daily_coins'] ?? 2500)),
];
$replace = static fn (mixed $value): string => strtr((string) $value, $tokens);
?>
<header class="page-hero"><div class="shell page-hero-inner"><nav class="breadcrumbs" aria-label="Breadcrumb"><a href="<?= $url('home', [], '/') ?>">Parlor</a><span aria-hidden="true">/</span><span>Legal</span><span aria-hidden="true">/</span><span><?= $esc($replace($document['title'])) ?></span></nav><p class="eyebrow">Plain-language policy</p><h1><?= $esc($replace($document['title'])) ?></h1><p class="lede"><?= $esc($replace($document['summary'] ?? '')) ?></p><p class="fine-print">Effective: <?= $esc($replace($document['effective_date'] ?? ($app['legal_effective_date'] ?? '[Set before launch]'))) ?> · Version <?= $esc((string) ($document['version'] ?? 'draft')) ?></p></div></header>
<section class="section shell article-layout">
    <nav class="article-nav" aria-label="Policy sections"><?php foreach (($document['sections'] ?? []) as $index => $section): ?><a href="#section-<?= $esc((string) $index) ?>"><?= $esc($replace($section['heading'] ?? 'Section')) ?></a><?php endforeach; ?></nav>
    <article class="prose">
        <?php if ($managed === null): ?><div class="notice notice-warning legal-template-label"><div><strong>Template requiring review.</strong><p>This document is an editable project template, not legal advice. The Adult Owner must confirm the legal identity, jurisdiction, dates, provider terms, prices, contact details, and applicable law—using qualified professional review when appropriate—before publishing.</p></div></div><?php endif; ?>
        <?php if (!empty($document['important_notice'])): ?><div class="callout callout-gold"><?= $esc($replace($document['important_notice'])) ?></div><?php endif; ?>
        <?php if ($managed !== null): ?>
            <?php foreach ((preg_split('/\n{2,}/', trim((string) $document['body_text']), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $paragraph): ?><p><?= nl2br($esc($replace($paragraph)), false) ?></p><?php endforeach; ?>
        <?php else: ?>
            <?php foreach (($document['sections'] ?? []) as $index => $section): ?><section id="section-<?= $esc((string) $index) ?>"><h2><?= $esc($replace($section['heading'] ?? 'Section')) ?></h2><?php foreach (($section['paragraphs'] ?? []) as $paragraph): ?><p><?= $esc($replace($paragraph)) ?></p><?php endforeach; ?><?php if (!empty($section['items'])): ?><ul><?php foreach ($section['items'] as $item): ?><li><?= $esc($replace($item)) ?></li><?php endforeach; ?></ul><?php endif; ?></section><?php endforeach; ?>
        <?php endif; ?>
        <hr><p>Questions about this document may be sent to <a href="mailto:<?= $esc($tokens['{{SUPPORT_EMAIL}}']) ?>"><?= $esc($tokens['{{SUPPORT_EMAIL}}']) ?></a>.</p>
    </article>
</section>
