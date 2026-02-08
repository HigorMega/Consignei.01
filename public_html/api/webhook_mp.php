<?php
// api/webhook_mp.php
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";

function mp_request(string $method, string $url, string $accessToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
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

function mp_log(string $message): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/mp_webhook.log';
    file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$payloadRaw = file_get_contents('php://input') ?: '';
$payload = json_decode($payloadRaw, true) ?: [];
mp_log(date('c') . " | payload=" . $payloadRaw);

$accessToken = env('MP_ACCESS_TOKEN');
if (!$accessToken) {
    mp_log(date('c') . " | error=missing_access_token");
    echo json_encode(['success' => true]);
    exit;
}

$preapprovalId = $payload['data']['id'] ?? $_GET['id'] ?? $_POST['id'] ?? null;
if (!$preapprovalId) {
    mp_log(date('c') . " | payload=" . $payloadRaw . " | error=missing_preapproval_id");
    echo json_encode(['success' => true]);
    exit;
}

$preapprovalResponse = mp_request('GET', "https://api.mercadopago.com/preapproval/{$preapprovalId}", $accessToken);
if (!$preapprovalResponse['success']) {
    mp_log(date('c') . " | subId={$preapprovalId} | error=preapproval_fetch_failed | payload=" . $payloadRaw);
    echo json_encode(['success' => true]);
    exit;
}

$preapproval = $preapprovalResponse['data'] ?? [];
$status = $preapproval['status'] ?? 'unknown';
$externalReference = $preapproval['external_reference'] ?? null;

$lojaId = null;
$invoiceId = null;
$invoice = null;
$hasExternalReference = sh_column_exists($pdo, 'invoices', 'external_reference');

if ($externalReference) {
    if (preg_match('/^(inv|subinv):(\d+)$/', (string) $externalReference, $matches)) {
        $invoiceId = (int) $matches[2];
        $stmt = $pdo->prepare("SELECT id, loja_id FROM invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif ($hasExternalReference) {
        $stmt = $pdo->prepare("SELECT id, loja_id FROM invoices WHERE external_reference = ? LIMIT 1");
        $stmt->execute([(string) $externalReference]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if ($invoice) {
    $invoiceId = (int) $invoice['id'];
    $lojaId = (int) $invoice['loja_id'];
}

if (!$lojaId) {
    if ($externalReference && ctype_digit((string) $externalReference)) {
        $lojaId = (int) $externalReference;
    } elseif (sh_column_exists($pdo, 'lojas', 'assinatura_id')) {
        $stmt = $pdo->prepare("SELECT id FROM lojas WHERE assinatura_id = ? LIMIT 1");
        $stmt->execute([$preapprovalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lojaId = (int) $row['id'];
        }
    }
}

if (!$lojaId) {
    mp_log(date('c') . " | subId={$preapprovalId} | status={$status} | lojaId=not_found | payload=" . $payloadRaw);
    echo json_encode(['success' => true]);
    exit;
}

$updates = [];
$values = [];

if (sh_column_exists($pdo, 'lojas', 'assinatura_status')) {
    $updates[] = 'assinatura_status = ?';
    $values[] = $status;
}
if (sh_column_exists($pdo, 'lojas', 'assinatura_gateway')) {
    $updates[] = 'assinatura_gateway = ?';
    $values[] = 'mercadopago';
}
if (sh_column_exists($pdo, 'lojas', 'assinatura_id')) {
    $updates[] = 'assinatura_id = ?';
    $values[] = $preapprovalId;
}

$activeStatuses = ['authorized', 'active', 'approved'];
$inactiveStatuses = ['paused', 'cancelled', 'cancelled_by_user', 'expired', 'rejected'];

if (in_array($status, $activeStatuses, true) && sh_column_exists($pdo, 'lojas', 'paid_until')) {
    $nextPaymentDate = $preapproval['next_payment_date'] ?? null;
    $startDate = $preapproval['auto_recurring']['start_date'] ?? null;

    $paidUntil = null;
    if ($nextPaymentDate) {
        $paidUntil = sh_parse_datetime($nextPaymentDate);
    } elseif ($startDate) {
        $startAt = sh_parse_datetime($startDate);
        if ($startAt) {
            $paidUntil = $startAt->modify('+1 month');
        }
    }

    if ($paidUntil) {
        $updates[] = 'paid_until = ?';
        $values[] = $paidUntil->format('Y-m-d H:i:s');
    }
} elseif (in_array($status, $inactiveStatuses, true)) {
    // Não estende paid_until para status inativos
}

if ($updates) {
    $values[] = $lojaId;
    $stmtUpdate = $pdo->prepare("UPDATE lojas SET " . implode(', ', $updates) . " WHERE id = ?");
    $stmtUpdate->execute($values);
}

$mpPaymentId = $preapproval['last_payment_id'] ?? $preapproval['payment_id'] ?? null;
if (!$mpPaymentId && !empty($preapproval['last_payment']['id'])) {
    $mpPaymentId = $preapproval['last_payment']['id'];
}

$amount = $preapproval['auto_recurring']['transaction_amount'] ?? (float) env('SUBSCRIPTION_PRICE', '21.90');
$currency = $preapproval['auto_recurring']['currency_id'] ?? 'BRL';

$periodStart = sh_parse_datetime($preapproval['last_payment_date'] ?? null);
$periodEnd = sh_parse_datetime($preapproval['next_payment_date'] ?? null);

$invoiceStatus = 'pending';
$paidAt = null;

if (in_array($status, $inactiveStatuses, true)) {
    $invoiceStatus = 'failed';
}

if ($mpPaymentId) {
    $paymentResponse = mp_request('GET', "https://api.mercadopago.com/v1/payments/{$mpPaymentId}", $accessToken);
    if ($paymentResponse['success']) {
        $payment = $paymentResponse['data'] ?? [];
        $paymentStatus = $payment['status'] ?? null;
        if (in_array($paymentStatus, ['approved', 'authorized'], true)) {
            $invoiceStatus = 'paid';
            $paidAt = sh_parse_datetime($payment['date_approved'] ?? $payment['date_created'] ?? null);
        } elseif (in_array($paymentStatus, ['rejected', 'cancelled', 'refunded', 'charged_back'], true)) {
            $invoiceStatus = 'failed';
        }
        if (!$periodStart) {
            $periodStart = sh_parse_datetime($payment['date_created'] ?? null);
        }
    }
}

$invoiceData = [
    'loja_id' => $lojaId,
    'assinatura_id' => $preapprovalId,
    'gateway' => 'mercadopago',
    'mp_payment_id' => $mpPaymentId,
    'status' => $invoiceStatus,
    'amount' => $amount,
    'currency' => $currency,
    'period_start' => $periodStart ? $periodStart->format('Y-m-d H:i:s') : null,
    'period_end' => $periodEnd ? $periodEnd->format('Y-m-d H:i:s') : null,
    'paid_at' => $paidAt ? $paidAt->format('Y-m-d H:i:s') : null,
    'external_reference' => $externalReference,
];

$existing = null;
if ($invoiceId) {
    $existing = ['id' => $invoiceId];
} elseif ($mpPaymentId) {
    $stmtInvoice = $pdo->prepare("SELECT id FROM invoices WHERE mp_payment_id = ? LIMIT 1");
    $stmtInvoice->execute([$mpPaymentId]);
    $existing = $stmtInvoice->fetch(PDO::FETCH_ASSOC);
} else {
    $stmtInvoice = $pdo->prepare("SELECT id, status FROM invoices WHERE assinatura_id = ? AND mp_payment_id IS NULL ORDER BY id DESC LIMIT 1");
    $stmtInvoice->execute([$preapprovalId]);
    $existing = $stmtInvoice->fetch(PDO::FETCH_ASSOC);
    if ($existing && $existing['status'] === $invoiceStatus) {
        $existing = null;
    }
}

if ($existing) {
    $updateColumns = [
        'status = ?',
        'amount = ?',
        'currency = ?',
        'period_start = ?',
        'period_end = ?',
        'paid_at = ?',
    ];
    $updateValues = [
        $invoiceData['status'],
        $invoiceData['amount'],
        $invoiceData['currency'],
        $invoiceData['period_start'],
        $invoiceData['period_end'],
        $invoiceData['paid_at'],
    ];
    if ($mpPaymentId) {
        $updateColumns[] = 'mp_payment_id = ?';
        $updateValues[] = $mpPaymentId;
    }
    if ($hasExternalReference && $externalReference) {
        $updateColumns[] = 'external_reference = ?';
        $updateValues[] = $externalReference;
    }
    $updateValues[] = $existing['id'];

    $stmtUpdateInvoice = $pdo->prepare(
        "UPDATE invoices SET " . implode(', ', $updateColumns) . " WHERE id = ?"
    );
    $stmtUpdateInvoice->execute($updateValues);
} else {
    $columns = ['loja_id', 'assinatura_id', 'gateway', 'mp_payment_id', 'status', 'amount', 'currency', 'period_start', 'period_end', 'paid_at'];
    $placeholders = array_fill(0, count($columns), '?');
    $values = [
        $invoiceData['loja_id'],
        $invoiceData['assinatura_id'],
        $invoiceData['gateway'],
        $invoiceData['mp_payment_id'],
        $invoiceData['status'],
        $invoiceData['amount'],
        $invoiceData['currency'],
        $invoiceData['period_start'],
        $invoiceData['period_end'],
        $invoiceData['paid_at'],
    ];
    if ($hasExternalReference && $externalReference) {
        $columns[] = 'external_reference';
        $placeholders[] = '?';
        $values[] = $externalReference;
    }
    $stmtInsertInvoice = $pdo->prepare(
        "INSERT INTO invoices (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
    );
    $stmtInsertInvoice->execute($values);
}

mp_log(date('c') . " | payload=" . $payloadRaw . " | subId={$preapprovalId} | status={$status} | lojaId={$lojaId}");

echo json_encode(['success' => true]);
?>
