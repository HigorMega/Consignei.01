<?php
// api/assinatura_status.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";
require_once __DIR__ . "/../lib/mercadopago.php";

sh_require_login();

$lojaId = (int)$_SESSION['loja_id'];
$snapshot = sh_get_subscription_snapshot($pdo, $lojaId);

$paymentSuggestion = null;
$paymentSuggestionText = null;
$latestInvoiceId = null;
$latestPaymentId = null;
$latestStatus = null;
$latestCreatedAt = null;

try {
    $stmt = $pdo->prepare(
        "SELECT id, status, mp_payment_id, created_at
         FROM invoices
         WHERE loja_id = ?
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$lojaId]);
    $latestInvoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($latestInvoice) {
        $latestInvoiceId = (int) $latestInvoice['id'];
        $latestPaymentId = $latestInvoice['mp_payment_id'] ?? null;
        $latestStatus = $latestInvoice['status'] ?? null;
        $latestCreatedAt = sh_parse_datetime($latestInvoice['created_at'] ?? null);
    }
} catch (PDOException $e) {
    $latestInvoice = null;
}

$snapshot['last_payment_status'] = $latestStatus;

if ($latestStatus) {
    $now = new DateTimeImmutable('now');
    $withinWindow = true;
    if ($latestCreatedAt) {
        $withinWindow = $latestCreatedAt >= $now->modify('-24 hours');
    }

    if ($latestStatus === 'failed' && $withinWindow) {
        $paymentSuggestion = 'alt_payments';
        $paymentSuggestionText = 'Pagamento recusado. Recomendamos PIX/Boleto.';
        $snapshot['message'] = $paymentSuggestionText;
        mp_log('payment_rejected_suggest_alt', [
            'invoice_id' => $latestInvoiceId,
            'payment_id' => $latestPaymentId,
            'created_at' => $latestCreatedAt ? $latestCreatedAt->format('Y-m-d H:i:s') : null,
        ]);
    }
}

$snapshot['payment_suggestion'] = $paymentSuggestion;
$snapshot['payment_suggestion_text'] = $paymentSuggestionText;

echo json_encode($snapshot, JSON_UNESCAPED_UNICODE);
?>
