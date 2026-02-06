<?php
/**
 * API APROVAR LOTE (CORREÇÃO FINAL v4)
 * - Removeu datas do INSERT em 'produtos' (evita erro 'Unknown column').
 * - Grava 'data_aprovacao' na tabela 'lotes' (onde ela existe).
 */

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../db/conexao.php';
require_once __DIR__ . "/subscription_helpers.php";

if (session_status() === PHP_SESSION_NONE) session_start();

sh_require_login();
sh_require_active_subscription($pdo);
$loja_id = $_SESSION['loja_id'];

try {
    $dados = json_decode(file_get_contents("php://input"), true);
    $lote_id = $dados['lote_id'] ?? 0;

    if (!$lote_id) throw new Exception("ID do lote não informado.");

    // 1. Verifica Lote
    $stmt = $pdo->prepare("SELECT * FROM lotes WHERE id = ? AND loja_id = ?");
    $stmt->execute([$lote_id, $loja_id]);
    $lote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lote) throw new Exception("Lote não encontrado.");
    if ($lote['status'] === 'ativo' || $lote['status'] === 'finalizado') {
        throw new Exception("Lote já aprovado.");
    }

    // 2. Busca Itens
    $stmtItens = $pdo->prepare("SELECT * FROM lote_itens WHERE lote_id = ?");
    $stmtItens->execute([$lote_id]);
    $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

    if (count($itens) === 0) throw new Exception("Lote vazio.");

    $pdo->beginTransaction();

    // 3. Insere em Produtos (SEM DATA para evitar erro, o banco usa padrão se tiver)
    $sqlInsert = "INSERT INTO produtos 
        (loja_id, codigo_produto, nome, categoria, preco_custo, preco, quantidade, fornecedor_id, imagem, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo')";
    
    $stmtInsert = $pdo->prepare($sqlInsert);
    $stmtSelectProduto = $pdo->prepare("SELECT id, quantidade FROM produtos WHERE loja_id = ? AND codigo_produto = ?");
    $stmtUpdateProduto = $pdo->prepare(
        "UPDATE produtos SET quantidade = quantidade + ?, preco_custo = COALESCE(?, preco_custo), preco = COALESCE(?, preco) WHERE id = ?"
    );
    $stmtCat = $pdo->prepare("SELECT nome FROM categorias WHERE id = ?");

    $count = 0;
    foreach ($itens as $item) {
        $nomeCategoria = 'Geral';
        if (!empty($item['categoria_id'])) {
            $stmtCat->execute([$item['categoria_id']]);
            $cat = $stmtCat->fetch(PDO::FETCH_ASSOC);
            if ($cat) $nomeCategoria = $cat['nome'];
        }

        $stmtSelectProduto->execute([$loja_id, $item['codigo_produto']]);
        $produtoExistente = $stmtSelectProduto->fetch(PDO::FETCH_ASSOC);

        if ($produtoExistente) {
            $stmtUpdateProduto->execute([
                $item['quantidade'],
                $item['preco_custo'],
                $item['preco_venda'],
                $produtoExistente['id']
            ]);
        } else {
            $stmtInsert->execute([
                $loja_id,
                $item['codigo_produto'],
                $item['nome'],
                $nomeCategoria,          
                $item['preco_custo'],
                $item['preco_venda'],    
                $item['quantidade'],
                $lote['fornecedor_id'],  
                $item['foto_temp'] ?? null 
            ]);
        }
        $count++;
    }

    // 4. Atualiza o LOTE (Aqui sim usamos data_aprovacao)
    // Se sua tabela tiver 'data_entrada' em vez de 'data_aprovacao', troque o nome abaixo.
    // Baseado no seu pedido, estou usando 'data_aprovacao'.
    $stmtUpdate = $pdo->prepare("UPDATE lotes SET status = 'ativo', data_aprovacao = NOW() WHERE id = ?");
    
    // CASO DÊ ERRO NOVAMENTE DIZENDO QUE 'data_aprovacao' NÃO EXISTE EM 'LOTES', 
    // TROQUE PARA: data_entrada = NOW()
    // Mas vou manter data_aprovacao conforme você informou.
    
    $stmtUpdate->execute([$lote_id]);

    // 5. Atualiza itens
    $pdo->prepare("UPDATE lote_itens SET status = 'aprovado' WHERE lote_id = ?")->execute([$lote_id]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => "Sucesso! $count produtos no estoque."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
