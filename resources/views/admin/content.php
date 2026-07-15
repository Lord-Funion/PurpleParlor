<?php
$requests = is_array($data['requests'] ?? null) ? $data['requests'] : [];
$workflows = is_array($data['workflows'] ?? null) ? $data['workflows'] : [];
$managedContent = is_array($data['managed_content'] ?? null) ? $data['managed_content'] : [];
?>
<div class="admin-heading"><div><p class="eyebrow">Review workflows</p><h1 class="heading-admin">Content, requests & monetization</h1><p class="muted">Legal documents and email bodies use immutable, database-backed plain-text revisions. Drafts are private; only one explicitly published revision can reach public pages or new email.</p></div></div>

<section class="card card-pad"><h2 class="heading-card">Queue summary</h2><div class="metric-grid"><?php foreach (($data['rows'] ?? []) as $row): ?><div class="metric-card"><span><?= $esc($row['resource_name']) ?></span><strong><?= $esc($row['status']) ?></strong><small><?= $esc($row['updated']) ?></small></div><?php endforeach; ?></div></section>

<section class="card card-pad stack"><h2 class="heading-card">Message, licensing & support workflow</h2>
<?php foreach ($requests as $row): $statuses = match ($row['type']) {'contact_message' => ['new','in_review','replied','closed','spam'], 'licensing_inquiry' => ['new','qualified','contacted','negotiation','closed','rejected','spam'], default => ['open','in_progress','waiting_on_customer','resolved','closed']}; ?>
  <form class="form-grid callout" method="post" action="<?= $url('admin.requests.status', ['type' => $row['type'], 'id' => $row['id']], '/admin/requests/'.$row['type'].'/'.$row['id']) ?>">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?><div><strong><?= $esc($row['label']) ?> #<?= $esc((string) $row['id']) ?></strong><p class="fine-print"><?= $esc($row['summary']) ?> · <?= $esc($row['received_at']) ?></p></div>
    <label class="field"><span>Status</span><select name="status"><?php foreach ($statuses as $status): ?><option value="<?= $esc($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= $esc(str_replace('_', ' ', ucfirst($status))) ?></option><?php endforeach; ?></select></label>
    <?php $actionLabel = 'Update request'; $reasonMinimum = 5; require __DIR__.'/_action-footer.php'; ?>
  </form>
<?php endforeach; ?><?php if (!$requests): ?><p>No request records yet.</p><?php endif; ?></section>

<?php if (!empty($data['can_ads'])): ?><section class="card card-pad stack"><h2 class="heading-card">Advertising & sponsors</h2><p class="muted">Activation requires Adult Owner reauthentication. Provider configuration is not editable here.</p>
<?php foreach (($data['advertising_slots'] ?? []) as $row): ?><form class="form-grid callout" method="post" action="<?= $url('admin.monetization.update', ['type' => 'advertising_slot', 'id' => $row['id']], '/admin/monetization/advertising_slot/'.$row['id']) ?>"><?= function_exists('csrf_field') ? csrf_field() : '' ?><div><strong><?= $esc($row['slot_key']) ?></strong><p class="fine-print">Provider: <?= $esc($row['provider']) ?></p></div><label class="field"><span>Status</span><select name="status"><option value="disabled" <?= !$row['enabled'] ? 'selected' : '' ?>>Disabled</option><option value="enabled" <?= $row['enabled'] ? 'selected' : '' ?>>Enabled</option></select></label><?php $actionLabel = 'Update ad slot'; $reasonMinimum = 10; $sensitiveAction = true; require __DIR__.'/_action-footer.php'; ?></form><?php endforeach; ?>
<?php foreach (($data['sponsors'] ?? []) as $row): ?><form class="form-grid callout" method="post" action="<?= $url('admin.monetization.update', ['type' => 'sponsor', 'id' => $row['id']], '/admin/monetization/sponsor/'.$row['id']) ?>"><?= function_exists('csrf_field') ? csrf_field() : '' ?><div><strong><?= $esc($row['name']) ?></strong><p class="fine-print"><?= $esc((string) ($row['starts_at'] ?? 'No start')) ?> to <?= $esc((string) ($row['ends_at'] ?? 'No end')) ?></p></div><label class="field"><span>Status</span><select name="status"><?php foreach (['draft','pending_review','approved','active','paused','ended','rejected'] as $status): ?><option value="<?= $esc($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= $esc(str_replace('_', ' ', ucfirst($status))) ?></option><?php endforeach; ?></select></label><?php $actionLabel = 'Update sponsor'; $reasonMinimum = 10; $sensitiveAction = true; require __DIR__.'/_action-footer.php'; ?></form><?php endforeach; ?></section><?php endif; ?>

