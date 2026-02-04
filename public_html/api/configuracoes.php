<?php
// Arquivo: api/configuracoes.php (salva configuracoes + slug em lojas)

header('Content-Type: application/json; charset=UTF-8');
require_once "../db/conexao.php";
session_start();

// 1. Verifica se a sessão existe
if (!isset($_SESSION['loja_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit;
}

$loja_id = (int)$_SESSION['loja_id'];

// ------------------------------
// Helper (slug)
// ------------------------------
function normalizarSlug($slug) {
    if ($slug === null) return null;

    $slug = trim((string)$slug);
    if ($slug === '') return null;

    $slug = mb_strtolower($slug, 'UTF-8');

    // apenas a-z 0-9 e hífen, sem espaços
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) return '__INVALID__';

    $len = strlen($slug);
    if ($len < 3 || $len > 60) return '__INVALID__';

    return $slug;
}

// 2. GET (buscar)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM configuracoes WHERE loja_id = ?");
        $stmt->execute([$loja_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        $resp = $config ?: [];

        // Puxa slug da tabela lojas (PK = id)
        try {
            $stSlug = $pdo->prepare("SELECT slug FROM lojas WHERE id = ? LIMIT 1");
            $stSlug->execute([$loja_id]);
            $slug = $stSlug->fetchColumn();
            if ($slug !== false) $resp['slug'] = $slug;
        } catch (PDOException $e) {
            // não quebra o retorno se slug der erro
        }

        echo json_encode(!empty($resp) ? $resp : new stdClass());
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 3. POST (salvar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
        exit;
    }

    // Campos do JS -> banco (mantém igual ao seu original)
    $nome_loja = $data['nome_loja'] ?? '';
    $whatsapp  = $data['whatsapp'] ?? '';
    $vendedor  = $data['vendedor'] ?? ''; // no banco é 'nome_vendedor'
    $instagram = $data['instagram'] ?? '';
    $tema      = $data['tema'] ?? 'marble';
    $estiloFonte = $data['estilo_fonte'] ?? 'classico';
    $corFundo = $data['cor_fundo'] ?? '#ffffff';
    $texturaFundo = $data['textura_fundo'] ?? 'liso';
    $bannerAviso = $data['banner_aviso'] ?? '';

    // Slug: só altera se a chave existir no JSON
    $querAlterarSlug = array_key_exists('slug', $data);
    $slugNormalizado = null;

    if ($querAlterarSlug) {
        $slugNormalizado = normalizarSlug($data['slug']);

        if ($slugNormalizado === '__INVALID__') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Slug inválido. Use apenas letras minúsculas, números e hífens (ex: minha-loja-123).'
            ]);
            exit;
        }

        // slug vazio = não altera
        if ($slugNormalizado === null) {
            $querAlterarSlug = false;
        }
    }

    try {
        $pdo->beginTransaction();

        // ✅ NÃO usa mais "SELECT id" (pois pode não existir)
        $check = $pdo->prepare("SELECT 1 FROM configuracoes WHERE loja_id = ? LIMIT 1");
        $check->execute([$loja_id]);
        $existe = ($check->fetchColumn() !== false);

        if ($existe) {
            // UPDATE (igual ao original)
            $sql = "UPDATE configuracoes SET 
                    nome_loja = ?, 
                    whatsapp = ?, 
                    nome_vendedor = ?, 
                    instagram = ?, 
                    tema = ?,
                    estilo_fonte = ?,
                    cor_fundo = ?,
                    textura_fundo = ?,
                    banner_aviso = ?
                    WHERE loja_id = ?";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                $nome_loja,
                $whatsapp,
                $vendedor,
                $instagram,
                $tema,
                $estiloFonte,
                $corFundo,
                $texturaFundo,
                $bannerAviso,
                $loja_id
            ]);
        } else {
            // INSERT (igual ao original)
            $sql = "INSERT INTO configuracoes (loja_id, nome_loja, whatsapp, nome_vendedor, instagram, tema, estilo_fonte, cor_fundo, textura_fundo, banner_aviso) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                $loja_id,
                $nome_loja,
                $whatsapp,
                $vendedor,
                $instagram,
                $tema,
                $estiloFonte,
                $corFundo,
                $texturaFundo,
                $bannerAviso
            ]);
        }

        if (!$success) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Erro ao executar SQL.']);
            exit;
        }

        // Salva slug na tabela lojas (PK = id) com unicidade
        if ($querAlterarSlug) {
            // Unicidade: não pode existir slug em outra loja
            $stUniq = $pdo->prepare("SELECT id FROM lojas WHERE slug = ? AND id <> ? LIMIT 1");
            $stUniq->execute([$slugNormalizado, $loja_id]);
            if ($stUniq->fetch(PDO::FETCH_ASSOC)) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Esse slug já está em uso. Escolha outro.']);
                exit;
            }

            $stUp = $pdo->prepare("UPDATE lojas SET slug = ? WHERE id = ? LIMIT 1");
            $okSlug = $stUp->execute([$slugNormalizado, $loja_id]);

            if (!$okSlug) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Erro ao salvar slug na tabela lojas.']);
                exit;
            }
        }

        $pdo->commit();

        // Atualiza sessão
        $_SESSION['nome'] = $nome_loja;
        if ($querAlterarSlug) $_SESSION['slug'] = $slugNormalizado;

        echo json_encode(['success' => true]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erro no banco: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Método não permitido. Use GET ou POST.']);
exit;
