<?php
require_once __DIR__ . '/../../config/database.php';

class UsuarioController {
    
    public static function listar($busca = '') {
        $sql = "SELECT id, username, nome, email, nivel, ativo, ultimo_login, created_at 
                FROM usuarios";
        $params = [];
        
        if ($busca !== '') {
            $sql .= " WHERE username LIKE ? OR nome LIKE ? OR email LIKE ?";
            $like = "%{$busca}%";
            $params = [$like, $like, $like];
        }
        
        $sql .= " ORDER BY created_at DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public static function buscar($id) {
        $stmt = db()->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public static function criar($dados) {
        // Validações
        $erros = self::validar($dados, true);
        if (!empty($erros)) return ['ok' => false, 'erros' => $erros];
        
        // Verificar duplicidade
        $stmt = db()->prepare("SELECT id FROM usuarios WHERE username = ?");
        $stmt->execute([$dados['username']]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'erros' => ['Usuário já existe.']];
        }
        
        $stmt = db()->prepare(
            "INSERT INTO usuarios (username, password, nome, email, nivel, ativo) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $dados['username'],
            password_hash($dados['password'], PASSWORD_BCRYPT),
            $dados['nome'],
            $dados['email'] ?? null,
            $dados['nivel'] ?? 'user',
            isset($dados['ativo']) ? 1 : 0,
        ]);
        
        return ['ok' => true, 'id' => db()->lastInsertId()];
    }
    
    public static function atualizar($id, $dados) {
        $erros = self::validar($dados, false);
        if (!empty($erros)) return ['ok' => false, 'erros' => $erros];
        
        // Verificar duplicidade (exceto o próprio)
        $stmt = db()->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?");
        $stmt->execute([$dados['username'], $id]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'erros' => ['Usuário já existe.']];
        }
        
        $sql = "UPDATE usuarios SET username = ?, nome = ?, email = ?, nivel = ?, ativo = ?";
        $params = [
            $dados['username'],
            $dados['nome'],
            $dados['email'] ?? null,
            $dados['nivel'] ?? 'user',
            isset($dados['ativo']) ? 1 : 0,
        ];
        
        // Só atualiza senha se foi preenchida
        if (!empty($dados['password'])) {
            $sql .= ", password = ?";
            $params[] = password_hash($dados['password'], PASSWORD_BCRYPT);
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        db()->prepare($sql)->execute($params);
        return ['ok' => true];
    }
    
    public static function excluir($id) {
        // Não permitir excluir a si mesmo
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $id) {
            return ['ok' => false, 'erro' => 'Você não pode excluir sua própria conta.'];
        }
        
        // Não permitir excluir o último admin ativo
        $stmt = db()->prepare("SELECT COUNT(*) FROM usuarios WHERE nivel = 'admin' AND ativo = 1 AND id != ?");
        $stmt->execute([$id]);
        $outrosAdmins = $stmt->fetchColumn();
        
        $user = self::buscar($id);
        if ($user && $user['nivel'] === 'admin' && $user['ativo'] && $outrosAdmins == 0) {
            return ['ok' => false, 'erro' => 'Não é possível excluir o último administrador ativo.'];
        }
        
        db()->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }
    
    public static function toggleAtivo($id) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $id) {
            return ['ok' => false, 'erro' => 'Você não pode desativar sua própria conta.'];
        }
        
        db()->prepare("UPDATE usuarios SET ativo = NOT ativo WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    }
    
    public static function estatisticas() {
        $stmt = db()->query(
            "SELECT 
                COUNT(*) as total,
                SUM(ativo = 1) as ativos,
                SUM(ativo = 0) as inativos,
                SUM(nivel = 'admin') as admins
             FROM usuarios"
        );
        return $stmt->fetch();
    }
    
    private static function validar($dados, $novo) {
        $erros = [];
        
        if (empty($dados['username'])) {
            $erros[] = 'Usuário é obrigatório.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $dados['username'])) {
            $erros[] = 'Usuário deve ter 3-50 caracteres (letras, números, . _ -).';
        }
        
        if (empty($dados['nome'])) {
            $erros[] = 'Nome é obrigatório.';
        }
        
        if (!empty($dados['email']) && !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'E-mail inválido.';
        }
        
        if ($novo && empty($dados['password'])) {
            $erros[] = 'Senha é obrigatória para novos usuários.';
        }
        
        if (!empty($dados['password']) && strlen($dados['password']) < 6) {
            $erros[] = 'Senha deve ter no mínimo 6 caracteres.';
        }
        
        if (!empty($dados['password']) && $dados['password'] !== ($dados['password2'] ?? '')) {
            $erros[] = 'As senhas não coincidem.';
        }
        
        return $erros;
    }
}
