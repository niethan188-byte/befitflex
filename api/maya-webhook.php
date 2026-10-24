<?php
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maya.php';

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

$confirmed = false;
if (in_array($status, ['SUCCESS', 'COMPLETED', 'PAYMENT_SUCCESS'], true)) {
    if (preg_match('/^DAY\d{5,}$/', $reference)) {
        $pass = row(
            "SELECT pass_id AS payment_id, amount, pass_status AS payment_status, maya_checkout_id
               FROM day_passes
              WHERE pass_id = ? AND pass_status = 'Pending' AND payment_method = 'Maya'",
            [$reference]
        );
        if (!$pass || !maya_webhook_confirms_payment($event, $pass)) {
            error_log('Maya day-pass webhook did not match a pending pass.');
            http_response_code(202);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'confirmed' => false]);
            exit;
        }

        $checkoutId = (string) ($event['checkoutId'] ?? $event['id'] ?? '');
        $stmt = db()->prepare(
            'UPDATE day_passes
                SET maya_checkout_id = ?, pass_status = "Paid"
              WHERE pass_id = ? AND payment_method = "Maya" AND pass_status = "Pending"
                AND (maya_checkout_id IS NULL OR maya_checkout_id = "" OR maya_checkout_id = ?)'
        );
        $stmt->execute([$checkoutId, $reference, $checkoutId]);
        if ($stmt->rowCount() > 0) {
            log_activity('Maya webhook', 'Day Passes', $reference . ' → Paid');
            $confirmed = true;
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'confirmed' => $confirmed]);
        exit;
    }

    $payment = row(
        'SELECT payment_id, amount, payment_status, maya_checkout_id
           FROM payments
          WHERE payment_id = ? AND payment_status IN ("Pending", "Overdue")',
        [$reference]
    );
    if (!$payment || !maya_webhook_confirms_payment($event, $payment)) {
        error_log('Maya success webhook did not match a pending payment.');
        http_response_code(202);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'confirmed' => false]);
        exit;
    }

    $checkoutId = (string) ($event['checkoutId'] ?? $event['id'] ?? '');
    $stmt = db()->prepare(
        'UPDATE payments
            SET maya_checkout_id = ?, payment_method = "Maya", payment_status = "Paid", payment_date = CURDATE()
          WHERE payment_id = ? AND (maya_checkout_id IS NULL OR maya_checkout_id = "" OR maya_checkout_id = ?)
            AND payment_status IN ("Pending", "Overdue")'
    );
    $stmt->execute([$checkoutId, $reference, $checkoutId]);
    if ($stmt->rowCount() > 0) {
        log_activity('Maya webhook', 'Payments', $reference . ' → Paid');
        $confirmed = true;
    }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'confirmed' => $confirmed]);