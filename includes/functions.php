<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = APP_BASE === '' ? '' : APP_BASE;
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): void
{
    if (preg_match('#^https?://#i', $path) === 1) {
        header('Location: ' . $path);
        exit;
    }

    header('Location: ' . url($path));
    exit;
}

function flash_set(string $key, string $message): void
{
    $_SESSION['_flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }

    $message = (string) $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $message;
}

function old(string $key, string $default = ''): string
{
    $value = $_SESSION['_old'][$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function old_set(array $data): void
{
    $_SESSION['_old'] = $data;
}

function old_clear(): void
{
    unset($_SESSION['_old']);
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['_csrf'] ?? '';
    return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        return null;
    }

    return [
        'user_id' => (int) $_SESSION['user_id'],
        'name' => (string) ($_SESSION['name'] ?? ''),
        'email' => (string) ($_SESSION['email'] ?? ''),
        'role' => (string) $_SESSION['role'],
    ];
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_member(): bool
{
    return (current_user()['role'] ?? '') === 'Member';
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'Admin';
}

function establish_session(int $userId, string $name, string $email, string $role): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['name'] = $name;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $role;
}

function find_account_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT member_id AS user_id, name, email, password_hash, role, status FROM members WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $member = $stmt->fetch();
    if ($member) {
        $member['account_type'] = 'Member';
        return $member;
    }

    $stmt = db()->prepare('SELECT admin_id AS user_id, name, email, password_hash, role, status FROM admins WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch();
    if ($admin) {
        $admin['account_type'] = 'Admin';
        return $admin;
    }

    return null;
}

function email_taken(string $email, ?int $ignoreId = null, ?string $ignoreRole = null): bool
{
    $stmt = db()->prepare('SELECT member_id FROM members WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $member = $stmt->fetch();
    if ($member && !($ignoreRole === 'Member' && (int) $member['member_id'] === $ignoreId)) {
        return true;
    }

    $stmt = db()->prepare('SELECT admin_id FROM admins WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch();
    if ($admin && !($ignoreRole === 'Admin' && (int) $admin['admin_id'] === $ignoreId)) {
        return true;
    }

    return false;
}

function phone_taken(string $phone): bool
{
    $stmt = db()->prepare('SELECT member_id FROM members WHERE phone = :phone LIMIT 1');
    $stmt->execute(['phone' => $phone]);
    return (bool) $stmt->fetch();
}

function ensure_contributions_table(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS payment_methods (
            payment_method_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(80) NOT NULL,
            code VARCHAR(30) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (payment_method_id),
            UNIQUE KEY uq_payment_methods_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $paymentMethodStmt = db()->prepare(
        'INSERT IGNORE INTO payment_methods (name, code)
         VALUES (:name, :code)'
    );
    foreach ([
        ['name' => 'Cash', 'code' => 'cash'],
        ['name' => 'Bank Transfer', 'code' => 'bank_transfer'],
        ['name' => 'Mobile Money', 'code' => 'mobile_money'],
    ] as $paymentMethod) {
        $paymentMethodStmt->execute($paymentMethod);
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS contributions (
            contribution_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12, 2) NOT NULL,
            payment_method_id INT UNSIGNED NULL,
            contribution_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'recorded\',
            reference VARCHAR(80) NULL,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (contribution_id),
            KEY idx_contributions_member (member_id),
            KEY idx_contributions_payment_method (payment_method_id),
            KEY idx_contributions_date (contribution_date),
            CONSTRAINT fk_contributions_payment_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (payment_method_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $column = db()->query("SHOW COLUMNS FROM members LIKE 'profile_picture'")->fetch();
    if (!$column) {
        db()->exec('ALTER TABLE members ADD COLUMN profile_picture VARCHAR(255) NULL AFTER joined_date');
    }

    $paymentColumn = db()->query("SHOW COLUMNS FROM contributions LIKE 'payment_method_id'")->fetch();
    if (!$paymentColumn) {
        db()->exec('ALTER TABLE contributions ADD COLUMN payment_method_id INT UNSIGNED NULL AFTER amount');
        db()->exec('ALTER TABLE contributions ADD KEY idx_contributions_payment_method (payment_method_id)');
        db()->exec('ALTER TABLE contributions ADD CONSTRAINT fk_contributions_payment_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (payment_method_id)');
    }

    $checked = true;
}
