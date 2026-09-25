<?php
require_once __DIR__ . '/../app/controllers/UsuarioController.php';

$paginaAtual = 'usuarios';
$titulo = 'Gerenciar Usuários';

$busca = trim($_GET['q'] ?? '');
$usuarios = UsuarioController::listar($busca);
$stats = UsuarioController::estatisticas();

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<section class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">
  <div class="stat-card">
    <div class="stat-icon">👥</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['total'] ?></div>
      <div class="stat-label">Total de Usuários</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">✅</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['ativos'] ?></div>
      <div class="stat-label">Ativos</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🚫</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['inativos'] ?></div>
      <div class="stat-label">Inativos</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🔑</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['admins'] ?></div>
      <div class="stat-label">Administradores</div>
    </div>
  </div>
</section>

<div class="panel">
  <div class="panel-header">
    <h2>Lista de Usuários</h2>
    <div class="panel-actions">
      <form method="GET" class="search-form">
        <input type="text" name="q" placeholder="Buscar usuário..." value="<?= htmlspecialchars($busca) ?>">
        <button type="submit" class="btn btn-secondary">🔍</button>
      </form>
      <a href="usuario-form.php" class="btn btn-primary">+ Novo Usuário</a>
    </div>
  </div>
  
  <?php if (empty($usuarios)): ?>
    <div class="empty-state">
      <div class="empty-icon">👤</div>
      <p>Nenhum usuário encontrado.</p>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Usuário</th>
            <th>Nome</th>
            <th>E-mail</th>
            <th>Nível</th>
            <th>Status</th>
            <th>Último Login</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($usuarios as $u): ?>
            <tr>
              <td><span class="badge-id">#<?= $u['id'] ?></span></td>
              <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
              <td><?= htmlspecialchars($u['nome']) ?></td>
              <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
              <td>
                <span class="badge badge-<?= $u['nivel'] === 'admin' ? 'primary' : 'muted' ?>">
                  <?= $u['nivel'] === 'admin' ? '🔑 Admin' : '👤 User' ?>
                </span>
              </td>
              <td>
                <?php if ($u['ativo']): ?>
                  <span class="badge badge-success">● Ativo</span>
                <?php else: ?>
                  <span class="badge badge-danger">● Inativo</span>
                <?php endif; ?>
              </td>
              <td>
                <small style="color: var(--text-muted);">
                  <?= $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : 'Nunca' ?>
                </small>
              </td>
              <td class="text-right">
                <div class="action-buttons">
                  <a href="usuario-form.php?id=<?= $u['id'] ?>" class="btn-icon" title="Editar">✏️</a>
                  
                  <form method="POST" action="usuario-action.php" style="display:inline;" 
                        onsubmit="return confirm('Alternar status deste usuário?');">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn-icon" title="<?= $u['ativo'] ? 'Desativar' : 'Ativar' ?>">
                      <?= $u['ativo'] ? '🚫' : '✅' ?>
                    </button>
                  </form>
                  
                  <form method="POST" action="usuario-action.php" style="display:inline;"
                        onsubmit="return confirm('Excluir o usuário <?= htmlspecialchars($u['username']) ?>? Esta ação não pode ser desfeita.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
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
</div>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