<section class="card card-pad stack">
  <div><p class="eyebrow">Versioned publication</p><h2 class="heading-card">Legal documents & email bodies</h2><p class="muted">Bodies are plain text. Supported placeholders are substituted from trusted runtime values and then escaped. PHP, JavaScript, and submitted HTML are never executed. Installed source templates remain the fallback until a valid revision is published.</p></div>
  <?php foreach ($managedContent as $item):
      $latest = is_array($item['latest'] ?? null) ? $item['latest'] : null;
      $published = is_array($item['published'] ?? null) ? $item['published'] : null;
      $legal = $item['type'] === 'legal';
  ?>
    <details class="callout" <?= $latest !== null ? '' : 'open' ?>>
      <summary><strong><?= $esc($item['label']) ?></strong> <span class="tag"><?= $esc($legal ? 'Legal' : 'Email') ?></span> <span class="fine-print">Published: <?= $esc($published['version_label'] ?? 'source fallback') ?> · Latest: <?= $esc($latest['status'] ?? 'none') ?></span></summary>
      <div class="stack">
        <p class="fine-print">Allowed placeholders: <?= $esc(implode(', ', array_map(static fn (string $token): string => '{{'.$token.'}}', $item['placeholders']))) ?></p>
        <form class="form-stack" method="post" action="<?= $url('admin.managed-content.draft', ['type' => $item['type'], 'key' => $item['key']], '/admin/managed-content/'.$item['type'].'/'.$item['key'].'/draft') ?>">
          <?= function_exists('csrf_field') ? csrf_field() : '' ?>
          <div class="form-grid"><label class="field"><span>New version</span><input name="version" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,31}" placeholder="v2" required></label><label class="field"><span>Title</span><input name="title" maxlength="200" value="<?= $esc((string) ($latest['title_text'] ?? $item['label'])) ?>" required></label></div>
          <label class="field"><span>Plain-text body</span><textarea name="body" rows="12" maxlength="<?= $legal ? '50000' : '20000' ?>" required><?= $esc((string) ($latest['body_text'] ?? '')) ?></textarea><small>Creating a draft never changes public pages or queued email.</small></label>
          <?php $actionLabel = 'Create immutable draft'; $reasonMinimum = 8; require __DIR__.'/_action-footer.php'; ?>
        </form>

        <?php if (!empty($item['revisions'])): ?><div class="stack"><h3>Recent revisions</h3>
          <?php foreach ($item['revisions'] as $revision): ?>
            <article class="callout">
              <div class="split"><div><strong><?= $esc($revision['version_label']) ?></strong> <span class="tag"><?= $esc(str_replace('_', ' ', $revision['status'])) ?></span><p class="fine-print">Revision #<?= $esc((string) $revision['id']) ?> · Created <?= $esc($revision['created_at']) ?><?= $revision['based_on_revision_id'] ? ' · Based on #'.$esc((string) $revision['based_on_revision_id']) : '' ?></p></div><a class="button button-small button-ghost" href="<?= $url('admin.managed-content.preview', ['type' => $item['type'], 'key' => $item['key'], 'id' => $revision['id']], '/admin/managed-content/'.$item['type'].'/'.$item['key'].'/'.$revision['id'].'/preview') ?>">Private preview</a></div>
              <?php if ($legal && $revision['status'] === 'draft' && !empty($data['can_publish_legal'])): ?><form class="form-grid" method="post" action="<?= $url('admin.managed-content.approve', ['type' => $item['type'], 'key' => $item['key'], 'id' => $revision['id']], '/admin/managed-content/legal/'.$item['key'].'/'.$revision['id'].'/approve') ?>"><?= function_exists('csrf_field') ? csrf_field() : '' ?><?php $actionLabel = 'Adult Owner approve'; $reasonMinimum = 8; $sensitiveAction = true; require __DIR__.'/_action-footer.php'; ?></form><?php endif; ?>
              <?php if (($legal && $revision['status'] === 'owner_approved' && !empty($data['can_publish_legal'])) || (!$legal && $revision['status'] === 'draft')): ?><form class="form-grid" method="post" action="<?= $url('admin.managed-content.publish', ['type' => $item['type'], 'key' => $item['key'], 'id' => $revision['id']], '/admin/managed-content/'.$item['type'].'/'.$item['key'].'/'.$revision['id'].'/publish') ?>"><?= function_exists('csrf_field') ? csrf_field() : '' ?><?php $actionLabel = 'Publish this revision'; $reasonMinimum = 8; $sensitiveAction = $legal; require __DIR__.'/_action-footer.php'; ?></form><?php endif; ?>
              <?php if ($revision['status'] === 'retired' && (!$legal || !empty($data['can_publish_legal']))): ?><form class="form-grid" method="post" action="<?= $url('admin.managed-content.rollback', ['type' => $item['type'], 'key' => $item['key'], 'id' => $revision['id']], '/admin/managed-content/'.$item['type'].'/'.$item['key'].'/'.$revision['id'].'/rollback') ?>"><?= function_exists('csrf_field') ? csrf_field() : '' ?><?php $actionLabel = 'Publish as rollback'; $reasonMinimum = 8; $sensitiveAction = $legal; require __DIR__.'/_action-footer.php'; ?></form><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div><?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
</section>

<section class="card card-pad stack"><h2 class="heading-card">Public page review registry</h2><p class="muted">These status records track review of fixed public page source. They do not edit runtime PHP templates. Legal and email publication use the versioned controls above.</p>
<?php foreach ($workflows as $row): $statuses = ['draft','review','approved','published','retired']; ?>
  <form class="form-grid callout" method="post" action="<?= $url('admin.workflows.update', ['type' => $row['type'], 'key' => $row['key']], '/admin/workflows/'.$row['type'].'/'.$row['key']) ?>">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?><div><strong><?= $esc(ucwords(str_replace(['-','_'], ' ', $row['key']))) ?></strong><p class="fine-print">Public source page · Last updated <?= $esc((string) ($row['updated_at'] ?? 'never')) ?></p></div>
    <label class="field"><span>Status</span><select name="status"><?php foreach ($statuses as $status): ?><option value="<?= $esc($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= $esc(str_replace('_', ' ', ucfirst($status))) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Version</span><input name="version" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,31}" value="<?= $esc($row['version']) ?>" required></label>
    <?php $actionLabel = 'Record page review status'; $reasonMinimum = 8; require __DIR__.'/_action-footer.php'; ?>
  </form>
<?php endforeach; ?></section>
