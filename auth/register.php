<?php
/**
 * auth/register.php
 * Creates a new PASSENGER account. Passwords are stored as hashes, never as plain text.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('passenger/dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $first    = trim($_POST['first_name'] ?? '');
        $last     = trim($_POST['last_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = preg_replace('/[\s-]/', '', $_POST['phone_number'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        // Server-side validation (never trust the browser)
        if (!preg_match("/^[\p{L}][\p{L} .'-]{1,49}$/u", $first)) {
            $errors[] = 'Enter a valid first name (2 to 50 letters).';
        }
        if (!preg_match("/^[\p{L}][\p{L} .'-]{1,49}$/u", $last)) {
            $errors[] = 'Enter a valid last name (2 to 50 letters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
            $errors[] = 'Enter a Philippine mobile number, like 09171234567.';
        }
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Password must be at least 8 characters with a letter and a number.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT 1 FROM users WHERE email = :email');
            $check->execute([':email' => $email]);
            if ($check->fetch()) {
                $errors[] = 'That email is already registered. Try logging in.';
            }
        }

        if (!$errors) {
            try {
                $insert = $pdo->prepare(
                    "INSERT INTO users (first_name, last_name, email, phone_number, password_hash, role)
                     VALUES (:first, :last, :email, :phone, :hash, 'PASSENGER')"
                );
                $insert->execute([
                    ':first' => $first,
                    ':last'  => $last,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':hash'  => password_hash($password, PASSWORD_DEFAULT),
                ]);
                set_flash('success', 'Account created. You can log in now.');
                redirect('auth/login.php');
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = 'We could not create your account. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create account';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container auth-wrap">
    <div class="auth-card auth-card-wide">
        <h1 class="h3">Create your account</h1>
        <p class="text-muted">It takes a minute, and you can book right after.</p>

        <?php if ($errors): ?>
            <div class="alert alert-danger py-2">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-sm-6">
                    <label for="first_name" class="form-label">First name</label>
                    <input type="text" class="form-control" id="first_name" name="first_name" value="<?= old('first_name') ?>" maxlength="50" required>
                </div>
                <div class="col-sm-6">
                    <label for="last_name" class="form-label">Last name</label>
                    <input type="text" class="form-control" id="last_name" name="last_name" value="<?= old('last_name') ?>" maxlength="50" required>
                </div>
                <div class="col-sm-7">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= old('email') ?>" maxlength="100" autocomplete="email" required>
                </div>
                <div class="col-sm-5">
                    <label for="phone_number" class="form-label">Mobile number</label>
                    <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?= old('phone_number') ?>" placeholder="09171234567" required>
                </div>
                <div class="col-sm-6">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" aria-describedby="pwHelp" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <div id="pwHelp" class="form-text">At least 8 characters, with a letter and a number.</div>
                </div>
                <div class="col-sm-6">
                    <label for="confirm_password" class="form-label">Confirm password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="confirm_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-4">Create account</button>
        </form>
        <p class="mt-3 mb-0 text-center small">Already registered? <a href="<?= url('auth/login.php') ?>">Log in</a></p>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
