<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/PTRHelper.php';

/**
 * PTRController — Gerencia regras PTR com hierarquia automática.
 * 
 * Prefixo de IP (opção "🌐 Prefixar IP"):
 *   IP 45.168.168.3 + hostname "weblinknet.com.br." → "3.168.168.45.weblinknet.com.br."
 * 
 * Comentários no arquivo: identifica blocos, subblocos e exceções.
 */
class PTRController {
    
    public static function listar($zonaId) {
        $stmt = db()->prepare("
            SELECT * FROM ptr_regras 
            WHERE zona_id = ? 
            ORDER BY bits DESC, inicio ASC
        ");
        $stmt->execute([$zonaId]);
        return $stmt->fetchAll();
    }
    
    public static function buscar($id) {
        $stmt = db()->prepare("SELECT * FROM ptr_regras WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public static function regraParaIp($zonaId, $ipLong) {
        $stmt = db()->prepare("
            SELECT * FROM ptr_regras 
            WHERE zona_id = ? 
              AND inicio <= ? 
              AND fim >= ? 
              AND ativo = 1
            ORDER BY bits DESC
            LIMIT 1
        ");
        $stmt->execute([$zonaId, $ipLong, $ipLong]);
        return $stmt->fetch();
    }
    
    public static function adicionar($zonaId, $cidr, $hostname, $prefixarIp = false) {
        $cidr = trim($cidr);
        $hostname = trim($hostname);
        
        if (empty($cidr)) return ['ok' => false, 'erro' => 'IP ou CIDR é obrigatório.'];
        if (empty($hostname)) return ['ok' => false, 'erro' => 'Hostname é obrigatório.'];
        
        $range = PTRHelper::calcularRangeRegra($cidr);
        if (!$range) {
            return ['ok' => false, 'erro' => 'IP ou CIDR inválido. Use /24 a /31 ou IP único.'];
        }
        
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.?$/', $hostname)) {
            return ['ok' => false, 'erro' => 'Hostname inválido.'];
        }
        
        $stmt = db()->prepare("SELECT nome FROM zonas WHERE id = ?");
        $stmt->execute([$zonaId]);
        $nomeZona = $stmt->fetchColumn();
        if (!$nomeZona) return ['ok' => false, 'erro' => 'Zona não encontrada.'];
        
        $blocoPai = PTRHelper::blocoPai($nomeZona);
        if ($blocoPai) {
            $rangePai = PTRHelper::calcularRange($blocoPai);
            if (!PTRHelper::contido($range, $rangePai)) {
                return ['ok' => false, 'erro' => "O CIDR está fora do bloco da zona ({$blocoPai})."];
            }
        }
        
        $stmt = db()->prepare("SELECT id FROM ptr_regras WHERE zona_id = ? AND cidr = ?");
        $stmt->execute([$zonaId, $range['cidr']]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'erro' => "Já existe uma regra para {$range['cidr']}."];
        }
        
        $existentes = self::listar($zonaId);
        foreach ($existentes as $e) {
            if ((int)$e['bits'] !== $range['bits']) continue;
            $rangeE = ['inicio' => (int)$e['inicio'], 'fim' => (int)$e['fim']];
            if (PTRHelper::sobrepoe($range, $rangeE)) {
                return ['ok' => false, 'erro' => "Já existe uma regra de mesmo tamanho cobrindo este range ({$e['cidr']})."];
            }
        }
        
        db()->prepare("
            INSERT INTO ptr_regras (zona_id, cidr, inicio, fim, bits, hostname, prefixar_ip)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $zonaId, $range['cidr'], $range['inicio'], $range['fim'], $range['bits'], 
            $hostname, $prefixarIp ? 1 : 0,
        ]);
        
        return ['ok' => true, 'id' => db()->lastInsertId()];
    }
    
