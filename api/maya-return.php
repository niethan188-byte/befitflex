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
    flash('No Maya checkout ID is saved, so Maya cannot be queried by payment reference. Its status remains unchanged. Contact the gym with your receipt for manual verification in Maya.', 'err');
    redirect($paymentsUrl);
}

$result = maya_get_checkout($checkoutId);
if (!$result['ok']) {
    flash($result['error'], 'err');
    redirect($paymentsUrl);
}

$checkout = $result['data'];
if (!maya_checkout_confirms_payment($checkout, $payment, $checkoutId)) {
    $mismatch = maya_checkout_payment_mismatch($checkout, $payment, $checkoutId);
    if ($mismatch === 'payment reference') {
        error_log('Maya status lookup reference mismatch for payment ' . $paymentId);
        flash('Maya returned a different payment reference. The payment status was not changed.', 'err');
    } elseif ($mismatch === 'checkout reference') {
        error_log('Maya status lookup checkout ID mismatch for payment ' . $paymentId);
        flash('Maya returned a conflicting checkout ID. The payment status was not changed.', 'err');
    } elseif ($mismatch === 'currency') {
        error_log('Maya status lookup currency mismatch for payment ' . $paymentId);
        flash('Maya returned a different currency. The payment status was not changed.', 'err');
    } elseif ($mismatch === 'amount') {
        $expectedCents = maya_amount_in_cents($payment['amount'] ?? null);
        $returnedCents = maya_checkout_amount_in_cents($checkout);
        error_log(
            'Maya status lookup amount mismatch for payment ' . $paymentId
            . ' (expected cents=' . ($expectedCents === null ? 'invalid' : $expectedCents)
            . ', returned cents=' . ($returnedCents === null ? 'invalid' : $returnedCents) . ').'
        );
        flash('Maya’s verified amount does not match this invoice exactly. The payment status was not changed. Do not pay again; contact the gym to check the Maya receipt and merchant record.', 'err');
    } elseif (maya_checkout_status($checkout) === 'PAYMENT_SUCCESS') {
        error_log('Maya status lookup did not include all required payment details for payment ' . $paymentId);
        flash('Maya confirmed payment success but did not return all details needed to verify it. The payment status was not changed; contact the gym with your Maya receipt.', 'err');
    } else {
        flash('Maya has not confirmed a successful payment yet. Please check again shortly.', 'err');
    }
    redirect($paymentsUrl);
}

$stmt = db()->prepare(
    'UPDATE payments SET payment_method = "Maya", payment_status = "Paid", payment_date = CURDATE()
      WHERE payment_id = ? AND maya_checkout_id = ? AND payment_status <> "Paid"'
);
$stmt->execute([$paymentId, $checkoutId]);
if ($stmt->rowCount() > 0) {
    log_activity('Maya return verification', 'Payments', $paymentId . ' → Paid');
} else {
    flash('The payment changed while Maya was being checked. Refresh the payment page and verify its current status.', 'err');
    redirect($paymentsUrl);
}
flash('Maya confirmed your payment.');
redirect($paymentsUrl);
