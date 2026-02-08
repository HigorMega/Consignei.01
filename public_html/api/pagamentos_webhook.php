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

$rawBody = $GLOBALS['mp_webhook_raw_body'] ?? (file_get_contents('php://input') ?: '');
$payload = $GLOBALS['mp_webhook_payload'] ?? (json_decode($rawBody, true) ?: []);
$headers = $GLOBALS['mp_webhook_headers'] ?? (function_exists('getallheaders') ? getallheaders() : []);
$GLOBALS['mp_webhook_raw_body'] = $rawBody;
$h = [];
foreach ($headers as $k => $v) {
    $h[strtolower($k)] = $v;
}
$type = (string) ($payload['type'] ?? '');
$action = (string) ($payload['action'] ?? '');
$liveMode = (bool) ($payload['live_mode'] ?? false);
$eventId = (string) ($payload['id'] ?? '');
$resourceId = (string) ($payload['data']['id'] ?? '');
$isPanelTest = (!$liveMode) && ($eventId === '123456' || $resourceId === '123456');
$hasSignature = !empty($h['x-signature']);
$hasRequestId = !empty($h['x-request-id']);
$secretValue = (string) getenv('MP_WEBHOOK_SECRET');
$hasSecret = $secretValue !== '';
$secretLength = strlen($secretValue);

$signaturePairs = [];
$signatureHeader = $h['x-signature'] ?? '';
if ($signatureHeader !== '') {
    foreach (explode(',', $signatureHeader) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '' || !str_contains($chunk, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $chunk, 2));
        $signaturePairs[$key] = $value;
    }
}
$tsValue = $signaturePairs['ts'] ?? '';
$v1Value = $signaturePairs['v1'] ?? '';
$manifestPreview = null;
$calcPrefix = null;
if ($resourceId !== '' && $hasRequestId && $tsValue !== '') {
    $manifest = 'id:' . $resourceId . ';request-id:' . $h['x-request-id'] . ';ts:' . $tsValue . ';';
    $manifestPreview = substr($manifest, 0, 60);
    if ($hasSecret) {
        $calcPrefix = substr(hash_hmac('sha256', $manifest, $secretValue), 0, 8);
    }
}
$v1Prefix = $v1Value !== '' ? substr($v1Value, 0, 8) : null;

mp_log('webhook_headers_debug', [
    'live_mode' => $liveMode,
    'event_id' => $eventId,
    'resource_id' => $resourceId,
    'has_signature' => $hasSignature,
    'has_request_id' => $hasRequestId,
    'ts_exists' => $tsValue !== '',
    'v1_exists' => $v1Value !== '',
    'manifest_preview' => $manifestPreview,
    'v1_prefix' => $v1Prefix,
    'calc_prefix' => $calcPrefix,
    'has_secret' => $hasSecret,
    'secret_length' => $secretLength,
    'header_keys' => array_keys($h),
]);

