<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/dashboard.php' : 'member/dashboard.php');
}

$resetLink = $_SESSION['_reset_link'] ?? null;
unset($_SESSION['_reset_link']);

$pageTitle = 'Forgot Password | ' . APP_NAME;
$navType = 'auth';
$bodyClass = 'auth-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <h1>Forgot Password?</h1>
        <p class="lead-text">Enter your email address and we will help you create a new password.</p>
        <form method="post" action="<?php echo e(url('auth/process_forgot_password.php')); ?>" novalidate>
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label for="email">Email Address <span class="required">*</span></label>
                <input class="form-control" type="email" id="email" name="email" value="<?php echo e(old('email')); ?>" placeholder="you@example.com" required>
            </div>
            <button class="btn btn-auth" type="submit">Continue</button>
        </form>
        <?php if ($resetLink): ?>
            <div class="reset-link-box">
                <strong>Local development reset link</strong>
                <p>Open this link to choose a new password:</p>
                <a href="<?php echo e($resetLink); ?>"><?php echo e($resetLink); ?></a>
            </div>
        <?php endif; ?>
        <p class="auth-switch mt-4"><a href="<?php echo e(url('auth/login.php')); ?>">Back to login</a></p>
    </div>
</div>
<?php
old_clear();
require ROOT_PATH . '/includes/footer.php';
?>
