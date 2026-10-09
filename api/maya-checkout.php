<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/maya.php';

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

$configuredBase = getenv('BEFITFLEX_APP_URL');
if (is_string($configuredBase) && trim($configuredBase) !== '') {
    $base = rtrim(trim($configuredBase), '/');
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = rtrim($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
}
$result = maya_create_checkout($payment, $base . '/member/payments.php?maya=success', $base . '/member/payments.php?maya=failed');
if (!$result['ok']) {
    flash($result['error'], 'err');
    redirect($u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php');
}

$redirect = $result['data']['redirectUrl'] ?? $result['data']['checkoutUrl'] ?? null;
if (!is_string($redirect) || filter_var($redirect, FILTER_VALIDATE_URL) === false
    || strtolower((string) parse_url($redirect, PHP_URL_SCHEME)) !== 'https') {
    flash('Maya returned no checkout URL.', 'err');
    redirect($u['type'] === 'member' ? '../member/payments.php' : '../admin/payments.php');
}
header('Location: ' . $redirect);
exit;