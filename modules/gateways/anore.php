<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function anore_MetaData()
{
    return [
        'DisplayName' => 'Anore',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function anore_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Anore',
        ],
        'apiUrl' => [
            'FriendlyName' => 'API URL',
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'https://api.anore.cc',
            'Description' => 'Базовый URL API без /api/v1/payments.',
        ],
        'apiKey' => [
            'FriendlyName' => 'API-ключ',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Bearer API-ключ из личного кабинета Anore.',
        ],
        'apiSecret' => [
            'FriendlyName' => 'Секрет API-ключа',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Используется для подписи запроса X-ZPay-Signature.',
        ],
        'shopId' => [
            'FriendlyName' => 'Shop ID',
            'Type' => 'text',
            'Size' => '12',
            'Description' => 'Нужен только для API-ключа уровня аккаунта. Для ключа магазина оставьте пустым.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Секрет вебхука',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Должен совпадать с секретом вебхука магазина в Anore.',
        ],
        'methods' => [
            'FriendlyName' => 'Методы оплаты',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Необязательно: sbp,card,yoomoney,crypto,crypto-old. Пусто — методы магазина.',
        ],
    ];
}

function anore_link($params)
{
    try {
        $currency = strtoupper(trim((string) ($params['currency'] ?? 'RUB')));
        if (!in_array($currency, ['RUB', 'USD'], true)) {
            throw new RuntimeException('Anore поддерживает счета WHMCS только в RUB и USD.');
        }

        $minor = anore_moneyMinor($params['amount'] ?? 0);
        $amount = $minor / 100;
        if ($minor <= 0) {
            throw new RuntimeException('Сумма инвойса должна быть больше нуля.');
        }

        $invoiceId = (int) ($params['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            throw new RuntimeException('WHMCS не передал ID инвойса.');
        }

        if (trim((string) ($params['webhookSecret'] ?? '')) === '') {
            throw new RuntimeException('Не указан секрет вебхука магазина.');
        }

        $fingerprint = anore_requestFingerprint($params);
        anore_ensureSchema();
        return anore_withInvoiceLock($invoiceId, function () use ($params, $invoiceId, $minor, $amount, $currency, $fingerprint) {
            anore_assertPayableInvoice($invoiceId, $currency, $minor);
            $stored = anore_findReusablePayment($invoiceId, $amount, $currency, $fingerprint);
            if ($stored !== null) {
                return anore_paymentButton($stored->payment_url, $params);
            }

            $returnUrl = trim((string) ($params['returnurl'] ?? ''));
            $client = is_array($params['clientdetails'] ?? null) ? $params['clientdetails'] : [];
            $description = trim((string) ($params['description'] ?? ''));
            if ($description === '') {
                $description = 'WHMCS invoice #' . $invoiceId;
            }

            $payload = [
                'amount' => $amount,
                'currency' => strtolower($currency),
                'description' => $description,
                'orderId' => (string) $invoiceId,
                'getbackurl' => $returnUrl,
                'successurl' => $returnUrl,
                'failurl' => $returnUrl,
                'callbackUrl' => anore_callbackUrl($params),
            ];

            $email = trim((string) ($client['email'] ?? ''));
            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Некорректный email клиента.');
                }
                $payload['email'] = $email;
            }

            $shopId = trim((string) ($params['shopId'] ?? ''));
            if ($shopId !== '') {
                if (!ctype_digit($shopId) || (int) $shopId <= 0) {
                    throw new RuntimeException('Shop ID должен быть положительным числом.');
                }
                $payload['shopId'] = (int) $shopId;
            }

            $methods = anore_paymentMethods($params);
            if ($methods !== []) {
                $payload['methods'] = $methods;
            }

            $response = anore_apiRequest($params, 'POST', '/api/v1/payments', $payload);
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
                anore_logApiFailure($params, $response, 'Create payment failed');
                throw new RuntimeException(anore_apiErrorMessage($response));
            }

            $paymentId = trim((string) ($response['json']['id'] ?? ''));
            $paymentUrl = trim((string) ($response['json']['paymentUrl'] ?? ''));
            if (!anore_validPaymentId($paymentId) || !filter_var($paymentUrl, FILTER_VALIDATE_URL)
                || !preg_match('~^https?://~i', $paymentUrl)) {
                anore_logApiFailure($params, $response, 'Invalid create response');
                throw new RuntimeException('Anore вернул неполный ответ при создании платежа.');
            }

            $expiresIn = max(1, (int) ($response['json']['expiresIn'] ?? 14400));
            anore_storePayment($invoiceId, $paymentId, $paymentUrl, $amount, $currency, $expiresIn, $fingerprint);

            return anore_paymentButton($paymentUrl, $params);
        });
    } catch (Throwable $error) {
        anore_safeLog(
            (string) ($params['name'] ?? 'anore'),
            ['invoiceId' => $params['invoiceid'] ?? null, 'error' => $error->getMessage()],
            'Payment initialization failed'
        );

        return '<div class="alert alert-danger">'
            . anore_escape('Не удалось создать платёж: ' . $error->getMessage())
            . '</div>';
    }
}

