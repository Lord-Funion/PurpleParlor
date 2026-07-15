<?php
$emailEsc=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$emailTitle='We received your Purple Parlor message'; $emailPreheader='Your support request is safely in the queue.';
ob_start(); ?>
<p style="margin:0 0 8px;color:#e8b85d;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase">Request received</p><h1 style="margin:0 0 16px;font-family:Georgia,serif;font-size:32px">We have your message</h1><p style="margin:0 0 18px;color:#ddd0e6;line-height:1.6">Your request was recorded under reference <strong><?= $emailEsc($ticketReference??'') ?></strong>. A support reply will use the configured support mailbox.</p><p style="margin:0;color:#ac9ab8;font-size:13px">Support will never ask for your password, full card number, bank password, government ID, tax document, or payment-provider secret. If your original message included sensitive credentials, rotate them with the provider and notify the Adult Owner.</p>
<?php $emailContent=(string)ob_get_clean(); require __DIR__.'/layout.php';
