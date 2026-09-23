<?php
$pageTitle = $pageTitle ?? APP_NAME;
$bodyClass = $bodyClass ?? '';
$navType = $navType ?? 'public';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo e(asset('css/style.css') . '?v=' . (string) filemtime(ROOT_PATH . '/assets/css/style.css')); ?>" rel="stylesheet">
    <link href="<?php echo e(asset('css/auth.css') . '?v=' . (string) filemtime(ROOT_PATH . '/assets/css/auth.css')); ?>" rel="stylesheet">
</head>
<body class="<?php echo e($bodyClass); ?>">
<?php require ROOT_PATH . '/includes/navbar.php'; ?>
<main>
<?php require ROOT_PATH . '/includes/alerts.php'; ?>
