<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('ADMIN');

function save_payment_qr_image(array $file, string $option): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('The QR image could not be uploaded.');
    }
    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('QR images must be 2 MB or smaller.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $imageInfo = getimagesize($file['tmp_name']);
    if (!isset($types[$mime]) || !$imageInfo || ($imageInfo['mime'] ?? '') !== $mime
        || $imageInfo[0] > 3000 || $imageInfo[1] > 3000) {
        throw new RuntimeException('Upload a PNG, JPEG, or WebP QR image no larger than 3000 by 3000 pixels.');
    }

    $directory = __DIR__ . '/../uploads/payment_qr';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The QR image directory could not be created.');
    }
    $filename = strtolower($option) . '-' . bin2hex(random_bytes(16)) . '.' . $types[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('The QR image could not be saved.');
    }
    return $filename;
}

function delete_payment_qr_image(?string $filename): void
{
    if ($filename && basename($filename) === $filename) {
        $path = __DIR__ . '/../uploads/payment_qr/' . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }
}

$paymentOptions = [
    'GCASH' => 'GCash',
    'MAYA' => 'Maya',
    'MARIBANK' => 'MariBank',
    'BANK_TRANSFER' => 'Online bank transfer (BPI / UnionBank)',
];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'save_payment_instructions') {
        $submitted = [];
        $uploadedImages = [];
        $oldImages = [];
        $enabledOptions = is_array($_POST['enabled'] ?? null) ? $_POST['enabled'] : [];
        $removeImages = is_array($_POST['remove_qr'] ?? null) ? $_POST['remove_qr'] : [];
        $currentRows = [];
        foreach ($pdo->query('SELECT payment_option, qr_image_path FROM manual_payment_instructions')->fetchAll() as $row) {
            $currentRows[$row['payment_option']] = $row;
        }
        $qrUploads = $_FILES['qr_image'] ?? [];
        foreach ($paymentOptions as $option => $label) {
            $names = is_array($_POST['account_name'] ?? null) ? $_POST['account_name'] : [];
            $numbers = is_array($_POST['account_number'] ?? null) ? $_POST['account_number'] : [];
            $notes = is_array($_POST['instructions'] ?? null) ? $_POST['instructions'] : [];
            $accountName = trim(is_string($names[$option] ?? null) ? $names[$option] : '');
            $accountNumber = trim(is_string($numbers[$option] ?? null) ? $numbers[$option] : '');
            $instructions = trim(is_string($notes[$option] ?? null) ? $notes[$option] : '');
            $isEnabled = isset($enabledOptions[$option]) ? 1 : 0;
            $qrImage = $currentRows[$option]['qr_image_path'] ?? null;
            $uploadError = $qrUploads['error'][$option] ?? UPLOAD_ERR_NO_FILE;
            if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                try {
                    $qrImage = save_payment_qr_image([
                        'error' => $uploadError,
                        'tmp_name' => $qrUploads['tmp_name'][$option] ?? '',
                        'size' => $qrUploads['size'][$option] ?? 0,
                    ], $option);
                    $uploadedImages[] = $qrImage;
                } catch (RuntimeException $e) {
                    $errors[] = $label . ': ' . $e->getMessage();
                }
            } elseif (isset($removeImages[$option])) {
                $qrImage = null;
            }
            if ($isEnabled && ($accountName === '' || $accountNumber === '')) {
                $errors[] = $label . ' needs an account name and account number before it can be enabled.';
            }
            if (strlen($accountName) > 120 || strlen($accountNumber) > 120 || strlen($instructions) > 2000) {
                $errors[] = $label . ' payment instructions are too long.';
            }
            if (($currentRows[$option]['qr_image_path'] ?? null) !== $qrImage) {
                $oldImages[] = $currentRows[$option]['qr_image_path'] ?? null;
            }
            $submitted[$option] = [$accountName, $accountNumber, $instructions, $qrImage, $isEnabled];
        }
        if (!$errors) {
            $saved = false;
            try {
                $pdo->beginTransaction();
                $save = $pdo->prepare(
                    "INSERT INTO manual_payment_instructions
                     (payment_option, account_name, account_number, instructions, qr_image_path, is_enabled)
                     VALUES (:option, :name, :number, :instructions, :qr_image, :enabled)
                     ON DUPLICATE KEY UPDATE account_name = VALUES(account_name),
                         account_number = VALUES(account_number), instructions = VALUES(instructions),
                         qr_image_path = VALUES(qr_image_path),
                         is_enabled = VALUES(is_enabled)"
                );
                foreach ($submitted as $option => [$name, $number, $instructions, $qrImage, $enabled]) {
                    $save->execute([
                        ':option' => $option,
                        ':name' => $name,
                        ':number' => $number,
                        ':instructions' => $instructions,
                        ':qr_image' => $qrImage,
                        ':enabled' => $enabled,
                    ]);
                }
                $pdo->commit();
                $saved = true;
                foreach ($oldImages as $oldImage) {
                    delete_payment_qr_image($oldImage);
                }
                set_flash('success', 'Transfer instructions saved.');
                redirect('admin/settings.php#payment-instructions');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('admin payment instructions: ' . $e->getMessage());
                $errors[] = 'Could not save transfer instructions. Please try again.';
            }
        }
        if ($errors) {
            foreach ($uploadedImages as $uploadedImage) {
                delete_payment_qr_image($uploadedImage);
            }
        }
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

