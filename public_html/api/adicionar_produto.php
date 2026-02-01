<?php
// Arquivo: api/adicionar_produto.php
header('Content-Type: application/json');
require_once "../db/conexao.php";
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['loja_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit;
}

$loja_id = $_SESSION['loja_id'];
$nome = $_POST['nome'] ?? '';
$codigo = $_POST['codigo_produto'] ?? '';
$categoria = $_POST['categoria'] ?? '';
$fornecedor_id = $_POST['fornecedor_id'] ?? null;
$preco_custo = $_POST['preco_custo'] ?? 0;
$markup = $_POST['markup'] ?? 0;
$preco = $_POST['preco'] ?? 0;
$quantidade = $_POST['quantidade'] ?? 1;

$nome_imagem = null;

// --- INÍCIO DO UPLOAD E TRATAMENTO DA IMAGEM ---
if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === 0) {
    
    // 1. SEGURANÇA: Validar extensão (Lista Branca)
    $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'webp'];
    $extensao = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));

    if (!in_array($extensao, $extensoes_permitidas)) {
        echo json_encode(['success' => false, 'error' => 'Formato inválido! Apenas JPG, PNG ou WEBP são permitidos.']);
        exit;
    }

    // 2. SEGURANÇA: Validar se é realmente uma imagem (evita script falso)
    if (getimagesize($_FILES['imagem']['tmp_name']) === false) {
        echo json_encode(['success' => false, 'error' => 'O arquivo enviado não é uma imagem válida.']);
        exit;
    }

    // --- LÓGICA DE PASTA POR VENDEDOR ---
    $pasta_vendedor = "loja_" . $loja_id;
    $diretorio_base = "../public/uploads/";
    $caminho_loja = $diretorio_base . $pasta_vendedor . "/";
    
    // CRIA A PASTA AUTOMATICAMENTE SE NÃO EXISTIR
    if (!is_dir($caminho_loja)) {
        mkdir($caminho_loja, 0755, true);
    }

    // Gera um nome único e seguro para o arquivo
    $novo_nome = "prod_" . uniqid() . ".jpg"; 
    $caminho_final = $caminho_loja . $novo_nome;

    // --- REDIMENSIONAMENTO (ECONOMIZA ESPAÇO) ---
    $arquivo_temp = $_FILES['imagem']['tmp_name'];
    $img_origem = null;
    
    if ($extensao == 'jpg' || $extensao == 'jpeg') $img_origem = imagecreatefromjpeg($arquivo_temp);
    elseif ($extensao == 'png') $img_origem = imagecreatefrompng($arquivo_temp);
    elseif ($extensao == 'webp') $img_origem = imagecreatefromwebp($arquivo_temp);

    if ($img_origem) {
        $largura_orig = imagesx($img_origem);
        $altura_orig = imagesy($img_origem);
        $limite = 800; // Tamanho máximo 800px

        // Calcula novas dimensões mantendo a proporção
        if ($largura_orig > $limite || $altura_orig > $limite) {
            if ($largura_orig > $altura_orig) {
                $n_largura = $limite;
                $n_altura = ($altura_orig / $largura_orig) * $limite;
            } else {
                $n_altura = $limite;
                $n_largura = ($largura_orig / $altura_orig) * $limite;
            }
        } else {
            $n_largura = $largura_orig;
            $n_altura = $altura_orig;
        }

        $img_dest = imagecreatetruecolor($n_largura, $n_altura);
        
        // Mantém fundo branco para converter PNG/WEBP transparente para JPG
        $cor_fundo = imagecolorallocate($img_dest, 255, 255, 255);
        imagefill($img_dest, 0, 0, $cor_fundo);
        
        imagecopyresampled($img_dest, $img_origem, 0, 0, 0, 0, $n_largura, $n_altura, $largura_orig, $altura_orig);

        // Salva a imagem na pasta específica do vendedor (Qualidade 75 é ótima para web)
        imagejpeg($img_dest, $caminho_final, 75);
        
        imagedestroy($img_origem);
        imagedestroy($img_dest);
        
        // No banco de dados, guardamos o caminho relativo: loja_X/foto.jpg
        $nome_imagem = $pasta_vendedor . "/" . $novo_nome;
    } else {
        echo json_encode(['success' => false, 'error' => 'Erro ao processar a imagem.']);
        exit;
    }
}

// --- INSERÇÃO NO BANCO DE DADOS ---
try {
    $sql = "INSERT INTO produtos (loja_id, nome, codigo_produto, categoria, fornecedor_id, preco_custo, markup, preco, quantidade, imagem) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$loja_id, $nome, $codigo, $categoria, $fornecedor_id, $preco_custo, $markup, $preco, $quantidade, $nome_imagem]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
}
?>