<?php
// api/reenviar_email.php
session_start();
header('Content-Type: application/json');

// Desativa erros para não sujar o JSON
error_reporting(0);
ini_set('display_errors', 0);

require_once '../db/conexao.php';
require_once 'enviar_email.php'; // Chama o arquivo que configura o PHPMailer

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $email = $data['email'] ?? '';

    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'E-mail necessário para o reenvio.']);
        exit;
    }

    try {
        // 1. Busca os dados do usuário no banco
        $stmt = $pdo->prepare("SELECT nome_loja, email_confirmado, token_ativacao FROM lojas WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // 2. Verifica se já está ativado
            if ($user['email_confirmado'] == 1) {
                echo json_encode(['success' => false, 'message' => 'Esta conta já está ativada. Você já pode fazer login.']);
                exit;
            }

            // 3. Reenvia o e-mail usando o token que já está salvo no banco
            $nome = $user['nome_loja'];
            $token = $user['token_ativacao'];
            
            // Caso o token tenha sumido por algum motivo, gera um novo
            if (empty($token)) {
                $token = bin2hex(random_bytes(32));
                $upd = $pdo->prepare("UPDATE lojas SET token_ativacao = ? WHERE email = ?");
                $upd->execute([$token, $email]);
            }

            $enviou = enviarEmailAtivacao($email, $nome, $token);
            
            if ($enviou) {
                echo json_encode(['success' => true, 'message' => 'E-mail reenviado com sucesso! Verifique sua caixa de entrada e Spam.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Erro técnico ao enviar o e-mail. Tente novamente mais tarde.']);
            }
        } else {
            // Se o e-mail não existe no banco
            echo json_encode(['success' => false, 'message' => 'Este e-mail não consta em nossa base de dados.']);
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro interno no servidor.']);
    }
}
?>