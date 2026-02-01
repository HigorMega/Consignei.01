<?php
// api/ativar.php
require_once '../db/conexao.php';

$mensagem = "";
$tipo = ""; // success ou error

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // Procura alguém com esse token
    $stmt = $pdo->prepare("SELECT id FROM lojas WHERE token_ativacao = ? LIMIT 1");
    $stmt->execute([$token]);
    $loja = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($loja) {
        // Achou! Ativa a conta e remove o token para não ser usado de novo
        $update = $pdo->prepare("UPDATE lojas SET email_confirmado = 1, token_ativacao = NULL WHERE id = ?");
        $update->execute([$loja['id']]);

        $tipo = "success";
        $titulo = "Conta Ativada!";
        $mensagem = "Sua loja está pronta. Você já pode fazer login e começar a vender.";
    } else {
        $tipo = "error";
        $titulo = "Link Inválido";
        $mensagem = "Este link de ativação já foi usado ou é inválido.";
    }
} else {
    $tipo = "error";
    $titulo = "Erro";
    $mensagem = "Nenhum código de ativação fornecido.";
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ativação - Consignei</title>
    <link rel="icon" type="image/png" href="/img/favicon.png?v=1.2">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f4f6f8; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); text-align: center; max-width: 400px; width: 90%; }
        .icon { font-size: 60px; margin-bottom: 20px; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        h1 { margin: 0 0 10px 0; font-size: 24px; color: #333; }
        p { color: #666; margin-bottom: 30px; line-height: 1.5; }
        .btn { background: #d4af37; color: white; text-decoration: none; padding: 12px 30px; border-radius: 6px; font-weight: 600; display: inline-block; transition: 0.2s; }
        .btn:hover { background: #b8962e; }
    </style>
</head>
<body>
    <div class="card">
        <?php if($tipo == 'success'): ?>
            <i class="ph-fill ph-check-circle icon success"></i>
            <h1><?= $titulo ?></h1>
            <p><?= $mensagem ?></p>
            <a href="../login" class="btn">Ir para o Login</a>
        <?php else: ?>
            <i class="ph-fill ph-x-circle icon error"></i>
            <h1><?= $titulo ?></h1>
            <p><?= $mensagem ?></p>
            <a href="../login" class="btn">Voltar</a>
        <?php endif; ?>
    </div>
</body>
</html>
