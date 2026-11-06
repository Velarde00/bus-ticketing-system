<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('SUPER_ADMIN');

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'create') {
        $first = is_string($_POST['first_name'] ?? null) ? trim($_POST['first_name']) : '';
        $last = is_string($_POST['last_name'] ?? null) ? trim($_POST['last_name']) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $phone = is_string($_POST['phone_number'] ?? null) ? preg_replace('/[\s-]/', '', $_POST['phone_number']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        $old = ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone_number' => $phone];

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
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (first_name, last_name, email, phone_number, password_hash, role, account_status)
                     VALUES (:first, :last, :email, :phone, :hash, 'ADMIN', 'ACTIVE')"
                );
                $stmt->execute([
                    ':first' => $first,
                    ':last' => $last,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                set_flash('success', 'Administrator account created. Share the temporary password securely.');
                redirect('admin/administrators.php');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = 'An account with that email address already exists.';
                } else {
                    error_log('admin administrator create: ' . $e->getMessage());
                    $errors[] = 'Could not create the administrator account. Please try again.';
                }
            }
        }
    } elseif ($action === 'toggle') {
        $rawId = $_POST['user_id'] ?? '';
        $userId = filter_var(is_scalar($rawId) ? $rawId : '', FILTER_VALIDATE_INT);
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if (!$userId || !in_array($status, ['ACTIVE', 'INACTIVE'], true) || $userId === (int)$_SESSION['user_id']) {
            $errors[] = 'Choose another administrator account and a valid account status.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE users SET account_status = :status
                 WHERE user_id = :id AND role = 'ADMIN'"
            );
            $stmt->execute([':status' => $status, ':id' => $userId]);
            if ($stmt->rowCount() !== 1) {
                $errors[] = 'That administrator account could not be updated.';
            } else {
                set_flash('success', $status === 'INACTIVE' ? 'Administrator account disabled.' : 'Administrator account re-enabled.');
                redirect('admin/administrators.php');
            }
        }
    } else {
        $errors[] = 'Choose a valid administrator action.';
    }
}

$admins = $pdo->query(
    "SELECT user_id, first_name, last_name, email, phone_number, role, account_status, created_at
     FROM users WHERE role IN ('ADMIN', 'SUPER_ADMIN')
     ORDER BY CASE role WHEN 'SUPER_ADMIN' THEN 0 ELSE 1 END, last_name, first_name"
)->fetchAll();
$pageTitle = 'Manage administrators';
$layout = 'admin';
$activePage = 'administrators';
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-3">
    <h1 class="h3 mb-0">Manage administrators</h1>
    <p class="text-muted mb-0">Create administrator accounts, or disable and re-enable administrator access.</p>
</div>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="mb-3">
    <form method="post" class="panel p-3 p-lg-4" novalidate>
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <h2 class="h5 mb-3">Create administrator</h2>
            <div class="row g-3">
                <div class="col-md-6 col-xl-3"><label class="form-label" for="first_name">First name</label><input class="form-control" id="first_name" name="first_name" maxlength="50" value="<?= e($old['first_name'] ?? '') ?>" required></div>
                <div class="col-md-6 col-xl-3"><label class="form-label" for="last_name">Last name</label><input class="form-control" id="last_name" name="last_name" maxlength="50" value="<?= e($old['last_name'] ?? '') ?>" required></div>
                <div class="col-md-7 col-xl-3"><label class="form-label" for="email">Email</label><input class="form-control" type="email" id="email" name="email" maxlength="100" value="<?= e($old['email'] ?? '') ?>" required></div>
                <div class="col-md-5 col-xl-3"><label class="form-label" for="phone_number">Mobile number</label><input class="form-control" type="tel" id="phone_number" name="phone_number" value="<?= e($old['phone_number'] ?? '') ?>" placeholder="09171234567" required></div>
                <div class="col-md-6"><label class="form-label" for="password">Temporary password</label><div class="input-group"><input class="form-control" type="password" id="password" name="password" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div><div class="form-text">At least 8 characters, including a letter and a number.</div></div>
                <div class="col-md-6"><label class="form-label" for="confirm_password">Confirm password</label><div class="input-group"><input class="form-control" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="confirm_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
            </div>
            <div class="administrator-form-actions">
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Create administrator</button>
            </div>
    </form>
</div>

<div class="panel">
    <div class="panel-head"><h2 class="h5 mb-0">Administrator accounts</h2></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Administrator</th><th>Email</th><th>Mobile</th><th>Role</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($admins as $admin): ?>
                <tr>
                    <td><?= e($admin['first_name'] . ' ' . $admin['last_name']) ?></td>
                    <td><?= e($admin['email']) ?></td>
                    <td><?= e($admin['phone_number']) ?></td>
                    <td><?= $admin['role'] === 'SUPER_ADMIN' ? '<span class="badge text-bg-dark">Super admin</span>' : 'Admin' ?></td>
                    <td><?= status_badge($admin['account_status']) ?></td>
                    <td><?= e(fmt_date($admin['created_at'])) ?></td>
                    <td class="text-end">
                        <?php if ($admin['role'] === 'ADMIN'): ?>
                            <form method="post" class="d-inline" data-confirm="<?= $admin['account_status'] === 'ACTIVE' ? 'Disable this administrator account?' : 'Re-enable this administrator account?' ?>">
                                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$admin['user_id'] ?>">
                                <input type="hidden" name="status" value="<?= $admin['account_status'] === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' ?>">
                                <button type="submit" class="btn btn-outline-<?= $admin['account_status'] === 'ACTIVE' ? 'danger' : 'dark' ?> btn-sm"><?= $admin['account_status'] === 'ACTIVE' ? 'Disable' : 'Re-enable' ?></button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted small">Protected</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
