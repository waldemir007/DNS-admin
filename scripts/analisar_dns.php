<?php
/**
 * analisar_dns.php — Analisa o log do Bind9 e salva métricas em JSON.
 * 
 * Roda via cron a cada 5 minutos.
 * 
 * Saída: /var/www/dns-admin/cache/dns_stats.json
 * 
 * Analisa 4 janelas:
 *   - 5min  (tempo real)
 *   - 1h    (tendência)
 *   - 24h   (visão geral)
 *   - 30d   (histórico — snapshot diário)
 * 
 * ⚡ Novidades v1.0.3:
 *   - Suporte a IPv6 no Top 10 IPs
 *   - Suporte a blocos IPv6 (/64) na tabela de Blocos
 */

require_once __DIR__ . '/../config/database.php';

const QUERY_LOG = '/var/log/named/query.log';
const NAMED_OPTIONS = '/etc/bind/named.conf.options';
const CACHE_FILE = '/var/www/dns-admin/cache/dns_stats.json';
const SNAPSHOTS_FILE = '/var/www/dns-admin/cache/dns_snapshots.json';
const MAX_LER_BYTES = 100000000; // 100 MB por arquivo

function log_msg($m) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
}

log_msg('Análise iniciada');

// ============================================================
// Regex de linha do Bind
// ============================================================
$regex = '/^(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})\.\d+ queries: info: client @\S+ (\S+)#\d+ \(([^)]+)\): query: (\S+) IN (\w+) ([^\s]+)/';

// ============================================================
// Filtro de infraestrutura DNS (root servers, arpa, etc)
// ============================================================
function isInfraestrutura($dominio) {
    if (preg_match('/^[a-m]\.root-servers\.net$/i', $dominio)) return true;
    if (preg_match('/\.(root-servers|gtld-servers)\.net$/i', $dominio)) return true;
    if (preg_match('/\.arpa$/i', $dominio)) return true;
    if (in_array($dominio, ['localhost', 'version.bind', 'hostname.bind', 'id.server'])) return true;
    return false;
}

// ============================================================
// Extrai as redes do allow-recursion
// ============================================================
$redes = [];
if (file_exists(NAMED_OPTIONS)) {
    $conteudo = file_get_contents(NAMED_OPTIONS);
    if (preg_match('/allow-recursion\s*\{([^}]+)\}/s', $conteudo, $m)) {
        if (preg_match_all('/([0-9a-fA-F.:]+(?:\/\d+)?)\s*;/', $m[1], $matches)) {
            foreach ($matches[1] as $ip) {
                $ip = trim($ip);
                if ($ip === '') continue;
                if (strpos($ip, '/') !== false) {
                    $redes[] = $ip;
                } else {
                    $bits = (strpos($ip, ':') !== false) ? 128 : 32;
                    $redes[] = $ip . '/' . $bits;
                }
            }
        }
    }
}
log_msg('Redes do Bind: ' . implode(', ', $redes));

// ============================================================
// Verifica se o log existe
// ============================================================
if (!file_exists(QUERY_LOG)) {
    log_msg('ERRO: log não existe');
    exit(1);
}

$limite24h = time() - (24 * 3600);

// Arquivos de log (mais antigo primeiro)
$arquivos = glob(QUERY_LOG . '*');
usort($arquivos, function($a, $b) {
    if ($a === QUERY_LOG) return 1;
    if ($b === QUERY_LOG) return -1;
    $aNum = (int)substr($a, strrpos($a, '.') + 1);
    $bNum = (int)substr($b, strrpos($b, '.') + 1);
    return $bNum <=> $aNum;
});

// Estruturas por janela
$janelas = [
    '5min' => ['segundos' => 300,   'eventos' => []],
    '1h'   => ['segundos' => 3600,  'eventos' => []],
    '24h'  => ['segundos' => 86400, 'eventos' => []],
];

$totalLido = 0;
$totalIgnorado = 0;

