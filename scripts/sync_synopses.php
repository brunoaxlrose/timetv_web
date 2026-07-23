<?php
/**
 * Script de sincronização automática de sinopses ausentes.
 * Busca no banco de dados itens e episódios sem descrição, e tenta preenchê-los
 * usando a API da Wikipedia (grátis) ou a API do Gemini (camada gratuita).
 */

// 1. Carregar configurações do banco de dados do projeto
$globalConfigPath = __DIR__ . '/../config/autoload/global.php';
$localConfigPath = __DIR__ . '/../config/autoload/local.php';

if (!file_exists($globalConfigPath)) {
    die("Erro: Configuração global.php não encontrada em {$globalConfigPath}\n");
}

$globalConfig = require $globalConfigPath;
$localConfig = file_exists($localConfigPath) ? require $localConfigPath : [];

$dbConfig = array_merge($globalConfig['db'] ?? [], $localConfig['db'] ?? []);

if (empty($dbConfig['dsn'])) {
    die("Erro: DSN do banco de dados não configurada.\n");
}

// 2. Carregar variáveis de ambiente de um arquivo .env se existir
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Remover aspas extras
        $value = trim($value, '"\'');
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }
}

$geminiApiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? null);

echo "Iniciando sincronização de sinopses...\n";
if ($geminiApiKey) {
    echo "API Key do Gemini encontrada (usando fallback de IA).\n";
} else {
    echo "AVISO: Chave GEMINI_API_KEY não configurada. Somente a busca via Wikipedia funcionará.\n";
}

try {
    $pdo = new PDO($dbConfig['dsn'], $dbConfig['username'] ?? null, $dbConfig['password'] ?? null);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Erro ao conectar no banco de dados: " . $e->getMessage() . "\n");
}

// Helper para fazer requisições HTTP GET/POST simples
function makeHttpRequest($url, $postData = null, $headers = []) {
    $opts = [
        'http' => [
            'method' => $postData ? 'POST' : 'GET',
            'header' => implode("\r\n", array_merge([
                'User-Agent: TVTimeSyncScript/1.0',
            ], $headers)),
            'content' => $postData,
            'ignore_errors' => true
        ]
    ];
    $context = stream_context_create($opts);
    $result = @file_get_contents($url, false, $context);
    return $result;
}

// Helper para buscar na Wikipedia
function fetchWikipediaSynopsis($title, $type) {
    $searchTerm = $title;
    if ($type === 'anime') {
        $searchTerm .= " (anime)";
    } elseif ($type === 'series' || $type === 'novela') {
        $searchTerm .= " (telenovela)"; // Tenta telenovela primeiro
    }

    // 1. Procurar a página mais relevante
    $searchUrl = "https://pt.wikipedia.org/w/api.php?action=query&list=search&srsearch=" . urlencode($searchTerm) . "&utf8=&format=json";
    $searchRes = makeHttpRequest($searchUrl);
    if (!$searchRes) return null;

    $searchData = json_decode($searchRes, true);
    $pageTitle = $searchData['query']['search'][0]['title'] ?? null;

    // Se não encontrou com termo específico, tenta buscar apenas com o título original
    if (!$pageTitle) {
        $searchUrl = "https://pt.wikipedia.org/w/api.php?action=query&list=search&srsearch=" . urlencode($title) . "&utf8=&format=json";
        $searchRes = makeHttpRequest($searchUrl);
        if ($searchRes) {
            $searchData = json_decode($searchRes, true);
            $pageTitle = $searchData['query']['search'][0]['title'] ?? null;
        }
    }

    if (!$pageTitle) return null;

    // 2. Buscar o extracto da página encontrada
    $extractUrl = "https://pt.wikipedia.org/w/api.php?action=query&prop=extracts&exintro=1&explaintext=1&titles=" . urlencode($pageTitle) . "&format=json";
    $extractRes = makeHttpRequest($extractUrl);
    if (!$extractRes) return null;

    $extractData = json_decode($extractRes, true);
    $pages = $extractData['query']['pages'] ?? [];
    foreach ($pages as $page) {
        if (isset($page['extract']) && strlen(trim($page['extract'])) > 100) {
            return trim($page['extract']);
        }
    }

    return null;
}

