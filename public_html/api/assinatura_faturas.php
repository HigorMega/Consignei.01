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
    $faturas = array_map(static function (array $fatura): array {
        $fatura['created_at'] = sh_format_datetime_br($fatura['created_at'] ?? null);
        $fatura['paid_at'] = sh_format_datetime_br($fatura['paid_at'] ?? null);
        $fatura['period_start'] = sh_format_datetime_br($fatura['period_start'] ?? null);
        $fatura['period_end'] = sh_format_datetime_br($fatura['period_end'] ?? null);
        return $fatura;
    }, $faturas);

    echo json_encode(['success' => true, 'faturas' => $faturas], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'faturas' => []], JSON_UNESCAPED_UNICODE);
}
?>