foreach ($arquivos as $arq) {
    if (!is_readable($arq)) continue;
    if (!file_exists($arq)) continue;
    
    $tamanho = filesize($arq);
    $pos = max(0, $tamanho - MAX_LER_BYTES);
    
    $fh = @fopen($arq, 'r');
    if (!$fh) continue;
    
    fseek($fh, $pos);
    if ($pos > 0) fgets($fh);
    
    while (($linha = fgets($fh)) !== false) {
        if (!preg_match($regex, $linha, $m)) continue;
        
        $ts = strtotime($m[1]);
        if ($ts === false || $ts < $limite24h) continue;
        
        $cliente = preg_replace('/#.*/', '', $m[2]);
        $dominio = strtolower($m[4]);
        $tipo = strtoupper($m[5]);
        $flags = $m[6];
        
        if (isInfraestrutura($dominio)) {
            $totalIgnorado++;
            continue;
        }
        
        $resposta = 'NOERROR';
        if (strpos($flags, 'NXDOMAIN') !== false) $resposta = 'NXDOMAIN';
        elseif (strpos($flags, 'SERVFAIL') !== false) $resposta = 'SERVFAIL';
        elseif (strpos($flags, 'REFUSED') !== false) $resposta = 'REFUSED';
        
        $evento = [
            'ts' => $ts,
            'cliente' => $cliente,
            'dominio' => $dominio,
            'tipo' => $tipo,
            'resposta' => $resposta,
        ];
        
        $totalLido++;
        
        $idadeSegundos = time() - $ts;
        foreach ($janelas as $nome => &$j) {
            if ($idadeSegundos <= $j['segundos']) {
                $j['eventos'][] = $evento;
            }
        }
        unset($j);
    }
    
    fclose($fh);
}

log_msg("Linhas lidas: $totalLido (ignoradas: $totalIgnorado infraestrutura)");

// ============================================================
// Funções auxiliares
// ============================================================

function ipNaRede($ip, $rede, $bits) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) 
        && filter_var($rede, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $mask = -1 << (32 - $bits);
        return (ip2long($ip) & $mask) === (ip2long($rede) & $mask);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) 
        && filter_var($rede, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $ipBin = inet_pton($ip); $redeBin = inet_pton($rede);
        $bytes = (int)floor($bits / 8); $bitsRest = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($redeBin, 0, $bytes)) return false;
        if ($bitsRest > 0) {
            $mask = 0xFF << (8 - $bitsRest) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($redeBin[$bytes]) & $mask)) return false;
        }
        return true;
    }
    return false;
}

/**
 * ⚡ Identifica o bloco de um IP (IPv4 ou IPv6).
 */
function identificarBloco($ip, $redes) {
    // ⚡ IPv6: agrupa em /64
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return identificarBlocoIPv6($ip);
    }
    
    // IPv4 — usa as redes configuradas
    static $redesOrd = null;
    if ($redesOrd === null) {
        $redesOrd = $redes;
        usort($redesOrd, function($a, $b) {
            $ba = (int)explode('/', $a)[1];
            $bb = (int)explode('/', $b)[1];
            return $bb <=> $ba;
        });
    }
    foreach ($redesOrd as $rede) {
        // Só compara IPv4 vs IPv4
        if (strpos($rede, ':') !== false) continue;
        [$redeIp, $bits] = explode('/', $rede);
        if (ipNaRede($ip, $redeIp, (int)$bits)) return $rede;
    }
    return 'outros';
}

/**
 * ⚡ Agrupa IPv6 em bloco /64.
 * 
 * Ex: 2804:57a4:300:1::1 → 2804:57a4:300:1::/64
 */
