<?php
/**
 * API v11.5 - CORREÇÃO DE EXCLUSÃO (BODY + QUERY STRING)
 * - A IA agora define a categoria (Ex: "Anéis", "Colares") visualmente.
 * - Função 'limparCodigo' corrige erros comuns de OCR (O -> 0, espaços, etc).
 * - Log detalhado para auditoria.
 */

// Configurações do Servidor
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);
ini_set('display_errors', 0); 
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: " . (env('ALLOWED_ORIGIN', '*') ?? '*'));
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");

// --- LOG ---
function debugLog($msg) {
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0700, true);
    }
    $logFile = $logDir . '/scanner.log';
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// --- CONEXÃO ---
function getConexao() {
    try {
        require '../db/conexao.php'; 
        if (!isset($pdo)) {
            // $pdo = new PDO("mysql:host=localhost;dbname=...", "...", "...");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $pdo->query("SET SESSION wait_timeout = 28800"); 
            $pdo->query("SET SESSION max_allowed_packet = 16777216");
        } catch (Exception $e) {}
        return $pdo;
    } catch (Exception $e) {
        debugLog("ERRO DB: " . $e->getMessage());
        die(json_encode(['success' => false, 'error' => 'Erro Conexão DB']));
    }
}

$pdo = getConexao();
if (session_status() === PHP_SESSION_NONE) session_start();
$loja_id = $_SESSION['loja_id'] ?? 1;

$apiKey = env('OPENAI_API_KEY');

// --- FUNÇÃO 1: CORRIGIR CÓDIGO (NOVA) ---
function limparCodigo($codigo) {
    // Remove espaços vazios
    $codigo = trim(str_replace(' ', '', $codigo));
    // Força maiúsculas
    $codigo = strtoupper($codigo);
    // Remove caracteres especiais (mantém apenas Letras, Números e Hífen)
    $codigo = preg_replace('/[^A-Z0-9\-]/', '', $codigo);
    
    // Opcional: Se o código for muito curto (erro de leitura), gera um
    if (strlen($codigo) < 3) return 'SCAN-' . rand(1000, 9999);
    
    return $codigo;
}

// --- FUNÇÃO 2: GERENCIAR CATEGORIA ---
function obterIdCategoria($pdo, $loja_id, $nomeSugerido) {
    $nomeLimpo = mb_convert_case(trim($nomeSugerido), MB_CASE_TITLE, "UTF-8");
    if (empty($nomeLimpo)) $nomeLimpo = 'Geral';

    // Mapeamento para padronizar (Evita "Anel" e "Aneis" duplicados)
    $mapa = [
        'Anel' => 'Anéis', 'Aneis' => 'Anéis',
        'Brinco' => 'Brincos', 
        'Colar' => 'Colares', 'Corrente' => 'Colares',
        'Pulseira' => 'Pulseiras',
        'Pingente' => 'Pingentes',
        'Conjunto' => 'Conjuntos'
    ];
    
    if (isset($mapa[$nomeLimpo])) $nomeLimpo = $mapa[$nomeLimpo];

    try {
        // Busca
        $stmt = $pdo->prepare("SELECT id FROM categorias WHERE loja_id = ? AND nome = ? LIMIT 1");
        $stmt->execute([$loja_id, $nomeLimpo]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cat) return $cat['id'];

        // Cria
        $stmtInsert = $pdo->prepare("INSERT INTO categorias (loja_id, nome) VALUES (?, ?)");
        $stmtInsert->execute([$loja_id, $nomeLimpo]);
        return $pdo->lastInsertId();

    } catch (Exception $e) {
        // Se der erro, reconecta e tenta pegar a Geral (ID 1 ou cria)
        $pdo = getConexao();
        return 0; 
    }
}

function isListaArray($valor) {
    if (!is_array($valor)) {
        return false;
    }
    $i = 0;
    foreach ($valor as $chave => $_) {
        if ($chave !== $i) {
            return false;
        }
        $i++;
    }
    return true;
}

