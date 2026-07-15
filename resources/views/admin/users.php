<?php $rows = is_array($data['rows'] ?? null) ? $data['rows'] : []; ?>
<div class="admin-heading"><div><p class="eyebrow">Accounts and authorization</p><h1 class="heading-admin">Users & access</h1><p class="muted">Suspend or reactivate accounts without exposing email or authentication material. Administrator targets and every role or permission change require Adult Owner authority.</p></div></div>
<section class="card card-pad"><form class="toolbar-inner" method="get" role="search"><label class="search-control"><span class="sr-only">Search users</span><input type="search" name="q" value="<?= $esc((string)($data['query'] ?? '')) ?>" placeholder="Search username or status"></label><button class="button button-ghost">Filter</button></form></section>
<section class="stack">
<?php foreach ($rows as $row): ?>
  <article class="card card-pad stack">
    <div class="split"><div><h2 class="heading-card"><?= $esc($row['username']) ?></h2><p class="fine-print">User #<?= $esc((string)$row['id']) ?> · Created <?= $esc($row['created']) ?> · Last login <?= $esc($row['last_login']) ?></p></div><span class="status status-<?= $row['status_value'] === 'active' ? 'success' : 'warning' ?>"><?= $esc($row['status']) ?></span></div>
    <p><strong>Roles:</strong> <?= $esc($row['roles'] !== '' ? $row['roles'] : 'None') ?></p>
    <form class="form-grid" method="post" action="<?= $url('admin.users.status', ['id'=>$row['id']], '/admin/users/'.$row['id'].'/status') ?>">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <label class="field"><span>Account status</span><select name="status" required><option value="active" <?= $row['status_value']==='active'?'selected':'' ?>>Active</option><option value="suspended" <?= $row['status_value']==='suspended'?'selected':'' ?>>Suspended</option></select></label>
      <?php $actionLabel='Update account status'; $reasonMinimum=8; require __DIR__.'/_action-footer.php'; ?>
    </form>
    <?php if (!empty($data['can_manage_roles'])): ?>
      <form class="form-grid" method="post" action="<?= $url('admin.users.roles', ['id'=>$row['id']], '/admin/users/'.$row['id'].'/roles') ?>">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <label class="field"><span>Role</span><select name="role" required><?php foreach (($data['role_options'] ?? []) as $role): ?><option value="<?= $esc($role) ?>"><?= $esc(str_replace('_',' ',ucwords($role,'_'))) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Action</span><select name="action" required><option value="grant">Grant</option><option value="revoke">Revoke</option></select></label>
        <?php $actionLabel='Apply role change'; $reasonMinimum=10; $sensitiveAction=true; require __DIR__.'/_action-footer.php'; ?>
      </form>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card card-pad"><p>No matching accounts.</p></div><?php endif; ?>
</section>
<?php if (!empty($data['can_manage_roles'])): ?>
<section class="card settings-section stack">
  <div><h2 class="heading-card">Role permission assignment</h2><p class="muted">Adult Owner permissions cannot be weakened or delegated. This editor changes a role’s permission link only.</p></div>
  <?php foreach (($data['role_options'] ?? []) as $role): if(!in_array($role,['developer_admin','support_admin','content_manager','moderator'],true)) continue; ?>
  <form class="form-grid callout" method="post" action="<?= $url('admin.roles.permissions', ['role'=>$role], '/admin/roles/'.rawurlencode($role).'/permissions') ?>">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
    <div><strong><?= $esc(str_replace('_',' ',ucwords($role,'_'))) ?></strong><p class="fine-print">Role permission editor</p></div>
    <label class="field"><span>Permission</span><select name="permission" required><?php foreach (($data['permission_options'] ?? []) as $permission): ?><option value="<?= $esc($permission) ?>"><?= $esc($permission) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Action</span><select name="action" required><option value="grant">Grant</option><option value="revoke">Revoke</option></select></label>
    <?php $actionLabel='Apply permission change'; $reasonMinimum=10; $sensitiveAction=true; require __DIR__.'/_action-footer.php'; ?>
  </form>
  <?php endforeach; ?>
</section>
<?php endif; ?>
