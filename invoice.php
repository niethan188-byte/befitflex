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

$isPdf = ($_GET['format'] ?? '') === 'pdf';
$status = (string) $payment['payment_status'];
$statusClass = match ($status) {
    'Paid' => 'paid',
    'Pending' => 'pending',
    'Overdue' => 'overdue',
    default => 'neutral',
};
$description = trim((string) ($payment['notes'] ?? '')) ?: 'Membership dues';
$paymentsUrl = $u['type'] === 'admin' ? 'admin/payments.php' : 'member/payments.php';
$pdfUrl = '?payment_id=' . rawurlencode($paymentId) . '&format=pdf';

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Invoice <?= e($payment['payment_id']) ?> · Be Fit Flex Gym</title>
  <style>
    @page { size: A4; margin: 16mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0; padding: 32px 16px; background: #f3f4f6; color: #202632;
      font: 14px/1.5 Arial, Helvetica, sans-serif;
    }
    .invoice-actions {
      display: flex; justify-content: space-between; align-items: center; gap: 12px;
      width: 100%; max-width: 760px; margin: 0 auto 16px;
    }
    .invoice-actions a, .invoice-actions button {
      display: inline-flex; align-items: center; justify-content: center; min-height: 40px;
      padding: 9px 14px; border: 1px solid #d6dbe2; border-radius: 6px;
      background: #fff; color: #202632; font: inherit; font-size: 13px;
      text-decoration: none; cursor: pointer;
    }
    .invoice-actions .action-group { display: flex; flex-wrap: wrap; gap: 8px; }
    .invoice-actions .primary { border-color: #b4232a; background: #b4232a; color: #fff; }
    .invoice {
      width: 100%; max-width: 760px; margin: 0 auto; padding: 38px;
      border: 1px solid #e1e5eb; background: #fff;
    }
    .masthead { width: 100%; border-collapse: collapse; }
    .masthead td { padding: 0 0 20px; vertical-align: top; }
    .brand { color: #b4232a; font-size: 20px; font-weight: 700; letter-spacing: .04em; }
    .brand-sub { margin-top: 3px; color: #697386; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; }
    .document-title { color: #202632; font-size: 21px; font-weight: 700; text-align: right; }
    .document-type { margin-top: 3px; color: #697386; font-size: 11px; text-align: right; }
    .rule { height: 3px; margin: 0 0 24px; background: #b4232a; }
    .status {
      margin-bottom: 22px; padding: 11px 14px; border: 1px solid #d8dee7;
      background: #f7f8fa; color: #384152; font-size: 13px; font-weight: 600;
    }
    .status.paid { border-color: #b7dfc4; background: #f0f8f2; color: #24613a; }
    .status.pending { border-color: #f0d69a; background: #fff9e9; color: #805b13; }
    .status.overdue { border-color: #efc0c0; background: #fff3f2; color: #9c2727; }
    .section-title {
      margin: 0 0 10px; color: #697386; font-size: 10px;
      font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
    }
    .details { width: 100%; margin: 0 0 24px; border-collapse: collapse; }
    .details td { width: 50%; padding: 5px 16px 5px 0; vertical-align: top; }
    .detail-label { display: block; margin-bottom: 2px; color: #778195; font-size: 10px; }
    .detail-value { color: #202632; font-size: 13px; font-weight: 600; word-break: break-word; }
    .items { width: 100%; border-collapse: collapse; }
    .items th {
      padding: 10px 12px; border-top: 1px solid #dfe3e9; border-bottom: 1px solid #dfe3e9;
      color: #697386; font-size: 10px; letter-spacing: .08em; text-align: left;
      text-transform: uppercase;
    }
    .items td { padding: 14px 12px; border-bottom: 1px solid #e8ebef; font-size: 13px; }
    .items .amount { text-align: right; white-space: nowrap; }
    .total-row td { padding-top: 16px; border: 0; }
    .total-label { color: #4b5565; font-size: 13px; font-weight: 600; text-align: right; }
    .total-amount { color: #202632; font-size: 21px; font-weight: 700; text-align: right; white-space: nowrap; }
    .footnote {
      margin-top: 28px; padding-top: 14px; border-top: 1px solid #e1e5eb;
      color: #697386; font-size: 11px; text-align: center;
    }
    @media (max-width: 560px) {
      body { padding: 12px; }
      .invoice-actions { align-items: stretch; flex-direction: column; }
      .invoice-actions .action-group { display: grid; grid-template-columns: 1fr 1fr; }
      .invoice-actions a, .invoice-actions button { width: 100%; }
      .invoice { padding: 22px 18px; }
      .brand { font-size: 17px; }
      .document-title { font-size: 18px; }
      .details td { display: block; width: 100%; padding: 7px 0; }
      .items th, .items td { padding-right: 7px; padding-left: 7px; }
    }
    @media print {
      body { padding: 0; background: #fff; }
      .invoice-actions { display: none !important; }
      .invoice { max-width: none; margin: 0; border-color: #e1e5eb; }
    }
  </style>
</head>
<body>
  <?php if (!$isPdf): ?>
  <nav class="invoice-actions" aria-label="Invoice actions">
    <a href="<?= e($paymentsUrl) ?>">Back to payments</a>
    <div class="action-group">
      <button type="button" onclick="window.print()">Print invoice</button>
      <a class="primary" href="<?= e($pdfUrl) ?>">Download PDF</a>
    </div>
  </nav>
  <?php endif; ?>
  <main class="invoice">
    <table class="masthead" role="presentation">
      <tr>
        <td>
          <div class="brand">BE FIT FLEX</div>
          <div class="brand-sub">Gym &amp; Fitness</div>
        </td>
        <td>
          <div class="document-title">Payment receipt</div>
          <div class="document-type">Membership invoice</div>
        </td>
      </tr>
    </table>
    <div class="rule"></div>

    <div class="status <?= e($statusClass) ?>">
      Payment status: <?= e($status) ?>
    </div>

    <h2 class="section-title">Member and payment details</h2>
    <table class="details">
      <tr>
        <td><span class="detail-label">Member</span><span class="detail-value"><?= e($payment['member_name']) ?></span></td>
        <td><span class="detail-label">Invoice reference</span><span class="detail-value"><?= e($payment['payment_id']) ?></span></td>
      </tr>
      <tr>
        <td><span class="detail-label">Member ID</span><span class="detail-value"><?= e($payment['member_id']) ?></span></td>
        <td><span class="detail-label">Membership plan</span><span class="detail-value"><?= e($payment['membership_type']) ?></span></td>
      </tr>
      <tr>
        <td><span class="detail-label">Payment method</span><span class="detail-value"><?= e($payment['payment_method']) ?></span></td>
        <td><span class="detail-label">Payment date</span><span class="detail-value"><?= e(dt($payment['payment_date'])) ?></span></td>
      </tr>
    </table>

    <h2 class="section-title">Payment summary</h2>
    <table class="items">
      <thead>
        <tr><th>Description</th><th class="amount">Amount</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><?= nl2br(e($description)) ?></td>
          <td class="amount"><?= money($payment['amount']) ?></td>
        </tr>
        <tr class="total-row">
          <td class="total-label">Total</td>
          <td class="total-amount"><?= money($payment['amount']) ?></td>
        </tr>
      </tbody>
    </table>

    <div class="footnote">Thank you for choosing Be Fit Flex Gym.</div>
  </main>
</body>
</html>
<?php
$invoiceHtml = ob_get_clean();

if ($isPdf) {
    require __DIR__ . '/vendor/autoload.php';

    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($invoiceHtml, 'UTF-8');
    $dompdf->setPaper('A4');
    $dompdf->render();
    $dompdf->stream('invoice-' . $payment['payment_id'] . '.pdf', ['Attachment' => true]);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
echo $invoiceHtml;
