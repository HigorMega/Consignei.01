<?php
// Arquivo: api/dados_vitrine.php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");

// Desativa exibição de erros no HTML
error_reporting(0);
ini_set('display_errors', 0);

require_once "../db/conexao.php";

/* Helpers */
function getTableColumns(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        return array_map(static fn($row) => $row['Field'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        return [];
    }
}

function findFirstColumn(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function respondJson(array $payload, array $debugInfo, bool $debug): void {
    if ($debug) {
        $payload['debug'] = $debugInfo;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$debugInfo = [
    'database' => $db ?? null,
    'host' => $host ?? null,
    'inputs' => [
        'slug' => $_GET['slug'] ?? null,
        'loja' => $_GET['loja'] ?? null
    ],
    'columns' => [],
    'pk' => [],
    'fk' => [],
    'queries' => []
];

$lojasColumns = getTableColumns($pdo, 'lojas');
$debugInfo['columns']['lojas'] = $lojasColumns;
$lojaPk = findFirstColumn($lojasColumns, ['id', 'loja_id', 'id_loja']);
$debugInfo['pk']['lojas'] = $lojaPk;

if (!$lojaPk) {
    respondJson(['error' => 'Não foi possível identificar a PK da tabela lojas.'], $debugInfo, $debug);
}

$slugColumn = in_array('slug', $lojasColumns, true) ? 'slug' : null;

$slug = $_GET['slug'] ?? '';
$slug = strtolower(trim($slug));
$slug = preg_replace('/[^a-z0-9-]/', '', $slug);

$lojaId = 0;
$lojaSlug = null;

if (!empty($slug)) {
    if (!$slugColumn) {
        respondJson(['error' => 'Slug informado, mas a coluna slug não existe na tabela lojas.'], $debugInfo, $debug);
    }

    $sqlLojaSlug = "SELECT `$lojaPk` AS loja_pk, nome_loja, email, slug FROM lojas WHERE slug = ? LIMIT 1";
    $debugInfo['queries'][] = ['sql' => $sqlLojaSlug, 'params' => [$slug]];
    try {
        $stmt = $pdo->prepare($sqlLojaSlug);
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lojaId = (int)$row['loja_pk'];
            $lojaSlug = $row['slug'] ?? null;
        } else {
            respondJson(['error' => 'Loja não encontrada'], $debugInfo, $debug);
        }
    } catch (Exception $e) {
        respondJson(['error' => 'Erro ao localizar a loja'], $debugInfo, $debug);
    }
} else {
    $lojaId = isset($_GET['loja']) ? (int)$_GET['loja'] : 0;
}

if ($lojaId <= 0) {
    respondJson(['error' => 'Loja não informada'], $debugInfo, $debug);
}

try {
    $configColumns = getTableColumns($pdo, 'configuracoes');
    $debugInfo['columns']['configuracoes'] = $configColumns;
    $configFk = findFirstColumn($configColumns, ['loja_id', 'id_loja']);
    $debugInfo['fk']['configuracoes'] = $configFk;
    $config = [];

    if ($configFk) {
        $sqlConfig = "SELECT * FROM configuracoes WHERE `$configFk` = ? LIMIT 1";
        $debugInfo['queries'][] = ['sql' => $sqlConfig, 'params' => [$lojaId]];
        $stmtConfig = $pdo->prepare($sqlConfig);
        $stmtConfig->execute([$lojaId]);
        $config = $stmtConfig->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $selectLojaFields = ["`$lojaPk` AS loja_pk", "nome_loja", "email"];
    if ($slugColumn) {
        $selectLojaFields[] = "slug";
    }
    $sqlLoja = "SELECT " . implode(', ', $selectLojaFields) . " FROM lojas WHERE `$lojaPk` = ? LIMIT 1";
    $debugInfo['queries'][] = ['sql' => $sqlLoja, 'params' => [$lojaId]];
    $stmtLoja = $pdo->prepare($sqlLoja);
    $stmtLoja->execute([$lojaId]);
    $dadosLoja = $stmtLoja->fetch(PDO::FETCH_ASSOC);

    if (!$dadosLoja) {
        respondJson(['error' => 'Loja não encontrada'], $debugInfo, $debug);
    }

    $lojaSlug = $lojaSlug ?? ($dadosLoja['slug'] ?? null);

    $lojaFinal = [
        'id'        => (int)$lojaId,
        'slug'      => $lojaSlug,
        'nome_loja' => $config['nome_loja'] ?? $dadosLoja['nome_loja'] ?? 'Loja',
        'whatsapp'  => $config['whatsapp'] ?? '',
        'instagram' => $config['instagram'] ?? '',
        'vendedor'  => $config['nome_vendedor'] ?? '',
        'tema'      => $config['tema'] ?? 'rose',
        'estilo_fonte' => $config['estilo_fonte'] ?? 'classico',
        'cor_fundo' => $config['cor_fundo'] ?? '#ffffff',
        'textura_fundo' => $config['textura_fundo'] ?? 'liso',
        'banner_aviso' => $config['banner_aviso'] ?? ''
    ];

    $produtosColumns = getTableColumns($pdo, 'produtos');
    $debugInfo['columns']['produtos'] = $produtosColumns;
    $produtoPk = findFirstColumn($produtosColumns, ['id', 'produto_id', 'id_produto']);
    $produtoFk = findFirstColumn($produtosColumns, ['loja_id', 'id_loja']);
    $debugInfo['pk']['produtos'] = $produtoPk;
    $debugInfo['fk']['produtos'] = $produtoFk;

    if (!$produtoPk || !$produtoFk) {
        respondJson(['error' => 'Não foi possível identificar a chave de produtos para a loja.'], $debugInfo, $debug);
    }

    $selectProdutos = ["`$produtoPk` AS id"];
    $camposProdutos = ['codigo_produto', 'nome', 'preco', 'imagem', 'categoria', 'quantidade', 'data_criacao'];
    foreach ($camposProdutos as $campo) {
        if (in_array($campo, $produtosColumns, true)) {
            $selectProdutos[] = "`$campo`";
        }
    }

    $where = ["`$produtoFk` = ?"];
    if (in_array('quantidade', $produtosColumns, true)) {
        $where[] = "quantidade > 0";
    }
    $sqlProd = "SELECT " . implode(', ', $selectProdutos)
        . " FROM produtos WHERE " . implode(' AND ', $where)
        . " ORDER BY `$produtoPk` DESC";

    $debugInfo['queries'][] = ['sql' => $sqlProd, 'params' => [$lojaId]];
    $stmtProd = $pdo->prepare($sqlProd);
    $stmtProd->execute([$lojaId]);
    $produtos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    respondJson([
        'loja' => $lojaFinal,
        'produtos' => $produtos
    ], $debugInfo, $debug);

} catch (PDOException $e) {
    respondJson(['error' => 'Erro DB: ' . $e->getMessage()], $debugInfo, $debug);
}
?>
