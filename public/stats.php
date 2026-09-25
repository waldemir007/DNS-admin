<?php
require_once __DIR__ . '/../app/controllers/Auth.php';

$paginaAtual = 'stats';
$titulo = 'Estatísticas DNS';

// Carrega dados do cache JSON
$cacheFile = '/var/www/dns-admin/cache/dns_stats.json';
$stats = null;
$cacheIdade = null;

if (file_exists($cacheFile)) {
    $json = @file_get_contents($cacheFile);
    if ($json) {
        $stats = @json_decode($json, true);
        if ($stats) $cacheIdade = time() - filemtime($cacheFile);
    }
}

// Aba ativa
$aba = $_GET['aba'] ?? '5min';
if (!in_array($aba, ['5min', '1h', '24h'])) $aba = '5min';

$dados = $stats['janelas'][$aba] ?? null;

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<?php if (!$stats): ?>
  <div class="panel">
    <div class="alert alert-error" style="display:block;">
      ⚠️ Nenhum dado disponível. Execute:
      <code>sudo -u www-data php /var/www/dns-admin/scripts/analisar_dns.php</code>
    </div>
  </div>
<?php else: ?>

<!-- Abas -->
<div class="panel" style="margin-bottom:20px; padding:16px 24px;">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <h2 style="margin:0; font-size:15px;">📊 Período de análise</h2>
    <div class="periodo-tabs">
      <a href="?aba=5min" class="<?= $aba === '5min' ? 'active' : '' ?>">⚡ 5 minutos</a>
      <a href="?aba=1h"   class="<?= $aba === '1h'   ? 'active' : '' ?>">🕐 Última hora</a>
      <a href="?aba=24h"  class="<?= $aba === '24h'  ? 'active' : '' ?>">📅 24 horas</a>
    </div>
    <?php if ($cacheIdade !== null): ?>
      <small style="color:var(--text-muted); font-size:11px;">
        Cache: há <?= $cacheIdade ?>s
      </small>
    <?php endif; ?>
  </div>
</div>

<?php if (!$dados): ?>
  <div class="panel"><p>Sem dados para este período.</p></div>
<?php else: ?>

<!-- Cards resumo -->
<section class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); margin-bottom:20px;">
  <div class="stat-card">
    <div class="stat-icon">📈</div>
    <div class="stat-info">
      <div class="stat-value"><?= number_format($dados['total_queries'], 0, ',', '.') ?></div>
      <div class="stat-label">Consultas</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🖥️</div>
    <div class="stat-info">
      <div class="stat-value"><?= number_format($dados['ips_unicos'], 0, ',', '.') ?></div>
      <div class="stat-label">IPs únicos</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🌐</div>
    <div class="stat-info">
      <div class="stat-value"><?= number_format($dados['dominios_unicos'], 0, ',', '.') ?></div>
      <div class="stat-label">Domínios únicos</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">⚡</div>
    <div class="stat-info">
      <div class="stat-value"><?= number_format($dados['qps'], 1, ',', '.') ?></div>
      <div class="stat-label">QPS médio</div>
    </div>
  </div>
</section>

<!-- Gráfico série temporal (linha) -->
<?php if (!empty($dados['serie_temporal']) && count($dados['serie_temporal']) > 1): ?>
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header">
    <h2>📈 Consultas ao longo do tempo</h2>
  </div>
  <div style="position:relative; height:300px;">
    <canvas id="chart-serie"></canvas>
  </div>
</div>
<?php endif; ?>

<!-- Top domínios (barra) + Tipos (rosca) -->
<div class="dashboard-grid">
  
  <div class="panel">
    <div class="panel-header"><h2>🔥 Top 10 Domínios</h2></div>
    <?php if (empty($dados['top_dominios'])): ?>
      <div class="empty-state" style="padding:30px;"><p>Sem dados.</p></div>
    <?php else: ?>
      <div style="position:relative; height:320px;">
        <canvas id="chart-dominios"></canvas>
      </div>
    <?php endif; ?>
  </div>
  
  <div class="panel">
    <div class="panel-header"><h2>🏷️ Tipos de Registro</h2></div>
    <?php if (empty($dados['tipos'])): ?>
      <div class="empty-state" style="padding:30px;"><p>Sem dados.</p></div>
    <?php else: ?>
      <div style="position:relative; height:320px;">
        <canvas id="chart-tipos"></canvas>
      </div>
    <?php endif; ?>
  </div>
  
