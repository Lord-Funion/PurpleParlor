<?php
$mode = (string)($data['payment_mode'] ?? 'demo');
$providers = isset($data['providers']) && is_array($data['providers']) ? $data['providers'] : [];
?>
<div class="admin-heading">
    <div><p class="eyebrow">Adult Owner controlled</p><h1 class="heading-admin">Commerce status</h1><p class="muted">Developer Administrators see readiness and aggregate figures, never full live secrets or payout details.</p></div>
    <span class="tag tag-gold">Mode: <?= $esc(mb_strtoupper($mode)) ?></span>
</div>
<div class="callout <?= $mode === 'live' ? 'callout-danger' : 'callout-gold' ?>">
    <strong>Live payment activation lock: <?= !empty($data['activation_lock']) ? 'ON' : 'OFF' ?></strong>
    <p><?= !empty($data['activation_lock']) ? 'Production payment activation requires Adult Owner reauthentication and creates a permanent audit entry.' : 'The production lock is released. Review the checklist and audit log immediately.' ?></p>
</div>
<section class="two-column">
    <?php foreach ($providers as $provider): ?>
        <article class="card card-pad stack">
            <div class="split"><h2 class="heading-card"><?= $esc($provider['name']) ?></h2><span class="status status-<?= $provider['status'] === 'ready' ? 'success' : 'warning' ?>"><?= $esc(str_replace('_', ' ', ucfirst($provider['status']))) ?></span></div>
            <dl class="stack">
                <div class="split"><dt>Public identifier</dt><dd><?= $esc($provider['public_id']) ?></dd></div>
                <div class="split"><dt>Webhook</dt><dd><?= $esc($provider['webhook']) ?></dd></div>
                <div class="split"><dt>Secret</dt><dd>Stored privately · never displayed</dd></div>
            </dl>
            <?php if (!empty($user['is_adult_owner'])): ?>
                <form method="post" action="<?= $url('admin.payments.test', ['provider' => mb_strtolower($provider['name'])], '/admin/commerce/test') ?>">
                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                    <button class="button button-ghost">Test <?= $esc($provider['name']) ?> connection</button>
                </form>
                <p class="fine-print">Read-only provider authentication/status request. It cannot create a checkout, payment, capture, or refund.</p>
            <?php else: ?>
                <p class="fine-print">Connection tests are available to a recently reauthenticated Adult Owner. No secret is exposed to this page.</p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php if (!empty($data['can_manage_catalog'])): ?>
