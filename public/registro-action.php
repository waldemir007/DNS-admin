<?php
require_once __DIR__ . '/../app/controllers/ZonaController.php';
require_once __DIR__ . '/../app/controllers/Auth.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: registros.php');
    exit;
}

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

switch ($action) {
    case 'create':
        $r = ZonaController::criarRegistro($_POST);
        if ($r['ok']) {
            $_SESSION['flash'] = ['tipo' => 'success', 'msg' => 'Registro criado e Bind recarregado!'];
            $voltar = 'registros.php';
            if (!empty($_POST['zona_id'])) {
                $voltar .= '?zona=' . (int)$_POST['zona_id'];
            }
            header('Location: ' . $voltar);
        } else {
            $_SESSION['form_erros'] = $r['erros'];
            $_SESSION['form_dados'] = $_POST;
            header('Location: registro-form.php');
        }
        exit;
    
    case 'update':
        if (!$id) {
            header('Location: registros.php');
            exit;
        }
        $r = ZonaController::atualizarRegistro($id, $_POST);
        if ($r['ok']) {
            $_SESSION['flash'] = ['tipo' => 'success', 'msg' => 'Registro atualizado e Bind recarregado!'];
            $voltar = 'registros.php';
            if (!empty($_POST['zona_id'])) {
                $voltar .= '?zona=' . (int)$_POST['zona_id'];
            }
            header('Location: ' . $voltar);
        } else {
            $_SESSION['form_erros'] = $r['erros'];
            $_SESSION['form_dados'] = $_POST;
            header('Location: registro-form.php?id=' . $id);
        }
        exit;
    
    case 'delete':
        if (!$id) {
            header('Location: registros.php');
            exit;
        }
        $reg = ZonaController::buscarRegistro($id);
        $zonaId = $reg['zona_id'] ?? 0;
        $r = ZonaController::excluirRegistro($id);
        $_SESSION['flash'] = $r['ok']
            ? ['tipo' => 'success', 'msg' => 'Registro excluído!']
            : ['tipo' => 'error', 'msg' => $r['erro'] ?? 'Erro ao excluir.'];
        header('Location: registros.php' . ($zonaId ? '?zona=' . $zonaId : ''));
        exit;
    
    default:
        header('Location: registros.php');
        exit;
}
