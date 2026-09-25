<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/LogAnalyzer.php';

class StatsController {
    
    /**
     * Retorna dados do período solicitado.
     * - "agora": usa LogAnalyzer (tempo real)
     * - outros: consulta tabelas agregadas
     */
    public static function obter($periodo = '24h') {
        switch ($periodo) {
            case 'agora':
                return self::dadosTempoReal();
            case '1h':
                return self::dadosHistorico(60, '1h');
            case '6h':
                return self::dadosHistorico(360, '6h');
            case '24h':
                return self::dadosHistorico(1440, '24h');
            case '7d':
                return self::dadosHistorico(10080, '7d');
            case '30d':
                return self::dadosHistorico(43200, '30d');
            default:
                return self::dadosHistorico(1440, '24h');
        }
    }
    
    /**
     * Dados em tempo real (últimos 5 min) via LogAnalyzer.
     */
    private static function dadosTempoReal() {
        $dados = LogAnalyzer::analisar(300); // 5 min
        
        // Adapta formato para o padrão das views
        return [
            'periodo' => 'agora',
            'resumo' => [
                'total_queries' => $dados['total_queries'],
                'clientes_unicos' => $dados['clientes_unicos'],
                'dominios_unicos' => $dados['dominios_unicos'],
                'nxdomain' => $dados['respostas']['NXDOMAIN'] ?? 0,
                'servfail' => $dados['respostas']['SERVFAIL'] ?? 0,
                'refused' => $dados['respostas']['REFUSED'] ?? 0,
                'erros_total' => $dados['erros'],
            ],
            'top_dominios' => $dados['top_dominios'],
            'top_clientes' => $dados['top_clientes'],
            'tipos' => $dados['tipos'],
            'respostas' => $dados['respostas'],
            'serie_temporal' => [], // não faz sentido em 5 min
        ];
    }
    
