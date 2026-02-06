<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: DELETE");

// CORREÇÃO 1: Usa a conexão correta e inicia sessão
require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";
session_start();

sh_require_login();
sh_require_active_subscription($pdo);

$loja_id = $_SESSION['loja_id'];

if (isset($_GET['id'])) {
    $id_venda = $_GET['id'];

    try {
        // Inicia uma transação (Tudo ou nada)
        $pdo->beginTransaction();

        // 1. Busca qual produto foi vendido nesta venda
        // Usamos AND loja_id = ? para garantir que você só apague suas próprias vendas
        $stmt = $pdo->prepare("SELECT produto_id FROM vendas WHERE id = ? AND loja_id = ?");
        $stmt->execute([$id_venda, $loja_id]);
        $venda = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($venda) {
            $prod_id = $venda['produto_id'];

            // 2. Devolve +1 unidade para o estoque do produto
            $stmtEstoque = $pdo->prepare("UPDATE produtos SET quantidade = quantidade + 1 WHERE id = ?");
            $stmtEstoque->execute([$prod_id]);

            // 3. Agora sim, exclui a venda
            $stmtDel = $pdo->prepare("DELETE FROM vendas WHERE id = ?");
            
            if ($stmtDel->execute([$id_venda])) {
                $pdo->commit(); // Confirma as alterações
                echo json_encode(["success" => true]);
            } else {
                $pdo->rollBack(); // Desfaz se der erro
                echo json_encode(["success" => false, "message" => "Erro ao apagar registro."]);
            }
        } else {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => "Venda não encontrada."]);
        }

    } catch (PDOException $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Erro SQL: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["success" => false, "message" => "ID não informado."]);
}
?>
