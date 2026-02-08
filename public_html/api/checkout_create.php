<?php
// api/checkout_create.php
session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";

function mp_log_preference(string $message): void
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

    $price = (float) env('SUBSCRIPTION_PRICE', '21.90');
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $assinaturaId = 'pref:' . uniqid();
    $hasExternalReference = sh_column_exists($pdo, 'invoices', 'external_reference');

    $columns = ['loja_id', 'assinatura_id', 'gateway', 'status', 'amount', 'currency'];
    $placeholders = ['?', '?', '?', '?', '?', '?'];
    $values = [$lojaId, $assinaturaId, 'mercadopago', 'pending', $price, 'BRL'];
    if ($hasExternalReference) {
        $columns[] = 'external_reference';
        $placeholders[] = '?';
        $values[] = null;
    }

    $stmtInvoice = $pdo->prepare(
        "INSERT INTO invoices (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
    );
    $stmtInvoice->execute($values);

    $invoiceId = (int) $pdo->lastInsertId();
    $externalReference = 'inv:' . $invoiceId;

    if ($hasExternalReference) {
        $stmtUpdateInvoice = $pdo->prepare("UPDATE invoices SET external_reference = ? WHERE id = ?");
        $stmtUpdateInvoice->execute([$externalReference, $invoiceId]);
    }

    $payload = [
        'external_reference' => $externalReference,
        'notification_url' => $appUrl . '/api/pagamentos_webhook.php',
        'payer' => [
            'email' => $loja['email'] ?? null,
        ],
        'items' => [
            [
                'id' => 'assinatura_mensal',
                'title' => 'Assinatura Consignei - Plano Mensal',
                'description' => 'Acesso ao sistema Consignei por 30 dias',
                'category_id' => 'services',
                'quantity' => 1,
                'unit_price' => $price,
            ],
        ],
    ];

    mp_log_preference("Iniciando checkout preference para Loja ID: {$lojaId}");
    mp_log_preference('Payload enviado: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));

    $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
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
        mp_log_preference('Erro cURL: ' . $curlError);
        throw new Exception('Erro ao conectar ao Mercado Pago: ' . $curlError);
    }

    mp_log_preference('Resposta MP (' . $httpCode . '): ' . $response);

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        $message = 'Erro ao criar pagamento no Mercado Pago.';
        $mpMessage = '';

        if (is_array($data)) {
            if (!empty($data['message'])) {
                $mpMessage = (string) $data['message'];
            } elseif (!empty($data['cause'][0]['description'])) {
                $mpMessage = (string) $data['cause'][0]['description'];
            }
        }

        if ($mpMessage) {
            $message .= ' Detalhe: ' . $mpMessage;
        }

        mp_log_preference('Falha no checkout: ' . $message);
        http_response_code($httpCode);
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($data['id'])) {
        mp_log_preference('Resposta inválida do MP: ' . $response);
        throw new Exception('Resposta inválida do Mercado Pago.');
    }

    echo json_encode([
        'success' => true,
        'id' => $data['id'],
        'init_point' => $data['init_point'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    mp_log_preference('Erro crítico: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
