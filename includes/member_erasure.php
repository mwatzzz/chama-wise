<?php

declare(strict_types=1);

final class MemberErasureBlocked extends RuntimeException
{
}

function ensure_member_erasure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS member_erasure_audit (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            admin_id INT UNSIGNED NOT NULL,
            erased_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (audit_id),
            KEY idx_member_erasure_audit_member (member_id, erased_at),
            KEY idx_member_erasure_audit_admin (admin_id, erased_at),
            CONSTRAINT fk_member_erasure_audit_member FOREIGN KEY (member_id) REFERENCES members (member_id),
            CONSTRAINT fk_member_erasure_audit_admin FOREIGN KEY (admin_id) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $checked = true;
}

function member_erasure_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
    );
    $stmt->execute(['table_name' => $table]);
    return (bool) $stmt->fetchColumn();
}

function member_profile_picture_directory(): string
{
    return APP_ENV === 'test'
        ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chamawise-test-profile-pictures'
        : ROOT_PATH . '/uploads/profile-pictures';
}

function member_erasure_profile_picture_path_is_valid(?string $relativePath, int $memberId): bool
{
    return $relativePath !== null
        && preg_match(
            '/\Auploads\/profile-pictures\/member-' . $memberId . '-[a-f0-9]{16}\.(?:jpg|png|webp)\z/i',
            $relativePath
        ) === 1;
}

/** @return list<string> */
function member_erasure_obligations(PDO $pdo, int $memberId): array
{
    $obligations = [];
    $loanStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM loans
         WHERE member_id = :member_id
           AND (
               status IN ('pending', 'eligible', 'pending_second_approval')
               OR (status IN ('approved', 'overdue') AND (outstanding_balance IS NULL OR outstanding_balance > 0))
               OR COALESCE(outstanding_balance, 0) > 0
           )"
    );
    $loanStmt->execute(['member_id' => $memberId]);
    if ((int) $loanStmt->fetchColumn() > 0) {
        $obligations[] = 'outstanding loans or pending loan applications';
    }

    $penaltyStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM penalties WHERE member_id = :member_id AND status = 'outstanding'"
    );
    $penaltyStmt->execute(['member_id' => $memberId]);
    if ((int) $penaltyStmt->fetchColumn() > 0) {
        $obligations[] = 'unpaid penalties';
    }

    foreach (['member_daraja_payments', 'investment_payments'] as $table) {
        if (!member_erasure_table_exists($pdo, $table)) {
            continue;
        }

        $paymentStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE member_id = :member_id
               AND status NOT IN ('successful', 'failed', 'cancelled', 'timed_out')"
        );
        $paymentStmt->execute(['member_id' => $memberId]);
        if ((int) $paymentStmt->fetchColumn() > 0) {
            $obligations[] = 'unresolved payment transactions';
            break;
        }
    }

    if (member_erasure_table_exists($pdo, 'sms_campaign_recipients')) {
        $smsStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sms_campaign_recipients
             WHERE member_id = :member_id AND status IN ('pending', 'accepted', 'uncertain')"
        );
        $smsStmt->execute(['member_id' => $memberId]);
        if ((int) $smsStmt->fetchColumn() > 0) {
            $obligations[] = 'pending or unreconciled SMS deliveries';
        }
    }

    return $obligations;
}

/** @return list<string> */
function member_erasure_safe_name_fragments(string $name): array
{
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $common = [
        'admin', 'account', 'amount', 'chama', 'contribution', 'finance', 'group',
        'investment', 'loan', 'member', 'money', 'payment', 'record', 'sacco',
        'savings', 'test', 'data', 'paid', 'balance', 'receipt',
    ];

    return array_values(array_unique(array_filter(
        $tokens,
        static fn (string $token): bool => mb_strlen($token) >= 6
            && preg_match('/\A[\p{L}]+\z/u', $token) === 1
            && !in_array($token, $common, true)
    )));
}

/**
 * Redacts known identifiers from human-readable text while preserving records.
 * Short/common name tokens and opaque payment/receipt references are not inferred:
 * the latter remain for reconciliation and cannot be safely distinguished from PII.
 *
 * @param list<string> $identifiers
 */
