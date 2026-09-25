<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/PTRController.php';
require_once __DIR__ . '/PTRHelper.php';

class ZonaController {
    const ZONAS_DIR = '/var/cache/bind/zones';
    const NAMED_LOCAL = '/etc/bind/named.conf.local';
    const ZONAS_RESERVADAS = ['.','localhost','127.in-addr.arpa','0.in-addr.arpa','255.in-addr.arpa'];
    
    // ============ CRUD ZONAS ============
    
    public static function listar($busca = '') {
        $sql = "SELECT z.*, (SELECT COUNT(*) FROM registros r WHERE r.zona_id = z.id) as total_registros FROM zonas z";
        $params = [];
        if ($busca !== '') { $sql .= " WHERE z.nome LIKE ?"; $params[] = "%{$busca}%"; }
        $sql .= " ORDER BY z.nome ASC";
        $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    }
    
    public static function buscar($id) {
        $stmt = db()->prepare("SELECT * FROM zonas WHERE id = ?"); $stmt->execute([$id]); return $stmt->fetch();
    }
    
    public static function buscarPorNome($n) {
        $stmt = db()->prepare("SELECT * FROM zonas WHERE nome = ?"); $stmt->execute([$n]); return $stmt->fetch();
    }
    
    public static function criar($dados) {
        $erros = self::validar($dados, true);
        if (!empty($erros)) return ['ok' => false, 'erros' => $erros];
        db()->prepare("INSERT INTO zonas (nome,tipo,primario,admin_email,ttl,refresh,retry,expire,negative_ttl,serial,ativo) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
            strtolower(trim($dados['nome'])), $dados['tipo'] ?? 'master', $dados['primario'] ?? null,
            trim($dados['admin_email']), (int)($dados['ttl'] ?? 3600), (int)($dados['refresh'] ?? 10800),
            (int)($dados['retry'] ?? 3600), (int)($dados['expire'] ?? 604800), (int)($dados['negative_ttl'] ?? 86400),
            (int)($dados['serial'] ?? date('Ymd').'01'), isset($dados['ativo'])?1:0,
        ]);
        $id = db()->lastInsertId();
        self::log('criar_zona', "Zona: {$dados['nome']}");
        $r = self::aplicarConfiguracao();
        return $r['ok'] ? ['ok'=>true, 'id'=>$id] : ['ok'=>false, 'erros'=>[$r['erro']]];
    }
    
    public static function atualizar($id, $dados) {
        $erros = self::validar($dados, false, $id);
        if (!empty($erros)) return ['ok' => false, 'erros' => $erros];
        $antiga = self::buscar($id); if (!$antiga) return ['ok'=>false,'erros'=>['Não encontrada.']];
        $mudou = strtolower($antiga['nome']) !== strtolower(trim($dados['nome']));
        db()->prepare("UPDATE zonas SET nome=?,tipo=?,primario=?,admin_email=?,ttl=?,refresh=?,retry=?,expire=?,negative_ttl=?,serial=?,ativo=? WHERE id=?")->execute([
            strtolower(trim($dados['nome'])), $dados['tipo'] ?? 'master', $dados['primario'] ?? null,
            trim($dados['admin_email']), (int)$dados['ttl'], (int)$dados['refresh'], (int)$dados['retry'],
            (int)$dados['expire'], (int)$dados['negative_ttl'], (int)$dados['serial'], isset($dados['ativo'])?1:0, $id,
        ]);
        if ($mudou) self::removerArquivoZona($antiga['nome']);
        self::log('editar_zona', "Zona: {$dados['nome']}");
        $r = self::aplicarConfiguracao();
        return ['ok'=>true, 'aviso'=>$r['ok']?null:$r['erro']];
    }
    
    public static function excluir($id) {
        $z = self::buscar($id); if (!$z) return ['ok'=>false,'erro'=>'Não encontrada.'];
        
        // Remove regras PTR associadas (se for zona reversa)
        PTRController::removerTodas($id);
        
        self::removerArquivoZona($z['nome']);
        db()->prepare("DELETE FROM zonas WHERE id = ?")->execute([$id]);
        self::log('excluir_zona', "Zona: {$z['nome']}");
        $r = self::aplicarConfiguracao();
        return $r['ok'] ? ['ok'=>true] : ['ok'=>true, 'aviso'=>$r['erro']];
    }
    
    // ============ ARQUIVOS ============
    
    public static function caminhoArquivo($n) { return self::ZONAS_DIR . '/db.' . $n; }
    
    private static function removerArquivoZona($n) {
        $a = self::caminhoArquivo($n); if (file_exists($a)) @unlink($a); @unlink($a.'.jnl');
    }
    
    private static function ipServidor() {
        $ip = $_SERVER['SERVER_ADDR'] ?? null;
        if (!$ip || $ip === '127.0.0.1') {
            $s = []; exec("hostname -I 2>/dev/null", $s);
            if (!empty($s[0])) foreach (explode(' ', trim($s[0])) as $i)
                if (filter_var($i, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !preg_match('/^127\./', $i)) { $ip = $i; break; }
        }
        return $ip ?: '127.0.0.1';
    }
    
    public static function gerarArquivoZona($z) {
        $regs = self::listarRegistros($z['id']);
        $ip = self::ipServidor();
        $temNs1 = false;
        $isReversa = PTRHelper::isZonaReversa($z['nome']);
        
        foreach ($regs as $r) {
            if ($r['nome'] === 'ns1' && in_array($r['tipo'], ['A','AAAA'])) $temNs1 = true;
        }
        
        $l = [];
        $l[] = '$TTL ' . $z['ttl'];
        $l[] = '@   IN  SOA ns1.' . $z['nome'] . '. ' . $z['admin_email'] . '. (';
        $l[] = sprintf('        %-12s ; serial', $z['serial']);
        $l[] = sprintf('        %-12s ; refresh', $z['refresh']);
        $l[] = sprintf('        %-12s ; retry', $z['retry']);
        $l[] = sprintf('        %-12s ; expire', $z['expire']);
        $l[] = sprintf('        %-12s ; minimum', $z['negative_ttl']);
        $l[] = '        )';
        $l[] = '';
        $l[] = '; ===== Servidores NS =====';
        $l[] = '@   IN  NS  ns1.' . $z['nome'] . '.';
        $l[] = '';
        
        // A ns1 (obrigatório para o Bind validar)
        if (!$temNs1) {
            $l[] = '; ===== Registro A do nameserver (automático) =====';
            $l[] = 'ns1 IN  A   ' . $ip;
            $l[] = '';
        }
        
        // ============================================
        // ZONAS REVERSAS: gera regras PTR (hierarquia)
        // ============================================
        if ($isReversa) {
            $linhasPtr = PTRController::gerarLinhasZona($z['id']);
            if (!empty($linhasPtr)) {
                foreach ($linhasPtr as $linha) {
                    $l[] = $linha;
                }
                $l[] = '';
            }
        }
        
        // ============================================
        // Registros manuais
        // ============================================
        if (!empty($regs)) {
            $l[] = '; ===== Registros manuais =====';
            foreach ($regs as $r) {
                $l[] = self::formatarRegistro($r);
            }
        }
        
        return implode("\n", $l) . "\n";
    }
    
    private static function formatarRegistro($r) {
        $nome = str_pad($r['nome'], 20, ' ');
        $ttl = !empty($r['ttl']) ? ' ' . $r['ttl'] : '';
        $tp = str_pad($r['tipo'], 6, ' ');
        $v = $r['valor'];
        
        if (in_array($r['tipo'], ['MX','SRV']) && !empty($r['prioridade'])) {
            $v = $r['prioridade'] . ' ' . $v;
        }
        
        if (in_array($r['tipo'], ['CNAME','NS','MX','PTR']) && !preg_match('/\.$/', $v)) {
            $p = explode(' ', $v);
            $u = $p[count($p)-1];
            if (!filter_var($u, FILTER_VALIDATE_IP)) {
                $p[count($p)-1] = rtrim($u, '.') . '.';
                $v = implode(' ', $p);
            }
        }
        
        return $nome . $ttl . ' IN ' . $tp . ' ' . $v;
    }
    
    public static function aplicarConfiguracao() {
        try {
            if (!is_dir(self::ZONAS_DIR) && !@mkdir(self::ZONAS_DIR, 0775, true)) return ['ok'=>false,'erro'=>'mkdir'];
            $zonas = self::listar();
            
            foreach ($zonas as $z) {
                if (!$z['ativo']) continue;
                $a = self::caminhoArquivo($z['nome']);
                if (@file_put_contents($a, self::gerarArquivoZona($z)) === false) return ['ok'=>false,'erro'=>"Escrita: $a"];
                @chmod($a, 0664);
            }
            
            if (@file_put_contents(self::NAMED_LOCAL, self::gerarNamedConfLocal($zonas)) === false) return ['ok'=>false,'erro'=>'named.conf.local'];
            @chmod(self::NAMED_LOCAL, 0664);
            
            $c = self::executar('sudo /usr/bin/named-checkconf 2>&1');
            if ($c['codigo'] !== 0) return ['ok'=>false,'erro'=>'checkconf: ' . $c['saida']];
            
            foreach ($zonas as $z) {
                if (!$z['ativo']) continue;
                $a = self::caminhoArquivo($z['nome']);
                $c = self::executar("sudo /usr/bin/named-checkzone " . escapeshellarg($z['nome']) . " " . escapeshellarg($a) . " 2>&1");
                if ($c['codigo'] !== 0) return ['ok'=>false,'erro'=>"Zona {$z['nome']}: " . $c['saida']];
            }
            
            $r = self::executar('sudo /usr/sbin/rndc reload 2>&1');
            if ($r['codigo'] !== 0) return ['ok'=>false,'erro'=>'rndc: ' . $r['saida']];
            
            return ['ok'=>true];
        } catch (Exception $e) { return ['ok'=>false,'erro'=>$e->getMessage()]; }
    }
    
    private static function gerarNamedConfLocal($zonas) {
        $o = ['//', '// Gerenciado pelo DNS Admin', '// ' . date('Y-m-d H:i:s'), '//', ''];
        foreach ($zonas as $z) {
            if (!$z['ativo']) continue;
            $a = self::caminhoArquivo($z['nome']);
            $o[] = "zone \"{$z['nome']}\" {";
            $o[] = "    type {$z['tipo']};";
            if ($z['tipo'] === 'slave' && !empty($z['primario'])) $o[] = "    masters { {$z['primario']}; };";
            $o[] = "    file \"{$a}\";";
            $o[] = "};";
            $o[] = '';
        }
        return implode("\n", $o);
    }
    
    // ============ REGISTROS ============
    
    public static function listarRegistros($zid) {
        $stmt = db()->prepare("SELECT * FROM registros WHERE zona_id = ? ORDER BY nome, tipo");
        $stmt->execute([$zid]); return $stmt->fetchAll();
    }
    
    public static function listarTodosRegistros($fz = null, $b = '') {
        $sql = "SELECT r.*, z.nome as zona_nome FROM registros r JOIN zonas z ON z.id = r.zona_id WHERE 1=1";
        $p = [];
        if ($fz) { $sql .= " AND r.zona_id = ?"; $p[] = $fz; }
        if ($b !== '') { $sql .= " AND (r.nome LIKE ? OR r.valor LIKE ?)"; $p[] = "%{$b}%"; $p[] = "%{$b}%"; }
        $sql .= " ORDER BY z.nome, r.nome, r.tipo";
        $stmt = db()->prepare($sql); $stmt->execute($p); return $stmt->fetchAll();
    }
    
    public static function buscarRegistro($id) {
        $stmt = db()->prepare("SELECT r.*, z.nome as zona_nome FROM registros r JOIN zonas z ON z.id = r.zona_id WHERE r.id = ?");
        $stmt->execute([$id]); return $stmt->fetch();
    }
    
    public static function criarRegistro($dados) {
        require_once __DIR__ . '/RegistroValidator.php';
        
        $erros = RegistroValidator::validar($dados);
        if (!empty($erros)) return ['ok' => false, 'erros' => $erros];
        
        $zonaId = (int)($dados['zona_id'] ?? 0);
        $zona = self::buscar($zonaId);
        if (!$zona) return ['ok' => false, 'erros' => ['Zona não encontrada.']];
        
        $dados['_zona_nome'] = $zona['nome'];
        $conf = RegistroValidator::validarConflitos($dados, $zonaId);
        if (!empty($conf)) return ['ok' => false, 'erros' => $conf];
        
        $stmt = db()->prepare("INSERT INTO registros (zona_id, nome, tipo, valor, ttl, prioridade) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $zonaId, trim($dados['nome']), strtoupper($dados['tipo']), trim($dados['valor']),
            !empty($dados['ttl']) ? (int)$dados['ttl'] : null,
            !empty($dados['prioridade']) ? (int)$dados['prioridade'] : null,
        ]);
        
        self::atualizarSerial($zonaId);
        self::log('criar_registro', "{$dados['tipo']} {$dados['nome']} em {$zona['nome']}");
        
        return self::aplicarConfiguracao();
    }
    
    public static function atualizarRegistro($id, $dados) {
        require_once __DIR__ . '/RegistroValidator.php';
        
        $registro = self::buscarRegistro($id);
        if (!$registro) return ['ok' => false, 'erros' => ['Registro não encontrado.']];
        
        $zonaId = (int)$registro['zona_id'];
        $zona = self::buscar($zonaId);
        $erros = RegistroValidator::validar($dados);
        if (!empty($erros)) return ['ok' => false, 'erros' => $erros];
        
        $dados['_zona_nome'] = $zona['nome'];
        $conf = RegistroValidator::validarConflitos($dados, $zonaId, $id);
        if (!empty($conf)) return ['ok' => false, 'erros' => $conf];
        
        db()->prepare("UPDATE registros SET nome=?, tipo=?, valor=?, ttl=?, prioridade=? WHERE id=?")->execute([
            trim($dados['nome']), strtoupper($dados['tipo']), trim($dados['valor']),
            !empty($dados['ttl']) ? (int)$dados['ttl'] : null,
            !empty($dados['prioridade']) ? (int)$dados['prioridade'] : null, $id,
        ]);
        
        self::atualizarSerial($zonaId);
        self::log('editar_registro', "ID $id");
        
        return self::aplicarConfiguracao();
    }
    
    public static function excluirRegistro($id) {
        $r = self::buscarRegistro($id); if (!$r) return ['ok'=>false,'erro'=>'Não encontrado.'];
        db()->prepare("DELETE FROM registros WHERE id = ?")->execute([$id]);
        self::atualizarSerial($r['zona_id']);
        self::log('excluir_registro', "{$r['tipo']} {$r['nome']}");
        return self::aplicarConfiguracao();
    }
    
    private static function atualizarSerial($zid) {
        db()->prepare("UPDATE zonas SET serial = CASE WHEN LEFT(CAST(serial AS CHAR),8) = DATE_FORMAT(NOW(),'%Y%m%d') THEN serial+1 ELSE CAST(CONCAT(DATE_FORMAT(NOW(),'%Y%m%d'),'01') AS UNSIGNED) END WHERE id = ?")->execute([$zid]);
    }
    
    // ============ STATUS / HELPERS ============
    
    public static function statusBind() {
        $s = self::executar('sudo /usr/bin/systemctl is-active named 2>&1');
        return trim($s['saida']) === 'active';
    }
    
    public static function recarregarBind() {
        self::log('recarregar_bind', '');
        return self::executar('sudo /usr/sbin/rndc reload 2>&1');
    }
    
    public static function estatisticas() {
        return db()->query("SELECT (SELECT COUNT(*) FROM zonas) as total_zonas, (SELECT COUNT(*) FROM zonas WHERE ativo=1) as zonas_ativas, (SELECT COUNT(*) FROM registros) as total_registros, (SELECT COUNT(*) FROM registros WHERE tipo='A') as registros_a")->fetch();
    }
    
    public static function log($a, $d = '') {
        try {
            if (session_status() === PHP_SESSION_NONE) session_start();
            db()->prepare("INSERT INTO logs (usuario_id,acao,detalhe,ip) VALUES (?,?,?,?)")->execute([
                $_SESSION['user_id'] ?? null, $a, $d, $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Exception $e) {}
    }
    
    private static function executar($cmd) { $s = []; $c = 0; exec($cmd, $s, $c); return ['codigo'=>$c, 'saida'=>implode("\n", $s)]; }
    
    private static function validar($d, $novo, $id = null) {
        $e = []; $n = strtolower(trim($d['nome'] ?? ''));
        if (empty($n)) $e[] = 'Nome obrigatório.';
        elseif (in_array($n, self::ZONAS_RESERVADAS, true)) $e[] = "Zona reservada: $n";
        elseif (!preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $n)) $e[] = 'Nome inválido.';
        if (empty($d['admin_email'])) $e[] = 'E-mail admin obrigatório.';
        $sql = "SELECT id FROM zonas WHERE nome = ?"; $p = [$n];
        if ($id) { $sql .= " AND id != ?"; $p[] = $id; }
        $stmt = db()->prepare($sql); $stmt->execute($p);
        if ($stmt->fetch()) $e[] = 'Zona já existe.';
        return $e;
    }
    
    /**
     * Cria uma zona reversa a partir de um CIDR (/8, /16 ou /24).
     * 
     * O PTR padrão agora é uma REGRA de bloco (com prefixar_ip).
     */
    public static function criarZonaReversaComBloco($cidr, $ptrPadrao = '') {
        $nomeZona = PTRHelper::zonaReversaDeCidr($cidr);
        if (!$nomeZona) {
            return ['ok' => false, 'erro' => 'CIDR inválido. Use /8, /16 ou /24.'];
        }
        
        if (self::buscarPorNome($nomeZona)) {
            return ['ok' => false, 'erro' => "A zona '{$nomeZona}' já existe."];
        }
        
        $serial = (int)(date('Ymd') . '01');
        $adminEmail = 'admin.' . $nomeZona;
        
        // Cria a zona reversa
        db()->prepare("
            INSERT INTO zonas (nome, tipo, admin_email, ttl, refresh, retry, expire, negative_ttl, serial, ativo)
            VALUES (?, 'master', ?, 3600, 10800, 3600, 604800, 86400, ?, 1)
        ")->execute([$nomeZona, $adminEmail, $serial]);
        
        $zonaId = db()->lastInsertId();
        
        // ✅ Adiciona o PTR padrão como uma REGRA de bloco
        if (!empty($ptrPadrao)) {
            // Adiciona o bloco principal (/24, /16 ou /8)
            $r = PTRController::adicionar($zonaId, $cidr, $ptrPadrao, true);
            if (!$r['ok']) {
                // Se falhar, remove a zona criada
                db()->prepare("DELETE FROM zonas WHERE id = ?")->execute([$zonaId]);
                return ['ok' => false, 'erro' => 'Erro ao adicionar PTR padrão: ' . $r['erro']];
            }
        }
        
        self::log('criar_zona_reversa', "Zona reversa: {$nomeZona} ({$cidr})");
        
        $r = self::aplicarConfiguracao();
        if (!$r['ok']) return ['ok' => false, 'erro' => $r['erro']];
        
        return ['ok' => true, 'id' => $zonaId, 'nome' => $nomeZona];
    }
}
