<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/maya.php';

function check_maya_test(string $name, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException('Failed: ' . $name);
    }
    echo 'PASS  ' . $name . PHP_EOL;
}

$payment = [
    'payment_id' => 'PAY00001',
    'amount' => '995.00',
    'maya_checkout_id' => 'checkout-123',
];
$checkout = [
    'checkoutId' => 'checkout-123',
    'paymentStatus' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
];

check_maya_test('successful checkout must match reference, amount, currency, and saved ID', maya_checkout_confirms_payment($checkout, $payment, 'checkout-123'));
check_maya_test('checkout fetched using saved ID may omit its ID in the response body', maya_checkout_confirms_payment([
    'paymentStatus' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
], $payment, 'checkout-123'));
check_maya_test('generic response id is not mistaken for the checkout ID', maya_checkout_confirms_payment([
    'id' => 'payment-transaction-456',
    'paymentStatus' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
], $payment, 'checkout-123'));
check_maya_test('explicitly conflicting checkout ID is rejected', !maya_checkout_confirms_payment(array_replace($checkout, ['checkoutId' => 'checkout-other']), $payment, 'checkout-123'));
check_maya_test('different requested checkout ID is rejected', !maya_checkout_confirms_payment($checkout, $payment, 'checkout-other'));
check_maya_test('wrong payment reference is rejected', !maya_checkout_confirms_payment(array_replace($checkout, ['requestReferenceNumber' => 'OTHER']), $payment, 'checkout-123'));
check_maya_test('wrong amount is rejected', !maya_checkout_confirms_payment([
    'checkoutId' => 'checkout-123',
    'paymentStatus' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995.01, 'currency' => 'PHP'],
], $payment, 'checkout-123'));
check_maya_test('wrong currency is rejected', !maya_checkout_confirms_payment([
    'checkoutId' => 'checkout-123',
    'paymentStatus' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'USD'],
], $payment, 'checkout-123'));
check_maya_test('missing saved checkout ID is rejected', !maya_checkout_confirms_payment($checkout, [
    'payment_id' => 'PAY00001',
    'amount' => '995.00',
], 'checkout-123'));
check_maya_test('fractional-cent amounts are rejected', maya_amount_in_cents('995.001') === null);

$event = [
    'id' => 'checkout-123',
    'status' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
];
check_maya_test('signed webhook details must match saved checkout', maya_webhook_confirms_payment($event, $payment));
check_maya_test('webhook with another checkout ID is rejected', !maya_webhook_confirms_payment(array_replace($event, ['id' => 'checkout-other']), $payment));
check_maya_test('complete signed webhook can safely recover an unsaved checkout ID', maya_webhook_confirms_payment($event, [
    'payment_id' => 'PAY00001',
    'amount' => '995.00',
]));
check_maya_test('webhook without amount and currency is rejected', !maya_webhook_confirms_payment([
    'id' => 'checkout-123',
    'status' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
], $payment));
check_maya_test('webhook success label without PAYMENT_SUCCESS is rejected', !maya_webhook_confirms_payment([
    'id' => 'checkout-123',
    'status' => 'COMPLETED',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
], $payment));
check_maya_test('manual admin payment is allowed for a pending cash payment', maya_manual_mark_allowed([
    'payment_status' => 'Pending',
    'payment_method' => 'Cash',
    'maya_checkout_id' => null,
]));
check_maya_test('manual admin payment is blocked for a Maya checkout', !maya_manual_mark_allowed([
    'payment_status' => 'Pending',
    'payment_method' => 'Cash',
    'maya_checkout_id' => 'checkout-123',
]));
check_maya_test('manual admin payment is blocked for Maya method without a saved ID', !maya_manual_mark_allowed([
    'payment_status' => 'Pending',
    'payment_method' => 'Maya',
    'maya_checkout_id' => null,
]));

$previousSecret = getenv('MAYA_WEBHOOK_SECRET');
putenv('MAYA_WEBHOOK_SECRET=maya-test-secret');
$body = '{"event":"test"}';
$validSignature = hash_hmac('sha256', $body, 'maya-test-secret');
check_maya_test('valid webhook signature is accepted', maya_webhook_valid($body, $validSignature));
check_maya_test('invalid webhook signature is rejected', !maya_webhook_valid($body, 'invalid'));
if ($previousSecret === false) {
    putenv('MAYA_WEBHOOK_SECRET');
} else {
    putenv('MAYA_WEBHOOK_SECRET=' . $previousSecret);
}
