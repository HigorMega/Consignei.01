<?php
// api/billing_create_checkout.php
session_start();
ini_set('display_errors', 0);

$redirectMode = isset($_GET['redirect']) && $_GET['redirect'] === '1';
if ($redirectMode && !defined('SH_REDIRECT_MODE')) {
    define('SH_REDIRECT_MODE', true);
}

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";
require_once __DIR__ . "/../lib/mercadopago.php";

function render_billing_redirect_error(string $message, string $retryUrl, string $supportUrl): void
{
    header('Content-Type: text/html; charset=UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeRetry = htmlspecialchars($retryUrl, ENT_QUOTES, 'UTF-8');
    $safeSupport = htmlspecialchars($supportUrl, ENT_QUOTES, 'UTF-8');
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
    .support { margin-top: 12px; display: inline-block; color: #2563eb; }
  </style>
</head>
<body>
  <div class=\"box\">
    <h3>Não foi possível iniciar o pagamento.</h3>
    <p>{$safeMessage}</p>
    <div class=\"actions\">
      <a class=\"btn\" href=\"{$safeRetry}\">Tentar novamente</a>
    </div>
    <a class=\"support\" href=\"{$safeSupport}\" target=\"_blank\" rel=\"noopener\">Falar com o suporte</a>
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
            mp_log('billing_redirect_error', ['message' => 'MP_ACCESS_TOKEN não configurado.']);
            http_response_code(500);
            render_billing_redirect_error(
                'Configuração de pagamento indisponível no momento.',
                '/public/assinatura.html',
                'https://wa.me/5548999530933?text=Oi%21%20Preciso%20de%20ajuda%20para%20assinar.'
            );
        }
        throw new Exception('MP_ACCESS_TOKEN não configurado.');
    }

    $lojaId = (int)$_SESSION['loja_id'];
    if ($redirectMode) {
        mp_log('billing_redirect_mode', ['loja_id' => $lojaId]);
    }
    $stmt = $pdo->prepare("SELECT email FROM lojas WHERE id = ? LIMIT 1");
    $stmt->execute([$lojaId]);
    $loja = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loja) {
        http_response_code(404);
        if ($redirectMode) {
            mp_log('billing_redirect_error', ['message' => 'Loja não encontrada.']);
            render_billing_redirect_error(
                'Não encontramos os dados da sua loja.',
                '/public/assinatura.html',
                'https://wa.me/5548999530933?text=Oi%21%20Preciso%20de%20ajuda%20para%20assinar.'
            );
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Loja não encontrada.']);
        exit;
    }

    if (empty($loja['email'])) {
        if ($redirectMode) {
            mp_log('billing_redirect_error', ['message' => 'E-mail da loja não encontrado para a assinatura.']);
            http_response_code(422);
            render_billing_redirect_error(
                'Não encontramos o e-mail da sua loja para finalizar o pagamento.',
                '/public/assinatura.html',
                'https://wa.me/5548999530933?text=Oi%21%20Preciso%20de%20ajuda%20para%20assinar.'
            );
        }
        throw new Exception('E-mail da loja não encontrado para a assinatura.');
    }

    $reason = env('SUBSCRIPTION_REASON', 'Consignei App - Mensalidade');
    $price = (float) env('SUBSCRIPTION_PRICE', '21.90');
    $appUrl = rtrim(env('APP_URL', ''), '/');
    if (!$appUrl) {
        if ($redirectMode) {
            mp_log('billing_redirect_error', ['message' => 'APP_URL não configurado.']);
            http_response_code(500);
            render_billing_redirect_error(
                'Configuração de pagamento indisponível no momento.',
                '/public/assinatura.html',
                'https://wa.me/5548999530933?text=Oi%21%20Preciso%20de%20ajuda%20para%20assinar.'
            );
        }
        throw new Exception('APP_URL não configurado.');
    }

    $notificationUrl = $appUrl . '/api/webhook_mp.php';
    $backUrl = $appUrl . '/public/assinatura_retorno.html';
    $trialDays = TRIAL_DAYS;
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $trialStart = $trialDays > 0 ? $nowUtc->modify('+' . $trialDays . ' days') : $nowUtc;
    $startDate = mp_calc_start_date($trialDays);
    $assinaturaId = 'sub:' . uniqid();
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
    $externalReference = 'subinv:' . $invoiceId;

    if ($hasExternalReference) {
        $stmtUpdateInvoice = $pdo->prepare("UPDATE invoices SET external_reference = ? WHERE id = ?");
        $stmtUpdateInvoice->execute([$externalReference, $invoiceId]);
    }

    $payerInfo = mp_resolve_payer_email($loja['email']);
    if (mp_is_sandbox() && $payerInfo['source'] !== 'test') {
        http_response_code(422);
        if ($redirectMode) {
            mp_log('billing_redirect_error', [
                'message' => 'Configure um e-mail de comprador de teste (MP_TEST_PAYER_EMAIL) diferente do vendedor para continuar.',
            ]);
            render_billing_redirect_error(
                'Configure um e-mail de comprador de teste diferente do vendedor para continuar.',
                '/public/assinatura.html',
                'https://wa.me/5548999530933?text=Oi%21%20Preciso%20de%20ajuda%20para%20assinar.'
            );
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Configure um e-mail de comprador de teste (MP_TEST_PAYER_EMAIL) diferente do vendedor para continuar.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = [
        'reason' => $reason,
        'external_reference' => $externalReference,
        'payer_email' => $payerInfo['email'],
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

    mp_log('billing_preapproval_start', [
        'loja_id' => $lojaId,
        'external_reference' => $externalReference,
        'trial_days' => $trialDays,
        'start_date' => $startDate,
        'end_date' => null,
    ]);

    $response = mp_request('POST', '/preapproval', $payload);
    $data = $response['data'] ?? [];
    if (!$response['success']) {
        $message = 'Erro ao criar assinatura no Mercado Pago.';
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
                $message = 'Erro de teste: use um comprador de teste diferente do vendedor Mercado Pago.';
            } else {
                $message .= ' Detalhe: ' . $mpMessage;
            }
        }

        mp_log('billing_preapproval_failed', [
            'message' => $message,
            'status' => $response['status'],
            'request_id' => $response['request_id'] ?? null,
        ]);
        http_response_code($response['status'] ?: 502);
        if ($redirectMode) {
            mp_log('billing_redirect_error', ['message' => $message]);
            render_billing_redirect_error(
                $message,
                '/public/assinatura.html',
                'https://wa.me/5548999530933?text=Oi%21%20Preciso%20de%20ajuda%20para%20assinar.'
            );
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($data['id'])) {
        mp_log('billing_preapproval_invalid', ['response' => $response['raw'] ?? null]);
        if ($redirectMode) {
            mp_log('billing_redirect_error', ['message' => 'Resposta inválida do Mercado Pago.']);
            http_response_code(502);
            render_billing_redirect_error(
                'Recebemos uma resposta inválida do Mercado Pago.',
                '/public/assinatura.html',
                'https://wa.me/5548999530933?text=Oi%21%20Preciso%20de%20ajuda%20para%20assinar.'
            );
        }
        throw new Exception('Resposta inválida do Mercado Pago.');
    }

    if (sh_column_exists($pdo, 'invoices', 'mp_preapproval_id')) {
        $stmtUpdateInvoice = $pdo->prepare('UPDATE invoices SET mp_preapproval_id = ? WHERE id = ?');
        $stmtUpdateInvoice->execute([(string) $data['id'], $invoiceId]);
    }

    $updates = [];
    $values = [];
    if (sh_column_exists($pdo, 'lojas', 'subscription_id')) {
        $updates[] = 'subscription_id = ?';
        $values[] = (string) $data['id'];
    }
    if (sh_column_exists($pdo, 'lojas', 'subscription_status')) {
        $updates[] = 'subscription_status = ?';
        $values[] = $trialDays > 0 ? 'trial' : 'pending';
    }
    if (sh_column_exists($pdo, 'lojas', 'trial_until')) {
        $updates[] = 'trial_until = ?';
        $values[] = $trialDays > 0 ? $trialStart->format('Y-m-d H:i:s') : null;
    }
    if (sh_column_exists($pdo, 'lojas', 'paid_until')) {
        $updates[] = 'paid_until = ?';
        $values[] = null;
    }

    if ($updates) {
        $values[] = $lojaId;
        $stmtUpdate = $pdo->prepare("UPDATE lojas SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmtUpdate->execute($values);
    }

    if ($redirectMode && !empty($data['init_point'])) {
        $initPoint = (string) $data['init_point'];
        $parsed = parse_url($initPoint);
        $initPointSafe = '';
        if (!empty($parsed['host'])) {
            $initPointSafe = $parsed['host'] . ($parsed['path'] ?? '');
        }
        mp_log('billing_redirect_to_init_point', [
            'init_point' => $initPointSafe,
        ]);
        header('Location: ' . $initPoint, true, 302);
        header('Content-Type: text/html; charset=UTF-8');
        $safeInitPoint = htmlspecialchars($initPoint, ENT_QUOTES, 'UTF-8');
        echo "<!doctype html>
<html lang=\"pt-BR\">
<head>
  <meta charset=\"UTF-8\" />
  <meta http-equiv=\"refresh\" content=\"0;url={$safeInitPoint}\" />
  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />
  <title>Redirecionando</title>
</head>
<body>
  <p>Redirecionando... Se não abrir, <a href=\"{$safeInitPoint}\">clique aqui</a>.</p>
</body>
</html>";
        exit;
    }

    if ($redirectMode) {
        mp_log('billing_redirect_error', ['message' => 'init_point não encontrado no retorno do Mercado Pago.']);
        http_response_code(502);
        render_billing_redirect_error(
            'Não foi possível encontrar o link de pagamento.',
            '/public/assinatura.html',
            'https://wa.me/5548999530933?text=Oi%21%20Preciso%20de%20ajuda%20para%20assinar.'
        );
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'checkout_url' => $data['init_point'] ?? null,
        'init_point' => $data['init_point'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    mp_log('billing_preapproval_error', ['error' => $e->getMessage()]);
    http_response_code(500);
    if ($redirectMode) {
        mp_log('billing_redirect_error', ['message' => $e->getMessage()]);
        render_billing_redirect_error(
            'Não foi possível iniciar o pagamento.',
            '/public/assinatura.html',
            'https://wa.me/5548999530933?text=Oi%21%20Preciso%20de%20ajuda%20para%20assinar.'
        );
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
