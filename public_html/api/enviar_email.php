<?php
// api/enviar_email.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/bootstrap.php';

// Ajuste o caminho se a pasta PHPMailer estiver em outro lugar
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

function carregarConfigEmail(): array {
    $host = env('SMTP_HOST', 'smtp.hostinger.com');
    $user = env('SMTP_USER');
    $pass = env('SMTP_PASS');
    $from = env('SMTP_FROM', $user ?? '');
    $fromName = env('SMTP_FROM_NAME', 'Consignei App');
    $port = (int) (env('SMTP_PORT', '465') ?? 465);
    $secure = env('SMTP_SECURE', PHPMailer::ENCRYPTION_SMTPS);

    return [
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'from' => $from,
        'from_name' => $fromName,
        'port' => $port,
        'secure' => $secure,
    ];
}

// --- FUNÇÃO 1: E-MAIL DE ATIVAÇÃO (CADASTRO) ---
function enviarEmailAtivacao($emailDestino, $nomeDestino, $token) {
    $mail = new PHPMailer(true);
    $config = carregarConfigEmail();

    try {
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['user'];
        $mail->Password   = $config['pass']; 
        $mail->SMTPSecure = $config['secure'];
        $mail->Port       = $config['port'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($config['from'], $config['from_name']);
        $mail->addAddress($emailDestino, $nomeDestino);

        $mail->isHTML(true);
        $mail->Subject = 'Ative sua conta no Consignei';
        
        // Link de Ativação (Mantive na API pois o ativar.php deve estar na pasta API)
        $link = "https://consigneiapp.com.br/api/ativar.php?token=" . $token;

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
            <h2 style='color: #d4af37; text-align: center;'>Bem-vindo ao Consignei!</h2>
            <p>Olá, <strong>$nomeDestino</strong>.</p>
            <p>Clique abaixo para ativar sua conta:</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='$link' style='background-color: #d4af37; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Ativar Minha Conta</a>
            </div>
        </div>";
        $mail->AltBody = "Ative sua conta: $link";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// --- FUNÇÃO 2: E-MAIL DE RECUPERAÇÃO (CORRIGIDA) ---
function enviarEmailRecuperacao($emailDestino, $token) {
    $mail = new PHPMailer(true);
    $config = carregarConfigEmail();

    try {
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['user'];
        $mail->Password   = $config['pass']; 
        $mail->SMTPSecure = $config['secure'];
        $mail->Port       = $config['port'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($config['from'], $config['from_name']);
        $mail->addAddress($emailDestino);

        $mail->isHTML(true);
        $mail->Subject = 'Redefinir Senha - Consignei';
        
        // --- Link sem /public ---
        $link = "https://consigneiapp.com.br/nova_senha.html?token=" . $token;

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
            <h2 style='color: #d4af37; text-align: center;'>Esqueceu sua senha?</h2>
            <p>Recebemos uma solicitação para redefinir a senha da sua loja.</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='$link' style='background-color: #d4af37; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Redefinir Minha Senha</a>
            </div>
            <p style='font-size: 12px; color: #999; text-align: center;'>Válido por 1 hora.</p>
        </div>";

        $mail->AltBody = "Redefinir senha: $link";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>
