<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maya.php';

$u = require_role('admin', 'member');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('POST required.');
}
csrf_check();

$paymentId = trim((string) ($_POST['payment_id'] ?? ''));
$payment = row(
    'SELECT p.*, m.member_name, m.email, m.member_id
       FROM payments p JOIN members m ON m.member_id = p.member_id
      WHERE p.payment_id = ? AND p.payment_status IN ("Pending", "Overdue")',
    [$paymentId]
);
$paymentsUrl = $u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php';
if (!$payment || ($u['type'] === 'member' && $payment['member_id'] !== ($_SESSION['member_id'] ?? ''))) {
    http_response_code(404);
    exit('Payment not found.');
}

$checkoutId = trim((string) ($payment['maya_checkout_id'] ?? ''));
if ($checkoutId === '') {
    flash('No Maya checkout ID is saved. Start a checkout from the payment page or contact the gym if you already paid.', 'err');
    redirect($paymentsUrl);
}
if (!maya_payment_flow_configured()) {
    flash('Maya retry requires public and secret API keys and PHP cURL so the existing payment can be verified.', 'err');
    redirect($paymentsUrl);
}

$statusResult = maya_get_checkout($checkoutId);
if (!$statusResult['ok']) {
    flash($statusResult['error'], 'err');
    redirect($paymentsUrl);
}
$checkout = $statusResult['data'];
if (maya_checkout_confirms_payment($checkout, $payment, $checkoutId)) {
    $stmt = db()->prepare(
        'UPDATE payments
            SET payment_method = "Maya", payment_status = "Paid", payment_date = CURDATE()
          WHERE payment_id = ? AND maya_checkout_id = ? AND payment_status IN ("Pending", "Overdue")'
    );
    $stmt->execute([$paymentId, $checkoutId]);
    if ($stmt->rowCount() === 1) {
        log_activity('Maya retry status check', 'Payments', $paymentId . ' → Paid');
        flash('Maya confirmed the existing checkout as paid. No new checkout was started.');
    } else {
        flash('The payment changed while Maya was being checked. Refresh the payment page.', 'err');
    }
    redirect($paymentsUrl);
}

if (!maya_checkout_is_retryable_failure($checkout, $payment, $checkoutId)) {
    $mismatch = maya_checkout_payment_mismatch($checkout, $payment, $checkoutId);
    if ($mismatch !== null) {
        error_log('Maya retry status did not match the pending payment: ' . $mismatch . ' mismatch.');
        flash('Maya could not verify the saved checkout ' . $mismatch . ' against this payment. No new checkout was started. Ask staff to check the Maya merchant dashboard or contact support.', 'err');
    } else {
        flash('Maya has not confirmed that the existing checkout failed, expired, or was cancelled. Check its status again later; no new checkout was started.', 'err');
    }
    redirect($paymentsUrl);
}

$configuredBase = getenv('BEFITFLEX_APP_URL');
if (is_string($configuredBase) && trim($configuredBase) !== '') {
    $base = rtrim(trim($configuredBase), '/');
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = rtrim($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
}
$returnUrl = $base . '/api/maya-return.php?payment_id=' . rawurlencode($paymentId);
$createResult = maya_create_checkout($payment, $returnUrl, $returnUrl . '&result=failed');
if (!$createResult['ok']) {
    flash($createResult['error'], 'err');
    redirect($paymentsUrl);
}

$redirectUrl = $createResult['data']['redirectUrl'] ?? $createResult['data']['checkoutUrl'] ?? null;
$newCheckoutId = $createResult['data']['checkoutId'] ?? null;
if (!is_string($redirectUrl) || filter_var($redirectUrl, FILTER_VALIDATE_URL) === false
    || strtolower((string) parse_url($redirectUrl, PHP_URL_SCHEME)) !== 'https') {
    flash('Maya returned no valid checkout URL. The payment was not redirected; refresh and check its status before trying again.', 'err');
    redirect($paymentsUrl);
}
if (!is_string($newCheckoutId) || trim($newCheckoutId) === '' || hash_equals($checkoutId, trim($newCheckoutId))) {
    flash('Maya did not return a new checkout reference. The payment was not redirected; refresh and check its status before trying again.', 'err');
    redirect($paymentsUrl);
}
$newCheckoutId = trim($newCheckoutId);

$stmt = db()->prepare(
    'UPDATE payments SET maya_checkout_id = ?, payment_method = "Maya"
      WHERE payment_id = ? AND maya_checkout_id = ? AND payment_status IN ("Pending", "Overdue")'
);
$stmt->execute([$newCheckoutId, $paymentId, $checkoutId]);
if ($stmt->rowCount() !== 1) {
    flash('The payment changed before the new checkout could be linked. It was not opened; refresh and check its status.', 'err');
    redirect($paymentsUrl);
}

header('Location: ' . $redirectUrl);
exit;
