<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db/conexao.php';
require_once __DIR__ . '/subscription_helpers.php';

function billing_checkout_json_error(int $status, string $error, ?string $detail = null, array $extras = []): void
{
    http_response_code($status);
    $payload = ['success' => false, 'error' => $error];
    if ($detail !== null) {
        $payload['detail'] = $detail;
    }
    if ($extras) {
        $payload = array_merge($payload, $extras);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function billing_checkout_log(array $data): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/billing_create_checkout.log';
    $line = sprintf(
        "%s %s%s",
        (new DateTimeImmutable('now'))->format(DATE_ATOM),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        PHP_EOL
    );
    file_put_contents($logFile, $line, FILE_APPEND);
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
        billing_checkout_json_error(500, 'Erro interno', 'APP_URL ausente no .env');
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

    $price = 21.90;
    $trialDays = 5;
    $reason = 'Plano Mensal';
    $startDate = sh_format_mp_datetime(
        (new DateTimeImmutable('now'))
            ->modify(sprintf('+%d days', max(0, $trialDays)))
    );

    $notificationUrl = $appUrl . '/api/billing_webhook.php';
    $backUrl = $appUrl . '/public/assinatura_retorno.html';

    $rawBody = file_get_contents('php://input');
    $requestData = [];
    if (is_string($rawBody) && $rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $requestData = $decoded;
        }
    }
    if (!$requestData && !empty($_POST)) {
        $requestData = $_POST;
    }

    $isSandbox = strtolower((string) env('MP_MODE', '')) === 'sandbox' || str_starts_with($accessToken, 'TEST-');
    $payerEmail = null;
    if ($isSandbox && !empty($requestData['payer_email']) && is_string($requestData['payer_email'])) {
        $candidateEmail = trim($requestData['payer_email']);
        if (filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
            $payerEmail = $candidateEmail;
        }
    }

    billing_checkout_log([
        'context' => 'payer_email_handling',
        'payer_email_included' => $payerEmail !== null,
        'reason' => $payerEmail !== null
            ? 'payer_email provided by frontend in sandbox'
            : 'payer_email removed from payload',
    ]);

    $payload = [
        'reason' => $reason,
        'external_reference' => (string) $lojaId,
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

    if ($payerEmail !== null) {
        $payload['payer_email'] = $payerEmail;
    }

    $endpoint = 'https://api.mercadopago.com/preapproval';
    $response = null;
    $curlError = null;
    $httpCode = 0;

    try {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } catch (Throwable $e) {
        billing_checkout_log([
            'context' => 'curl_exception',
            'url' => $endpoint,
            'payload' => $payload,
            'http_status' => $httpCode,
            'response_body' => $response,
            'curl_error' => $curlError,
            'exception' => $e->getMessage(),
        ]);
        billing_checkout_json_error(500, 'Erro interno', 'Erro ao criar assinatura.', [
            'provider_http_status' => $httpCode,
            'provider_response' => $response,
        ]);
    }

    billing_checkout_log([
        'context' => 'provider_response',
        'url' => $endpoint,
        'payload' => $payload,
        'http_status' => $httpCode,
        'response_body' => $response,
        'curl_error' => $curlError,
    ]);

    if ($response === false) {
        billing_checkout_json_error(500, 'Erro interno', 'Erro ao conectar ao gateway: ' . $curlError, [
            'provider_http_status' => $httpCode,
            'provider_response' => $response,
        ]);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        $detail = 'Erro ao criar assinatura';
        $providerMessage = '';
        if (is_array($data)) {
            if (!empty($data['message']) && is_string($data['message'])) {
                $providerMessage = $data['message'];
            } elseif (!empty($data['cause']) && is_array($data['cause'])) {
                $messages = array_filter(array_map(static fn ($cause) => is_array($cause) && !empty($cause['description'])
                    ? (string) $cause['description']
                    : null, $data['cause']));
                $providerMessage = implode(' | ', $messages);
            }
        }
        $normalizedMessage = strtolower($providerMessage);
        if (
            $isSandbox
            && $normalizedMessage
            && (str_contains($normalizedMessage, 'payer') || str_contains($normalizedMessage, 'collector'))
        ) {
            $detail = 'No modo teste, use uma conta buyer de teste diferente do seller. Abra o checkout em janela anônima.';
        }

        billing_checkout_json_error($httpCode, 'Erro interno', $detail, [
            'provider_http_status' => $httpCode,
            'provider_response' => $response,
        ]);
    }

    if (empty($data['id'])) {
        billing_checkout_json_error(500, 'Erro interno', 'Resposta inválida ao criar assinatura.');
    }

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
