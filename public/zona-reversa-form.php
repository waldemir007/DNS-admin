<?php
require_once __DIR__ . '/../app/controllers/ZonaController.php';

$paginaAtual = 'zonas';
$id = (int)($_GET['id'] ?? 0);
$editando = $id > 0;
$titulo = $editando ? 'Editar Zona Reversa' : 'Nova Zona Reversa';

$zona = $editando ? ZonaController::buscar($id) : null;
if ($editando && !$zona) {
    $_SESSION['flash'] = ['tipo' => 'error', 'msg' => 'Zona não encontrada.'];
    header('Location: zonas.php');
    exit;
}

// Se for edição, valida se é reversa
if ($editando && !preg_match('/\.(in-addr|ip6)\.arpa$/', $zona['nome'])) {
    $_SESSION['flash'] = ['tipo' => 'error', 'msg' => 'Esta não é uma zona reversa.'];
    header('Location: zona-form.php?id=' . $id);
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
    // Tenta extrair o CIDR do nome da zona
    if (preg_match('/^(\d+)\.(\d+)\.(\d+)\.in-addr\.arpa$/', $zona['nome'], $m)) {
        $dados['cidr'] = $m[3] . '.' . $m[2] . '.' . $m[1] . '.0/24';
    }
} else {
    $dados = [
        'cidr' => '',
        'ptr_padrao' => '',
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
    <h2><?= $editando ? '🔄 Editar Zona Reversa: ' . htmlspecialchars($zona['nome']) : '🔄 Nova Zona Reversa' ?></h2>
    <a href="zonas.php" class="btn btn-secondary">← Voltar</a>
  </div>
  
  <?php if (!$editando): ?>
    <div style="margin-bottom:20px; padding:12px 16px; background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.2); border-radius:8px; font-size:13px; color:#93c5fd;">
      💡 <strong>Zona reversa</strong> — serve para resolver <strong>IP → Hostname</strong> (DNS reverso).
      Ex: <code>45.168.168.2 → servidor.weblinknet.com.br</code>.
      Para criar uma zona normal (domínios), use
      <a href="zona-form.php" style="color:#93c5fd; font-weight:600; text-decoration:underline;">Nova Zona →</a>
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
  
  <form method="POST" action="zona-action.php" class="form-horizontal" id="form-reversa">
    <?php if ($editando): ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="nome" value="<?= htmlspecialchars($zona['nome']) ?>">
    <?php else: ?>
      <input type="hidden" name="action" value="create_reversa">
    <?php endif; ?>
    
    <h3 style="color:#fff;font-size:15px;margin-bottom:16px;border-bottom:1px solid var(--border);padding-bottom:10px;">Bloco IP</h3>
    
    <?php if (!$editando): ?>
    <div class="form-group">
      <label for="cidr">Bloco IPv4 (CIDR) *</label>
      <input type="text" id="cidr" name="cidr" required
             value="<?= htmlspecialchars($dados['cidr'] ?? '') ?>"
             placeholder="45.168.168.0/24"
             style="font-family:monospace;">
      <small>
        Aceita <strong>/8</strong>, <strong>/16</strong> ou <strong>/24</strong>.<br>
        Ex: <code>45.168.168.0/24</code> → cria <code>168.168.45.in-addr.arpa</code>
      </small>
      <div id="cidr-preview" style="margin-top:10px; padding:10px 14px; background:var(--dark); border-radius:8px; font-family:monospace; font-size:12px; color:#6ee7b7; display:none;"></div>
    </div>
    <?php else: ?>
    <div class="form-group">
      <label>Bloco</label>
      <input type="text" value="<?= htmlspecialchars($dados['cidr'] ?? $zona['nome']) ?>" disabled
             style="font-family:monospace; opacity:0.6; cursor:not-allowed;">
      <small>O bloco não pode ser alterado. Exclua a zona e crie outra se precisar mudar.</small>
    </div>
    <?php endif; ?>
    
    <div class="form-group">
      <label for="ptr_padrao">PTR padrão do bloco (opcional)</label>
      <input type="text" id="ptr_padrao" name="ptr_padrao"
             value="<?= htmlspecialchars($dados['ptr_padrao'] ?? '') ?>"
             placeholder="weblinknet.com.br."
             style="font-family:monospace;">
      <small>
        Retornado para <strong>qualquer IP do bloco</strong> sem regra específica.<br>
        Se vazio, IPs sem regra retornarão NXDOMAIN.
      </small>
    </div>
    
    <h3 style="color:#fff;font-size:15px;margin:24px 0 16px;border-bottom:1px solid var(--border);padding-bottom:10px;">Informações Administrativas</h3>
    
    <div class="form-group">
      <label for="admin_email">E-mail do Administrador (formato DNS) *</label>
      <input type="text" id="admin_email" name="admin_email" required
             value="<?= htmlspecialchars($dados['admin_email']) ?>"
             placeholder="admin.exemplo.com.br">
      <small>Use <strong>ponto</strong> em vez de @.</small>
    </div>
    
    <h3 style="color:#fff;font-size:15px;margin:24px 0 16px;border-bottom:1px solid var(--border);padding-bottom:10px;">Parâmetros SOA</h3>
    
    <div class="form-row">
      <div class="form-group">
        <label for="serial">Serial</label>
        <input type="number" id="serial" name="serial" value="<?= (int)$dados['serial'] ?>">
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
        <?= $editando ? '💾 Salvar Zona Reversa' : '🔄 Criar Zona Reversa' ?>
      </button>
    </div>
  </form>
  
  <?php if ($editando): ?>
    <div style="margin-top:20px; padding:16px; background:var(--dark); border-radius:10px; text-align:center;">
      <p style="font-size:13px; color:var(--text-muted); margin-bottom:12px;">
        Para gerenciar os PTRs desta zona (padrão + individuais):
      </p>
      <a href="ptr-bloco.php?id=<?= $id ?>" class="btn btn-primary">
        🔄 Gerenciar PTRs desta Zona
      </a>
    </div>
  <?php endif; ?>
</div>

<script>
document.getElementById('cidr')?.addEventListener('input', function() {
  const preview = document.getElementById('cidr-preview');
  const cidr = this.value.trim();
  
  if (!cidr) { preview.style.display = 'none'; return; }
  
  const partes = cidr.split('/');
  if (partes.length !== 2) { preview.innerHTML = '⚠️ Formato: IP/bits'; preview.style.display = 'block'; return; }
  
  const [ip, bits] = partes;
  const o = ip.split('.');
  if (o.length !== 4 || o.some(x => isNaN(x) || parseInt(x) < 0 || parseInt(x) > 255)) {
    preview.innerHTML = '⚠️ IPv4 inválido';
    preview.style.display = 'block';
    return;
  }
  
  let zona = '';
  if (bits === '24') zona = o[2] + '.' + o[1] + '.' + o[0] + '.in-addr.arpa';
  else if (bits === '16') zona = o[1] + '.' + o[0] + '.in-addr.arpa';
  else if (bits === '8') zona = o[0] + '.in-addr.arpa';
  else { preview.innerHTML = '⚠️ Apenas /8, /16 e /24'; preview.style.display = 'block'; return; }
  
  preview.innerHTML = '✓ Zona reversa que será criada: <strong>' + zona + '</strong>';
  preview.style.display = 'block';
});
</script>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
