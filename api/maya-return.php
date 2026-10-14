<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maya.php';

$u = require_role('admin', 'member');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    exit('GET or POST required.');
}

$paymentId = trim((string) ($_REQUEST['payment_id'] ?? ''));
$payment = row(
    'SELECT p.*, m.member_id
       FROM payments p JOIN members m ON m.member_id = p.member_id
      WHERE p.payment_id = ?',
    [$paymentId]
);
if (!$payment || ($u['type'] === 'member' && $payment['member_id'] !== ($_SESSION['member_id'] ?? ''))) {
    http_response_code(404);
    exit('Payment not found.');
}

$paymentsUrl = $u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}
if (($_REQUEST['result'] ?? '') === 'failed') {
    flash('Maya did not confirm the payment. Its status has not been changed.', 'err');
    redirect($paymentsUrl);
}
if ($payment['payment_status'] === 'Paid') {
    flash('This payment is already confirmed as paid.');
    redirect($paymentsUrl);
}

$checkoutId = (string) ($payment['maya_checkout_id'] ?? '');
if ($checkoutId === '') {
    flash('No Maya checkout reference is saved for this payment. Its status remains unchanged.', 'err');
    redirect($paymentsUrl);
}

$result = maya_get_checkout($checkoutId);
if (!$result['ok']) {
    flash($result['error'], 'err');
    redirect($paymentsUrl);
}

$checkout = $result['data'];
if (!maya_checkout_confirms_payment($checkout, $payment)) {
    if (($checkout['requestReferenceNumber'] ?? null) !== $paymentId) {
        error_log('Maya status lookup reference mismatch for payment ' . $paymentId);
        flash('Maya returned a different payment reference. The payment status was not changed.', 'err');
    } elseif (($checkout['paymentStatus'] ?? null) === 'PAYMENT_SUCCESS') {
        error_log('Maya status lookup amount mismatch for payment ' . $paymentId);
        flash('Maya returned a different amount or currency. The payment status was not changed.', 'err');
    } else {
        flash('Maya has not confirmed a successful payment yet. Please check again shortly.', 'err');
    }
    redirect($paymentsUrl);
}

$stmt = db()->prepare(
    'UPDATE payments SET payment_method = "Maya", payment_status = "Paid", payment_date = CURDATE()
      WHERE payment_id = ? AND payment_status <> "Paid"'
);
$stmt->execute([$paymentId]);
if ($stmt->rowCount() > 0) {
    log_activity('Maya return verification', 'Payments', $paymentId . ' → Paid');
}
flash('Maya confirmed your payment.');
redirect($paymentsUrl);
