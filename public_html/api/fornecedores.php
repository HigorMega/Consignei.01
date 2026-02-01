<?php
session_start();
header('Content-Type: application/json');
// Permite métodos PUT e DELETE que não são padrão em alguns servidores
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");

include "../db/conexao.php";

// Verifica se está logado
if (!isset($_SESSION['loja_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

$loja_id = $_SESSION['loja_id'];
$method = $_SERVER['REQUEST_METHOD'];

// 1. LISTAR (GET)
if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE loja_id = ? ORDER BY nome ASC");
    $stmt->execute([$loja_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} 

// 2. CRIAR (POST)
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if(isset($data['nome'])) {
        $stmt = $pdo->prepare("INSERT INTO fornecedores (loja_id, nome, contato) VALUES (?, ?, ?)");
        $stmt->execute([$loja_id, $data['nome'], $data['contato'] ?? '']);
        echo json_encode(['success' => true]);
    }
}

// 3. EDITAR (PUT) - ADICIONADO AGORA
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['id']) && isset($data['nome'])) {
        $stmt = $pdo->prepare("UPDATE fornecedores SET nome = ?, contato = ? WHERE id = ? AND loja_id = ?");
        $stmt->execute([$data['nome'], $data['contato'] ?? '', $data['id'], $loja_id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    }
}

// 4. EXCLUIR (DELETE) - ADICIONADO AGORA
if ($method === 'DELETE') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM fornecedores WHERE id = ? AND loja_id = ?");
        $stmt->execute([$_GET['id'], $loja_id]);
        echo json_encode(['success' => true]);
    }
}
?>