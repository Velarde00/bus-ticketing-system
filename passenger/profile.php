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
    <h1 class="h3 mb-3">My profile</h1>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-md-7">
            <form method="post" class="panel p-4" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">
                <h2 class="h5 mb-3">Personal details</h2>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label for="first_name" class="form-label">First name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" maxlength="50" value="<?= e($_POST['first_name'] ?? $profile['first_name']) ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label for="last_name" class="form-label">Last name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" maxlength="50" value="<?= e($_POST['last_name'] ?? $profile['last_name']) ?>" required>
                    </div>
                    <div class="col-12">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" value="<?= e($profile['email']) ?>" disabled>
                        <div class="form-text">Your email is your login, so it cannot be changed here.</div>
                    </div>
                    <div class="col-12">
                        <label for="phone_number" class="form-label">Mobile number</label>
                        <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?= e($_POST['phone_number'] ?? $profile['phone_number']) ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">Save changes</button>
            </form>
        </div>

        <div class="col-md-5">
            <form method="post" class="panel p-4" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <h2 class="h5 mb-3">Change password</h2>
                <div class="mb-3">
                    <label for="current_password" class="form-label">Current password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="current_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label">New password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="new_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm new password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="confirm_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-dark">Change password</button>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>