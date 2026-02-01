<?php
// 1. FORÇAR HTTPS (SEGURANÇA)
// Verifica se o site foi acessado via HTTP comum
if ((!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != 'on') &&
    (!isset($_SERVER['HTTP_X_FORWARDED_PROTO']) || $_SERVER['HTTP_X_FORWARDED_PROTO'] != 'https')) {

    // Se não for seguro, redireciona para a versão HTTPS
    $redirect_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: $redirect_url");
    exit();
}

// 2. ENTREGAR A LANDING SEM /public NA URL
readfile(__DIR__ . '/public/index.html');
exit;
?>