$savedPaymentOptions = [];
foreach ($pdo->query('SELECT * FROM manual_payment_instructions')->fetchAll() as $paymentOption) {
    $savedPaymentOptions[$paymentOption['payment_option']] = $paymentOption;
}

$pageTitle = 'Account settings';
$layout = 'admin';
$activePage = 'settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-3"><h1 class="h3 mb-0">Account settings</h1><p class="text-muted mb-0">Change the administrator password used to sign in.</p></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="panel settings-form p-3 p-lg-4" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="settings-form-grid">
        <div><label for="current_password" class="form-label">Current password</label><div class="input-group"><input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="current_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
        <div><label for="new_password" class="form-label">New password</label><div class="input-group"><input class="form-control" type="password" id="new_password" name="new_password" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="new_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
        <div><label for="confirm_password" class="form-label">Confirm new password</label><div class="input-group"><input class="form-control" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="confirm_password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
    </div>
    <div class="settings-form-footer">
        <p class="form-text mb-0">At least 8 characters, including a letter and a number.</p>
        <button class="btn btn-primary" type="submit"><i class="bi bi-shield-lock me-1"></i>Change password</button>
    </div>
</form>
<section class="panel p-3 p-lg-4 mt-4" id="payment-instructions">
    <div class="mb-3">
        <h2 class="h5 mb-1">Transfer payment instructions</h2>
        <p class="text-muted mb-0">Set the receiving accounts passengers will use after reserving seats.</p>
    </div>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_payment_instructions">
        <?php foreach ($paymentOptions as $option => $label): ?>
            <?php $saved = $savedPaymentOptions[$option] ?? []; ?>
            <fieldset class="border rounded p-3 mb-3">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                    <legend class="h6 mb-0"><?= e($label) ?></legend>
                    <label class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="enabled[<?= e($option) ?>]" value="1" <?= !empty($saved['is_enabled']) ? 'checked' : '' ?>>
                        <span class="form-check-label">Enabled</span>
                    </label>
                </div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="account-name-<?= e($option) ?>">Account name</label><input class="form-control" id="account-name-<?= e($option) ?>" name="account_name[<?= e($option) ?>]" maxlength="120" value="<?= e($saved['account_name'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="account-number-<?= e($option) ?>">Account number / mobile</label><input class="form-control" id="account-number-<?= e($option) ?>" name="account_number[<?= e($option) ?>]" maxlength="120" value="<?= e($saved['account_number'] ?? '') ?>"></div>
                    <div class="col-12"><label class="form-label" for="instructions-<?= e($option) ?>">Additional instructions</label><textarea class="form-control" id="instructions-<?= e($option) ?>" name="instructions[<?= e($option) ?>]" rows="2" maxlength="2000"><?= e($saved['instructions'] ?? '') ?></textarea></div>
                    <div class="col-md-6"><label class="form-label" for="qr-image-<?= e($option) ?>">Payment QR image</label><input class="form-control" type="file" id="qr-image-<?= e($option) ?>" name="qr_image[<?= e($option) ?>]" accept="image/png,image/jpeg,image/webp"><div class="form-text">PNG, JPEG, or WebP; maximum 2 MB. Upload the QR from your payment app.</div></div>
                    <div class="col-md-6 d-flex align-items-center gap-3">
                        <?php if (!empty($saved['qr_image_path'])): ?><img src="<?= url('actions/payment_qr.php?option=' . urlencode($option)) ?>" alt="Current <?= e($label) ?> payment QR" width="96" height="96" class="border rounded p-1" style="object-fit:contain"><?php endif; ?>
                        <?php if (!empty($saved['qr_image_path'])): ?><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="remove_qr[<?= e($option) ?>]" value="1"><span class="form-check-label">Remove QR</span></label><?php endif; ?>
                    </div>
                </div>
            </fieldset>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-1"></i>Save transfer instructions</button>
    </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
