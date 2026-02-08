<?php
// api/webhook_mp.php
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";
require_once __DIR__ . "/../lib/mercadopago.php";

$payloadRaw = $GLOBALS['mp_webhook_raw_body'] ?? (file_get_contents('php://input') ?: '');
$payload = $GLOBALS['mp_webhook_payload'] ?? (json_decode($payloadRaw, true) ?: []);
$headers = $GLOBALS['mp_webhook_headers'] ?? mp_get_request_headers();
$GLOBALS['mp_webhook_raw_body'] = $payloadRaw;
$type = (string) ($payload['type'] ?? '');
$action = (string) ($payload['action'] ?? '');

if (empty($GLOBALS['mp_webhook_logged'])) {
    mp_log('webhook_received', [
        'event_id' => $payload['id'] ?? null,
        'resource_id' => $payload['data']['id'] ?? null,
        'type' => $type ?: null,
        'action' => $action ?: null,
    ]);
}

if ($type === 'payment' || ($action !== '' && str_starts_with($action, 'payment.'))) {
    $GLOBALS['mp_webhook_raw_body'] = $payloadRaw;
    $GLOBALS['mp_webhook_payload'] = $payload;
    $GLOBALS['mp_webhook_headers'] = $headers;
    require __DIR__ . '/pagamentos_webhook.php';
    exit;
}

if (empty($GLOBALS['mp_webhook_logged'])) {
    mp_log('subscription_webhook_received', [
        'event_id' => $payload['id'] ?? null,
        'resource_id' => $payload['data']['id'] ?? null,
        'type' => $type ?: null,
        'action' => $action ?: null,
    ]);
}

if (empty($GLOBALS['mp_webhook_signature_validated'])) {
    if (!mp_validate_webhook_signature($headers, $payload, getenv('MP_WEBHOOK_SECRET'))) {
        mp_log('webhook_signature_invalid');
        http_response_code(401);
        echo json_encode(['success' => false]);
        exit;
    }
    mp_log('webhook_signature_ok');
    $GLOBALS['mp_webhook_signature_validated'] = true;
}

$accessToken = mp_get_access_token();
if (!$accessToken) {
    mp_log('preapproval_webhook_missing_token');
    echo json_encode(['success' => true]);
    exit;
}

$preapprovalId = $payload['data']['id'] ?? $_GET['id'] ?? $_POST['id'] ?? null;
if (!$preapprovalId) {
    mp_log('preapproval_webhook_missing_id', ['payload' => $payload]);
    echo json_encode(['success' => true]);
    exit;
}

$eventType = (string) ($payload['type'] ?? 'preapproval');
$action = $action !== '' ? $action : null;
$stored = mp_store_webhook_event($pdo, $payload, $headers, (string) $preapprovalId, $eventType, $action);
if ($stored['duplicate']) {
    mp_log('preapproval_webhook_duplicate', ['event_id' => $stored['event_id']]);
    echo json_encode(['success' => true]);
    exit;
}

