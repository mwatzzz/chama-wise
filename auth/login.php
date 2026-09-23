<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/dashboard.php' : 'member/dashboard.php');
}

$pageTitle = 'Login | ChamaWise';
$navType = 'auth';
$bodyClass = 'auth-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <h1>Welcome Back</h1>
        <p class="lead-text">Sign in to your ChamaWise account.</p>
        <form method="post" action="<?php echo e(url('auth/process_login.php')); ?>" novalidate>
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label for="email">Email Address <span class="required">*</span></label>
                <input class="form-control" type="email" id="email" name="email" value="<?php echo e(old('email')); ?>" placeholder="you@example.com" required>
            </div>
            <div class="mb-3">
                <label for="password">Password <span class="required">*</span></label>
                <div class="password-wrap">
                    <input class="form-control" type="password" id="password" name="password" placeholder="Enter your password" required>
                    <button type="button" class="toggle-password" aria-label="Show password">&#128065;</button>
                </div>
            </div>
            <div class="auth-meta">
                <label class="d-flex align-items-center gap-2 mb-0">
                    <input type="checkbox" name="remember" value="1"> Remember me
                </label>
                <a href="<?php echo e(url('auth/forgot_password.php')); ?>">Forgot Password?</a>
            </div>
            <button class="btn btn-auth" type="submit">Login</button>
        </form>
        <p class="auth-switch mt-4">Don't have an account? <a href="<?php echo e(url('auth/register.php')); ?>">Create an account</a></p>
        <p class="secure-note">Your account information is protected.</p>
    </div>
</div>
<?php
old_clear();
require ROOT_PATH . '/includes/footer.php';
?>
