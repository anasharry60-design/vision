<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . (!empty($_SESSION['user_id']) ? 'index.php' : 'login.php'));
    exit;
}

try {
    authValidateCsrf();
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['flash'] = array('type' => 'success', 'message' => 'You have been logged out.');
} catch (RuntimeException $exception) {
    $_SESSION['flash'] = array('type' => 'error', 'message' => $exception->getMessage());
}

header('Location: login.php');
exit;
