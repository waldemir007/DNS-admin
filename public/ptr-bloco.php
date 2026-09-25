<?php
require_once __DIR__ . '/../app/controllers/ZonaController.php';
require_once __DIR__ . '/../app/controllers/PTRController.php';
require_once __DIR__ . '/../app/controllers/PTRHelper.php';

$paginaAtual = 'zonas';
$titulo = 'Gerenciar PTR';

$zonaId = (int)($_GET['id'] ?? 0);
$zona = $zonaId ? ZonaController::buscar($zonaId) : null;

if (!$zona) {
    $_SESSION['flash'] = ['tipo' => 'error', 'msg' => 'Zona não encontrada.'];
    header('Location: zonas.php');
    exit;
}

if (!PTRHelper::isZonaReversa($zona['nome'])) {
    $_SESSION['flash'] = ['tipo' => 'error', 'msg' => 'Esta zona não é reversa.'];
    header('Location: zonas.php');
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'adicionar') {
        $r = PTRController::adicionar(
            $zonaId, 
            $_POST['cidr'] ?? '', 
            $_POST['hostname'] ?? '',
            !empty($_POST['prefixar_ip'])
        );
        if ($r['ok']) {
            ZonaController::aplicarConfiguracao();
            $_SESSION['flash'] = ['tipo' => 'success', 'msg' => 'Regra adicionada!'];
        } else {
            $_SESSION['flash'] = ['tipo' => 'error', 'msg' => $r['erro']];
        }
    } elseif ($action === 'remover') {
        $r = PTRController::remover((int)($_POST['id'] ?? 0));
        if ($r['ok']) {
            ZonaController::aplicarConfiguracao();
            $_SESSION['flash'] = ['tipo' => 'success', 'msg' => 'Regra removida!'];
        } else {
            $_SESSION['flash'] = ['tipo' => 'error', 'msg' => $r['erro']];
        }
    } elseif ($action === 'editar') {
        $r = PTRController::atualizar(
            (int)($_POST['id'] ?? 0), 
            $_POST['hostname'] ?? '',
            !empty($_POST['prefixar_ip'])
        );
        if ($r['ok']) {
            ZonaController::aplicarConfiguracao();
            $_SESSION['flash'] = ['tipo' => 'success', 'msg' => 'Hostname atualizado!'];
        } else {
            $_SESSION['flash'] = ['tipo' => 'error', 'msg' => $r['erro']];
        }
    }
    
    header('Location: ptr-bloco.php?id=' . $zonaId);
    exit;
}

$regras = PTRController::listar($zonaId);
$blocoPai = PTRHelper::blocoPai($zona['nome']);
$stats = PTRController::estatisticas($zonaId);

// Identifica bloco principal (menor bits)
$blocoPrincipal = null;
$excecoes = [];
foreach ($regras as $r) {
    if ($r['bits'] < 32) {
        if (!$blocoPrincipal || $r['bits'] < $blocoPrincipal['bits']) {
            $blocoPrincipal = $r;
        }
    }
}
foreach ($regras as $r) {
    if ($blocoPrincipal && $r['id'] == $blocoPrincipal['id']) continue;
    $excecoes[] = $r;
}

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header">
    <div>
      <h2>🔄 <?= htmlspecialchars($zona['nome']) ?></h2>
      <small style="color:var(--text-muted); font-size:13px;">
        Bloco: <code style="color:#93c5fd;"><?= htmlspecialchars($blocoPai ?: '?') ?></code>
      </small>
    </div>
    <a href="zonas.php" class="btn btn-secondary">← Voltar</a>
  </div>
</div>

<section class="stats-grid" style="grid-template-columns:repeat(4,1fr); margin-bottom:20px;">
  <div class="stat-card"><div class="stat-icon">📋</div><div class="stat-info"><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-label">Regras</div></div></div>
  <div class="stat-card"><div class="stat-icon">🌐</div><div class="stat-info"><div class="stat-value"><?= (int)$stats['blocos_grandes'] ?></div><div class="stat-label">Blocos grandes</div></div></div>
  <div class="stat-card"><div class="stat-icon">📦</div><div class="stat-info"><div class="stat-value"><?= (int)$stats['subblocos'] ?></div><div class="stat-label">Subblocos</div></div></div>
  <div class="stat-card"><div class="stat-icon">📍</div><div class="stat-info"><div class="stat-value"><?= (int)$stats['individuais'] ?></div><div class="stat-label">Individuais</div></div></div>
</section>

