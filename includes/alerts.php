<?php
$error = flash_get('error');
$success = flash_get('success');
$info = flash_get('info');
?>
<?php if ($error || $success || $info): ?>
    <div class="container pt-3">
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success" role="alert"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($info): ?>
            <div class="alert alert-info" role="alert"><?php echo e($info); ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
