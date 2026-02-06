<?php
// api/subscription_helpers.php

function sh_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function sh_require_login(): void
{
    if (empty($_SESSION['loja_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sessão expirada.']);
        exit;
    }
}

function sh_parse_datetime(?string $value): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }
}

function sh_get_subscription_snapshot(PDO $pdo, int $lojaId): array
{
    $hasTrial = sh_column_exists($pdo, 'lojas', 'trial_until');
    $hasPaid = sh_column_exists($pdo, 'lojas', 'paid_until');
    $hasStatus = sh_column_exists($pdo, 'lojas', 'assinatura_status');

    if (!$hasTrial && !$hasPaid && !$hasStatus) {
        return [
            'active' => true,
            'trial_until' => null,
            'paid_until' => null,
            'assinatura_status' => null,
        ];
    }

    $fields = [];
    if ($hasTrial) {
        $fields[] = 'trial_until';
    }
    if ($hasPaid) {
        $fields[] = 'paid_until';
    }
    if ($hasStatus) {
        $fields[] = 'assinatura_status';
    }

    $stmt = $pdo->prepare("SELECT " . implode(', ', $fields) . " FROM lojas WHERE id = ? LIMIT 1");
    $stmt->execute([$lojaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $now = new DateTimeImmutable('now');
    $trialUntil = $hasTrial ? sh_parse_datetime($row['trial_until'] ?? null) : null;
    $paidUntil = $hasPaid ? sh_parse_datetime($row['paid_until'] ?? null) : null;

    $trialActive = $trialUntil ? $now <= $trialUntil : false;
    $paidActive = $paidUntil ? $now <= $paidUntil : false;

    return [
        'active' => $trialActive || $paidActive,
        'trial_until' => $trialUntil ? $trialUntil->format('Y-m-d H:i:s') : null,
        'paid_until' => $paidUntil ? $paidUntil->format('Y-m-d H:i:s') : null,
        'assinatura_status' => $hasStatus ? ($row['assinatura_status'] ?? null) : null,
    ];
}

function sh_require_active_subscription(PDO $pdo): void
{
    sh_require_login();

    $lojaId = (int)$_SESSION['loja_id'];
    if ($lojaId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sessão expirada.']);
        exit;
    }

    $snapshot = sh_get_subscription_snapshot($pdo, $lojaId);
    if (!$snapshot['active']) {
        http_response_code(402);
        echo json_encode(['success' => false, 'message' => 'Assinatura inativa.']);
        exit;
    }
}
?>
