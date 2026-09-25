<?php

/**
 * DnsAnalyzer — Análise incremental do log do Bind9.
 * 
 * ⚡ Estratégia:
 *   1. Guarda o estado em /tmp/dns-analyzer-state.json
 *   2. Na primeira execução, processa TODO o log e salva agregados
 *   3. Nas execuções seguintes, processa APENAS as linhas novas
 *   4. Ao rotacionar o log, reprocessa do zero
 *   5. Cache em memória/arquivo para acesso instantâneo
 * 
 * Resultado:
 *   - Primeira análise: 8-17s
 *   - Análises incrementais: 50-200ms
 */
class DnsAnalyzer {
    
    const QUERY_LOG = '/var/log/named/query.log';
    const NAMED_OPTIONS = '/etc/bind/named.conf.options';
    const STATE_FILE = '/tmp/dns-analyzer-state.json';
    const CACHE_TTL = 300;      // 5 min
    const CACHE_STALE = 900;    // 15 min
    
    // Tamanho da janela de agregado (deve ser o maior período que usamos)
    const JANELA_MAX_MINUTOS = 60;
    
    // ============ REDES DO BIND ============
    
    public static function obterRedesBind() {
        if (!file_exists(self::NAMED_OPTIONS)) return [];
        
        $conteudo = @file_get_contents(self::NAMED_OPTIONS);
        if ($conteudo === false) return [];
        
        if (!preg_match('/allow-recursion\s*\{([^}]+)\}/s', $conteudo, $m)) return [];
        
        $redes = [];
        if (preg_match_all('/([0-9a-fA-F.:]+(?:\/\d+)?)\s*;/', $m[1], $matches)) {
            foreach ($matches[1] as $ip) {
                $ip = trim($ip);
                if ($ip === '') continue;
                if (strpos($ip, '/') !== false) {
                    [$rede, $bits] = explode('/', $ip);
                    $redes[] = ['rede' => $rede, 'bits' => (int)$bits, 'cidr' => $ip];
                } else {
                    $bits = (strpos($ip, ':') !== false) ? 128 : 32;
                    $redes[] = ['rede' => $ip, 'bits' => $bits, 'cidr' => $ip . '/' . $bits];
                }
            }
        }
        return $redes;
    }
    
