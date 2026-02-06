<?php
// api/assinatura_faturas.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";

sh_require_login();

$lojaId = (int)$_SESSION['loja_id'];

try {
    $stmt = $pdo->prepare(
        "SELECT status, amount, currency, paid_at, created_at, period_start, period_end, mp_payment_id
         FROM invoices
         WHERE loja_id = ?
         ORDER BY created_at DESC
         LIMIT 12"
    );
    $stmt->execute([$lojaId]);
    $faturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'faturas' => $faturas], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'faturas' => []], JSON_UNESCAPED_UNICODE);
}
?>