</div>

<!-- Top IPs (barra) + Respostas (rosca) -->
<div class="dashboard-grid">
  
  <div class="panel">
    <div class="panel-header"><h2>👥 Top 10 IPs</h2></div>
    <?php if (empty($dados['top_ips'])): ?>
      <div class="empty-state" style="padding:30px;"><p>Sem dados.</p></div>
    <?php else: ?>
      <div style="position:relative; height:320px;">
        <canvas id="chart-ips"></canvas>
      </div>
    <?php endif; ?>
  </div>
  
  <div class="panel">
    <div class="panel-header"><h2>📊 Respostas</h2></div>
    <?php if (empty($dados['respostas'])): ?>
      <div class="empty-state" style="padding:30px;"><p>Sem dados.</p></div>
    <?php else: ?>
      <div style="position:relative; height:320px;">
        <canvas id="chart-respostas"></canvas>
      </div>
    <?php endif; ?>
  </div>
  
</div>

<!-- TLDs (barra) + Blocos (tabela) -->
<div class="dashboard-grid">
  
  <div class="panel">
    <div class="panel-header"><h2>🌍 Top 10 TLDs</h2></div>
    <?php if (empty($dados['top_tlds'])): ?>
      <div class="empty-state" style="padding:30px;"><p>Sem dados.</p></div>
    <?php else: ?>
      <div style="position:relative; height:320px;">
        <canvas id="chart-tlds"></canvas>
      </div>
    <?php endif; ?>
  </div>
  
  <div class="panel">
    <div class="panel-header"><h2>🌐 Blocos de IP</h2></div>
    <?php if (empty($dados['blocos'])): ?>
      <div class="empty-state" style="padding:30px;"><p>Sem dados.</p></div>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>Bloco</th>
              <th class="text-right" style="width:80px;">IPs</th>
              <th class="text-right" style="width:100px;">Consultas</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dados['blocos'] as $b): ?>
              <tr>
                <td style="font-family:monospace; font-size:13px; color:#93c5fd;">
                  <?= htmlspecialchars($b['bloco']) ?>
                </td>
                <td class="text-right"><span class="badge badge-muted"><?= $b['ips_unicos'] ?></span></td>
                <td class="text-right"><strong><?= number_format($b['total'], 0, ',', '.') ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  
</div>

<!-- Erros + Suspeitos -->
<div class="dashboard-grid">
  
  <div class="panel">
    <div class="panel-header"><h2>⚠️ Domínios com Erro</h2></div>
    <?php if (empty($dados['dominios_erro'])): ?>
      <div class="empty-state" style="padding:30px;"><p>Nenhum erro detectado.</p></div>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr><th>Domínio</th><th class="text-right" style="width:100px;">Ocorrências</th></tr>
          </thead>
          <tbody>
            <?php foreach ($dados['dominios_erro'] as $e): ?>
              <tr>
                <td style="font-family:monospace; font-size:13px;"><?= htmlspecialchars($e['dominio']) ?></td>
                <td class="text-right"><span class="badge badge-danger"><?= number_format($e['total'], 0, ',', '.') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  
  <div class="panel">
    <div class="panel-header"><h2>🚨 IPs com Erros</h2></div>
    <?php if (empty($dados['ips_suspeitos'])): ?>
      <div class="empty-state" style="padding:30px;"><p>Nenhum IP suspeito.</p></div>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr><th>IP</th><th class="text-right" style="width:100px;">Erros</th></tr>
          </thead>
          <tbody>
            <?php foreach ($dados['ips_suspeitos'] as $s): ?>
              <tr>
                <td style="font-family:monospace; font-size:13px;"><?= htmlspecialchars($s['ip']) ?></td>
                <td class="text-right"><span class="badge badge-danger"><?= number_format($s['total'], 0, ',', '.') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  
</div>

<!-- Chart.js -->
<script src="assets/js/chart.min.js"></script>
<script>
// Paleta de cores
const CORES = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#ec4899','#14b8a6','#f97316','#6366f1','#84cc16'];
const CORES_RESP = {
  'NOERROR':  '#10b981',
  'NXDOMAIN': '#ef4444',
  'SERVFAIL': '#f59e0b',
  'REFUSED':  '#8b5cf6',
  'FORMERR':  '#6366f1',
};