// Helper para gerar sinopse usando a API do Gemini
function generateGeminiSynopsis($prompt, $apiKey) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.5,
            'maxOutputTokens' => 400
        ]
    ];

    $response = makeHttpRequest($url, json_encode($payload), ['Content-Type: application/json']);
    if (!$response) return null;

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    
    if ($text) {
        return trim($text);
    }
    return null;
}

// ==========================================
// 1. Processar ITENS (Séries/Animes/Novelas/Filmes)
// ==========================================
$stmt = $pdo->query("
    SELECT id_item, title, release_year, type 
    FROM item 
    WHERE description IS NULL 
       OR description = '' 
       OR description = 'Nenhuma sinopse disponível.'
       OR description = 'Nenhuma sinopse disponivel.'
");
$items = $stmt->fetchAll();

echo "Encontrados " . count($items) . " itens sem sinopse.\n";

foreach ($items as $item) {
    $id = $item['id_item'];
    $title = $item['title'];
    $year = $item['release_year'];
    $type = $item['type'];
    
    echo "Processando item: {$title} ({$year}) [{$type}]... ";
    
    $synopsis = null;

    // Tentar Wikipedia primeiro
    $synopsis = fetchWikipediaSynopsis($title, $type);
    
    if ($synopsis) {
        echo "[Wikipedia] Encontrado! ";
    } elseif ($geminiApiKey) {
        // Fallback para o Gemini
        echo "[Wikipedia não encontrou. Chamando Gemini...] ";
        $prompt = "Escreva uma sinopse em português curta (máximo de 4 parágrafos) e sem spoilers para a obra/novela/série/filme/anime: '{$title}' lançado no ano {$year}. Escreva apenas a sinopse diretamente, sem introduções ou observações.";
        $synopsis = generateGeminiSynopsis($prompt, $geminiApiKey);
    }
    
    if ($synopsis) {
        $update = $pdo->prepare("UPDATE item SET description = :description WHERE id_item = :id");
        $update->execute([':description' => $synopsis, ':id' => $id]);
        echo "✓ Atualizado no banco de dados.\n";
    } else {
        echo "✗ Nenhuma sinopse encontrada.\n";
    }
    
    // Pequena pausa para evitar limites de taxa
    usleep(500000);
}

// ==========================================
// 2. Processar EPISÓDIOS
// ==========================================
$stmt = $pdo->query("
    SELECT e.id_episodio, e.season_number, e.episode_number, e.title as ep_title, i.title as show_title, i.type 
    FROM episodio e
    JOIN item i ON e.id_item = i.id_item
    WHERE e.description IS NULL 
       OR e.description = '' 
       OR e.description = 'Nenhuma sinopse disponível.'
       OR e.description = 'Nenhuma sinopse disponivel.'
");
$episodes = $stmt->fetchAll();

echo "Encontrados " . count($episodes) . " episódios sem sinopse.\n";

if (count($episodes) > 0 && !$geminiApiKey) {
    echo "Para preencher episódios individuais automaticamente, é necessário ter a chave GEMINI_API_KEY no arquivo .env.\n";
}

foreach ($episodes as $ep) {
    if (!$geminiApiKey) break; // Wikipedia geralmente não tem sinopse de episódios individuais
    
    $id = $ep['id_episodio'];
    $show = $ep['show_title'];
    $season = $ep['season_number'];
    $number = $ep['episode_number'];
    $epTitle = $ep['ep_title'];
    
    echo "Processando episódio: {$show} - S{$season}E{$number} ('{$epTitle}')... ";
    
    $prompt = "Escreva um resumo curto de 2 a 4 frases em português (sem spoilers importantes) para o episódio número {$number} da temporada {$season} da série/novela/anime '{$show}', intitulado '{$epTitle}'. Vá direto ao ponto, não diga 'aqui está o resumo'.";
    
    $synopsis = generateGeminiSynopsis($prompt, $geminiApiKey);
    
    if ($synopsis) {
        $update = $pdo->prepare("UPDATE episodio SET description = :description WHERE id_episodio = :id");
        $update->execute([':description' => $synopsis, ':id' => $id]);
        echo "✓ Atualizado.\n";
    } else {
        echo "✗ Erro ao gerar.\n";
    }
    
    // Pausa para limite de taxa da API gratuita do Gemini (15 RPM)
    sleep(4);
}

echo "Sincronização concluída!\n";