function anore_apiRequest(array $params, string $method, string $path, ?array $payload = null): array
{
    $apiUrl = anore_apiBase($params);
    $apiKey = trim((string) ($params['apiKey'] ?? ''));
    if (!filter_var($apiUrl, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $apiUrl)) {
        throw new RuntimeException('Некорректный API URL.');
    }
    if ($apiKey === '') {
        throw new RuntimeException('Не указан API-ключ.');
    }

    $raw = $payload === null
        ? ''
        : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($raw === false) {
        throw new RuntimeException('Не удалось сформировать JSON запроса.');
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $apiSecret = trim((string) ($params['apiSecret'] ?? ''));
        if ($apiSecret !== '') {
            $headers[] = 'X-ZPay-Signature: ' . hash_hmac('sha256', $raw, $apiSecret);
        }
    }

    $handle = curl_init($apiUrl . $path);
    if ($handle === false) {
        throw new RuntimeException('Не удалось инициализировать cURL.');
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $raw);
    }

    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    $body = is_string($body) ? $body : '';
    $json = json_decode($body, true);

    return [
        'status' => $status,
        'body' => $body,
        'json' => is_array($json) ? $json : null,
        'error' => $error,
    ];
}

function anore_findReusablePayment(int $invoiceId, float $amount, string $currency, string $fingerprint)
{
    $row = \WHMCS\Database\Capsule::table('mod_anore_payment_attempts')
        ->where('invoice_id', $invoiceId)
        ->where('amount', anore_moneyText($amount))
        ->where('currency', $currency)
        ->where('request_fingerprint', $fingerprint)
        ->where('status', 'new')
        ->orderBy('id', 'desc')
        ->first();

    if ($row !== null && strtotime((string) $row->expires_at . ' UTC') > time() + 30) {
        return $row;
    }

    return null;
}

function anore_storePayment(
    int $invoiceId,
    string $paymentId,
    string $paymentUrl,
    float $amount,
    string $currency,
    int $expiresIn,
    string $fingerprint
): void
{
    anore_ensureSchema();
    $now = gmdate('Y-m-d H:i:s');
    $values = [
        'payment_id' => $paymentId,
        'payment_url' => $paymentUrl,
        'invoice_id' => $invoiceId,
        'amount' => anore_moneyText($amount),
        'currency' => strtoupper($currency),
        'request_fingerprint' => $fingerprint,
        'status' => 'new',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + $expiresIn),
        'created_at' => $now,
        'updated_at' => $now,
    ];

    \WHMCS\Database\Capsule::table('mod_anore_payment_attempts')->insert($values);
}

function anore_ensureSchema(): void
{
    static $ready = false;
    if ($ready) return;
    $schema = \WHMCS\Database\Capsule::schema();
    if (!$schema->hasTable('mod_anore_payment_attempts')) {
        anore_withLock('anore:schema:v2', function () use ($schema) {
            if ($schema->hasTable('mod_anore_payment_attempts')) return;
            $schema->create('mod_anore_payment_attempts', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('invoice_id')->index();
                $table->string('payment_id', 64)->unique();
                $table->text('payment_url');
                $table->decimal('amount', 18, 2);
                $table->string('currency', 8);
                $table->string('request_fingerprint', 64);
                $table->string('status', 24)->default('new');
                $table->dateTime('expires_at');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        });
    }
    $ready = true;
}

function anore_paymentButton(string $paymentUrl, array $params): string
{
    $label = trim((string) ($params['langpaynow'] ?? 'Оплатить'));
    if ($label === '') {
        $label = 'Оплатить';
    }

    return '<a class="btn btn-primary" href="' . anore_escape($paymentUrl) . '">'
        . anore_escape($label) . '</a>';
}

function anore_apiErrorMessage(array $response): string
{
    if (($response['error'] ?? '') !== '') {
        return 'API недоступен: ' . $response['error'];
    }
    $json = $response['json'] ?? null;
    if (is_array($json)) {
        foreach (['message', 'error', 'statusMessage'] as $field) {
            if (isset($json[$field]) && is_scalar($json[$field]) && trim((string) $json[$field]) !== '') {
                return trim((string) $json[$field]);
            }
        }
    }

    $status = (int) ($response['status'] ?? 0);
    return $status > 0 ? 'API вернул HTTP ' . $status . '.' : 'API не вернул ответ.';
}

function anore_logApiFailure(array $params, array $response, string $status): void
{
    anore_safeLog(
        (string) ($params['name'] ?? 'anore'),
        [
            'httpStatus' => $response['status'] ?? 0,
            'response' => $response['json'] ?? substr((string) ($response['body'] ?? ''), 0, 2000),
            'transportError' => $response['error'] ?? '',
        ],
        $status
    );
}

function anore_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function anore_safeLog(string $gateway, array $data, string $status): void
{
    try {
        logTransaction($gateway, $data, $status);
    } catch (Throwable $error) {
        error_log('[anore] Gateway log unavailable (' . get_class($error) . ')');
    }
}

function anore_apiBase(array $params): string
{
    $url = rtrim(trim((string) ($params['apiUrl'] ?? '')), '/');
    if ($url === '') $url = 'https://api.anore.cc';
    return preg_replace('~/(?:api/)?v1$~i', '', $url);
}

function anore_callbackUrl(array $params): string
{
    $url = rtrim(trim((string) ($params['systemurl'] ?? '')), '/') . '/modules/gateways/callback/anore.php';
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) {
        throw new RuntimeException('Не задан корректный System URL WHMCS.');
    }
    return $url;
}

