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
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-dark">Manage Administrators</h1>
        <p class="text-muted small mb-0">Create internal administrator accounts or adjust staff access privileges.</p>
    </div>
    <span class="badge bg-warning text-dark border px-3 py-2 rounded-pill"><i class="bi bi-shield-lock-fill me-1"></i> Super Admin Only</span>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger py-2 border-0 shadow-sm small mb-4">
        <ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <form method="post" class="panel bg-white p-4 rounded-4 border shadow-sm" novalidate>
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                <span class="stat-icon-wrapper bg-primary-subtle text-primary"><i class="bi bi-person-plus-fill"></i></span>
                <h2 class="h5 fw-bold mb-0 text-dark">Create New Administrator</h2>
            </div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label small fw-semibold" for="first_name">First name</label><input class="form-control" id="first_name" name="first_name" maxlength="50" value="<?= e($old['first_name'] ?? '') ?>" placeholder="First name" required></div>
                <div class="col-md-6"><label class="form-label small fw-semibold" for="last_name">Last name</label><input class="form-control" id="last_name" name="last_name" maxlength="50" value="<?= e($old['last_name'] ?? '') ?>" placeholder="Last name" required></div>
                <div class="col-md-7"><label class="form-label small fw-semibold" for="email">Email address</label><input class="form-control" type="email" id="email" name="email" maxlength="100" value="<?= e($old['email'] ?? '') ?>" placeholder="admin@domain.com" required></div>
                <div class="col-md-5"><label class="form-label small fw-semibold" for="phone_number">Mobile number</label><input class="form-control" type="tel" id="phone_number" name="phone_number" value="<?= e($old['phone_number'] ?? '') ?>" placeholder="09171234567" required></div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold" for="password">Temporary password</label>
                    <div class="input-group">
                        <input class="form-control" type="password" id="password" name="password" autocomplete="new-password" placeholder="Min. 8 characters" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <div class="form-text" style="font-size:0.75rem;">At least 8 characters with letter &amp; number.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold" for="confirm_password">Confirm password</label>
                    <div class="input-group">
                        <input class="form-control" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" placeholder="Repeat password" required>
                        <button class="btn btn-outline-secondary" type="button" data-password-toggle="confirm_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary rounded-pill shadow-sm px-4 mt-4">
                <i class="bi bi-person-check-fill me-1"></i> Create Administrator
            </button>
        </form>
    </div>
</div>

<div class="panel bg-white rounded-4 border shadow-sm overflow-hidden">
    <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50">
        <h2 class="h6 fw-bold mb-0 text-uppercase letter-spacing-1 text-muted">Administrator Directory</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 custom-modern-table">
            <thead>
                <tr>
                    <th class="ps-4">Administrator</th>
                    <th>Email</th>
                    <th>Mobile</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($admins as $admin): ?>
                <tr>
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-2">
                            <span class="table-avatar-circle"><?= strtoupper(substr($admin['first_name'], 0, 1)) ?></span>
                            <span class="fw-semibold text-dark"><?= e($admin['first_name'] . ' ' . $admin['last_name']) ?></span>
                        </div>
                    </td>
                    <td><span class="small text-muted"><?= e($admin['email']) ?></span></td>
                    <td><span class="small text-muted"><?= e($admin['phone_number']) ?></span></td>
                    <td><?= $admin['role'] === 'SUPER_ADMIN' ? '<span class="badge bg-dark rounded-pill">Super Admin</span>' : '<span class="badge bg-light text-dark border rounded-pill">Admin</span>' ?></td>
                    <td><?= status_badge($admin['account_status']) ?></td>
                    <td><span class="small text-muted"><?= e(fmt_date($admin['created_at'])) ?></span></td>
                    <td class="text-end pe-4">
                        <?php if ($admin['role'] === 'ADMIN'): ?>
                            <form method="post" class="d-inline" data-confirm="<?= $admin['account_status'] === 'ACTIVE' ? 'Disable this administrator account?' : 'Re-enable this administrator account?' ?>">
                                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$admin['user_id'] ?>">
                                <input type="hidden" name="status" value="<?= $admin['account_status'] === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' ?>">
                                <button type="submit" class="btn btn-outline-<?= $admin['account_status'] === 'ACTIVE' ? 'danger' : 'dark' ?> btn-sm rounded-pill px-3">
                                    <?= $admin['account_status'] === 'ACTIVE' ? 'Disable' : 'Re-enable' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border small">Super Admin</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
