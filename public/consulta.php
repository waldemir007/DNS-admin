<?php
require_once __DIR__ . '/../app/controllers/Auth.php';

$paginaAtual = 'consulta';
$titulo = 'Consulta DNS';

$resultado = null;
$dominio = '';
$tipo = 'A';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dominio = trim($_POST['dominio'] ?? '');
    $tipo = strtoupper(trim($_POST['tipo'] ?? 'A'));
    
    if ($dominio !== '') {
        $resultado = consultarDns($dominio, $tipo);
    }
}

/**
 * Executa uma consulta DNS usando o `dig` no Bind local.
 */
function consultarDns($dominio, $tipo = 'A') {
    $dominio = trim($dominio);
    $tipo = strtoupper(trim($tipo));
    
    // Validação básica (evita command injection)
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9_-]*[a-zA-Z0-9])?)*\.?$/', $dominio)) {
        return ['erro' => 'Domínio inválido. Use apenas letras, números, pontos e hífens.'];
    }
    
    $tiposValidos = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA', 'SRV', 'PTR', 'CAA', 'ANY'];
    if (!in_array($tipo, $tiposValidos, true)) {
        return ['erro' => 'Tipo de registro inválido.'];
    }
    
    // Executa dig
    $cmd = 'dig @127.0.0.1 ' . escapeshellarg($dominio) . ' ' . escapeshellarg($tipo) . ' +noall +answer +authority +comments 2>&1';
    $saida = shell_exec($cmd);
    
    // Extrai o status
    $cmdStatus = 'dig @127.0.0.1 ' . escapeshellarg($dominio) . ' ' . escapeshellarg($tipo) . ' +noall +comments 2>&1 | grep "status:"';
    $status = trim(shell_exec($cmdStatus) ?? '');
    
    // Tempo de resposta
    $cmdTime = 'dig @127.0.0.1 ' . escapeshellarg($dominio) . ' ' . escapeshellarg($tipo) . ' +noall +stats 2>&1 | grep "Query time:"';
    $tempo = trim(shell_exec($cmdTime) ?? '');
    
    if (!$saida) {
        return ['erro' => 'Sem resposta do servidor DNS. Verifique se o Bind9 está rodando.'];
    }
    
    return [
        'ok' => true,
        'dominio' => $dominio,
        'tipo' => $tipo,
        'saida' => $saida,
        'status' => $status,
        'tempo' => $tempo,
    ];
}

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<div class="panel" style="max-width: 900px;">
  <div class="panel-header">
    <h2>🔍 Consulta DNS</h2>
  </div>
  
  <p style="color: var(--text-muted); margin-bottom: 20px;">
    Digite um domínio para consultar diretamente no Bind9 local (127.0.0.1).
  </p>
  
  <form method="POST" class="form-horizontal">
    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label for="dominio">Domínio</label>
        <input type="text" id="dominio" name="dominio" required
               placeholder="exemplo.com.br"
               value="<?= htmlspecialchars($dominio) ?>"
               autofocus
               style="font-family:monospace;">
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="tipo">Tipo de Registro</label>
        <select id="tipo" name="tipo">
          <?php foreach (['A','AAAA','CNAME','MX','TXT','NS','SOA','SRV','PTR','CAA','ANY'] as $t): ?>
            <option value="<?= $t ?>" <?= $tipo === $t ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="display:flex; align-items:flex-end;">
        <button type="submit" class="btn btn-primary" style="width:100%;">🔍 Consultar</button>
      </div>
    </div>
  </form>
</div>

<?php if ($resultado): ?>
  <?php if (!empty($resultado['erro'])): ?>
    <div class="panel" style="margin-top:20px;">
      <div class="alert alert-error" style="display:block;">
        ❌ <?= htmlspecialchars($resultado['erro']) ?>
      </div>
    </div>
  <?php else: ?>
    <div class="panel" style="margin-top:20px;">
      <div class="panel-header">
        <h2>
          Resultado:
          <code style="color:#93c5fd;"><?= htmlspecialchars($resultado['dominio']) ?></code>
          — 
          <span class="badge badge-primary"><?= htmlspecialchars($resultado['tipo']) ?></span>
        </h2>
      </div>
      
      <?php if (!empty($resultado['status'])): ?>
        <div style="padding:10px 14px; background:var(--dark); border-radius:8px; margin-bottom:14px; font-family:monospace; font-size:13px; color:var(--text-muted);">
          <?= htmlspecialchars($resultado['status']) ?>
          <?php if (!empty($resultado['tempo'])): ?>
            · <?= htmlspecialchars($resultado['tempo']) ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      
      <pre style="
        background: var(--dark);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 18px;
        overflow-x: auto;
        font-family: 'Courier New', monospace;
        font-size: 13px;
        line-height: 1.6;
        color: #e2e8f0;
        margin: 0;
        white-space: pre-wrap;
      "><?= htmlspecialchars($resultado['saida']) ?></pre>
    </div>
  <?php endif; ?>
<?php endif; ?>

<!-- Exemplos rápidos -->
<div class="panel" style="margin-top:20px;">
  <div class="panel-header">
    <h2>💡 Exemplos rápidos</h2>
  </div>
  <p style="color:var(--text-muted); font-size:13px; margin-bottom:12px;">
    Clique em um exemplo para preencher e consultar:
  </p>
  <div style="display:flex; flex-wrap:wrap; gap:8px;">
    <?php
    $exemplos = [
        ['google.com', 'A'],
        ['gmail.com', 'MX'],
        ['cloudflare.com', 'NS'],
        ['github.com', 'TXT'],
        ['1.1.1.1', 'PTR'],
    ];
    foreach ($exemplos as [$dom, $tp]):
    ?>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="dominio" value="<?= htmlspecialchars($dom) ?>">
        <input type="hidden" name="tipo" value="<?= htmlspecialchars($tp) ?>">
        <button class="btn btn-secondary" style="font-size:13px;">
          <?= htmlspecialchars($dom) ?> <span style="opacity:0.6;">(<?= $tp ?>)</span>
        </button>
      </form>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
