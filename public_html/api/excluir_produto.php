<?php
// api/excluir_produto.php - Seguro (valida loja da sessão)
session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once '../db/conexao.php';

if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true || empty($_SESSION['loja_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado.']);
    exit;
}

$loja_id = (int)$_SESSION['loja_id'];

// Aceita GET (compatibilidade) ou POST (preferível)
$id = 0;
if (isset($_POST['id'])) {
    $id = (int)$_POST['id'];
} elseif (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
}

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

try {
    // 1) Confirma que o produto pertence à loja logada e pega a imagem
    $stmt = $pdo->prepare("SELECT imagem FROM produtos WHERE id = ? AND loja_id = ? LIMIT 1");
    $stmt->execute([$id, $loja_id]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sem permissão ou produto não encontrado.']);
        exit;
    }

    // 2) Remove arquivo físico (se for arquivo local)
    if (!empty($produto['imagem']) && strpos($produto['imagem'], 'http') === false) {
        $arquivo = basename($produto['imagem']); // evita path traversal
        $caminho = __DIR__ . "/../public/uploads/" . $arquivo;

        if (is_file($caminho)) {
            @unlink($caminho);
        }
    }

    // 3) Deleta no banco garantindo loja_id
    $stmtDel = $pdo->prepare("DELETE FROM produtos WHERE id = ? AND loja_id = ?");
    $stmtDel->execute([$id, $loja_id]);

    if ($stmtDel->rowCount() === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sem permissão ou produto não encontrado.']);
        exit;
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno.'], JSON_UNESCAPED_UNICODE);
}
?>