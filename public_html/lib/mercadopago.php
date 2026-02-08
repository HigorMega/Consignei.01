<?php
// lib/mercadopago.php

declare(strict_types=1);

function mp_log(string $message, array $context = []): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $timestamp = date('c');
    $entry = $timestamp . ' | ' . $message;
    if ($context) {
        $entry .= ' | context=' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    file_put_contents($logDir . '/mercadopago.log', $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function mp_iso_utc(DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
}

function mp_calc_start_date(int $trialDays): string
{
    $trialDays = max(0, $trialDays);
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    if ($trialDays > 0) {
        $start = $nowUtc->modify("+{$trialDays} days");
    } else {
        $start = $nowUtc->modify('+2 minutes');
    }

    if ($start <= $nowUtc) {
        $start = $nowUtc->modify('+2 minutes');
    }

    $startDate = mp_iso_utc($start);
    mp_log('mp_preapproval_start_date_calculated', [
        'trial_days' => $trialDays,
        'start_date' => $startDate,
        'now_utc' => mp_iso_utc($nowUtc),
    ]);

    return $startDate;
}

function mp_get_mode(?string $token = null): string
{
    $mode = strtolower((string) env('MP_MODE', ''));
    if (in_array($mode, ['sandbox', 'test', 'testing'], true)) {
        return 'sandbox';
    }
    if (in_array($mode, ['production', 'prod'], true)) {
        return 'production';
    }

    if ($token !== null && $token !== '') {
        return str_starts_with($token, 'TEST-') ? 'sandbox' : 'production';
    }

    $token = (string) env('MP_ACCESS_TOKEN');
    if ($token !== '') {
        return str_starts_with($token, 'TEST-') ? 'sandbox' : 'production';
    }

    return 'production';
}

function mp_get_access_token(): ?string
{
    $mode = mp_get_mode();
    if ($mode === 'sandbox') {
        $token = env('MP_ACCESS_TOKEN_TEST');
        if ($token) {
            return $token;
        }
    }

    if ($mode === 'production') {
        $token = env('MP_ACCESS_TOKEN_PROD');
        if ($token) {
            return $token;
        }
    }

    return env('MP_ACCESS_TOKEN');
}

function mp_is_sandbox(): bool
{
    $token = mp_get_access_token();
    if (!$token) {
        return strtolower((string) env('MP_MODE', '')) === 'sandbox';
    }
    return str_starts_with($token, 'TEST-') || strtolower((string) env('MP_MODE', '')) === 'sandbox';
}

function mp_normalize_headers(array $headers): array
{
    $normalized = [];
    foreach ($headers as $name => $value) {
        $normalized[strtolower($name)] = $value;
    }
    return $normalized;
}

function mp_get_request_headers(): array
{
    if (function_exists('getallheaders')) {
        return getallheaders();
    }

    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_')) {
            $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$key] = $value;
        }
    }
    return $headers;
}

function mp_validate_webhook_signature(string $rawBody, array $headers): bool
{
    $secret = env('MP_WEBHOOK_SECRET');
    if (!$secret) {
        return true;
    }

    $normalized = mp_normalize_headers($headers);
    $signatureHeader = $normalized['x-signature'] ?? '';
    if ($signatureHeader === '') {
        return false;
    }

    $pairs = [];
    foreach (explode(',', $signatureHeader) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '' || !str_contains($chunk, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $chunk, 2));
        $pairs[$key] = $value;
    }

    $timestamp = $pairs['ts'] ?? null;
    $signature = $pairs['v1'] ?? null;
    if (!$timestamp || !$signature) {
        return false;
    }

    $payload = $timestamp . '.' . $rawBody;
    $computed = hash_hmac('sha256', $payload, $secret);

    return hash_equals($computed, $signature);
}

