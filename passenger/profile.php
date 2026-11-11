<?php
/**
 * passenger/profile.php
 * Update name and phone number, or change the password.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$uid    = (int)$_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'profile') {
            $first = trim($_POST['first_name'] ?? '');
            $last  = trim($_POST['last_name'] ?? '');
            $phone = preg_replace('/[\s-]/', '', $_POST['phone_number'] ?? '');

            if (!preg_match("/^[\p{L}][\p{L} .'-]{1,49}$/u", $first)) { $errors[] = 'Enter a valid first name (2 to 50 letters).'; }
            if (!preg_match("/^[\p{L}][\p{L} .'-]{1,49}$/u", $last))  { $errors[] = 'Enter a valid last name (2 to 50 letters).'; }
            if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone))      { $errors[] = 'Enter a Philippine mobile number, like 09171234567.'; }

            if (!$errors) {
                $pdo->prepare('UPDATE users SET first_name = :f, last_name = :l, phone_number = :p WHERE user_id = :id')
                    ->execute([':f' => $first, ':l' => $last, ':p' => $phone, ':id' => $uid]);
                $_SESSION['user_name'] = $first . ' ' . $last;
                set_flash('success', 'Profile updated.');
                redirect('passenger/profile.php');
            }
        } elseif ($action === 'password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $row = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id');
            $row->execute([':id' => $uid]);
            $hash = $row->fetchColumn();

            if (!$hash || !password_verify($current, $hash)) { $errors[] = 'Your current password is incorrect.'; }
            if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
                $errors[] = 'New password must be at least 8 characters with a letter and a number.';
            }
            if ($new !== $confirm) { $errors[] = 'The new passwords do not match.'; }

            if (!$errors) {
                $pdo->prepare('UPDATE users SET password_hash = :h WHERE user_id = :id')
                    ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $uid]);
                session_regenerate_id(true);
                set_flash('success', 'Password changed.');
                redirect('passenger/profile.php');
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT first_name, last_name, email, phone_number, created_at FROM users WHERE user_id = :id');
$stmt->execute([':id' => $uid]);
$profile = $stmt->fetch();

$pageTitle = 'My profile';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4" style="max-width: 860px;">
    <div class="mb-4">
        <h1 class="h3 fw-bold mb-1">Account &amp; Profile</h1>
        <p class="text-muted small mb-0">Update your passenger profile details and manage your security credentials.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 border-0 shadow-sm small mb-4">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-md-7">
            <form method="post" class="panel bg-white p-4 rounded-4 border shadow-sm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">
                <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                    <span class="stat-icon-wrapper bg-primary-subtle text-primary"><i class="bi bi-person-gear"></i></span>
                    <h2 class="h5 fw-bold mb-0 text-dark">Personal Information</h2>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="first_name" class="form-label small fw-semibold">First name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" maxlength="50" value="<?= e($_POST['first_name'] ?? $profile['first_name']) ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label for="last_name" class="form-label small fw-semibold">Last name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" maxlength="50" value="<?= e($_POST['last_name'] ?? $profile['last_name']) ?>" required>
                    </div>
                    <div class="col-12">
                        <label for="email" class="form-label small fw-semibold">Email address</label>
                        <input type="email" class="form-control bg-light" id="email" value="<?= e($profile['email']) ?>" disabled>
                        <div class="form-text" style="font-size:0.75rem;">Your registered email is your login identity and cannot be edited.</div>
                    </div>
                    <div class="col-12">
                        <label for="phone_number" class="form-label small fw-semibold">Mobile number</label>
                        <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?= e($_POST['phone_number'] ?? $profile['phone_number']) ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary rounded-pill shadow-sm px-4 mt-4">
                    <i class="bi bi-check2 me-1"></i> Save Changes
                </button>
            </form>
        </div>

        <div class="col-md-5">
            <form method="post" class="panel bg-white p-4 rounded-4 border shadow-sm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                    <span class="stat-icon-wrapper bg-warning-subtle text-warning"><i class="bi bi-shield-lock"></i></span>
                    <h2 class="h5 fw-bold mb-0 text-dark">Security</h2>
                </div>
                <div class="mb-3">
                    <label for="current_password" class="form-label small fw-semibold">Current password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="current_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label small fw-semibold">New password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="new_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label small fw-semibold">Confirm new password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="confirm_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-dark rounded-pill w-100 mt-2">
                    <i class="bi bi-key me-1"></i> Update Password
                </button>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>