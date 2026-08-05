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
            'Description' => 'Необязательно. Например: sbp,yoomoney,crypto. Пусто — методы магазина.',
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

        $amount = round((float) ($params['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Сумма инвойса должна быть больше нуля.');
        }

        $invoiceId = (int) ($params['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            throw new RuntimeException('WHMCS не передал ID инвойса.');
        }

        $stored = anore_findReusablePayment($invoiceId);
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
        ];

        $shopId = trim((string) ($params['shopId'] ?? ''));
        if ($shopId !== '') {
            if (!ctype_digit($shopId) || (int) $shopId <= 0) {
                throw new RuntimeException('Shop ID должен быть положительным числом.');
            }
            $payload['shopId'] = (int) $shopId;
        }

        $methods = array_values(array_filter(array_map('trim', explode(',', (string) ($params['methods'] ?? '')))));
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
        if ($paymentId === '' || !filter_var($paymentUrl, FILTER_VALIDATE_URL)) {
            anore_logApiFailure($params, $response, 'Invalid create response');
            throw new RuntimeException('Anore вернул неполный ответ при создании платежа.');
        }

        $expiresIn = max(60, (int) ($response['json']['expiresIn'] ?? 14400));
        anore_storePayment($invoiceId, $paymentId, $paymentUrl, $amount, $currency, $expiresIn);

        return anore_paymentButton($paymentUrl, $params);
    } catch (Throwable $error) {
        logTransaction(
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
    $apiUrl = rtrim(trim((string) ($params['apiUrl'] ?? '')), '/');
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

function anore_findReusablePayment(int $invoiceId)
{
    try {
        anore_ensureSchema();
        $row = \WHMCS\Database\Capsule::table('mod_anore_payments')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'new')
            ->orderBy('id', 'desc')
            ->first();

        if ($row !== null && strtotime((string) $row->expires_at) > time() + 30) {
            return $row;
        }
    } catch (Throwable $error) {
        logTransaction('anore', ['invoiceId' => $invoiceId, 'error' => $error->getMessage()], 'Payment cache read failed');
    }

    return null;
}

function anore_storePayment(
    int $invoiceId,
    string $paymentId,
    string $paymentUrl,
    float $amount,
    string $currency,
    int $expiresIn
): void
{
    anore_ensureSchema();
    $now = gmdate('Y-m-d H:i:s');
    $values = [
        'payment_id' => $paymentId,
        'payment_url' => $paymentUrl,
        'amount' => $amount,
        'currency' => strtoupper($currency),
        'status' => 'new',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + $expiresIn - 30),
        'updated_at' => $now,
    ];

    $exists = \WHMCS\Database\Capsule::table('mod_anore_payments')
        ->where('invoice_id', $invoiceId)
        ->exists();
    if ($exists) {
        \WHMCS\Database\Capsule::table('mod_anore_payments')
            ->where('invoice_id', $invoiceId)
            ->update($values);
    } else {
        $values['invoice_id'] = $invoiceId;
        $values['created_at'] = $now;
        \WHMCS\Database\Capsule::table('mod_anore_payments')->insert($values);
    }
}

function anore_ensureSchema(): void
{
    $schema = \WHMCS\Database\Capsule::schema();
    if ($schema->hasTable('mod_anore_payments')) {
        return;
    }

    $schema->create('mod_anore_payments', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('invoice_id')->unique();
        $table->string('payment_id', 64)->unique();
        $table->text('payment_url');
        $table->decimal('amount', 18, 2);
        $table->string('currency', 8);
        $table->string('status', 24)->default('new');
        $table->dateTime('expires_at');
        $table->dateTime('created_at');
        $table->dateTime('updated_at');
    });
}

function anore_paymentButton(string $paymentUrl, array $params): string
{
    $label = trim((string) ($params['langpaynow'] ?? 'Оплатить'));
    if ($label === '') {
        $label = 'Оплатить';
    }

    return '<form method="get" action="' . anore_escape($paymentUrl) . '">'
        . '<button type="submit" class="btn btn-primary">' . anore_escape($label) . '</button>'
        . '</form>';
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
    logTransaction(
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
