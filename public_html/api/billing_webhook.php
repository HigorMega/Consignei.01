<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

function billing_webhook_get_client_ip(): string
{
    $candidates = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];

    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $value = (string) $_SERVER[$key];
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $value);
                $value = trim($parts[0] ?? $value);
            }
            return $value;
        }
    }

    return 'unknown';
}

function billing_webhook_get_main_headers(): array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $wanted = [
        'Host',
        'User-Agent',
        'Content-Type',
        'Content-Length',
        'Accept',
        'X-Forwarded-For',
        'X-Real-IP',
        'Authorization',
        'X-Signature',
        'X-Webhook-Id',
    ];

    $normalized = [];
    foreach ($headers as $name => $value) {
        $normalized[strtolower($name)] = $value;
    }

    $result = [];
    foreach ($wanted as $name) {
        $key = strtolower($name);
        if (array_key_exists($key, $normalized)) {
            $result[$name] = $normalized[$key];
        }
    }

    return $result;
}

function billing_webhook_write_log(string $message): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/billing_webhook.log';
    file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$rawBody = file_get_contents('php://input') ?: '';
$mainHeaders = billing_webhook_get_main_headers();
$ip = billing_webhook_get_client_ip();

$logEntry = [
    'time' => date('c'),
    'ip' => $ip,
    'headers' => $mainHeaders,
    'body' => $rawBody,
];

billing_webhook_write_log(json_encode($logEntry, JSON_UNESCAPED_UNICODE));

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
