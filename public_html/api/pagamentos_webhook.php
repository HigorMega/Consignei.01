<?php
// api/pagamentos_webhook.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";
require_once __DIR__ . "/../lib/mercadopago.php";

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true) ?: [];
$headers = mp_get_request_headers();

mp_log('payment_webhook_received', [
    'event_id' => $payload['id'] ?? null,
    'resource_id' => $payload['data']['id'] ?? ($payload['data_id'] ?? null),
    'type' => $payload['type'] ?? null,
    'action' => $payload['action'] ?? null,
]);

if (!mp_validate_webhook_signature($rawBody, $headers)) {
    mp_log('payment_webhook_invalid_signature');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$accessToken = mp_get_access_token();
if (!$accessToken) {
    mp_log('payment_webhook_missing_token');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$paymentId = $payload['data']['id'] ?? $payload['data_id'] ?? $payload['id'] ?? $_GET['id'] ?? $_POST['id'] ?? null;
if (!$paymentId && !empty($_GET['data.id'])) {
    $paymentId = $_GET['data.id'];
}
if (!$paymentId) {
    mp_log('payment_webhook_missing_payment_id', ['payload' => $payload]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$eventType = (string) ($payload['type'] ?? 'payment');
$action = isset($payload['action']) ? (string) $payload['action'] : null;
$stored = mp_store_webhook_event($pdo, $payload, $headers, (string) $paymentId, $eventType, $action);
if ($stored['duplicate']) {
    mp_log('payment_webhook_duplicate', ['event_id' => $stored['event_id']]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$paymentResponse = mp_get_payment((string) $paymentId);
if (!$paymentResponse['success']) {
    mp_log('payment_webhook_fetch_failed', [
        'payment_id' => $paymentId,
        'status' => $paymentResponse['status'],
        'request_id' => $paymentResponse['request_id'] ?? null,
    ]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$payment = $paymentResponse['data'] ?? [];
$externalReference = $payment['external_reference'] ?? null;
$paymentStatus = $payment['status'] ?? null;
$transactionAmount = isset($payment['transaction_amount']) ? (float) $payment['transaction_amount'] : null;
$currencyId = $payment['currency_id'] ?? null;
$paidAt = sh_parse_datetime($payment['date_approved'] ?? $payment['date_created'] ?? null);

$invoiceStatus = mp_map_payment_status($paymentStatus);

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

if (!$invoice && sh_column_exists($pdo, 'invoices', 'mp_payment_id')) {
    $stmt = $pdo->prepare("SELECT id, loja_id FROM invoices WHERE mp_payment_id = ? LIMIT 1");
    $stmt->execute([(string) $paymentId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($invoice) {
    $invoiceId = (int) $invoice['id'];
    $lojaId = (int) $invoice['loja_id'];
} else {
    mp_log('payment_webhook_invoice_not_found', [
        'payment_id' => $paymentId,
        'external_reference' => $externalReference,
    ]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->beginTransaction();
try {
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
        $baseDate = $paidAt ?: new DateTimeImmutable('now');
        $paidUntil = $baseDate->modify('+1 month')->format('Y-m-d H:i:s');
        $updates = ['paid_until = ?'];
        $values = [$paidUntil];
        if (sh_column_exists($pdo, 'lojas', 'trial_until')) {
            $updates[] = 'trial_until = ?';
            $values[] = null;
        }
        if (sh_column_exists($pdo, 'lojas', 'subscription_status')) {
            $updates[] = 'subscription_status = ?';
            $values[] = 'active';
        }
        $values[] = $lojaId;
        $stmtUpdateLoja = $pdo->prepare("UPDATE lojas SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmtUpdateLoja->execute($values);
    } elseif ($invoiceStatus === 'failed' && $lojaId) {
        $updates = [];
        $values = [];
        if (sh_column_exists($pdo, 'lojas', 'trial_until')) {
            $updates[] = 'trial_until = ?';
            $values[] = null;
        }
        if (sh_column_exists($pdo, 'lojas', 'paid_until')) {
            $updates[] = 'paid_until = ?';
            $values[] = null;
        }
        if (sh_column_exists($pdo, 'lojas', 'subscription_status')) {
            $updates[] = 'subscription_status = ?';
            $values[] = 'cancelled';
        }
        if ($updates) {
            $values[] = $lojaId;
            $stmtUpdateLoja = $pdo->prepare("UPDATE lojas SET " . implode(', ', $updates) . " WHERE id = ?");
            $stmtUpdateLoja->execute($values);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    mp_log('payment_webhook_db_error', ['error' => $e->getMessage()]);
}

mp_log('payment_webhook_processed', [
    'payment_id' => $paymentId,
    'status' => $paymentStatus,
    'invoice_id' => $invoiceId,
    'request_id' => $paymentResponse['request_id'] ?? null,
]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
?>
