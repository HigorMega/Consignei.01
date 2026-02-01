<?php
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $isSecure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');

$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookieParams['path'] ?? '/',
    'domain' => $cookieParams['domain'] ?? '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();
header('Content-Type: application/json');
include "../db/conexao.php";

// 1. Tenta ler JSON (padrão novo) ou POST (padrão antigo)
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? $_POST['email'] ?? '';
$senha = $input['senha'] ?? $_POST['senha'] ?? '';

if (empty($email) || empty($senha)) {
    echo json_encode(['success' => false, 'message' => 'Preencha e-mail e senha!']);
    exit;
}

// 2. Busca o usuário no banco (Adicionei email_confirmado na busca para garantir)
$stmt = $pdo->prepare("SELECT * FROM lojas WHERE email = ?");
$stmt->execute([$email]);
$loja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loja) {
    echo json_encode(['success' => false, 'message' => 'E-mail não encontrado.']);
    exit;
}

// 3. Verificação de Senha (Híbrida: Suporta senhas antigas e novas)
$senhaValida = false;

// Tenta verificar como HASH (Seguro - Novos cadastros)
if (password_verify($senha, $loja['senha'])) {
    $senhaValida = true;
} 
// Tenta verificar como TEXTO PURO (Legado - Cadastros antigos)
elseif ($senha === $loja['senha']) {
    $senhaValida = true;
    // Atualiza a senha para hash seguro no banco
    $novoHash = password_hash($senha, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE lojas SET senha = ? WHERE id = ?");
    $upd->execute([$novoHash, $loja['id']]);
}

if ($senhaValida) {
    
    // --- [NOVO] TRAVA DE SEGURANÇA: E-MAIL CONFIRMADO? ---
    // Se o campo for 0, bloqueia. Se for 1, libera.
    if (isset($loja['email_confirmado']) && $loja['email_confirmado'] == 0) {
        echo json_encode(['success' => false, 'message' => 'Confirme seu e-mail antes de entrar.']);
        exit;
    }
    // -----------------------------------------------------

    // 4. Cria a Sessão (Importante para o Dashboard abrir)
    session_regenerate_id(true);
    $_SESSION['loja_id'] = $loja['id'];
    $_SESSION['nome'] = $loja['nome_loja'];
    $_SESSION['email'] = $loja['email'];
    $_SESSION['logado'] = true;

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Senha incorreta.']);
}
?>
