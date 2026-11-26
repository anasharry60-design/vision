<?php
session_start();
require_once __DIR__ . '/dbcon.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function authAccountCount()
{
    global $conn;
    return (int) $conn->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'];
}

function authValidateCsrf()
{
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])
    ) {
        throw new RuntimeException('Your form session expired. Please try again.');
    }
}

$authSetupError = '';
try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (mysqli_sql_exception $exception) {
    $authSetupError = 'Database setup failed. Check that the database exists and the account has permission to create tables.';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
