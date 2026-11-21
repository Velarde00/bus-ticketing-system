<?php
/**
 * auth/login.php
 * Smooth animated sliding authentication container (Login & Register).
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(in_array($_SESSION['role'], ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php');
}

$loginErrors = [];
$registerErrors = [];
$isRegister = (isset($_GET['tab']) && $_GET['tab'] === 'register') || (isset($_POST['action']) && $_POST['action'] === 'register');

// Process Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if (!csrf_verify()) {
        $loginErrors[] = 'Your session expired. Please try again.';
    } else {
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $loginErrors[] = 'Enter your email and password.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT user_id, first_name, last_name, email, phone_number, password_hash, role, account_status
                 FROM users WHERE email = :email LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $loginErrors[] = 'Incorrect email or password.';
            } elseif ($user['account_status'] !== 'ACTIVE') {
                $loginErrors[] = 'This account is inactive. Contact the administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']   = (int)$user['user_id'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['role']      = $user['role'];
                redirect(in_array($user['role'], ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php');
            }
        }
    }
}

// Process Register
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    if (!csrf_verify()) {
        $registerErrors[] = 'Your session expired. Please try again.';
    } else {
        $first    = trim($_POST['first_name'] ?? '');
        $last     = trim($_POST['last_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = preg_replace('/[\s-]/', '', $_POST['phone_number'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!preg_match("/^[\p{L}][\p{L} .'-]{1,49}$/u", $first)) {
            $registerErrors[] = 'Enter a valid first name (2 to 50 letters).';
        }
        if (!preg_match("/^[\p{L}][\p{L} .'-]{1,49}$/u", $last)) {
            $registerErrors[] = 'Enter a valid last name (2 to 50 letters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $registerErrors[] = 'Enter a valid email address.';
        }
        if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
            $registerErrors[] = 'Enter a Philippine mobile number, like 09171234567.';
        }
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $registerErrors[] = 'Password must be at least 8 characters with a letter and a number.';
        }
        if ($password !== $confirm) {
            $registerErrors[] = 'The two passwords do not match.';
        }

        if (!$registerErrors) {
            $check = $pdo->prepare('SELECT 1 FROM users WHERE email = :email');
            $check->execute([':email' => $email]);
            if ($check->fetch()) {
                $registerErrors[] = 'That email is already registered. Try logging in.';
            }
        }

        if (!$registerErrors) {
            $otpInfo = create_and_dispatch_otp($pdo, $email, $phone, 'REGISTRATION');

            $_SESSION['pending_registration'] = [
                'first_name'    => $first,
                'last_name'     => $last,
                'email'         => $email,
                'phone_number'  => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'masked_email'  => $otpInfo['masked_email'],
                'masked_phone'  => $otpInfo['masked_phone'],
                'code'          => $otpInfo['code'],
                'expires_at'    => $otpInfo['expires_at'],
            ];

            set_flash('info', 'We sent a 6-digit verification code to your email and phone.');
            redirect('auth/verify_otp.php');
        }
    }
}

$pageTitle = $isRegister ? 'Create Account' : 'Sign In';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-page-scenic py-5 d-flex align-items-center justify-content-center">
    <div class="auth-floating-glow"></div>
    <div class="auth-container-sliding <?= $isRegister ? 'right-panel-active' : '' ?>" id="authContainer">
        <!-- Register Form Box (Left side of container in background) -->
        <div class="form-container sign-up-container">
            <form method="post" action="<?= url('auth/login.php') ?>" class="auth-inner-form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <h2 class="h4 fw-bold text-dark mb-1">Create Passenger Account</h2>
                <p class="text-muted small mb-3">Join to book seats &amp; travel across the Philippines.</p>

                <?php foreach ($registerErrors as $error): ?>
                    <div class="alert alert-danger py-1 px-2 border-0 shadow-sm small mb-2 w-100 text-start">
                        <i class="bi bi-exclamation-circle-fill me-1"></i><?= e($error) ?>
                    </div>
                <?php endforeach; ?>

                <div class="row g-2 w-100 mb-2">
                    <div class="col-6 text-start">
                        <label class="form-label small fw-semibold mb-1">First Name</label>
                        <input type="text" class="form-control form-control-sm" name="first_name" value="<?= e($_POST['first_name'] ?? '') ?>" placeholder="Juan" required>
                    </div>
                    <div class="col-6 text-start">
                        <label class="form-label small fw-semibold mb-1">Last Name</label>
                        <input type="text" class="form-control form-control-sm" name="last_name" value="<?= e($_POST['last_name'] ?? '') ?>" placeholder="Dela Cruz" required>
                    </div>
                </div>

                <div class="w-100 text-start mb-2">
                    <label class="form-label small fw-semibold mb-1">Email Address</label>
                    <input type="email" class="form-control form-control-sm" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="juan@example.com" required>
                </div>

                <div class="w-100 text-start mb-2">
                    <label class="form-label small fw-semibold mb-1">Mobile Number</label>
                    <input type="tel" class="form-control form-control-sm" name="phone_number" value="<?= e($_POST['phone_number'] ?? '') ?>" placeholder="09171234567" required>
                </div>

                <div class="row g-2 w-100 mb-3">
                    <div class="col-6 text-start">
                        <label class="form-label small fw-semibold mb-1">Password</label>
                        <div class="input-group input-group-sm">
                            <input type="password" class="form-control" id="reg_pw" name="password" placeholder="Min. 8 chars" required>
                            <button class="btn btn-outline-secondary" type="button" data-password-toggle="reg_pw"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="col-6 text-start">
                        <label class="form-label small fw-semibold mb-1">Confirm</label>
                        <div class="input-group input-group-sm">
                            <input type="password" class="form-control" id="reg_cpw" name="confirm_password" placeholder="Repeat" required>
                            <button class="btn btn-outline-secondary" type="button" data-password-toggle="reg_cpw"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary rounded-pill w-100 py-2 fw-bold shadow-sm">
                    <i class="bi bi-person-check-fill me-1"></i> Register &amp; Verify OTP
                </button>
                <div class="d-block d-md-none mt-3">
                    <a href="javascript:void(0)" onclick="toggleToLogin()" class="text-primary small fw-semibold">Already have an account? Sign In</a>
                </div>
            </form>
        </div>

        <!-- Login Form Box (Right side of container in default state) -->
        <div class="form-container sign-in-container">
            <form method="post" action="<?= url('auth/login.php') ?>" class="auth-inner-form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">

                <div class="brand-icon-wrapper mb-2" style="width: 52px; height: 52px; font-size: 1.5rem; background: var(--primary-subtle, #ecfdf5); color: var(--primary, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-bus-front-fill"></i>
                </div>
                <h2 class="h3 fw-bold text-dark mb-1">Welcome Back</h2>
                <p class="text-muted small mb-4">Sign in to manage your tickets &amp; reservations.</p>

                <?php foreach ($loginErrors as $error): ?>
                    <div class="alert alert-danger py-2 px-3 border-0 shadow-sm small mb-3 w-100 text-start">
                        <i class="bi bi-exclamation-circle-fill me-1"></i><?= e($error) ?>
                    </div>
                <?php endforeach; ?>

                <div class="w-100 text-start mb-3">
                    <label class="form-label small fw-semibold">Email address</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control border-start-0 ps-0" name="email" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" placeholder="name@example.com" required autofocus>
                    </div>
                </div>

                <div class="w-100 text-start mb-4">
                    <label class="form-label small fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control border-start-0 border-end-0 ps-0" id="login_pw" name="password" autocomplete="current-password" placeholder="Enter password" required>
                        <button class="btn btn-outline-secondary border-start-0" type="button" data-password-toggle="login_pw"><i class="bi bi-eye"></i></button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary rounded-pill w-100 py-3 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-box-arrow-in-right"></i> Sign In to Account
                </button>
                <div class="d-block d-md-none mt-3">
                    <a href="javascript:void(0)" onclick="toggleToRegister()" class="text-primary small fw-semibold">Don't have an account? Sign Up</a>
                </div>
            </form>
        </div>

        <!-- The Sliding Overlay Layer -->
        <div class="overlay-container">
            <div class="overlay">
                <!-- Overlay Left (Visible when register form is shown) -->
                <div class="overlay-panel overlay-left">
                    <span class="badge bg-white bg-opacity-20 text-white rounded-pill px-3 py-1 small mb-3">Philippine Intercity Express</span>
                    <h2 class="h2 fw-bold text-white mb-2">Welcome Back!</h2>
                    <p class="text-white-50 px-4 small mb-4">To stay connected with your journeys and download boarding passes, please log in with your credentials.</p>
                    <button type="button" class="btn btn-outline-light rounded-pill px-5 py-2 fw-bold shadow-sm" id="signInBtn" onclick="toggleToLogin()">
                        <i class="bi bi-arrow-left me-1"></i> Sign In
                    </button>
                </div>

                <!-- Overlay Right (Visible when login form is shown) -->
                <div class="overlay-panel overlay-right">
                    <span class="badge bg-white bg-opacity-20 text-white rounded-pill px-3 py-1 small mb-3">Instant Online Booking</span>
                    <h2 class="h2 fw-bold text-white mb-2">Hello, Traveler!</h2>
                    <p class="text-white-50 px-4 small mb-4">Register your passenger account today to select seats, enjoy 20% discounts, and travel with ease.</p>
                    <button type="button" class="btn btn-outline-light rounded-pill px-5 py-2 fw-bold shadow-sm" id="signUpBtn" onclick="toggleToRegister()">
                        Sign Up <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const container = document.getElementById('authContainer');

function toggleToRegister() {
    container.classList.add('right-panel-active');
    window.history.replaceState(null, '', '?tab=register');
}

function toggleToLogin() {
    container.classList.remove('right-panel-active');
    window.history.replaceState(null, '', '?tab=login');
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