Chart.defaults.color = '#8b95ab';
Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
Chart.defaults.font.family = "'Inter', sans-serif";

// ============ Série temporal (linha) ============
<?php if (!empty($dados['serie_temporal']) && count($dados['serie_temporal']) > 1): ?>
const serie = <?= json_encode($dados['serie_temporal']) ?>;
new Chart(document.getElementById('chart-serie'), {
  type: 'line',
  data: {
    labels: serie.map(d => {
      const p = d.periodo.split(' ')[1] || d.periodo;
      return p.substring(0, 5);
    }),
    datasets: [
      {
        label: 'Total',
        data: serie.map(d => parseInt(d.total)),
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59,130,246,0.15)',
        borderWidth: 2,
        fill: true,
        tension: 0.35,
        pointRadius: 2,
      },
      {
        label: 'Erros',
        data: serie.map(d => parseInt(d.erros)),
        borderColor: '#ef4444',
        backgroundColor: 'rgba(239,68,68,0.08)',
        borderWidth: 1.5,
        fill: true,
        tension: 0.35,
        pointRadius: 2,
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: { legend: { position: 'top', align: 'end' } },
    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } }
  }
});
<?php endif; ?>

// ============ Top domínios (barra horizontal) ============
<?php if (!empty($dados['top_dominios'])): ?>
const dom = <?= json_encode($dados['top_dominios']) ?>;
new Chart(document.getElementById('chart-dominios'), {
  type: 'bar',
  data: {
    labels: dom.map(d => d.dominio.length > 30 ? d.dominio.substring(0, 27) + '...' : d.dominio),
    datasets: [{
      label: 'Consultas',
      data: dom.map(d => parseInt(d.total)),
      backgroundColor: dom.map((_, i) => CORES[i % CORES.length]),
      borderRadius: 6,
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
  }
});
<?php endif; ?>

// ============ Tipos (rosca) ============
<?php if (!empty($dados['tipos'])): ?>
const tipos = <?= json_encode($dados['tipos']) ?>;
new Chart(document.getElementById('chart-tipos'), {
  type: 'doughnut',
  data: {
    labels: Object.keys(tipos),
    datasets: [{
      data: Object.values(tipos),
      backgroundColor: CORES,
      borderColor: '#131826',
      borderWidth: 3,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false, cutout: '65%',
    plugins: { legend: { position: 'right' } }
  }
});
<?php endif; ?>

// ============ Top IPs (barra horizontal) ============
<?php if (!empty($dados['top_ips'])): ?>
const ips = <?= json_encode($dados['top_ips']) ?>;
new Chart(document.getElementById('chart-ips'), {
  type: 'bar',
  data: {
    labels: ips.map(d => d.ip),
    datasets: [{
      label: 'Consultas',
      data: ips.map(d => parseInt(d.total)),
      backgroundColor: ips.map((_, i) => CORES[i % CORES.length]),
      borderRadius: 6,
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
  }
});
<?php endif; ?>

// ============ Respostas (rosca) ============
<?php if (!empty($dados['respostas'])): ?>
const resp = <?= json_encode($dados['respostas']) ?>;
new Chart(document.getElementById('chart-respostas'), {
  type: 'doughnut',
  data: {
    labels: Object.keys(resp),
    datasets: [{
      data: Object.values(resp),
      backgroundColor: Object.keys(resp).map(k => CORES_RESP[k] || '#8b95ab'),
      borderColor: '#131826',
      borderWidth: 3,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false, cutout: '65%',
    plugins: { legend: { position: 'right' } }
  }
});
<?php endif; ?>

// ============ TLDs (barra horizontal) ============
<?php if (!empty($dados['top_tlds'])): ?>
const tlds = <?= json_encode($dados['top_tlds']) ?>;
new Chart(document.getElementById('chart-tlds'), {
  type: 'bar',
  data: {
    labels: tlds.map(d => '.' + d.tld),
    datasets: [{
      label: 'Consultas',
      data: tlds.map(d => parseInt(d.total)),
      backgroundColor: tlds.map((_, i) => CORES[i % CORES.length]),
      borderRadius: 6,
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
  }
});
<?php endif; ?>
</script>

<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
