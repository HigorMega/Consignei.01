<?php
/**
 * API DE RELATÓRIO - CORREÇÃO DE VALORES E NOMES
 * - Calcula Custo Real (Acerto) e Venda Real (Faturamento).
 * - Retorna nomes de colunas compatíveis com o Dashboard (codigo_produto, preco_custo, etc).
 */

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");

require_once '../db/conexao.php';
require_once __DIR__ . "/subscription_helpers.php";

if (session_status() === PHP_SESSION_NONE) session_start();
sh_require_login();
sh_require_active_subscription($pdo);
$loja_id = $_SESSION['loja_id'];

$lote_id = isset($_GET['lote_id']) ? intval($_GET['lote_id']) : 0;

if (!$lote_id) { echo json_encode(['success' => false, 'error' => 'Lote não informado']); exit; }

try {
    // 1. Busca dados do Lote
    $stmt = $pdo->prepare("
        SELECT l.*, f.nome AS nome_empresa, f.contato
        FROM lotes l
        LEFT JOIN fornecedores f ON l.fornecedor_id = f.id
        WHERE l.id = ? AND l.loja_id = ?
    ");
    $stmt->execute([$lote_id, $loja_id]);
    $lote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lote) throw new Exception("Lote não encontrado.");

    // 2. Busca itens e cruza com Vendas para saber o que foi vendido
    // Usa COLLATE para evitar erros de charset
    $data_inicio = $lote['data_entrada'] ?? '1970-01-01 00:00:00';
    $data_fim = $lote['data_aprovacao'] ?? date('Y-m-d H:i:s');
    $sql = "
        SELECT 
            li.codigo_produto, 
            li.nome, 
            li.preco_custo, 
            li.preco_venda, 
            li.quantidade as qtd_entrada,
            COALESCE(v.qtd_vendida, 0) as qtd_vendida
        FROM lote_itens li
        LEFT JOIN (
            SELECT 
                codigo_produto, 
                COUNT(*) as qtd_vendida
            FROM vendas
            WHERE loja_id = :loja_id
              AND data_venda >= :data_inicio
              AND data_venda <= :data_fim
            GROUP BY codigo_produto
        ) v ON (
            v.codigo_produto COLLATE utf8mb4_unicode_ci = li.codigo_produto COLLATE utf8mb4_unicode_ci
        )
        WHERE li.lote_id = :lote_id AND li.status = 'aprovado'
        ORDER BY li.nome ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'loja_id' => $loja_id, 
        'lote_id' => $lote_id,
        'data_inicio' => $data_inicio,
        'data_fim' => $data_fim
    ]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $relatorio = [];
    
    // Resumo para os Cards (KPIs)
    $resumo = [
        'qtd_total' => 0,           // Total de peças que entraram
        'qtd_vendida' => 0,         // Total de peças vendidas
        'total_custo_vendido' => 0, // ACERTO: Quanto tenho que pagar (Custo * Vendidos)
        'total_vendido_valor' => 0, // FATURAMENTO: Quanto entrou (Venda * Vendidos)
        'total_pagar' => 0          // Igual ao total_custo_vendido (compatibilidade)
    ];

    foreach ($itens as $item) {
        $qtd_entrada = intval($item['qtd_entrada']);
        $qtd_vendida = intval($item['qtd_vendida']);
        $custo_unit  = floatval($item['preco_custo']);
        $venda_unit  = floatval($item['preco_venda']);
        $qtd_vendida = min($qtd_vendida, $qtd_entrada);
        
        // Atualiza Totais Gerais
        $resumo['qtd_total'] += $qtd_entrada;
        $resumo['qtd_vendida'] += $qtd_vendida;
        
        // Calcula Financeiro APENAS do que foi vendido
        $custo_da_venda = $qtd_vendida * $custo_unit; // O que devo pagar
        $faturamento    = $qtd_vendida * $venda_unit; // O que recebi

        $resumo['total_custo_vendido'] += $custo_da_venda;
        $resumo['total_vendido_valor'] += $faturamento;

        // Monta o item para a tabela (usando nomes que o JS espera)
        $relatorio[] = [
            'codigo_produto' => $item['codigo_produto'], // JS espera 'codigo_produto'
            'nome'           => $item['nome'],
            'preco_custo'    => $custo_unit,             // JS espera 'preco_custo'
            'preco_venda'    => $venda_unit,             // JS espera 'preco_venda'
            
            // Para a tabela mostrar "10 / 8" (Entrada / Vendido)
            'quantidade'     => "$qtd_entrada / $qtd_vendida", 
            
            // Dados brutos para cálculos extras se precisar
            'qtd_entrada'    => $qtd_entrada,
            'qtd_vendida'    => $qtd_vendida,
            'vendido'        => ($qtd_vendida > 0) // Flag para saber se teve venda
        ];
    }

    // Compatibilidade: total_pagar é o acerto
    $resumo['total_pagar'] = $resumo['total_custo_vendido'];

    echo json_encode(['success' => true, 'lote' => $lote, 'itens' => $relatorio, 'resumo' => $resumo]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
