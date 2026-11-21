<?php
/**
 * auth/verify_otp.php
 * One-Time Password (OTP) verification page for new account registrations.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(in_array($_SESSION['role'], ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php');
}

if (empty($_SESSION['pending_registration'])) {
    set_flash('warning', 'Please register first to verify your account.');
    redirect('auth/register.php');
}

$pending = $_SESSION['pending_registration'];
$errors = [];

// Handle Resend Request
if (isset($_GET['resend']) && $_GET['resend'] === '1') {
    $newOtp = create_and_dispatch_otp($pdo, $pending['email'], $pending['phone_number'], 'REGISTRATION');
    $_SESSION['pending_registration']['code'] = $newOtp['code'];
    $_SESSION['pending_registration']['expires_at'] = $newOtp['expires_at'];
    $pending = $_SESSION['pending_registration'];
    set_flash('success', 'A new 6-digit verification code has been dispatched to your email and mobile number.');
    redirect('auth/verify_otp.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $enteredCode = trim($_POST['otp_code'] ?? '');

        if ($enteredCode === '' || strlen($enteredCode) !== 6 || !ctype_digit($enteredCode)) {
            $errors[] = 'Please enter a valid 6-digit verification code.';
        } else {
            $isValid = verify_otp_code($pdo, $pending['email'], $enteredCode, 'REGISTRATION');

            if (!$isValid) {
                $errors[] = 'The code you entered is invalid or has expired. Please check and try again.';
            } else {
                // Successful verification: save new user account
                try {
                    $insert = $pdo->prepare(
                        "INSERT INTO users (first_name, last_name, email, phone_number, password_hash, role, account_status)
                         VALUES (:first, :last, :email, :phone, :hash, 'PASSENGER', 'ACTIVE')"
                    );
                    $insert->execute([
                        ':first' => $pending['first_name'],
                        ':last'  => $pending['last_name'],
                        ':email' => $pending['email'],
                        ':phone' => $pending['phone_number'],
                        ':hash'  => $pending['password_hash'],
                    ]);
                    $userId = (int)$pdo->lastInsertId();

                    // Automatically sign in the user
                    session_regenerate_id(true);
                    $_SESSION['user_id']   = $userId;
                    $_SESSION['user_name'] = $pending['first_name'] . ' ' . $pending['last_name'];
                    $_SESSION['role']      = 'PASSENGER';

                    unset($_SESSION['pending_registration']);

                    set_flash('success', 'Email verified and account activated successfully! Welcome to BusTicket.');
                    redirect('passenger/dashboard.php');
                } catch (PDOException $e) {
                    error_log('registration error: ' . $e->getMessage());
                    $errors[] = 'An error occurred while creating your account. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Verify Your Email';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container auth-wrap py-5">
    <div class="auth-card-modern shadow-lg bg-white rounded-4 border p-4 p-sm-5 mx-auto" style="max-width: 480px;">
        <div class="text-center mb-4">
            <div class="brand-icon-wrapper mx-auto mb-3" style="width: 58px; height: 58px; font-size: 1.6rem; background: var(--primary-subtle, #ecfdf5); color: var(--primary, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-shield-check"></i>
            </div>
            <h1 class="h4 fw-bold text-dark mb-1">Verify Your Email &amp; Phone</h1>
            <p class="text-muted small mb-0">We sent a 6-digit One-Time Password (OTP) to confirm your registration:</p>
        </div>

        <div class="p-3 bg-light rounded-3 border mb-4 text-center">
            <div class="small text-muted mb-1"><i class="bi bi-envelope me-1 text-primary"></i> Email: <strong><?= e($pending['masked_email']) ?></strong></div>
            <div class="small text-muted"><i class="bi bi-phone me-1 text-primary"></i> Mobile: <strong><?= e($pending['masked_phone']) ?></strong></div>
        </div>

        <!-- Simulation Delivery Badge for Local Testing -->
        <div class="alert alert-info border-0 shadow-sm rounded-3 py-2 px-3 small d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-bell-fill text-info flex-shrink-0 fs-5"></i>
            <div>
                <strong>Inbox &amp; SMS Dispatch:</strong><br>
                <span>Sent code: <strong class="badge bg-primary text-white fs-6 px-2 py-1 font-monospace"><?= e($pending['code']) ?></strong> (Click <a href="javascript:void(0);" onclick="document.getElementById('otp_code').value='<?= e($pending['code']) ?>';" class="fw-bold text-decoration-underline">Autofill</a>)</span>
            </div>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger py-2 d-flex align-items-center gap-2 border-0 shadow-sm small mb-3">
                <i class="bi bi-exclamation-circle-fill text-danger"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endforeach; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="mb-4">
                <label for="otp_code" class="form-label small fw-semibold text-center d-block">Enter 6-Digit Code</label>
                <input type="text" class="form-control form-control-lg text-center font-monospace fw-bold tracking-widest letter-spacing-lg"
                       id="otp_code" name="otp_code" maxlength="6" pattern="\d{6}" placeholder="000000" inputmode="numeric" autocomplete="one-time-code" required autofocus
                       style="font-size: 1.8rem; letter-spacing: 0.4em;">
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm mb-3">
                <i class="bi bi-check2-circle me-1"></i> Verify &amp; Activate Account
            </button>
        </form>

        <div class="text-center pt-3 border-top d-flex justify-content-between align-items-center small">
            <a href="<?= url('auth/verify_otp.php?resend=1') ?>" class="text-primary fw-semibold text-decoration-none">
                <i class="bi bi-arrow-repeat me-1"></i> Resend Code
            </a>
            <a href="<?= url('auth/register.php') ?>" class="text-muted text-decoration-none">
                <i class="bi bi-box-arrow-left me-1"></i> Back to Registration
            </a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
