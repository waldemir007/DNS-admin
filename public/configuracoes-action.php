<?php
require_once __DIR__ . '/../app/controllers/ConfigController.php';
require_once __DIR__ . '/../app/controllers/Auth.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: configuracoes.php');
    exit;
}

$erros = [];

// Processa redes permitidas
$redes = array_values(array_filter(array_map('trim', explode("\n", $_POST['redes_permitidas'] ?? ''))));
foreach ($redes as $rede) {
    if (!ConfigController::validarRede($rede)) {
        $erros[] = "Rede inválida: {$rede}";
    }
}

// Processa forwarders
$forwarders = array_values(array_filter(array_map('trim', explode("\n", $_POST['forwarders'] ?? ''))));
foreach ($forwarders as $fw) {
    if (!filter_var($fw, FILTER_VALIDATE_IP)) {
        $erros[] = "Forwarder inválido: {$fw}";
    }
}

if (empty($redes)) {
    $erros[] = 'Adicione pelo menos uma rede/IP autorizado.';
}

if (!empty($erros)) {
    $_SESSION['flash'] = ['tipo' => 'error', 'msg' => implode(' | ', $erros)];
    header('Location: configuracoes.php');
    exit;
}

// Salva
ConfigController::setVarias([
    'recursao_ativa'    => isset($_POST['recursao_ativa']) ? '1' : '0',
    'redes_permitidas'  => implode("\n", $redes),
    'forwarders'        => implode("\n", $forwarders),
    'usar_root_servers' => isset($_POST['usar_root_servers']) ? '1' : '0',
    'dnssec_validation' => $_POST['dnssec_validation'] ?? 'auto',
    'max_cache_size'    => (int)($_POST['max_cache_size'] ?? 64),
    'max_cache_ttl'     => (int)($_POST['max_cache_ttl'] ?? 86400),
    'query_log'         => isset($_POST['query_log']) ? '1' : '0',
]);

$r = ConfigController::aplicar();

if ($r['ok']) {
    $_SESSION['flash'] = ['tipo' => 'success', 'msg' => 'Configurações aplicadas e Bind9 recarregado!'];
} else {
    $_SESSION['flash'] = ['tipo' => 'error', 'msg' => $r['erro']];
}

header('Location: configuracoes.php');
exit;
