<?php
// Limpeza de buffer para evitar erros ocultos (Mantido da sua base)
ob_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once "../db/conexao.php"; // CORREÇÃO 1: Aponta para o arquivo de conexão correto
require_once __DIR__ . "/subscription_helpers.php";
session_start(); // CORREÇÃO 2: Inicia sessão para pegar o ID da loja

try {
    // Verifica se está logado
    sh_require_login();
    sh_require_active_subscription($pdo);

    $loja_id = $_SESSION['loja_id'];

    // Verifica se a variável $pdo foi criada
    if (!isset($pdo)) {
        throw new Exception("Erro na conexão com o banco de dados.");
    }

    // Recebe os dados do Javascript
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if (!$data) throw new Exception("Nenhum dado recebido.");

    if (isset($data['id']) && isset($data['preco_venda']) && isset($data['data_venda'])) {
        $id = $data['id'];
        $nova_data = $data['data_venda'];
        $novo_preco = floatval($data['preco_venda']);

        // 1. Busca custo original NA TABELA VENDAS (Mais seguro que buscar no produto)
        // Isso garante que editamos apenas essa venda específica da sua loja
        $stmt = $pdo->prepare("SELECT preco_custo FROM vendas WHERE id = ? AND loja_id = ?");
        $stmt->execute([$id, $loja_id]);
        $vendaInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($vendaInfo) {
            $custoOriginal = floatval($vendaInfo['preco_custo']);
            $novoLucro = $novo_preco - $custoOriginal;

            // 2. Atualiza
            // Mantive sua lógica de preservar a hora atual se necessário
            // Mas geralmente o input date vem YYYY-MM-DD. Vamos garantir o formato:
            $data_formatada = $nova_data; 
            if(strlen($data_formatada) <= 10) {
                 $data_formatada .= ' ' . date('H:i:s'); // Adiciona hora atual se vier só a data
            }

            $update = $pdo->prepare("UPDATE vendas SET data_venda = ?, preco_venda = ?, lucro = ? WHERE id = ? AND loja_id = ?");
            
            if ($update->execute([$data_formatada, $novo_preco, $novoLucro, $id, $loja_id])) {
                $response = ["success" => true];
            } else {
                $response = ["success" => false, "message" => "Banco recusou atualização"];
            }
        } else {
            $response = ["success" => false, "message" => "Venda não encontrada ou permissão negada."];
        }
    } else {
        $response = ["success" => false, "message" => "Dados incompletos"];
    }

} catch (Exception $e) {
    $response = ["success" => false, "message" => "Erro: " . $e->getMessage()];
}

ob_clean();
echo json_encode($response);
exit;
?>
