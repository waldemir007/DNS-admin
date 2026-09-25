<?php
require_once __DIR__ . '/../../config/database.php';

class ConfigController {
    
    const NAMED_OPTIONS = '/etc/bind/named.conf.options';
    
    /**
     * Retorna array associativo com todas as configs.
     */
    public static function todas() {
        $stmt = db()->query("SELECT chave, valor FROM config");
        $config = [];
        foreach ($stmt->fetchAll() as $row) {
            $config[$row['chave']] = $row['valor'];
        }
        return $config;
    }
    
    /**
     * Retorna uma config específica.
     */
    public static function get($chave, $default = null) {
        $stmt = db()->prepare("SELECT valor FROM config WHERE chave = ?");
        $stmt->execute([$chave]);
        $r = $stmt->fetch();
        return $r ? $r['valor'] : $default;
    }
    
    /**
     * Salva uma config.
     */
    public static function set($chave, $valor) {
        db()->prepare(
            "INSERT INTO config (chave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        )->execute([$chave, $valor]);
    }
    
    /**
     * Salva várias configs de uma vez.
     */
    public static function setVarias($dados) {
        foreach ($dados as $chave => $valor) {
            self::set($chave, $valor);
        }
    }
    
    /**
     * Retorna as redes permitidas como array.
     */
    public static function getRedesPermitidas() {
        $txt = self::get('redes_permitidas', '127.0.0.1/32');
        $linhas = array_filter(array_map('trim', explode("\n", $txt)));
        return array_values($linhas);
    }
    
    /**
     * Retorna os forwarders como array.
     */
    public static function getForwarders() {
        $txt = self::get('forwarders', '');
        $linhas = array_filter(array_map('trim', explode("\n", $txt)));
        return array_values($linhas);
    }
    
    /**
     * Valida um IP/CIDR (IPv4 ou IPv6).
     * Aceita formatos:
     *   - IPv4 puro: 192.168.1.1
     *   - IPv4 CIDR: 192.168.1.0/24
     *   - IPv6 puro: 2001:db8::1
     *   - IPv6 CIDR: 2001:db8::/32
     */
    public static function validarRede($cidr) {
        $cidr = trim($cidr);
        if ($cidr === '') return false;
        
        // Não pode ter mais de uma barra
        $partes = explode('/', $cidr);
        if (count($partes) > 2) return false;
        
        $ip = $partes[0];
        $mask = isset($partes[1]) ? $partes[1] : null;
        
        // Máscara deve ser número inteiro não negativo
        if ($mask !== null) {
            if (!ctype_digit($mask)) return false;
            $mask = (int)$mask;
        }
        
        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($mask !== null && ($mask < 0 || $mask > 32)) return false;
            return true;
        }
        
        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($mask !== null && ($mask < 0 || $mask > 128)) return false;
            return true;
        }
        
