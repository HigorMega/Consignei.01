<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

require_once __DIR__ . '/../db/conexao.php';
require_once __DIR__ . '/subscription_helpers.php';

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

function billing_webhook_fetch_preapproval(string $accessToken, string $subscriptionId): array
{
    $endpoint = 'https://api.mercadopago.com/preapproval/' . urlencode($subscriptionId);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $response !== false && $httpCode < 400,
        'http_status' => $httpCode,
        'response_body' => $response,
        'curl_error' => $curlError,
        'data' => $response ? json_decode($response, true) : null,
    ];
}

function billing_webhook_extract_subscription_id(array $payload): ?string
{
    if (!empty($payload['data']['id'])) {
        return (string) $payload['data']['id'];
    }
    if (!empty($payload['id'])) {
        return (string) $payload['id'];
    }
    return null;
}

function billing_webhook_compute_paid_until(array $preapproval): ?string
{
    $nextPayment = $preapproval['next_payment_date'] ?? null;
    if ($nextPayment) {
        $date = sh_parse_datetime((string) $nextPayment);
        return $date ? $date->format('Y-m-d H:i:s') : null;
    }

    $autoRecurring = $preapproval['auto_recurring'] ?? null;
    if (!is_array($autoRecurring)) {
        return null;
    }
    $frequency = (int) ($autoRecurring['frequency'] ?? 0);
    $frequencyType = strtolower((string) ($autoRecurring['frequency_type'] ?? ''));
    if ($frequency <= 0 || $frequencyType === '') {
        return null;
    }

    $baseDate = new DateTimeImmutable('now');
    switch ($frequencyType) {
        case 'months':
            $computed = $baseDate->modify(sprintf('+%d months', $frequency));
            break;
        case 'days':
            $computed = $baseDate->modify(sprintf('+%d days', $frequency));
            break;
        default:
            $computed = null;
            break;
    }

    return $computed ? $computed->format('Y-m-d H:i:s') : null;
}

$rawBody = file_get_contents('php://input') ?: '';
$mainHeaders = billing_webhook_get_main_headers();
$ip = billing_webhook_get_client_ip();

$payload = json_decode($rawBody, true);
$subscriptionId = is_array($payload) ? billing_webhook_extract_subscription_id($payload) : null;
if (!$subscriptionId && !empty($_GET['id'])) {
    $subscriptionId = (string) $_GET['id'];
}

$logEntry = [
    'time' => date('c'),
    'ip' => $ip,
    'headers' => $mainHeaders,
    'body' => $rawBody,
    'subscription_id' => $subscriptionId,
];

billing_webhook_write_log(json_encode($logEntry, JSON_UNESCAPED_UNICODE));

if ($subscriptionId) {
    $accessToken = (string) env('MP_ACCESS_TOKEN');
    if ($accessToken) {
        $fetch = billing_webhook_fetch_preapproval($accessToken, $subscriptionId);
        billing_webhook_write_log(json_encode([
            'time' => date('c'),
            'context' => 'preapproval_fetch',
            'subscription_id' => $subscriptionId,
            'http_status' => $fetch['http_status'],
            'curl_error' => $fetch['curl_error'],
            'response_body' => $fetch['response_body'],
        ], JSON_UNESCAPED_UNICODE));

        if ($fetch['ok'] && is_array($fetch['data'])) {
            $preapproval = $fetch['data'];
            $externalReference = $preapproval['external_reference'] ?? null;
            $status = $preapproval['status'] ?? null;
            $paidUntil = billing_webhook_compute_paid_until($preapproval);

            $updates = [];
            $values = [];

            if (sh_column_exists($pdo, 'lojas', 'subscription_status')) {
                $updates[] = 'subscription_status = ?';
                $values[] = $status;
            }

            if (sh_column_exists($pdo, 'lojas', 'paid_until')) {
                $updates[] = 'paid_until = ?';
                $values[] = $paidUntil;
            }

            if (sh_column_exists($pdo, 'lojas', 'subscription_id')) {
                $updates[] = 'subscription_id = ?';
                $values[] = $subscriptionId;
            }

            if ($updates) {
                if ($externalReference !== null && is_numeric($externalReference)) {
                    $values[] = (int) $externalReference;
                    $stmt = $pdo->prepare('UPDATE lojas SET ' . implode(', ', $updates) . ' WHERE id = ?');
                    $stmt->execute($values);
                } else {
                    $values[] = $subscriptionId;
                    $stmt = $pdo->prepare('UPDATE lojas SET ' . implode(', ', $updates) . ' WHERE subscription_id = ?');
                    $stmt->execute($values);
                }
            }
        }
    } else {
        billing_webhook_write_log(json_encode([
            'time' => date('c'),
            'context' => 'missing_access_token',
            'subscription_id' => $subscriptionId,
        ], JSON_UNESCAPED_UNICODE));
    }
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