function anore_requestFingerprint(array $params): string
{
    return hash('sha256', json_encode([
        anore_apiBase($params), trim((string) ($params['apiKey'] ?? '')),
        trim((string) ($params['shopId'] ?? '')), anore_paymentMethods($params),
        anore_callbackUrl($params), trim((string) ($params['clientdetails']['email'] ?? '')),
    ], JSON_THROW_ON_ERROR));
}

function anore_paymentMethods(array $params): array
{
    $methods = [];
    foreach (explode(',', (string) ($params['methods'] ?? '')) as $method) {
        $method = strtolower(trim($method));
        if ($method === '') continue;
        $method = ['dvnet' => 'crypto', 'xrocket' => 'crypto-old'][$method] ?? $method;
        if (!in_array($method, ['sbp', 'card', 'yoomoney', 'crypto', 'crypto-old'], true)) {
            throw new RuntimeException('Указан неизвестный метод оплаты Anore.');
        }
        $methods[] = $method;
    }
    return array_values(array_unique($methods));
}

function anore_validPaymentId(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $id);
}

function anore_moneyMinor($value): int
{
    if (is_float($value)) {
        if (!is_finite($value)) throw new InvalidArgumentException('Invalid monetary amount');
        $value = number_format($value, 2, '.', '');
    }
    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Invalid monetary amount');
    $value = trim((string) $value);
    if (!preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,2}))?$/', $value, $parts)) {
        throw new InvalidArgumentException('Invalid monetary amount');
    }
    return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
}

function anore_moneyText($value): string
{
    $minor = anore_moneyMinor($value);
    return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
}

function anore_withInvoiceLock(int $invoiceId, callable $callback)
{
    return anore_withLock('anore:invoice:' . $invoiceId, $callback);
}

function anore_invoiceForCurrency(int $invoiceId, string $currency)
{
    $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if ($invoice === null) throw new InvalidArgumentException('Invoice not found');
    $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    if ($client === null) throw new InvalidArgumentException('Invoice client not found');
    $storedCurrency = \WHMCS\Database\Capsule::table('tblcurrencies')->where('id', $client->currency)->first();
    if ($storedCurrency === null || strtoupper((string) $storedCurrency->code) !== $currency) {
        throw new InvalidArgumentException('Валюта обработки должна совпадать с валютой инвойса. Отключите Convert To For Processing для Anore.');
    }
    return $invoice;
}

function anore_assertPayableInvoice(int $invoiceId, string $currency, int $amountMinor): void
{
    anore_invoiceForCurrency($invoiceId, $currency);
    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    if (!is_array($invoice) || ($invoice['result'] ?? '') !== 'success'
        || (int) ($invoice['invoiceid'] ?? 0) !== $invoiceId) {
        throw new RuntimeException('Не удалось проверить текущий остаток инвойса WHMCS. Повторите попытку.');
    }
    $balance = anore_moneyMinor($invoice['balance'] ?? null);
    if (in_array($invoice['status'] ?? '', ['Paid', 'Cancelled', 'Draft'], true) || $balance <= 0) {
        throw new RuntimeException('Этот инвойс уже оплачен или закрыт для оплаты. Обновите страницу.');
    }
    if ($balance !== $amountMinor) {
        throw new RuntimeException('Сумма к оплате изменилась. Обновите страницу инвойса.');
    }
}

function anore_withLock(string $name, callable $callback)
{
    $connection = \WHMCS\Database\Capsule::connection();
    $lock = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name]);
    if ($lock === null || (int) $lock->acquired !== 1) throw new RuntimeException('Payment is being processed; retry later');
    try {
        return $callback();
    } finally {
        try {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        } catch (Throwable $ignored) {
        }
    }
}
