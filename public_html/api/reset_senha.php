<?php
// api/reset_senha.php
header('Content-Type: application/json');
require_once "../db/conexao.php";

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';
$nova_senha = $input['nova_senha'] ?? '';

if (empty($token) || empty($nova_senha)) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
    exit;
}

try {
    // 1. Busca usuário pelo Token e verifica se não expirou
    $stmt = $pdo->prepare("SELECT id, senha FROM lojas WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Este link é inválido ou já expirou.']);
        exit;
    }

    // 2. VERIFICAÇÃO DE SEGURANÇA: A senha nova é igual à antiga?
    if (password_verify($nova_senha, $user['senha'])) {
        echo json_encode(['success' => false, 'message' => 'Por segurança, a nova senha não pode ser igual à anterior.']);
        exit;
    }

    // 3. Tudo certo! Hash da nova senha
    $novoHash = password_hash($nova_senha, PASSWORD_DEFAULT);

    // 4. Atualiza a senha e LIMPA o token (para o link não funcionar mais)
    $update = $pdo->prepare("UPDATE lojas SET senha = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
    
    if ($update->execute([$novoHash, $user['id']])) {
        echo json_encode(['success' => true, 'message' => 'Senha alterada com sucesso!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar nova senha.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
}
?>