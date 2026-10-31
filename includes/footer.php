</main>
<?php if (($navType ?? 'public') === 'public'): ?>
<footer class="site-footer">
    <div class="container">
        <div class="footer-brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 32 32" width="32" height="32">
                    <circle cx="16" cy="16" r="16" fill="#0b5c3a"></circle>
                    <path d="M10.5 16.5l3.2 3.2 7.8-8.2" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
            </span>
            <div>
                <strong><?php echo e(APP_NAME); ?></strong>
                <p>Smarter Chamas. Stronger Futures.</p>
            </div>
        </div>
        <div class="footer-links">
            <a href="<?php echo e(url('index.php#about')); ?>">About</a>
            <a href="<?php echo e(url('index.php#features')); ?>">Features</a>
            <a href="<?php echo e(url('index.php#how-it-works')); ?>">How It Works</a>
            <a href="<?php echo e(url('index.php#contact')); ?>">Contact</a>
            <a href="<?php echo e(url('auth/login.php')); ?>">Login</a>
            <a href="<?php echo e(url('auth/register.php')); ?>">Create Account</a>
        </div>
        <p class="copyright">&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?>. All rights reserved.</p>
    </div>
</footer>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo e(asset('js/script.js') . '?v=' . (string) filemtime(ROOT_PATH . '/assets/js/script.js')); ?>"></script>
<script src="<?php echo e(asset('js/auth.js')); ?>"></script>
</body>
</html>
