<?php

declare(strict_types=1);

function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('error', 'Please log in to continue.');
        redirect('auth/login.php');
    }
}

function require_member(): void
{
    require_login();
    if (!is_member()) {
        flash_set('error', 'You are not authorised to view that page.');
        redirect('admin/dashboard.php');
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash_set('error', 'You are not authorised to view that page.');
        redirect('member/dashboard.php');
    }
}

function remember_user(int $userId, string $role): void
{
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');

    $stmt = db()->prepare(
        'INSERT INTO remember_tokens (user_id, role, selector, token_hash, expires_at)
         VALUES (:user_id, :role, :selector, :token_hash, :expires_at)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'role' => $role,
        'selector' => $selector,
        'token_hash' => password_hash($validator, PASSWORD_DEFAULT),
        'expires_at' => $expires,
    ]);

    set_remember_cookie($selector . ':' . $validator, 60 * 60 * 24 * 30);
}

function set_remember_cookie(string $value, int $lifetime): void
{
    setcookie('chamawise_remember', $value, [
        'expires' => time() + $lifetime,
        'path' => APP_BASE === '' ? '/' : APP_BASE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_remember_cookie(): void
{
    if (empty($_COOKIE['chamawise_remember'])) {
        return;
    }

    $parts = explode(':', (string) $_COOKIE['chamawise_remember'], 2);
    if (count($parts) === 2) {
        $stmt = db()->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
        $stmt->execute(['selector' => $parts[0]]);
    }

    set_remember_cookie('', -3600);
    unset($_COOKIE['chamawise_remember']);
}

function restore_remembered_login(): void
{
    if (is_logged_in() || empty($_COOKIE['chamawise_remember'])) {
        return;
    }

    $parts = explode(':', (string) $_COOKIE['chamawise_remember'], 2);
    if (count($parts) !== 2) {
        clear_remember_cookie();
        return;
    }

    [$selector, $validator] = $parts;
    $stmt = db()->prepare(
        'SELECT token_id, user_id, role, token_hash, expires_at
         FROM remember_tokens
         WHERE selector = :selector
         LIMIT 1'
    );
    $stmt->execute(['selector' => $selector]);
    $row = $stmt->fetch();

    if (!$row || new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
        clear_remember_cookie();
        return;
    }

    if (!password_verify($validator, $row['token_hash'])) {
        clear_remember_cookie();
        return;
    }

    $account = null;
    if ($row['role'] === 'Member') {
        $userStmt = db()->prepare('SELECT member_id AS user_id, name, email, role, status FROM members WHERE member_id = :id LIMIT 1');
        $userStmt->execute(['id' => $row['user_id']]);
        $account = $userStmt->fetch();
    } elseif ($row['role'] === 'Admin') {
        $userStmt = db()->prepare('SELECT admin_id AS user_id, name, email, role, status FROM admins WHERE admin_id = :id LIMIT 1');
        $userStmt->execute(['id' => $row['user_id']]);
        $account = $userStmt->fetch();
    }

    if (!$account || $account['status'] !== 'active') {
        clear_remember_cookie();
        return;
    }

    establish_session((int) $account['user_id'], $account['name'], $account['email'], $account['role']);
}

function destroy_session(): void
{
    clear_remember_cookie();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: (APP_BASE === '' ? '/' : APP_BASE),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}

function create_password_reset(array $account): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

    if ($account['account_type'] === 'Member') {
        $stmt = db()->prepare('UPDATE members SET reset_token_hash = :hash, reset_expires = :expires WHERE member_id = :id');
        $stmt->execute([
            'hash' => $hash,
            'expires' => $expires,
            'id' => $account['user_id'],
        ]);
    } else {
        $stmt = db()->prepare('UPDATE admins SET reset_token_hash = :hash, reset_expires = :expires WHERE admin_id = :id');
        $stmt->execute([
            'hash' => $hash,
            'expires' => $expires,
            'id' => $account['user_id'],
        ]);
    }

    return $token;
}

function find_account_by_reset_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $hash = hash('sha256', $token);

    $stmt = db()->prepare(
        'SELECT member_id AS user_id, name, email, role, status, reset_expires
         FROM members
         WHERE reset_token_hash = :hash
         LIMIT 1'
    );
    $stmt->execute(['hash' => $hash]);
    $member = $stmt->fetch();
    if ($member) {
        $member['account_type'] = 'Member';
        return $member;
    }

    $stmt = db()->prepare(
        'SELECT admin_id AS user_id, name, email, role, status, reset_expires
         FROM admins
         WHERE reset_token_hash = :hash
         LIMIT 1'
    );
    $stmt->execute(['hash' => $hash]);
    $admin = $stmt->fetch();
    if ($admin) {
        $admin['account_type'] = 'Admin';
        return $admin;
    }

    return null;
}

function clear_password_reset(array $account): void
{
    if ($account['account_type'] === 'Member') {
        $stmt = db()->prepare('UPDATE members SET reset_token_hash = NULL, reset_expires = NULL WHERE member_id = :id');
        $stmt->execute(['id' => $account['user_id']]);
        return;
    }

    $stmt = db()->prepare('UPDATE admins SET reset_token_hash = NULL, reset_expires = NULL WHERE admin_id = :id');
    $stmt->execute(['id' => $account['user_id']]);
}

function update_password_hash(array $account, string $password): void
{
    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($account['account_type'] === 'Member') {
        $stmt = db()->prepare('UPDATE members SET password_hash = :hash WHERE member_id = :id');
        $stmt->execute(['hash' => $hash, 'id' => $account['user_id']]);
        return;
    }

    $stmt = db()->prepare('UPDATE admins SET password_hash = :hash WHERE admin_id = :id');
    $stmt->execute(['hash' => $hash, 'id' => $account['user_id']]);
}
