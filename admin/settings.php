<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('ADMIN');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :id AND role IN ('ADMIN', 'SUPER_ADMIN')");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) {
            $errors[] = 'Your current password is incorrect.';
        }
        if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $errors[] = 'New password must be at least 8 characters with a letter and a number.';
        }
        if ($new !== $confirm) {
            $errors[] = 'The new passwords do not match.';
        }
        if (!$errors) {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id AND role IN ('ADMIN', 'SUPER_ADMIN')");
            $stmt->execute([':hash' => password_hash($new, PASSWORD_DEFAULT), ':id' => $_SESSION['user_id']]);
            session_regenerate_id(true);
            set_flash('success', 'Administrator password changed.');
            redirect('admin/settings.php');
        }
    }
}

$pageTitle = 'Account settings';
$layout = 'admin';
$activePage = 'settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-4"><h1 class="h3 mb-0">Account settings</h1><p class="text-muted mb-0">Change the administrator password used to sign in.</p></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="panel p-4" style="max-width: 560px;" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3"><label for="current_password" class="form-label">Current password</label><div class="input-group"><input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="current_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
    <div class="mb-3"><label for="new_password" class="form-label">New password</label><div class="input-group"><input class="form-control" type="password" id="new_password" name="new_password" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="new_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
    <div class="mb-3"><label for="confirm_password" class="form-label">Confirm new password</label><div class="input-group"><input class="form-control" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="confirm_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
    <p class="form-text">At least 8 characters, including a letter and a number.</p>
    <button class="btn btn-primary" type="submit">Change password</button>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