    public static function remover($id) {
        $regra = self::buscar($id);
        if (!$regra) return ['ok' => false, 'erro' => 'Regra não encontrada.'];
        db()->prepare("DELETE FROM ptr_regras WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }
    
    public static function atualizar($id, $hostname, $prefixarIp = false) {
        $hostname = trim($hostname);
        if (empty($hostname)) return ['ok' => false, 'erro' => 'Hostname obrigatório.'];
        
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.?$/', $hostname)) {
            return ['ok' => false, 'erro' => 'Hostname inválido.'];
        }
        
        db()->prepare("UPDATE ptr_regras SET hostname = ?, prefixar_ip = ? WHERE id = ?")
            ->execute([$hostname, $prefixarIp ? 1 : 0, $id]);
        return ['ok' => true];
    }
    
    public static function removerTodas($zonaId) {
        db()->prepare("DELETE FROM ptr_regras WHERE zona_id = ?")->execute([$zonaId]);
    }
    
    /**
     * Monta o hostname final com prefixo de IP (4 octetos).
     */
    private static function montarHostname($regra, $ip) {
        $hostname = $regra['hostname'];
        
        if (!empty($regra['prefixar_ip'])) {
            $o = explode('.', $ip);
            $ipInvertido = $o[3] . '.' . $o[2] . '.' . $o[1] . '.' . $o[0];
            $hostname = $ipInvertido . '.' . $hostname;
        }
        
        if (substr($hostname, -1) !== '.') $hostname .= '.';
        return $hostname;
    }
    
    /**
     * Retorna um "tipo" legível da regra.
     */
    private static function tipoRegra($regra, $blocoPrincipalId) {
        if ($regra['id'] == $blocoPrincipalId) return 'bloco';
        if ((int)$regra['bits'] === 32) return 'individual';
        return 'subbloco';
    }
    
    /**
     * Gera as linhas do arquivo de zona com hierarquia resolvida.
     * 
     * ⚡ Comentários organizados:
     *   - Cabeçalho com todas as regras aplicadas
     *   - Comentário antes de cada exceção/subbloco
     *   - Comentário marcando fim de subbloco
     */
    public static function gerarLinhasZona($zonaId) {
        $regras = self::listar($zonaId);
        if (empty($regras)) return [];
        
        // ============================================================
        // 1. Encontra o bloco principal (menor bits)
        // ============================================================
        $porBits = [];
        foreach ($regras as $r) {
            $porBits[(int)$r['bits']][] = $r;
        }
        ksort($porBits);
        $menorBits = array_key_first($porBits);
        $blocoPrincipal = $porBits[$menorBits][0];
        
        // ============================================================
        // 2. CABEÇALHO com todas as regras
        // ============================================================
        $linhas = [];
        $linhas[] = '; ==================================================================';
        $linhas[] = '; PTRs — Hierarquia resolvida automaticamente pelo DNS Admin';
        $linhas[] = '; Gerado em: ' . date('Y-m-d H:i:s');
        $linhas[] = '; ==================================================================';
        $linhas[] = ';';
        $linhas[] = '; Regras aplicadas (prioridade: menor bloco vence):';
        $linhas[] = ';';
        
        // Ordena por especificidade para listar
        $regrasOrdenadas = $regras;
        usort($regrasOrdenadas, fn($a, $b) => $b['bits'] <=> $a['bits']);
        
        foreach ($regrasOrdenadas as $r) {
            $tipo = self::tipoRegra($r, $blocoPrincipal['id']);
            $prefixo = !empty($r['prefixar_ip']) ? ' [🌐 prefixo]' : '';
            $linhas[] = sprintf(
                ';   [%s] %-20s → %s%s',
                strtoupper(substr($tipo, 0, 3)),
                $r['cidr'],
                $r['hostname'],
                $prefixo
            );
        }
        
        $linhas[] = ';';
        $linhas[] = '; ==================================================================';
        $linhas[] = '';
        
        // ============================================================
        // 3. Gera cada IP com comentários quando há mudança de regra
        // ============================================================
        $regraAnterior = null;
        
        for ($i = $blocoPrincipal['inicio']; $i <= $blocoPrincipal['fim']; $i++) {
            $regraAplicavel = null;
            foreach ($regrasOrdenadas as $r) {
                if ($i >= $r['inicio'] && $i <= $r['fim']) {
                    $regraAplicavel = $r;
                    break;
                }
            }
            if (!$regraAplicavel) continue;
            
            // Detecta mudança de regra
            if ($regraAnterior && $regraAnterior['id'] !== $regraAplicavel['id']) {
                $tipoAnt = self::tipoRegra($regraAnterior, $blocoPrincipal['id']);
                
                // Se saiu de um subbloco/exceção, marca o fim
                if ($tipoAnt !== 'bloco') {
                    $linhas[] = '';
                    $linhas[] = '; --- Fim ' . ($tipoAnt === 'subbloco' ? 'do subbloco' : 'da exceção') . ': ' 
                              . $regraAnterior['cidr'] . ' ---';
                    $linhas[] = '';
                }
                
                // Se entrou em um subbloco/exceção, marca o início
                $tipoNovo = self::tipoRegra($regraAplicavel, $blocoPrincipal['id']);
                if ($tipoNovo !== 'bloco') {
                    $tipoLabel = $tipoNovo === 'subbloco' ? 'Subbloco' : 'Exceção individual';
                    $linhas[] = '; --- ' . $tipoLabel . ': ' 
                              . $regraAplicavel['cidr'] . ' → ' 
                              . $regraAplicavel['hostname'] . ' ---';
                }
            }
            // Primeira linha, se for uma exceção/subbloco que começa no IP inicial
            elseif (!$regraAnterior) {
                $tipo = self::tipoRegra($regraAplicavel, $blocoPrincipal['id']);
                if ($tipo !== 'bloco') {
                    $tipoLabel = $tipo === 'subbloco' ? 'Subbloco' : 'Exceção individual';
                    $linhas[] = '; --- ' . $tipoLabel . ': ' 
                              . $regraAplicavel['cidr'] . ' → ' 
                              . $regraAplicavel['hostname'] . ' ---';
                }
            }
            
            $ip = long2ip($i);
            $nome = PTRHelper::nomeRegistro($ip);
            $hostnameFinal = self::montarHostname($regraAplicavel, $ip);
            
            $linhas[] = str_pad($nome, 20) . ' IN PTR    ' . $hostnameFinal;
            
            $regraAnterior = $regraAplicavel;
        }
        
        // Fim do último bloco, se aplicável
        if ($regraAnterior) {
            $tipo = self::tipoRegra($regraAnterior, $blocoPrincipal['id']);
            if ($tipo !== 'bloco') {
                $linhas[] = '';
                $linhas[] = '; --- Fim ' . ($tipo === 'subbloco' ? 'do subbloco' : 'da exceção') . ': ' 
                          . $regraAnterior['cidr'] . ' ---';
            }
        }
        
        $linhas[] = '';
        $linhas[] = '; ==================================================================';
        $linhas[] = '; Fim da zona';
        $linhas[] = '; ==================================================================';
        
        return $linhas;
    }
    
    public static function estatisticas($zonaId) {
        $stmt = db()->prepare("
            SELECT 
                COUNT(*) AS total,
                SUM(bits = 32) AS individuais,
                SUM(bits >= 24 AND bits < 32) AS subblocos,
                SUM(bits < 24) AS blocos_grandes
            FROM ptr_regras WHERE zona_id = ?
        ");
        $stmt->execute([$zonaId]);
        return $stmt->fetch();
    }
}
