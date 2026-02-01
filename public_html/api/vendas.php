<?php
// Arquivo: api/vendas.php
header('Content-Type: application/json');
require_once "../db/conexao.php";
session_start();

if (!isset($_SESSION['loja_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit;
}
$loja_id = $_SESSION['loja_id'];

// --- GET: LISTAR VENDAS DO MÊS ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Pega as últimas 100 vendas, ordenadas da mais recente para a antiga
        $sql = "SELECT * FROM vendas WHERE loja_id = ? ORDER BY data_venda DESC LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$loja_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        echo json_encode([]);
    }
    exit;
}

// --- POST: REGISTRAR NOVA VENDA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $prod_id = $data['produto_id'] ?? 0;

    try {
        $pdo->beginTransaction();

        // 1. Busca dados do produto para calcular lucro
        $stmt = $pdo->prepare("SELECT nome, codigo_produto, preco_custo, preco FROM produtos WHERE id = ? AND loja_id = ?");
        $stmt->execute([$prod_id, $loja_id]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$produto) throw new Exception("Produto não encontrado.");

        // 2. Diminui estoque
        $stmtUpd = $pdo->prepare("UPDATE produtos SET quantidade = quantidade - 1 WHERE id = ?");
        $stmtUpd->execute([$prod_id]);

        // 3. Registra a venda
        $lucro = $produto['preco'] - $produto['preco_custo'];
        $sqlVenda = "INSERT INTO vendas (loja_id, produto_id, codigo_produto, nome_produto, preco_custo, preco_venda, lucro) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmtVenda = $pdo->prepare($sqlVenda);
        $stmtVenda->execute([
            $loja_id, 
            $prod_id, 
            $produto['codigo_produto'], 
            $produto['nome'], 
            $produto['preco_custo'], 
            $produto['preco'], 
            $lucro
        ]);

        $pdo->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}