<?php
// api/billing/create_checkout.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../db/conexao.php';
require_once __DIR__ . '/../subscription_helpers.php';

sh_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

$checkoutUrl = env('BILLING_CHECKOUT_URL');
if (!$checkoutUrl) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Checkout não configurado. Defina BILLING_CHECKOUT_URL no .env.'
    ]);
    exit;
}

$lojaId = (int) ($_SESSION['loja_id'] ?? 0);
if ($lojaId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão expirada.']);
    exit;
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
    'checkout_url' => $checkoutUrl
], JSON_UNESCAPED_UNICODE);
?>