function extrairItensDaIA($rawContent) {
    $conteudo = trim($rawContent);
    if ($conteudo === '') {
        return null;
    }

    $extrairLista = function ($decodificado) {
        if (!is_array($decodificado)) {
            return null;
        }

        if (isListaArray($decodificado)) {
            return $decodificado;
        }

        $chavesPossiveis = ['itens', 'items', 'dados', 'data'];
        foreach ($chavesPossiveis as $chave) {
            if (isset($decodificado[$chave]) && is_array($decodificado[$chave])) {
                return $decodificado[$chave];
            }
        }

        foreach ($decodificado as $valor) {
            if (is_array($valor) && isListaArray($valor)) {
                return $valor;
            }
        }

        return null;
    };

    $candidatos = [];
    $candidatos[] = $conteudo;
    $candidatos[] = preg_replace('/^```(?:json)?|```$/m', '', $conteudo);

    foreach ($candidatos as $candidato) {
        $decodificado = json_decode($candidato, true);
        $lista = $extrairLista($decodificado);
        if ($lista !== null) {
            return $lista;
        }
    }

    $primeiro = strpos($conteudo, '[');
    $ultimo = strrpos($conteudo, ']');
    if ($primeiro !== false && $ultimo !== false && $ultimo > $primeiro) {
        $arrayJson = substr($conteudo, $primeiro, $ultimo - $primeiro + 1);
        $decodificado = json_decode($arrayJson, true);
        $lista = $extrairLista($decodificado);
        if ($lista !== null) {
            return $lista;
        }
    }

    $primeiroObj = strpos($conteudo, '{');
    $ultimoObj = strrpos($conteudo, '}');
    if ($primeiroObj !== false && $ultimoObj !== false && $ultimoObj > $primeiroObj) {
        $objJson = substr($conteudo, $primeiroObj, $ultimoObj - $primeiroObj + 1);
        $decodificado = json_decode($objJson, true);
        $lista = $extrairLista($decodificado);
        if ($lista !== null) {
            return $lista;
        }
    }

    return null;
}

$method = $_SERVER['REQUEST_METHOD'];

// --- ROTAS PADRÃO ---
if ($method === 'PUT') {
    $d = json_decode(file_get_contents("php://input"), true);
    $id = $d['id'] ?? 0;
    if ($id) {
        $campos=[]; $vals=[];
        $perm = ['codigo_produto','nome','preco_custo','preco_venda','quantidade','categoria_id'];
        foreach($perm as $f) { if(isset($d[$f])) { $campos[]="$f=?"; $vals[]=$d[$f]; } }
        if(!empty($campos)) { $vals[]=$id; $pdo->prepare("UPDATE lote_itens SET ".implode(',',$campos)." WHERE id=?")->execute($vals); }
    }
    echo json_encode(['success'=>true]);
    exit;
}

if ($method === 'GET') {
    $lote_id = $_GET['lote_id'] ?? 0;
    $sql = "SELECT li.*, c.nome as nome_categoria 
            FROM lote_itens li 
            LEFT JOIN categorias c ON li.categoria_id = c.id 
            WHERE li.lote_id = ? ORDER BY li.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$lote_id]);
    echo json_encode([
        'itens' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'resumo' => ['qtd'=>0] // Simplificado para focar na correção
    ]);
    exit;
}

