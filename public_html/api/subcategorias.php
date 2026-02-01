<?php
session_start();
header('Content-Type: application/json');
include "../db/conexao.php";

if (!isset($_SESSION['loja_id'])) {
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

$loja_id = $_SESSION['loja_id'];
$method = $_SERVER['REQUEST_METHOD'];

// LISTAR SUBCATEGORIAS (Com filtro opcional por categoria pai)
if ($method === 'GET') {
    $catId = $_GET['categoria_id'] ?? null;
    
    if($catId) {
        $stmt = $pdo->prepare("SELECT * FROM subcategorias WHERE loja_id = ? AND categoria_id = ? ORDER BY nome ASC");
        $stmt->execute([$loja_id, $catId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM subcategorias WHERE loja_id = ? ORDER BY id DESC");
        $stmt->execute([$loja_id]);
    }
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ADICIONAR SUBCATEGORIA
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $categoria_id = $data['categoria_id'] ?? null;
    $nome = $data['nome'] ?? '';

    if ($nome && $categoria_id) {
        $stmt = $pdo->prepare("INSERT INTO subcategorias (loja_id, categoria_id, nome) VALUES (?, ?, ?)");
        if ($stmt->execute([$loja_id, $categoria_id, $nome])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erro ao salvar']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
    }
}

// EXCLUIR SUBCATEGORIA
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? 0;
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM subcategorias WHERE id = ? AND loja_id = ?");
        $stmt->execute([$id, $loja_id]);
        echo json_encode(['success' => true]);
    }
}
?>