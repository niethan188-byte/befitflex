<?php
define('IN_SUBDIR', false);
require __DIR__ . '/includes/auth.php';

$u = require_role('admin', 'member');
$paymentId = trim((string) ($_GET['payment_id'] ?? ''));
$payment = row(
    'SELECT p.*, m.member_name, m.email, m.member_id, m.membership_type
       FROM payments p JOIN members m ON m.member_id = p.member_id
      WHERE p.payment_id = ?',
    [$paymentId]
);

if (!$payment || ($u['type'] === 'member' && $payment['member_id'] !== ($_SESSION['member_id'] ?? ''))) {
    http_response_code(404);
    exit('Invoice not found.');
}

require __DIR__ . '/vendor/autoload.php';

$dompdf = new Dompdf\Dompdf();
$dompdf->loadHtml('<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;color:#202020;margin:42px}h1{color:#c91518;margin-bottom:4px}
.muted{color:#666}.line{border-top:1px solid #ddd;margin:24px 0}.row{display:flex;justify-content:space-between;margin:10px 0}
.total{font-size:20px;font-weight:bold;border-top:2px solid #202020;padding-top:14px}
</style></head><body>
<h1>Be Fit Flex Gym</h1><div class="muted">Payment receipt and invoice</div><div class="line"></div>
<div class="row"><b>Invoice reference</b><span>' . e($payment['payment_id']) . '</span></div>
<div class="row"><b>Member</b><span>' . e($payment['member_name']) . '</span></div>
<div class="row"><b>Member ID</b><span>' . e($payment['member_id']) . '</span></div>
<div class="row"><b>Membership</b><span>' . e($payment['membership_type']) . '</span></div>
<div class="row"><b>Payment method</b><span>' . e($payment['payment_method']) . '</span></div>
<div class="row"><b>Status</b><span>' . e($payment['payment_status']) . '</span></div>
<div class="row"><b>Payment date</b><span>' . e($payment['payment_date'] ?: 'Pending') . '</span></div>
<div class="line"></div><div class="row total"><span>Total</span><span>&#8369;' . number_format((float) $payment['amount'], 2) . '</span></div>
<p class="muted">Thank you for choosing Be Fit Flex Gym.</p></body></html>');
$dompdf->setPaper('A4');
$dompdf->render();
$dompdf->stream('invoice-' . $payment['payment_id'] . '.pdf', ['Attachment' => true]);