$preapprovalResponse = mp_get_preapproval((string) $preapprovalId);
if (!$preapprovalResponse['success']) {
    mp_log('preapproval_webhook_fetch_failed', [
        'preapproval_id' => $preapprovalId,
        'status' => $preapprovalResponse['status'],
        'request_id' => $preapprovalResponse['request_id'] ?? null,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

$preapproval = $preapprovalResponse['data'] ?? [];
$status = $preapproval['status'] ?? 'unknown';
$externalReference = $preapproval['external_reference'] ?? null;
$startDateRaw = $preapproval['auto_recurring']['start_date'] ?? null;
$nextPaymentDateRaw = $preapproval['next_payment_date'] ?? null;

mp_log('subscription_preapproval_fetched', [
    'preapproval_id' => $preapprovalId,
    'status' => $status,
]);

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
    } elseif (sh_column_exists($pdo, 'lojas', 'subscription_id')) {
        $stmt = $pdo->prepare("SELECT id FROM lojas WHERE subscription_id = ? LIMIT 1");
        $stmt->execute([$preapprovalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lojaId = (int) $row['id'];
        }
    }
}

if (!$lojaId) {
    mp_log('preapproval_webhook_loja_not_found', [
        'preapproval_id' => $preapprovalId,
        'status' => $status,
        'external_reference' => $externalReference,
    ]);
    echo json_encode(['success' => true]);
    exit;
}

$updates = [];
$values = [];

$activeStatuses = ['authorized', 'active', 'approved'];
$inactiveStatuses = ['paused', 'cancelled', 'cancelled_by_user', 'expired', 'rejected'];
$trialStatus = 'trial';
$activeStatus = 'active';
$cancelledStatus = 'cancelled';

$now = new DateTimeImmutable('now');
$startDate = sh_parse_datetime($startDateRaw);
$nextPaymentDate = sh_parse_datetime($nextPaymentDateRaw);

$mpPaymentId = $preapproval['last_payment_id'] ?? $preapproval['payment_id'] ?? null;
if (!$mpPaymentId && !empty($preapproval['last_payment']['id'])) {
    $mpPaymentId = $preapproval['last_payment']['id'];
}

$shouldSetTrial = false;
$shouldSetActive = false;
$shouldBlock = false;

if (in_array($status, $activeStatuses, true)) {
    if (!$mpPaymentId) {
        if ($startDate && $now < $startDate) {
            $shouldSetTrial = true;
        } else {
            $shouldBlock = true;
        }
    } else {
        $shouldSetActive = true;
    }
} elseif (in_array($status, $inactiveStatuses, true)) {
    $shouldBlock = true;
}

if (sh_column_exists($pdo, 'lojas', 'subscription_id')) {
    $updates[] = 'subscription_id = ?';
    $values[] = $preapprovalId;
}

if (sh_column_exists($pdo, 'lojas', 'subscription_status')) {
    if ($shouldSetTrial) {
        $updates[] = 'subscription_status = ?';
        $values[] = $trialStatus;
    } elseif ($shouldSetActive) {
        $updates[] = 'subscription_status = ?';
        $values[] = $activeStatus;
    } elseif ($shouldBlock) {
        $updates[] = 'subscription_status = ?';
        $values[] = $cancelledStatus;
    } else {
        $updates[] = 'subscription_status = ?';
        $values[] = 'pending';
    }
}

if (sh_column_exists($pdo, 'lojas', 'trial_until')) {
    if ($shouldSetTrial) {
        $trialUntil = $startDate ?? $nextPaymentDate;
        $updates[] = 'trial_until = ?';
        $values[] = $trialUntil ? $trialUntil->format('Y-m-d H:i:s') : null;
    } else {
        $updates[] = 'trial_until = ?';
        $values[] = null;
    }
}

if (sh_column_exists($pdo, 'lojas', 'paid_until')) {
    if ($shouldSetActive) {
        $paidUntil = $nextPaymentDate;
        if (!$paidUntil && $startDate) {
            $paidUntil = $startDate->modify('+1 month');
        }
        $updates[] = 'paid_until = ?';
        $values[] = $paidUntil ? $paidUntil->format('Y-m-d H:i:s') : null;
    } else {
        $updates[] = 'paid_until = ?';
        $values[] = null;
    }
}

$pdo->beginTransaction();
try {
    if ($updates) {
        $values[] = $lojaId;
        $stmtUpdate = $pdo->prepare("UPDATE lojas SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmtUpdate->execute($values);
    }

    $amount = $preapproval['auto_recurring']['transaction_amount'] ?? (float) env('SUBSCRIPTION_PRICE', '21.90');
    $currency = $preapproval['auto_recurring']['currency_id'] ?? 'BRL';

    $periodStart = sh_parse_datetime($preapproval['last_payment_date'] ?? null);
    $periodEnd = $nextPaymentDate;

    $invoiceStatus = mp_map_preapproval_status($status);
    $paidAt = null;

    if ($mpPaymentId) {
        $paymentResponse = mp_get_payment((string) $mpPaymentId);
        if ($paymentResponse['success']) {
            $payment = $paymentResponse['data'] ?? [];
            $paymentStatus = $payment['status'] ?? null;
            $invoiceStatus = mp_map_payment_status($paymentStatus);
            if ($invoiceStatus === 'paid') {
                $paidAt = sh_parse_datetime($payment['date_approved'] ?? $payment['date_created'] ?? null);
            }
            if (!$periodStart) {
                $periodStart = sh_parse_datetime($payment['date_created'] ?? null);
            }
        }
    }

    if ($shouldSetTrial || (!$mpPaymentId && in_array($status, $activeStatuses, true))) {
        $invoiceStatus = 'pending';
    } elseif ($shouldBlock && in_array($status, $inactiveStatuses, true)) {
        $invoiceStatus = 'failed';
    }

    $invoiceData = [
        'loja_id' => $lojaId,
        'assinatura_id' => $preapprovalId,
        'gateway' => 'mercadopago',
        'mp_payment_id' => $mpPaymentId,
        'mp_preapproval_id' => $preapprovalId,
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
    } elseif (sh_column_exists($pdo, 'invoices', 'mp_preapproval_id')) {
        $stmtInvoice = $pdo->prepare("SELECT id FROM invoices WHERE mp_preapproval_id = ? ORDER BY id DESC LIMIT 1");
        $stmtInvoice->execute([$preapprovalId]);
        $existing = $stmtInvoice->fetch(PDO::FETCH_ASSOC);
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
        if (sh_column_exists($pdo, 'invoices', 'mp_preapproval_id')) {
            $updateColumns[] = 'mp_preapproval_id = ?';
            $updateValues[] = $preapprovalId;
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
        if (sh_column_exists($pdo, 'invoices', 'mp_preapproval_id')) {
            $columns[] = 'mp_preapproval_id';
            $placeholders[] = '?';
            $values[] = $preapprovalId;
        }
        if ($hasExternalReference && $externalReference) {
            $columns[] = 'external_reference';
            $placeholders[] = '?';
            $values[] = $externalReference;
        }
        $stmtInsertInvoice = $pdo->prepare(
            "INSERT INTO invoices (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
        );
        try {
            $stmtInsertInvoice->execute($values);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $stmtInvoice = $pdo->prepare("SELECT id FROM invoices WHERE mp_preapproval_id = ? LIMIT 1");
                $stmtInvoice->execute([$preapprovalId]);
                $existing = $stmtInvoice->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $stmtUpdateInvoice = $pdo->prepare(
                        "UPDATE invoices SET status = ?, amount = ?, currency = ?, period_start = ?, period_end = ?, paid_at = ?, mp_payment_id = ?, external_reference = ? WHERE id = ?"
                    );
                    $stmtUpdateInvoice->execute([
                        $invoiceData['status'],
                        $invoiceData['amount'],
                        $invoiceData['currency'],
                        $invoiceData['period_start'],
                        $invoiceData['period_end'],
                        $invoiceData['paid_at'],
                        $invoiceData['mp_payment_id'],
                        $invoiceData['external_reference'],
                        $existing['id'],
                    ]);
                }
            } else {
                throw $e;
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    mp_log('preapproval_webhook_db_error', ['error' => $e->getMessage()]);
}

mp_log('preapproval_webhook_processed', [
    'preapproval_id' => $preapprovalId,
    'status' => $status,
    'invoice_status' => $invoiceStatus,
    'loja_id' => $lojaId,
]);

echo json_encode(['success' => true]);
?>