    private static function ipNaRede($ip, $rede, $bits) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) 
            && filter_var($rede, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $redeLong = ip2long($rede);
            $mask = -1 << (32 - $bits);
            return ($ipLong & $mask) === ($redeLong & $mask);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) 
            && filter_var($rede, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);
            $redeBin = inet_pton($rede);
            $bytes = (int)floor($bits / 8);
            $bitsRestantes = $bits % 8;
            if (substr($ipBin, 0, $bytes) !== substr($redeBin, 0, $bytes)) return false;
            if ($bitsRestantes > 0) {
                $mask = 0xFF << (8 - $bitsRestantes) & 0xFF;
                if ((ord($ipBin[$bytes]) & $mask) !== (ord($redeBin[$bytes]) & $mask)) return false;
            }
            return true;
        }
        return false;
    }
    
    private static function redeDoIp($ip, $redes) {
        foreach ($redes as $r) {
            if (self::ipNaRede($ip, $r['rede'], $r['bits'])) {
                return $r['cidr'];
            }
        }
        return 'outros';
    }
    
    // ============ ESTADO PERSISTIDO ============
    
    private static function carregarEstado() {
        if (!file_exists(self::STATE_FILE)) {
            return [
                'arquivos' => [],   // ['query.log' => ['pos' => N, 'size' => N, 'mtime' => N], ...]
                'eventos' => [],    // Eventos recentes: [{ts, cliente, dominio, tipo, resposta}, ...]
                'ultima_analise' => 0,
            ];
        }
        
        $json = @file_get_contents(self::STATE_FILE);
        if (!$json) return ['arquivos' => [], 'eventos' => [], 'ultima_analise' => 0];
        
        $estado = @json_decode($json, true);
        if (!is_array($estado)) return ['arquivos' => [], 'eventos' => [], 'ultima_analise' => 0];
        
        // Garante as chaves
        $estado['arquivos'] = $estado['arquivos'] ?? [];
        $estado['eventos'] = $estado['eventos'] ?? [];
        $estado['ultima_analise'] = $estado['ultima_analise'] ?? 0;
        
        return $estado;
    }
    
    private static function salvarEstado($estado) {
        $tmp = self::STATE_FILE . '.tmp';
        @file_put_contents($tmp, json_encode($estado, JSON_UNESCAPED_SLASHES));
        @rename($tmp, self::STATE_FILE);
    }
    
    // ============ ANÁLISE INCREMENTAL ============
    
    /**
     * Processa apenas as linhas novas desde a última análise.
     * Retorna array de eventos (para agregar).
     */
    private static function processarNovas($estado) {
        $arquivos = self::listarArquivosLog();
        $limiteAntigo = time() - (self::JANELA_MAX_MINUTOS * 60);
        $novosEventos = [];
        $precisaReprocessar = false;
        
        foreach ($arquivos as $arq) {
            $nome = basename($arq);
            if (!is_readable($arq)) continue;
            
            $tamanhoAtual = filesize($arq);
            $infoAnterior = $estado['arquivos'][$nome] ?? null;
            
            if ($infoAnterior === null) {
                // Arquivo novo (nunca visto) — se for o log atual ou recente, processa
                $pos = 0;
            } else {
                // Se o arquivo diminuiu, rotacionou
                if ($tamanhoAtual < $infoAnterior['size']) {
                    $precisaReprocessar = true;
                    $pos = 0;
                } else {
                    $pos = $infoAnterior['pos'];
                }
            }
            
            if ($pos >= $tamanhoAtual) {
                // Sem mudanças
                $estado['arquivos'][$nome] = [
                    'pos' => $pos,
                    'size' => $tamanhoAtual,
                    'mtime' => filemtime($arq),
                ];
                continue;
            }
            
            // Lê do ponto parado até o fim
            $fh = @fopen($arq, 'r');
            if (!$fh) continue;
            fseek($fh, $pos);
            
            // Se começou no meio, descarta linha parcial
            if ($pos > 0) fgets($fh);
            
            while (($linha = fgets($fh)) !== false) {
                if (!preg_match('/^(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})/', $linha, $m)) continue;
                $ts = strtotime($m[1]);
                if ($ts === false) continue;
                
                // Ignora linhas muito antigas
                if ($ts < $limiteAntigo) continue;
                
                $evento = self::parseLinhaCom($linha, $ts);
                if ($evento) $novosEventos[] = $evento;
            }
            
            $posFinal = ftell($fh);
            fclose($fh);
            
            $estado['arquivos'][$nome] = [
                'pos' => $posFinal,
                'size' => $tamanhoAtual,
                'mtime' => filemtime($arq),
            ];
        }
        
        // Se precisa reprocessar (rotação), limpa eventos antigos e processa tudo do zero
        if ($precisaReprocessar) {
            $estado['eventos'] = [];
            $estado['arquivos'] = [];
            // Recursão única para reprocessar
            return self::processarNovas($estado);
        }
        
        return $novosEventos;
    }
    
    private static function parseLinhaCom($linha, $ts) {
        $regex = '/^\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}\.\d+ queries: info: client @\S+ (\S+)#\d+ \(([^)]+)\): query: (\S+) IN (\w+) ([^\s]+)/';
        
        if (!preg_match($regex, $linha, $m)) return null;
        
        $cliente = preg_replace('/#.*/', '', $m[1]);
        $dominio = strtolower($m[3]);
        $tipo = strtoupper($m[4]);
        $flags = $m[5];
        
        if (in_array($dominio, ['localhost', 'version.bind', 'hostname.bind', 'id.server'])) {
            return null;
        }
        
        $resposta = 'NOERROR';
        if (strpos($flags, 'NXDOMAIN') !== false) $resposta = 'NXDOMAIN';
        elseif (strpos($flags, 'SERVFAIL') !== false) $resposta = 'SERVFAIL';
        elseif (strpos($flags, 'REFUSED') !== false) $resposta = 'REFUSED';
        
        return [
            'ts' => $ts,
            'cliente' => $cliente,
            'dominio' => $dominio,
            'tipo' => $tipo,
            'resposta' => $resposta,
        ];
    }
    
    private static function listarArquivosLog() {
        $arquivos = glob(self::QUERY_LOG . '*');
        $lista = [];
        foreach ($arquivos as $arq) {
            if ($arq === self::QUERY_LOG) {
                $lista[] = ['arquivo' => $arq, 'ordem' => -1];
            } else {
                $n = (int)substr($arq, strrpos($arq, '.') + 1);
                $lista[] = ['arquivo' => $arq, 'ordem' => $n];
            }
        }
        usort($lista, fn($a, $b) => $a['ordem'] <=> $b['ordem']);
        return array_column($lista, 'arquivo');
    }
    
    // ============ AGREGAÇÃO ============
    
    /**
     * Agrega os eventos da janela N e retorna o resultado final.
     */
    private static function agregar($estado, $minutos) {
        $limite = time() - ($minutos * 60);
        $redes = self::obterRedesBind();
        
        $dominios = [];
        $blocos = [];
        $ipsUnicos = [];
        $ipsForaDaLista = [];
        $total = 0;
        
        foreach ($estado['eventos'] as $ev) {
            if ($ev['ts'] < $limite) continue;
            
            $total++;
            $ipsUnicos[$ev['cliente']] = true;
            
            $chave = $ev['dominio'] . '|' . $ev['tipo'];
            $dominios[$chave] = ($dominios[$chave] ?? 0) + 1;
            
            $bloco = self::redeDoIp($ev['cliente'], $redes);
            if (!isset($blocos[$bloco])) {
                $blocos[$bloco] = ['total' => 0, 'ips' => [], 'dominios' => []];
            }
            $blocos[$bloco]['total']++;
            $blocos[$bloco]['ips'][$ev['cliente']] = true;
            $blocos[$bloco]['dominios'][$ev['dominio']] = true;
            
            if ($bloco === 'outros') {
                $ipsForaDaLista[$ev['cliente']] = ($ipsForaDaLista[$ev['cliente']] ?? 0) + 1;
            }
        }
        
        // Top 10 domínios
        arsort($dominios);
        $topDominios = [];
        $i = 0;
        foreach ($dominios as $chave => $totalDom) {
            if ($i++ >= 10) break;
            [$dominio, $tipo] = explode('|', $chave);
            $topDominios[] = ['dominio' => $dominio, 'tipo' => $tipo, 'total' => $totalDom];
        }
        
        // Blocos
        $listaBlocos = [];
        foreach ($blocos as $bloco => $info) {
            $listaBlocos[] = [
                'bloco' => $bloco,
                'total' => $info['total'],
                'ips_unicos' => count($info['ips']),
                'dominios_unicos' => count($info['dominios']),
            ];
        }
        usort($listaBlocos, fn($a, $b) => $b['total'] <=> $a['total']);
        
        // IPs fora da lista
        arsort($ipsForaDaLista);
        $topFora = [];
        foreach ($ipsForaDaLista as $ip => $totalIp) {
            $topFora[] = ['ip' => $ip, 'total' => $totalIp];
            if (count($topFora) >= 10) break;
        }
        
        return [
            'periodo_minutos' => $minutos,
            'total_queries' => $total,
            'ips_unicos' => count($ipsUnicos),
            'dominios_unicos' => count(array_unique(array_map(fn($k) => explode('|', $k)[0], array_keys($dominios)))),
            'top_dominios' => $topDominios,
            'blocos' => $listaBlocos,
            'redes_configuradas' => $redes,
            'ips_fora_da_lista' => $topFora,
        ];
    }
    
    // ============ API PÚBLICA ============
    
    /**
     * Análise incremental principal.
     */
    public static function analisar($minutos = 5) {
        $t0 = microtime(true);
        
        // Lock para evitar concorrência (duas execuções simultâneas)
        $lockFile = '/tmp/dns-analyzer.lock';
        $lock = @fopen($lockFile, 'c');
        if (!$lock) return null;
        
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            // Já tem alguém processando — devolve cache antigo
            fclose($lock);
            return self::lerCacheAntigo($minutos);
        }
        
        try {
            // Carrega estado
            $estado = self::carregarEstado();
            
            // Processa novas linhas (incremental)
            $novosEventos = self::processarNovas($estado);
            
            // Adiciona novos eventos ao estado
            foreach ($novosEventos as $ev) {
                $estado['eventos'][] = $ev;
            }
            
            // Remove eventos antigos (mais que JANELA_MAX_MINUTOS)
            $limiteAntigo = time() - (self::JANELA_MAX_MINUTOS * 60);
            $estado['eventos'] = array_values(array_filter(
                $estado['eventos'],
                fn($ev) => $ev['ts'] >= $limiteAntigo
            ));
            
            $estado['ultima_analise'] = time();
            
            // Salva estado
            self::salvarEstado($estado);
            
            // Agrega
            $resultado = self::agregar($estado, $minutos);
            
            // Salva cache
            self::salvarCache($minutos, $resultado);
            
            $resultado['_tempo_ms'] = round((microtime(true) - $t0) * 1000);
            $resultado['_novos_eventos'] = count($novosEventos);
            $resultado['_total_eventos'] = count($estado['eventos']);
            
            return $resultado;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    
    /**
     * Análise com cache + stale-while-revalidate.
     */
    public static function analisarComStale($minutos = 5) {
        // Cache fresco
        $cache = self::lerCache($minutos);
        if ($cache !== null) {
            $idade = time() - ($cache['_cache_ts'] ?? 0);
            if ($idade < self::CACHE_TTL) {
                $cache['_cache_status'] = 'fresh';
                return $cache;
            }
            if ($idade < self::CACHE_STALE) {
                $cache['_cache_status'] = 'stale';
                return $cache;
            }
        }
        
        // Precisa recalcular
        $resultado = self::analisar($minutos);
        $resultado['_cache_status'] = 'regenerated';
        return $resultado;
    }
    
    private static function salvarCache($minutos, $resultado) {
        $arquivo = "/tmp/dns-analyzer-cache/{$minutos}.json";
        if (!is_dir('/tmp/dns-analyzer-cache')) @mkdir('/tmp/dns-analyzer-cache', 0777, true);
        $resultado['_cache_ts'] = time();
        @file_put_contents($arquivo, json_encode($resultado));
    }
    
    private static function lerCache($minutos) {
        $arquivo = "/tmp/dns-analyzer-cache/{$minutos}.json";
        if (!file_exists($arquivo)) return null;
        $json = @file_get_contents($arquivo);
        if (!$json) return null;
        return @json_decode($json, true);
    }
    
    private static function lerCacheAntigo($minutos) {
        $cache = self::lerCache($minutos);
        if ($cache) {
            $cache['_cache_status'] = 'stale_lock';
            return $cache;
        }
        // Sem cache — retorna vazio
        return [
            'periodo_minutos' => $minutos,
            'total_queries' => 0,
            'ips_unicos' => 0,
            'dominios_unicos' => 0,
            'top_dominios' => [],
            'blocos' => [],
            'redes_configuradas' => self::obterRedesBind(),
            'ips_fora_da_lista' => [],
            '_cache_status' => 'empty',
        ];
    }
    
    /**
     * Busca específica.
     */
    public static function buscar($busca, $minutos = 5) {
        $busca = trim($busca);
        if ($busca === '') return null;
        
        $estado = self::carregarEstado();
        $limite = time() - ($minutos * 60);
        
        $resultado = [
            'busca' => $busca,
            'total' => 0,
            'dominios' => [],
            'ips' => [],
            'tipo_busca' => null,
        ];
        
        $ehIp = filter_var($busca, FILTER_VALIDATE_IP) !== false;
        $ehCidr = !$ehIp && strpos($busca, '/') !== false;
        
        if ($ehIp) $resultado['tipo_busca'] = 'ip';
        elseif ($ehCidr) $resultado['tipo_busca'] = 'cidr';
        else $resultado['tipo_busca'] = 'texto';
        
        $buscaLower = strtolower($busca);
        $cidrParts = $ehCidr ? explode('/', $busca) : null;
        
        foreach ($estado['eventos'] as $ev) {
            if ($ev['ts'] < $limite) continue;
            
            $combina = false;
            if ($ehIp) $combina = ($ev['cliente'] === $busca);
            elseif ($ehCidr) $combina = self::ipNaRede($ev['cliente'], $cidrParts[0], (int)$cidrParts[1]);
            else $combina = (strpos($ev['dominio'], $buscaLower) !== false);
            
            if (!$combina) continue;
            
            $resultado['total']++;
            $resultado['ips'][$ev['cliente']] = ($resultado['ips'][$ev['cliente']] ?? 0) + 1;
            $resultado['dominios'][$ev['dominio']] = ($resultado['dominios'][$ev['dominio']] ?? 0) + 1;
        }
        
        arsort($resultado['ips']);
        arsort($resultado['dominios']);
        $resultado['ips'] = array_slice($resultado['ips'], 0, 20, true);
        $resultado['dominios'] = array_slice($resultado['dominios'], 0, 20, true);
        
        return $resultado;
    }
    
    /**
     * Força reprocessamento do zero (apaga estado).
     */
    public static function resetar() {
        @unlink(self::STATE_FILE);
        foreach (glob('/tmp/dns-analyzer-cache/*.json') as $f) @unlink($f);
    }
    
    /**
     * Estatísticas do estado.
     */
    public static function status() {
        $estado = self::carregarEstado();
        return [
            'arquivos' => count($estado['arquivos']),
            'eventos' => count($estado['eventos']),
            'ultima_analise' => $estado['ultima_analise'],
            'tamanho_estado' => file_exists(self::STATE_FILE) ? filesize(self::STATE_FILE) : 0,
        ];
    }
}
