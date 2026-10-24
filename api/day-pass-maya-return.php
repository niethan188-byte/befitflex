<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maya.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('GET required.');
}

$passId = trim((string) ($_GET['pass_id'] ?? ''));
$token = (string) ($_GET['token'] ?? '');
$pass = row(
    'SELECT pass_id, amount, payment_method, pass_status, public_token, maya_checkout_id
       FROM day_passes WHERE pass_id = ?',
    [$passId]
);
if (!$pass || !preg_match('/^[a-f0-9]{64}$/', $token)
    || !is_string($pass['public_token']) || !hash_equals($pass['public_token'], $token)) {
    http_response_code(404);
    exit('Day pass not found.');
}

$passUrl = '../day-pass.php?pass_id=' . rawurlencode($passId) . '&token=' . rawurlencode($token);
if (($_GET['result'] ?? '') === 'failed') {
    flash('Maya did not confirm the payment. The pass remains inactive until payment is verified.', 'err');
    redirect($passUrl);
}
if ($pass['pass_status'] === 'Paid') {
    redirect($passUrl);
}
if ($pass['payment_method'] !== 'Maya' || $pass['pass_status'] !== 'Pending') {
    http_response_code(409);
    exit('This pass is not awaiting Maya payment.');
}

$checkoutId = (string) ($pass['maya_checkout_id'] ?? '');
if ($checkoutId === '') {
    flash('The Maya checkout reference is missing. The pass remains inactive; contact the gym with your receipt.', 'err');
    redirect($passUrl);
}

$result = maya_get_checkout($checkoutId);
if (!$result['ok']) {
    flash($result['error'], 'err');
    redirect($passUrl);
}

$payment = [
    'payment_id' => $pass['pass_id'],
    'amount' => $pass['amount'],
    'payment_status' => $pass['pass_status'],
    'maya_checkout_id' => $checkoutId,
];
$checkout = $result['data'];
if (!maya_checkout_confirms_payment($checkout, $payment, $checkoutId)) {
    $mismatch = maya_checkout_payment_mismatch($checkout, $payment, $checkoutId);
    if ($mismatch !== null) {
        error_log('Maya day-pass return verification mismatch for pass ' . $passId . ': ' . $mismatch);
        flash('Maya could not verify the pass payment details. The pass remains inactive; contact the gym with your receipt.', 'err');
    } else {
        flash('Maya has not confirmed successful payment yet. The pass remains inactive; refresh shortly.', 'err');
    }
    redirect($passUrl);
}

$stmt = db()->prepare(
    "UPDATE day_passes SET pass_status = 'Paid'
      WHERE pass_id = ? AND maya_checkout_id = ? AND payment_method = 'Maya' AND pass_status = 'Pending'"
);
$stmt->execute([$passId, $checkoutId]);
if ($stmt->rowCount() > 0) {
    log_activity('Maya return verification', 'Day Passes', $passId . ' → Paid');
} elseif ((string) scalar('SELECT pass_status FROM day_passes WHERE pass_id = ?', [$passId]) !== 'Paid') {
    flash('The pass changed while Maya was being checked. Refresh to verify its current status.', 'err');
    redirect($passUrl);
}

flash('Maya confirmed your payment. Your day-pass QR is ready.');
redirect($passUrl);