        return false;
    }
    
    /**
     * Valida um forwarder (deve ser IP puro, sem máscara).
     */
    public static function validarForwarder($ip) {
        $ip = trim($ip);
        if ($ip === '') return false;
        if (strpos($ip, '/') !== false) return false;
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    /**
     * Verifica se um IP é IPv6 (para formatação).
     */
    public static function isIPv6($ip) {
        return strpos($ip, ':') !== false;
    }
    
    /**
     * Gera o conteúdo do named.conf.options.
     */
    public static function gerarNamedOptions() {
        $cfg = self::todas();
        $redes = self::getRedesPermitidas();
        $forwarders = self::getForwarders();
        $usarRoot = ($cfg['usar_root_servers'] ?? '1') === '1';
        
        $out = [];
        $out[] = '//';
        $out[] = '// Arquivo gerenciado automaticamente pelo DNS Admin';
        $out[] = '// Gerado em: ' . date('Y-m-d H:i:s');
        $out[] = '// NÃO EDITE MANUALMENTE - use a interface web';
        $out[] = '//';
        $out[] = '';
        $out[] = 'options {';
        $out[] = '    directory "/var/cache/bind";';
        $out[] = '';
        $out[] = '    // ===== Escuta em todas as interfaces =====';
        $out[] = '    listen-on { any; };';
        $out[] = '    listen-on-v6 { any; };';
        $out[] = '';
        $out[] = '    // ===== Recursão =====';
        $recursao = ($cfg['recursao_ativa'] ?? '1') === '1';
        $out[] = '    recursion ' . ($recursao ? 'yes' : 'no') . ';';
        $out[] = '';
        $out[] = '    // ===== Quem pode consultar (ACL) =====';
        if (empty($redes)) {
            $out[] = '    allow-recursion { none; };';
            $out[] = '    allow-query-cache { none; };';
            $out[] = '    allow-query { any; };';
        } else {
            // Cada rede numa linha separada para ficar legível
            $out[] = '    allow-recursion {';
            foreach ($redes as $r) {
                $out[] = '        ' . $r . ';';
            }
            $out[] = '    };';
            $out[] = '';
            $out[] = '    allow-query-cache {';
            foreach ($redes as $r) {
                $out[] = '        ' . $r . ';';
            }
            $out[] = '    };';
            $out[] = '';
            $out[] = '    // Autoritativo público, cache restrito às redes acima';
            $out[] = '    allow-query { any; };';
        }
        $out[] = '';
        $out[] = '    // ===== Forwarders =====';
        if (!$usarRoot && !empty($forwarders)) {
            $out[] = '    forwarders {';
            foreach ($forwarders as $fw) {
                $out[] = '        ' . $fw . ';';
            }
            $out[] = '    };';
            $out[] = '    forward only;';
        } else {
            $out[] = '    // Usando servidores root (recursão direta)';
        }
        $out[] = '';
        $out[] = '    // ===== DNSSEC =====';
        $dnssec = $cfg['dnssec_validation'] ?? 'auto';
        if (!in_array($dnssec, ['auto', 'yes', 'no'], true)) {
            $dnssec = 'auto';
        }
        $out[] = '    dnssec-validation ' . $dnssec . ';';
        $out[] = '';
        $out[] = '    // ===== Cache =====';
        $out[] = '    max-cache-size ' . (int)($cfg['max_cache_size'] ?? 64) . 'm;';
        $out[] = '    max-cache-ttl ' . (int)($cfg['max_cache_ttl'] ?? 86400) . ';';
        $out[] = '    max-ncache-ttl 10800;';
        $out[] = '';
        $out[] = '    // ===== Segurança =====';
        $out[] = '    version "DNS Admin";';
        $out[] = '    hostname none;';
        $out[] = '    server-id none;';
        $out[] = '';
        $out[] = '    // ===== Sem transferência de zona para estranhos =====';
        $out[] = '    allow-transfer { none; };';
        $out[] = '';
        $out[] = '    // ===== Log de queries (opcional) =====';
        if (($cfg['query_log'] ?? '0') === '1') {
            $out[] = '    querylog yes;';
        }
        $out[] = '};';
        $out[] = '';
        
        return implode("\n", $out);
    }
    
    /**
     * Aplica a configuração: valida, escreve o arquivo e recarrega o Bind.
     */
    public static function aplicar() {
        try {
            // 1. Valida redes
            $redes = self::getRedesPermitidas();
            foreach ($redes as $rede) {
                if (!self::validarRede($rede)) {
                    return ['ok' => false, 'erro' => "Rede inválida: {$rede}"];
                }
            }
            
            // 2. Valida forwarders
            $forwarders = self::getForwarders();
            foreach ($forwarders as $fw) {
                if (!self::validarForwarder($fw)) {
                    return ['ok' => false, 'erro' => "Forwarder inválido: {$fw}"];
                }
            }
            
            // 3. Gera o conteúdo
            $conteudo = self::gerarNamedOptions();
            
            // 4. Backup antes de sobrescrever
            if (file_exists(self::NAMED_OPTIONS)) {
                @copy(self::NAMED_OPTIONS, self::NAMED_OPTIONS . '.bak.' . date('YmdHis'));
            }
            
            // 5. Escreve
            if (@file_put_contents(self::NAMED_OPTIONS, $conteudo) === false) {
                return ['ok' => false, 'erro' => 'Falha ao escrever ' . self::NAMED_OPTIONS . '. Verifique permissões.'];
            }
            @chmod(self::NAMED_OPTIONS, 0664);
            
            // 6. Valida sintaxe
            $check = self::executar('sudo /usr/bin/named-checkconf 2>&1');
            if ($check['codigo'] !== 0) {
                // Restaura o backup
                $backups = glob(self::NAMED_OPTIONS . '.bak.*');
                if (!empty($backups)) {
                    $ultimo = end($backups);
                    @copy($ultimo, self::NAMED_OPTIONS);
                }
                return ['ok' => false, 'erro' => 'named-checkconf falhou: ' . $check['saida']];
            }
            
            // 7. Recarrega
            $reload = self::executar('sudo /usr/sbin/rndc reload 2>&1');
            if ($reload['codigo'] !== 0) {
                return ['ok' => false, 'erro' => 'rndc reload falhou: ' . $reload['saida']];
            }
            
            self::log('editar_config', 'Configurações do Bind9 atualizadas');
            
            return ['ok' => true];
            
        } catch (Exception $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        }
    }
    
    /**
     * Status atual do Bind.
     */
    public static function statusBind() {
        $status = self::executar('sudo /usr/bin/systemctl is-active named 2>&1');
        $ativo = trim($status['saida']) === 'active';
        
        $uptime = null;
        $stats = null;
        if ($ativo) {
            $r = self::executar('sudo /usr/sbin/rndc status 2>&1');
            if ($r['codigo'] === 0) {
                if (preg_match('/running since (.+)$/m', $r['saida'], $m)) {
                    $uptime = trim($m[1]);
                }
                $stats = $r['saida'];
            }
        }
        
        return [
            'ativo' => $ativo,
            'uptime' => $uptime,
            'stats' => $stats,
        ];
    }
    
    /**
     * Retorna o conteúdo atual do named.conf.options (para debug/exibição).
     */
    public static function lerNamedOptions() {
        if (!file_exists(self::NAMED_OPTIONS)) {
            return null;
        }
        return file_get_contents(self::NAMED_OPTIONS);
    }
    
    /**
     * Faz backup manual do named.conf.options.
     */
    public static function backup() {
        if (!file_exists(self::NAMED_OPTIONS)) {
            return ['ok' => false, 'erro' => 'Arquivo não existe.'];
        }
        $destino = self::NAMED_OPTIONS . '.bak.' . date('YmdHis');
        if (@copy(self::NAMED_OPTIONS, $destino)) {
            return ['ok' => true, 'arquivo' => $destino];
        }
        return ['ok' => false, 'erro' => 'Falha ao copiar.'];
    }
    
    /**
     * Lista os backups existentes.
     */
    public static function listarBackups() {
        $backups = glob(self::NAMED_OPTIONS . '.bak.*');
        if (!$backups) return [];
        
        $resultado = [];
        foreach ($backups as $b) {
            $resultado[] = [
                'arquivo' => $b,
                'nome' => basename($b),
                'data' => filemtime($b),
                'tamanho' => filesize($b),
            ];
        }
        
        // Mais recente primeiro
        usort($resultado, fn($a, $b) => $b['data'] <=> $a['data']);
        
        return $resultado;
    }
    
    private static function log($acao, $detalhe = '') {
        try {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $uid = $_SESSION['user_id'] ?? null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            db()->prepare("INSERT INTO logs (usuario_id, acao, detalhe, ip) VALUES (?, ?, ?, ?)")
                ->execute([$uid, $acao, $detalhe, $ip]);
        } catch (Exception $e) { /* silencioso */ }
    }
    
    private static function executar($cmd) {
        $saida = [];
        $codigo = 0;
        exec($cmd, $saida, $codigo);
        return ['codigo' => $codigo, 'saida' => implode("\n", $saida)];
    }
}
