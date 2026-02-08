<?php
// api/checkout_create.php
session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";
require_once __DIR__ . "/../lib/mercadopago.php";

try {
    sh_require_login();

    $accessToken = mp_get_access_token();
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

    $payerInfo = mp_resolve_payer_email($loja['email'] ?? null);
    if (mp_is_sandbox() && $payerInfo['source'] !== 'test') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Configure um e-mail de comprador de teste (MP_TEST_PAYER_EMAIL) diferente do vendedor para pagar no modo teste.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = [
        'external_reference' => $externalReference,
        'notification_url' => $appUrl . '/api/pagamentos_webhook.php',
        'payer' => [
            'email' => $payerInfo['email'],
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

    mp_log('checkout_preference_start', ['loja_id' => $lojaId, 'external_reference' => $externalReference]);
    mp_log('checkout_preference_payload', ['payload' => $payload]);

    $response = mp_request('POST', '/checkout/preferences', $payload);
    $data = $response['data'] ?? [];

    if (!$response['success']) {
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
            $normalized = mb_strtolower($mpMessage);
            if (str_contains($normalized, 'payer') && str_contains($normalized, 'collector')) {
                $message = 'Use um comprador de teste diferente da conta vendedora Mercado Pago para continuar.';
            } else {
                $message .= ' Detalhe: ' . $mpMessage;
            }
        }

        mp_log('checkout_preference_failed', [
            'message' => $message,
            'status' => $response['status'],
            'request_id' => $response['request_id'] ?? null,
        ]);
        http_response_code($response['status'] ?: 502);
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($data['id'])) {
        mp_log('checkout_preference_invalid', ['response' => $response['raw'] ?? null]);
        throw new Exception('Resposta inválida do Mercado Pago.');
    }

    echo json_encode([
        'success' => true,
        'id' => $data['id'],
        'init_point' => $data['init_point'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    mp_log('checkout_preference_error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
