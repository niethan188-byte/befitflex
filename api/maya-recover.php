<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maya.php';

$u = require_role('admin');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('POST required.');
}
csrf_check();

$paymentId = trim((string) ($_POST['payment_id'] ?? ''));
$checkoutId = trim((string) ($_POST['checkout_id'] ?? ''));
$paymentsUrl = '../admin/payments.php';
if ($checkoutId === '' || strlen($checkoutId) > 100) {
    flash('Enter the checkout ID from the Maya receipt or merchant dashboard.', 'err');
    redirect($paymentsUrl);
}

$payment = row(
    'SELECT payment_id, amount, payment_status, maya_checkout_id
       FROM payments
      WHERE payment_id = ? AND payment_status IN ("Pending", "Overdue")',
    [$paymentId]
);
if (!$payment) {
    flash('Pending payment not found.', 'err');
    redirect($paymentsUrl);
}
if (!empty($payment['maya_checkout_id'])) {
    flash('This payment already has a saved Maya checkout ID. Use Check Maya status instead.', 'err');
    redirect($paymentsUrl);
}

$result = maya_get_checkout($checkoutId);
if (!$result['ok']) {
    flash($result['error'], 'err');
    redirect($paymentsUrl);
}
if (!maya_checkout_matches_payment($result['data'], $payment, $checkoutId)) {
    error_log('Maya recovery lookup did not match a pending payment.');
    flash('Maya could not match that checkout to this payment by checkout ID, payment reference, amount, and currency. No changes were made.', 'err');
    redirect($paymentsUrl);
}

$checkout = $result['data'];
$verifiedPayment = $payment;
$verifiedPayment['maya_checkout_id'] = $checkoutId;
$confirmed = maya_checkout_confirms_payment($checkout, $verifiedPayment, $checkoutId);
if ($confirmed) {
    $stmt = db()->prepare(
        'UPDATE payments
            SET maya_checkout_id = ?, payment_method = "Maya", payment_status = "Paid", payment_date = CURDATE()
          WHERE payment_id = ? AND payment_status IN ("Pending", "Overdue")
            AND (maya_checkout_id IS NULL OR maya_checkout_id = "")'
    );
} else {
    $stmt = db()->prepare(
        'UPDATE payments
            SET maya_checkout_id = ?, payment_method = "Maya"
          WHERE payment_id = ? AND payment_status IN ("Pending", "Overdue")
            AND (maya_checkout_id IS NULL OR maya_checkout_id = "")'
    );
}
$stmt->execute([$checkoutId, $paymentId]);
if ($stmt->rowCount() !== 1) {
    flash('The payment changed while Maya was being checked. Refresh the payment page and verify its current status.', 'err');
    redirect($paymentsUrl);
}

if ($confirmed) {
    log_activity('Maya checkout recovery', 'Payments', $paymentId . ' → Paid');
    flash('Maya confirmed this existing checkout as paid.');
} else {
    log_activity('Maya checkout recovery', 'Payments', $paymentId . ' → checkout linked');
    flash('Maya matched the checkout, but has not confirmed payment success. Its status remains pending; check Maya status again later.', 'err');
}
redirect($paymentsUrl);
