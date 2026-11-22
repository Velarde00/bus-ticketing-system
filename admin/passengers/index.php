<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawId = $_POST['id'] ?? '';
    $id = filter_var(is_scalar($rawId) ? $rawId : '', FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$id || !in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
        $errors[] = 'Choose a valid passenger and account status.';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET account_status = :status WHERE user_id = :id AND role = 'PASSENGER'");
        $stmt->execute([':status' => $status, ':id' => $id]);
        set_flash('success', 'Passenger account updated.');
        redirect('admin/passengers/index.php');
    }
}

$passengers = $pdo->query(
    "SELECT user_id, first_name, last_name, email, phone_number, account_status, created_at
     FROM users WHERE role = 'PASSENGER' ORDER BY created_at DESC, user_id DESC"
)->fetchAll();

$pageTitle = 'Manage passengers';
$layout = 'admin';
$activePage = 'passengers';
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4">
    <h1 class="h3 mb-0">Manage passengers</h1>
    <p class="text-muted mb-0">Review registered passengers and enable or disable their accounts.</p>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<div class="panel">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Passenger</th><th>Email</th><th>Mobile</th><th>Joined</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($passengers as $passenger): ?>
                <tr>
                    <td><?= e($passenger['first_name'] . ' ' . $passenger['last_name']) ?></td>
                    <td><?= e($passenger['email']) ?></td>
                    <td><?= e($passenger['phone_number']) ?></td>
                    <td><?= e(fmt_date($passenger['created_at'])) ?></td>
                    <td><?= status_badge($passenger['account_status']) ?></td>
                    <td class="text-end">
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-light btn-sm rounded-circle p-2 shadow-xs border" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 py-1">
                                <li>
                                    <form method="post">
                                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$passenger['user_id'] ?>">
                                        <input type="hidden" name="status" value="<?= $passenger['account_status'] === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' ?>">
                                        <button class="dropdown-item py-2 px-3 small d-flex align-items-center gap-2 <?= $passenger['account_status'] === 'ACTIVE' ? 'text-danger' : 'text-success' ?>" type="submit">
                                            <i class="bi <?= $passenger['account_status'] === 'ACTIVE' ? 'bi-person-x text-danger' : 'bi-person-check text-success' ?>"></i>
                                            <?= $passenger['account_status'] === 'ACTIVE' ? 'Deactivate Account' : 'Activate Account' ?>
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$passengers): ?><tr><td colspan="6" class="text-center text-muted py-4">No passenger accounts yet. Passengers can create accounts from the sign-up page.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
