<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../gatewayfunctions.php';
require_once __DIR__ . '/../invoicefunctions.php';

$moduleName = 'anore';
$gatewayParams = getGatewayVariables($moduleName);

if (empty($gatewayParams['type'])) {
    anore_callbackRespond(503, 'Gateway is not active');
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    anore_callbackLog($gatewayParams, ['error' => 'empty body'], 'Rejected');
    anore_callbackRespond(400, 'Empty body');
}

$webhookSecret = trim((string) ($gatewayParams['webhookSecret'] ?? ''));
$signature = strtolower(trim((string) ($_SERVER['HTTP_ANORE_SIGNATURE'] ?? '')));
if ($webhookSecret === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
    anore_callbackLog($gatewayParams, ['error' => 'missing webhook secret or signature'], 'Rejected');
    anore_callbackRespond(401, 'Invalid signature');
}

$expected = hash_hmac('sha256', $raw, $webhookSecret);
if (!hash_equals($expected, $signature)) {
    anore_callbackLog($gatewayParams, ['error' => 'signature mismatch'], 'Rejected');
    anore_callbackRespond(401, 'Invalid signature');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    anore_callbackLog($gatewayParams, ['error' => 'invalid JSON'], 'Rejected');
    anore_callbackRespond(400, 'Invalid JSON');
}

$event = trim((string) ($payload['event'] ?? ''));
if ($event === 'payment.expired') {
    anore_callbackMarkExpired((string) ($payload['id'] ?? ''));
    anore_callbackLog($gatewayParams, $payload, 'Ignored: payment.expired');
    anore_callbackRespond(200, 'OK');
}
if ($event !== 'payment.succeeded') {
    anore_callbackLog($gatewayParams, $payload, 'Ignored: ' . ($event !== '' ? $event : 'unknown event'));
    anore_callbackRespond(200, 'OK');
}

$invoiceId = filter_var($payload['orderId'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$transactionId = trim((string) ($payload['id'] ?? ''));
$amount = isset($payload['amount']) && is_numeric($payload['amount'])
    ? round((float) $payload['amount'], 2)
    : 0.0;

if ($invoiceId === false || $transactionId === '' || $amount <= 0) {
    anore_callbackLog($gatewayParams, $payload, 'Rejected: missing payment fields');
    anore_callbackRespond(400, 'Missing payment fields');
}

checkCbInvoiceID($invoiceId, $moduleName);

try {
    if (!\WHMCS\Database\Capsule::schema()->hasTable('mod_anore_payments')) {
        throw new RuntimeException('Anore payment mapping table is missing');
    }

    $mapping = \WHMCS\Database\Capsule::table('mod_anore_payments')
        ->where('invoice_id', $invoiceId)
        ->first();
    if ($mapping === null) {
        throw new RuntimeException('Anore payment mapping was not found');
    }
    if (!hash_equals((string) $mapping->payment_id, $transactionId)) {
        throw new RuntimeException('Anore payment ID does not match the invoice mapping');
    }

    $currency = strtoupper(trim((string) ($payload['currency'] ?? '')));
    if ($currency === '' || !hash_equals((string) $mapping->currency, $currency)) {
        throw new RuntimeException('Payment currency does not match the invoice mapping');
    }
    if (abs((float) $mapping->amount - $amount) > 0.01) {
        throw new RuntimeException('Payment amount does not match the invoice mapping');
    }

    $alreadyProcessed = \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('gateway', $moduleName)
        ->where('transid', $transactionId)
        ->exists();
} catch (Throwable $error) {
    anore_callbackLog($gatewayParams, ['error' => $error->getMessage(), 'payment' => $payload], 'Database check failed');
    anore_callbackRespond(500, 'Database error');
}

if ($alreadyProcessed) {
    anore_callbackLog($gatewayParams, $payload, 'Duplicate ignored');
    anore_callbackRespond(200, 'OK');
}

checkCbTransID($transactionId);

try {
    addInvoicePayment($invoiceId, $transactionId, $amount, 0.0, $moduleName);

    if (\WHMCS\Database\Capsule::schema()->hasTable('mod_anore_payments')) {
        \WHMCS\Database\Capsule::table('mod_anore_payments')
            ->where('invoice_id', $invoiceId)
            ->where('payment_id', $transactionId)
            ->update([
                'status' => 'paid',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }
} catch (Throwable $error) {
    anore_callbackLog($gatewayParams, ['error' => $error->getMessage(), 'payment' => $payload], 'Invoice credit failed');
    anore_callbackRespond(500, 'Invoice credit failed');
}

anore_callbackLog($gatewayParams, $payload, 'Successful');
anore_callbackRespond(200, 'OK');

function anore_callbackLog(array $gatewayParams, array $data, string $status): void
{
    logTransaction((string) ($gatewayParams['name'] ?? 'anore'), $data, $status);
}

function anore_callbackMarkExpired(string $paymentId): void
{
    if ($paymentId === '') {
        return;
    }

    try {
        if (\WHMCS\Database\Capsule::schema()->hasTable('mod_anore_payments')) {
            \WHMCS\Database\Capsule::table('mod_anore_payments')
                ->where('payment_id', $paymentId)
                ->update([
                    'status' => 'expired',
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
        }
    } catch (Throwable $error) {
        logTransaction('anore', ['paymentId' => $paymentId, 'error' => $error->getMessage()], 'Expiry cache update failed');
    }
}

function anore_callbackRespond(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}
