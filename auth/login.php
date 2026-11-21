<?php
/**
 * auth/login.php
 * Premium sliding auth experience: Login on the left, slide to right to Register.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(in_array($_SESSION['role'], ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php');
}

$loginErrors = [];
$registerErrors = [];
$activeTab = $_GET['tab'] ?? 'login';

// Process Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $activeTab = 'login';
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
    $activeTab = 'register';
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

$pageTitle = $activeTab === 'register' ? 'Create Account' : 'Sign In';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-5 d-flex align-items-center justify-content-center" style="min-height: calc(100vh - 200px);">
    <div class="auth-split-wrapper shadow-2xl rounded-4 border overflow-hidden row g-0">
        <!-- Interactive Sliding Hero Panel -->
        <div class="col-lg-5 auth-hero-side d-flex flex-column justify-content-between p-4 p-md-5 order-lg-<?= $activeTab === 'register' ? '2' : '1' ?>" id="authHeroSide">
            <div class="mb-4">
                <div class="brand-icon-wrapper mb-3" style="width: 52px; height: 52px; font-size: 1.5rem; background: rgba(255,255,255,0.15); color: #FFF; border-radius: 50%; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px);">
                    <i class="bi bi-bus-front-fill"></i>
                </div>
                <span class="badge bg-white bg-opacity-20 text-white rounded-pill px-3 py-1 small mb-2">Philippine Intercity Express</span>
                <h2 class="h3 fw-bold text-white mb-2" id="heroHeading">
                    <?= $activeTab === 'register' ? 'Already have an account?' : 'New to BusTicket?' ?>
                </h2>
                <p class="text-white-50 small mb-0" id="heroSubtext">
                    <?= $activeTab === 'register' 
                        ? 'Sign in now to access your booked trips, digital boarding passes, and ticket receipts.'
                        : 'Register your passenger account today to book seats, select preferred coach layouts, and travel seamlessly across the Philippines.' ?>
                </p>
            </div>

            <!-- Slide Toggle Trigger Button -->
            <div class="pt-4 border-top border-white border-opacity-10">
                <button type="button" class="btn btn-outline-light rounded-pill px-4 py-2 w-100 fw-semibold d-flex align-items-center justify-content-center gap-2 shadow-sm" id="toggleSlideBtn" onclick="toggleAuthSlide()">
                    <span id="toggleBtnText"><?= $activeTab === 'register' ? 'Switch to Sign In' : 'Create Free Account' ?></span>
                    <i class="bi bi-arrow-right" id="toggleBtnIcon"></i>
                </button>
            </div>
        </div>

        <!-- Form Panels Container -->
        <div class="col-lg-7 p-4 p-md-5 bg-white d-flex flex-column justify-content-center order-lg-<?= $activeTab === 'register' ? '1' : '2' ?>" id="authFormContainer">
            <!-- ================= LOGIN FORM ================= -->
            <div id="loginSection" style="display: <?= $activeTab === 'register' ? 'none' : 'block' ?>;">
                <div class="mb-4">
                    <h2 class="h4 fw-bold text-dark mb-1">Welcome Back</h2>
                    <p class="text-muted small mb-0">Sign in to your account with your email and password.</p>
                </div>

                <?php foreach ($loginErrors as $error): ?>
                    <div class="alert alert-danger py-2 d-flex align-items-center gap-2 border-0 shadow-sm small mb-3">
                        <i class="bi bi-exclamation-circle-fill text-danger flex-shrink-0"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endforeach; ?>

                <form method="post" action="<?= url('auth/login.php') ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="login">

                    <div class="mb-3">
                        <label for="login_email" class="form-label small fw-semibold">Email address</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control border-start-0 ps-0" id="login_email" name="email" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" placeholder="name@example.com" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="login_password" class="form-label small fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control border-start-0 border-end-0 ps-0" id="login_password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                            <button class="btn btn-outline-secondary border-start-0" type="button" data-password-toggle="login_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm py-2 fw-bold d-flex align-items-center justify-content-center gap-2">
                        <i class="bi bi-box-arrow-in-right"></i> Sign In to Account
                    </button>
                </form>
            </div>

            <!-- ================= REGISTER FORM ================= -->
            <div id="registerSection" style="display: <?= $activeTab === 'register' ? 'block' : 'none' ?>;">
                <div class="mb-3">
                    <h2 class="h4 fw-bold text-dark mb-1">Create Passenger Account</h2>
                    <p class="text-muted small mb-0">Join in one minute to book seats and travel across the country.</p>
                </div>

                <?php foreach ($registerErrors as $error): ?>
                    <div class="alert alert-danger py-2 d-flex align-items-center gap-2 border-0 shadow-sm small mb-3">
                        <i class="bi bi-exclamation-circle-fill text-danger flex-shrink-0"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endforeach; ?>

                <form method="post" action="<?= url('auth/login.php') ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="register">

                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label for="first_name" class="form-label small fw-semibold mb-1">First Name</label>
                            <input type="text" class="form-control form-control-sm" id="first_name" name="first_name" value="<?= e($_POST['first_name'] ?? '') ?>" placeholder="Juan" required>
                        </div>
                        <div class="col-sm-6">
                            <label for="last_name" class="form-label small fw-semibold mb-1">Last Name</label>
                            <input type="text" class="form-control form-control-sm" id="last_name" name="last_name" value="<?= e($_POST['last_name'] ?? '') ?>" placeholder="Dela Cruz" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-sm-7">
                            <label for="reg_email" class="form-label small fw-semibold mb-1">Email Address</label>
                            <input type="email" class="form-control form-control-sm" id="reg_email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="juan@example.com" required>
                        </div>
                        <div class="col-sm-5">
                            <label for="reg_phone" class="form-label small fw-semibold mb-1">Mobile Number</label>
                            <input type="tel" class="form-control form-control-sm" id="reg_phone" name="phone_number" value="<?= e($_POST['phone_number'] ?? '') ?>" placeholder="09171234567" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label for="reg_password" class="form-label small fw-semibold mb-1">Password</label>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control border-end-0" id="reg_password" name="password" placeholder="Min. 8 chars" required>
                                <button class="btn btn-outline-secondary border-start-0" type="button" data-password-toggle="reg_password"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label for="reg_confirm" class="form-label small fw-semibold mb-1">Confirm Password</label>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control border-end-0" id="reg_confirm" name="confirm_password" placeholder="Repeat password" required>
                                <button class="btn btn-outline-secondary border-start-0" type="button" data-password-toggle="reg_confirm"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 rounded-pill shadow-sm py-2 fw-bold d-flex align-items-center justify-content-center gap-2">
                        <i class="bi bi-person-plus-fill"></i> Register Account &amp; Verify OTP
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let currentMode = '<?= $activeTab === 'register' ? 'register' : 'login' ?>';

function toggleAuthSlide() {
    const heroSide = document.getElementById('authHeroSide');
    const formSide = document.getElementById('authFormContainer');
    const loginSec = document.getElementById('loginSection');
    const regSec   = document.getElementById('registerSection');
    const heroHeading = document.getElementById('heroHeading');
    const heroSubtext = document.getElementById('heroSubtext');
    const toggleBtnText = document.getElementById('toggleBtnText');
    const toggleBtnIcon = document.getElementById('toggleBtnIcon');

    if (currentMode === 'login') {
        // Slide hero to the right, show register
        currentMode = 'register';
        heroSide.classList.remove('order-lg-1');
        heroSide.classList.add('order-lg-2');
        formSide.classList.remove('order-lg-2');
        formSide.classList.add('order-lg-1');

        loginSec.style.display = 'none';
        regSec.style.display = 'block';

        heroHeading.textContent = 'Already have an account?';
        heroSubtext.textContent = 'Sign in now to access your booked trips, digital boarding passes, and ticket receipts.';
        toggleBtnText.textContent = 'Switch to Sign In';
        toggleBtnIcon.className = 'bi bi-arrow-left';
        window.history.replaceState(null, '', '?tab=register');
    } else {
        // Slide hero to the left, show login
        currentMode = 'login';
        heroSide.classList.remove('order-lg-2');
        heroSide.classList.add('order-lg-1');
        formSide.classList.remove('order-lg-1');
        formSide.classList.add('order-lg-2');

        regSec.style.display = 'none';
        loginSec.style.display = 'block';

        heroHeading.textContent = 'New to BusTicket?';
        heroSubtext.textContent = 'Register your passenger account today to book seats, select preferred coach layouts, and travel seamlessly across the Philippines.';
        toggleBtnText.textContent = 'Create Free Account';
        toggleBtnIcon.className = 'bi bi-arrow-right';
        window.history.replaceState(null, '', '?tab=login');
    }
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