if ($isPanelTest) {
    mp_log('webhook_panel_test_bypass', [
        'event_id' => $eventId,
        'resource_id' => $resourceId,
        'type' => $payload['type'] ?? null,
        'action' => $payload['action'] ?? null,
    ]);
    http_response_code(200);
    echo json_encode(['ok' => true, 'panel_test' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

mp_log('webhook_received', [
    'event_id' => $payload['id'] ?? null,
    'resource_id' => $payload['data']['id'] ?? ($payload['data_id'] ?? null),
    'type' => $type ?: null,
    'action' => $action ?: null,
]);

$isPayment = $type === 'payment' || ($action !== '' && str_starts_with($action, 'payment.'));
$isSubscription = $type === 'subscription'
    || $type === 'preapproval'
    || ($action !== '' && (str_contains($action, 'preapproval')
        || str_contains($action, 'subscription')
        || str_contains($action, 'plan')));

if ($isPayment) {
    mp_log('payment_webhook_received', [
        'event_id' => $payload['id'] ?? null,
        'resource_id' => $payload['data']['id'] ?? ($payload['data_id'] ?? null),
        'type' => $type ?: null,
        'action' => $action ?: null,
    ]);
} elseif ($isSubscription) {
    mp_log('subscription_webhook_received', [
        'event_id' => $payload['id'] ?? null,
        'resource_id' => $payload['data']['id'] ?? ($payload['data_id'] ?? null),
        'type' => $type ?: null,
        'action' => $action ?: null,
    ]);
}

if (!mp_validate_webhook_signature($h, $payload, $secretValue)) {
    mp_log('webhook_signature_invalid', ['live_mode' => $liveMode, 'has_signature' => $hasSignature]);
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature'], JSON_UNESCAPED_UNICODE);
    exit;
}

mp_log('webhook_signature_ok');

$GLOBALS['mp_webhook_signature_validated'] = true;
$GLOBALS['mp_webhook_logged'] = true;

if ($isSubscription) {
    $GLOBALS['mp_webhook_raw_body'] = $rawBody;
    $GLOBALS['mp_webhook_payload'] = $payload;
    $GLOBALS['mp_webhook_headers'] = $headers;
    require __DIR__ . '/webhook_mp.php';
    exit;
}

if (!$isPayment) {
    mp_log('webhook_unknown_type', [
        'type' => $type ?: null,
        'action' => $action ?: null,
    ]);
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

$eventType = $type ?: 'payment';
$action = $action !== '' ? $action : null;
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

mp_log('payment_fetched', [
    'payment_id' => $paymentId,
    'status' => $paymentStatus,
    'external_reference' => $externalReference,
]);

$invoiceStatus = mp_map_payment_status($paymentStatus);

$invoice = null;
$invoiceId = null;
$lojaId = null;

if ($externalReference) {
    if (preg_match('/^(inv|subinv):(\d+)$/', (string) $externalReference, $matches)) {
        $invoiceId = (int) $matches[2];
        $stmt = $pdo->prepare("SELECT id, loja_id, status, mp_payment_id FROM invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif (sh_column_exists($pdo, 'invoices', 'external_reference')) {
        $stmt = $pdo->prepare("SELECT id, loja_id, status, mp_payment_id FROM invoices WHERE external_reference = ? LIMIT 1");
        $stmt->execute([(string) $externalReference]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!$invoice && sh_column_exists($pdo, 'invoices', 'mp_payment_id')) {
    $stmt = $pdo->prepare("SELECT id, loja_id, status, mp_payment_id FROM invoices WHERE mp_payment_id = ? LIMIT 1");
    $stmt->execute([(string) $paymentId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($invoice) {
    $invoiceId = (int) $invoice['id'];
    $lojaId = (int) $invoice['loja_id'];
    mp_log('invoice_matched', [
        'payment_id' => $paymentId,
        'invoice_id' => $invoiceId,
    ]);
} else {
    mp_log('payment_webhook_invoice_not_found', [
        'payment_id' => $paymentId,
        'external_reference' => $externalReference,
    ]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((string) ($invoice['mp_payment_id'] ?? '') === (string) $paymentId
    && in_array((string) ($invoice['status'] ?? ''), ['paid', 'failed'], true)
) {
    mp_log('payment_webhook_idempotent_skip', [
        'payment_id' => $paymentId,
        'invoice_id' => $invoiceId,
        'status' => $invoice['status'] ?? null,
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
    mp_log('invoice_updated', [
        'payment_id' => $paymentId,
        'invoice_id' => $invoiceId,
        'status' => $invoiceStatus,
    ]);

    if ($invoiceStatus === 'paid' && $lojaId && sh_column_exists($pdo, 'lojas', 'paid_until')) {
        $updates = ['paid_until = DATE_ADD(CASE WHEN paid_until > NOW() THEN paid_until ELSE NOW() END, INTERVAL 1 MONTH)'];
        $values = [];
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
        mp_log('paid_until_updated', [
            'payment_id' => $paymentId,
            'loja_id' => $lojaId,
        ]);
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
