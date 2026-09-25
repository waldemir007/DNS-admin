<?php
require_once __DIR__ . '/../app/controllers/ConfigController.php';

$paginaAtual = 'config';
$titulo = 'Configurações do Bind9';

$cfg = ConfigController::todas();
$status = ConfigController::statusBind();
$redes = ConfigController::getRedesPermitidas();
$forwarders = ConfigController::getForwarders();
$usarRoot = ($cfg['usar_root_servers'] ?? '1') === '1';

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<!-- Status do serviço -->
<div class="panel" style="margin-bottom: 20px;">
  <div class="panel-header">
    <h2>Status do Serviço</h2>
  </div>
  <div class="status-list">
    <div class="status-item">
      <span class="status-dot <?= $status['ativo'] ? 'status-online' : 'status-offline' ?>"></span>
      <span>Bind9 (named)</span>
      <span class="status-label" style="color: <?= $status['ativo'] ? '#6ee7b7' : '#fca5a5' ?>;">
        <?= $status['ativo'] ? 'Ativo' : 'Inativo' ?>
      </span>
    </div>
  </div>
</div>

<form method="POST" action="configuracoes-action.php" id="form-config">
  
  <!-- Recursão -->
  <div class="panel" style="margin-bottom: 20px;">
    <div class="panel-header">
      <h2>🌐 Recursão DNS</h2>
    </div>
    
    <div class="form-group">
      <label class="checkbox-label">
        <input type="checkbox" name="recursao_ativa" value="1" 
               <?= ($cfg['recursao_ativa'] ?? '1') === '1' ? 'checked' : '' ?>>
        <span><strong>Ativar recursão</strong> — permite resolver domínios externos (google.com, github.com, etc.)</span>
      </label>
    </div>
    
    <p style="color: var(--text-muted); font-size: 13px; margin-top: 8px;">
      ⚠️ A recursão é restrita aos IPs/redes abaixo. Todo o resto recebe <strong>REFUSED</strong>.
    </p>
  </div>
  
  <!-- Redes permitidas -->
  <div class="panel" style="margin-bottom: 20px;">
    <div class="panel-header">
      <h2>🛡️ Redes e IPs que podem consultar</h2>
    </div>
    
    <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 16px;">
      Adicione IPs ou redes (IPv4 e IPv6). Use <code>/32</code> para IPv4 único, <code>/128</code> para IPv6 único.<br>
      Exemplos: <code>192.168.1.100</code>, <code>10.0.0.0/8</code>, <code>2001:db8::1</code>, <code>fd00::/8</code>
    </p>
    
    <!-- Input de adição -->
    <div class="rede-add-form">
      <input type="text" id="nova-rede" placeholder="Digite um IP ou rede (ex: 192.168.1.100 ou 10.0.0.0/8)" 
             autocomplete="off" spellcheck="false">
      <button type="button" class="btn btn-primary" onclick="adicionarRede()">➕ Adicionar</button>
    </div>
    
    <div id="rede-erro" class="alert alert-error" style="display: none; margin-top: 12px;"></div>
    
    <!-- Lista de redes -->
    <div class="rede-lista" id="rede-lista">
      <?php if (empty($redes)): ?>
        <div class="empty-state" style="padding: 30px;">
          <p style="color: var(--text-muted);">Nenhuma rede cadastrada. Adicione pelo menos uma.</p>
        </div>
      <?php else: ?>
        <?php foreach ($redes as $rede): ?>
          <div class="rede-item" data-rede="<?= htmlspecialchars($rede) ?>">
            <span class="rede-badge <?= strpos($rede, ':') !== false ? 'rede-ipv6' : 'rede-ipv4' ?>">
              <?= strpos($rede, ':') !== false ? 'IPv6' : 'IPv4' ?>
            </span>
            <code class="rede-valor"><?= htmlspecialchars($rede) ?></code>
            <button type="button" class="btn-icon btn-icon-danger" onclick="removerRede(this)" title="Remover">✕</button>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    
    <!-- Sugestões rápidas -->
    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
      <p style="color: var(--text-muted); font-size: 12px; margin-bottom: 8px;">Sugestões rápidas (clique para adicionar):</p>
      <div style="display: flex; flex-wrap: wrap; gap: 6px;">
        <button type="button" class="chip" onclick="sugerir('127.0.0.1/32')">127.0.0.1/32</button>
        <button type="button" class="chip" onclick="sugerir('::1/128')">::1/128</button>
        <button type="button" class="chip" onclick="sugerir('172.16.0.0/16')">172.16.0.0/16</button>
        <button type="button" class="chip" onclick="sugerir('192.168.0.0/16')">192.168.0.0/16</button>
        <button type="button" class="chip" onclick="sugerir('10.0.0.0/8')">10.0.0.0/8</button>
        <button type="button" class="chip" onclick="sugerir('fd00::/8')">fd00::/8 (IPv6 ULA)</button>
        <button type="button" class="chip" onclick="sugerir('fe80::/10')">fe80::/10 (IPv6 link-local)</button>
      </div>
    </div>
    
    <input type="hidden" name="redes_permitidas" id="input-redes" value="<?= htmlspecialchars(implode("\n", $redes)) ?>">
  </div>
  
  <!-- Forwarders -->
  <div class="panel" style="margin-bottom: 20px;">
    <div class="panel-header">
      <h2>➡️ Forwarders (servidores DNS upstream)</h2>
    </div>
    
    <div class="form-group">
      <label class="checkbox-label">
        <input type="checkbox" name="usar_root_servers" value="1" id="usar_root"
               <?= $usarRoot ? 'checked' : '' ?>
               onchange="toggleForwarders()">
        <span><strong>Usar servidores root</strong> (recursão direta, sem intermediários)</span>
      </label>
    </div>
    
    <div id="forwarders-box" style="display: <?= $usarRoot ? 'none' : 'block' ?>;">
      <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 16px;">
        Adicione servidores DNS (IPv4 e IPv6). O Bind vai usá-los para resolver domínios externos.
      </p>
      
      <div class="rede-add-form">
        <input type="text" id="novo-forwarder" placeholder="Digite um IP (ex: 8.8.8.8 ou 2001:4860:4860::8888)" 
               autocomplete="off" spellcheck="false">
        <button type="button" class="btn btn-primary" onclick="adicionarForwarder()">➕ Adicionar</button>
      </div>
      
      <div id="fw-erro" class="alert alert-error" style="display: none; margin-top: 12px;"></div>
      
      <div class="rede-lista" id="fw-lista">
        <?php if (empty($forwarders)): ?>
          <div class="empty-state" style="padding: 30px;">
            <p style="color: var(--text-muted);">Nenhum forwarder cadastrado.</p>
          </div>
        <?php else: ?>
          <?php foreach ($forwarders as $fw): ?>
            <div class="rede-item" data-rede="<?= htmlspecialchars($fw) ?>">
              <span class="rede-badge <?= strpos($fw, ':') !== false ? 'rede-ipv6' : 'rede-ipv4' ?>">
                <?= strpos($fw, ':') !== false ? 'IPv6' : 'IPv4' ?>
              </span>
              <code class="rede-valor"><?= htmlspecialchars($fw) ?></code>
              <button type="button" class="btn-icon btn-icon-danger" onclick="removerForwarder(this)" title="Remover">✕</button>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      
      <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
        <p style="color: var(--text-muted); font-size: 12px; margin-bottom: 8px;">Sugestões rápidas:</p>
        <div style="display: flex; flex-wrap: wrap; gap: 6px;">
          <button type="button" class="chip" onclick="sugerirFw('8.8.8.8')">8.8.8.8 (Google v4)</button>
          <button type="button" class="chip" onclick="sugerirFw('8.8.4.4')">8.8.4.4 (Google v4)</button>
          <button type="button" class="chip" onclick="sugerirFw('2001:4860:4860::8888')">Google IPv6</button>
          <button type="button" class="chip" onclick="sugerirFw('1.1.1.1')">1.1.1.1 (Cloudflare v4)</button>
          <button type="button" class="chip" onclick="sugerirFw('1.0.0.1')">1.0.0.1 (Cloudflare v4)</button>
          <button type="button" class="chip" onclick="sugerirFw('2606:4700:4700::1111')">Cloudflare IPv6</button>
          <button type="button" class="chip" onclick="sugerirFw('9.9.9.9')">9.9.9.9 (Quad9 v4)</button>
          <button type="button" class="chip" onclick="sugerirFw('2620:fe::fe')">Quad9 IPv6</button>
        </div>
      </div>
      
      <input type="hidden" name="forwarders" id="input-forwarders" value="<?= htmlspecialchars(implode("\n", $forwarders)) ?>">
    </div>
  </div>
  
  <!-- Avançado -->
  <div class="panel" style="margin-bottom: 20px;">
    <div class="panel-header">
      <h2>⚙️ Configurações avançadas</h2>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="dnssec_validation">Validação DNSSEC</label>
        <select id="dnssec_validation" name="dnssec_validation">
          <option value="auto"  <?= ($cfg['dnssec_validation'] ?? '') === 'auto'  ? 'selected' : '' ?>>Auto (recomendado)</option>
          <option value="yes"   <?= ($cfg['dnssec_validation'] ?? '') === 'yes'   ? 'selected' : '' ?>>Sim (obrigatório)</option>
          <option value="no"    <?= ($cfg['dnssec_validation'] ?? '') === 'no'    ? 'selected' : '' ?>>Não (desabilitado)</option>
        </select>
      </div>
      
      <div class="form-group">
        <label for="max_cache_size">Tamanho máximo do cache (MB)</label>
        <input type="number" id="max_cache_size" name="max_cache_size" 
               value="<?= (int)($cfg['max_cache_size'] ?? 64) ?>" min="8" max="4096">
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="max_cache_ttl">TTL máximo do cache (segundos)</label>
        <input type="number" id="max_cache_ttl" name="max_cache_ttl"
               value="<?= (int)($cfg['max_cache_ttl'] ?? 86400) ?>">
      </div>
      
      <div class="form-group">
        <label class="checkbox-label">
          <input type="checkbox" name="query_log" value="1"
                 <?= ($cfg['query_log'] ?? '0') === '1' ? 'checked' : '' ?>>
          <span>Registrar log de consultas (<code>querylog</code>)</span>
        </label>
      </div>
    </div>
  </div>
  
  <div class="form-actions">
    <button type="submit" class="btn btn-primary">💾 Salvar e Recarregar Bind9</button>
  </div>
  
