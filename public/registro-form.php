<?php
require_once __DIR__ . '/../app/controllers/ZonaController.php';

$paginaAtual = 'registros';
$id = (int)($_GET['id'] ?? 0);
$zps = (int)($_GET['zona'] ?? 0);
$editando = $id > 0;
$titulo = $editando ? 'Editar Registro' : 'Novo Registro';

$registro = $editando ? ZonaController::buscarRegistro($id) : null;
if ($editando && !$registro) { header('Location: registros.php'); exit; }

$zonas = ZonaController::listar();

if (session_status() === PHP_SESSION_NONE) session_start();
$erros = $_SESSION['form_erros'] ?? [];
$antigos = $_SESSION['form_dados'] ?? [];
unset($_SESSION['form_erros'], $_SESSION['form_dados']);

$d = !empty($antigos) ? $antigos : ($registro ?: [
    'zona_id' => $zps, 'nome' => '', 'tipo' => 'A', 'valor' => '',
    'ttl' => '', 'prioridade' => '',
]);

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<div class="panel" style="max-width: 900px;">
  <div class="panel-header">
    <h2><?= $editando ? 'Editar Registro #' . $id : 'Novo Registro DNS' ?></h2>
    <a href="registros.php<?= $zps ? '?zona=' . $zps : '' ?>" class="btn btn-secondary">← Voltar</a>
  </div>
  
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
  
  <form method="POST" action="registro-action.php" class="form-horizontal" id="form-registro">
    <input type="hidden" name="action" value="<?= $editando ? 'update' : 'create' ?>">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    
    <!-- TIPO (sem PTR) -->
    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label for="tipo">Tipo de Registro *</label>
        <select id="tipo" name="tipo" required onchange="atualizarFormulario()">
          <optgroup label="Endereços">
            <option value="A"     <?= $d['tipo'] === 'A'     ? 'selected' : '' ?>>A — IPv4</option>
            <option value="AAAA"  <?= $d['tipo'] === 'AAAA'  ? 'selected' : '' ?>>AAAA — IPv6</option>
          </optgroup>
          <optgroup label="Apontamentos">
            <option value="CNAME" <?= $d['tipo'] === 'CNAME' ? 'selected' : '' ?>>CNAME — Alias</option>
          </optgroup>
          <optgroup label="E-mail">
            <option value="MX"    <?= $d['tipo'] === 'MX'    ? 'selected' : '' ?>>MX — Servidor de e-mail</option>
            <option value="TXT"   <?= $d['tipo'] === 'TXT'   ? 'selected' : '' ?>>TXT — Texto (SPF, DKIM...)</option>
          </optgroup>
          <optgroup label="Infraestrutura">
            <option value="NS"    <?= $d['tipo'] === 'NS'    ? 'selected' : '' ?>>NS — Servidor de nomes</option>
            <option value="SRV"   <?= $d['tipo'] === 'SRV'   ? 'selected' : '' ?>>SRV — Serviço</option>
          </optgroup>
          <optgroup label="Segurança">
            <option value="CAA"   <?= $d['tipo'] === 'CAA'   ? 'selected' : '' ?>>CAA — Autoridade de certificado</option>
          </optgroup>
        </select>
        <small>
          💡 Para criar <strong>PTR (DNS reverso)</strong>, use o gerenciador de 
          <a href="zonas.php" style="color:#93c5fd; text-decoration:underline;">Zonas Reversas</a>.
        </small>
      </div>
    </div>
    
    <!-- ZONA -->
    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label for="zona_id">Zona *</label>
        <select id="zona_id" name="zona_id" required>
          <option value="">— Selecione —</option>
          <?php foreach ($zonas as $z): 
            // Oculta zonas reversas do dropdown (são gerenciadas em ptr-bloco.php)
            if (preg_match('/\.(in-addr|ip6)\.arpa$/', $z['nome'])) continue;
          ?>
            <option value="<?= $z['id'] ?>" <?= (int)$d['zona_id'] === (int)$z['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($z['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small>Zonas reversas não aparecem aqui — use o gerenciador de PTR.</small>
      </div>
    </div>
    
    <!-- NOME -->
    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label for="nome">Nome *</label>
        <input type="text" id="nome" name="nome" required
               value="<?= htmlspecialchars($d['nome']) ?>"
               placeholder="www, mail, @ (raiz), * (coringa)">
        <small>Use <strong>@</strong> para o domínio raiz, <strong>*</strong> para coringa.</small>
      </div>
    </div>
    
    <!-- TTL -->
    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label for="ttl">TTL (segundos — opcional)</label>
        <input type="number" id="ttl" name="ttl"
               value="<?= htmlspecialchars($d['ttl'] ?? '') ?>"
               placeholder="Deixe vazio para usar o TTL da zona">
      </div>
    </div>
    
    <!-- PRIORIDADE (MX/SRV) -->
    <div class="form-row" id="row-prioridade" style="display:none;">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label for="prioridade">Prioridade *</label>
        <input type="number" id="prioridade" name="prioridade" min="0" max="65535"
               value="<?= htmlspecialchars($d['prioridade'] ?? '') ?>"
               placeholder="10">
        <small>Menor número = maior prioridade.</small>
      </div>
    </div>
    
    <!-- VALOR -->
    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label for="valor">Valor *</label>
        <input type="text" id="valor" name="valor" required
               value="<?= htmlspecialchars($d['valor']) ?>"
               placeholder="Ex: 192.168.1.10">
        <small id="valor-ajuda">Digite o valor do registro.</small>
      </div>
    </div>
    
    <div class="form-actions">
      <a href="registros.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">
        <?= $editando ? '💾 Salvar e Recarregar Bind' : '➕ Criar e Recarregar Bind' ?>
      </button>
    </div>
  </form>
</div>

<script>
const DICAS = {
  'A':     { valor: 'IPv4. Ex: 192.168.1.10',           exemplo: '192.168.1.10' },
  'AAAA':  { valor: 'IPv6. Ex: 2001:db8::1',            exemplo: '2001:db8::1' },
  'CNAME': { valor: 'Hostname destino. Ex: exemplo.com.br', exemplo: 'exemplo.com.br' },
  'MX':    { valor: 'Hostname do servidor. Ex: mail.exemplo.com.br', exemplo: 'mail.exemplo.com.br' },
  'TXT':   { valor: 'Texto. Ex: v=spf1 include:_spf.google.com ~all', exemplo: 'v=spf1 -all' },
  'NS':    { valor: 'Hostname. Ex: ns1.exemplo.com.br',  exemplo: 'ns1.exemplo.com.br' },
  'SRV':   { valor: 'peso porta alvo. Ex: 5 5060 sip.exemplo.com.br', exemplo: '5 5060 sip.exemplo.com.br' },
  'CAA':   { valor: 'flags tag valor. Ex: 0 issue letsencrypt.org', exemplo: '0 issue letsencrypt.org' },
};

function atualizarFormulario() {
  const tipo = document.getElementById('tipo').value;
  const rowPrio = document.getElementById('row-prioridade');
  const valorAjuda = document.getElementById('valor-ajuda');
  const valorInput = document.getElementById('valor');
  
  // Prioridade (só MX e SRV)
  rowPrio.style.display = (tipo === 'MX' || tipo === 'SRV') ? 'grid' : 'none';
  
  // Dicas do valor
  valorAjuda.textContent = DICAS[tipo]?.valor || '';
  valorInput.placeholder = DICAS[tipo]?.exemplo || '';
}

document.addEventListener('DOMContentLoaded', atualizarFormulario);
</script>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
