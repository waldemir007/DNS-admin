<?php
require_once __DIR__ . '/../app/controllers/UsuarioController.php';

$paginaAtual = 'usuarios';
$id = (int)($_GET['id'] ?? 0);
$editando = $id > 0;
$titulo = $editando ? 'Editar Usuário' : 'Novo Usuário';

$usuario = $editando ? UsuarioController::buscar($id) : null;

if ($editando && !$usuario) {
    $_SESSION['flash'] = ['tipo' => 'error', 'msg' => 'Usuário não encontrado.'];
    header('Location: usuarios.php');
    exit;
}

// Recuperar dados de erro da sessão
if (session_status() === PHP_SESSION_NONE) session_start();
$erros = $_SESSION['form_erros'] ?? [];
$dadosAntigos = $_SESSION['form_dados'] ?? [];
unset($_SESSION['form_erros'], $_SESSION['form_dados']);

// Mesclar: prioridade para dados antigos (em caso de erro), senão para o usuário do banco
if (!empty($dadosAntigos)) {
    $dados = $dadosAntigos;
} elseif ($usuario) {
    $dados = $usuario;
} else {
    $dados = ['username' => '', 'nome' => '', 'email' => '', 'nivel' => 'user', 'ativo' => 1];
}

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<div class="panel" style="max-width: 720px;">
  <div class="panel-header">
    <h2><?= $editando ? 'Editar Usuário #' . $id : 'Novo Usuário' ?></h2>
    <a href="usuarios.php" class="btn btn-secondary">← Voltar</a>
  </div>
  
  <?php if (!empty($erros)): ?>
    <div class="alert alert-error" style="display: block;">
      <strong>Corrija os erros abaixo:</strong>
      <ul style="margin: 8px 0 0 20px;">
        <?php foreach ($erros as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  
  <form method="POST" action="usuario-action.php" class="form-horizontal">
    <input type="hidden" name="action" value="<?= $editando ? 'update' : 'create' ?>">
    <?php if ($editando): ?>
      <input type="hidden" name="id" value="<?= $id ?>">
    <?php endif; ?>
    
    <div class="form-row">
      <div class="form-group">
        <label for="username">Usuário *</label>
        <input type="text" id="username" name="username" required
               value="<?= htmlspecialchars($dados['username']) ?>"
               pattern="[a-zA-Z0-9._-]{3,50}"
               title="3-50 caracteres (letras, números, . _ -)">
        <small>Letras, números, ponto, underline e hífen.</small>
      </div>
      
      <div class="form-group">
        <label for="nome">Nome Completo *</label>
        <input type="text" id="nome" name="nome" required
               value="<?= htmlspecialchars($dados['nome']) ?>">
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email"
               value="<?= htmlspecialchars($dados['email'] ?? '') ?>">
      </div>
      
      <div class="form-group">
        <label for="nivel">Nível de Acesso</label>
        <select id="nivel" name="nivel">
          <option value="user" <?= $dados['nivel'] === 'user' ? 'selected' : '' ?>>👤 Usuário</option>
          <option value="admin" <?= $dados['nivel'] === 'admin' ? 'selected' : '' ?>>🔑 Administrador</option>
        </select>
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label for="password">
          Senha <?= $editando ? '(deixe em branco para não alterar)' : '*' ?>
        </label>
        <input type="password" id="password" name="password"
               <?= $editando ? '' : 'required' ?>
               minlength="6" autocomplete="new-password">
        <small>Mínimo 6 caracteres.</small>
      </div>
      
      <div class="form-group">
        <label for="password2">Confirmar Senha</label>
        <input type="password" id="password2" name="password2"
               <?= $editando ? '' : 'required' ?>
               minlength="6" autocomplete="new-password">
      </div>
    </div>
    
    <div class="form-group">
      <label class="checkbox-label">
        <input type="checkbox" name="ativo" value="1" <?= !empty($dados['ativo']) ? 'checked' : '' ?>>
        <span>Usuário ativo (pode fazer login)</span>
      </label>
    </div>
    
    <div class="form-actions">
      <a href="usuarios.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">
        <?= $editando ? '💾 Salvar Alterações' : '➕ Criar Usuário' ?>
      </button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>
