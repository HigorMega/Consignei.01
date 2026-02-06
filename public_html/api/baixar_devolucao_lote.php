<?php
/**
 * API DE BAIXA DE DEVOLUÇÃO
 * 1. Calcula o que sobrou do lote.
 * 2. Remove essa quantidade do estoque (Produtos).
 * 3. Marca o lote como "finalizado".
 */

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");

require_once '../db/conexao.php';
require_once __DIR__ . "/subscription_helpers.php";

if (session_status() === PHP_SESSION_NONE) session_start();
sh_require_login();
sh_require_active_subscription($pdo);
$loja_id = $_SESSION['loja_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $lote_id = $data['lote_id'] ?? 0;

    if (!$lote_id) {
        echo json_encode(['success' => false, 'error' => 'Lote inválido']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Busca os itens do lote e compara com estoque atual
        // Usa o truque do COLLATE para evitar erros de banco
        $sql = "
            SELECT 
                li.codigo_produto, 
                li.nome,
                COALESCE(p.quantidade, 0) as qtd_atual,
                p.id as produto_id
            FROM lote_itens li
            LEFT JOIN produtos p ON (
                p.codigo_produto COLLATE utf8mb4_unicode_ci = li.codigo_produto COLLATE utf8mb4_unicode_ci 
                AND p.loja_id = :loja_id
            )
            WHERE li.lote_id = :lote_id AND li.status = 'aprovado'
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['loja_id' => $loja_id, 'lote_id' => $lote_id]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $itens_devolvidos = 0;

        foreach ($itens as $item) {
            // A quantidade a devolver é o que tem no estoque agora
            $qtd_devolver = intval($item['qtd_atual']);
            $produto_id = $item['produto_id'];

            if ($produto_id && $qtd_devolver > 0) {
                // Remove do estoque
                $upd = $pdo->prepare("UPDATE produtos SET quantidade = quantidade - ? WHERE id = ?");
                $upd->execute([$qtd_devolver, $produto_id]);
                $itens_devolvidos += $qtd_devolver;
            }
        }

        // 2. Finaliza o Lote
        $pdo->prepare("UPDATE lotes SET status = 'finalizado', data_aprovacao = NOW() WHERE id = ?")->execute([$lote_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "Devolução confirmada! $itens_devolvidos itens removidos do estoque."]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>
