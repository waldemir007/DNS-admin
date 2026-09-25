<?php
/**
 * agregar_stats.php — Lê o log do Bind9 e popula as tabelas agregadas.
 * 
 * Executado via cron a cada 5 minutos.
 * 
 * Fluxo:
 *   1. Lê a última posição salva (stats_controle.pos_log)
 *   2. Abre o log e pula para essa posição
 *   3. Lê TODAS as linhas novas
 *   4. Agrega em memória (rápido)
 *   5. Grava em lote nas tabelas stats_*
 *   6. Salva a nova posição
 */

require_once __DIR__ . '/../config/database.php';

const QUERY_LOG = '/var/log/named/query.log';
const JANELA_MINUTOS = 5;

function log_msg($m) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
}

log_msg('Agregador iniciado');

// ============================================================
// 1. Verificar log
// ============================================================
if (!file_exists(QUERY_LOG)) {
    log_msg('ERRO: ' . QUERY_LOG . ' não existe');
    exit(1);
}

$tamanhoAtual = filesize(QUERY_LOG);

// ============================================================
// 2. Ler posição salva
// ============================================================
$stmt = db()->prepare("SELECT valor FROM stats_controle WHERE chave = 'pos_log'");
$stmt->execute();
$posInicial = (int)($stmt->fetchColumn() ?: 0);

// Detecta rotação do log (tamanho menor que a posição salva)
if ($tamanhoAtual < $posInicial) {
    log_msg('Log rotacionado — reiniciando do começo');
    $posInicial = 0;
}

if ($tamanhoAtual === $posInicial) {
    log_msg('Sem novas queries');
    exit(0);
}

log_msg("Lendo de " . $posInicial . " até " . $tamanhoAtual . " (" . ($tamanhoAtual - $posInicial) . " bytes)");

// ============================================================
// 3. Abrir e ler o log
// ============================================================
$fh = fopen(QUERY_LOG, 'r');
if (!$fh) {
    log_msg('ERRO: não consegui abrir o log');
    exit(1);
}

fseek($fh, $posInicial);

// Se começamos no meio do arquivo, descarta a primeira linha parcial
if ($posInicial > 0) {
    fgets($fh);
}

// ============================================================
// 4. Agregação em memória
// ============================================================
$dominios = [];      // ['dominio|tipo' => ['dominio','tipo','total','erros']]
$clientes = [];      // ['cliente' => ['cliente','total','erros','dominios' => []]]
$tipos = [];         // ['A' => total]
$respostas = [];     // ['NOERROR' => total]

$totalGeral = 0;
$errosGeral = 0;

while (($linha = fgets($fh)) !== false) {
    // Formato: 20-Sep-2026 14:35:42.123 queries: info: client @0x... 172.16.0.5#54321 (google.com): query: google.com IN A + (172.16.0.157)
    if (!preg_match(
        '/^(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})\.\d+ queries: info: client @\S+ (\S+)#\d+ \(([^)]+)\): query: (\S+) IN (\w+) ([^\s]+)/',
        $linha, $m
    )) {
        continue;
    }
    
    $cliente = preg_replace('/#.*/', '', $m[2]);
    $dominio = strtolower($m[4]);
    $tipo = strtoupper($m[5]);
    $flags = $m[6];
    
    // Ignora internos
    if (in_array($dominio, ['localhost', 'version.bind', 'hostname.bind', 'id.server'])) {
        continue;
    }
    
    // Detecta resposta
    $resposta = 'NOERROR';
    if (strpos($flags, 'NXDOMAIN') !== false) $resposta = 'NXDOMAIN';
    elseif (strpos($flags, 'SERVFAIL') !== false) $resposta = 'SERVFAIL';
    elseif (strpos($flags, 'REFUSED') !== false) $resposta = 'REFUSED';
    elseif (strpos($flags, 'FORMERR') !== false) $resposta = 'FORMERR';
    
    $isErro = ($resposta !== 'NOERROR');
    
    // ---- Domínios ----
    $chaveDom = $dominio . '|' . $tipo;
    if (!isset($dominios[$chaveDom])) {
        $dominios[$chaveDom] = ['dominio' => $dominio, 'tipo' => $tipo, 'total' => 0, 'erros' => 0];
    }
    $dominios[$chaveDom]['total']++;
    if ($isErro) $dominios[$chaveDom]['erros']++;
    
    // ---- Clientes ----
    if (!isset($clientes[$cliente])) {
        $clientes[$cliente] = ['cliente' => $cliente, 'total' => 0, 'erros' => 0, 'dominios' => []];
    }
    $clientes[$cliente]['total']++;
    $clientes[$cliente]['dominios'][$dominio] = true;
    if ($isErro) $clientes[$cliente]['erros']++;
    
    // ---- Tipos ----
    $tipos[$tipo] = ($tipos[$tipo] ?? 0) + 1;
    
    // ---- Respostas ----
    $respostas[$resposta] = ($respostas[$resposta] ?? 0) + 1;
    
    $totalGeral++;
    if ($isErro) $errosGeral++;
}

