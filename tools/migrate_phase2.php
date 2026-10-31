<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once dirname(__DIR__) . '/config/app.php';

$tables = [
    'payment_methods' => "CREATE TABLE IF NOT EXISTS payment_methods (
        payment_method_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(80) NOT NULL,
        code VARCHAR(30) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (payment_method_id),
        UNIQUE KEY uq_payment_methods_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'penalties' => "CREATE TABLE IF NOT EXISTS penalties (
        penalty_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        member_id INT UNSIGNED NOT NULL,
        contribution_id INT UNSIGNED NULL,
        amount DECIMAL(12, 2) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        penalty_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'outstanding',
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (penalty_id),
        KEY idx_penalties_member (member_id),
        KEY idx_penalties_contribution (contribution_id),
        KEY idx_penalties_status (status),
        CONSTRAINT fk_penalties_member FOREIGN KEY (member_id) REFERENCES members (member_id),
        CONSTRAINT fk_penalties_contribution FOREIGN KEY (contribution_id) REFERENCES contributions (contribution_id),
        CONSTRAINT fk_penalties_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'loans' => "CREATE TABLE IF NOT EXISTS loans (
        loan_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        member_id INT UNSIGNED NOT NULL,
        requested_amount DECIMAL(12, 2) NOT NULL,
        approved_amount DECIMAL(12, 2) NULL,
        interest_rate DECIMAL(5, 2) NULL,
        outstanding_balance DECIMAL(12, 2) NULL,
        purpose VARCHAR(255) NULL,
        notes VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        application_date DATE NOT NULL,
        approval_date DATE NULL,
        approved_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (loan_id),
        KEY idx_loans_member (member_id),
        KEY idx_loans_status (status),
        CONSTRAINT fk_loans_member FOREIGN KEY (member_id) REFERENCES members (member_id),
        CONSTRAINT fk_loans_admin FOREIGN KEY (approved_by) REFERENCES admins (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'loan_repayments' => "CREATE TABLE IF NOT EXISTS loan_repayments (
        repayment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        loan_id INT UNSIGNED NOT NULL,
        amount DECIMAL(12, 2) NOT NULL,
        payment_date DATE NOT NULL,
        payment_method_id INT UNSIGNED NULL,
        reference VARCHAR(80) NULL,
        recorded_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (repayment_id),
        KEY idx_repayments_loan (loan_id),
        KEY idx_repayments_date (payment_date),
        CONSTRAINT fk_repayments_loan FOREIGN KEY (loan_id) REFERENCES loans (loan_id),
        CONSTRAINT fk_repayments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (payment_method_id),
        CONSTRAINT fk_repayments_admin FOREIGN KEY (recorded_by) REFERENCES admins (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'meetings' => "CREATE TABLE IF NOT EXISTS meetings (
        meeting_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(160) NOT NULL,
        meeting_date DATE NOT NULL,
        minutes TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (meeting_id),
        KEY idx_meetings_date (meeting_date),
        CONSTRAINT fk_meetings_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_advice_sessions' => "CREATE TABLE IF NOT EXISTS ai_advice_sessions (
        session_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        member_id INT UNSIGNED NOT NULL,
        title VARCHAR(160) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (session_id),
        KEY idx_ai_sessions_member (member_id),
        CONSTRAINT fk_ai_sessions_member FOREIGN KEY (member_id) REFERENCES members (member_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_chat_messages' => "CREATE TABLE IF NOT EXISTS ai_chat_messages (
        message_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id INT UNSIGNED NOT NULL,
        sender VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (message_id),
        KEY idx_ai_messages_session (session_id),
        CONSTRAINT fk_ai_messages_session FOREIGN KEY (session_id) REFERENCES ai_advice_sessions (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    db()->exec($sql);
    fwrite(STDOUT, "Ensured {$name}.\n");
}

$meetingColumns = [
    'document_path' => 'ALTER TABLE meetings ADD COLUMN document_path VARCHAR(255) NULL AFTER minutes',
    'document_name' => 'ALTER TABLE meetings ADD COLUMN document_name VARCHAR(255) NULL AFTER document_path',
    'document_mime' => 'ALTER TABLE meetings ADD COLUMN document_mime VARCHAR(100) NULL AFTER document_name',
];

foreach ($meetingColumns as $column => $sql) {
    $columnExists = db()->query("SHOW COLUMNS FROM meetings LIKE '{$column}'")->fetch();
    if (!$columnExists) {
        db()->exec($sql);
        fwrite(STDOUT, "Added meetings.{$column}.\n");
    }
}

$loanColumns = [
    'rejection_reason' => 'ALTER TABLE loans ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER status',
    'first_approved_by' => 'ALTER TABLE loans ADD COLUMN first_approved_by INT UNSIGNED NULL AFTER approved_by',
    'first_approval_date' => 'ALTER TABLE loans ADD COLUMN first_approval_date DATE NULL AFTER first_approved_by',
    'second_approved_by' => 'ALTER TABLE loans ADD COLUMN second_approved_by INT UNSIGNED NULL AFTER first_approval_date',
    'second_approval_date' => 'ALTER TABLE loans ADD COLUMN second_approval_date DATE NULL AFTER second_approved_by',
];

foreach ($loanColumns as $column => $sql) {
    $columnExists = db()->query("SHOW COLUMNS FROM loans LIKE '{$column}'")->fetch();
    if (!$columnExists) {
        db()->exec($sql);
        fwrite(STDOUT, "Added loans.{$column}.\n");
    }
}

fwrite(STDOUT, "Phase 2 database migration completed.\n");
