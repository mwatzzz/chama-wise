<?php
$navType = $navType ?? 'public';
$user = current_user();
?>
<header class="site-header">
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo e($user ? (is_admin() ? url('admin/dashboard.php') : url('member/dashboard.php')) : url('index.php')); ?>">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 32 32" width="32" height="32">
                        <circle cx="16" cy="16" r="16" fill="#0b5c3a"></circle>
                        <path d="M10.5 16.5l3.2 3.2 7.8-8.2" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                </span>
                <span class="brand-name"><?php echo e(APP_NAME); ?></span>
            </a>

            <?php if ($navType === 'auth'): ?>
                <a class="back-home" href="<?php echo e(url('index.php')); ?>">&#8592; Back home</a>
            <?php else: ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-3">
                        <?php if ($navType === 'public'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?php echo e(url('index.php#about')); ?>">About</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?php echo e(url('index.php#features')); ?>">Features</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?php echo e(url('index.php#how-it-works')); ?>">How It Works</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?php echo e(url('index.php#contact')); ?>">Contact</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?php echo e(url('auth/login.php')); ?>">Login</a></li>
                            <li class="nav-item"><a class="btn btn-brand" href="<?php echo e(url('auth/register.php')); ?>">Create Account</a></li>
                        <?php elseif ($navType === 'member'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?php echo e(url('member/dashboard.php')); ?>">Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?php echo e(url('auth/logout.php')); ?>">Logout</a></li>
                        <?php elseif ($navType === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>">Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?php echo e(url('auth/logout.php')); ?>">Logout</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </nav>
</header>
