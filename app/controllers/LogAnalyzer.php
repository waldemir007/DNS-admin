<?php

/**
 * LogAnalyzer — Análise em tempo real do log do Bind9.
 *
 * Lê apenas as últimas linhas do log (últimos minutos)
 * e devolve os dados agregados em memória.
 *
 * NÃO escreve no banco de dados.
 */
class LogAnalyzer {
    
    const QUERY_LOG = '/var/log/named/query.log';
    
    // Tamanho máximo em bytes para ler de trás pra frente
    // 500 KB = ~4.000 linhas = cobre > 5 min em 99% dos casos
    const MAX_READ_BYTES = 500000;
    
    /**
     * Lê as últimas N linhas do log (as mais recentes).
     */
    private static function lerUltimasLinhas($segundos = 300) {
        if (!file_exists(self::QUERY_LOG)) {
            return [];
        }
        
        $tamanho = filesize(self::QUERY_LOG);
        $pos = max(0, $tamanho - self::MAX_READ_BYTES);
        
        $fh = fopen(self::QUERY_LOG, 'r');
        if (!$fh) return [];
        
        fseek($fh, $pos);
        
        // Se começamos no meio do arquivo, descarta a primeira linha parcial
        if ($pos > 0) {
            fgets($fh);
        }
        
        $limite = time() - $segundos;
        $linhas = [];
        
        while (($linha = fgets($fh)) !== false) {
            // Extrai o timestamp
            if (!preg_match('/^(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})/', $linha, $m)) {
                continue;
            }
            
            $ts = strtotime($m[1]);
            if ($ts === false || $ts < $limite) {
                continue;
            }
            
            $linhas[] = $linha;
        }
        
        fclose($fh);
        return $linhas;
    }
    
    /**
     * Parseia uma linha do log e extrai os campos.
     * Formato esperado:
     *   20-Sep-2026 14:35:42.123 queries: info: client @0x... 172.16.0.5#54321 (google.com): query: google.com IN A + (172.16.0.157)
     */
    private static function parseLinha($linha) {
        $regex = '/^(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})\.\d+ queries: info: client @\S+ (\S+)#\d+ \(([^)]+)\): query: (\S+) IN (\w+) ([^\s]+)/';
        
        if (!preg_match($regex, $linha, $m)) {
            return null;
        }
        
        $timestamp = date('Y-m-d H:i:s', strtotime($m[1]));
        $cliente = preg_replace('/#.*/', '', $m[2]);
        $dominio = strtolower($m[4]);
        $tipo = strtoupper($m[5]);
        $flags = $m[6];
        
        // Ignora queries internas do sistema
        if (in_array($dominio, ['localhost', 'version.bind', 'hostname.bind', 'id.server'])) {
            return null;
        }
        
        // Detecta resposta
        $resposta = 'NOERROR';
        if (strpos($flags, 'NXDOMAIN') !== false) $resposta = 'NXDOMAIN';
        elseif (strpos($flags, 'SERVFAIL') !== false) $resposta = 'SERVFAIL';
        elseif (strpos($flags, 'REFUSED') !== false) $resposta = 'REFUSED';
        elseif (strpos($flags, 'FORMERR') !== false) $resposta = 'FORMERR';
        
        return [
            'timestamp' => $timestamp,
            'cliente' => $cliente,
            'dominio' => $dominio,
            'tipo' => $tipo,
            'resposta' => $resposta,
        ];
    }
    
    /**
     * Analisa os últimos X segundos e devolve tudo agregado.
     */
    public static function analisar($segundos = 300) {
        $linhas = self::lerUltimasLinhas($segundos);
        
        // Estruturas de agregação
        $dominios = [];      // ['google.com|A' => total]
        $clientes = [];      // ['172.16.0.5' => total]
        $tipos = [];         // ['A' => total]
        $respostas = [];     // ['NOERROR' => total]
        $dominiosPorCliente = []; // ['172.16.0.5' => ['google.com' => 5, ...]]
        
        $total = 0;
        $erros = 0;
        $cacheCandidatos = []; // para estimar cache hit
        
        foreach ($linhas as $linha) {
            $d = self::parseLinha($linha);
            if (!$d) continue;
            
            $chaveDom = $d['dominio'] . '|' . $d['tipo'];
            
            // Domínios
            if (!isset($dominios[$chaveDom])) {
                $dominios[$chaveDom] = ['dominio' => $d['dominio'], 'tipo' => $d['tipo'], 'total' => 0, 'erros' => 0];
            }
            $dominios[$chaveDom]['total']++;
            
            // Clientes
            if (!isset($clientes[$d['cliente']])) {
                $clientes[$d['cliente']] = ['cliente' => $d['cliente'], 'total' => 0, 'erros' => 0, 'dominios' => []];
            }
            $clientes[$d['cliente']]['total']++;
            $clientes[$d['cliente']]['dominios'][$d['dominio']] = true;
            
            // Tipos
            $tipos[$d['tipo']] = ($tipos[$d['tipo']] ?? 0) + 1;
            
            // Respostas
            $respostas[$d['resposta']] = ($respostas[$d['resposta']] ?? 0) + 1;
            
            $total++;
            
            // Erros
            if ($d['resposta'] !== 'NOERROR') {
                $erros++;
                $dominios[$chaveDom]['erros']++;
                $clientes[$d['cliente']]['erros']++;
            }
            
            // Cache hit estimado: se o mesmo domínio já apareceu antes neste período
            if (isset($cacheCandidatos[$chaveDom])) {
                // repetido = provavelmente cache hit
            }
            $cacheCandidatos[$chaveDom] = true;
        }
        
        // Converte dominiosPorCliente em contagem
        foreach ($clientes as &$c) {
            $c['dominios_unicos'] = count($c['dominios']);
            unset($c['dominios']);
        }
        unset($c);
        
        // Ordena por total (desc)
        $domArray = array_values($dominios);
        usort($domArray, fn($a, $b) => $b['total'] <=> $a['total']);
        
        $cliArray = array_values($clientes);
        usort($cliArray, fn($a, $b) => $b['total'] <=> $a['total']);
        
        arsort($tipos);
        arsort($respostas);
        
        return [
            'total_queries' => $total,
            'erros' => $erros,
            'clientes_unicos' => count($clientes),
            'dominios_unicos' => count(array_unique(array_column($domArray, 'dominio'))),
            'top_dominios' => array_slice($domArray, 0, 15),
            'top_clientes' => array_slice($cliArray, 0, 15),
            'tipos' => $tipos,
            'respostas' => $respostas,
            'periodo_segundos' => $segundos,
        ];
    }
}
