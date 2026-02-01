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

// Ler o input JSON para métodos POST e PUT
$inputData = json_decode(file_get_contents('php://input'), true);

// LISTAR CATEGORIAS
if ($method === 'GET') {
    // Buscamos todas. O Frontend deve organizar quem é filho de quem baseado no parent_id
    $stmt = $pdo->prepare("SELECT * FROM categorias WHERE loja_id = ? ORDER BY id DESC");
    $stmt->execute([$loja_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ADICIONAR CATEGORIA (OU SUBCATEGORIA)
if ($method === 'POST') {
    $nome = $inputData['nome'] ?? '';
    // Recebe o ID do pai se for uma subcategoria, ou NULL se for principal
    $parent_id = isset($inputData['parent_id']) && !empty($inputData['parent_id']) ? $inputData['parent_id'] : null;

    if ($nome) {
        $stmt = $pdo->prepare("INSERT INTO categorias (loja_id, nome, parent_id) VALUES (?, ?, ?)");
        if ($stmt->execute([$loja_id, $nome, $parent_id])) {
            // Retornamos o ID criado para facilitar o redirecionamento no frontend
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Nome obrigatório']);
    }
}

// EDITAR CATEGORIA (Novo bloco para o botão Editar)
if ($method === 'PUT') {
    $id = $inputData['id'] ?? 0;
    $nome = $inputData['nome'] ?? '';
    // Permite alterar o pai também na edição
    $parent_id = isset($inputData['parent_id']) && !empty($inputData['parent_id']) ? $inputData['parent_id'] : null;

    if ($id && $nome) {
        $stmt = $pdo->prepare("UPDATE categorias SET nome = ?, parent_id = ? WHERE id = ? AND loja_id = ?");
        if ($stmt->execute([$nome, $parent_id, $id, $loja_id])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    }
}

// EXCLUIR CATEGORIA
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? 0;
    if ($id) {
        // Opcional: Aqui você pode decidir se deleta as subcategorias junto ou se elas ficam "órfãs"
        // Por enquanto, deletamos apenas a categoria específica
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = ? AND loja_id = ?");
        
        if($stmt->execute([$id, $loja_id])) {
             echo json_encode(['success' => true]);
        } else {
             echo json_encode(['success' => false]);
        }
    }
}
?>