$novaPos = ftell($fh);
fclose($fh);

log_msg("Processadas $totalGeral queries (erros: $errosGeral)");
log_msg("Únicos: " . count($dominios) . " domínios, " . count($clientes) . " clientes");

// ============================================================
// 5. Calcular janela (arredondada para múltiplo de 5 min)
// ============================================================
// Ex: 08:02:02 → 08:00:00
//     08:05:34 → 08:05:00
//     08:09:15 → 08:05:00
$janelaTs = floor(time() / (JANELA_MINUTOS * 60)) * (JANELA_MINUTOS * 60);
$janela = date('Y-m-d H:i:00', $janelaTs);

log_msg("Gravando na janela: $janela");

// ============================================================
// 6. Gravar no banco
// ============================================================
db()->beginTransaction();

try {
    // ---- Domínios ----
    if (!empty($dominios)) {
        $stmtDom = db()->prepare("
            INSERT INTO stats_dominios (periodo, janela_inicio, dominio, tipo, total, erros)
            VALUES ('5min', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE total = total + VALUES(total), erros = erros + VALUES(erros)
        ");
        foreach ($dominios as $d) {
            $stmtDom->execute([$janela, $d['dominio'], $d['tipo'], $d['total'], $d['erros']]);
        }
    }
    
    // ---- Clientes ----
    if (!empty($clientes)) {
        $stmtCli = db()->prepare("
            INSERT INTO stats_clientes (periodo, janela_inicio, cliente, total, erros, dominios_unicos)
            VALUES ('5min', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                total = total + VALUES(total),
                erros = erros + VALUES(erros),
                dominios_unicos = dominios_unicos + VALUES(dominios_unicos)
        ");
        foreach ($clientes as $c) {
            $stmtCli->execute([$janela, $c['cliente'], $c['total'], $c['erros'], count($c['dominios'])]);
        }
    }
    
    // ---- Tipos ----
    if (!empty($tipos)) {
        $stmtTip = db()->prepare("
            INSERT INTO stats_tipos (periodo, janela_inicio, tipo, total)
            VALUES ('5min', ?, ?, ?)
            ON DUPLICATE KEY UPDATE total = total + VALUES(total)
        ");
        foreach ($tipos as $tipo => $total) {
            $stmtTip->execute([$janela, $tipo, $total]);
        }
    }
    
    // ---- Respostas ----
    if (!empty($respostas)) {
        $stmtResp = db()->prepare("
            INSERT INTO stats_respostas (periodo, janela_inicio, resposta, total)
            VALUES ('5min', ?, ?, ?)
            ON DUPLICATE KEY UPDATE total = total + VALUES(total)
        ");
        foreach ($respostas as $resp => $total) {
            $stmtResp->execute([$janela, $resp, $total]);
        }
    }
    
    // ---- Métricas gerais ----
    $metricas = [
        'total_queries' => $totalGeral,
        'erros' => $errosGeral,
        'clientes_unicos' => count($clientes),
        'dominios_unicos' => count(array_unique(array_column($dominios, 'dominio'))),
    ];
    
    $stmtGer = db()->prepare("
        INSERT INTO stats_geral (periodo, janela_inicio, metrica, valor)
        VALUES ('5min', ?, ?, ?)
        ON DUPLICATE KEY UPDATE valor = valor + VALUES(valor)
    ");
    foreach ($metricas as $metrica => $valor) {
        $stmtGer->execute([$janela, $metrica, $valor]);
    }
    
    db()->commit();
} catch (Exception $e) {
    db()->rollBack();
    log_msg('ERRO ao gravar: ' . $e->getMessage());
    exit(1);
}

// ============================================================
// 7. Salvar nova posição
// ============================================================
db()->prepare("
    INSERT INTO stats_controle (chave, valor) VALUES ('pos_log', ?)
    ON DUPLICATE KEY UPDATE valor = VALUES(valor)
")->execute([(string)$novaPos]);

db()->prepare("
    INSERT INTO stats_controle (chave, valor) VALUES ('ultima_agregacao', ?)
    ON DUPLICATE KEY UPDATE valor = VALUES(valor)
")->execute([date('Y-m-d H:i:s')]);

log_msg('OK — posição salva em ' . $novaPos);
log_msg('Fim');