<!-- Bloco principal -->
<?php if ($blocoPrincipal): ?>
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header">
    <h2>🔷 Bloco Principal</h2>
    <small style="color:var(--text-muted); font-size:12px;">Padrão para IPs sem regra específica</small>
  </div>
  
  <div style="padding:16px; background:var(--dark); border-radius:10px; border-left:4px solid var(--primary);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <div>
        <span class="badge badge-primary" style="font-size:13px; padding:5px 12px; font-family:monospace;">
          <?= htmlspecialchars($blocoPrincipal['cidr']) ?>
        </span>
        <span style="margin-left:12px; font-family:monospace; font-size:14px; color:#93c5fd;">
          → <?= htmlspecialchars($blocoPrincipal['hostname']) ?>
        </span>
        <?php if (!empty($blocoPrincipal['prefixar_ip'])): ?>
          <span class="badge badge-success" style="margin-left:8px; font-size:11px;">
            🌐 Com prefixo de IP
          </span>
        <?php endif; ?>
      </div>
      <div style="display:flex; gap:8px;">
        <button type="button" class="btn-icon" title="Editar" 
                onclick="editarRegra(<?= $blocoPrincipal['id'] ?>, '<?= htmlspecialchars($blocoPrincipal['hostname'], ENT_QUOTES) ?>', <?= !empty($blocoPrincipal['prefixar_ip']) ? 'true' : 'false' ?>)">
          ✏️
        </button>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Remover este bloco?');">
          <input type="hidden" name="action" value="remover">
          <input type="hidden" name="id" value="<?= $blocoPrincipal['id'] ?>">
          <button type="submit" class="btn-icon btn-icon-danger">🗑️</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Adicionar regra -->
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header">
    <h2>➕ Adicionar Regra</h2>
  </div>
  
  <form method="POST" class="form-horizontal">
    <input type="hidden" name="action" value="adicionar">
    
    <!-- CIDR -->
    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label for="cidr">IP ou Bloco (CIDR) *</label>
        <input type="text" id="cidr" name="cidr" required
               placeholder="exemplo: 45.168.168.10 ou 45.168.168.0/28"
               style="font-family:monospace;">
        <small>
          Aceita <strong>IP único</strong> (<code>45.168.168.10</code> ou <code>45.168.168.10/32</code>)<br>
          ou <strong>bloco</strong> (<code>/24</code> a <code>/31</code>).
        </small>
        <div id="cidr-info" style="margin-top:8px; font-size:12px; color:#6ee7b7; display:none;"></div>
      </div>
    </div>
    
    <!-- Hostname + Checkbox lado a lado -->
    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label for="hostname">Hostname destino *</label>
        <div style="display:flex; gap:10px; align-items:center;">
          
          <!-- Checkbox (à esquerda) -->
          <label class="checkbox-label" 
                 title="Adiciona o IP (formato reverso) na frente do hostname"
                 style="flex:0 0 auto; padding:12px 14px; background:rgba(59,130,246,0.08); border-color:rgba(59,130,246,0.25); margin:0; cursor:pointer;">
            <input type="checkbox" name="prefixar_ip" value="1" id="prefixar_ip" checked>
            <span style="white-space:nowrap; font-size:13px;">🌐 Prefixar IP</span>
          </label>
          
          <!-- Campo hostname (à direita) -->
          <input type="text" id="hostname" name="hostname" required
                 placeholder="exemplo.com.br."
                 style="font-family:monospace; flex:1;">
        </div>
        <small>Hostname que será retornado (com ponto final).</small>
        <div id="prefixo-preview" style="display:none; margin-top:10px; padding:10px 14px; background:var(--dark); border-radius:8px; font-family:monospace; font-size:12px; color:#6ee7b7;"></div>
      </div>
    </div>
    
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">➕ Adicionar</button>
    </div>
  </form>
</div>

<!-- Exceções e subblocos -->
<div class="panel">
  <div class="panel-header">
    <h2>📋 Exceções e Subblocos</h2>
    <small style="color:var(--text-muted); font-size:12px;">
      Prioridade: menor bloco vence
    </small>
  </div>
  
  <?php if (empty($excecoes)): ?>
    <div class="empty-state" style="padding:40px;">
      <div class="empty-icon">📋</div>
      <p>Nenhuma exceção ou subbloco cadastrado.</p>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>Tipo</th>
            <th>CIDR / IP</th>
            <th>Range</th>
            <th>Hostname</th>
            <th>Prefixo</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($excecoes as $r): 
            $isIndividual = (int)$r['bits'] === 32;
            $totalIps = $r['fim'] - $r['inicio'] + 1;
          ?>
            <tr>
              <td>
                <?php if ($isIndividual): ?>
                  <span class="badge badge-primary">📍 Individual</span>
                <?php else: ?>
                  <span class="badge badge-success">📦 <?= PTRHelper::labelTipo($r['bits']) ?></span>
                <?php endif; ?>
              </td>
              <td style="font-family:monospace; font-size:13px;"><?= htmlspecialchars($r['cidr']) ?></td>
              <td>
                <small style="color:var(--text-muted); font-family:monospace; font-size:11px;">
                  <?= long2ip((int)$r['inicio']) ?>
                  <?php if ($totalIps > 1): ?>
                    → <?= long2ip((int)$r['fim']) ?>
                    <span class="badge badge-muted" style="font-size:10px;"><?= $totalIps ?> IPs</span>
                  <?php endif; ?>
                </small>
              </td>
              <td style="font-family:monospace; font-size:13px; color:#93c5fd;">
                <?= htmlspecialchars($r['hostname']) ?>
              </td>
              <td>
                <?php if (!empty($r['prefixar_ip'])): ?>
                  <span class="badge badge-success" title="Adiciona o IP na frente">🌐 Sim</span>
                <?php else: ?>
                  <span class="badge badge-muted">☐ Não</span>
                <?php endif; ?>
              </td>
              <td class="text-right">
                <div class="action-buttons">
                  <button type="button" class="btn-icon" title="Editar"
                          onclick="editarRegra(<?= $r['id'] ?>, '<?= htmlspecialchars($r['hostname'], ENT_QUOTES) ?>', <?= !empty($r['prefixar_ip']) ? 'true' : 'false' ?>)">
                    ✏️
                  </button>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Remover esta regra?');">
                    <input type="hidden" name="action" value="remover">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn-icon btn-icon-danger">🗑️</button>
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