function mp_request(
    string $method,
    string $path,
    ?array $body = null,
    array $query = [],
    array $options = []
): array {
    $accessToken = mp_get_access_token();
    if (!$accessToken) {
        return [
            'success' => false,
            'status' => 0,
            'error' => 'missing_access_token',
            'request_id' => null,
            'data' => null,
            'raw' => null,
        ];
    }

    $baseUrl = rtrim((string) env('MP_API_BASE', 'https://api.mercadopago.com'), '/');
    $url = $baseUrl . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $timeout = (int) ($options['timeout'] ?? 20);
    $connectTimeout = (int) ($options['connect_timeout'] ?? 10);
    $retries = (int) ($options['retries'] ?? 2);

    $requestId = null;
    $responseHeaders = [];
    $attempt = 0;
    $response = false;
    $httpCode = 0;
    $curlError = '';

    while ($attempt <= $retries) {
        $attempt++;
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => array_merge([
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ], $options['headers'] ?? []),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, $headerLine) use (&$responseHeaders, &$requestId) {
                $len = strlen($headerLine);
                $headerLine = trim($headerLine);
                if ($headerLine === '' || !str_contains($headerLine, ':')) {
                    return $len;
                }
                [$name, $value] = array_map('trim', explode(':', $headerLine, 2));
                $responseHeaders[strtolower($name)] = $value;
                if (strtolower($name) === 'x-request-id') {
                    $requestId = $value;
                }
                return $len;
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false && $httpCode < 500) {
            break;
        }

        if ($attempt <= $retries) {
            usleep(200000 * $attempt);
        }
    }

    if ($response === false) {
        return [
            'success' => false,
            'status' => $httpCode,
            'error' => $curlError,
            'request_id' => $requestId,
            'data' => null,
            'raw' => null,
        ];
    }

    $data = json_decode($response, true);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'error' => $httpCode >= 200 && $httpCode < 300 ? null : ($data['message'] ?? null),
        'request_id' => $requestId,
        'data' => $data,
        'raw' => $response,
        'headers' => $responseHeaders,
    ];
}

function mp_get_payment(string $paymentId): array
{
    return mp_request('GET', '/v1/payments/' . urlencode($paymentId));
}

function mp_get_preapproval(string $preapprovalId): array
{
    return mp_request('GET', '/preapproval/' . urlencode($preapprovalId));
}

function mp_resolve_payer_email(?string $lojaEmail): array
{
    $mode = mp_get_mode();
    $fallback = $lojaEmail ?: null;

    if ($mode === 'sandbox') {
        $testEmail = env('MP_TEST_PAYER_EMAIL');
        if ($testEmail) {
            return ['email' => $testEmail, 'source' => 'test'];
        }
        return ['email' => $fallback, 'source' => 'store'];
    }

    return ['email' => $fallback, 'source' => 'store'];
}

function mp_map_payment_status(?string $status): string
{
    if (!$status) {
        return 'pending';
    }
    $status = strtolower($status);
    if (in_array($status, ['approved', 'authorized'], true)) {
        return 'paid';
    }
    if (in_array($status, ['rejected', 'cancelled', 'refunded', 'charged_back'], true)) {
        return 'failed';
    }
    return 'pending';
}

function mp_map_preapproval_status(?string $status): string
{
    if (!$status) {
        return 'pending';
    }
    $status = strtolower($status);
    if (in_array($status, ['authorized', 'active', 'approved'], true)) {
        return 'paid';
    }
    if (in_array($status, ['paused', 'cancelled', 'cancelled_by_user', 'expired', 'rejected'], true)) {
        return 'failed';
    }
    return 'pending';
}

function mp_store_webhook_event(PDO $pdo, array $payload, array $headers, string $resourceId, string $eventType, ?string $action): array
{
    $eventId = null;
    if (!empty($payload['id'])) {
        $eventId = (string) $payload['id'];
    }

    if (!$eventId) {
        $eventId = sha1(json_encode($payload));
    }

    $eventType = $eventType ?: 'unknown';

    if (!sh_column_exists($pdo, 'webhook_events', 'event_id')) {
        return ['stored' => false, 'event_id' => $eventId, 'duplicate' => false];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO webhook_events (event_id, event_type, action, resource_id, payload, headers) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $eventId,
            $eventType,
            $action,
            $resourceId,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            json_encode($headers, JSON_UNESCAPED_UNICODE),
        ]);
        return ['stored' => true, 'event_id' => $eventId, 'duplicate' => false];
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return ['stored' => false, 'event_id' => $eventId, 'duplicate' => true];
        }
        mp_log('webhook_store_failed', ['error' => $e->getMessage()]);
        return ['stored' => false, 'event_id' => $eventId, 'duplicate' => false];
    }
}
?>
