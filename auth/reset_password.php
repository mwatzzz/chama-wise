<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

$token = trim((string) ($_GET['token'] ?? ''));
$account = find_account_by_reset_token($token);
$validToken = $account && is_valid_password_reset($account);

$pageTitle = 'Reset Password | ' . APP_NAME;
$navType = 'auth';
$bodyClass = 'auth-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <?php if (!$validToken): ?>
            <h1>Link Expired</h1>
            <p class="lead-text">This password reset link is invalid or has expired.</p>
            <a class="btn btn-auth d-block text-center" href="<?php echo e(url('auth/forgot_password.php')); ?>">Request a New Link</a>
        <?php else: ?>
            <h1>Create New Password</h1>
            <p class="lead-text">Choose a new password for <?php echo e($account['email']); ?>.</p>
            <form method="post" action="<?php echo e(url('auth/process_reset_password.php')); ?>" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                <div class="mb-3">
                    <label for="password">New Password <span class="required">*</span></label>
                    <input class="form-control" type="password" id="password" name="password" placeholder="At least 8 characters" required>
                </div>
                <div class="mb-3">
                    <label for="password_confirm">Confirm Password <span class="required">*</span></label>
                    <input class="form-control" type="password" id="password_confirm" name="password_confirm" placeholder="Re-enter your password" required>
                </div>
                <button class="btn btn-auth" type="submit">Update Password</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
