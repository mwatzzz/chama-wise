<?php

declare(strict_types=1);

function financial_setting_defaults(): array
{
    return [
        'loan_min_membership_months' => (string) max(0, (int) env('LOAN_MIN_MEMBERSHIP_MONTHS', '3')),
        'loan_min_contribution_months' => '3',
        'loan_min_contributions' => (string) max(0, (int) env('LOAN_MIN_CONTRIBUTIONS', '3')),
        'loan_to_savings_multiplier' => (string) max(0, (float) env('LOAN_MAX_MULTIPLE', '3')),
        'loan_max_amount' => (string) max(0, (float) env('LOAN_MAX_AMOUNT', '100000')),
        'loan_penalties_block_borrowing' => '0',
        'loan_interest_method' => 'flat',
        'loan_interest_rate' => '5',
        'loan_max_term_months' => '12',
        'contribution_amount' => '0',
        'contribution_due_day' => (string) max(1, min(28, (int) env('CONTRIBUTION_DUE_DAY', '5'))),
        'contribution_grace_days' => '3',
        'contribution_penalty_amount' => (string) max(0, (float) env('LATE_CONTRIBUTION_PENALTY', '100')),
        'contribution_penalty_cap' => '500',
        'contribution_penalty_start_period' => date('Y-m'),
        'contribution_penalty_processed_through' => (new DateTimeImmutable('first day of this month'))->modify('-1 month')->format('Y-m'),
        'investment_minimum' => '500',
        'investment_goal_minimum' => '1000',
        'monthly_savings_goal_minimum' => '500',
        'investment_annual_return_assumption' => '0',
        'investment_default_duration_months' => '12',
    ];
}

