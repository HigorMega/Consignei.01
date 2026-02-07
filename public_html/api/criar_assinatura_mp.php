<?php
// api/criar_assinatura_mp.php
session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";

function mp_log_checkout(string $message): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logDir . '/checkout_mp.log', $timestamp . " - " . $message . PHP_EOL, FILE_APPEND);
}

try {
    sh_require_login();

    $accessToken = env('MP_ACCESS_TOKEN');
    if (!$accessToken) {
        throw new Exception('MP_ACCESS_TOKEN não configurado.');
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

    if (empty($loja['email'])) {
        throw new Exception('E-mail da loja não encontrado para a assinatura.');
    }

    $reason = env('SUBSCRIPTION_REASON', 'Consignei App - Mensalidade');
    $price = (float) env('SUBSCRIPTION_PRICE', '21.90');
    $appUrl = rtrim(env('APP_URL', ''), '/');
    if (!$appUrl) {
        throw new Exception('APP_URL não configurado.');
    }

    $notificationUrl = $appUrl . '/api/webhook_mp.php';
    $backUrl = $appUrl . '/public/assinatura_retorno.html';
    $startDate = sh_format_mp_datetime(
        (new DateTimeImmutable('now'))->modify('+5 days')
    );

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
        'notification_url' => $notificationUrl,
        'back_url' => $backUrl,
        'status' => 'pending',
    ];

    mp_log_checkout("Iniciando checkout para Loja ID: {$lojaId}");
    mp_log_checkout('Payload enviado: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));

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
        mp_log_checkout('Erro cURL: ' . $curlError);
        throw new Exception('Erro ao conectar ao Mercado Pago: ' . $curlError);
    }

    mp_log_checkout('Resposta MP (' . $httpCode . '): ' . $response);

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        $message = 'Erro ao criar assinatura no Mercado Pago.';
        $mpMessage = '';

        if (is_array($data)) {
            if (!empty($data['message'])) {
                $mpMessage = (string) $data['message'];
            } elseif (!empty($data['cause'][0]['description'])) {
                $mpMessage = (string) $data['cause'][0]['description'];
            }
        }

        if ($httpCode === 400 && $mpMessage) {
            $normalized = mb_strtolower($mpMessage);
            if (str_contains($normalized, 'payer') && str_contains($normalized, 'collector')) {
                $message = 'Erro de teste: o e-mail da Loja é igual ao e-mail da conta Mercado Pago (vendedor). Use um e-mail diferente para testar a assinatura.';
            } else {
                $message .= ' Detalhe: ' . $mpMessage;
            }
        } elseif ($mpMessage) {
            $message .= ' Detalhe: ' . $mpMessage;
        }

        mp_log_checkout('Falha no checkout: ' . $message);
        http_response_code($httpCode);
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($data['id'])) {
        mp_log_checkout('Resposta inválida do MP: ' . $response);
        throw new Exception('Resposta inválida do Mercado Pago.');
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
} catch (Exception $e) {
    mp_log_checkout('Erro crítico: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
