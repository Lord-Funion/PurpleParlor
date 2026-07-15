<?php
$actionLabel = isset($actionLabel) ? (string) $actionLabel : 'Save audited change';
$reasonMinimum = isset($reasonMinimum) ? max(5, (int) $reasonMinimum) : 8;
$sensitiveAction = !empty($sensitiveAction);
?>
<label class="field"><span>Reason for audit log</span><input name="reason" minlength="<?= $esc((string) $reasonMinimum) ?>" maxlength="500" required><small>Required. Do not enter passwords, tokens, card data, or other secrets.</small></label>
<?php if ($sensitiveAction): ?><p class="fine-print">This is an Adult Owner action and requires a recent privileged session.</p><?php endif; ?>
<button class="button button-small <?= $sensitiveAction ? 'button-danger' : 'button-gold' ?>"><?= $esc($actionLabel) ?></button>
<?php unset($actionLabel, $reasonMinimum, $sensitiveAction); ?>
