<?php
/**
 * includes/header.php
 * Set these BEFORE including it:
 *   $pageTitle  = 'Login';
 *   $layout     = 'public' or 'admin';
 *   $activePage = 'dashboard'; (admin sidebar highlight)
 */
$pageTitle  = $pageTitle  ?? APP_NAME;
$layout     = $layout     ?? 'public';
$activePage = $activePage ?? '';
$flash      = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="layout-<?= e($layout) ?>">
<?php if ($layout === 'admin'): ?>
    <div class="admin-shell">
        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar d-lg-none">
                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar">
                    <i class="bi bi-list"></i> Menu
                </button>
                <span class="brand-mark"><?= e(APP_NAME) ?></span>
            </div>
            <main class="admin-main">
<?php else: ?>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="site-main">
<?php endif; ?>

<?php if ($flash): ?>
    <div class="<?= $layout === 'admin' ? '' : 'container pt-3' ?>">
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
<?php endif; ?>