<section class="card card-pad stack">
    <div><h2 class="heading-card">Local plan & product catalog</h2><p class="muted">These values drive the public billing catalog. Provider identifiers, credentials, captures, and refunds are not changed by these forms.</p></div>
    <?php foreach (($data['plans'] ?? []) as $plan): $benefits=json_decode((string)$plan['benefits_json'],true); ?>
    <form class="form-grid callout" method="post" action="<?= $url('admin.catalog.update',['type'=>'plan','id'=>$plan['id']],'/admin/catalog/plan/'.$plan['id']) ?>">
        <?= function_exists('csrf_field')?csrf_field():'' ?><div><strong>Plan: <?= $esc($plan['plan_key']) ?></strong><p class="fine-print">Updated <?= $esc($plan['updated_at']) ?></p></div>
        <label class="field"><span>Name</span><input name="name" maxlength="100" value="<?= $esc($plan['name']) ?>" required></label>
        <label class="field"><span>Description</span><input name="description" maxlength="2000" value="<?= $esc($plan['description']) ?>" required></label>
        <label class="field"><span>Benefits (comma separated keys)</span><input name="benefits" value="<?= $esc(is_array($benefits)?implode(', ',$benefits):'') ?>"></label>
        <label class="field"><span>Sort order</span><input type="number" name="sort_order" min="0" max="10000" value="<?= $esc((string)$plan['sort_order']) ?>" required></label>
        <label class="field"><span>Status</span><select name="active"><option value="active" <?= $plan['active']?'selected':'' ?>>Active</option><option value="disabled" <?= !$plan['active']?'selected':'' ?>>Disabled</option></select></label>
        <?php $actionLabel='Save plan';$reasonMinimum=10;$sensitiveAction=true;require __DIR__.'/_action-footer.php'; ?>
    </form>
    <?php endforeach; ?>
    <?php foreach (($data['products'] ?? []) as $product): $contents=json_decode((string)$product['fixed_contents_json'],true); ?>
    <form class="form-grid callout" method="post" action="<?= $url('admin.catalog.update',['type'=>'product','id'=>$product['id']],'/admin/catalog/product/'.$product['id']) ?>">
        <?= function_exists('csrf_field')?csrf_field():'' ?><div><strong>Product: <?= $esc($product['product_key']) ?></strong><p class="fine-print">Updated <?= $esc($product['updated_at']) ?></p></div>
        <label class="field"><span>Name</span><input name="name" maxlength="150" value="<?= $esc($product['name']) ?>" required></label>
        <label class="field"><span>Description</span><input name="description" maxlength="2000" value="<?= $esc($product['description']) ?>" required></label>
        <label class="field"><span>Fixed entitlements</span><input name="entitlements" value="<?= $esc(is_array($contents)?implode(', ',$contents):(string)$product['entitlement_key']) ?>" required></label>
        <label class="field"><span>Refund policy reference</span><input name="refund_policy_reference" maxlength="100" value="<?= $esc($product['refund_policy_reference']) ?>" required></label>
        <label class="field"><span>Status</span><select name="active"><option value="active" <?= $product['active']?'selected':'' ?>>Active</option><option value="disabled" <?= !$product['active']?'selected':'' ?>>Disabled</option></select></label>
        <?php $actionLabel='Save product';$reasonMinimum=10;$sensitiveAction=true;require __DIR__.'/_action-footer.php'; ?>
    </form>
    <?php endforeach; ?>
