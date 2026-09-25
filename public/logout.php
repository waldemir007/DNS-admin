<?php
require_once __DIR__ . '/../app/controllers/Auth.php';

// Envia headers anti-cache
Auth::semCache();

// Faz logout
Auth::logout();

// Redireciona para login com headers anti-cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Location: login.php');
exit;
