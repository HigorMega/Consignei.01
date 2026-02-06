<?php
/**
 * API DE DEVOLUÇÃO DE MERCADORIA
 * Subtrai do estoque sem gerar venda (retorno ao fornecedor)
 */

include_once '../db/conexao.php';
require_once __DIR__ . "/subscription_helpers.php";
session_start();

header('Content-Type: application/json; charset=utf-8');

sh_require_login();
sh_require_active_subscription($pdo);

$loja_id = $_SESSION['loja_id'];

// Recebe JSON do frontend (melhor que POST puro para APIs modernas)
$input = json_decode(file_get_contents('php://input'), true);

$produto_id = isset($input['produto_id']) ? (int)$input['produto_id'] : 0;
$qtd_devolver = isset($input['quantidade']) ? (int)$input['quantidade'] : 0;

if (!$produto_id || $qtd_devolver <= 0) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos (ID ou Quantidade).']);
    exit;
}

// 1. Verifica se o produto existe e pertence à loja
$stmt = $conn->prepare("SELECT id, nome, quantidade FROM produtos WHERE id = ? AND loja_id = ?");
$stmt->bind_param("ii", $produto_id, $loja_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Produto não encontrado.']);
    exit;
}

$produto = $result->fetch_assoc();
$qtd_atual = (int)$produto['quantidade'];

// 2. Verifica se há estoque suficiente
if ($qtd_atual < $qtd_devolver) {
    echo json_encode([
        'success' => false, 
        'message' => "Erro: Você tem apenas $qtd_atual und em estoque, não pode devolver $qtd_devolver."
    ]);
    exit;
}

// 3. Executa a baixa no estoque
$nova_qtd = $qtd_atual - $qtd_devolver;
$status = ($nova_qtd > 0) ? 'ativo' : 'inativo'; // Opcional: inativar se zerar

$upd = $conn->prepare("UPDATE produtos SET quantidade = ?, status = ? WHERE id = ?");
$upd->bind_param("isi", $nova_qtd, $status, $produto_id);

if ($upd->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => "Devolução registrada! Estoque atual: $nova_qtd."
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Erro no banco de dados.']);
}
?>
