<?php
// api/pagamentos_webhook.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

$rawBody = file_get_contents('php://input') ?: '';
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logEntry = sprintf(
    "%s - %s%s",
    date('Y-m-d H:i:s'),
    $rawBody,
    PHP_EOL
);

file_put_contents($logDir . '/pagamentos_webhook.log', $logEntry, FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
?>
