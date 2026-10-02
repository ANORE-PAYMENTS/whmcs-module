<?php

try {
    require_once __DIR__ . '/../../../init.php';
    require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
    require_once __DIR__ . '/../../../includes/invoicefunctions.php';
    require_once __DIR__ . '/../anore.php';
    $gatewayParams = getGatewayVariables('anore');
    $result = anore_handleCallback($gatewayParams, (string) file_get_contents('php://input'), $_SERVER);
} catch (Throwable $error) {
    if (function_exists('anore_safeLog')) {
        anore_safeLog('anore', ['error' => $error->getMessage()], 'Callback temporarily unavailable');
    } else {
        error_log('[anore] Callback bootstrap failed (' . get_class($error) . ')');
    }
    $result = [503, 'Temporarily unavailable; retry later'];
}

http_response_code($result[0]);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if ($result[0] === 405) header('Allow: POST');
if ($result[0] === 503) header('Retry-After: 30');
echo $result[1];

function anore_handleCallback(array $params, string $raw, array $server): array
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return [405, 'Use POST'];
    if (empty($params['type'])) return [503, 'Gateway is not active'];
    $secret = trim((string) ($params['webhookSecret'] ?? ''));
    if ($secret === '') {
        anore_safeLog('anore', [], 'Webhook secret is not configured');
        return [503, 'Webhook is not configured'];
    }
    if ($raw === '') return [400, 'Empty body'];
    $signature = strtolower(trim((string) ($server['HTTP_ANORE_SIGNATURE'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $signature)
        || !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
        anore_safeLog('anore', [], 'Rejected: invalid webhook signature');
        return [401, 'Invalid signature'];
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || substr(ltrim($raw), 0, 1) !== '{') return [400, 'Invalid JSON object'];
    if (!isset($payload['event']) || !is_string($payload['event'])) return [400, 'Missing event'];
    if (!in_array($payload['event'], ['payment.succeeded', 'payment.expired'], true)) return [200, 'OK'];
    if (($payload['test'] ?? false) === true) return [200, 'OK'];
    if (!isset($payload['id']) || !is_string($payload['id']) || !anore_validPaymentId($payload['id'])) {
        return [400, 'Invalid payment ID'];
    }
    $paymentId = strtolower($payload['id']);
    $context = ['paymentId' => $paymentId, 'event' => $payload['event']];
    if ($payload['event'] === 'payment.expired') {
        anore_callbackSetState($paymentId, 'expired');
        return [200, 'OK'];
    }
    try {
        $minor = anore_moneyMinor($payload['amount'] ?? null);
    } catch (InvalidArgumentException $error) {
        return [400, 'Invalid payment amount'];
    }
    if ($minor <= 0) return [400, 'Invalid payment amount'];
    if (!isset($payload['currency']) || !is_string($payload['currency'])) return [400, 'Missing currency'];
    $currency = strtoupper(trim($payload['currency']));
    if (!in_array($currency, ['RUB', 'USD'], true)) return [422, 'Unsupported currency'];
    if (isset($payload['status']) && $payload['status'] !== 'paid') return [422, 'Payment is not paid'];
    $invoiceId = null;
    if (isset($payload['orderId']) && $payload['orderId'] !== '') {
        if (!is_string($payload['orderId']) && !is_int($payload['orderId'])) return [400, 'Invalid order ID'];
        $invoiceId = filter_var($payload['orderId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($invoiceId === false) return [422, 'Invalid invoice ID'];
    }
    $transaction = anore_callbackTransaction($paymentId);
    if ($transaction !== null) {
        $expectedInvoice = (int) $transaction->invoiceid;
    } else {
        $mapping = anore_callbackMapping($paymentId);
        if ($mapping === null) {
            anore_safeLog('anore', $context, 'Rejected: unknown payment; check legacy mapping');
            return [422, 'Payment mapping not found; manual reconciliation required'];
        }
        $expectedInvoice = (int) $mapping->invoice_id;
    }
    if ($expectedInvoice <= 0 || ($invoiceId !== null && $invoiceId !== $expectedInvoice)) {
        anore_safeLog('anore', $context, 'Rejected: invoice mismatch');
        return [422, 'Invoice mismatch'];
    }
    $invoiceId = $expectedInvoice;
    $context['invoiceId'] = $invoiceId;
    return anore_withInvoiceLock($invoiceId, function () use ($invoiceId, $paymentId, $minor, $currency, $context) {
        $transaction = anore_callbackTransaction($paymentId);
        if ($transaction !== null) {
            if ((int) $transaction->invoiceid !== $invoiceId || anore_moneyMinor($transaction->amountin) !== $minor) {
                anore_safeLog('anore', $context, 'Rejected: transaction mismatch');
                return [422, 'Transaction mismatch'];
            }
            anore_safeLog('anore', $context, 'Duplicate ignored');
            return [200, 'OK'];
        }
        $mapping = anore_callbackMapping($paymentId);
        if ($mapping === null || (int) $mapping->invoice_id !== $invoiceId
            || strtoupper((string) $mapping->currency) !== $currency
            || anore_moneyMinor($mapping->amount) !== $minor) {
            anore_safeLog('anore', $context, 'Rejected: payment mapping mismatch');
            return [422, 'Payment amount, currency or invoice mismatch'];
        }
        try {
            $invoice = anore_invoiceForCurrency($invoiceId, $currency);
        } catch (InvalidArgumentException $error) {
            anore_safeLog('anore', $context + ['error' => $error->getMessage()], 'Rejected: invoice mismatch');
            return [422, 'Invoice or currency mismatch'];
        }
        if (in_array($invoice->status, ['Cancelled', 'Draft'], true)) {
            anore_safeLog('anore', $context, 'Rejected: invoice cannot accept payment; manual reconciliation required');
            return [422, 'Invoice cannot accept payment; manual reconciliation required'];
        }
        $invoiceId = checkCbInvoiceID($invoiceId, 'anore');
        try {
            addInvoicePayment($invoiceId, $paymentId, $minor / 100, 0.0, 'anore');
        } catch (Throwable $error) {
            if (anore_callbackTransaction($paymentId) === null) throw $error;
            anore_safeLog('anore', $context + ['error' => $error->getMessage()], 'Payment recorded; post-payment hook failed');
        }
        if (anore_callbackTransaction($paymentId) === null) throw new RuntimeException('WHMCS did not record the invoice payment');
        try {
            anore_callbackSetState($paymentId, 'paid');
        } catch (Throwable $error) {
            anore_safeLog('anore', $context + ['error' => $error->getMessage()], 'Payment recorded; cache update failed');
        }
        anore_safeLog('anore', $context, 'Successful');
        return [200, 'OK'];
    });
}

function anore_callbackMapping(string $paymentId)
{
    foreach (['mod_anore_payment_attempts', 'mod_anore_payments'] as $table) {
        if (\WHMCS\Database\Capsule::schema()->hasTable($table)) {
            $mapping = \WHMCS\Database\Capsule::table($table)->where('payment_id', $paymentId)->first();
            if ($mapping !== null) return $mapping;
        }
    }
    return null;
}

function anore_callbackTransaction(string $paymentId)
{
    return \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('gateway', 'anore')->where('transid', $paymentId)->first();
}

function anore_callbackSetState(string $paymentId, string $state): void
{
    foreach (['mod_anore_payment_attempts', 'mod_anore_payments'] as $table) {
        if (!\WHMCS\Database\Capsule::schema()->hasTable($table)) continue;
        $query = \WHMCS\Database\Capsule::table($table)->where('payment_id', $paymentId);
        if ($state === 'expired') $query->where('status', 'new');
        $query->update(['status' => $state, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }
}
