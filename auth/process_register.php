<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('auth/register.php');
}

if (!csrf_verify()) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('auth/register.php');
}

$name = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

old_set([
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
]);

$errors = [
    validate_required($name, 'Full name'),
    validate_phone($phone),
    validate_email($email),
    validate_password($password),
    validate_password_confirmation($password, $passwordConfirm),
];

$normalizedPhone = normalize_phone($phone);
if (email_taken($email)) {
    $errors[] = 'An account with that email address already exists.';
}
if (phone_taken($normalizedPhone)) {
    $errors[] = 'An account with that phone number already exists.';
}

$errors = first_error($errors);
if ($errors !== []) {
    flash_set('error', $errors[0]);
    redirect('auth/register.php');
}

$stmt = db()->prepare(
    'INSERT INTO members (name, phone, email, password_hash, joined_date)
     VALUES (:name, :phone, :email, :password_hash, :joined_date)'
);
$stmt->execute([
    'name' => $name,
    'phone' => $normalizedPhone,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'joined_date' => date('Y-m-d'),
]);

old_clear();
flash_set('success', 'Your account has been created. Please log in.');
redirect('auth/login.php');
