<?php
// api/solicitar_recuperacao.php
header('Content-Type: application/json');
require_once "../db/conexao.php";
require_once "enviar_email.php";

$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Digite o e-mail.']);
    exit;
}

try {
    // 1. Verifica se o e-mail existe
    $stmt = $pdo->prepare("SELECT id FROM lojas WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() > 0) {
        // 2. Gera Token e Validade (1 hora a partir de agora)
        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // 3. Salva no banco
        $update = $pdo->prepare("UPDATE lojas SET reset_token = ?, reset_expires = ? WHERE email = ?");
        $update->execute([$token, $expira, $email]);

        // 4. Envia o e-mail
        enviarEmailRecuperacao($email, $token);
    }

    // POR SEGURANÇA: Sempre dizemos que "Se existir, enviamos". 
    // Assim hackers não descobrem quais e-mails estão cadastrados testando um por um.
    echo json_encode(['success' => true, 'message' => 'Se o e-mail estiver cadastrado, você receberá um link em instantes.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
?>