function identificarBlocoIPv6($ip) {
    $bin = @inet_pton($ip);
    if ($bin === false) return 'outros';
    
    // Pega os primeiros 8 bytes (prefixo /64)
    $prefixo = substr($bin, 0, 8);
    
    // Converte para hexadecimal
    $hex = bin2hex($prefixo);
    
    // Formata em grupos de 4 (IPv6 legível)
    $partes = str_split($hex, 4);
    $compacto = implode(':', $partes);
    
    return $compacto . '::/64';
}

function extrairTLD($dominio) {
    $partes = explode('.', $dominio);
    if (count($partes) < 2) return $dominio;
    return end($partes);
}

// ============================================================
// Processa cada janela
// ============================================================
function processarJanela($eventos, $segundos, $redes) {
    $dominios = [];
    $ips = [];
    $blocos = [];
    $tipos = [];
    $respostas = [];
    $tlds = [];
    $serieTemporal = [];
    $dominiosComErro = [];
    $ipsSuspeitos = [];
    $total = count($eventos);
    
    $agruparPor = ($segundos <= 3600) ? 'minuto' : 'hora';
    
    foreach ($eventos as $ev) {
        // Domínios
        $chaveDom = $ev['dominio'] . '|' . $ev['tipo'];
        $dominios[$chaveDom] = ($dominios[$chaveDom] ?? 0) + 1;
        
        // IPs (aceita IPv4 e IPv6)
        $ips[$ev['cliente']] = ($ips[$ev['cliente']] ?? 0) + 1;
        
        // Blocos (aceita IPv4 e IPv6)
        $bloco = identificarBloco($ev['cliente'], $redes);
        if (!isset($blocos[$bloco])) {
            $blocos[$bloco] = ['total' => 0, 'ips' => []];
        }
        $blocos[$bloco]['total']++;
        $blocos[$bloco]['ips'][$ev['cliente']] = true;
        
        // Tipos
        $tipos[$ev['tipo']] = ($tipos[$ev['tipo']] ?? 0) + 1;
        
        // Respostas
        $respostas[$ev['resposta']] = ($respostas[$ev['resposta']] ?? 0) + 1;
        
        // TLDs (só para domínios com ponto)
        if (strpos($ev['dominio'], '.') !== false) {
            $tld = extrairTLD($ev['dominio']);
            $tlds[$tld] = ($tlds[$tld] ?? 0) + 1;
        }
        
        // Erros por domínio
        if ($ev['resposta'] !== 'NOERROR') {
            $dominiosComErro[$ev['dominio']] = ($dominiosComErro[$ev['dominio']] ?? 0) + 1;
        }
        
        // IPs suspeitos
        if ($ev['resposta'] === 'NXDOMAIN' || $ev['resposta'] === 'SERVFAIL') {
            $ipsSuspeitos[$ev['cliente']] = ($ipsSuspeitos[$ev['cliente']] ?? 0) + 1;
        }
        
        // Série temporal
        $bucket = ($agruparPor === 'minuto') 
            ? date('Y-m-d H:i:00', $ev['ts'])
            : date('Y-m-d H:00:00', $ev['ts']);
        if (!isset($serieTemporal[$bucket])) {
            $serieTemporal[$bucket] = ['total' => 0, 'erros' => 0];
        }
        $serieTemporal[$bucket]['total']++;
        if ($ev['resposta'] !== 'NOERROR') $serieTemporal[$bucket]['erros']++;
    }
    
    // Top 10 domínios
    arsort($dominios);
    $topDominios = [];
    $i = 0;
    foreach ($dominios as $chave => $qtd) {
        if ($i++ >= 10) break;
        [$dom, $tp] = explode('|', $chave);
        $topDominios[] = ['dominio' => $dom, 'tipo' => $tp, 'total' => $qtd];
    }
    
    // Top 10 IPs (aceita IPv4 e IPv6)
    arsort($ips);
    $topIps = [];
    $i = 0;
    foreach ($ips as $ip => $qtd) {
        if ($i++ >= 10) break;
        $topIps[] = ['ip' => $ip, 'total' => $qtd];
    }
    
    // Blocos (aceita IPv4 e IPv6)
    $listaBlocos = [];
    foreach ($blocos as $bloco => $info) {
        $listaBlocos[] = [
            'bloco' => $bloco,
            'total' => $info['total'],
            'ips_unicos' => count($info['ips']),
        ];
    }
    usort($listaBlocos, fn($a, $b) => $b['total'] <=> $a['total']);
    
    // Top 10 TLDs
    arsort($tlds);
    $topTlds = [];
    $i = 0;
    foreach ($tlds as $tld => $qtd) {
        if ($i++ >= 10) break;
        $topTlds[] = ['tld' => $tld, 'total' => $qtd];
    }
    
    // Top 10 domínios com erro
    arsort($dominiosComErro);
    $topErros = [];
    $i = 0;
    foreach ($dominiosComErro as $dom => $qtd) {
        if ($i++ >= 10) break;
        $topErros[] = ['dominio' => $dom, 'total' => $qtd];
    }
    
    // Top 10 IPs suspeitos
    arsort($ipsSuspeitos);
    $topSuspeitos = [];
    $i = 0;
    foreach ($ipsSuspeitos as $ip => $qtd) {
        if ($i++ >= 10) break;
        $topSuspeitos[] = ['ip' => $ip, 'total' => $qtd];
    }
    
    // Série temporal
    ksort($serieTemporal);
    $serieArray = [];
    foreach ($serieTemporal as $bucket => $dados) {
        $serieArray[] = [
            'periodo' => $bucket,
            'total' => $dados['total'],
            'erros' => $dados['erros'],
        ];
    }
    
    $qps = $segundos > 0 ? round($total / $segundos, 2) : 0;
    
    return [
        'janela_segundos' => $segundos,
        'total_queries' => $total,
        'ips_unicos' => count($ips),
        'dominios_unicos' => count(array_unique(array_map(fn($k) => explode('|', $k)[0], array_keys($dominios)))),
        'qps' => $qps,
        'top_dominios' => $topDominios,
        'top_ips' => $topIps,
        'blocos' => $listaBlocos,
        'tipos' => $tipos,
        'respostas' => $respostas,
        'top_tlds' => $topTlds,
        'dominios_erro' => $topErros,
        'ips_suspeitos' => $topSuspeitos,
        'serie_temporal' => $serieArray,
    ];
}

