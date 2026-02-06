<?php
// Arquivo: api/register.php
header('Content-Type: application/json; charset=UTF-8');

// Desativa exibição de erros na tela para não quebrar o JSON
error_reporting(0);
ini_set('display_errors', 0);

require_once "../db/conexao.php";
require_once "enviar_email.php";

/* Helpers */
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function slugify(string $text): string {
    $text = trim($text);
    if ($text === '') return '';
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) $text = $converted;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text;
}

function ensureUniqueSlug(PDO $pdo, string $baseSlug, int $maxTries = 50): string {
    $slug = $baseSlug !== '' ? $baseSlug : ('loja-' . date('YmdHis'));
    $try = 0;

    while ($try < $maxTries) {
        $candidate = $try === 0 ? $slug : ($slug . '-' . ($try + 1));
        $stmt = $pdo->prepare("SELECT id FROM lojas WHERE slug = ? LIMIT 1");
        $stmt->execute([$candidate]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            return $candidate;
        }
        $try++;
    }
    // fallback final
    return $slug . '-' . bin2hex(random_bytes(2));
}

// Recebe dados via JSON ou POST
$input = json_decode(file_get_contents('php://input'), true);
$nome_loja = $input['nome_loja'] ?? $_POST['nome_loja'] ?? '';
$nome_resp = $input['nome_responsavel'] ?? $_POST['nome_responsavel'] ?? $nome_loja;
$email     = $input['email'] ?? $_POST['email'] ?? '';
$senha     = $input['senha'] ?? $_POST['senha'] ?? '';

if (empty($nome_loja) || empty($email) || empty($senha)) {
    echo json_encode(['success' => false, 'message' => 'Preencha todos os campos obrigatórios.']);
    exit;
}

try {
    // 1) E-mail único
    $stmtCheck = $pdo->prepare("SELECT id FROM lojas WHERE email = ? LIMIT 1");
    $stmtCheck->execute([$email]);
    if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['success' => false, 'message' => 'Este e-mail já está cadastrado.']);
        exit;
    }

    // 2) Segurança
    $hashSenha = password_hash($senha, PASSWORD_DEFAULT);
    $token     = bin2hex(random_bytes(32));

    // 3) Slug (para URL bonita da vitrine)
    $hasSlugColumn = columnExists($pdo, 'lojas', 'slug');
    $slug = null;

    if ($hasSlugColumn) {
        $baseSlug = slugify($nome_loja);
        $slug = ensureUniqueSlug($pdo, $baseSlug);
    }

    // 4) Insere loja
    if ($hasSlugColumn) {
        $sql = "INSERT INTO lojas (nome_loja, slug, email, senha, email_confirmado, token_ativacao)
                VALUES (?, ?, ?, ?, 0, ?)";
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([$nome_loja, $slug, $email, $hashSenha, $token]);
    } else {
        // Fallback caso o banco ainda não tenha a coluna slug
        $sql = "INSERT INTO lojas (nome_loja, email, senha, email_confirmado, token_ativacao)
                VALUES (?, ?, ?, 0, ?)";
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([$nome_loja, $email, $hashSenha, $token]);
    }

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar os dados no banco.']);
        exit;
    }

    $novoId = (int)$pdo->lastInsertId();

    // 5) Configurações (não trava se der erro)
    try {
        $sqlConfig = "INSERT INTO configuracoes (loja_id, nome_loja, nome_vendedor) VALUES (?, ?, ?)";
        $stmtConfig = $pdo->prepare($sqlConfig);
        $stmtConfig->execute([$novoId, $nome_loja, $nome_resp]);
    } catch (Exception $e) {
        // Ignora
    }

    // 6) Trial de assinatura (se colunas existirem)
    $hasTrialUntilColumn = columnExists($pdo, 'lojas', 'trial_until');
    $hasAssinaturaStatusColumn = columnExists($pdo, 'lojas', 'assinatura_status');
    $hasSubscriptionStatusColumn = columnExists($pdo, 'lojas', 'subscription_status');

    if ($hasTrialUntilColumn || $hasAssinaturaStatusColumn || $hasSubscriptionStatusColumn) {
        $updates = [];
        $values = [];

        if ($hasTrialUntilColumn) {
            $trialUntil = (new DateTimeImmutable('now'))->modify('+5 days')->format('Y-m-d H:i:s');
            $updates[] = "trial_until = ?";
            $values[] = $trialUntil;
        }

        if ($hasAssinaturaStatusColumn) {
            $updates[] = "assinatura_status = ?";
            $values[] = "trial";
        }

        if ($hasSubscriptionStatusColumn) {
            $updates[] = "subscription_status = ?";
            $values[] = "trial";
        }

        if ($updates) {
            $values[] = $novoId;
            $stmtTrial = $pdo->prepare("UPDATE lojas SET " . implode(', ', $updates) . " WHERE id = ?");
            $stmtTrial->execute($values);
        }
    }

    // 7) E-mail de ativação
    $enviou = enviarEmailAtivacao($email, $nome_resp, $token);

    $payload = [
        'success' => true,
        'message' => 'Cadastro realizado!',
        'dados' => [
            'id' => $novoId,
            'email' => $email
        ]
    ];

    if ($hasSlugColumn) {
        $payload['dados']['slug'] = $slug;
    } else {
        $payload['warning'] = 'Sua tabela lojas ainda não tem a coluna slug. Para ativar URL bonita, rode: ALTER TABLE lojas ADD COLUMN slug VARCHAR(120) UNIQUE;';
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro no banco: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}
?>
