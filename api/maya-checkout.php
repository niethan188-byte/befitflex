<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maya.php';

$u = require_role('admin', 'member');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
if (!$payment || ($u['type'] === 'member' && $payment['member_id'] !== ($_SESSION['member_id'] ?? ''))) {
    http_response_code(404);
    exit('Payment not found.');
}

if (!empty($payment['maya_checkout_id'])) {
    redirect('maya-return.php?payment_id=' . rawurlencode($paymentId));
}
if ($payment['payment_method'] === 'Maya') {
    flash('This Maya payment has no saved checkout ID. Contact the gym with your receipt so staff can verify the existing checkout instead of creating another one.', 'err');
    redirect($u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php');
}
if (!maya_payment_flow_configured()) {
    flash('Maya checkout requires public and secret API keys and PHP cURL so payment status can be verified.', 'err');
    redirect($u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php');
}

$configuredBase = getenv('BEFITFLEX_APP_URL');
if (is_string($configuredBase) && trim($configuredBase) !== '') {
    $base = rtrim(trim($configuredBase), '/');
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = rtrim($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
}
$returnUrl = $base . '/api/maya-return.php?payment_id=' . rawurlencode($paymentId);
$result = maya_create_checkout($payment, $returnUrl, $returnUrl . '&result=failed');
if (!$result['ok']) {
    flash($result['error'], 'err');
    redirect($u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php');
}

$redirect = $result['data']['redirectUrl'] ?? $result['data']['checkoutUrl'] ?? null;
$checkoutId = $result['data']['checkoutId'] ?? null;
if (!is_string($redirect) || filter_var($redirect, FILTER_VALIDATE_URL) === false
    || strtolower((string) parse_url($redirect, PHP_URL_SCHEME)) !== 'https') {
    flash('Maya returned no checkout URL.', 'err');
    redirect($u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php');
}
if (!is_string($checkoutId) || trim($checkoutId) === '') {
    flash('Maya returned no checkout reference. Please try again.', 'err');
    redirect($u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php');
}
$checkoutId = trim($checkoutId);
$stmt = db()->prepare(
    "UPDATE payments SET maya_checkout_id = ?
      WHERE payment_id = ? AND payment_status IN ('Pending', 'Overdue')
        AND (maya_checkout_id IS NULL OR maya_checkout_id = '')"
);
$stmt->execute([$checkoutId, $paymentId]);
if ($stmt->rowCount() !== 1) {
    flash('A Maya checkout could not be safely linked to this payment. It was not opened in your browser; refresh the payment page and check its status before retrying.', 'err');
    redirect($u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php');
}
header('Location: ' . $redirect);
exit;