</form>

<script>
// ============ VALIDAÇÃO DE IP/REDE (v4 e v6) ============
function validarRede(cidr) {
  if (!cidr || cidr.trim() === '') return { ok: false, erro: 'Valor vazio.' };
  cidr = cidr.trim();
  
  // Divide em IP e máscara
  let ip = cidr, mask = null;
  if (cidr.includes('/')) {
    const partes = cidr.split('/');
    if (partes.length !== 2) return { ok: false, erro: 'Formato inválido. Use IP ou IP/máscara.' };
    ip = partes[0];
    mask = parseInt(partes[1], 10);
    if (isNaN(mask)) return { ok: false, erro: 'Máscara deve ser um número.' };
  }
  
  // IPv4
  const ipv4Regex = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;
  if (ipv4Regex.test(ip)) {
    const octetos = ip.split('.').map(Number);
    if (octetos.some(o => o < 0 || o > 255)) return { ok: false, erro: 'IPv4 inválido: octeto fora de 0-255.' };
    if (mask !== null && (mask < 0 || mask > 32)) return { ok: false, erro: 'Máscara IPv4 deve ser 0-32.' };
    return { ok: true };
  }
  
  // IPv6 (validação básica)
  if (ip.includes(':')) {
    // Verifica caracteres válidos
    if (!/^[0-9a-fA-F:]+$/.test(ip)) return { ok: false, erro: 'IPv6 contém caracteres inválidos.' };
    // Deve ter pelo menos um :
    if (!ip.includes(':')) return { ok: false, erro: 'IPv6 inválido.' };
    // Máximo 8 grupos
    const grupos = ip.split(':').filter(g => g !== '');
    if (grupos.length > 8) return { ok: false, erro: 'IPv6 com grupos demais.' };
    // Grupos devem ter no máximo 4 dígitos
    if (grupos.some(g => g.length > 4)) return { ok: false, erro: 'IPv6 com grupo maior que 4 dígitos.' };
    if (mask !== null && (mask < 0 || mask > 128)) return { ok: false, erro: 'Máscara IPv6 deve ser 0-128.' };
    return { ok: true };
  }
  
  return { ok: false, erro: 'Não é um IPv4 nem IPv6 válido.' };
}

