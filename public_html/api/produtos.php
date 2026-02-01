<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
require_once "../db/conexao.php";

/**
 * REGRA DE SEGURANÇA:
 * - Se estiver logado, SEMPRE usa loja_id da sessão (ignora GET).
 * - Se não estiver logado, permite leitura pública via ?loja_id= (somente READ).
 */

$loja_id = 0;

// Se logado (painel)
if (isset($_SESSION['logado']) && $_SESSION['logado'] === true && isset($_SESSION['loja_id'])) {
    $loja_id = (int)$_SESSION['loja_id'];
} else {
    // Leitura pública (se você realmente precisar)
    if (isset($_GET['loja_id'])) {
        $loja_id = (int)$_GET['loja_id'];
    }
}

if ($loja_id <= 0) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE loja_id = ? ORDER BY id DESC");
    $stmt->execute([$loja_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($produtos, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
}
?>