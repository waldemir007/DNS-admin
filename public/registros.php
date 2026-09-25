<?php
require_once __DIR__ . '/../app/controllers/ZonaController.php';

$paginaAtual = 'registros';
$titulo = 'Registros DNS';

$zonaFiltro = (int)($_GET['zona'] ?? 0);
$busca = trim($_GET['q'] ?? '');

$zonas = ZonaController::listar();
$registros = ZonaController::listarTodosRegistros($zonaFiltro ?: null, $busca);

$zonaAtual = null;
if ($zonaFiltro) {
    $zonaAtual = ZonaController::buscar($zonaFiltro);
}

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<div class="panel">
  <div class="panel-header">
    <h2>
      <?php if ($zonaAtual): ?>
        Registros da zona: <span style="font-family:monospace;color:#93c5fd;"><?= htmlspecialchars($zonaAtual['nome']) ?></span>
      <?php else: ?>
        Todos os Registros
      <?php endif; ?>
    </h2>
    <div class="panel-actions">
      <form method="GET" class="search-form">
        <?php if ($zonaFiltro): ?>
          <input type="hidden" name="zona" value="<?= $zonaFiltro ?>">
        <?php endif; ?>
        <input type="text" name="q" placeholder="Buscar nome ou valor..." value="<?= htmlspecialchars($busca) ?>">
        <button type="submit" class="btn btn-secondary">🔍</button>
      </form>
      <a href="registro-form.php<?= $zonaFiltro ? '?zona=' . $zonaFiltro : '' ?>" class="btn btn-primary">+ Novo Registro</a>
    </div>
  </div>
  
  <?php if (empty($zonas)): ?>
    <div class="empty-state">
      <div class="empty-icon">🗂️</div>
      <p>Você precisa criar uma zona DNS primeiro.</p>
      <a href="zona-form.php" class="btn btn-primary" style="margin-top:16px;">Criar Zona</a>
    </div>
  <?php else: ?>
    
    <!-- Filtro de zona -->
    <div class="zona-tabs">
      <a href="registros.php" class="<?= !$zonaFiltro ? 'active' : '' ?>">Todas</a>
      <?php foreach ($zonas as $z): ?>
        <a href="registros.php?zona=<?= $z['id'] ?>" class="<?= $zonaFiltro === (int)$z['id'] ? 'active' : '' ?>">
          <?= htmlspecialchars($z['nome']) ?>
          <span class="tab-count"><?= $z['total_registros'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    
    <?php if (empty($registros)): ?>
      <div class="empty-state">
        <div class="empty-icon">📝</div>
        <p>Nenhum registro encontrado.</p>
        <a href="registro-form.php<?= $zonaFiltro ? '?zona=' . $zonaFiltro : '' ?>" class="btn btn-primary" style="margin-top:16px;">Criar primeiro registro</a>
      </div>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <?php if (!$zonaFiltro): ?>
                <th>Zona</th>
              <?php endif; ?>
              <th>Nome</th>
              <th>Tipo</th>
              <th>TTL</th>
              <th>Valor</th>
              <th class="text-right">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($registros as $r): ?>
              <tr>
                <?php if (!$zonaFiltro): ?>
                  <td>
                    <a href="registros.php?zona=<?= $r['zona_id'] ?>" style="color:#93c5fd;text-decoration:none;font-family:monospace;font-size:12px;">
                      <?= htmlspecialchars($r['zona_nome']) ?>
                    </a>
                  </td>
                <?php endif; ?>
                <td><strong style="font-family:monospace;"><?= htmlspecialchars($r['nome']) ?></strong></td>
                <td><span class="badge badge-tipo-<?= strtolower($r['tipo']) ?>"><?= $r['tipo'] ?></span></td>
                <td><small style="color:var(--text-muted);"><?= $r['ttl'] ?: 'padrão' ?></small></td>
                <td>
                  <span style="font-family:monospace;font-size:13px;color:#cbd5e1;">
                    <?php if (in_array($r['tipo'], ['MX','SRV']) && $r['prioridade'] !== null): ?>
                      <span style="color:#fbbf24;"><?= $r['prioridade'] ?></span>
                    <?php endif; ?>
                    <?= htmlspecialchars($r['valor']) ?>
                  </span>
                </td>
                <td class="text-right">
                  <div class="action-buttons">
                    <a href="registro-form.php?id=<?= $r['id'] ?>" class="btn-icon" title="Editar">✏️</a>
                    <form method="POST" action="registro-action.php" style="display:inline;"
                          onsubmit="return confirm('Excluir este registro?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $r['id'] ?>">
                      <button type="submit" class="btn-icon btn-icon-danger" title="Excluir">🗑️</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
