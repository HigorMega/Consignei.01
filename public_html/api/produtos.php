<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";

sh_require_login();
sh_require_active_subscription($pdo);

$loja_id = (int)$_SESSION['loja_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE loja_id = ? ORDER BY id DESC");
    $stmt->execute([$loja_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($produtos, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
}
?>