function isIPv6(str) {
  return str.includes(':');
}

// ============ REDES PERMITIDAS ============
function adicionarRede() {
  const input = document.getElementById('nova-rede');
  const erro = document.getElementById('rede-erro');
  const valor = input.value.trim();
  
  erro.style.display = 'none';
  
  if (!valor) return;
  
  const v = validarRede(valor);
  if (!v.ok) {
    erro.textContent = '❌ ' + v.erro;
    erro.style.display = 'flex';
    return;
  }
  
  // Verifica duplicidade
  const existentes = getRedesArray();
  if (existentes.includes(valor)) {
    erro.textContent = '❌ Este IP/rede já está na lista.';
    erro.style.display = 'flex';
    return;
  }
  
  // Adiciona na lista visual
  const lista = document.getElementById('rede-lista');
  // Remove o empty-state se existir
  const empty = lista.querySelector('.empty-state');
  if (empty) empty.remove();
  
  const div = document.createElement('div');
  div.className = 'rede-item';
  div.dataset.rede = valor;
  div.innerHTML = `
    <span class="rede-badge ${isIPv6(valor) ? 'rede-ipv6' : 'rede-ipv4'}">
      ${isIPv6(valor) ? 'IPv6' : 'IPv4'}
    </span>
    <code class="rede-valor">${escapeHtml(valor)}</code>
    <button type="button" class="btn-icon btn-icon-danger" onclick="removerRede(this)" title="Remover">✕</button>
  `;
  lista.appendChild(div);
  
  // Atualiza input hidden
  atualizarInputRedes();
  
  input.value = '';
  input.focus();
}