// ============================================================
// Processa 5min, 1h, 24h
// ============================================================
$resultado = [
    'gerado_em' => date('Y-m-d H:i:s'),
    'proxima_atualizacao' => date('Y-m-d H:i:s', time() + 300),
    'redes_configuradas' => $redes,
    'janelas' => [],
];

foreach ($janelas as $nome => $info) {
    $resultado['janelas'][$nome] = processarJanela($info['eventos'], $info['segundos'], $redes);
    log_msg("Janela {$nome}: {$resultado['janelas'][$nome]['total_queries']} queries");
}

// ============================================================
// JANELA 30 DIAS — via snapshots diários
// ============================================================
$snapshots = [];
if (file_exists(SNAPSHOTS_FILE)) {
    $json = @file_get_contents(SNAPSHOTS_FILE);
    if ($json) $snapshots = @json_decode($json, true) ?: [];
}

$hoje = date('Y-m-d');
$dados24h = $resultado['janelas']['24h'];

$snapshotHoje = [
    'data' => $hoje,
    'total_queries' => $dados24h['total_queries'],
    'ips_unicos' => $dados24h['ips_unicos'],
    'dominios_unicos' => $dados24h['dominios_unicos'],
    'top_dominios' => array_slice($dados24h['top_dominios'], 0, 10),
    'top_ips' => array_slice($dados24h['top_ips'], 0, 10),
    'tipos' => $dados24h['tipos'],
    'respostas' => $dados24h['respostas'],
    'atualizado_em' => date('Y-m-d H:i:s'),
];

