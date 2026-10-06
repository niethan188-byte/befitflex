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
$reference = (string) ($event['requestReferenceNumber'] ?? $event['referenceNumber'] ?? '');
$status = strtoupper((string) ($event['status'] ?? $event['paymentStatus'] ?? ''));
if ($reference === '' || !in_array($status, ['SUCCESS', 'COMPLETED', 'FAILED', 'CANCELLED'], true)) {
    http_response_code(422);
    exit('Invalid webhook payload.');
}

$paymentStatus = in_array($status, ['SUCCESS', 'COMPLETED'], true) ? 'Paid' : 'Overdue';
$stmt = db()->prepare('UPDATE payments SET payment_status = ?, payment_date = CASE WHEN ? = "Paid" THEN CURDATE() ELSE payment_date END WHERE payment_id = ?');
$stmt->execute([$paymentStatus, $paymentStatus, $reference]);
if ($stmt->rowCount() > 0) {
    log_activity('Maya webhook', 'Payments', $reference . ' → ' . $paymentStatus);
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);