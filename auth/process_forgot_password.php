<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('auth/forgot_password.php');
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
old_set(['email' => $email]);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'Enter a valid email address.');
    redirect('auth/forgot_password.php');
}

$account = find_account_by_email($email);
if ($account) {
    $token = create_password_reset($account);
    $resetLink = url('auth/reset_password.php?token=' . rawurlencode($token));
    $emailSent = send_password_reset_email($account, $resetLink);

    if (!$emailSent) {
        $_SESSION['_reset_link'] = $resetLink;
    }
}

old_clear();
flash_set('success', 'If an account exists for that email, password reset instructions have been sent.');
redirect('auth/forgot_password.php');