function removerRede(btn) {
  btn.closest('.rede-item').remove();
  atualizarInputRedes();
  verificarListaVazia('rede-lista');
}

function getRedesArray() {
  return Array.from(document.querySelectorAll('#rede-lista .rede-item'))
    .map(el => el.dataset.rede);
}

function atualizarInputRedes() {
  document.getElementById('input-redes').value = getRedesArray().join('\n');
}

function sugerir(valor) {
  document.getElementById('nova-rede').value = valor;
  adicionarRede();
}

// ============ FORWARDERS ============
function adicionarForwarder() {
  const input = document.getElementById('novo-forwarder');
  const erro = document.getElementById('fw-erro');
  const valor = input.value.trim();
  
  erro.style.display = 'none';
  
  if (!valor) return;
  
  // Forwarder deve ser IP puro (sem máscara)
  const v = validarRede(valor);
  if (!v.ok) {
    erro.textContent = '❌ ' + v.erro;
    erro.style.display = 'flex';
    return;
  }
  
  if (valor.includes('/')) {
    erro.textContent = '❌ Forwarder deve ser um IP único (sem máscara).';
    erro.style.display = 'flex';
    return;
  }
  
  const existentes = getForwardersArray();
  if (existentes.includes(valor)) {
    erro.textContent = '❌ Este forwarder já está na lista.';
    erro.style.display = 'flex';
    return;
  }
  
  const lista = document.getElementById('fw-lista');
  const empty = lista.querySelector('.empty-state');
  if (empty) empty.remove();
  
  const div = document.createElement('div');
  div.className = 'rede-item';
  div.dataset.rede = valor;
  div.innerHTML = `
    <span class="rede-badge ${isIPv6(valor) ? 'rede-ipv6' : 'rede-ipv4'}">
      ${isIPv6(valor) ? 'IPv6' : 'IPv4'}
    </span>
    <code class="rede-valor">${escapeHtml(valor)}</code>
    <button type="button" class="btn-icon btn-icon-danger" onclick="removerForwarder(this)" title="Remover">✕</button>
  `;
  lista.appendChild(div);
  
  atualizarInputForwarders();
  
  input.value = '';
  input.focus();
}

function removerForwarder(btn) {
  btn.closest('.rede-item').remove();
  atualizarInputForwarders();
  verificarListaVazia('fw-lista');
}

function getForwardersArray() {
  return Array.from(document.querySelectorAll('#fw-lista .rede-item'))
    .map(el => el.dataset.rede);
}

function atualizarInputForwarders() {
  document.getElementById('input-forwarders').value = getForwardersArray().join('\n');
}

function sugerirFw(valor) {
  document.getElementById('novo-forwarder').value = valor;
  adicionarForwarder();
}

// ============ UTILITÁRIOS ============
function escapeHtml(str) {
  return str.replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

function verificarListaVazia(idLista) {
  const lista = document.getElementById(idLista);
  if (lista.querySelectorAll('.rede-item').length === 0) {
    lista.innerHTML = '<div class="empty-state" style="padding: 30px;"><p style="color: var(--text-muted);">Nenhum item cadastrado.</p></div>';
  }
}

function toggleForwarders() {
  const usarRoot = document.getElementById('usar_root').checked;
  document.getElementById('forwarders-box').style.display = usarRoot ? 'none' : 'block';
}

// ============ ENTER para adicionar ============
document.getElementById('nova-rede').addEventListener('keypress', e => {
  if (e.key === 'Enter') { e.preventDefault(); adicionarRede(); }
});
document.getElementById('novo-forwarder').addEventListener('keypress', e => {
  if (e.key === 'Enter') { e.preventDefault(); adicionarForwarder(); }
});
</script>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
