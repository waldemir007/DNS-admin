<?php
require_once __DIR__ . '/../app/controllers/Auth.php';
if (Auth::check()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