$encontrouHoje = false;
foreach ($snapshots as $i => $s) {
    if (($s['data'] ?? '') === $hoje) {
        $snapshots[$i] = $snapshotHoje;
        $encontrouHoje = true;
        break;
    }
}
if (!$encontrouHoje) {
    $snapshots[] = $snapshotHoje;
}

$limite30d = date('Y-m-d', strtotime('-30 days'));
$snapshots = array_values(array_filter($snapshots, fn($s) => ($s['data'] ?? '') >= $limite30d));
usort($snapshots, fn($a, $b) => strcmp($b['data'], $a['data']));

if (!is_dir(dirname(SNAPSHOTS_FILE))) @mkdir(dirname(SNAPSHOTS_FILE), 0755, true);
@file_put_contents(SNAPSHOTS_FILE, json_encode($snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Agrega os 30 dias
$janela30d = [
    'janela_segundos' => 30 * 86400,
    'total_queries' => 0,
    'ips_unicos' => 0,
    'dominios_unicos' => 0,
    'qps' => 0,
    'top_dominios' => [],
    'top_ips' => [],
    'blocos' => [],
    'tipos' => [],
    'respostas' => [],
    'top_tlds' => [],
    'dominios_erro' => [],
    'ips_suspeitos' => [],
    'serie_temporal' => [],
    'snapshots' => $snapshots,
];

$somaDominios = [];
$somaIps = [];
$somaTipos = [];
$somaRespostas = [];
$serieDiaria = [];

foreach ($snapshots as $s) {
    $janela30d['total_queries'] += (int)($s['total_queries'] ?? 0);
    
    foreach (($s['top_dominios'] ?? []) as $d) {
        $chave = $d['dominio'] . '|' . $d['tipo'];
        if (!isset($somaDominios[$chave])) {
            $somaDominios[$chave] = ['dominio' => $d['dominio'], 'tipo' => $d['tipo'], 'total' => 0];
        }
        $somaDominios[$chave]['total'] += (int)$d['total'];
    }
    foreach (($s['top_ips'] ?? []) as $ip) {
        $somaIps[$ip['ip']] = ($somaIps[$ip['ip']] ?? 0) + (int)$ip['total'];
    }
    foreach (($s['tipos'] ?? []) as $t => $q) {
        $somaTipos[$t] = ($somaTipos[$t] ?? 0) + (int)$q;
    }
    foreach (($s['respostas'] ?? []) as $r => $q) {
        $somaRespostas[$r] = ($somaRespostas[$r] ?? 0) + (int)$q;
    }
    
    $serieDiaria[] = [
        'periodo' => $s['data'],
        'total' => (int)($s['total_queries'] ?? 0),
        'erros' => 0,
    ];
}

usort($somaDominios, fn($a, $b) => $b['total'] <=> $a['total']);
$janela30d['top_dominios'] = array_slice(array_values($somaDominios), 0, 10);

arsort($somaIps);
$i = 0;
foreach ($somaIps as $ip => $qtd) {
    if ($i++ >= 10) break;
    $janela30d['top_ips'][] = ['ip' => $ip, 'total' => $qtd];
}

$janela30d['tipos'] = $somaTipos;
$janela30d['respostas'] = $somaRespostas;
$janela30d['serie_temporal'] = $serieDiaria;

$resultado['janelas']['30d'] = $janela30d;

log_msg("Janela 30d: " . count($snapshots) . " snapshots, {$janela30d['total_queries']} queries agregadas");

// ============================================================
// Salva JSON principal
// ============================================================
$json = json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (@file_put_contents(CACHE_FILE, $json) === false) {
    log_msg('ERRO: falha ao salvar cache');
    exit(1);
}

$tamanho = round(filesize(CACHE_FILE) / 1024, 1);
log_msg("Cache salvo: {$tamanho} KB em " . CACHE_FILE);
log_msg('Fim');
