<?php
require_once __DIR__ . '/../app/controllers/ZonaController.php';

$paginaAtual = 'zonas';
$titulo = 'Conf Zonas';

$busca = trim($_GET['q'] ?? '');
$zonas = ZonaController::listar($busca);
$stats = ZonaController::estatisticas();
$bindAtivo = ZonaController::statusBind();

// Conta zonas reversas e normais
$totalReversas = 0;
$totalComuns = 0;
foreach ($zonas as $z) {
    if (preg_match('/\.(in-addr|ip6)\.arpa$/', $z['nome'])) $totalReversas++;
    else $totalComuns++;
}

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<section class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">
  <div class="stat-card">
    <div class="stat-icon">🗂️</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['total_zonas'] ?></div>
      <div class="stat-label">Total de Zonas</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🌐</div>
    <div class="stat-info">
      <div class="stat-value"><?= $totalComuns ?></div>
      <div class="stat-label">Zonas Normais</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🔄</div>
    <div class="stat-info">
      <div class="stat-value"><?= $totalReversas ?></div>
      <div class="stat-label">Zonas Reversas</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon"><?= $bindAtivo ? '🟢' : '🔴' ?></div>
    <div class="stat-info">
      <div class="stat-value"><?= $bindAtivo ? 'ON' : 'OFF' ?></div>
      <div class="stat-label">Bind9</div>
    </div>
  </div>
</section>

<div class="panel">
  <div class="panel-header">
    <h2>Conf Zonas</h2>
    <div class="panel-actions">
      <form method="GET" class="search-form">
        <input type="text" name="q" placeholder="Buscar..." value="<?= htmlspecialchars($busca) ?>">
        <button class="btn btn-secondary">🔍</button>
      </form>
      <a href="zona-reversa-form.php" class="btn btn-secondary">🔄 Nova Zona Reversa</a>
      <a href="zona-form.php" class="btn btn-primary">+ Nova Zona</a>
    </div>
  </div>
  
  <?php if (empty($zonas)): ?>
    <div class="empty-state">
      <div class="empty-icon">🗂️</div>
      <p>Nenhuma zona cadastrada.</p>
      <div style="display:flex; gap:10px; justify-content:center; margin-top:16px; flex-wrap:wrap;">
        <a href="zona-form.php" class="btn btn-primary">+ Criar Zona Normal</a>
        <a href="zona-reversa-form.php" class="btn btn-secondary">🔄 Criar Zona Reversa</a>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Zona</th>
            <th>Tipo</th>
            <th>Serial</th>
            <th>Registros</th>
            <th>Status</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($zonas as $z): 
            $isReversa = preg_match('/\.(in-addr|ip6)\.arpa$/', $z['nome']) === 1;
          ?>
            <tr>
              <td>
                <strong style="font-family:monospace;"><?= htmlspecialchars($z['nome']) ?></strong>
                <?php if ($isReversa): ?>
                  <span class="badge badge-primary" style="margin-left:6px; font-size:10px;">REVERSA</span>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-<?= $z['tipo'] === 'master' ? 'primary' : 'muted' ?>"><?= $z['tipo'] ?></span></td>
              <td><small><?= $z['serial'] ?></small></td>
              <td>
                <?php if ($isReversa): ?>
                  <a href="ptr-bloco.php?id=<?= $z['id'] ?>" class="badge badge-primary" style="text-decoration:none;">
                    🔄 Gerenciar PTRs
                  </a>
                <?php else: ?>
                  <a href="registros.php?zona=<?= $z['id'] ?>" class="badge badge-primary" style="text-decoration:none;">
                    <?= $z['total_registros'] ?> registros
                  </a>
                <?php endif; ?>
              </td>
              <td><?= $z['ativo'] ? '<span class="badge badge-success">● Ativa</span>' : '<span class="badge badge-danger">● Inativa</span>' ?></td>
              <td class="text-right">
                <div class="action-buttons">
                  <?php if ($isReversa): ?>
                    <a href="ptr-bloco.php?id=<?= $z['id'] ?>" class="btn-icon" title="Gerenciar PTRs">🔄</a>
                  <?php else: ?>
                    <a href="registros.php?zona=<?= $z['id'] ?>" class="btn-icon" title="Ver registros">📝</a>
                  <?php endif; ?>
                  <a href="zona-form.php?id=<?= $z['id'] ?>" class="btn-icon" title="Editar">✏️</a>
                  <form method="POST" action="zona-action.php" style="display:inline;" onsubmit="return confirm('Excluir a zona <?= htmlspecialchars($z['nome']) ?>? Todos os registros serão perdidos.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $z['id'] ?>">
                    <button class="btn-icon btn-icon-danger" title="Excluir">🗑️</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
