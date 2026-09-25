<?php
require_once __DIR__ . '/../app/controllers/Auth.php';

// ⚡ Headers anti-cache
Auth::semCache();

if (Auth::check()) {
    header('Location: dashboard.php');
    exit;
}
// ... resto do código
require_once __DIR__ . '/../app/controllers/Auth.php';

if (Auth::check()) {
    header('Location: dashboard.php');
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (Auth::login($username, $password)) {
        header('Location: dashboard.php');
        exit;
    } else {
        $erro = 'Usuário ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - DNS Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
  <div class="login-container">
    <div class="login-box">
      
      <div class="logo">
        <div class="logo-icon">🌐</div>
        <h1>DNS Admin</h1>
        <p>Sistema de Gerenciamento DNS</p>
      </div>
      
      <?php if ($erro): ?>
        <div class="alert alert-error"><?= htmlspecialchars($erro) ?></div>
      <?php endif; ?>
      
      <form method="POST" autocomplete="off">
        <div class="form-group">
          <label for="username">Usuário</label>
          <input 
            type="text" 
            id="username" 
            name="username" 
            placeholder="Digite seu usuário"
            required 
            autofocus
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        
        <div class="form-group">
          <label for="password">Senha</label>
          <input 
            type="password" 
            id="password" 
            name="password" 
            placeholder="Digite sua senha"
            required>
        </div>
        
        <button type="submit" class="btn btn-primary btn-block">
          Entrar
        </button>
      </form>
      
      <div class="login-footer">
        <small>⚡ Bind9 DNS Manager v1.0</small>
      </div>
      
    </div>
  </div>
</body>
</html>
