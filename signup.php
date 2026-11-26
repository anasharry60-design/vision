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
        if ($accountCount > 0) {
            $_SESSION['flash'] = array('type' => 'error', 'message' => 'Signup is closed. Log in with your account.');
            header('Location: login.php');
            exit;
        }
    } catch (mysqli_sql_exception $exception) {
        $error = 'Could not load the account. Please check the database setup.';
    }
}

if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        authValidateCsrf();
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $email = strtolower(trim(isset($_POST['email']) ? $_POST['email'] : ''));
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new RuntimeException('Enter a valid name and email address.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('Password must be at least 10 characters long.');
        }

        $lockResult = $conn->query("SELECT GET_LOCK('the_best_vision_initial_admin', 5) AS acquired")->fetch_assoc();
        if ((int) $lockResult['acquired'] !== 1) {
            throw new RuntimeException('Signup could not start. Please try again.');
        }
        try {
            if (authAccountCount() > 0) {
                throw new RuntimeException('Signup is closed. Please log in.');
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $statement = $conn->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $statement->bind_param('sss', $name, $email, $passwordHash);
            $statement->execute();
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $conn->insert_id;
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['flash'] = array('type' => 'success', 'message' => 'Admin account created successfully.');
        } finally {
            $conn->query("SELECT RELEASE_LOCK('the_best_vision_initial_admin')");
        }
        header('Location: index.php');
        exit;
    } catch (mysqli_sql_exception $exception) {
        $error = $exception->getCode() === 1062
            ? 'An account with this email already exists.'
            : 'Could not create the account. Please try again.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Signup · The Best Vision School</title>
    <link rel="stylesheet" href="auth.css">
</head>
<body>
<header>
    <h1>The Best Vision School</h1>
    <p>Create the first admin account</p>
</header>
<main>
    <?php if ($error !== ''): ?>
        <div class="flash error"><?php echo h($error); ?></div>
    <?php endif; ?>
    <?php if ($error === '' && $accountCount === 0): ?>
        <section class="panel">
            <h2>Admin signup</h2>
            <p class="subtext">This is only for creating the first admin account. New accounts cannot be created after signup is complete.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                <label>Admin name<input name="name" maxlength="150" autocomplete="name" required></label>
                <label>Email<input type="email" name="email" maxlength="255" autocomplete="email" required></label>
                <label>Password (minimum 10 characters)<input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
                <button type="submit">Create admin account</button>
            </form>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
