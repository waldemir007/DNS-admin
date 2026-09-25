<?php
require_once __DIR__ . '/../app/controllers/UsuarioController.php';
require_once __DIR__ . '/../app/controllers/Auth.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: usuarios.php');
    exit;
}

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

switch ($action) {
    case 'create':
        $resultado = UsuarioController::criar($_POST);
        if ($resultado['ok']) {
            $_SESSION['flash'] = ['tipo' => 'success', 'msg' => 'Usuário criado com sucesso!'];
            header('Location: usuarios.php');
        } else {
            $_SESSION['form_erros'] = $resultado['erros'];
            $_SESSION['form_dados'] = $_POST;
            header('Location: usuario-form.php');
        }
        exit;
    
    case 'update':
        if (!$id) { header('Location: usuarios.php'); exit; }
        $resultado = UsuarioController::atualizar($id, $_POST);
        if ($resultado['ok']) {
            $_SESSION['flash'] = ['tipo' => 'success', 'msg' => 'Usuário atualizado com sucesso!'];
            header('Location: usuarios.php');
        } else {
            $_SESSION['form_erros'] = $resultado['erros'];
            $_SESSION['form_dados'] = $_POST;
            header('Location: usuario-form.php?id=' . $id);
        }
        exit;
    
    case 'delete':
        if (!$id) { header('Location: usuarios.php'); exit; }
        $resultado = UsuarioController::excluir($id);
        $_SESSION['flash'] = $resultado['ok']
            ? ['tipo' => 'success', 'msg' => 'Usuário excluído com sucesso!']
            : ['tipo' => 'error', 'msg' => $resultado['erro']];
        header('Location: usuarios.php');
        exit;
    
    case 'toggle':
        if (!$id) { header('Location: usuarios.php'); exit; }
        $resultado = UsuarioController::toggleAtivo($id);
        $_SESSION['flash'] = $resultado['ok']
            ? ['tipo' => 'success', 'msg' => 'Status alterado com sucesso!']
            : ['tipo' => 'error', 'msg' => $resultado['erro']];
        header('Location: usuarios.php');
        exit;
    
    default:
        header('Location: usuarios.php');
        exit;
}
