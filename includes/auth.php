<?php

declare(strict_types=1);

function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('error', 'Please log in to continue.');
        redirect('auth/login.php');
    }

    if (is_member() && !member_account_is_active(db(), (int) current_user()['user_id'])) {
        destroy_session();
        flash_set('error', 'This member account is no longer active.');
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

function member_account_is_active(PDO $pdo, int $memberId): bool
{
    $stmt = $pdo->prepare('SELECT status FROM members WHERE member_id = :member_id LIMIT 1');
    $stmt->execute(['member_id' => $memberId]);
    return $stmt->fetchColumn() === 'active';
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash_set('error', 'You are not authorised to view that page.');
        redirect('member/dashboard.php');
    }
}

function ensure_auth_login_attempts_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS auth_login_attempts (
            email_hash CHAR(64) NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            window_started_at DATETIME NOT NULL,
            failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            PRIMARY KEY (email_hash, ip_hash),
            KEY idx_auth_attempts_locked_until (locked_until),
            KEY idx_auth_attempts_window (window_started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $checked = true;
}

function prune_login_attempts(): void
{
    db()->exec('DELETE FROM auth_login_attempts WHERE window_started_at < CURRENT_TIMESTAMP - INTERVAL 1 DAY');
}

function login_attempt_hashes(string $email): array
{
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    return [
        hash('sha256', strtolower(trim($email))),
        hash('sha256', $ipAddress),
    ];
}

function login_is_rate_limited(string $email): bool
{
    [$emailHash, $ipHash] = login_attempt_hashes($email);
    $stmt = db()->prepare(
        'SELECT locked_until > CURRENT_TIMESTAMP
         FROM auth_login_attempts
         WHERE email_hash = :email_hash AND ip_hash = :ip_hash
         LIMIT 1'
    );
    $stmt->execute(['email_hash' => $emailHash, 'ip_hash' => $ipHash]);

    return (bool) $stmt->fetchColumn();
}

function record_login_failure(string $email): void
{
    [$emailHash, $ipHash] = login_attempt_hashes($email);
    db()->prepare(
        'INSERT INTO auth_login_attempts (email_hash, ip_hash, window_started_at, failed_attempts, locked_until)
         VALUES (:email_hash, :ip_hash, CURRENT_TIMESTAMP, 1, NULL)
         ON DUPLICATE KEY UPDATE
            failed_attempts = IF(window_started_at <= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE, 1, failed_attempts + 1),
            locked_until = IF(
                window_started_at <= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE,
                NULL,
                IF(failed_attempts >= 5, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), locked_until)
            ),
            window_started_at = IF(window_started_at <= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE, CURRENT_TIMESTAMP, window_started_at)'
    )->execute(['email_hash' => $emailHash, 'ip_hash' => $ipHash]);
}

function clear_login_failures(string $email): void
{
    [$emailHash, $ipHash] = login_attempt_hashes($email);
    db()->prepare(
        'DELETE FROM auth_login_attempts WHERE email_hash = :email_hash AND ip_hash = :ip_hash'
    )->execute(['email_hash' => $emailHash, 'ip_hash' => $ipHash]);
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
        'secure' => APP_ENV === 'production' || app_request_is_https(),
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
            'secure' => APP_ENV === 'production' || app_request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}

function is_valid_password_reset(array $account): bool
{
    if (($account['status'] ?? '') !== 'active') {
        return false;
    }

    $expires = (string) ($account['reset_expires'] ?? '');
    if ($expires === '') {
        return false;
    }

    try {
        return new DateTimeImmutable($expires) >= new DateTimeImmutable();
    } catch (Exception $exception) {
        return false;
    }
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

function send_password_reset_email(array $account, string $resetUrl): bool
{
    $mailer = strtolower((string) env('MAIL_MAILER', 'mail'));
    $fromAddress = trim((string) env('MAIL_FROM_ADDRESS', 'noreply@localhost'));
    $fromName = trim((string) env('MAIL_FROM_NAME', APP_NAME));
    $subject = 'Reset your ' . APP_NAME . ' password';
    $message = "Hello {$account['name']},\r\n\r\n" .
        "We received a request to reset your password for " . APP_NAME . ".\r\n" .
        "Use the link below to create a new password:\r\n\r\n" .
        $resetUrl . "\r\n\r\n" .
        "This link is valid for 1 hour. If you did not request this, you can ignore this email.\r\n\r\n" .
        "Regards,\r\n" . APP_NAME;

    $headers = [
        'From: ' . $fromName . ' <' . $fromAddress . '>',
        'Reply-To: ' . $fromAddress,
        'X-Mailer: ChamaWise',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    if ($mailer === 'smtp') {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            error_log('Password reset email unavailable: run composer install.');
            return false;
        }
        require_once $autoload;

        $smtpHost = trim((string) env('MAIL_HOST', ''));
        $smtpPort = (int) env('MAIL_PORT', '587');
        $smtpUser = trim((string) env('MAIL_USERNAME', ''));
        $smtpPass = (string) env('MAIL_PASSWORD', '');
        $smtpEncryption = strtolower(trim((string) env('MAIL_ENCRYPTION', 'tls')));

        if ($smtpHost === '' || $smtpPort < 1 || $smtpPort > 65535 ||
            !in_array($smtpEncryption, ['tls', 'ssl'], true) ||
            ($smtpUser !== '' && $smtpPass === '')) {
            error_log('Password reset email unavailable: check SMTP configuration.');
            return false;
        }

        try {
            $email = new \PHPMailer\PHPMailer\PHPMailer(true);
            $email->isSMTP();
            $email->Host = $smtpHost;
            $email->Port = $smtpPort;
            $email->SMTPAuth = $smtpUser !== '';
            $email->Username = $smtpUser;
            $email->Password = $smtpPass;
            $email->SMTPSecure = $smtpEncryption === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $email->Timeout = 15;
            $email->CharSet = 'UTF-8';
            $email->setFrom($fromAddress, $fromName);
            $email->addAddress($account['email'], $account['name']);
            $email->Subject = $subject;
            $email->Body = $message;
            return $email->send();
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            error_log('Password reset SMTP delivery failed. Check mail server settings and credentials.');
            return false;
        }
    }

    return mail($account['email'], $subject, $message, implode("\r\n", $headers));
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
        if (($member['status'] ?? '') === 'active') {
            return $member;
        }
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
        if (($admin['status'] ?? '') === 'active') {
            return $admin;
        }
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
