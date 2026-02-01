<?php
// Arquivo: api/dados_vitrine.php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");

// Desativa exibição de erros no HTML
error_reporting(0);
ini_set('display_errors', 0);

require_once "../db/conexao.php";

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
    return $slug . '-' . bin2hex(random_bytes(2));
}

$slug = $_GET['slug'] ?? '';
$slug = strtolower(trim($slug));
$slug = preg_replace('/[^a-z0-9-]/', '', $slug);

$loja_id = 0;
$loja_slug = null;

// 1) Preferência: slug (URL bonita /vitrine/<slug>)
if (!empty($slug) && columnExists($pdo, 'lojas', 'slug')) {
    try {
        $stmt = $pdo->prepare("SELECT id, nome_loja, email, slug FROM lojas WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $loja_id = (int)$row['id'];
            $loja_slug = $row['slug'] ?? null;
        } else {
            echo json_encode(['error' => 'Loja não encontrada'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Erro ao localizar a loja'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    // 2) Fallback legado: ?loja=17
    $loja_id = isset($_GET['loja']) ? (int)$_GET['loja'] : 0;
}

if ($loja_id <= 0) {
    echo json_encode(['error' => 'Loja não informada'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Configurações
    $stmtConfig = $pdo->prepare("SELECT * FROM configuracoes WHERE loja_id = ? LIMIT 1");
    $stmtConfig->execute([$loja_id]);
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC) ?: [];

    // 2. Dados da loja
    if (columnExists($pdo, 'lojas', 'slug')) {
        $stmtLoja = $pdo->prepare("SELECT id, nome_loja, email, slug FROM lojas WHERE id = ? LIMIT 1");
    } else {
        $stmtLoja = $pdo->prepare("SELECT id, nome_loja, email FROM lojas WHERE id = ? LIMIT 1");
    }
    $stmtLoja->execute([$loja_id]);
    $dadosLoja = $stmtLoja->fetch(PDO::FETCH_ASSOC);

    if (!$dadosLoja) {
        echo json_encode(['error' => 'Loja não encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $loja_slug = $loja_slug ?? ($dadosLoja['slug'] ?? null);

    // 3. Monta objeto final
    $lojaFinal = [
        'id'        => (int)$loja_id,
        'slug'      => $loja_slug,
        'nome_loja' => $config['nome_loja'] ?? $dadosLoja['nome_loja'] ?? 'Loja',
        'whatsapp'  => $config['whatsapp'] ?? '',
        'instagram' => $config['instagram'] ?? '',
        'vendedor'  => $config['nome_vendedor'] ?? '',
        'tema'      => $config['tema'] ?? 'rose'
    ];

    // 4. Produtos
    $sqlProd = "SELECT id, codigo_produto, nome, preco, imagem, categoria, quantidade
                FROM produtos
                WHERE loja_id = ? AND quantidade > 0
                ORDER BY id DESC";
    $stmtProd = $pdo->prepare($sqlProd);
    $stmtProd->execute([$loja_id]);
    $produtos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'loja' => $lojaFinal,
        'produtos' => $produtos
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro DB: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>