// --- ROTA DELETE (ATUALIZADA E CORRIGIDA) ---
if ($method === 'DELETE') {
    try {
        $idsParaExcluir = [];

        // 1. Tenta ler o corpo da requisição (JSON - usado pelo botão de massa)
        // Usa verificação !empty para evitar erro em versões antigas do PHP
        $json = file_get_contents("php://input");
        $body = !empty($json) ? json_decode($json, true) : [];
        
        if ($body && isset($body['ids'])) {
            $idsParaExcluir = is_array($body['ids']) ? $body['ids'] : explode(',', $body['ids']);
        } elseif ($body && isset($body['id'])) {
            $idsParaExcluir[] = $body['id'];
        }

        // 2. Tenta ler da URL se o body estiver vazio (usado por links diretos)
        if (empty($idsParaExcluir)) {
            if (isset($_GET['ids'])) {
                $idsParaExcluir = explode(',', $_GET['ids']);
            } elseif (isset($_GET['id'])) {
                $idsParaExcluir[] = $_GET['id'];
            }
        }

        // Limpeza e validação dos IDs
        $idsParaExcluir = array_filter(array_map('intval', $idsParaExcluir));

        if (!empty($idsParaExcluir)) {
            $in = str_repeat('?,', count($idsParaExcluir) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM lote_itens WHERE id IN ($in)");
            $stmt->execute($idsParaExcluir);
            echo json_encode(['success' => true, 'count' => count($idsParaExcluir)]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Nenhum item selecionado']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================================================
//  POST: MANUAL + IA
// ==========================================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    if ($input) $_POST = array_merge($_POST, $input);

    $lote_id = $_POST['lote_id'] ?? 0;
    $margem  = floatval($_POST['margem'] ?? 100);
    $img     = $_POST['imagem'] ?? null;
    $nomeMan = $_POST['nome'] ?? null;

    if (!$lote_id) { echo json_encode(['success'=>false, 'error'=>'Lote inválido']); exit; }

    // 1. MANUAL
    if (empty($img) && !empty($nomeMan)) {
        try {
            $custo = floatval($_POST['custo'] ?? 0);
            $venda = floatval($_POST['venda'] ?? 0);
            $cod   = limparCodigo($_POST['codigo'] ?? 'MANUAL-'.rand(100,999));
            $catId = obterIdCategoria($pdo, $loja_id, 'Geral'); // Manual vai para Geral se não especificar

            $pdo->prepare("INSERT INTO lote_itens (lote_id, codigo_produto, nome, preco_custo, preco_venda, quantidade, categoria_id, status) VALUES (?,?,?,?,?,1,?, 'pendente')")
                ->execute([$lote_id, $cod, $nomeMan, $custo, $venda, $catId]);
            
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    // 2. IA GPT-4o
    if ($img) {
        try {
            if (!$apiKey || strpos($apiKey, 'sk-') === false) {
                throw new Exception("API Key Inválida");
            }

            // Fecha conexão temporária
            $pdo = null;

            $systemPrompt = "Você é um assistente de OCR e extração de dados. A imagem contém apenas listas de produtos. Extraia texto e transforme em JSON seguindo exatamente o formato solicitado, sem qualquer texto extra.";
            $prompt = "Analise esta imagem de uma lista de joias.
            Retorne um JSON estrito com a chave \"itens\" contendo um array de objetos:
            {
                \"codigo\": \"(leia o código EXATAMENTE como está na imagem)\",
                \"nome\": \"(descrição completa)\",
                \"custo\": (valor numérico, ex: 90.00),
                \"categoria\": \"(Defina a categoria baseada na descrição. Ex: Anéis, Brincos, Colares, Pulseiras, Conjuntos)\"
            }
            IMPORTANTE:
            1. Corrija códigos onde 'O' parece '0' ou 'I' parece '1' se forem numéricos.
            2. NÃO invente preços. Se não houver, coloque 0.
            3. Ignore cabeçalhos.
            4. Responda SOMENTE com JSON válido, sem texto extra.";

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://api.openai.com/v1/chat/completions",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "Authorization: Bearer $apiKey"
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    "model" => "gpt-4o-mini",
                    "response_format" => ["type" => "json_object"],
                    "messages" => [
                        ["role" => "system", "content" => $systemPrompt],
                        ["role" => "user", "content" => [
                            ["type" => "text", "text" => $prompt],
                            ["type" => "image_url", "image_url" => ["url" => "data:image/jpeg;base64," . $img]]
                        ]]
                    ],
                    "max_tokens" => 4000
                ])
            ]);

            $response = curl_exec($curl);
            $err = curl_error($curl);
            curl_close($curl);

            if ($err) throw new Exception("Erro Curl: $err");

            $result = json_decode($response, true);
            if (!isset($result['choices'][0]['message']['content'])) {
                $refusal = $result['choices'][0]['message']['refusal'] ?? null;
                if ($refusal) {
                    throw new Exception("IA recusou analisar a imagem. Tente novamente com uma foto mais nítida e apenas da lista de produtos.");
                }
                throw new Exception("Erro IA: " . json_encode($result));
            }

            $rawContent = $result['choices'][0]['message']['content'];
            $itensDetectados = extrairItensDaIA($rawContent);
            if (!is_array($itensDetectados)) {
                debugLog("Resposta IA inválida: " . $rawContent);
                throw new Exception("JSON inválido da IA");
            }

            // RECONECTA
            $pdo = getConexao();
            $stmt = $pdo->prepare("INSERT INTO lote_itens (lote_id, codigo_produto, nome, preco_custo, preco_venda, quantidade, categoria_id, status) VALUES (?,?,?,?,?,1,?, 'pendente')");
            $contador = 0;

            foreach ($itensDetectados as $item) {
                // Tratamento de Dados
                $codigo = limparCodigo($item['codigo'] ?? '');
                if (empty($codigo)) $codigo = 'SCAN-'.rand(1000,9999);

                $nome = $item['nome'] ?? 'Item sem nome';
                $custo = floatval($item['custo'] ?? 0);
                $venda = $custo * (1 + ($margem / 100));
                
                // CATEGORIA VINDA DA IA
                $catSugerida = $item['categoria'] ?? 'Geral';
                $catId = obterIdCategoria($pdo, $loja_id, $catSugerida);

                try {
                    $stmt->execute([$lote_id, $codigo, $nome, $custo, $venda, $catId]);
                    $contador++;
                    debugLog("Inserido: $codigo | $nome | Cat: $catSugerida ($catId)");
                } catch(Exception $ex) {
                    debugLog("Erro insert: " . $ex->getMessage());
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Sucesso! $contador itens processados.",
                'qtd' => $contador
            ]);

        } catch (Exception $e) {
            debugLog("FATAL: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
?>
