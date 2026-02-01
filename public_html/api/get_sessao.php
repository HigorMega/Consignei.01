<?php
// api/get_sessao.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

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

if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
    $lojaId = (int)($_SESSION['loja_id'] ?? 0);
    $resp = [
        'logado' => true,
        'nome' => $_SESSION['nome'] ?? 'Lojista',
        'loja_id' => $lojaId
    ];

    // Tenta incluir slug (se existir no banco)
    if ($lojaId > 0 && columnExists($pdo, 'lojas', 'slug')) {
        try {
            $stmt = $pdo->prepare("SELECT slug FROM lojas WHERE id = ? LIMIT 1");
            $stmt->execute([$lojaId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['slug'])) {
                $resp['loja_slug'] = $row['slug'];
            }
        } catch (Exception $e) {
            // ignora
        }
    }

    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['logado' => false], JSON_UNESCAPED_UNICODE);
}
?>