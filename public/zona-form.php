<?php
require_once __DIR__ . '/../app/controllers/ZonaController.php';

$paginaAtual = 'zonas';
$id = (int)($_GET['id'] ?? 0);
$editando = $id > 0;
$titulo = $editando ? 'Editar Zona' : 'Nova Zona';

$zona = $editando ? ZonaController::buscar($id) : null;
if ($editando && !$zona) {
    $_SESSION['flash'] = ['tipo' => 'error', 'msg' => 'Zona não encontrada.'];
    header('Location: zonas.php');
    exit;
}

// Redireciona se for zona reversa
if ($zona && preg_match('/\.(in-addr|ip6)\.arpa$/', $zona['nome'])) {
    $_SESSION['flash'] = ['tipo' => 'error', 'msg' => 'Zonas reversas devem ser editadas na tela de zona reversa.'];
    header('Location: zona-reversa-form.php?id=' . $id);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$erros = $_SESSION['form_erros'] ?? [];
$dadosAntigos = $_SESSION['form_dados'] ?? [];
unset($_SESSION['form_erros'], $_SESSION['form_dados']);

if (!empty($dadosAntigos)) {
    $dados = $dadosAntigos;
} elseif ($zona) {
    $dados = $zona;
} else {
    $dados = [
        'nome' => '', 'tipo' => 'master', 'primario' => '',
        'admin_email' => 'admin.' . ($_SERVER['SERVER_NAME'] ?? 'exemplo.com.br'),
        'ttl' => 3600, 'refresh' => 10800, 'retry' => 3600,
        'expire' => 604800, 'negative_ttl' => 86400,
        'serial' => date('Ymd') . '01', 'ativo' => 1,
    ];
}

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<div class="panel" style="max-width: 860px;">
  <div class="panel-header">
    <h2><?= $editando ? '✏️ Editar Zona: ' . htmlspecialchars($zona['nome']) : '🗂️ Nova Zona DNS' ?></h2>
    <a href="zonas.php" class="btn btn-secondary">← Voltar</a>
  </div>
  
  <?php if (!$editando): ?>
    <div style="margin-bottom:20px; padding:12px 16px; background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.2); border-radius:8px; font-size:13px; color:#93c5fd;">
      💡 <strong>Zona normal</strong> — para domínios como <code>exemplo.com.br</code>.
      Se quiser criar uma <strong>zona reversa</strong> (DNS reverso), use
      <a href="zona-reversa-form.php" style="color:#93c5fd; font-weight:600; text-decoration:underline;">Nova Zona Reversa →</a>
    </div>
  <?php endif; ?>
  
  <?php if (!empty($erros)): ?>
    <div class="alert alert-error" style="display: block;">
      <strong>Corrija os erros:</strong>
      <ul style="margin: 8px 0 0 20px;">
        <?php foreach ($erros as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  
  <form method="POST" action="zona-action.php" class="form-horizontal">
    <input type="hidden" name="action" value="<?= $editando ? 'update' : 'create' ?>">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    
    <h3 style="color:#fff;font-size:15px;margin-bottom:16px;border-bottom:1px solid var(--border);padding-bottom:10px;">Informações Básicas</h3>
    
    <div class="form-row">
      <div class="form-group">
        <label for="nome">Nome da Zona *</label>
        <input type="text" id="nome" name="nome" required
               value="<?= htmlspecialchars($dados['nome']) ?>"
               placeholder="exemplo.com.br"
               pattern="([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}">
        <small>Domínio sem www. Ex: exemplo.com.br</small>
      </div>
      
      <div class="form-group">
        <label for="tipo">Tipo</label>
        <select id="tipo" name="tipo" onchange="togglePrimario()">
          <option value="master" <?= ($dados['tipo'] ?? '') === 'master' ? 'selected' : '' ?>>🏠 Master (primário)</option>
          <option value="slave" <?= ($dados['tipo'] ?? '') === 'slave' ? 'selected' : '' ?>>🔄 Slave (secundário)</option>
        </select>
      </div>
    </div>
    
    <div class="form-row" id="primario-row" style="display:<?= ($dados['tipo'] ?? '') === 'slave' ? 'grid' : 'none' ?>;">
      <div class="form-group">
        <label for="primario">IP do Servidor Primário</label>
        <input type="text" id="primario" name="primario"
               value="<?= htmlspecialchars($dados['primario'] ?? '') ?>"
               placeholder="192.168.1.1">
      </div>
    </div>
    
    <div class="form-group">
      <label for="admin_email">E-mail do Administrador (formato DNS) *</label>
      <input type="text" id="admin_email" name="admin_email" required
             value="<?= htmlspecialchars($dados['admin_email']) ?>"
             placeholder="admin.exemplo.com.br">
      <small>Use <strong>ponto</strong> em vez de @. Ex: admin.exemplo.com.br</small>
    </div>
    
    <h3 style="color:#fff;font-size:15px;margin:24px 0 16px;border-bottom:1px solid var(--border);padding-bottom:10px;">Parâmetros SOA</h3>
    
    <div class="form-row">
      <div class="form-group">
        <label for="serial">Serial</label>
        <input type="number" id="serial" name="serial" value="<?= (int)$dados['serial'] ?>">
        <small>Incrementa automaticamente ao editar registros.</small>
      </div>
      <div class="form-group">
        <label for="ttl">TTL Padrão (segundos)</label>
        <input type="number" id="ttl" name="ttl" value="<?= (int)$dados['ttl'] ?>">
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="refresh">Refresh (segundos)</label>
        <input type="number" id="refresh" name="refresh" value="<?= (int)$dados['refresh'] ?>">
      </div>
      <div class="form-group">
        <label for="retry">Retry (segundos)</label>
        <input type="number" id="retry" name="retry" value="<?= (int)$dados['retry'] ?>">
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="expire">Expire (segundos)</label>
        <input type="number" id="expire" name="expire" value="<?= (int)$dados['expire'] ?>">
      </div>
      <div class="form-group">
        <label for="negative_ttl">Negative TTL (segundos)</label>
        <input type="number" id="negative_ttl" name="negative_ttl" value="<?= (int)$dados['negative_ttl'] ?>">
      </div>
    </div>
    
    <div class="form-group">
      <label class="checkbox-label">
        <input type="checkbox" name="ativo" value="1" <?= !empty($dados['ativo']) ? 'checked' : '' ?>>
        <span>Zona ativa (carregada no Bind9)</span>
      </label>
    </div>
    
    <div class="form-actions">
      <a href="zonas.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">
        <?= $editando ? '💾 Salvar e Recarregar Bind' : '➕ Criar e Recarregar Bind' ?>
      </button>
    </div>
  </form>
</div>

<script>
function togglePrimario() {
  const tipo = document.getElementById('tipo').value;
  document.getElementById('primario-row').style.display = tipo === 'slave' ? 'grid' : 'none';
}
</script>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
