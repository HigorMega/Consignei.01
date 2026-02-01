<?php
// Arquivo: db/conexao.php

require_once __DIR__ . '/../api/bootstrap.php';

// DADOS DO BANCO (use variáveis de ambiente no .env)
$host = env('DB_HOST', 'localhost');
$db   = env('DB_NAME');
$user = env('DB_USER');
$pass = env('DB_PASS');

$charset = 'utf8mb4';

$missing = [];
if (!$db) { $missing[] = 'DB_NAME'; }
if (!$user) { $missing[] = 'DB_USER'; }
if (!$pass) { $missing[] = 'DB_PASS'; }
if ($missing) {
    throw new RuntimeException('Variáveis de ambiente ausentes: ' . implode(', ', $missing));
}

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Em produção, não mostre o erro real para o usuário
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>
