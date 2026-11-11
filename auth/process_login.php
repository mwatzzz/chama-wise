<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('auth/login.php');
}

if (!csrf_verify()) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('auth/login.php');
}

prune_login_attempts();

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');

old_set(['email' => $email]);

if (login_is_rate_limited($email)) {
    flash_set('error', 'Too many unsuccessful sign-in attempts. Please wait 15 minutes before trying again.');
    redirect('auth/login.php');
}

$account = find_account_by_email($email);
if (!$account || !password_verify($password, (string) $account['password_hash'])) {
    record_login_failure($email);
    flash_set('error', 'The email or password is incorrect.');
    redirect('auth/login.php');
}

if (($account['status'] ?? '') !== 'active') {
    record_login_failure($email);
    flash_set('error', 'Your account is not active. Please contact an administrator.');
    redirect('auth/login.php');
}

clear_login_failures($email);
establish_session(
    (int) $account['user_id'],
    (string) $account['name'],
    (string) $account['email'],
    (string) $account['role']
);

if (!empty($_POST['remember'])) {
    remember_user((int) $account['user_id'], (string) $account['role']);
}

old_clear();
flash_set('success', 'You have logged in successfully.');
redirect($account['account_type'] === 'Admin' ? 'admin/dashboard.php' : 'member/dashboard.php');
