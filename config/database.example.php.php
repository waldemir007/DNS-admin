<?php
/**
 * Configuração do banco de dados
 * 
 * IMPORTANTE: 
 *   1. Copie este arquivo para database.php
 *   2. Altere a linha DB_PASS com a senha definida na instalação
 * 
 * Este arquivo (database.example.php) serve como template público.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'dns');
define('DB_USER', 'root');
define('DB_PASS', 'COLE_A_SENHA_AQUI');   // ← ALTERE ESTA LINHA
define('DB_CHARSET', 'utf8mb4');

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die("Erro de conexão: " . $e->getMessage());
        }
    }
    return $pdo;
}