<?php
// api/criar_assinatura_mp.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";

sh_require_login();

$accessToken = env('MP_ACCESS_TOKEN');
if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'MP_ACCESS_TOKEN não configurado.']);
    exit;
}

$lojaId = (int)$_SESSION['loja_id'];
$stmt = $pdo->prepare("SELECT email FROM lojas WHERE id = ? LIMIT 1");
$stmt->execute([$lojaId]);
$loja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loja) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Loja não encontrada.']);
    exit;
}

$reason = env('SUBSCRIPTION_REASON', 'Consignei App - Mensalidade');
$price = (float) env('SUBSCRIPTION_PRICE', '21.90');
$appUrl = rtrim(env('APP_URL', ''), '/');
if (!$appUrl) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'APP_URL não configurado.']);
    exit;
}

$notificationUrl = $appUrl . '/api/webhook_mp.php';
$backUrl = $appUrl . '/public/assinatura_retorno.html';

$startDate = (new DateTimeImmutable('now'))->modify('+5 days')->format(DATE_ATOM);

$payload = [
    'reason' => $reason,
    'external_reference' => (string) $lojaId,
    'payer_email' => $loja['email'],
    'auto_recurring' => [
        'frequency' => 1,
        'frequency_type' => 'months',
        'transaction_amount' => $price,
        'currency_id' => 'BRL',
        'start_date' => $startDate,
    ],
];

$payload['notification_url'] = $notificationUrl;
$payload['back_url'] = $backUrl;

$ch = curl_init('https://api.mercadopago.com/preapproval');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao Mercado Pago: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);
if ($httpCode >= 400) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao criar assinatura no Mercado Pago.',
        'details' => $data,
    ]);
    exit;
}

if (empty($data['id'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Resposta inválida do Mercado Pago.']);
    exit;
}

$updates = [];
$values = [];
if (sh_column_exists($pdo, 'lojas', 'assinatura_gateway')) {
    $updates[] = 'assinatura_gateway = ?';
    $values[] = 'mercadopago';
}
if (sh_column_exists($pdo, 'lojas', 'assinatura_id')) {
    $updates[] = 'assinatura_id = ?';
    $values[] = $data['id'];
}
if (sh_column_exists($pdo, 'lojas', 'assinatura_status')) {
    $updates[] = 'assinatura_status = ?';
    $values[] = 'pending';
}

if ($updates) {
    $values[] = $lojaId;
    $stmtUpdate = $pdo->prepare("UPDATE lojas SET " . implode(', ', $updates) . " WHERE id = ?");
    $stmtUpdate->execute($values);
}

echo json_encode([
    'success' => true,
    'id' => $data['id'],
    'init_point' => $data['init_point'] ?? null,
    'status' => $data['status'] ?? null,
], JSON_UNESCAPED_UNICODE);
?>
