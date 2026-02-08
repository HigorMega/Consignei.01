<?php
// api/pagamentos_webhook.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";

function mp_log_payment(string $message): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/pagamentos_webhook.log', $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function mp_fetch_payment(string $paymentId, string $accessToken): array
{
    $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => $curlError, 'status' => $httpCode];
    }

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'data' => json_decode($response, true),
        'raw' => $response,
    ];
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true) ?: [];
mp_log_payment(date('c') . ' | payload=' . $rawBody);

$accessToken = env('MP_ACCESS_TOKEN');
if (!$accessToken) {
    mp_log_payment(date('c') . ' | error=missing_access_token');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$paymentId = $payload['data']['id'] ?? $payload['data_id'] ?? $payload['id'] ?? $_GET['id'] ?? $_POST['id'] ?? null;
if (!$paymentId && !empty($_GET['data.id'])) {
    $paymentId = $_GET['data.id'];
}
if (!$paymentId) {
    mp_log_payment(date('c') . ' | error=missing_payment_id');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$paymentResponse = mp_fetch_payment((string) $paymentId, $accessToken);
if (!$paymentResponse['success']) {
    mp_log_payment(date('c') . ' | paymentId=' . $paymentId . ' | error=payment_fetch_failed | status=' . $paymentResponse['status']);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$payment = $paymentResponse['data'] ?? [];
$externalReference = $payment['external_reference'] ?? null;
$paymentStatus = $payment['status'] ?? null;
$transactionAmount = isset($payment['transaction_amount']) ? (float) $payment['transaction_amount'] : null;
$currencyId = $payment['currency_id'] ?? null;
$paidAt = sh_parse_datetime($payment['date_approved'] ?? $payment['date_created'] ?? null);

$invoiceStatus = 'pending';
if (in_array($paymentStatus, ['approved', 'authorized'], true)) {
    $invoiceStatus = 'paid';
} elseif (in_array($paymentStatus, ['rejected', 'cancelled', 'refunded', 'charged_back'], true)) {
    $invoiceStatus = 'failed';
}

$invoice = null;
$invoiceId = null;
$lojaId = null;

if ($externalReference) {
    if (preg_match('/^(inv|subinv):(\d+)$/', (string) $externalReference, $matches)) {
        $invoiceId = (int) $matches[2];
        $stmt = $pdo->prepare("SELECT id, loja_id FROM invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif (sh_column_exists($pdo, 'invoices', 'external_reference')) {
        $stmt = $pdo->prepare("SELECT id, loja_id FROM invoices WHERE external_reference = ? LIMIT 1");
        $stmt->execute([(string) $externalReference]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if ($invoice) {
    $invoiceId = (int) $invoice['id'];
    $lojaId = (int) $invoice['loja_id'];
} else {
    mp_log_payment(date('c') . ' | paymentId=' . $paymentId . ' | error=invoice_not_found | external_reference=' . ($externalReference ?? 'null'));
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$updateColumns = ['mp_payment_id = ?', 'status = ?'];
$updateValues = [$paymentId, $invoiceStatus];

if ($transactionAmount !== null) {
    $updateColumns[] = 'amount = ?';
    $updateValues[] = $transactionAmount;
}
if ($currencyId) {
    $updateColumns[] = 'currency = ?';
    $updateValues[] = $currencyId;
}
if (sh_column_exists($pdo, 'invoices', 'external_reference') && $externalReference) {
    $updateColumns[] = 'external_reference = ?';
    $updateValues[] = (string) $externalReference;
}

$updateColumns[] = 'paid_at = ?';
if ($invoiceStatus === 'paid' && $paidAt) {
    $updateValues[] = $paidAt->format('Y-m-d H:i:s');
} else {
    $updateValues[] = null;
}

$updateValues[] = $invoiceId;
if ($updateColumns) {
    $stmtUpdate = $pdo->prepare(
        "UPDATE invoices SET " . implode(', ', $updateColumns) . " WHERE id = ?"
    );
    $stmtUpdate->execute($updateValues);
}

if ($invoiceStatus === 'paid' && $lojaId && sh_column_exists($pdo, 'lojas', 'paid_until')) {
    $paidUntil = (new DateTimeImmutable('now'))->modify('+1 month')->format('Y-m-d H:i:s');
    $stmtUpdateLoja = $pdo->prepare("UPDATE lojas SET paid_until = ? WHERE id = ?");
    $stmtUpdateLoja->execute([$paidUntil, $lojaId]);
}

mp_log_payment(date('c') . ' | paymentId=' . $paymentId . ' | status=' . ($paymentStatus ?? 'null') . ' | invoiceId=' . $invoiceId);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
?>
