<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db/conexao.php';
require_once __DIR__ . '/subscription_helpers.php';

function billing_webhook_json_error(int $status, string $error, ?string $detail = null): void
{
    http_response_code($status);
    $payload = ['success' => false, 'error' => $error];
    if ($detail !== null) {
        $payload['detail'] = $detail;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function billing_log_webhook(string $message): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/billing_webhook.log';
    file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function billing_webhook_normalize_status(?string $status): string
{
    if (!$status) {
        return 'pending';
    }
    $status = strtolower($status);
    if (in_array($status, ['authorized', 'active', 'approved'], true)) {
        return 'active';
    }
    if (in_array($status, ['trial', 'testing'], true)) {
        return 'trial';
    }
    if (in_array($status, ['expired', 'overdue'], true)) {
        return 'expired';
    }
    if (in_array($status, ['cancelled', 'canceled', 'rejected'], true)) {
        return 'cancelled';
    }
    return 'pending';
}

function billing_webhook_parse_date(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    try {
        $date = new DateTimeImmutable($value);
        return $date->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

try {
    $payloadRaw = file_get_contents('php://input') ?: '';
    $payload = json_decode($payloadRaw, true) ?: [];

    $subscriptionId = $payload['subscription_id']
        ?? $payload['data']['id']
        ?? $_GET['subscription_id']
        ?? $_POST['subscription_id']
        ?? null;
    $statusRaw = $payload['status'] ?? $payload['data']['status'] ?? null;
    $trialUntil = billing_webhook_parse_date($payload['trial_until'] ?? null);
    $paidUntil = billing_webhook_parse_date($payload['paid_until'] ?? null);

    $lojaId = $payload['loja_id'] ?? $payload['external_reference'] ?? null;
    if ($lojaId !== null && ctype_digit((string) $lojaId)) {
        $lojaId = (int) $lojaId;
    } else {
        $lojaId = null;
    }

    if (!$lojaId && $subscriptionId && sh_column_exists($pdo, 'lojas', 'subscription_id')) {
        $stmt = $pdo->prepare('SELECT id FROM lojas WHERE subscription_id = ? LIMIT 1');
        $stmt->execute([$subscriptionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lojaId = (int) $row['id'];
        }
    }

    if (!$lojaId) {
        billing_log_webhook(date('c') . ' | error=loja_not_found | payload=' . $payloadRaw);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = billing_webhook_normalize_status($statusRaw);

    $updates = [];
    $values = [];

    if (sh_column_exists($pdo, 'lojas', 'subscription_status')) {
        $updates[] = 'subscription_status = ?';
        $values[] = $status;
    }
    if ($subscriptionId && sh_column_exists($pdo, 'lojas', 'subscription_id')) {
        $updates[] = 'subscription_id = ?';
        $values[] = $subscriptionId;
    }
    if ($trialUntil && sh_column_exists($pdo, 'lojas', 'trial_until')) {
        $updates[] = 'trial_until = ?';
        $values[] = $trialUntil;
    }
    if ($paidUntil && sh_column_exists($pdo, 'lojas', 'paid_until')) {
        $updates[] = 'paid_until = ?';
        $values[] = $paidUntil;
    }

    if ($updates) {
        $values[] = $lojaId;
        $stmtUpdate = $pdo->prepare('UPDATE lojas SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmtUpdate->execute($values);
    }

    billing_log_webhook(
        date('c')
        . " | loja_id={$lojaId} | status={$status} | subscription_id={$subscriptionId} | payload="
        . $payloadRaw
    );

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    billing_webhook_json_error(500, 'Erro interno', $e->getMessage());
}
