<?php
// api/billing/status.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../db/conexao.php';
require_once __DIR__ . '/../subscription_helpers.php';

sh_require_login();

function normalize_status(?string $status): string
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
    if (in_array($status, ['cancelled', 'canceled'], true)) {
        return 'cancelled';
    }
    return 'pending';
}

$lojaId = (int) ($_SESSION['loja_id'] ?? 0);
if ($lojaId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão expirada.']);
    exit;
}

$hasStatus = sh_column_exists($pdo, 'lojas', 'subscription_status');
$hasTrial = sh_column_exists($pdo, 'lojas', 'trial_until');
$hasPaid = sh_column_exists($pdo, 'lojas', 'paid_until');

$fields = [];
if ($hasStatus) {
    $fields[] = 'subscription_status';
}
if ($hasTrial) {
    $fields[] = 'trial_until';
}
if ($hasPaid) {
    $fields[] = 'paid_until';
}

$row = [];
if ($fields) {
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $fields) . ' FROM lojas WHERE id = ? LIMIT 1');
    $stmt->execute([$lojaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$trialUntil = $hasTrial ? sh_parse_datetime($row['trial_until'] ?? null) : null;
$paidUntil = $hasPaid ? sh_parse_datetime($row['paid_until'] ?? null) : null;

$now = new DateTimeImmutable('now');
$status = null;

if ($hasStatus && !empty($row['subscription_status'])) {
    $status = normalize_status($row['subscription_status']);
} else {
    if ($paidUntil && $now <= $paidUntil) {
        $status = 'active';
    } elseif ($trialUntil && $now <= $trialUntil) {
        $status = 'trial';
    } elseif ($paidUntil || $trialUntil) {
        $status = 'expired';
    } else {
        $status = 'pending';
    }
}

$response = [
    'success' => true,
    'status' => $status,
    'price' => 21.90,
    'trial_days' => 5,
    'trial_until' => $trialUntil ? $trialUntil->format('Y-m-d') : null,
    'paid_until' => $paidUntil ? $paidUntil->format('Y-m-d') : null,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>
