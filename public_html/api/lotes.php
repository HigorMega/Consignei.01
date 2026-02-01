<?php
// ARQUIVO: api/lotes.php
// Este arquivo gerencia a CRIAÇÃO e LISTAGEM das remessas (lotes)

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");

require_once '../db/conexao.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(200); exit; }

// Verifica se está logado
if (!isset($_SESSION['loja_id'])) {
    // Se não tiver sessão (ex: teste local), tenta usar 1 provisoriamente
    $loja_id = 1; 
} else {
    $loja_id = $_SESSION['loja_id'];
}

// 1. LISTAR LOTES (GET)
if ($method === 'GET') {
    try {
        $sql = "SELECT l.*, f.nome AS nome_empresa, f.contato 
                FROM lotes l 
                LEFT JOIN fornecedores f ON l.fornecedor_id = f.id 
                WHERE l.loja_id = :loja_id 
                ORDER BY l.data_entrada DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':loja_id' => $loja_id]);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($lotes);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao listar: ' . $e->getMessage()]);
    }
}

// 2. CRIAR NOVO LOTE (POST)
elseif ($method === 'POST') {
    $dados = json_decode(file_get_contents("php://input"), true);

    if (!isset($dados['fornecedor_id']) || empty($dados['fornecedor_id'])) {
        echo json_encode(['success' => false, 'error' => 'Selecione um fornecedor.']);
        exit;
    }

    try {
        $sql = "INSERT INTO lotes (loja_id, fornecedor_id, observacao, status, data_entrada) 
                VALUES (:loja_id, :forn, :obs, 'rascunho', NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':loja_id' => $loja_id,
            ':forn' => $dados['fornecedor_id'],
            ':obs'  => $dados['observacao'] ?? ''
        ]);

        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro SQL: ' . $e->getMessage()]);
    }
}

// 3. DELETAR LOTE (DELETE)
elseif ($method === 'DELETE') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($id > 0) {
        try {
            // Verifica se o lote é da minha loja
            $check = $pdo->prepare("SELECT id FROM lotes WHERE id = :id AND loja_id = :loja_id");
            $check->execute([':id' => $id, ':loja_id' => $loja_id]);
            
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Lote não encontrado ou permissão negada.']);
                exit;
            }

            // Remove imagens físicas dos itens desse lote
            $stmtImagens = $pdo->prepare("SELECT foto_temp FROM lote_itens WHERE lote_id = :id");
            $stmtImagens->execute([':id' => $id]);
            $imagens = $stmtImagens->fetchAll(PDO::FETCH_ASSOC);

            foreach ($imagens as $img) {
                if ($img['foto_temp'] && $img['foto_temp'] != 'manual_placeholder.png') {
                    $caminho = "../uploads/" . $img['foto_temp'];
                    if (file_exists($caminho)) unlink($caminho);
                }
            }

            // Apaga itens e o lote
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM lote_itens WHERE lote_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM lotes WHERE id = ?")->execute([$id]);
            $pdo->commit();

            echo json_encode(['success' => true]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
?>