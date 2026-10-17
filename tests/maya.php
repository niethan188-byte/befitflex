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
check_maya_test('Maya API totalAmount.amount verifies against local invoice amount', maya_checkout_confirms_payment([
    'checkoutId' => 'checkout-123',
    'paymentStatus' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['amount' => 995, 'currency' => 'PHP'],
], $payment, 'checkout-123'));
check_maya_test('conflicting totalAmount value and amount cannot confirm payment', !maya_checkout_confirms_payment([
    'checkoutId' => 'checkout-123',
    'paymentStatus' => 'PAYMENT_SUCCESS',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'amount' => 996, 'currency' => 'PHP'],
], $payment, 'checkout-123'));
check_maya_test('pending old checkout may be safely linked when server response details match', maya_checkout_matches_payment([
    'checkoutId' => 'checkout-old',
    'paymentStatus' => 'PENDING',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
], ['payment_id' => 'PAY00001', 'amount' => '995.00'], 'checkout-old'));
foreach (['PAYMENT_FAILED', 'PAYMENT_CANCELLED', 'PAYMENT_EXPIRED'] as $failedStatus) {
    check_maya_test($failedStatus . ' permits retry only for the matched checkout', maya_checkout_is_retryable_failure([
        'checkoutId' => 'checkout-123',
        'paymentStatus' => $failedStatus,
        'requestReferenceNumber' => 'PAY00001',
        'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
    ], $payment, 'checkout-123'));
}
foreach (['FAILED', 'CANCELLED', 'EXPIRED'] as $failedStatus) {
    check_maya_test($failedStatus . ' terminal checkout status permits retry', maya_checkout_is_retryable_failure([
        'checkoutId' => 'checkout-123',
        'status' => $failedStatus,
    ], $payment, 'checkout-123'));
}
check_maya_test('failed checkout using status field permits retry when Maya omits payment details', maya_checkout_is_retryable_failure([
    'checkoutId' => 'checkout-123',
    'status' => 'PAYMENT_FAILED',
], $payment, 'checkout-123'));
check_maya_test('retry rejects a present conflicting reference even if status is failed', !maya_checkout_is_retryable_failure([
    'checkoutId' => 'checkout-123',
    'status' => 'PAYMENT_FAILED',
    'requestReferenceNumber' => 'OTHER',
], $payment, 'checkout-123'));
check_maya_test('retry rejects a present conflicting amount even if status is failed', !maya_checkout_is_retryable_failure([
    'checkoutId' => 'checkout-123',
    'status' => 'PAYMENT_FAILED',
    'totalAmount' => ['value' => 994, 'currency' => 'PHP'],
], $payment, 'checkout-123'));
check_maya_test('retry validates Maya totalAmount.amount when returned', !maya_checkout_is_retryable_failure([
    'checkoutId' => 'checkout-123',
    'status' => 'PAYMENT_FAILED',
    'totalAmount' => ['amount' => 994, 'currency' => 'PHP'],
], $payment, 'checkout-123'));
check_maya_test('pending checkout does not permit another checkout', !maya_checkout_is_retryable_failure(array_replace($checkout, [
    'paymentStatus' => 'PENDING',
]), $payment, 'checkout-123'));
check_maya_test('successful checkout does not permit another checkout', !maya_checkout_is_retryable_failure($checkout, $payment, 'checkout-123'));
check_maya_test('retry is blocked for a mismatched checkout', !maya_checkout_is_retryable_failure(array_replace($checkout, [
    'paymentStatus' => 'PAYMENT_FAILED',
    'requestReferenceNumber' => 'OTHER',
]), $payment, 'checkout-123'));
check_maya_test('old checkout with wrong reference cannot be linked', !maya_checkout_matches_payment([
    'checkoutId' => 'checkout-old',
    'paymentStatus' => 'PENDING',
    'requestReferenceNumber' => 'OTHER',
    'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
], ['payment_id' => 'PAY00001', 'amount' => '995.00'], 'checkout-old'));
check_maya_test('old checkout with wrong amount cannot be linked', !maya_checkout_matches_payment([
    'checkoutId' => 'checkout-old',
    'paymentStatus' => 'PENDING',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 994, 'currency' => 'PHP'],
], ['payment_id' => 'PAY00001', 'amount' => '995.00'], 'checkout-old'));
check_maya_test('old checkout with wrong currency cannot be linked', !maya_checkout_matches_payment([
    'checkoutId' => 'checkout-old',
    'paymentStatus' => 'PENDING',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'USD'],
], ['payment_id' => 'PAY00001', 'amount' => '995.00'], 'checkout-old'));
check_maya_test('old checkout with conflicting response ID cannot be linked', !maya_checkout_matches_payment([
    'checkoutId' => 'different-checkout',
    'paymentStatus' => 'PENDING',
    'requestReferenceNumber' => 'PAY00001',
    'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
], ['payment_id' => 'PAY00001', 'amount' => '995.00'], 'checkout-old'));
check_maya_test('checkout mismatch identifies missing payment reference without returning payment data',
    maya_checkout_payment_mismatch([
        'checkoutId' => 'checkout-old',
        'paymentStatus' => 'PAYMENT_FAILED',
        'totalAmount' => ['value' => 995, 'currency' => 'PHP'],
    ], ['payment_id' => 'PAY00001', 'amount' => '995.00'], 'checkout-old') === 'payment reference');
check_maya_test('checkout mismatch identifies amount separately',
    maya_checkout_payment_mismatch([
        'checkoutId' => 'checkout-old',
        'paymentStatus' => 'PAYMENT_FAILED',
        'requestReferenceNumber' => 'PAY00001',
        'totalAmount' => ['value' => 995.01, 'currency' => 'PHP'],
    ], ['payment_id' => 'PAY00001', 'amount' => '995.00'], 'checkout-old') === 'amount');
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
check_maya_test('extra zero decimal places preserve the exact cent amount', maya_amount_in_cents('995.0000') === 99500);
check_maya_test('non-zero fractional-cent amounts are rejected', maya_amount_in_cents('995.001') === null);

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
check_maya_test('Maya cannot be manually created as paid', !maya_manual_payment_creation_allowed('Maya', 'Paid'));
check_maya_test('pending Maya payment may be created for server verification', maya_manual_payment_creation_allowed('Maya', 'Pending'));
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

$previousPublicKey = getenv('MAYA_PUBLIC_KEY');
$previousSecretKey = getenv('MAYA_SECRET_KEY');
putenv('MAYA_PUBLIC_KEY');
putenv('MAYA_SECRET_KEY');
check_maya_test('Maya checkout flow requires both API keys and cURL', !maya_payment_flow_configured());
$unconfiguredCheckout = maya_create_checkout($payment, 'https://merchant.example/return', 'https://merchant.example/failure');
check_maya_test('unconfigured Maya checkout fails before contacting Maya', !$unconfiguredCheckout['ok']);
if ($previousPublicKey === false) {
    putenv('MAYA_PUBLIC_KEY');
} else {
    putenv('MAYA_PUBLIC_KEY=' . $previousPublicKey);
}
if ($previousSecretKey === false) {
    putenv('MAYA_SECRET_KEY');
} else {
    putenv('MAYA_SECRET_KEY=' . $previousSecretKey);
}

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
