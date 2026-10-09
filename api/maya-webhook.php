<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/maya.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required.');
}

$body = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_MAYA_SIGNATURE'] ?? '');
if (!maya_webhook_valid($body, $signature)) {
    http_response_code(401);
    exit('Invalid webhook signature.');
}

$event = json_decode($body, true);
if (!is_array($event)) {
    http_response_code(422);
    exit('Invalid webhook payload.');
}
$reference = (string) ($event['requestReferenceNumber'] ?? $event['referenceNumber'] ?? '');
$status = strtoupper((string) ($event['status'] ?? $event['paymentStatus'] ?? ''));
if ($reference === '' || !in_array($status, [
    'SUCCESS', 'COMPLETED', 'PAYMENT_SUCCESS',
    'FAILED', 'CANCELLED', 'EXPIRED', 'PAYMENT_FAILED', 'PAYMENT_CANCELLED', 'PAYMENT_EXPIRED',
], true)) {
    http_response_code(422);
    exit('Invalid webhook payload.');
}

if (in_array($status, ['SUCCESS', 'COMPLETED', 'PAYMENT_SUCCESS'], true)) {
    $stmt = db()->prepare(
        'UPDATE payments SET payment_method = "Maya", payment_status = "Paid", payment_date = CURDATE()
          WHERE payment_id = ? AND payment_status <> "Paid"'
    );
    $stmt->execute([$reference]);
    if ($stmt->rowCount() > 0) {
        log_activity('Maya webhook', 'Payments', $reference . ' → Paid');
    }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);