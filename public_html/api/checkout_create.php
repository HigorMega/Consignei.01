<?php
// api/checkout_create.php
session_start();
ini_set('display_errors', 0);

$redirectMode = isset($_GET['redirect']) && $_GET['redirect'] === '1';
if ($redirectMode && !defined('SH_REDIRECT_MODE')) {
    define('SH_REDIRECT_MODE', true);
}

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";
require_once __DIR__ . "/../lib/mercadopago.php";

function render_checkout_redirect_error(string $message, string $retryUrl): void
{
    header('Content-Type: text/html; charset=UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeRetry = htmlspecialchars($retryUrl, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html>
<html lang=\"pt-BR\">
<head>
  <meta charset=\"UTF-8\" />
  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />
  <title>Pagamento</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 24px; color: #1f2a37; }
    .box { max-width: 520px; margin: 0 auto; }
    .actions { margin-top: 16px; }
    .btn { display: inline-block; padding: 12px 18px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 6px; }
  </style>
</head>
<body>
  <div class=\"box\">
    <h3>Não foi possível iniciar o pagamento.</h3>
    <p>{$safeMessage}</p>
    <div class=\"actions\">
      <a class=\"btn\" href=\"{$safeRetry}\">Tentar novamente</a>
    </div>
  </div>
</body>
</html>";
    exit;
}

try {
    if (!$redirectMode) {
        header('Content-Type: application/json; charset=UTF-8');
    }

    sh_require_login();

    $accessToken = mp_get_access_token();
    if (!$accessToken) {
        if ($redirectMode) {
            mp_log('checkout_redirect_error', ['message' => 'MP_ACCESS_TOKEN não configurado.']);
            http_response_code(500);
            render_checkout_redirect_error(
                'Configuração de pagamento indisponível no momento.',
                '/public/assinatura.html'
            );
        }
        throw new Exception('MP_ACCESS_TOKEN não configurado.');
    }

    $lojaId = (int)$_SESSION['loja_id'];
    $method = strtolower((string) ($_GET['method'] ?? $_POST['method'] ?? 'all'));
    if (!in_array($method, ['all', 'pix', 'boleto'], true)) {
        $method = 'all';
    }
    $lojaFields = ['email', 'nome_loja'];
    if (sh_column_exists($pdo, 'lojas', 'payer_first_name')) {
        $lojaFields[] = 'payer_first_name';
    }
    if (sh_column_exists($pdo, 'lojas', 'payer_last_name')) {
        $lojaFields[] = 'payer_last_name';
    }
    $stmt = $pdo->prepare("SELECT " . implode(', ', $lojaFields) . " FROM lojas WHERE id = ? LIMIT 1");
    $stmt->execute([$lojaId]);
    $loja = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loja) {
        http_response_code(404);
        if ($redirectMode) {
            mp_log('checkout_redirect_error', ['message' => 'Loja não encontrada.']);
            render_checkout_redirect_error(
                'Não encontramos os dados da sua loja.',
                '/public/assinatura.html'
            );
        }
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
    $payerFirstName = trim((string) ($loja['payer_first_name'] ?? ''));
    $payerLastName = trim((string) ($loja['payer_last_name'] ?? ''));
    $nomeLoja = trim((string) ($loja['nome_loja'] ?? ''));

    if (($payerFirstName === '' || $payerLastName === '') && $nomeLoja !== '') {
        $parts = preg_split('/\s+/', $nomeLoja, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!empty($parts)) {
            $fallbackFirst = array_shift($parts) ?: '';
            $fallbackLast = trim(implode(' ', $parts));
            if ($fallbackLast === '') {
                $fallbackLast = '-';
            }
            if ($payerFirstName === '') {
                $payerFirstName = $fallbackFirst;
            }
            if ($payerLastName === '') {
                $payerLastName = $fallbackLast;
            }
        }
    }
    if (mp_is_sandbox() && $payerInfo['source'] !== 'test') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Configure um e-mail de comprador de teste (MP_TEST_PAYER_EMAIL) diferente do vendedor para pagar no modo teste.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payerPayload = [
        'email' => $payerInfo['email'],
    ];
    if ($payerFirstName !== '') {
        $payerPayload['first_name'] = $payerFirstName;
    }
    if ($payerLastName !== '') {
        $payerPayload['last_name'] = $payerLastName;
    }

    $payload = [
        'external_reference' => $externalReference,
        'notification_url' => $appUrl . '/api/pagamentos_webhook.php',
        'payer' => $payerPayload,
        'items' => [
            [
                'id' => 'CONS_MENSAL_30D',
                'title' => 'Consignei App - Mensalidade',
                'description' => 'Acesso ao Consignei App por 30 dias (pagamento avulso)',
                'category_id' => 'services',
                'quantity' => 1,
                'unit_price' => $price,
            ],
        ],
    ];

    mp_log('mp_payment_enrichment', [
        'has_first' => $payerFirstName !== '',
        'has_last' => $payerLastName !== '',
        'item_id' => 'CONS_MENSAL_30D',
        'category_id' => 'services',
    ]);

    if ($method === 'pix') {
        $payload['payment_methods'] = [
            'excluded_payment_types' => [
                ['id' => 'credit_card'],
                ['id' => 'debit_card'],
                ['id' => 'ticket'],
                ['id' => 'atm'],
            ],
        ];
    } elseif ($method === 'boleto') {
        $payload['payment_methods'] = [
            'excluded_payment_types' => [
                ['id' => 'credit_card'],
                ['id' => 'debit_card'],
                ['id' => 'bank_transfer'],
                ['id' => 'atm'],
            ],
        ];
    }

    mp_log('checkout_preference_start', [
        'loja_id' => $lojaId,
        'external_reference' => $externalReference,
        'method' => $method,
    ]);

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
        if ($redirectMode) {
            render_checkout_redirect_error($message, '/public/assinatura.html');
        }
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($data['id'])) {
        mp_log('checkout_preference_invalid', ['response' => $response['raw'] ?? null]);
        if ($redirectMode) {
            http_response_code(502);
            render_checkout_redirect_error('Recebemos uma resposta inválida do Mercado Pago.', '/public/assinatura.html');
        }
        throw new Exception('Resposta inválida do Mercado Pago.');
    }

    if ($redirectMode) {
        $initPoint = (string) ($data['init_point'] ?? '');
        if ($initPoint !== '') {
            header('Location: ' . $initPoint, true, 302);
            exit;
        }
        render_checkout_redirect_error('Link de pagamento indisponível.', '/public/assinatura.html');
    }

    echo json_encode([
        'success' => true,
        'id' => $data['id'],
        'init_point' => $data['init_point'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    mp_log('checkout_preference_error', ['error' => $e->getMessage()]);
    http_response_code(500);
    if ($redirectMode) {
        render_checkout_redirect_error('Erro ao iniciar pagamento.', '/public/assinatura.html');
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
