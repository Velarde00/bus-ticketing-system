<?php
/**
 * auth/verify_otp.php
 * Premium modern OTP verification page for new account registrations.
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
    set_flash('success', 'A fresh 6-digit code has been dispatched to your email and mobile number.');
    redirect('auth/verify_otp.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $digitArr = $_POST['otp_digits'] ?? [];
        if (is_array($digitArr) && count($digitArr) === 6) {
            $enteredCode = implode('', $digitArr);
        } else {
            $enteredCode = trim($_POST['otp_code'] ?? '');
        }

        if ($enteredCode === '' || strlen($enteredCode) !== 6 || !ctype_digit($enteredCode)) {
            $errors[] = 'Please enter all 6 digits of the verification code.';
        } else {
            $isValid = verify_otp_code($pdo, $pending['email'], $enteredCode, 'REGISTRATION');

            if (!$isValid) {
                $errors[] = 'The code you entered is invalid or has expired. Please check and try again.';
            } else {
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
    <div class="auth-card-modern shadow-lg bg-white rounded-4 border p-4 p-sm-5 mx-auto" style="max-width: 520px;">
        <!-- Header Section -->
        <div class="text-center mb-4">
            <div class="brand-icon-wrapper mx-auto mb-3" style="width: 68px; height: 68px; font-size: 2rem; background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); color: var(--primary, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 25px -5px rgba(5, 150, 105, 0.2);">
                <i class="bi bi-shield-check"></i>
            </div>
            <h1 class="h3 fw-bold text-dark mb-1">Verify Your Account</h1>
            <p class="text-muted small mb-0">We sent a 6-digit confirmation code to authenticate your contact details.</p>
        </div>

        <!-- Destination Details Chip -->
        <div class="p-3 bg-light rounded-4 border mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small"><i class="bi bi-envelope text-primary me-2"></i>Email Address:</span>
                <span class="fw-semibold text-dark font-monospace small"><?= e($pending['masked_email']) ?></span>
            </div>
            <div class="d-flex align-items-center justify-content-between">
                <span class="text-muted small"><i class="bi bi-phone text-primary me-2"></i>Mobile Number:</span>
                <span class="fw-semibold text-dark font-monospace small"><?= e($pending['masked_phone']) ?></span>
            </div>
        </div>

        <!-- Testing / Demo Helper Toast -->
        <div class="alert alert-info border-0 shadow-sm rounded-4 py-3 px-3 small d-flex align-items-center gap-3 mb-4" style="background-color: #f0f9ff; border-left: 4px solid #0284c7 !important;">
            <div class="p-2 bg-white rounded-circle shadow-sm text-info d-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px;">
                <i class="bi bi-key-fill fs-5"></i>
            </div>
            <div class="flex-grow-1">
                <div class="fw-semibold text-dark">Demo Dispatch Code:</div>
                <div class="text-muted">Your code is <strong class="badge bg-primary text-white fs-6 px-2 py-1 font-monospace ms-1"><?= e($pending['code']) ?></strong></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-none" onclick="fillOtp('<?= e($pending['code']) ?>')">
                Autofill
            </button>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger py-2 px-3 d-flex align-items-center gap-2 border-0 shadow-sm rounded-3 small mb-3">
                <i class="bi bi-exclamation-circle-fill text-danger flex-shrink-0"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endforeach; ?>

        <!-- OTP Form with 6 Distinct Digits Boxes -->
        <form method="post" id="otpForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="otp_code" id="hiddenOtpInput">

            <div class="mb-4">
                <label class="form-label small fw-semibold text-center d-block mb-3 text-secondary">
                    ENTER 6-DIGIT VERIFICATION CODE
                </label>
                <div class="d-flex justify-content-between gap-2 mb-2" id="otpBoxesContainer">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="text"
                               class="form-control text-center font-monospace fw-bold rounded-3 otp-digit-box"
                               name="otp_digits[<?= $i ?>]"
                               id="digit-<?= $i ?>"
                               maxlength="1"
                               inputmode="numeric"
                               pattern="[0-9]"
                               autocomplete="off"
                               style="font-size: 1.6rem; height: 58px; padding: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);"
                               <?= $i === 0 ? 'autofocus' : '' ?>>
                    <?php endfor; ?>
                </div>
                <div class="text-center">
                    <span class="text-muted small" style="font-size: 0.75rem;"><i class="bi bi-shield-lock me-1"></i> Code expires in 10 minutes</span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm py-3 fw-bold d-flex align-items-center justify-content-center gap-2 mb-3">
                <i class="bi bi-check-circle-fill"></i> Verify &amp; Activate Account
            </button>
        </form>

        <!-- Footer Links -->
        <div class="text-center pt-3 border-top d-flex justify-content-between align-items-center small">
            <a href="<?= url('auth/verify_otp.php?resend=1') ?>" class="text-primary fw-semibold text-decoration-none d-inline-flex align-items-center gap-1">
                <i class="bi bi-arrow-repeat"></i> Resend New Code
            </a>
            <a href="<?= url('auth/register.php') ?>" class="text-muted text-decoration-none d-inline-flex align-items-center gap-1">
                <i class="bi bi-pencil-square"></i> Change Details
            </a>
        </div>
    </div>
</div>

<script>
(function() {
    const boxes = document.querySelectorAll('.otp-digit-box');
    const hiddenInput = document.getElementById('hiddenOtpInput');
    const form = document.getElementById('otpForm');

    function syncHiddenInput() {
        let code = '';
        boxes.forEach(b => code += b.value);
        hiddenInput.value = code;
    }

    window.fillOtp = function(code) {
        if (!code || code.length !== 6) return;
        boxes.forEach((box, idx) => {
            box.value = code[idx] || '';
        });
        syncHiddenInput();
        boxes[5].focus();
    };

    boxes.forEach((box, idx) => {
        box.addEventListener('input', function(e) {
            // Remove non-numeric
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length >= 1) {
                this.value = this.value[0];
                if (idx < 5) {
                    boxes[idx + 1].focus();
                    boxes[idx + 1].select();
                }
            }
            syncHiddenInput();
        });

        box.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && idx > 0) {
                boxes[idx - 1].focus();
                boxes[idx - 1].value = '';
                syncHiddenInput();
            }
        });

        box.addEventListener('paste', function(e) {
            e.preventDefault();
            const pasteData = (e.clipboardData || window.clipboardData).getData('text').trim().replace(/[^0-9]/g, '');
            if (pasteData) {
                fillOtp(pasteData.slice(0, 6));
            }
        });
    });

    form.addEventListener('submit', function() {
        syncHiddenInput();
    });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
