<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/dashboard.php' : 'member/dashboard.php');
}

$pageTitle = 'Create Account | ChamaWise';
$navType = 'auth';
$bodyClass = 'auth-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <h1>Create Your ChamaWise Account</h1>
        <p class="lead-text">Join your chama's digital financial management platform.</p>
        <form method="post" action="<?php echo e(url('auth/process_register.php')); ?>" novalidate>
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label for="name">Full Name <span class="required">*</span></label>
                <input class="form-control" id="name" name="name" value="<?php echo e(old('name')); ?>" placeholder="Jane Wanjiku" required>
            </div>
            <div class="mb-3">
                <label for="phone">Phone Number <span class="required">*</span></label>
                <input class="form-control" id="phone" name="phone" value="<?php echo e(old('phone')); ?>" placeholder="0712 345 678" required>
            </div>
            <div class="mb-3">
                <label for="email">Email Address <span class="required">*</span></label>
                <input class="form-control" type="email" id="email" name="email" value="<?php echo e(old('email')); ?>" placeholder="you@example.com" required>
            </div>
            <div class="mb-3">
                <label for="password">Password <span class="required">*</span></label>
                <div class="password-wrap">
                    <input class="form-control" type="password" id="password" name="password" placeholder="Create a password" required>
                    <button type="button" class="toggle-password" aria-label="Show password">&#128065;</button>
                </div>
            </div>
            <div class="mb-3">
                <label for="password_confirm">Confirm Password <span class="required">*</span></label>
                <div class="password-wrap">
                    <input class="form-control" type="password" id="password_confirm" name="password_confirm" placeholder="Re-enter your password" required>
                    <button type="button" class="toggle-password" aria-label="Show password">&#128065;</button>
                </div>
            </div>
            <button class="btn btn-auth" type="submit">Create Account</button>
        </form>
        <p class="auth-switch mt-4">Already have an account? <a href="<?php echo e(url('auth/login.php')); ?>">Login</a></p>
    </div>
</div>
<?php
old_clear();
require ROOT_PATH . '/includes/footer.php';
?>
