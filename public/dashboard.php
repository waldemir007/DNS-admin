<?php
require_once __DIR__ . '/../app/controllers/Auth.php';
require_once __DIR__ . '/../app/controllers/ZonaController.php';

$paginaAtual = 'dashboard';
$titulo = 'Dashboard';
$user = Auth::user();

// ============================================================
// Estatísticas do painel (banco)
// ============================================================
$stats = [
    'zonas' => db()->query("SELECT COUNT(*) FROM zonas")->fetchColumn(),
    'registros' => db()->query("SELECT COUNT(*) FROM registros")->fetchColumn(),
    'usuarios' => db()->query("SELECT COUNT(*) FROM usuarios WHERE ativo=1")->fetchColumn(),
];

// ============================================================
// Estatísticas DNS (cache JSON — instantâneo)
// ============================================================
$cacheFile = '/var/www/dns-admin/cache/dns_stats.json';
$dnsStats = null;
$cacheIdade = null;

if (file_exists($cacheFile)) {
    $json = @file_get_contents($cacheFile);
    if ($json) {
        $dnsStats = @json_decode($json, true);
        if ($dnsStats) {
            $cacheIdade = time() - filemtime($cacheFile);
        }
    }
}

$consultas24h = 0;
if ($dnsStats && !empty($dnsStats['janelas']['24h']['total_queries'])) {
    $consultas24h = (int)$dnsStats['janelas']['24h']['total_queries'];
}

// ============================================================
// Status do Bind9 (usando método correto do ZonaController)
// ============================================================
$bindAtivo = ZonaController::statusBind();

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<!-- Cards de resumo -->
<section class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon">🗂️</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['zonas'] ?></div>
      <div class="stat-label">Zonas DNS</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📝</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['registros'] ?></div>
      <div class="stat-label">Registros</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📈</div>
    <div class="stat-info">
      <div class="stat-value"><?= number_format($consultas24h, 0, ',', '.') ?></div>
      <div class="stat-label">Consultas 24h</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">👥</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['usuarios'] ?></div>
      <div class="stat-label">Usuários Ativos</div>
    </div>
  </div>
</section>

<!-- Status Bind + Ações rápidas -->
<section class="panels">
  <div class="panel">
    <h2>Status do Bind9</h2>
    <div class="status-list">
      <div class="status-item">
        <span class="status-dot <?= $bindAtivo ? 'status-online' : 'status-offline' ?>"></span>
        <span>Serviço Bind9</span>
        <span class="status-label" style="color: <?= $bindAtivo ? '#6ee7b7' : '#fca5a5' ?>;">
          <?= $bindAtivo ? 'Ativo' : 'Inativo' ?>
        </span>
      </div>
      <?php if ($cacheIdade !== null): ?>
      <div class="status-item">
        <span class="status-dot <?= $cacheIdade < 600 ? 'status-online' : 'status-offline' ?>"></span>
        <span>Análise DNS (cache)</span>
        <span class="status-label">
          <?= $cacheIdade < 60 ? 'Atualizado agora' : 'Há ' . floor($cacheIdade / 60) . ' min' ?>
        </span>
      </div>
      <?php endif; ?>
    </div>
  </div>
  
  <div class="panel">
    <h2>Ações Rápidas</h2>
    <div class="quick-actions">
      <a href="zona-form.php" class="btn btn-secondary">+ Nova Zona</a>
      <a href="zona-reversa-form.php" class="btn btn-secondary">🔄 Nova Zona Reversa</a>
      <a href="registro-form.php" class="btn btn-secondary">+ Novo Registro</a>
      <a href="usuario-form.php" class="btn btn-secondary">+ Novo Usuário</a>
    </div>
  </div>
</section>

<!-- Top domínios -->
<?php if ($dnsStats && !empty($dnsStats['janelas']['24h']['top_dominios'])): ?>
<div class="panel" style="margin-top:20px;">
  <div class="panel-header">
    <h2>🔥 Top 10 Domínios (24h)</h2>
    <a href="stats.php" class="btn btn-secondary" style="font-size:13px;">Ver estatísticas →</a>
  </div>
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:40px;">#</th>
          <th>Domínio</th>
          <th style="width:70px;">Tipo</th>
          <th style="width:120px;" class="text-right">Consultas</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($dnsStats['janelas']['24h']['top_dominios'], 0, 10) as $i => $d): ?>
          <tr>
            <td><span class="badge-id"><?= $i + 1 ?></span></td>
            <td style="font-family:monospace; font-size:13px;"><?= htmlspecialchars($d['dominio']) ?></td>
            <td><span class="badge badge-tipo-<?= strtolower($d['tipo']) ?>"><?= $d['tipo'] ?></span></td>
            <td class="text-right"><strong><?= number_format($d['total'], 0, ',', '.') ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