</section>
<section class="card card-pad stack">
    <div><h2 class="heading-card">Manual entitlement source</h2><p class="muted">Only the administrator source is managed here. Purchase and subscription sources are never revoked by this action.</p></div>
    <form class="form-grid" method="post" action="<?= $url('admin.entitlements.update',[],'/admin/entitlements') ?>">
        <?= function_exists('csrf_field')?csrf_field():'' ?>
        <label class="field"><span>User ID</span><input type="number" name="user_id" min="1" required></label>
        <label class="field"><span>Entitlement</span><select name="entitlement_key" required><?php foreach(($data['known_entitlements']??[]) as $key): ?><option value="<?= $esc($key) ?>"><?= $esc($key) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Action</span><select name="action"><option value="grant">Grant/update admin source</option><option value="revoke">Revoke admin source</option></select></label>
        <label class="field"><span>Optional end (UTC)</span><input type="datetime-local" name="ends_at"></label>
        <?php $actionLabel='Apply entitlement action';$reasonMinimum=10;$sensitiveAction=true;require __DIR__.'/_action-footer.php'; ?>
    </form>
    <div class="data-table-wrap"><table class="data-table"><thead><tr><th>User</th><th>Entitlement</th><th>Ends</th><th>State</th></tr></thead><tbody><?php foreach(($data['manual_entitlements']??[]) as $item): ?><tr><td><?= $esc($item['username']) ?> (#<?= $esc((string)$item['user_id']) ?>)</td><td><?= $esc($item['entitlement_key']) ?></td><td><?= $esc((string)($item['ends_at']??'No expiry')) ?></td><td><?= $item['revoked_at']===null?'Active':'Revoked' ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<?php endif; ?>
<?php if (!empty($data['can_track_refunds'])): ?>
<section class="card card-pad stack">
    <div class="callout callout-gold"><strong>Review first, submit separately.</strong><p>Tracking controls never contact a provider. Only a request in <em>provider action required</em> can be submitted below, with a second exact confirmation and a recently reauthenticated Adult Owner session.</p></div>
    <h2 class="heading-card">Create refund request</h2>
    <form class="form-grid" method="post" action="<?= $url('admin.refunds.track',['id'=>0],'/admin/refund-requests/0') ?>">
        <?= function_exists('csrf_field')?csrf_field():'' ?><input type="hidden" name="status" value="requested">
        <label class="field"><span>Local payment</span><select name="payment_id" required><?php foreach(($data['recent_payments']??[]) as $payment): ?><option value="<?= $esc((string)$payment['id']) ?>">#<?= $esc((string)$payment['id']) ?> · <?= $esc($payment['provider']) ?> · <?= $esc($payment['status']) ?> · $<?= $esc(number_format(((int)$payment['amount_cents'])/100,2)) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Requested cents</span><input type="number" name="amount_cents" min="1" required></label>
        <label class="field"><span>Internal note (optional)</span><input name="note" maxlength="500"></label>
        <?php $actionLabel='Track refund request';$reasonMinimum=10;$sensitiveAction=true;require __DIR__.'/_action-footer.php'; ?>
    </form>
    <?php foreach(($data['refund_requests']??[]) as $refund): ?>
    <form class="form-grid callout" method="post" action="<?= $url('admin.refunds.track',['id'=>$refund['id']],'/admin/refund-requests/'.$refund['id']) ?>">
        <?= function_exists('csrf_field')?csrf_field():'' ?><input type="hidden" name="payment_id" value="<?= $esc((string)$refund['payment_id']) ?>"><input type="hidden" name="amount_cents" value="<?= $esc((string)$refund['requested_amount_cents']) ?>">
        <div><strong>Request #<?= $esc((string)$refund['id']) ?></strong><p class="fine-print">Payment #<?= $esc((string)$refund['payment_id']) ?> · <?= $esc($refund['provider']) ?> · $<?= $esc(number_format(((int)$refund['requested_amount_cents'])/100,2)) ?></p></div>
        <label class="field"><span>Workflow status</span><select name="status"><?php foreach(['under_review','provider_action_required','declined','closed'] as $status): ?><option value="<?= $esc($status) ?>" <?= $status===$refund['status']?'selected':'' ?>><?= $esc(str_replace('_',' ',ucfirst($status))) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Resolution note</span><input name="note" maxlength="500" value="<?= $esc((string)($refund['resolution_note']??'')) ?>"></label>
        <p class="fine-print">Provider execution: <?= $esc(str_replace('_',' ',(string)($refund['execution_status']??'not started'))) ?><?php if(isset($refund['attempt_count'])): ?> · <?= $esc((string)$refund['attempt_count']) ?> attempt(s)<?php endif; ?></p>
        <?php $actionLabel='Update refund tracking';$reasonMinimum=10;$sensitiveAction=true;require __DIR__.'/_action-footer.php'; ?>
    </form>
    <?php if ($refund['status']==='provider_action_required' && ($refund['execution_status']??'')!=='confirmed'): ?>
    <form class="form-grid callout callout-danger" method="post" action="<?= $url('admin.refunds.execute',['id'=>$refund['id']],'/admin/refund-requests/'.$refund['id'].'/submit') ?>">
        <?= function_exists('csrf_field')?csrf_field():'' ?>
        <div><strong>Submit request #<?= $esc((string)$refund['id']) ?> to <?= $esc(ucfirst((string)$refund['provider'])) ?></strong><p class="fine-print">This can move real money. The deterministic provider key makes retries use the same operation.</p></div>
        <label class="field"><span>Type exactly: REFUND REQUEST #<?= $esc((string)$refund['id']) ?></span><input name="confirmation" autocomplete="off" maxlength="80" required></label>
        <?php $actionLabel='Submit provider refund';$reasonMinimum=10;$sensitiveAction=true;require __DIR__.'/_action-footer.php'; ?>
    </form>
    <?php endif; ?>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php $paymentAudit=is_array($data['payment_audit']??null)?$data['payment_audit']:[];$auditSummary=is_array($paymentAudit['summary']??null)?$paymentAudit['summary']:[]; ?>
<section class="card card-pad stack" id="audit-history">
    <div><p class="eyebrow">Redacted operational history</p><h2 class="heading-card">Payment audit & webhooks</h2><p class="muted">Webhook bodies, credentials, payment instruments, and audit-chain hashes are never selected for this page.</p></div>
    <div class="health-grid">
        <div class="health-item callout"><span>Payment audit events</span><strong><?= $esc(number_format((int)($auditSummary['audit_events']??0))) ?></strong></div>
        <div class="health-item callout"><span>Verified webhooks</span><strong><?= $esc(number_format((int)($auditSummary['verified_webhooks']??0))) ?></strong></div>
        <div class="health-item callout"><span>Failed webhooks</span><strong><?= $esc(number_format((int)($auditSummary['failed_webhooks']??0))) ?></strong></div>
        <div class="health-item callout"><span>Latest webhook</span><strong><?= $esc((string)($auditSummary['latest_webhook_status']??'None')) ?></strong></div>
    </div>
    <?php if (!empty($paymentAudit['detailed'])): ?>
    <h3>Payment audit events</h3>
    <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Time</th><th>Action</th><th>Target</th><th>Request</th><th>Redacted details</th></tr></thead><tbody>
    <?php foreach(($paymentAudit['payment_events']??[]) as $event): ?><tr><td><?= $esc($event['created_at']) ?></td><td><?= $esc($event['action']) ?></td><td><?= $esc($event['target']) ?></td><td><?= $esc($event['request_id']) ?></td><td><code><?= $esc(json_encode($event['details'],JSON_UNESCAPED_SLASHES)?:'{}') ?></code></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <h3>Webhook events</h3>
    <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Received</th><th>Provider / event</th><th>Type</th><th>Signature</th><th>Status</th><th>Fingerprint</th><th>Sanitized error</th></tr></thead><tbody>
    <?php foreach(($paymentAudit['webhook_events']??[]) as $event): ?><tr><td><?= $esc($event['received_at']) ?><br><span class="fine-print">Processed <?= $esc((string)($event['processed_at']??'not yet')) ?></span></td><td><?= $esc($event['provider']) ?><br><span class="fine-print"><?= $esc($event['provider_event_id']) ?></span></td><td><?= $esc($event['event_type']) ?></td><td><?= !empty($event['signature_valid'])?'Verified':'Invalid' ?></td><td><?= $esc($event['status']) ?></td><td><code><?= $esc($event['payload_fingerprint']) ?></code></td><td><?= $esc((string)($event['error']??'None')) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?>
        <p class="fine-print">Developer and support administrators receive aggregate, non-sensitive status only. Detailed payment and webhook history requires the Adult Owner payment-audit permission.</p>
    <?php endif; ?>
</section>
<section class="card card-pad">
    <h2 class="heading-card">Production activation checklist</h2>
    <ul class="check-list">
        <?php foreach (($data['activation_checks'] ?? []) as $label => $passed): ?><li><span><?= $esc($label) ?></span><span class="status status-<?= $passed ? 'success' : 'warning' ?>"><?= $passed ? 'Complete' : 'Required' ?></span></li><?php endforeach; ?>
    </ul>
    <?php if (!empty($user['is_adult_owner'])): ?>
        <a class="button button-danger" href="<?= $url('admin.commerce.activation', [], '/admin/commerce/activation') ?>">Review live activation</a>
    <?php else: ?>
        <div class="notice notice-warning">Only an Adult Owner can enter legal merchant details, change payout settings, store live credentials, or activate production payments.</div>
    <?php endif; ?>
</section>
