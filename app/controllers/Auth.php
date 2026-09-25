<?php
require_once __DIR__ . '/../../config/database.php';

class Auth {
    
    /**
     * Inicia a sessão com configurações de segurança.
     */
    private static function iniciarSessao() {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        
        // Configurações de segurança da sessão
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Lax');
        
        session_start();
    }
    
    /**
     * Envia headers para evitar cache de páginas protegidas.
     */
    public static function semCache() {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }
    
    public static function login($username, $password) {
        $stmt = db()->prepare("SELECT * FROM usuarios WHERE username = ? AND ativo = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            self::iniciarSessao();
            
            // Regenera o ID da sessão (previne session fixation)
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nome'] = $user['nome'];
            $_SESSION['nivel'] = $user['nivel'];
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            $_SESSION['ultimo_acesso'] = time();
            $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            db()->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")
                ->execute([$user['id']]);
            
            return true;
        }
        return false;
    }
    
    public static function check() {
        self::iniciarSessao();
        
        // Verifica se está logado
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }
        
        // Sessão expira após 8 horas de inatividade
        $timeout = 8 * 3600;
        if (isset($_SESSION['ultimo_acesso']) && (time() - $_SESSION['ultimo_acesso']) > $timeout) {
            self::logout();
            return false;
        }
        
        // Atualiza último acesso
        $_SESSION['ultimo_acesso'] = time();
        
        return true;
    }
    
    public static function user() {
        if (!self::check()) return null;
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'nome' => $_SESSION['nome'],
            'nivel' => $_SESSION['nivel'],
        ];
    }
    
    public static function logout() {
        self::iniciarSessao();
        
        // Limpa todos os dados da sessão
        $_SESSION = [];
        
        // Remove o cookie da sessão
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        
        // Destroi a sessão no servidor
        session_destroy();
        
        // Envia headers anti-cache
        self::semCache();
    }
}
