<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db/conexao.php';
require_once __DIR__ . '/subscription_helpers.php';

function billing_checkout_json_error(int $status, string $error, ?string $detail = null): void
{
    http_response_code($status);
    $payload = ['success' => false, 'error' => $error];
    if ($detail !== null) {
        $payload['detail'] = $detail;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        billing_checkout_json_error(405, 'method_not_allowed');
    }

    if (empty($_SESSION['loja_id'])) {
        billing_checkout_json_error(401, 'unauthorized');
    }

    $checkoutUrl = env('BILLING_CHECKOUT_URL');
    if (!$checkoutUrl) {
        billing_checkout_json_error(500, 'Erro interno', 'Checkout não configurado. Defina BILLING_CHECKOUT_URL no .env.');
    }

    $lojaId = (int) ($_SESSION['loja_id'] ?? 0);
    if ($lojaId <= 0) {
        billing_checkout_json_error(401, 'unauthorized');
    }

    $updates = [];
    $values = [];

    if (sh_column_exists($pdo, 'lojas', 'subscription_status')) {
        $updates[] = 'subscription_status = ?';
        $values[] = 'pending';
    }

    if (sh_column_exists($pdo, 'lojas', 'subscription_id')) {
        $stmt = $pdo->prepare('SELECT subscription_id FROM lojas WHERE id = ? LIMIT 1');
        $stmt->execute([$lojaId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (empty($existing['subscription_id'])) {
            $updates[] = 'subscription_id = ?';
            $values[] = 'sub_' . bin2hex(random_bytes(8));
        }
    }

    if ($updates) {
        $values[] = $lojaId;
        $stmtUpdate = $pdo->prepare('UPDATE lojas SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmtUpdate->execute($values);
    }

    echo json_encode([
        'success' => true,
        'checkout_url' => $checkoutUrl,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    billing_checkout_json_error(500, 'Erro interno', $e->getMessage());
}
