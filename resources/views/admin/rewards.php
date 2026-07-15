<?php $rows = is_array($data['rows'] ?? null) ? $data['rows'] : []; ?>
<div class="admin-heading"><div><p class="eyebrow">Fictional rewards</p><h1 class="heading-admin">Rewards</h1><p class="muted">These definitions drive the public mission, achievement, and daily-reward flows. Previously committed ledger entries are append-only and never rewritten.</p></div></div>
<section class="card settings-section">
  <form class="form-grid" method="post" action="<?= $url('admin.rewards.daily', [], '/admin/rewards/daily') ?>">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
    <label class="field"><span>Daily Cozy Coin amount</span><input type="number" name="amount" min="1" max="100000" value="<?= $esc((string)($data['daily_amount'] ?? 1000)) ?>" required><small>Applies only to future daily claims.</small></label>
    <?php $actionLabel='Update daily reward'; $reasonMinimum=8; require __DIR__.'/_action-footer.php'; ?>
  </form>
</section>
<section class="stack">
<?php foreach ($rows as $row): $mission = $row['type']==='Mission'; ?>
  <form class="card settings-section stack" method="post" action="<?= $mission ? $url('admin.missions.update',['id'=>$row['id']],'/admin/rewards/missions/'.$row['id']) : $url('admin.achievements.update',['id'=>$row['id']],'/admin/rewards/achievements/'.$row['id']) ?>">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
    <div class="split"><div><p class="eyebrow"><?= $esc($row['type']) ?></p><h2 class="heading-card"><?= $esc($row['name']) ?></h2><p class="fine-print"><?= $esc((string)($row[$mission?'mission_key':'achievement_key'] ?? '')) ?></p></div><span class="status status-<?= !empty($row['active'])?'success':'warning' ?>"><?= $esc($row['status']) ?></span></div>
    <div class="form-grid">
      <label class="field"><span>Name</span><input name="name" maxlength="100" value="<?= $esc($row['name']) ?>" required></label>
      <label class="field"><span>Status</span><select name="active"><option value="active" <?= !empty($row['active'])?'selected':'' ?>>Active</option><option value="disabled" <?= empty($row['active'])?'selected':'' ?>>Disabled</option></select></label>
      <label class="field"><span>Description</span><input name="description" maxlength="500" value="<?= $esc($row['description']) ?>" required></label>
      <?php if ($mission): ?><label class="field"><span>Target</span><input type="number" name="target_value" min="1" max="1000000" value="<?= $esc((string)$row['target_value']) ?>" required></label><?php endif; ?>
      <label class="field"><span>Cozy Coins</span><input type="number" name="reward_coins" min="0" max="100000" value="<?= $esc((string)$row['reward_coins']) ?>" required></label>
      <label class="field"><span>Parlor Stars</span><input type="number" name="reward_stars" min="0" max="10000" value="<?= $esc((string)$row['reward_stars']) ?>" required></label>
      <?php if ($mission): ?><label class="field"><span>Starts (UTC)</span><input type="datetime-local" name="starts_at" value="<?= $esc(str_replace(' ','T',substr((string)$row['starts_at'],0,16))) ?>" required></label><label class="field"><span>Ends (UTC)</span><input type="datetime-local" name="ends_at" value="<?= $esc(str_replace(' ','T',substr((string)$row['ends_at'],0,16))) ?>" required></label><?php endif; ?>
    </div>
    <?php $actionLabel='Save reward definition'; $reasonMinimum=8; require __DIR__.'/_action-footer.php'; ?>
  </form>
<?php endforeach; ?>
</section>