<!-- Preview -->
<div class="panel" style="margin-top:20px;">
  <div class="panel-header">
    <h2>📄 Preview do arquivo de zona</h2>
    <small style="color:var(--text-muted); font-size:12px;">
      Hierarquia resolvida: cada IP aparece uma vez
    </small>
  </div>
  <pre style="background:var(--dark);border:1px solid var(--border);border-radius:8px;padding:18px;overflow-x:auto;font-family:'Courier New',monospace;font-size:13px;line-height:1.6;color:#e2e8f0;margin:0;max-height:500px;"><?php
    $linhas = PTRController::gerarLinhasZona($zonaId);
    echo htmlspecialchars(implode("\n", array_slice($linhas, 0, 50)));
    if (count($linhas) > 50) echo "\n... (" . (count($linhas) - 50) . " linhas adicionais)";
    if (empty($linhas)) echo '(sem regras)';
  ?></pre>
</div>

<script>
function atualizarPreview() {
  const cidr = document.getElementById('cidr').value.trim();
  const hostname = document.getElementById('hostname').value.trim();
  const prefixar = document.getElementById('prefixar_ip').checked;
  const info = document.getElementById('cidr-info');
  const preview = document.getElementById('prefixo-preview');
  
  // Info do CIDR
  if (!cidr) {
    info.style.display = 'none';
  } else if (cidr.includes('/')) {
    const partes = cidr.split('/');
    const bits = parseInt(partes[1]);
    if (bits >= 24 && bits <= 31) {
      const total = Math.pow(2, 32 - bits);
      info.innerHTML = '✓ Bloco /' + bits + ' com <strong>' + total + ' IPs</strong>';
      info.style.display = 'block';
    } else if (bits === 32) {
      info.innerHTML = '✓ IP individual (/32)';
      info.style.display = 'block';
    } else {
      info.innerHTML = '⚠️ Use /24 a /32';
      info.style.display = 'block';
    }
  } else {
    info.innerHTML = '✓ IP individual (/32)';
    info.style.display = 'block';
  }
  
  // Preview do hostname final
  if (hostname) {
    // Pega IP exemplo
    let ipExemplo = '45.168.168.10';
    if (cidr) {
      const ip = cidr.split('/')[0];
      const o = ip.split('.');
      if (o.length === 4) {
        ipExemplo = o[0] + '.' + o[1] + '.' + o[2] + '.10';
      }
    }
    
    if (prefixar) {
      const o = ipExemplo.split('.');
      const ipInvertido = o[3] + '.' + o[2] + '.' + o[1];
      preview.innerHTML = '✓ Resultado: <strong>' + ipInvertido + '.' + hostname.replace(/\.+$/, '') + '.</strong>';
    } else {
      preview.innerHTML = '✓ Resultado: <strong>' + hostname.replace(/\.+$/, '') + '.</strong>';
    }
    preview.style.display = 'block';
  } else {
    preview.style.display = 'none';
  }
}

function editarRegra(id, hostname, prefixar) {
  const novoHostname = prompt('Hostname:', hostname);
  if (novoHostname === null || novoHostname.trim() === '') return;
  
  const novoPrefixar = confirm(
    'Prefixo de IP?\n\n' +
    'OK = Com prefixo (N.168.168.45.' + novoHostname + ')\n' +
    'Cancelar = Sem prefixo (' + novoHostname + ')'
  );
  
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <input type="hidden" name="action" value="editar">
    <input type="hidden" name="id" value="${id}">
    <input type="hidden" name="hostname" value="${novoHostname.trim().replace(/"/g, '&quot;')}">
    ${novoPrefixar ? '<input type="hidden" name="prefixar_ip" value="1">' : ''}
  `;
  document.body.appendChild(form);
  form.submit();
}

// Inicialização
document.addEventListener('DOMContentLoaded', function() {
  // Força o checkbox a ficar marcado
  const cb = document.getElementById('prefixar_ip');
  cb.checked = true;
  
  // Listeners
  document.getElementById('cidr').addEventListener('input', atualizarPreview);
  document.getElementById('hostname').addEventListener('input', atualizarPreview);
  cb.addEventListener('change', atualizarPreview);
  
  // Preview inicial
  atualizarPreview();
});
</script>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
