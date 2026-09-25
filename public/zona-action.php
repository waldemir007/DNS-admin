<?php
require_once __DIR__ . '/../app/controllers/ZonaController.php';
require_once __DIR__ . '/../app/controllers/Auth.php';

if (!Auth::check()) { header('Location: login.php'); exit; }
if (session_status() === PHP_SESSION_NONE) session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: zonas.php'); exit; }

$a = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

switch ($a) {
    case 'create':
        $r = ZonaController::criar($_POST);
        if ($r['ok']) {
            $_SESSION['flash'] = ['tipo'=>'success','msg'=>'Zona criada!'];
            header('Location: zonas.php');
        } else {
            $_SESSION['form_erros'] = $r['erros'];
            $_SESSION['form_dados'] = $_POST;
            header('Location: zona-form.php');
        }
        exit;
    
    case 'update':
        $r = ZonaController::atualizar($id, $_POST);
        if ($r['ok']) {
            $_SESSION['flash'] = ['tipo'=>'success','msg'=>'Zona atualizada!'];
            header('Location: zonas.php');
        } else {
            $_SESSION['form_erros'] = $r['erros'];
            $_SESSION['form_dados'] = $_POST;
            header('Location: zona-form.php?id=' . $id);
        }
        exit;
    
    case 'create_reversa':
        // Detecta se é AJAX (fetch) ou submit normal
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
               || (isset($_POST['ajax']) && $_POST['ajax'] == '1');
        
        $cidr = trim($_POST['cidr'] ?? '');
        $ptrPadrao = trim($_POST['ptr_padrao'] ?? '');
        
        $r = ZonaController::criarZonaReversaComBloco($cidr, $ptrPadrao);
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($r);
            exit;
        }
        
        // Submit normal
        if ($r['ok']) {
            $_SESSION['flash'] = ['tipo'=>'success','msg'=>'Zona reversa criada!'];
            header('Location: ptr-bloco.php?id=' . $r['id']);
        } else {
            $_SESSION['form_erros'] = [$r['erro'] ?? 'Erro desconhecido'];
            $_SESSION['form_dados'] = $_POST;
            header('Location: zona-reversa-form.php');
        }
        exit;
    
    case 'delete':
        $r = ZonaController::excluir($id);
        $_SESSION['flash'] = ['tipo' => $r['ok'] ? 'success' : 'error', 'msg' => $r['ok'] ? 'Zona excluída!' : ($r['erro'] ?? 'Erro')];
        header('Location: zonas.php');
        exit;
    
    case 'reload':
        ZonaController::recarregarBind();
        $_SESSION['flash'] = ['tipo'=>'success','msg'=>'Bind recarregado!'];
        header('Location: zonas.php');
        exit;
    
    default:
        header('Location: zonas.php');
        exit;
}
