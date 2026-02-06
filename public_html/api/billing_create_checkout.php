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

    $accessToken = env('MP_ACCESS_TOKEN');
    if (!$accessToken) {
        billing_checkout_json_error(500, 'Erro interno', 'MP_ACCESS_TOKEN não configurado.');
    }

    $appUrl = rtrim(env('APP_URL', ''), '/');
    if (!$appUrl) {
        billing_checkout_json_error(500, 'Erro interno', 'APP_URL não configurado.');
    }

    $lojaId = (int) ($_SESSION['loja_id'] ?? 0);
    if ($lojaId <= 0) {
        billing_checkout_json_error(401, 'unauthorized');
    }

    $stmt = $pdo->prepare('SELECT email FROM lojas WHERE id = ? LIMIT 1');
    $stmt->execute([$lojaId]);
    $loja = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loja) {
        billing_checkout_json_error(404, 'Erro interno', 'Loja não encontrada.');
    }

    $price = (float) env('SUBSCRIPTION_PRICE', '21.90');
    $trialDays = (int) env('SUBSCRIPTION_TRIAL_DAYS', '5');
    $reason = env('SUBSCRIPTION_REASON', 'Consignei App - Mensalidade');
    $startDate = (new DateTimeImmutable('now'))
        ->modify(sprintf('+%d days', max(0, $trialDays)))
        ->format(DATE_ATOM);

    $notificationUrl = $appUrl . '/api/billing_webhook.php';
    $backUrl = $appUrl . '/public/assinatura_retorno.html';

    $payload = [
        'reason' => $reason,
        'external_reference' => (string) $lojaId,
        'payer_email' => $loja['email'] ?? '',
        'auto_recurring' => [
            'frequency' => 1,
            'frequency_type' => 'months',
            'transaction_amount' => $price,
            'currency_id' => 'BRL',
            'start_date' => $startDate,
        ],
        'notification_url' => $notificationUrl,
        'back_url' => $backUrl,
    ];

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
        billing_checkout_json_error(500, 'Erro interno', 'Erro ao conectar ao gateway: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        billing_checkout_json_error($httpCode, 'Erro interno', 'Erro ao criar assinatura.');
    }

    if (empty($data['id'])) {
        billing_checkout_json_error(500, 'Erro interno', 'Resposta inválida ao criar assinatura.');
    }

    $isSandbox = strtolower((string) env('MP_MODE', '')) === 'sandbox' || str_starts_with($accessToken, 'TEST-');
    $checkoutUrl = $isSandbox ? ($data['sandbox_init_point'] ?? null) : ($data['init_point'] ?? null);
    if (!$checkoutUrl) {
        billing_checkout_json_error(500, 'Erro interno', 'Checkout não retornou URL.');
    }

    $updates = [];
    $values = [];

    if (sh_column_exists($pdo, 'lojas', 'subscription_status')) {
        $updates[] = 'subscription_status = ?';
        $values[] = 'pending';
    }

    if (sh_column_exists($pdo, 'lojas', 'subscription_id')) {
        $updates[] = 'subscription_id = ?';
        $values[] = $data['id'];
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
