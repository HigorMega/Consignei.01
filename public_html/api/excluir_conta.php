<?php
/**
 * API: EXCLUSÃO COMPLETA DE CONTA
 * Remove dados do banco e apaga a pasta física de imagens
 */

header('Content-Type: application/json; charset=utf-8');
require_once "../db/conexao.php";
session_start();

// 1. Verifica se o usuário está logado
if (!isset($_SESSION['loja_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sessão expirada ou acesso negado.']);
    exit;
}

$loja_id = $_SESSION['loja_id'];
$pasta_vendedor = "../public/uploads/loja_" . $loja_id;

/**
 * Função Recursiva para apagar pastas com ficheiros dentro
 */
function removerDiretorioCompleto($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!removerDiretorioCompleto($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }

    return rmdir($dir);
}

try {
    // Inicia transação para garantir que ou apaga tudo ou não apaga nada
    $pdo->beginTransaction();

    // 2. Apagar dados das tabelas relacionadas (Ordem inversa para respeitar FKs)
    
    // Apaga vendas
    $stmt1 = $pdo->prepare("DELETE FROM vendas WHERE loja_id = ?");
    $stmt1->execute([$loja_id]);

    // Apaga produtos
    $stmt2 = $pdo->prepare("DELETE FROM produtos WHERE loja_id = ?");
    $stmt2->execute([$loja_id]);

    // Apaga configurações da vitrine
    $stmt3 = $pdo->prepare("DELETE FROM configuracoes WHERE loja_id = ?");
    $stmt3->execute([$loja_id]);

    // Apaga categorias
    $stmt4 = $pdo->prepare("DELETE FROM categorias WHERE loja_id = ?");
    $stmt4->execute([$loja_id]);

    // Apaga fornecedores
    $stmt5 = $pdo->prepare("DELETE FROM fornecedores WHERE loja_id = ?");
    $stmt5->execute([$loja_id]);

    // Por fim, apaga a própria loja
    $stmt6 = $pdo->prepare("DELETE FROM lojas WHERE id = ?");
    $stmt6->execute([$loja_id]);

    // 3. Remoção Física das Imagens
    // Isso é vital para não lotar o servidor de "lixo"
    if (is_dir($pasta_vendedor)) {
        removerDiretorioCompleto($pasta_vendedor);
    }

    // Confirma as alterações no banco
    $pdo->commit();
    
    // 4. Destruir a sessão para deslogar o usuário imediatamente
    session_unset();
    session_destroy();

    echo json_encode(['success' => true, 'message' => 'Conta e ficheiros eliminados com sucesso.']);

} catch (Exception $e) {
    // Se algo falhar, cancela as alterações no banco
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false, 
        'error' => 'Erro crítico ao eliminar conta: ' . $e->getMessage()
    ]);
}
?>