function member_erasure_redact_text(string $text, array $identifiers): string
{
    foreach ($identifiers as $identifier) {
        $identifier = trim($identifier);
        if ($identifier === '') {
            continue;
        }

        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $pattern = $isEmail
            ? '/' . preg_quote($identifier, '/') . '/iu'
            : '/(?<![\p{L}\p{N}])' . preg_quote($identifier, '/') . '(?![\p{L}\p{N}])/iu';
        $redacted = preg_replace($pattern, '[redacted]', $text);
        if (is_string($redacted)) {
            $text = $redacted;
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?? '';
        if (strlen($digits) < 9) {
            continue;
        }
        $phoneVariants = [$digits];
        if (str_starts_with($digits, '254')) {
            $phoneVariants[] = '0' . substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $phoneVariants[] = '254' . substr($digits, 1);
        }
        foreach (array_unique($phoneVariants) as $phoneDigits) {
            $phonePattern = '/(?<!\d)' . implode('[^0-9A-Za-z]*', str_split($phoneDigits)) . '(?!\d)/';
            $redactedPhone = preg_replace($phonePattern, '[redacted]', $text);
            if (is_string($redactedPhone)) {
                $text = $redactedPhone;
            }
        }
    }

    return $text;
}

/**
 * @param list<string> $fields
 * @param array<string, int|string> $parameters
 * @param list<string> $identifiers
 */
function member_erasure_scrub_rows(
    PDO $pdo,
    string $table,
    string $idColumn,
    array $fields,
    string $where,
    array $parameters,
    array $identifiers
): void {
    foreach ([$table, $idColumn, ...$fields] as $identifier) {
        if (preg_match('/\A[a-z_]+\z/i', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid member erasure schema identifier.');
        }
    }

    $columns = implode(', ', array_map(static fn (string $field): string => "`{$field}`", [$idColumn, ...$fields]));
    $select = $pdo->prepare("SELECT {$columns} FROM `{$table}` WHERE {$where} FOR UPDATE");
    $select->execute($parameters);
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $changes = [];
        $values = ['row_id' => $row[$idColumn]];
        foreach ($fields as $field) {
            if (!is_string($row[$field] ?? null)) {
                continue;
            }
            $redacted = member_erasure_redact_text($row[$field], $identifiers);
            if ($redacted !== $row[$field]) {
                $changes[] = "`{$field}` = :{$field}";
                $values[$field] = $redacted;
            }
        }

        if ($changes !== []) {
            $statement = $pdo->prepare(sprintf(
                "UPDATE `{$table}` SET %s WHERE `{$idColumn}` = :row_id",
                implode(', ', $changes)
            ));
            $statement->execute($values);
        }
    }
}

/**
 * @return array{member_id: int, profile_picture: ?string}
 */
function erase_member_account(PDO $pdo, int $memberId, int $adminId): array
{
    if ($memberId < 1 || $adminId < 1) {
        throw new InvalidArgumentException('A valid member and administrator are required.');
    }

    ensure_member_erasure_schema();
    $pdo->beginTransaction();
    try {
        $adminStmt = $pdo->prepare(
            "SELECT admin_id FROM admins WHERE admin_id = :admin_id AND status = 'active' FOR UPDATE"
        );
        $adminStmt->execute(['admin_id' => $adminId]);
        if (!$adminStmt->fetchColumn()) {
            throw new InvalidArgumentException('An active administrator is required to erase a member account.');
        }

        $memberStmt = $pdo->prepare(
            'SELECT member_id, name, phone, email, profile_picture, status
             FROM members WHERE member_id = :member_id FOR UPDATE'
        );
        $memberStmt->execute(['member_id' => $memberId]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member || $member['status'] === 'erased') {
            throw new InvalidArgumentException('The member account is unavailable or has already been erased.');
        }

        $contributionLocks = $pdo->prepare(
            'SELECT contribution_id FROM contributions WHERE member_id = :member_id FOR UPDATE'
        );
        $contributionLocks->execute(['member_id' => $memberId]);
        $contributionLocks->fetchAll(PDO::FETCH_COLUMN);
        if (member_erasure_table_exists($pdo, 'sms_campaign_recipients')) {
            $smsLocks = $pdo->prepare(
                "SELECT recipient_id FROM sms_campaign_recipients
                 WHERE member_id = :member_id AND status IN ('pending', 'accepted', 'uncertain')
                 FOR UPDATE"
            );
            $smsLocks->execute(['member_id' => $memberId]);
            $smsLocks->fetchAll(PDO::FETCH_COLUMN);
        }

        $obligations = member_erasure_obligations($pdo, $memberId);
        if ($obligations !== []) {
            throw new MemberErasureBlocked(
                'Account erasure is blocked by ' . implode(' and ', $obligations) . '. Resolve these records first.'
            );
        }

        $directIdentifiers = array_values(array_filter([
            (string) $member['name'],
            (string) $member['email'],
            (string) $member['phone'],
        ], static fn (string $value): bool => trim($value) !== ''));
        $identifiers = array_values(array_unique([
            ...$directIdentifiers,
            ...member_erasure_safe_name_fragments((string) $member['name']),
        ]));

        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id AND role = \'Member\'')
            ->execute(['user_id' => $memberId]);
        $pdo->prepare('DELETE FROM auth_login_attempts WHERE email_hash = :email_hash')
            ->execute(['email_hash' => hash('sha256', strtolower(trim((string) $member['email'])))]);

        $sessionStmt = $pdo->prepare('SELECT session_id FROM ai_advice_sessions WHERE member_id = :member_id FOR UPDATE');
        $sessionStmt->execute(['member_id' => $memberId]);
        $sessionIds = array_map('intval', $sessionStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($sessionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $pdo->prepare("DELETE FROM ai_chat_messages WHERE session_id IN ({$placeholders})")
                ->execute($sessionIds);
            $pdo->prepare("DELETE FROM ai_advice_sessions WHERE session_id IN ({$placeholders})")
                ->execute($sessionIds);
        }
        $pdo->prepare('DELETE FROM ai_member_daily_usage WHERE member_id = :member_id')
            ->execute(['member_id' => $memberId]);

        $memberRecords = [
            ['contributions', 'contribution_id', ['reference', 'notes'], 'member_id = :member_id'],
            ['loans', 'loan_id', ['purpose', 'notes'], 'member_id = :member_id'],
            ['penalties', 'penalty_id', ['reason'], 'member_id = :member_id'],
            ['investment_goals', 'goal_id', ['title'], 'member_id = :member_id'],
            ['group_investment_proposals', 'proposal_id', ['title', 'description', 'decision_reason'], 'submitted_by = :member_id'],
        ];
        foreach ($memberRecords as [$table, $idColumn, $fields, $where]) {
            if (member_erasure_table_exists($pdo, $table)) {
                member_erasure_scrub_rows(
                    $pdo,
                    $table,
                    $idColumn,
                    $fields,
                    $where,
                    ['member_id' => $memberId],
                    $identifiers
                );
            }
        }

        if (member_erasure_table_exists($pdo, 'loan_repayments')) {
            member_erasure_scrub_rows(
                $pdo,
                'loan_repayments',
                'repayment_id',
                ['reference'],
                'loan_id IN (SELECT loan_id FROM loans WHERE member_id = :member_id)',
                ['member_id' => $memberId],
                $identifiers
            );
            if (member_erasure_table_exists($pdo, 'loan_repayment_audit')) {
                member_erasure_scrub_rows(
                    $pdo,
                    'loan_repayment_audit',
                    'audit_id',
                    ['reason'],
                    'repayment_id IN (
                        SELECT r.repayment_id FROM loan_repayments r
                        INNER JOIN loans l ON l.loan_id = r.loan_id
                        WHERE l.member_id = :member_id
                    )',
                    ['member_id' => $memberId],
                    $identifiers
                );
            }
        }
        if (member_erasure_table_exists($pdo, 'penalty_audit')) {
            member_erasure_scrub_rows(
                $pdo,
                'penalty_audit',
                'audit_id',
                ['reason'],
                'penalty_id IN (SELECT penalty_id FROM penalties WHERE member_id = :member_id)',
                ['member_id' => $memberId],
                $identifiers
            );
        }
        if (member_erasure_table_exists($pdo, 'investment_transactions')) {
            member_erasure_scrub_rows(
                $pdo,
                'investment_transactions',
                'transaction_id',
                ['note'],
                'goal_id IN (SELECT goal_id FROM investment_goals WHERE member_id = :member_id)',
                ['member_id' => $memberId],
                $identifiers
            );
        }

        foreach (['member_daraja_payments', 'investment_payments'] as $table) {
            if (!member_erasure_table_exists($pdo, $table)) {
                continue;
            }
            $idColumn = $table === 'member_daraja_payments' ? 'member_payment_id' : 'payment_id';
            member_erasure_scrub_rows(
                $pdo,
                $table,
                $idColumn,
                ['request_result_description', 'callback_result_description'],
                'member_id = :member_id',
                ['member_id' => $memberId],
                $identifiers
            );

            $pdo->prepare(
                "UPDATE `{$table}` SET phone_number = '', callback_phone_number = NULL
                 WHERE member_id = :member_id"
            )->execute(['member_id' => $memberId]);
        }
        if (member_erasure_table_exists($pdo, 'investment_payment_audit')
            && member_erasure_table_exists($pdo, 'investment_payments')) {
            member_erasure_scrub_rows(
                $pdo,
                'investment_payment_audit',
                'audit_id',
                ['reason', 'result_description'],
                'payment_id IN (SELECT payment_id FROM investment_payments WHERE member_id = :member_id)',
                ['member_id' => $memberId],
                $identifiers
            );
        }
        if (member_erasure_table_exists($pdo, 'investment_payment_allocations')
            && member_erasure_table_exists($pdo, 'investment_payments')) {
            member_erasure_scrub_rows(
                $pdo,
                'investment_payment_allocations',
                'allocation_id',
                ['reason'],
                'payment_id IN (SELECT payment_id FROM investment_payments WHERE member_id = :member_id)',
                ['member_id' => $memberId],
                $identifiers
            );
        }

        if (member_erasure_table_exists($pdo, 'sms_campaign_recipients')) {
            member_erasure_scrub_rows(
                $pdo,
                'sms_campaign_recipients',
                'recipient_id',
                ['failure_reason', 'safe_response_message'],
                'member_id = :member_id',
                ['member_id' => $memberId],
                $identifiers
            );
            $pdo->prepare(
                "UPDATE sms_campaign_recipients
                 SET member_id = NULL,
                     recipient_name = 'Erased member',
                     normalized_phone = CONCAT('x', LPAD(HEX(recipient_id), 23, '0')),
                     rendered_message = '[redacted due to account erasure]'
                 WHERE member_id = :member_id"
            )->execute(['member_id' => $memberId]);
            if (member_erasure_table_exists($pdo, 'sms_campaigns')) {
                member_erasure_scrub_rows(
                    $pdo,
                    'sms_campaigns',
                    'campaign_id',
                    ['message_body'],
                    '1 = 1',
                    [],
                    $identifiers
                );
            }
            if (member_erasure_table_exists($pdo, 'sms_campaign_batches')) {
                member_erasure_scrub_rows(
                    $pdo,
                    'sms_campaign_batches',
                    'batch_id',
                    ['safe_response_message'],
                    '1 = 1',
                    [],
                    $identifiers
                );
            }
        }

        $profilePicture = is_string($member['profile_picture']) ? $member['profile_picture'] : null;
        if (!member_erasure_table_exists($pdo, 'member_erasure_file_cleanup')) {
            throw new RuntimeException('Apply the member erasure file-cleanup migration before erasing accounts.');
        }
        $picturePaths = member_erasure_profile_picture_paths($memberId, $profilePicture);
        foreach ($picturePaths as $picturePath) {
            member_erasure_queue_profile_picture_cleanup($pdo, $memberId, $picturePath);
        }

        $anonymousEmail = 'erased-member-' . $memberId . '@invalid.local';
        $anonymousPhone = 'erased-' . $memberId;
        $memberUpdate = $pdo->prepare(
            "UPDATE members
             SET name = 'Erased Member',
                 phone = :phone,
                 email = :email,
                 password_hash = :password_hash,
                 joined_date = '1970-01-01',
                 role = 'Member',
                 status = 'erased',
                 profile_picture = NULL,
                 reset_token_hash = NULL,
                 reset_expires = NULL
             WHERE member_id = :member_id"
        );
        $memberUpdate->execute([
            'phone' => $anonymousPhone,
            'email' => $anonymousEmail,
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'member_id' => $memberId,
        ]);

        $auditStmt = $pdo->prepare(
            'INSERT INTO member_erasure_audit (member_id, admin_id) VALUES (:member_id, :admin_id)'
        );
        $auditStmt->execute(['member_id' => $memberId, 'admin_id' => $adminId]);

        $pdo->commit();
        return ['member_id' => $memberId, 'profile_picture' => $profilePicture];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @return list<string> */
function member_erasure_profile_picture_paths(int $memberId, ?string $currentPath): array
{
    $paths = [];
    if (member_erasure_profile_picture_path_is_valid($currentPath, $memberId)) {
        $paths[] = $currentPath;
    }

    $directory = member_profile_picture_directory();
    if (is_link($directory)) {
        throw new RuntimeException('Profile-picture storage cannot be a symbolic-link folder.');
    }
    if (!is_dir($directory)) {
        return array_values(array_unique($paths));
    }
    $entries = scandir($directory);
    if ($entries === false) {
        throw new RuntimeException('Profile-picture cleanup could not inspect its storage directory.');
    }

    foreach ($entries as $entry) {
        $candidate = 'uploads/profile-pictures/' . $entry;
        if (member_erasure_profile_picture_path_is_valid($candidate, $memberId)) {
            $paths[] = $candidate;
        }
    }

    return array_values(array_unique($paths));
}

function member_erasure_queue_profile_picture_cleanup(PDO $pdo, int $memberId, string $relativePath): void
{
    if ($memberId < 1 || !member_erasure_profile_picture_path_is_valid($relativePath, $memberId)) {
        throw new InvalidArgumentException('The profile-picture cleanup path is invalid.');
    }
    if (!member_erasure_table_exists($pdo, 'member_erasure_file_cleanup')) {
        throw new RuntimeException('Apply the member erasure file-cleanup migration before queuing profile pictures.');
    }

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO member_erasure_file_cleanup (member_id, relative_path)
         VALUES (:member_id, :relative_path)'
    );
    $stmt->execute(['member_id' => $memberId, 'relative_path' => $relativePath]);
}

function member_erasure_delete_profile_picture(?string $relativePath, int $memberId): bool
{
    if ($relativePath === null || $relativePath === '') {
        return true;
    }
    if (!member_erasure_profile_picture_path_is_valid($relativePath, $memberId)) {
        return false;
    }

    $configuredDirectory = member_profile_picture_directory();
    if (is_link($configuredDirectory)) {
        return false;
    }
    $directory = realpath($configuredDirectory);
    if ($directory === false) {
        return true;
    }

    $file = $directory . DIRECTORY_SEPARATOR . basename($relativePath);
    if (!file_exists($file) && !is_link($file)) {
        return true;
    }
    $resolvedFile = realpath($file);
    if ($resolvedFile === false
        || !str_starts_with($resolvedFile, $directory . DIRECTORY_SEPARATOR)
        || is_link($file)
        || !is_file($resolvedFile)) {
        return false;
    }

    return unlink($resolvedFile);
}

function member_erasure_retry_profile_picture_cleanup(PDO $pdo, int $limit = 10, ?int $memberId = null): int
{
    if ($limit < 1 || $limit > 100 || !member_erasure_table_exists($pdo, 'member_erasure_file_cleanup')) {
        return 0;
    }

    $where = $memberId === null ? '' : ' WHERE member_id = :member_id';
    $query = $pdo->prepare(
        'SELECT cleanup_id, member_id, relative_path
         FROM member_erasure_file_cleanup' . $where . '
         ORDER BY cleanup_id ASC LIMIT ' . $limit
    );
    $query->execute($memberId === null ? [] : ['member_id' => $memberId]);
    $entries = $query->fetchAll(PDO::FETCH_ASSOC);
    $removed = 0;
    $delete = $pdo->prepare('DELETE FROM member_erasure_file_cleanup WHERE cleanup_id = :cleanup_id');
    $failed = $pdo->prepare(
        'UPDATE member_erasure_file_cleanup
         SET attempts = attempts + 1, last_attempt_at = CURRENT_TIMESTAMP
         WHERE cleanup_id = :cleanup_id'
    );

    foreach ($entries as $entry) {
        if (member_erasure_delete_profile_picture((string) $entry['relative_path'], (int) $entry['member_id'])) {
            $delete->execute(['cleanup_id' => $entry['cleanup_id']]);
            $removed += $delete->rowCount();
        } else {
            $failed->execute(['cleanup_id' => $entry['cleanup_id']]);
        }
    }

    return $removed;
}

function member_erasure_has_pending_profile_picture_cleanup(PDO $pdo, int $memberId): bool
{
    if (!member_erasure_table_exists($pdo, 'member_erasure_file_cleanup')) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM member_erasure_file_cleanup WHERE member_id = :member_id LIMIT 1'
    );
    $stmt->execute(['member_id' => $memberId]);
    return (bool) $stmt->fetchColumn();
}
