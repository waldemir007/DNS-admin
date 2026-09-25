<?php

/**
 * PTRHelper — Cálculos de IP, CIDR e zona reversa.
 * 
 * Só IPv4. Zona reversa aceita /8, /16 ou /24.
 * Regras dentro de uma zona aceitam /24 a /32.
 */
class PTRHelper {
    
    /**
     * Máscaras aceitas dentro de uma zona reversa /24.
     */
    const MASCARAS_REGRAS = [24, 25, 26, 27, 28, 29, 30, 31, 32];
    
    /**
     * Máscaras aceitas para criar uma ZONA reversa.
     */
    const MASCARAS_ZONA = [8, 16, 24];
    
    /**
     * Verifica se é um IPv4 válido.
     */
    public static function isIPv4($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
    
    /**
     * Calcula a zona reversa para um IPv4 (últimos 3 octetos invertidos).
     * 
     * 192.168.1.10 → 1.168.192.in-addr.arpa
     */
    public static function zonaReversa($ip) {
        if (!self::isIPv4($ip)) return null;
        $o = explode('.', $ip);
        return $o[2] . '.' . $o[1] . '.' . $o[0] . '.in-addr.arpa';
    }
    
    /**
     * Calcula o nome do registro dentro da zona reversa.
     * 
     * 192.168.1.10 → "10"
     */
    public static function nomeRegistro($ip) {
        if (!self::isIPv4($ip)) return null;
        $o = explode('.', $ip);
        return $o[3];
    }
    
    /**
     * Calcula range (início/fim) de um CIDR.
     * 
     * Aceita /24 a /32.
     * Se não tiver barra, assume /32.
     */
    public static function calcularRange($cidr) {
        $cidr = trim($cidr);
        if ($cidr === '') return null;
        
        // Sem barra = IP único /32
        if (strpos($cidr, '/') === false) {
            if (!self::isIPv4($cidr)) return null;
            $long = ip2long($cidr);
            return [
                'inicio' => $long,
                'fim' => $long,
                'bits' => 32,
                'rede' => $cidr,
                'cidr' => $cidr . '/32',
            ];
        }
        
        [$ip, $bits] = explode('/', $cidr);
        $bits = (int)$bits;
        
        if (!self::isIPv4($ip)) return null;
        if ($bits < 0 || $bits > 32) return null;
        
        $ipLong = ip2long($ip);
        $mask = -1 << (32 - $bits);
        
        $inicio = $ipLong & $mask;
        $fim = $inicio | (~$mask);
        
        return [
            'inicio' => $inicio,
            'fim' => $fim,
            'bits' => $bits,
            'rede' => long2ip($inicio),
            'cidr' => long2ip($inicio) . '/' . $bits,
        ];
    }
    
    /**
     * Calcula range mas **valida** se a máscara é permitida para REGRAS.
     */
    public static function calcularRangeRegra($cidr) {
        $range = self::calcularRange($cidr);
        if (!$range) return null;
        
        if (!in_array((int)$range['bits'], self::MASCARAS_REGRAS, true)) {
            return null;
        }
        
        return $range;
    }
    
    /**
     * Calcula range mas **valida** se a máscara é permitida para ZONAS.
     */
    public static function calcularRangeZona($cidr) {
        $range = self::calcularRange($cidr);
        if (!$range) return null;
        
        if (!in_array((int)$range['bits'], self::MASCARAS_ZONA, true)) {
            return null;
        }
        
        return $range;
    }
    
    /**
     * Calcula a zona reversa a partir de um CIDR /8, /16 ou /24.
     */
    public static function zonaReversaDeCidr($cidr) {
        $range = self::calcularRangeZona($cidr);
        if (!$range) return null;
        
        $bits = $range['bits'];
        $o = explode('.', $range['rede']);
        
        if ($bits === 24) return $o[2] . '.' . $o[1] . '.' . $o[0] . '.in-addr.arpa';
        if ($bits === 16) return $o[1] . '.' . $o[0] . '.in-addr.arpa';
        if ($bits === 8)  return $o[0] . '.in-addr.arpa';
        
        return null;
    }
    
    /**
     * Retorna o bloco pai de uma zona reversa.
     */
    public static function blocoPai($nomeZona) {
        if (!self::isZonaReversa($nomeZona)) return null;
        
        $prefixo = str_replace('.in-addr.arpa', '', $nomeZona);
        $o = explode('.', $prefixo);
        $n = count($o);
        
        if ($n === 3) return $o[2] . '.' . $o[1] . '.' . $o[0] . '.0/24';
        if ($n === 2) return $o[1] . '.' . $o[0] . '.0.0/16';
        if ($n === 1) return $o[0] . '.0.0.0/8';
        
        return null;
    }
    
    /**
     * Verifica se uma zona é reversa.
     */
    public static function isZonaReversa($nome) {
        return preg_match('/\.in-addr\.arpa$/', $nome) === 1;
    }
    
    /**
     * Verifica se um IP pertence a uma zona reversa.
     */
    public static function ipPertenceZona($ip, $nomeZona) {
        if (!self::isIPv4($ip)) return false;
        return self::zonaReversa($ip) === $nomeZona;
    }
    
    /**
     * Verifica se dois ranges se sobrepõem.
     */
    public static function sobrepoe($range1, $range2) {
        return !($range1['fim'] < $range2['inicio'] || $range1['inicio'] > $range2['fim']);
    }
    
    /**
     * Verifica se um range está contido em outro.
     */
    public static function contido($rangeMenor, $rangeMaior) {
        return $rangeMenor['inicio'] >= $rangeMaior['inicio'] 
            && $rangeMenor['fim'] <= $rangeMaior['fim'];
    }
    
    /**
     * Conta quantos IPs tem um range.
     */
    public static function contarIps($range) {
        return $range['fim'] - $range['inicio'] + 1;
    }
    
    /**
     * Converte IP para long.
     */
    public static function ip2long($ip) {
        return ip2long($ip);
    }
    
    /**
     * Converte long para IP.
     */
    public static function long2ip($long) {
        return long2ip($long);
    }
    
    /**
     * Verifica se o hostname precisa ser expandido (contém {ip} ou começa com ponto).
     */
    public static function precisaExpandir($hostname) {
        $hostname = trim($hostname);
        return strpos($hostname, '{ip}') !== false 
            || substr($hostname, 0, 1) === '.';
    }
    
    /**
     * Formata o hostname final para um IP.
     * 
     * Recebe um IP (ex: 45.168.168.3) e devolve o hostname com ponto final.
     * 
     * Formatos aceitos:
     *   1. 'rgnet.com.br'                  → '3.168.168.45.rgnet.com.br.'
     *   2. '{ip}.rgnet.com.br'             → '3.168.168.45.rgnet.com.br.'
     *   3. '{ip}.168.168.44.rgnet.com.br'  → '3.168.168.45.168.168.44.rgnet.com.br.'
     *   4. '.rgnet.com.br'                 → '3.168.168.45.rgnet.com.br.'
     */
    public static function formatarHostname($hostname, $ip) {
        $hostname = trim($hostname);
        if (empty($hostname)) return null;
        
        // Calcula o "IP invertido" (formato de nome reverso): N.168.168
        $o = explode('.', $ip);
        $ipInvertido = $o[3] . '.' . $o[2] . '.' . $o[1];
        
        $resultado = $hostname;
        
        // Se tem {ip}, substitui
        if (strpos($hostname, '{ip}') !== false) {
            $resultado = str_replace('{ip}', $ipInvertido, $hostname);
        }
        // Se começa com ponto, adiciona o IP na frente
        elseif (substr($hostname, 0, 1) === '.') {
            $resultado = $ipInvertido . $hostname;
        }
        // Senão, adiciona o IP como prefixo
        else {
            $resultado = $ipInvertido . '.' . $hostname;
        }
        
        // ⚠️ Garante ponto final (FQDN)
        if (substr($resultado, -1) !== '.') {
            $resultado .= '.';
        }
        
        return $resultado;
    }
    
    /**
     * Nome amigável do tipo.
     */
    public static function labelTipo($bits) {
        if ($bits === 32) return 'Individual';
        return 'Bloco /' . $bits;
    }
}
