<?php
require_once __DIR__ . '/auth.php';

$error = $authSetupError;
$accountCount = 0;
if ($error === '') {
    if (!empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
    try {
        $accountCount = authAccountCount();
        if ($accountCount === 0) {
            header('Location: signup.php');
            exit;
        }
    } catch (mysqli_sql_exception $exception) {
        $error = 'Could not load the account. Please check the database setup.';
    }
}

if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        authValidateCsrf();
        $email = strtolower(trim(isset($_POST['email']) ? $_POST['email'] : ''));
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $statement = $conn->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ?');
        $statement->bind_param('s', $email);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('The email or password is incorrect.');
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['flash'] = array('type' => 'success', 'message' => 'Login successful.');
        header('Location: index.php');
        exit;
    } catch (mysqli_sql_exception $exception) {
        $error = 'Could not complete the login. Please try again.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$flash = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login · The Best Vision School</title>
    <link rel="stylesheet" href="auth.css">
</head>
<body>
<header>
    <h1>The Best Vision School</h1>
    <p>Admin login</p>
</header>
<main>
    <?php if ($flash): ?>
        <div class="flash <?php echo h($flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash error"><?php echo h($error); ?></div>
    <?php endif; ?>
    <?php if ($error === '' && $accountCount > 0): ?>
        <section class="panel">
            <h2>Login</h2>
            <p class="subtext">Enter your admin credentials to open the dashboard.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                <label>Email<input type="email" name="email" maxlength="255" autocomplete="username" required></label>
                <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
                <button type="submit">Login</button>
            </form>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
