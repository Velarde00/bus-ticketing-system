<?php
/**
 * auth/login.php
 * Checks the email and password, then sends the user to the right dashboard.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect($_SESSION['role'] === 'ADMIN' ? 'admin/dashboard.php' : 'passenger/dashboard.php');
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $errors[] = 'Enter your email and password.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT user_id, first_name, last_name, password_hash, role, account_status
                 FROM users WHERE email = :email LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors[] = 'Incorrect email or password.';
            } elseif ($user['account_status'] !== 'ACTIVE') {
                $errors[] = 'This account is inactive. Contact the administrator.';
            } else {
                session_regenerate_id(true);               // stops session fixation
                $_SESSION['user_id']   = (int)$user['user_id'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['role']      = $user['role'];
                redirect($user['role'] === 'ADMIN' ? 'admin/dashboard.php' : 'passenger/dashboard.php');
            }
        }
    }
}

$pageTitle = 'Log in';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container auth-wrap">
    <div class="auth-card">
        <h1 class="h3">Welcome back</h1>
        <p class="text-muted">Log in to book seats and see your tickets.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger py-2"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Log in</button>
        </form>
        <p class="mt-3 mb-0 text-center small">No account yet? <a href="<?= url('auth/register.php') ?>">Create one</a></p>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