function ensure_business_rules_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS business_settings (
            setting_key VARCHAR(80) NOT NULL,
            setting_value VARCHAR(255) NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key),
            CONSTRAINT fk_business_settings_admin FOREIGN KEY (updated_by) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS business_setting_audit (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key VARCHAR(80) NOT NULL,
            previous_value VARCHAR(255) NOT NULL,
            new_value VARCHAR(255) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            changed_by INT UNSIGNED NOT NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (audit_id),
            KEY idx_setting_audit_key (setting_key, changed_at),
            CONSTRAINT fk_setting_audit_admin FOREIGN KEY (changed_by) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS penalty_audit (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            penalty_id INT UNSIGNED NOT NULL,
            previous_amount DECIMAL(12, 2) NOT NULL,
            new_amount DECIMAL(12, 2) NOT NULL,
            previous_status VARCHAR(20) NOT NULL,
            new_status VARCHAR(20) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            changed_by INT UNSIGNED NOT NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (audit_id),
            KEY idx_penalty_audit_penalty (penalty_id, changed_at),
            CONSTRAINT fk_penalty_audit_penalty FOREIGN KEY (penalty_id) REFERENCES penalties (penalty_id),
            CONSTRAINT fk_penalty_audit_admin FOREIGN KEY (changed_by) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS loan_repayment_audit (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            repayment_id INT UNSIGNED NOT NULL,
            previous_amount DECIMAL(12, 2) NOT NULL,
            new_amount DECIMAL(12, 2) NOT NULL,
            previous_payment_date DATE NOT NULL,
            new_payment_date DATE NOT NULL,
            reason VARCHAR(255) NOT NULL,
            changed_by INT UNSIGNED NOT NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (audit_id),
            KEY idx_repayment_audit_repayment (repayment_id, changed_at),
            CONSTRAINT fk_repayment_audit_repayment FOREIGN KEY (repayment_id) REFERENCES loan_repayments (repayment_id),
            CONSTRAINT fk_repayment_audit_admin FOREIGN KEY (changed_by) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS investment_opportunities (
            opportunity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(160) NOT NULL,
            category VARCHAR(80) NOT NULL,
            description VARCHAR(1000) NOT NULL,
            risk_level VARCHAR(20) NOT NULL DEFAULT \'unknown\',
            investment_period VARCHAR(120) NULL,
            liquidity VARCHAR(120) NULL,
            minimum_amount DECIMAL(12, 2) NULL,
            risks VARCHAR(1000) NULL,
            source_url VARCHAR(500) NULL,
            verified_at DATE NULL,
            annual_return_assumption DECIMAL(6, 3) NULL,
            verification_status VARCHAR(20) NOT NULL DEFAULT \'unverified\',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (opportunity_id),
            KEY idx_investment_opportunities_active (is_active, category),
            CONSTRAINT fk_investment_opportunity_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS investment_goals (
            goal_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            opportunity_id INT UNSIGNED NULL,
            title VARCHAR(160) NOT NULL,
            target_amount DECIMAL(12, 2) NOT NULL,
            initial_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
            monthly_contribution DECIMAL(12, 2) NOT NULL DEFAULT 0,
            annual_return_assumption DECIMAL(6, 3) NOT NULL DEFAULT 0,
            duration_months SMALLINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            target_date DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (goal_id),
            KEY idx_investment_goals_member (member_id, status),
            CONSTRAINT fk_investment_goal_member FOREIGN KEY (member_id) REFERENCES members (member_id),
            CONSTRAINT fk_investment_goal_opportunity FOREIGN KEY (opportunity_id) REFERENCES investment_opportunities (opportunity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS group_investment_proposals (
            proposal_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            submitted_by INT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            category VARCHAR(80) NOT NULL,
            description VARCHAR(1000) NOT NULL,
            simulated_amount DECIMAL(12, 2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            decision_reason VARCHAR(255) NULL,
            decided_by INT UNSIGNED NULL,
            decided_at DATETIME NULL,
            submission_key CHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (proposal_id),
            UNIQUE KEY uq_group_investment_submission_key (submission_key),
            KEY idx_group_investment_status (status, created_at),
            CONSTRAINT fk_group_investment_member FOREIGN KEY (submitted_by) REFERENCES members (member_id),
            CONSTRAINT fk_group_investment_admin FOREIGN KEY (decided_by) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS investment_transactions (
            transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            goal_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12, 2) NOT NULL,
            transaction_type VARCHAR(20) NOT NULL DEFAULT \'contribution\',
            note VARCHAR(255) NULL,
            recorded_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (transaction_id),
            KEY idx_investment_transactions_goal (goal_id, created_at),
            CONSTRAINT fk_investment_transaction_goal FOREIGN KEY (goal_id) REFERENCES investment_goals (goal_id),
            CONSTRAINT fk_investment_transaction_admin FOREIGN KEY (recorded_by) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $columns = [
        'eligibility_decided_at' => 'ALTER TABLE loans ADD COLUMN eligibility_decided_at DATETIME NULL AFTER rejection_reason',
        'eligibility_outcome' => 'ALTER TABLE loans ADD COLUMN eligibility_outcome TEXT NULL AFTER eligibility_decided_at',
        'submission_key' => 'ALTER TABLE loans ADD COLUMN submission_key CHAR(64) NULL AFTER eligibility_outcome',
        'interest_method' => 'ALTER TABLE loans ADD COLUMN interest_method VARCHAR(20) NULL AFTER interest_rate',
        'interest_accrued_until' => 'ALTER TABLE loans ADD COLUMN interest_accrued_until DATE NULL AFTER due_date',
        'contribution_period' => 'ALTER TABLE penalties ADD COLUMN contribution_period CHAR(7) NULL AFTER contribution_id',
    ];
    foreach ($columns as $column => $sql) {
        $table = $column === 'contribution_period' ? 'penalties' : 'loans';
        if (!db()->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch()) {
            db()->exec($sql);
        }
    }
    if (!db()->query("SHOW COLUMNS FROM loan_repayments LIKE 'idempotency_key'")->fetch()) {
        db()->exec('ALTER TABLE loan_repayments ADD COLUMN idempotency_key CHAR(64) NULL AFTER recorded_by');
    }
    if (!db()->query("SHOW COLUMNS FROM investment_transactions LIKE 'submission_key'")->fetch()) {
        db()->exec('ALTER TABLE investment_transactions ADD COLUMN submission_key CHAR(64) NULL AFTER recorded_by');
    }
    if (!db()->query("SHOW COLUMNS FROM investment_goals LIKE 'submission_key'")->fetch()) {
        db()->exec('ALTER TABLE investment_goals ADD COLUMN submission_key CHAR(64) NULL AFTER status');
    }
    foreach ([
        'risk_level' => "ALTER TABLE investment_opportunities ADD COLUMN risk_level VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER description",
        'investment_period' => 'ALTER TABLE investment_opportunities ADD COLUMN investment_period VARCHAR(120) NULL AFTER risk_level',
        'liquidity' => 'ALTER TABLE investment_opportunities ADD COLUMN liquidity VARCHAR(120) NULL AFTER investment_period',
        'minimum_amount' => 'ALTER TABLE investment_opportunities ADD COLUMN minimum_amount DECIMAL(12, 2) NULL AFTER liquidity',
        'risks' => 'ALTER TABLE investment_opportunities ADD COLUMN risks VARCHAR(1000) NULL AFTER minimum_amount',
        'verification_status' => "ALTER TABLE investment_opportunities ADD COLUMN verification_status VARCHAR(20) NOT NULL DEFAULT 'unverified' AFTER annual_return_assumption",
    ] as $column => $sql) {
        if (!db()->query("SHOW COLUMNS FROM investment_opportunities LIKE '{$column}'")->fetch()) {
            db()->exec($sql);
        }
    }
    if (!db()->query("SHOW COLUMNS FROM investment_goals LIKE 'target_date'")->fetch()) {
        db()->exec('ALTER TABLE investment_goals ADD COLUMN target_date DATE NULL AFTER status');
    }

    if (!db()->query("SHOW INDEX FROM penalties WHERE Key_name = 'uq_penalties_member_period'")->fetch()) {
        db()->exec('ALTER TABLE penalties ADD UNIQUE KEY uq_penalties_member_period (member_id, contribution_period)');
    }
    if (!db()->query("SHOW INDEX FROM loans WHERE Key_name = 'uq_loans_submission_key'")->fetch()) {
        db()->exec('ALTER TABLE loans ADD UNIQUE KEY uq_loans_submission_key (submission_key)');
    }
    if (!db()->query("SHOW INDEX FROM loan_repayments WHERE Key_name = 'uq_repayments_idempotency_key'")->fetch()) {
        db()->exec('ALTER TABLE loan_repayments ADD UNIQUE KEY uq_repayments_idempotency_key (idempotency_key)');
    }
    if (!db()->query("SHOW INDEX FROM investment_transactions WHERE Key_name = 'uq_investment_submission_key'")->fetch()) {
        db()->exec('ALTER TABLE investment_transactions ADD UNIQUE KEY uq_investment_submission_key (submission_key)');
    }
    if (!db()->query("SHOW INDEX FROM investment_goals WHERE Key_name = 'uq_investment_goal_submission_key'")->fetch()) {
        db()->exec('ALTER TABLE investment_goals ADD UNIQUE KEY uq_investment_goal_submission_key (submission_key)');
    }

    $insert = db()->prepare('INSERT IGNORE INTO business_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)');
    foreach (financial_setting_defaults() as $key => $value) {
        $insert->execute(['setting_key' => $key, 'setting_value' => $value]);
    }

    $checked = true;
}

function financial_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = financial_setting_defaults();
    $rows = db()->query('SELECT setting_key, setting_value FROM business_settings')->fetchAll();
    foreach ($rows as $row) {
        if (array_key_exists($row['setting_key'], $settings)) {
            $settings[$row['setting_key']] = (string) $row['setting_value'];
        }
    }
    return $settings;
}

function financial_setting(string $key): string
{
    $settings = financial_settings();
    if (!array_key_exists($key, $settings)) {
        throw new InvalidArgumentException('Unknown financial setting.');
    }
    return $settings[$key];
}

function save_financial_settings(array $values, string $reason, int $adminId): void
{
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 255) {
        throw new InvalidArgumentException('Enter a reason for changing the financial settings (up to 255 characters).');
    }

    $defaults = financial_setting_defaults();
    unset($defaults['contribution_penalty_start_period']);
    unset($defaults['contribution_penalty_processed_through']);
    $validated = [];
    foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $values) || !is_scalar($values[$key])) {
            throw new InvalidArgumentException('Submit all financial settings.');
        }
        $value = trim((string) $values[$key]);
        if ($key === 'loan_interest_method') {
            if (!in_array($value, ['flat', 'reducing_balance'], true)) {
                throw new InvalidArgumentException('Choose a supported loan interest method.');
            }
        } elseif ($key === 'loan_penalties_block_borrowing') {
            if (!in_array($value, ['0', '1'], true)) {
                throw new InvalidArgumentException('Choose whether outstanding penalties block borrowing.');
            }
        } elseif (!is_numeric($value) || !is_finite((float) $value) ||
            ((float) $value < 0 && $key !== 'investment_annual_return_assumption')) {
            throw new InvalidArgumentException('Financial settings must be valid non-negative numbers.');
        }
        $validated[$key] = $value;
    }

    foreach ([
        'loan_min_membership_months' => [0, 600],
        'loan_min_contribution_months' => [0, 600],
        'loan_min_contributions' => [0, 10000],
        'loan_to_savings_multiplier' => [0, 100],
        'loan_max_amount' => [0, 1000000000],
        'loan_interest_rate' => [0, 100],
        'loan_max_term_months' => [1, 120],
        'contribution_amount' => [0, 1000000000],
        'contribution_due_day' => [1, 28],
        'contribution_grace_days' => [0, 31],
        'contribution_penalty_amount' => [0, 1000000],
        'contribution_penalty_cap' => [0, 1000000],
        'investment_minimum' => [0, 1000000000],
        'investment_goal_minimum' => [0, 1000000000],
        'monthly_savings_goal_minimum' => [0, 1000000000],
        'investment_annual_return_assumption' => [-100, 100],
        'investment_default_duration_months' => [1, 600],
    ] as $key => [$minimum, $maximum]) {
        $number = (float) $validated[$key];
        if ($number < $minimum || $number > $maximum ||
            (in_array($key, ['loan_min_membership_months', 'loan_min_contribution_months', 'loan_min_contributions', 'loan_max_term_months', 'contribution_due_day', 'contribution_grace_days', 'investment_default_duration_months'], true) && floor($number) !== $number)) {
            throw new InvalidArgumentException('One or more financial settings are outside the allowed range.');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $read = $pdo->prepare('SELECT setting_value FROM business_settings WHERE setting_key = :key FOR UPDATE');
        $write = $pdo->prepare('UPDATE business_settings SET setting_value = :value, updated_by = :admin_id WHERE setting_key = :key');
        $audit = $pdo->prepare(
            'INSERT INTO business_setting_audit (setting_key, previous_value, new_value, reason, changed_by)
             VALUES (:key, :previous_value, :new_value, :reason, :admin_id)'
        );
        foreach ($validated as $key => $value) {
            $read->execute(['key' => $key]);
            $previous = (string) $read->fetchColumn();
            if ($previous === $value) {
                continue;
            }
            $write->execute(['value' => $value, 'admin_id' => $adminId, 'key' => $key]);
            $audit->execute([
                'key' => $key,
                'previous_value' => $previous,
                'new_value' => $value,
                'reason' => $reason,
                'admin_id' => $adminId,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
