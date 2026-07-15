<?php
$flash = $data['flash'] ?? null;
if (is_array($flash) && !empty($flash['message'])):
    $flashType = in_array(($flash['type'] ?? ''), ['success', 'warning', 'error', 'info'], true) ? $flash['type'] : 'info';
?>
<div class="toast-region shell" aria-live="polite" aria-atomic="true">
    <div class="notice notice-<?= $esc($flashType) ?>" role="status">
        <span><?= $esc($flash['message']) ?></span>
        <button type="button" class="notice-close" data-dismiss aria-label="Dismiss message">×</button>
    </div>
</div>
<?php endif; ?>
