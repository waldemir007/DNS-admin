<?php
require_once __DIR__ . '/../../controllers/Auth.php';

Auth::semCache();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$paginaAtual = $paginaAtual ?? '';

if (session_status() === PHP_SESSION_NONE) session_start();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$versao = '1.0.3';

$gruposAutoritativo = ['zonas', 'registros'];
$gruposRecursivo = ['stats', 'consulta', 'config'];

$abrirAutoritativo = in_array($paginaAtual, $gruposAutoritativo);
$abrirRecursivo = in_array($paginaAtual, $gruposRecursivo);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
<title><?= htmlspecialchars($titulo ?? 'DNS Admin') ?> - DNS Admin v<?= $versao ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= $versao ?>">
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="brand-icon">🌐</div>
      <div class="brand-info">
        <h2>DNS Admin</h2>
        <span class="brand-version">v<?= $versao ?></span>
      </div>
    </div>
    <nav class="sidebar-nav">
      <a href="dashboard.php" class="<?= $paginaAtual === 'dashboard' ? 'active' : '' ?>">
        <span class="nav-icon">📊</span>
        <span>Dashboard</span>
      </a>
      
      <!-- ⚡ GRUPO: Autoritativo -->
      <div class="nav-group">
        <div class="nav-group-title <?= $abrirAutoritativo ? 'open' : '' ?>" 
             onclick="toggleGrupo('autoritativo')">
          <span class="nav-icon">🗂️</span>
          <span class="nav-label">Autoritativo</span>
          <span class="nav-arrow"><?= $abrirAutoritativo ? '▾' : '▸' ?></span>
        </div>
        <div class="nav-submenu" id="submenu-autoritativo" 
             style="max-height: <?= $abrirAutoritativo ? '200px' : '0' ?>;">
          <a href="zonas.php" class="<?= $paginaAtual === 'zonas' ? 'active' : '' ?>">
            <span class="nav-dot"></span>
            <span>Conf Zonas</span>
          </a>
          <a href="registros.php" class="<?= $paginaAtual === 'registros' ? 'active' : '' ?>">
            <span class="nav-dot"></span>
            <span>Registros</span>
          </a>
        </div>
      </div>
      
      <!-- ⚡ GRUPO: Recursivo -->
      <div class="nav-group">
        <div class="nav-group-title <?= $abrirRecursivo ? 'open' : '' ?>" 
             onclick="toggleGrupo('recursivo')">
          <span class="nav-icon">🔄</span>
          <span class="nav-label">Recursivo</span>
          <span class="nav-arrow"><?= $abrirRecursivo ? '▾' : '▸' ?></span>
        </div>
        <div class="nav-submenu" id="submenu-recursivo" 
             style="max-height: <?= $abrirRecursivo ? '300px' : '0' ?>;">
          <a href="stats.php" class="<?= $paginaAtual === 'stats' ? 'active' : '' ?>">
            <span class="nav-dot"></span>
            <span>Estatísticas</span>
          </a>
          <a href="consulta.php" class="<?= $paginaAtual === 'consulta' ? 'active' : '' ?>">
            <span class="nav-dot"></span>
            <span>Consulta DNS</span>
          </a>
          <a href="configuracoes.php" class="<?= $paginaAtual === 'config' ? 'active' : '' ?>">
            <span class="nav-dot"></span>
            <span>Configurações</span>
          </a>
        </div>
      </div>
      
      <!-- ⚡ Menu normal -->
      <a href="usuarios.php" class="<?= $paginaAtual === 'usuarios' ? 'active' : '' ?>">
        <span class="nav-icon">👥</span>
        <span>Usuários</span>
      </a>
    </nav>
    <div class="sidebar-footer">
      <div class="user-info">
        <div class="user-avatar"><?= strtoupper(substr($user['nome'], 0, 1)) ?></div>
        <div>
          <div class="user-name"><?= htmlspecialchars($user['nome']) ?></div>
          <div class="user-role"><?= htmlspecialchars($user['nivel']) ?></div>
        </div>
      </div>
      <a href="logout.php" class="btn-logout">Sair</a>
    </div>
  </aside>
  <main class="main-content">
    <header class="topbar">
      <h1><?= htmlspecialchars($titulo ?? 'Dashboard') ?></h1>
      <div class="topbar-right">
        <span>Bem-vindo, <strong><?= htmlspecialchars($user['nome']) ?></strong></span>
      </div>
    </header>
    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['tipo'] === 'success' ? 'success' : 'error' ?>">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>
    
    <script>
    function toggleGrupo(grupo) {
        const subAutoritativo = document.getElementById('submenu-autoritativo');
        const subRecursivo = document.getElementById('submenu-recursivo');
        const btnAutoritativo = document.querySelectorAll('.nav-group-title')[0];
        const btnRecursivo = document.querySelectorAll('.nav-group-title')[1];
        
        if (grupo === 'autoritativo') {
            const aberto = subAutoritativo.style.maxHeight !== '0px' && subAutoritativo.style.maxHeight !== '';
            
            subAutoritativo.style.maxHeight = aberto ? '0' : '200px';
            subRecursivo.style.maxHeight = '0';
            btnAutoritativo.classList.toggle('open', !aberto);
            btnRecursivo.classList.remove('open');
            btnAutoritativo.querySelector('.nav-arrow').textContent = aberto ? '▸' : '▾';
            btnRecursivo.querySelector('.nav-arrow').textContent = '▸';
        } else {
            const aberto = subRecursivo.style.maxHeight !== '0px' && subRecursivo.style.maxHeight !== '';
            
            subRecursivo.style.maxHeight = aberto ? '0' : '300px';
            subAutoritativo.style.maxHeight = '0';
            btnRecursivo.classList.toggle('open', !aberto);
            btnAutoritativo.classList.remove('open');
            btnRecursivo.querySelector('.nav-arrow').textContent = aberto ? '▸' : '▾';
            btnAutoritativo.querySelector('.nav-arrow').textContent = '▸';
        }
    }
    </script>
