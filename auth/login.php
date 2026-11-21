<?php
/**
 * auth/login.php
 * Checks the email and password, then sends the user to the right dashboard.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(in_array($_SESSION['role'], ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php');
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
                'SELECT user_id, first_name, last_name, email, phone_number, password_hash, role, account_status
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
                redirect(in_array($user['role'], ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php');
            }
        }
    }
}

$pageTitle = 'Log in';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container auth-wrap py-5">
    <div class="auth-card-modern shadow-lg bg-white rounded-4 border p-4 p-sm-5 mx-auto">
        <div class="text-center mb-4">
            <div class="brand-icon-wrapper mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.5rem;">
                <i class="bi bi-bus-front-fill"></i>
            </div>
            <h1 class="h4 fw-bold text-dark mb-1">Welcome Back</h1>
            <p class="text-muted small mb-0">Sign in to book seats, manage reservations, and download boarding passes.</p>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger py-2 d-flex align-items-center gap-2 border-0 shadow-sm small mb-3">
                <i class="bi bi-exclamation-circle-fill text-danger"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endforeach; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="email" class="form-label small fw-semibold">Email address</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control border-start-0 ps-0" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" placeholder="name@example.com" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <label for="password" class="form-label small fw-semibold">Password</label>
                </div>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control border-start-0 border-end-0 ps-0" id="password" name="password" autocomplete="current-password" placeholder="Enter password" required>
                    <button class="btn btn-outline-secondary border-start-0" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm mb-3">
                <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
            </button>
        </form>

        <div class="text-center pt-3 border-top">
            <p class="mb-0 text-muted small">Don't have an account yet? <a href="<?= url('auth/register.php') ?>" class="text-primary fw-semibold text-decoration-none">Create an account</a></p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
