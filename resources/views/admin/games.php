<?php $rows = is_array($data['rows'] ?? null) ? $data['rows'] : []; ?>
<div class="admin-heading"><div><p class="eyebrow">Server-authoritative controls</p><h1 class="heading-admin">Games</h1><p class="muted">Availability and fictional Cozy Coin limits are read from the database for catalog display and round validation. Strategies, odds, and payouts remain code-reviewed definitions.</p></div></div>
<section class="card card-pad"><form class="toolbar-inner" method="get"><label class="search-control"><span class="sr-only">Search games</span><input type="search" name="q" value="<?= $esc((string)($data['query'] ?? '')) ?>" placeholder="Search name, slug, or category"></label><button class="button button-ghost">Filter</button></form></section>
<section class="stack">
<?php foreach ($rows as $row): ?>
  <form class="card settings-section stack" method="post" action="<?= $url('admin.games.update', ['id'=>$row['id']], '/admin/games/'.$row['id']) ?>">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
    <div class="split"><div><h2 class="heading-card"><?= $esc($row['name']) ?></h2><p class="fine-print"><?= $esc($row['slug']) ?> · <?= $esc($row['category']) ?></p></div><span class="status status-<?= $row['active']?'success':'warning' ?>"><?= $esc($row['status']) ?></span></div>
    <div class="form-grid">
      <label class="field"><span>Availability</span><select name="availability"><option value="active" <?= $row['active']?'selected':'' ?>>Active</option><option value="disabled" <?= !$row['active']?'selected':'' ?>>Disabled</option></select></label>
      <label class="field"><span>Minimum fictional wager</span><input type="number" name="minimum" min="0" max="1000000" value="<?= $esc((string)$row['minimum']) ?>" required></label>
      <label class="field"><span>Maximum fictional wager</span><input type="number" name="maximum" min="0" max="1000000" value="<?= $esc((string)$row['maximum']) ?>" required></label>
      <label class="field"><span>Increment</span><input type="number" name="increment" min="0" max="1000000" value="<?= $esc((string)$row['increment']) ?>" required></label>
    </div>
    <?php $actionLabel='Publish game controls'; $reasonMinimum=8; require __DIR__.'/_action-footer.php'; ?>
  </form>
<?php endforeach; ?>
</section>
