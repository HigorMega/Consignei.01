<?php
// api/assinatura_status.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";

sh_require_login();

$lojaId = (int)$_SESSION['loja_id'];
$snapshot = sh_get_subscription_snapshot($pdo, $lojaId);

echo json_encode($snapshot, JSON_UNESCAPED_UNICODE);
?>
