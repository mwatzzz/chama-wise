<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_chama_question(string $question): bool
{
    $question = strtolower(trim($question));
    if ($question === '') {
        return false;
    }

    $question = preg_replace('/[^a-z0-9\s]/i', ' ', $question) ?? $question;
    $question = preg_replace('/\s+/', ' ', $question);

    if (preg_match('/\b(?:answer|respond|reply|speak|write)\b.{0,40}\b(?:in|using)\s+(?:english|swahili|kiswahili)\b|\buse\s+(?:english|swahili|kiswahili)\b|\bswitch\s+to\s+(?:english|swahili|kiswahili)\b|\b(?:english|swahili|kiswahili)\s+(?:please|only)\b/i', $question) === 1) {
        return true;
    }

    $keywords = [
        'chama',
        'contribution', 'contributions', 'contribute', 'saving', 'savings', 'save',
        'loan', 'loans', 'borrow', 'debt',
        'repay', 'repayment', 'repayments', 'payment', 'payments', 'pay',
        'penalty', 'penalties', 'late', 'overdue', 'missed',
        'balance', 'outstanding', 'owe', 'owing', 'amount', 'budget',
        'investment', 'investments', 'investing', 'portfolio', 'projected return', 'projected gain',
        'compound', 'compound growth', 'diversification', 'liquidity', 'inflation', 'risk', 'risks', 'fees',
        'wealth', 'unit trust', 'fixed deposit', 'money market', 'treasury bill', 'government bond',
        'meeting', 'meetings', 'member', 'members', 'membership',
        'due', 'date', 'schedule', 'plan', 'advice', 'financial', 'finance',
        'history', 'record', 'records', 'treasury', 'fund', 'installment', 'instalment',
        'mchango', 'michango', 'mchango wa mwezi', 'akiba', 'mkopo', 'mikopo', 'kula', 'kufanya',
        'malipo', 'malipo ya mwezi', 'deni', 'kiasi', 'kiasi kilichobaki', 'ada', 'penalti',
        'penalti za kuchelewa', 'ratiba', 'mpango', 'ushauri', 'mali', 'fedha', 'mwanachama',
        'mwanachama wa chama', 'kazi ya chama', 'mkopo wa chama', 'suluhisho la malipo',
        'hali ya uanachama', 'mikutano', 'mikutano ya chama', 'jumla ya mchango', 'mchango wa mwaka',
        'kazi ya chama', 'mchango wa mwezi', 'mpango wa malipo', 'mwili', 'mchango wa chama'
    ];

    foreach ($keywords as $keyword) {
        if (str_contains($question, (string) $keyword)) {
            return true;
        }
    }

    $phrases = [
        'repayment plan', 'loan balance', 'contribution history', 'payment history', 'loan schedule',
        'repayment schedule', 'savings goal', 'penalty due', 'meeting minutes', 'membership status',
        'outstanding balance', 'mpango wa malipo', 'kiasi kilichobaki', 'mchango wa mwezi',
        'historia ya mchango', 'historia ya malipo', 'mkopo wa chama', 'penalti ya kuchelewa',
        'mchango wa mwaka', 'kiasi cha deni', 'hali ya mwanachama', 'ratiba ya malipo', 'mpango wa akiba',
        'michango ya mwezi', 'mchango wa chama'
    ];

    foreach ($phrases as $phrase) {
        if (str_contains($question, $phrase)) {
            return true;
        }
    }

    return false;
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
    $_SESSION['last_activity'] = time();
}

function enforce_session_timeout(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        return;
    }

    $lastActivity = (int) ($_SESSION['last_activity'] ?? time());
    if (time() - $lastActivity >= SESSION_IDLE_TIMEOUT) {
        destroy_session();
        return;
    }

    $_SESSION['last_activity'] = time();
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
        ['name' => 'M-Pesa Daraja', 'code' => 'daraja'],
    ] as $paymentMethod) {
        $paymentMethodStmt->execute($paymentMethod);
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS contributions (
            contribution_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12, 2) NOT NULL,
            currency_code VARCHAR(10) NOT NULL DEFAULT \'KES\',
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

    $currencyColumn = db()->query("SHOW COLUMNS FROM contributions LIKE 'currency_code'")->fetch();
    if (!$currencyColumn) {
        db()->exec("ALTER TABLE contributions ADD COLUMN currency_code VARCHAR(10) NOT NULL DEFAULT 'KES' AFTER amount");
    }

    $meetingColumns = [
        'document_path' => 'ALTER TABLE meetings ADD COLUMN document_path VARCHAR(255) NULL AFTER minutes',
        'document_name' => 'ALTER TABLE meetings ADD COLUMN document_name VARCHAR(255) NULL AFTER document_path',
        'document_mime' => 'ALTER TABLE meetings ADD COLUMN document_mime VARCHAR(100) NULL AFTER document_name',
    ];
    foreach ($meetingColumns as $column => $sql) {
        if (!db()->query("SHOW COLUMNS FROM meetings LIKE '{$column}'")->fetch()) {
            db()->exec($sql);
        }
    }

    $checked = true;
}

function ensure_loan_penalties_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    if (!db()->query("SHOW COLUMNS FROM loans LIKE 'due_date'")->fetch()) {
        db()->exec('ALTER TABLE loans ADD COLUMN due_date DATE NULL AFTER approval_date');
    }
    $loanStatusColumn = db()->query("SHOW COLUMNS FROM loans LIKE 'status'")->fetch();
    if ($loanStatusColumn && preg_match('/^varchar\((\d+)\)$/i', (string) $loanStatusColumn['Type'], $statusLength) && (int) $statusLength[1] < 32) {
        db()->exec("ALTER TABLE loans MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'");
    }
    db()->exec("UPDATE loans SET status = 'pending_second_approval' WHERE status = 'pending_second_appro'");
    if (!db()->query("SHOW COLUMNS FROM penalties LIKE 'loan_id'")->fetch()) {
        db()->exec('ALTER TABLE penalties ADD COLUMN loan_id INT UNSIGNED NULL AFTER contribution_id');
    }
    if (!db()->query("SHOW INDEX FROM penalties WHERE Key_name = 'uq_penalties_loan'")->fetch()) {
        db()->exec('ALTER TABLE penalties ADD UNIQUE KEY uq_penalties_loan (loan_id)');
    }

    $checked = true;
}

function ensure_loan_member_view(): void
{
    try {
        db()->query('SELECT 1 FROM loan_member_view LIMIT 1');
        return;
    } catch (PDOException $exception) {
        db()->exec(
            'CREATE VIEW loan_member_view AS
            SELECT
                l.loan_id,
                l.member_id,
                m.name AS member_name,
                m.email AS member_email,
                m.phone AS member_phone,
                l.requested_amount,
                l.approved_amount,
                l.interest_rate,
                l.outstanding_balance,
                l.purpose,
                l.notes,
                l.status,
                l.rejection_reason,
                l.application_date,
                l.approval_date,
                l.approved_by,
                l.first_approved_by,
                l.first_approval_date,
                l.second_approved_by,
                l.second_approval_date,
                l.created_at
            FROM loans l
            INNER JOIN members m ON m.member_id = l.member_id'
        );
    }
}
