<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once dirname(__DIR__) . '/config/app.php';

$email = trim((string) getenv('ADMIN_EMAIL'));
$password = (string) getenv('ADMIN_PASSWORD');
$name = trim((string) getenv('ADMIN_NAME'));

if ($email === '') {
    $email = trim((string) readline('Admin email: '));
}
if ($name === '') {
    $name = trim((string) readline('Admin name: '));
}
if ($password === '') {
    $password = trim((string) readline('Admin password: '));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}
if ($name === '') {
    fwrite(STDERR, "Admin name is required.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$stmt = db()->prepare('SELECT admin_id FROM admins WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    fwrite(STDERR, "An administrator with that email already exists.\n");
    exit(1);
}

$stmt = db()->prepare(
    'INSERT INTO admins (name, email, password_hash, role, status)
     VALUES (:name, :email, :password_hash, \'Admin\', \'active\')'
);
$stmt->execute([
    'name' => $name,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

fwrite(STDOUT, "Administrator created successfully for {$email}.\n");
fwrite(STDOUT, "The password was stored as a one-way hash and was not saved in the project files.\n");
