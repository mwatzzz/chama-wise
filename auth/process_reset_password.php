<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('auth/forgot_password.php');
}

$token = trim((string) ($_POST['token'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
$account = find_account_by_reset_token($token);
$validToken = $account && !empty($account['reset_expires']) && new DateTimeImmutable($account['reset_expires']) >= new DateTimeImmutable();

if (!$validToken) {
    flash_set('error', 'This password reset link is invalid or has expired.');
    redirect('auth/forgot_password.php');
}

$error = validate_password($password) ?? validate_password_confirmation($password, $passwordConfirm);
if ($error) {
    flash_set('error', $error);
    redirect('auth/reset_password.php?token=' . rawurlencode($token));
}

update_password_hash($account, $password);
clear_password_reset($account);
flash_set('success', 'Your password has been updated. You can now log in.');
redirect('auth/login.php');
