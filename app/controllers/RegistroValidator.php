<?php

/**
 * Valida registros DNS por tipo.
 */
class RegistroValidator {
    
    const TIPOS_VALIDOS = ['A','AAAA','CNAME','MX','TXT','NS','SRV','PTR','CAA'];
    
    public static function validar($dados) {
        $erros = [];
        
        $nome = trim($dados['nome'] ?? '');
        $tipo = strtoupper($dados['tipo'] ?? '');
        $valor = trim($dados['valor'] ?? '');
        $ttl = $dados['ttl'] ?? null;
        $prio = $dados['prioridade'] ?? null;
        
        if ($nome === '') {
            $erros[] = 'O campo "Nome" é obrigatório (use @ para o domínio raiz).';
        } elseif (!preg_match('/^(@|\*|[a-zA-Z0-9_]([a-zA-Z0-9_-]*[a-zA-Z0-9_])?(\.[a-zA-Z0-9_]([a-zA-Z0-9_-]*[a-zA-Z0-9_])?)*\.?)$/', $nome)) {
            $erros[] = 'Nome inválido. Use @, *, ou algo como www, mail, sub.dominio.';
        }
        
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            $erros[] = 'Tipo de registro inválido.';
            return $erros;
        }
        
        if ($ttl !== null && $ttl !== '' && (!is_numeric($ttl) || (int)$ttl < 0)) {
            $erros[] = 'TTL deve ser um número positivo.';
        }
        
        if ($valor === '') {
            $erros[] = 'O campo "Valor" é obrigatório.';
            return $erros;
        }
        
        switch ($tipo) {
            case 'A':
                if (!filter_var($valor, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $erros[] = 'Registro A requer um IPv4 válido (ex: 192.168.1.10).';
                }
                break;
            
            case 'AAAA':
                if (!filter_var($valor, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $erros[] = 'Registro AAAA requer um IPv6 válido (ex: 2001:db8::1).';
                }
                break;
            
            case 'CNAME':
                if (filter_var($valor, FILTER_VALIDATE_IP)) {
                    $erros[] = 'CNAME deve apontar para um nome, não um IP.';
                } elseif (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.?$/', $valor)) {
                    $erros[] = 'CNAME inválido (ex: exemplo.com.br ou sub.exemplo.com.br).';
                }
                if ($nome === '@') {
                    $erros[] = 'CNAME não é permitido no @ (raiz do domínio). Use A ou AAAA.';
                }
                break;
            
            case 'MX':
                if (empty($prio) || !is_numeric($prio) || (int)$prio < 0 || (int)$prio > 65535) {
                    $erros[] = 'Registro MX requer prioridade entre 0 e 65535.';
                }
                if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.?$/', $valor)) {
                    $erros[] = 'MX deve apontar para um hostname (ex: mail.exemplo.com.br).';
                }
                break;
            
            case 'TXT':
                if (strlen($valor) > 255 && !preg_match('/^".*"$/s', $valor)) {
                    $erros[] = 'TXT com mais de 255 caracteres deve estar entre aspas duplas.';
                }
                break;
            
            case 'NS':
                if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.?$/', $valor)) {
                    $erros[] = 'NS deve apontar para um hostname (ex: ns1.exemplo.com.br).';
                }
                break;
            
            case 'SRV':
                $partes = preg_split('/\s+/', $valor);
                if (count($partes) !== 3) {
                    $erros[] = 'SRV deve estar no formato: "peso porta alvo" (ex: 5 5060 sipserver.exemplo.com.br).';
                } else {
                    if (!is_numeric($partes[0]) || (int)$partes[0] < 0 || (int)$partes[0] > 65535) {
                        $erros[] = 'Peso do SRV deve ser 0-65535.';
                    }
                    if (!is_numeric($partes[1]) || (int)$partes[1] < 0 || (int)$partes[1] > 65535) {
                        $erros[] = 'Porta do SRV deve ser 0-65535.';
                    }
                }
                if (empty($prio) || !is_numeric($prio)) {
                    $erros[] = 'SRV requer prioridade.';
                }
                break;
            
            case 'PTR':
                if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.?$/', $valor)) {
                    $erros[] = 'PTR deve apontar para um hostname.';
                }
                break;
            
            case 'CAA':
                $partes = preg_split('/\s+/', $valor, 3);
                if (count($partes) !== 3) {
                    $erros[] = 'CAA deve estar no formato: "flags tag valor" (ex: 0 issue letsencrypt.org).';
                } else {
                    if (!in_array($partes[1], ['issue', 'issuewild', 'iodef'], true)) {
                        $erros[] = 'CAA tag deve ser issue, issuewild ou iodef.';
                    }
                }
                break;
        }
        
        return $erros;
    }
    
    public static function validarConflitos($dados, $zonaId, $idEdicao = null) {
        $erros = [];
        $nome = trim($dados['nome'] ?? '');
        $tipo = strtoupper($dados['tipo'] ?? '');
        
        $sql = "SELECT tipo FROM registros WHERE zona_id = ? AND nome = ?";
        $params = [$zonaId, $nome];
        
        if ($idEdicao) {
            $sql .= " AND id != ?";
            $params[] = $idEdicao;
        }
        
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $tiposExistentes = array_column($stmt->fetchAll(), 'tipo');
        
        if ($tipo === 'CNAME' && !empty($tiposExistentes)) {
            $outros = array_diff($tiposExistentes, ['CNAME']);
            if (!empty($outros)) {
                $erros[] = 'CNAME não pode coexistir com outros registros no mesmo nome (' . implode(', ', $outros) . ').';
            }
        }
        
        if ($tipo !== 'CNAME' && in_array('CNAME', $tiposExistentes, true)) {
            $erros[] = 'Já existe um CNAME para este nome. Remova-o antes de adicionar outros registros.';
        }
        
        return $erros;
    }
}
