<?php
// Arquivo: api/editar_produto.php
header('Content-Type: application/json');
require_once "../db/conexao.php";
require_once __DIR__ . "/subscription_helpers.php";
session_start();

sh_require_login();
sh_require_active_subscription($pdo);

$loja_id = $_SESSION['loja_id'];
$id = $_POST['id'] ?? '';
$nome = $_POST['nome'] ?? '';
$codigo = $_POST['codigo_produto'] ?? '';
$categoria = $_POST['categoria'] ?? '';
$fornecedor_id = $_POST['fornecedor_id'] ?? null;
$preco_custo = $_POST['preco_custo'] ?? 0;
$markup = $_POST['markup'] ?? 0;
$preco = $_POST['preco'] ?? 0;
$quantidade = $_POST['quantidade'] ?? 0;

try {
    // 1. Atualiza os dados de texto primeiro
    $sql = "UPDATE produtos SET 
            nome = ?, codigo_produto = ?, categoria = ?, 
            fornecedor_id = ?, preco_custo = ?, markup = ?, 
            preco = ?, quantidade = ? 
            WHERE id = ? AND loja_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nome, $codigo, $categoria, $fornecedor_id, $preco_custo, $markup, $preco, $quantidade, $id, $loja_id]);

    // 2. Verifica se enviou uma NOVA imagem
    if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === 0) {
        
        // --- [INÍCIO DA ATUALIZAÇÃO DE SEGURANÇA] ---
        // Validação de extensão (Lista Branca)
        $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'webp'];
        $extensao_check = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));

        if (!in_array($extensao_check, $extensoes_permitidas)) {
            echo json_encode(['success' => false, 'error' => 'Formato inválido! Apenas JPG, PNG ou WEBP são permitidos.']);
            exit;
        }

        // Validação de conteúdo real (Bloqueia scripts disfarçados)
        if (getimagesize($_FILES['imagem']['tmp_name']) === false) {
            echo json_encode(['success' => false, 'error' => 'O arquivo enviado não é uma imagem válida.']);
            exit;
        }
        // --- [FIM DA ATUALIZAÇÃO DE SEGURANÇA] ---

        // Configura pasta do vendedor
        $pasta_vendedor = "loja_" . $loja_id;
        $diretorio_base = "../public/uploads/";
        $caminho_loja = $diretorio_base . $pasta_vendedor . "/";
        
        if (!is_dir($caminho_loja)) mkdir($caminho_loja, 0755, true);

        // Processa a imagem (Redimensiona para economizar espaço)
        $extensao = $extensao_check; // Usa a extensão já validada
        $novo_nome_arquivo = "prod_" . uniqid() . ".jpg";
        $caminho_fisico = $caminho_loja . $novo_nome_arquivo;
        
        $arquivo_temp = $_FILES['imagem']['tmp_name'];
        $img_origem = null;

        if ($extensao == 'jpg' || $extensao == 'jpeg') $img_origem = imagecreatefromjpeg($arquivo_temp);
        elseif ($extensao == 'png') $img_origem = imagecreatefrompng($arquivo_temp);
        elseif ($extensao == 'webp') $img_origem = imagecreatefromwebp($arquivo_temp);

        if ($img_origem) {
            $largura_orig = imagesx($img_origem);
            $altura_orig = imagesy($img_origem);
            $limite = 800; // Reduz para max 800px

            if ($largura_orig > $limite || $altura_orig > $limite) {
                if ($largura_orig > $altura_orig) {
                    $n_largura = $limite;
                    $n_altura = ($altura_orig / $largura_orig) * $limite;
                } else {
                    $n_altura = $limite;
                    $n_largura = ($largura_orig / $altura_orig) * $limite;
                }
            } else {
                $n_largura = $largura_orig; $n_altura = $altura_orig;
            }

            $img_dest = imagecreatetruecolor($n_largura, $n_altura);
            
            // Garante fundo branco para converter transparências (PNG/WEBP)
            $cor_fundo = imagecolorallocate($img_dest, 255, 255, 255);
            imagefill($img_dest, 0, 0, $cor_fundo);

            imagecopyresampled($img_dest, $img_origem, 0, 0, 0, 0, $n_largura, $n_altura, $largura_orig, $altura_orig);

            imagejpeg($img_dest, $caminho_fisico, 75);
            imagedestroy($img_origem);
            imagedestroy($img_dest);

            // 3. Se deu certo o upload, atualiza o nome no banco
            // Caminho relativo para salvar no banco: loja_X/foto.jpg
            $caminho_banco = $pasta_vendedor . "/" . $novo_nome_arquivo;
            
            $stmtImg = $pdo->prepare("UPDATE produtos SET imagem = ? WHERE id = ? AND loja_id = ?");
            $stmtImg->execute([$caminho_banco, $id, $loja_id]);
        }
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
