<?php
// Arquivo: api/corrigir_cadastro_inicial.php
header('Content-Type: application/json');
require_once "../db/conexao.php";
require_once "enviar_email.php";

// Recebe os dados
$input = json_decode(file_get_contents('php://input'), true);
$id_loja = $input['id_loja'] ?? '';
$novo_email = $input['novo_email'] ?? '';

if (empty($id_loja) || empty($novo_email)) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos para correção.']);
    exit;
}

try {
    // 1. Verifica se a conta existe e ainda não foi confirmada
    $stmt = $pdo->prepare("SELECT nome_loja, token_ativacao FROM lojas WHERE id = ? AND email_confirmado = 0");
    $stmt->execute([$id_loja]);
    $loja = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loja) {
        echo json_encode(['success' => false, 'message' => 'Não foi possível localizar o cadastro pendente.']);
        exit;
    }

    // 2. Verifica se o NOVO e-mail já não está em uso por outra conta ativa
    $checkEmail = $pdo->prepare("SELECT id FROM lojas WHERE email = ? AND id != ?");
    $checkEmail->execute([$novo_email, $id_loja]);
    if ($checkEmail->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Este novo e-mail já está em uso por outra conta.']);
        exit;
    }

    // 3. Atualiza o e-mail no banco de dados
    $update = $pdo->prepare("UPDATE lojas SET email = ? WHERE id = ?");
    if ($update->execute([$novo_email, $id_loja])) {
        
        // 4. Reenvia o e-mail de ativação para o novo endereço correto
        // Usamos o nome da loja como fallback para o destinatário
        $enviou = enviarEmailAtivacao($novo_email, $loja['nome_loja'], $loja['token_ativacao']);
        
        if ($enviou) {
            echo json_encode(['success' => true, 'message' => 'E-mail corrigido e reenviado com sucesso!']);
        } else {
            echo json_encode(['success' => true, 'message' => 'E-mail corrigido no sistema, mas houve erro no envio.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar o endereço de e-mail.']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro de banco de dados: ' . $e->getMessage()]);
}
?>