    /**
     * Dados históricos — lê das tabelas stats_* agregadas.
     */
    private static function dadosHistorico($minutos, $periodoSlug) {
        $desde = date('Y-m-d H:i:s', strtotime("-{$minutos} minutes"));
        
        // ============================================================
        // RESUMO GERAL
        // ============================================================
        $stmt = db()->prepare("
            SELECT metrica, SUM(valor) AS total
            FROM stats_geral
            WHERE periodo = '5min' AND janela_inicio >= ?
            GROUP BY metrica
        ");
        $stmt->execute([$desde]);
        $resumoRaw = $stmt->fetchAll();
        
        $resumo = [
            'total_queries' => 0,
            'erros_total' => 0,
            'clientes_unicos' => 0,
            'dominios_unicos' => 0,
        ];
        foreach ($resumoRaw as $r) {
            $resumo[$r['metrica']] = (int)$r['total'];
        }
        
        // ============================================================
        // RESPOSTAS (NOERROR, NXDOMAIN, SERVFAIL, REFUSED)
        // ============================================================
        $stmt = db()->prepare("
            SELECT resposta, SUM(total) AS total
            FROM stats_respostas
            WHERE periodo = '5min' AND janela_inicio >= ?
            GROUP BY resposta
            ORDER BY total DESC
        ");
        $stmt->execute([$desde]);
        $respostasRaw = $stmt->fetchAll();
        
        $respostas = [];
        foreach ($respostasRaw as $r) {
            $respostas[$r['resposta']] = (int)$r['total'];
        }
        
        $resumo['nxdomain'] = $respostas['NXDOMAIN'] ?? 0;
        $resumo['servfail'] = $respostas['SERVFAIL'] ?? 0;
        $resumo['refused']  = $respostas['REFUSED'] ?? 0;
        
        // ============================================================
        // TOP DOMÍNIOS
        // ============================================================
        $stmt = db()->prepare("
            SELECT dominio, tipo, SUM(total) AS total, SUM(erros) AS erros
            FROM stats_dominios
            WHERE periodo = '5min' AND janela_inicio >= ?
            GROUP BY dominio, tipo
            ORDER BY total DESC
            LIMIT 15
        ");
        $stmt->execute([$desde]);
        $topDominios = $stmt->fetchAll();
        
        // ============================================================
        // TOP CLIENTES
        // ============================================================
        $stmt = db()->prepare("
            SELECT cliente, SUM(total) AS total, SUM(erros) AS erros
            FROM stats_clientes
            WHERE periodo = '5min' AND janela_inicio >= ?
            GROUP BY cliente
            ORDER BY total DESC
            LIMIT 15
        ");
        $stmt->execute([$desde]);
        $topClientes = $stmt->fetchAll();
        
        // ============================================================
        // TIPOS
        // ============================================================
        $stmt = db()->prepare("
            SELECT tipo, SUM(total) AS total
            FROM stats_tipos
            WHERE periodo = '5min' AND janela_inicio >= ?
            GROUP BY tipo
            ORDER BY total DESC
        ");
        $stmt->execute([$desde]);
        $tiposRaw = $stmt->fetchAll();
        $tipos = [];
        foreach ($tiposRaw as $t) {
            $tipos[$t['tipo']] = (int)$t['total'];
        }
        
        // ============================================================
        // SÉRIE TEMPORAL (para gráfico de linha)
        // ============================================================
        $agrupamento = '5min';
        if ($minutos > 1440) $agrupamento = 'hour';   // > 1 dia: agrupa por hora
        if ($minutos > 10080) $agrupamento = 'day';   // > 7 dias: agrupa por dia
        
        if ($agrupamento === '5min') {
            $sql = "SELECT janela_inicio AS periodo, 
                           SUM(CASE WHEN metrica='total_queries' THEN valor ELSE 0 END) AS total,
                           SUM(CASE WHEN metrica='erros' THEN valor ELSE 0 END) AS erros
                    FROM stats_geral
                    WHERE periodo = '5min' AND janela_inicio >= ?
                    GROUP BY janela_inicio
                    ORDER BY janela_inicio ASC";
        } elseif ($agrupamento === 'hour') {
            $sql = "SELECT DATE_FORMAT(janela_inicio, '%Y-%m-%d %H:00') AS periodo,
                           SUM(CASE WHEN metrica='total_queries' THEN valor ELSE 0 END) AS total,
                           SUM(CASE WHEN metrica='erros' THEN valor ELSE 0 END) AS erros
                    FROM stats_geral
                    WHERE periodo = '5min' AND janela_inicio >= ?
                    GROUP BY DATE_FORMAT(janela_inicio, '%Y-%m-%d %H:00')
                    ORDER BY periodo ASC";
        } else {
            $sql = "SELECT DATE(janela_inicio) AS periodo,
                           SUM(CASE WHEN metrica='total_queries' THEN valor ELSE 0 END) AS total,
                           SUM(CASE WHEN metrica='erros' THEN valor ELSE 0 END) AS erros
                    FROM stats_geral
                    WHERE periodo = '5min' AND janela_inicio >= ?
                    GROUP BY DATE(janela_inicio)
                    ORDER BY periodo ASC";
        }
        
        $stmt = db()->prepare($sql);
        $stmt->execute([$desde]);
        $serieTemporal = $stmt->fetchAll();
        
        return [
            'periodo' => $periodoSlug,
            'resumo' => $resumo,
            'top_dominios' => $topDominios,
            'top_clientes' => $topClientes,
            'tipos' => $tipos,
            'respostas' => $respostas,
            'serie_temporal' => $serieTemporal,
        ];
    }
    
    /**
     * Limpa dados antigos das tabelas stats_*.
     * Mantém janelas de 5min por X dias.
     */
    public static function limparAntigos($diasManter = 30) {
        $limite = date('Y-m-d H:i:s', strtotime("-{$diasManter} days"));
        
        $tabelas = ['stats_dominios', 'stats_clientes', 'stats_geral', 'stats_tipos', 'stats_respostas'];
        $removidos = 0;
        foreach ($tabelas as $t) {
            $stmt = db()->prepare("DELETE FROM $t WHERE janela_inicio < ?");
            $stmt->execute([$limite]);
            $removidos += $stmt->rowCount();
        }
        return $removidos;
    }
    
    /**
     * Estatísticas gerais do banco (para debug).
     */
    public static function info() {
        $info = [];
        
        $stmt = db()->query("
            SELECT 
                COUNT(*) AS total_linhas,
                MIN(janela_inicio) AS primeira,
                MAX(janela_inicio) AS ultima
            FROM stats_geral WHERE periodo = '5min'
        ");
        $info['stats_geral'] = $stmt->fetch();
        
        $stmt = db()->query("SELECT valor FROM stats_controle WHERE chave = 'ultima_agregacao'");
        $info['ultima_agregacao'] = $stmt->fetchColumn();
        
        $stmt = db()->query("SELECT valor FROM stats_controle WHERE chave = 'pos_log'");
        $info['pos_log'] = $stmt->fetchColumn();
        
        return $info;
    }
}
