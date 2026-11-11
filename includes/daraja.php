<?php

declare(strict_types=1);

function ensure_daraja_investment_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS member_daraja_payments (
            member_payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            payment_type VARCHAR(30) NOT NULL,
            related_id INT UNSIGNED NULL,
            transaction_reference VARCHAR(32) NOT NULL,
            amount DECIMAL(12, 2) NOT NULL,
            phone_number VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            submission_key CHAR(64) NOT NULL,
            merchant_request_id VARCHAR(100) NULL,
            checkout_request_id VARCHAR(100) NULL,
            mpesa_receipt_number VARCHAR(32) NULL,
            request_result_code VARCHAR(20) NULL,
            request_result_description VARCHAR(255) NULL,
            callback_result_code VARCHAR(20) NULL,
            callback_result_description VARCHAR(255) NULL,
            callback_amount DECIMAL(12, 2) NULL,
            callback_phone_number VARCHAR(20) NULL,
            callback_receipt_number VARCHAR(32) NULL,
            callback_received_at DATETIME NULL,
            callback_payload_hash CHAR(64) NULL,
            verified_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (member_payment_id),
            UNIQUE KEY uq_member_daraja_reference (transaction_reference),
            UNIQUE KEY uq_member_daraja_submission (submission_key),
            UNIQUE KEY uq_member_daraja_checkout (checkout_request_id),
            UNIQUE KEY uq_member_daraja_receipt (mpesa_receipt_number),
            KEY idx_member_daraja_member (member_id, created_at),
            KEY idx_member_daraja_status (status, created_at),
            CONSTRAINT fk_member_daraja_member FOREIGN KEY (member_id) REFERENCES members (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS investment_group_plans (
            plan_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(160) NOT NULL,
            description VARCHAR(1000) NOT NULL,
            target_amount DECIMAL(12, 2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (plan_id),
            KEY idx_investment_group_plan_status (status, created_at),
            CONSTRAINT fk_investment_group_plan_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS investment_group_plan_audit (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id INT UNSIGNED NOT NULL,
            previous_title VARCHAR(160) NULL,
            new_title VARCHAR(160) NOT NULL,
            previous_target_amount DECIMAL(12, 2) NULL,
            new_target_amount DECIMAL(12, 2) NOT NULL,
            previous_status VARCHAR(20) NULL,
            new_status VARCHAR(20) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            admin_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (audit_id),
            KEY idx_investment_group_plan_audit (plan_id, created_at),
            CONSTRAINT fk_investment_group_plan_audit_plan FOREIGN KEY (plan_id) REFERENCES investment_group_plans (plan_id),
            CONSTRAINT fk_investment_group_plan_audit_admin FOREIGN KEY (admin_id) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS investment_payments (
            payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            goal_id INT UNSIGNED NULL,
            opportunity_id INT UNSIGNED NULL,
            group_plan_id INT UNSIGNED NULL,
            transaction_reference VARCHAR(32) NOT NULL,
            amount DECIMAL(12, 2) NOT NULL,
            phone_number VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            submission_key CHAR(64) NOT NULL,
            merchant_request_id VARCHAR(100) NULL,
            checkout_request_id VARCHAR(100) NULL,
            mpesa_receipt_number VARCHAR(32) NULL,
            request_result_code VARCHAR(20) NULL,
            request_result_description VARCHAR(255) NULL,
            callback_result_code VARCHAR(20) NULL,
            callback_result_description VARCHAR(255) NULL,
            callback_amount DECIMAL(12, 2) NULL,
            callback_phone_number VARCHAR(20) NULL,
            callback_receipt_number VARCHAR(32) NULL,
            callback_received_at DATETIME NULL,
            callback_payload_hash CHAR(64) NULL,
            verified_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (payment_id),
            UNIQUE KEY uq_investment_payment_reference (transaction_reference),
            UNIQUE KEY uq_investment_payment_submission (submission_key),
            UNIQUE KEY uq_investment_payment_checkout (checkout_request_id),
            UNIQUE KEY uq_investment_payment_receipt (mpesa_receipt_number),
            KEY idx_investment_payment_member (member_id, created_at),
            KEY idx_investment_payment_status (status, created_at),
            CONSTRAINT fk_investment_payment_member FOREIGN KEY (member_id) REFERENCES members (member_id),
            CONSTRAINT fk_investment_payment_goal FOREIGN KEY (goal_id) REFERENCES investment_goals (goal_id),
            CONSTRAINT fk_investment_payment_opportunity FOREIGN KEY (opportunity_id) REFERENCES investment_opportunities (opportunity_id),
            CONSTRAINT fk_investment_payment_group_plan FOREIGN KEY (group_plan_id) REFERENCES investment_group_plans (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS investment_payment_allocations (
            allocation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT UNSIGNED NOT NULL,
            goal_id INT UNSIGNED NULL,
            opportunity_id INT UNSIGNED NULL,
            group_plan_id INT UNSIGNED NULL,
            amount DECIMAL(12, 2) NOT NULL,
            allocated_by_admin INT UNSIGNED NULL,
            reason VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (allocation_id),
            UNIQUE KEY uq_investment_allocation_payment (payment_id),
            KEY idx_investment_allocation_goal (goal_id),
            KEY idx_investment_allocation_opportunity (opportunity_id),
            CONSTRAINT fk_investment_allocation_payment FOREIGN KEY (payment_id) REFERENCES investment_payments (payment_id),
            CONSTRAINT fk_investment_allocation_goal FOREIGN KEY (goal_id) REFERENCES investment_goals (goal_id),
            CONSTRAINT fk_investment_allocation_opportunity FOREIGN KEY (opportunity_id) REFERENCES investment_opportunities (opportunity_id),
            CONSTRAINT fk_investment_allocation_group_plan FOREIGN KEY (group_plan_id) REFERENCES investment_group_plans (plan_id),
            CONSTRAINT fk_investment_allocation_admin FOREIGN KEY (allocated_by_admin) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS investment_payment_audit (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(32) NOT NULL,
            previous_goal_id INT UNSIGNED NULL,
            previous_opportunity_id INT UNSIGNED NULL,
            previous_group_plan_id INT UNSIGNED NULL,
            previous_amount DECIMAL(12, 2) NULL,
            new_goal_id INT UNSIGNED NULL,
            new_opportunity_id INT UNSIGNED NULL,
            new_group_plan_id INT UNSIGNED NULL,
            new_amount DECIMAL(12, 2) NULL,
            result_code VARCHAR(20) NULL,
            result_description VARCHAR(255) NULL,
            reason VARCHAR(255) NULL,
            admin_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (audit_id),
            KEY idx_investment_payment_audit (payment_id, created_at),
            CONSTRAINT fk_investment_payment_audit_payment FOREIGN KEY (payment_id) REFERENCES investment_payments (payment_id),
            CONSTRAINT fk_investment_payment_audit_admin FOREIGN KEY (admin_id) REFERENCES admins (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $checked = true;
}

function save_investment_group_plan(array $input, int $adminId, ?int $planId = null): void
{
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $targetInput = trim((string) ($input['target_amount'] ?? ''));
    $target = filter_var($targetInput, FILTER_VALIDATE_FLOAT);
    $status = (string) ($input['status'] ?? 'active');
    $reason = trim((string) ($input['reason'] ?? ''));
    if ($adminId < 1 || ($planId !== null && $planId < 1) ||
        $title === '' || mb_strlen($title) > 160 ||
        $description === '' || mb_strlen($description) > 1000 ||
        $target === false || !is_finite((float) $target) ||
        $target < (float) financial_setting('investment_goal_minimum') || $target > 1000000000 ||
        !in_array($status, ['active', 'paused', 'completed'], true) ||
        $reason === '' || mb_strlen($reason) > 255) {
        throw new InvalidArgumentException('Enter a title, description, target of at least KES ' . number_format((float) financial_setting('investment_goal_minimum'), 2) . ', valid status, and an audit reason.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $previous = null;
        if ($planId !== null) {
            $find = $pdo->prepare('SELECT * FROM investment_group_plans WHERE plan_id = :id FOR UPDATE');
            $find->execute(['id' => $planId]);
            $previous = $find->fetch();
            if (!$previous) {
                throw new InvalidArgumentException('Group investment plan was not found.');
            }
            $save = $pdo->prepare(
                'UPDATE investment_group_plans
                 SET title = :title, description = :description, target_amount = :target_amount, status = :status
                 WHERE plan_id = :plan_id'
            );
            $save->execute([
                'title' => $title,
                'description' => $description,
                'target_amount' => round((float) $target, 2),
                'status' => $status,
                'plan_id' => $planId,
            ]);
        } else {
            $save = $pdo->prepare(
                'INSERT INTO investment_group_plans (title, description, target_amount, status, created_by)
                 VALUES (:title, :description, :target_amount, :status, :admin_id)'
            );
            $save->execute([
                'title' => $title,
                'description' => $description,
                'target_amount' => round((float) $target, 2),
                'status' => $status,
                'admin_id' => $adminId,
            ]);
            $planId = (int) $pdo->lastInsertId();
        }

        $audit = $pdo->prepare(
            'INSERT INTO investment_group_plan_audit
                (plan_id, previous_title, new_title, previous_target_amount, new_target_amount,
                 previous_status, new_status, reason, admin_id)
             VALUES
                (:plan_id, :previous_title, :new_title, :previous_target, :new_target,
                 :previous_status, :new_status, :reason, :admin_id)'
        );
        $audit->execute([
            'plan_id' => $planId,
            'previous_title' => $previous['title'] ?? null,
            'new_title' => $title,
            'previous_target' => $previous['target_amount'] ?? null,
            'new_target' => round((float) $target, 2),
            'previous_status' => $previous['status'] ?? null,
            'new_status' => $status,
            'reason' => $reason,
            'admin_id' => $adminId,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @return array{consumer_key: string, consumer_secret: string, shortcode: string, passkey: string, callback_url: string}
 */
function daraja_sandbox_config(): array
{
    if (strtolower(trim(env('DARAJA_ENV', 'sandbox'))) !== 'sandbox') {
        throw new RuntimeException('Only Daraja Sandbox is enabled for this application.');
    }

    $config = [
        'consumer_key' => trim(env('DARAJA_CONSUMER_KEY', '')),
        'consumer_secret' => trim(env('DARAJA_CONSUMER_SECRET', '')),
        'shortcode' => trim(env('DARAJA_SHORTCODE', '')),
        'passkey' => trim(env('DARAJA_PASSKEY', '')),
        'callback_url' => trim(env('DARAJA_CALLBACK_URL', '')),
    ];
    foreach ($config as $value) {
        if ($value === '') {
            throw new RuntimeException('Daraja Sandbox is not configured. Set the server-side sandbox credentials and public HTTPS callback URL.');
        }
    }
    if (filter_var($config['callback_url'], FILTER_VALIDATE_URL) === false ||
        strtolower((string) parse_url($config['callback_url'], PHP_URL_SCHEME)) !== 'https' ||
        parse_url($config['callback_url'], PHP_URL_USER) !== null ||
        parse_url($config['callback_url'], PHP_URL_PASS) !== null) {
        throw new RuntimeException('The Daraja callback must be a valid public HTTPS URL.');
    }

    return $config;
}

function daraja_sandbox_is_configured(): bool
{
    try {
        daraja_sandbox_config();
        return true;
    } catch (RuntimeException $exception) {
        return false;
    }
}

function daraja_phone_number(string $phone): string
{
    $phone = preg_replace('/[\s().-]/', '', trim($phone)) ?? '';
    if (!preg_match('/^(?:\+?254|0)([17]\d{8})$/', $phone, $matches)) {
        throw new InvalidArgumentException('Enter a valid Kenyan M-Pesa number, such as 0712345678 or 254712345678.');
    }

    return '254' . $matches[1];
}

function daraja_payment_amount(string $amountInput, ?int $minimumAmount = null): int
{
    $amountInput = trim($amountInput);
    if (!preg_match('/^\d+(?:\.0{1,2})?$/', $amountInput)) {
        throw new InvalidArgumentException('Enter a whole-number amount in KES. Daraja STK Push accepts whole shillings only.');
    }
    $amount = (int) $amountInput;
    $minimum = $minimumAmount ?? max(1, (int) ceil((float) financial_setting('investment_minimum')));
    if ($amount < $minimum || $amount > 150000) {
        throw new InvalidArgumentException('Enter an amount between KES ' . number_format($minimum) . ' and KES 150,000.');
    }

    return $amount;
}

function daraja_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('YmdHis');
}

function daraja_log_event(string $event, array $context = []): void
{
    $allowedFields = [
        'api_operation',
        'checkout_request_id',
        'merchant_request_id',
        'payment_reference',
        'payment_purpose',
        'http_status',
        'processing_result',
        'error_type',
    ];
    $entry = [
        'event' => $event,
        'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format(DATE_ATOM),
    ];
    if (defined('APP_ENV') && APP_ENV === 'test') {
        $entry['data_label'] = 'TEST DATA';
    }
    foreach ($allowedFields as $field) {
        if (isset($context[$field]) && (is_string($context[$field]) || is_int($context[$field]))) {
            $entry[$field] = $context[$field];
        }
    }
    error_log('Daraja ' . json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

function daraja_payment_status_label(string $status, bool $hasCheckoutId): string
{
    if ($status === 'pending') {
        return $hasCheckoutId ? 'Pending customer approval / verification' : 'Initiating / awaiting Daraja response';
    }

    return match ($status) {
        'successful' => 'Successful',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'timed_out' => 'Timed out',
        'review_required' => 'Review required',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function daraja_payment_status_class(string $status): string
{
    return match ($status) {
        'successful' => 'success',
        'failed', 'cancelled', 'timed_out' => 'danger',
        default => 'warning',
    };
}

function daraja_failure_status(string $resultCode): string
{
    return match ($resultCode) {
        '1032' => 'cancelled',
        '1037' => 'timed_out',
        default => 'failed',
    };
}

function daraja_api_request(
    string $url,
    string $method,
    ?array $payload = null,
    ?string $accessToken = null,
    array $logContext = []
): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Daraja Sandbox request could not be initialized.');
    }
    $headers = ['Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    $path = parse_url($url, PHP_URL_PATH);
    $logContext['api_operation'] = is_string($path) ? basename($path) : 'unknown';
    $logContext['http_status'] = $status;
    $logContext['processing_result'] = $body === false || $status < 200 || $status >= 300
        ? 'http_error'
        : 'http_response_received';
    daraja_log_event('api_response', $logContext);
    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($body === false
            ? 'Daraja Sandbox request failed: ' . ($curlError !== '' ? $curlError : 'network error')
            : 'Daraja Sandbox returned HTTP ' . $status . '.');
    }

    try {
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Daraja Sandbox returned an invalid response.', 0, $exception);
    }
    if (!is_array($response)) {
        throw new RuntimeException('Daraja Sandbox returned an invalid response.');
    }

    return $response;
}

function daraja_access_token(array $logContext = []): string
{
    static $token = null;
    if (is_string($token)) {
        return $token;
    }
    $config = daraja_sandbox_config();
    $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Daraja Sandbox authorization request could not be initialized.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']),
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    $logContext['api_operation'] = 'oauth_token';
    $logContext['http_status'] = $status;
    $logContext['processing_result'] = $body === false || $status < 200 || $status >= 300
        ? 'http_error'
        : 'http_response_received';
    daraja_log_event('api_response', $logContext);
    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($body === false
            ? 'Daraja Sandbox authorization failed: ' . ($curlError !== '' ? $curlError : 'network error')
            : 'Daraja Sandbox authorization returned HTTP ' . $status . '.');
    }

    try {
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Daraja Sandbox authorization returned an invalid response.', 0, $exception);
    }
    $token = is_array($response) ? ($response['access_token'] ?? null) : null;
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('Daraja Sandbox authorization response did not contain an access token.');
    }
    return $token;
}

function daraja_stk_password(array $config, string $timestamp): string
{
    return base64_encode($config['shortcode'] . $config['passkey'] . $timestamp);
}

function daraja_reference(): string
{
    return 'C' . strtoupper(base_convert(bin2hex(random_bytes(4)), 16, 36));
}

/**
 * @return array{payment_id: int, status: string, message: string}
 */
function start_member_daraja_payment(
    int $memberId,
    string $paymentType,
    ?int $relatedId,
    string $amountInput,
    string $phoneInput,
    string $submissionKey
): array {
    $allowedTypes = ['contribution', 'loan_repayment'];
    if ($memberId < 1 || !preg_match('/^[a-f0-9]{64}$/', $submissionKey) || !in_array($paymentType, $allowedTypes, true)) {
        throw new InvalidArgumentException('Choose a valid member payment request and try again.');
    }

    $amount = daraja_payment_amount($amountInput, 1);
    $phone = daraja_phone_number($phoneInput);
    $config = daraja_sandbox_config();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $member = $pdo->prepare('SELECT status FROM members WHERE member_id = :member_id FOR UPDATE');
        $member->execute(['member_id' => $memberId]);
        if ($member->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Only active members can initiate Daraja payments.');
        }

        $existing = $pdo->prepare('SELECT member_payment_id, member_id, status FROM member_daraja_payments WHERE submission_key = :submission_key LIMIT 1');
        $existing->execute(['submission_key' => $submissionKey]);
        $duplicate = $existing->fetch();
        if ($duplicate) {
            if ((int) $duplicate['member_id'] !== $memberId) {
                throw new InvalidArgumentException('This payment request key has already been used.');
            }
            $pdo->commit();
            return [
                'payment_id' => (int) $duplicate['member_payment_id'],
                'status' => (string) $duplicate['status'],
                'message' => 'This Daraja payment request was already submitted; no second STK Push was sent.',
            ];
        }

        if ($paymentType === 'loan_repayment') {
            if ($relatedId === null || $relatedId < 1) {
                throw new InvalidArgumentException('Choose a valid loan to repay.');
            }
            $loan = $pdo->prepare(
                'SELECT loan_id, outstanding_balance, status FROM loans WHERE loan_id = :loan_id AND member_id = :member_id FOR UPDATE'
            );
            $loan->execute(['loan_id' => $relatedId, 'member_id' => $memberId]);
            $loanData = $loan->fetch();
            if (!$loanData || !in_array((string) $loanData['status'], ['approved', 'overdue'], true)) {
                throw new InvalidArgumentException('Only approved or overdue loans can be repaid with Daraja.');
            }
            $maxAmount = (float) ($loanData['outstanding_balance'] ?? 0);
            if ($amount > (int) round($maxAmount)) {
                throw new InvalidArgumentException('Repayment amount cannot exceed the outstanding loan balance.');
            }
        }

        $reference = daraja_reference();
        $insert = $pdo->prepare(
            'INSERT INTO member_daraja_payments (member_id, payment_type, related_id, transaction_reference, amount, phone_number, status, submission_key, request_result_description)
             VALUES (:member_id, :payment_type, :related_id, :reference, :amount, :phone, :status, :submission_key, :description)'
        );
        $insert->execute([
            'member_id' => $memberId,
            'payment_type' => $paymentType,
            'related_id' => $paymentType === 'loan_repayment' ? $relatedId : null,
            'reference' => $reference,
            'amount' => $amount,
            'phone' => $phone,
            'status' => 'pending',
            'submission_key' => $submissionKey,
            'description' => $paymentType === 'contribution' ? 'Daraja contribution request created.' : 'Daraja loan repayment request created.',
        ]);
        $paymentId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $timestamp = daraja_timestamp();
    $logContext = [
        'payment_reference' => $reference,
        'payment_purpose' => $paymentType,
    ];
    try {
        $response = daraja_api_request(
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            'POST',
            [
                'BusinessShortCode' => $config['shortcode'],
                'Password' => daraja_stk_password($config, $timestamp),
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $amount,
                'PartyA' => $phone,
                'PartyB' => $config['shortcode'],
                'PhoneNumber' => $phone,
                'CallBackURL' => $config['callback_url'],
                'AccountReference' => $reference,
                'TransactionDesc' => $paymentType === 'contribution' ? 'ChamaWise contribution' : 'ChamaWise loan repayment',
            ],
            daraja_access_token($logContext),
            $logContext
        );
    } catch (Throwable $exception) {
        $update = $pdo->prepare(
            'UPDATE member_daraja_payments
             SET request_result_description = :description
             WHERE member_payment_id = :member_payment_id AND status = \'pending\''
        );
        $update->execute([
            'description' => mb_substr('Daraja response is unconfirmed: ' . $exception->getMessage(), 0, 255),
            'member_payment_id' => $paymentId,
        ]);
        daraja_log_event('stk_initiation_unconfirmed', $logContext + [
            'processing_result' => 'pending_confirmation',
            'error_type' => get_class($exception),
        ]);
        return [
            'payment_id' => $paymentId,
            'status' => 'pending',
            'message' => 'Daraja could not confirm the STK request. The payment remains pending; check the transaction status before retrying.',
        ];
    }

    $responseCode = isset($response['ResponseCode']) ? (string) $response['ResponseCode'] : null;
    $checkoutId = isset($response['CheckoutRequestID']) ? trim((string) $response['CheckoutRequestID']) : '';
    $merchantId = isset($response['MerchantRequestID']) ? trim((string) $response['MerchantRequestID']) : '';
    $description = mb_substr(trim((string) ($response['CustomerMessage'] ?? $response['ResponseDescription'] ?? '')), 0, 255);
    $status = $responseCode === '0' && $checkoutId !== '' && $merchantId !== '' ? 'pending' : 'failed';
    $update = $pdo->prepare(
        'UPDATE member_daraja_payments
         SET status = :status, merchant_request_id = :merchant_id, checkout_request_id = :checkout_id,
             request_result_code = :result_code, request_result_description = :description
         WHERE member_payment_id = :member_payment_id AND status = \'pending\''
    );
    $update->execute([
        'status' => $status,
        'merchant_id' => $merchantId !== '' ? $merchantId : null,
        'checkout_id' => $checkoutId !== '' ? $checkoutId : null,
        'result_code' => $responseCode,
        'description' => $description !== '' ? $description : 'Daraja did not accept the payment request.',
        'member_payment_id' => $paymentId,
    ]);
    daraja_log_event('stk_initiation_result', $logContext + [
        'checkout_request_id' => $checkoutId,
        'merchant_request_id' => $merchantId,
        'processing_result' => $status,
    ]);

    return [
        'payment_id' => $paymentId,
        'status' => $status,
        'message' => $status === 'pending'
            ? 'STK Push sent to ' . $phone . '. Approve the prompt on your phone; your contribution or loan repayment is only recorded after verification.'
            : 'Daraja did not accept the payment request. No payment was recorded.',
    ];
}

function handle_daraja_member_callback(array $payload, string $rawBody): bool
{
    $callback = $payload['Body']['stkCallback'] ?? null;
    if (!is_array($callback)) {
        return false;
    }

    $checkoutId = trim((string) ($callback['CheckoutRequestID'] ?? ''));
    if ($checkoutId === '' || mb_strlen($checkoutId) > 100) {
        return false;
    }

    $resultCode = isset($callback['ResultCode']) ? (string) $callback['ResultCode'] : '';
    if ($resultCode === '' || strlen($resultCode) > 20 || !preg_match('/^\d+$/', $resultCode)) {
        daraja_log_event('callback_rejected', [
            'checkout_request_id' => $checkoutId,
            'processing_result' => 'invalid_result_code',
        ]);
        return false;
    }
    $resultDescription = mb_substr(trim((string) ($callback['ResultDesc'] ?? '')), 0, 255);
    $metadata = [];
    $items = $callback['CallbackMetadata']['Item'] ?? [];
    foreach (is_array($items) ? $items : [] as $item) {
        if (is_array($item) && isset($item['Name'])) {
            $metadata[(string) $item['Name']] = $item['Value'] ?? null;
        }
    }
    $callbackAmount = isset($metadata['Amount']) && is_numeric($metadata['Amount']) ? (float) $metadata['Amount'] : null;
    $callbackPhone = null;
    if (isset($metadata['PhoneNumber'])) {
        try {
            $callbackPhone = daraja_phone_number((string) $metadata['PhoneNumber']);
        } catch (InvalidArgumentException $exception) {
            $callbackPhone = null;
        }
    }
    $receipt = isset($metadata['MpesaReceiptNumber'])
        ? strtoupper(trim((string) $metadata['MpesaReceiptNumber']))
        : null;
    if ($receipt !== null && !preg_match('/^[A-Z0-9]{6,32}$/', $receipt)) {
        $receipt = null;
    }

    $pdo = db();
    $paymentStmt = $pdo->prepare('SELECT member_payment_id, member_id, payment_type, related_id, amount, phone_number, transaction_reference, merchant_request_id FROM member_daraja_payments WHERE checkout_request_id = :checkout_id LIMIT 1');
    $paymentStmt->execute(['checkout_id' => $checkoutId]);
    $payment = $paymentStmt->fetch();
    if (!$payment) {
        daraja_log_event('callback_unmatched', [
            'checkout_request_id' => $checkoutId,
            'processing_result' => 'unknown_checkout_request',
        ]);
        return false;
    }

    $callbackMerchantId = trim((string) ($callback['MerchantRequestID'] ?? ''));
    $merchantMatches = $callbackMerchantId !== '' &&
        !empty($payment['merchant_request_id']) &&
        hash_equals((string) $payment['merchant_request_id'], $callbackMerchantId);
    if (!$merchantMatches) {
        daraja_log_event('callback_rejected', [
            'checkout_request_id' => $checkoutId,
            'payment_reference' => (string) $payment['transaction_reference'],
            'payment_purpose' => (string) $payment['payment_type'],
            'processing_result' => 'merchant_request_id_mismatch',
        ]);
        return true;
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE member_daraja_payments
             SET callback_result_code = :result_code, callback_result_description = :description,
                 callback_amount = :amount, callback_phone_number = :phone, callback_receipt_number = :receipt,
                 callback_received_at = NOW(), callback_payload_hash = :payload_hash
             WHERE member_payment_id = :member_payment_id'
        );
        $update->execute([
            'result_code' => $resultCode,
            'description' => $resultDescription,
            'amount' => $callbackAmount,
            'phone' => $callbackPhone,
            'receipt' => $receipt,
            'payload_hash' => hash('sha256', $rawBody),
            'member_payment_id' => (int) $payment['member_payment_id'],
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $logContext = [
        'checkout_request_id' => $checkoutId,
        'merchant_request_id' => $callbackMerchantId,
        'payment_reference' => (string) $payment['transaction_reference'],
        'payment_purpose' => (string) $payment['payment_type'],
        'processing_result' => $resultCode === '0' ? 'success_callback_received' : 'failure_callback_received',
    ];
    daraja_log_event('callback_received', $logContext);

    try {
        reconcile_daraja_member_payment((int) $payment['member_payment_id']);
    } catch (Throwable $exception) {
        daraja_log_event('callback_reconciliation_error', array_merge($logContext, [
            'processing_result' => 'verification_pending',
            'error_type' => get_class($exception),
        ]));
    }

    return true;
}

function reconcile_daraja_member_payment(int $memberPaymentId): array
{
    if ($memberPaymentId < 1) {
        throw new InvalidArgumentException('Choose a valid Daraja member payment to reconcile.');
    }

    $stmt = db()->prepare('SELECT * FROM member_daraja_payments WHERE member_payment_id = :member_payment_id LIMIT 1');
    $stmt->execute(['member_payment_id' => $memberPaymentId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        throw new InvalidArgumentException('member_daraja_payments record was not found.');
    }
    if (empty($payment['checkout_request_id'])) {
        throw new InvalidArgumentException('Daraja has not provided a checkout request ID for this payment.');
    }

    $query = daraja_query_stk_payment((string) $payment['checkout_request_id'], [
        'payment_reference' => (string) $payment['transaction_reference'],
        'payment_purpose' => (string) $payment['payment_type'],
    ]);
    $queryCode = $query['result_code'];
    $queryDescription = $query['result_description'];
    $callbackMatches = $payment['callback_result_code'] === '0' &&
        $payment['callback_amount'] !== null &&
        (float) $payment['callback_amount'] === (float) $payment['amount'] &&
        $payment['callback_phone_number'] === $payment['phone_number'] &&
        !empty($payment['callback_receipt_number']);

    $status = (string) $payment['status'];
    $message = 'Payment remains pending until Daraja confirms the transaction and the callback matches the original request.';
    if ($queryCode === '0' && $callbackMatches) {
        $status = 'successful';
        $message = 'Daraja confirmed the payment and the callback matches the request exactly.';
    } elseif ($queryCode !== '' && $queryCode !== '0' && $payment['callback_result_code'] !== null && $payment['callback_result_code'] !== '0') {
        $status = daraja_failure_status((string) $payment['callback_result_code']);
        $message = match ($status) {
            'cancelled' => 'The M-Pesa prompt was cancelled. No payment was recorded.',
            'timed_out' => 'The M-Pesa prompt timed out. No payment was recorded.',
            default => 'Daraja confirmed that the STK payment did not complete.',
        };
    } elseif ($queryCode === '0' && !$callbackMatches) {
        $message = 'Daraja query returned success, but the callback amount, phone or receipt is missing or mismatched. No payment was credited.';
    } elseif ($queryCode !== '') {
        $message = 'Daraja payment status: ' . $queryDescription;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $locked = $pdo->prepare('SELECT status FROM member_daraja_payments WHERE member_payment_id = :member_payment_id FOR UPDATE');
        $locked->execute(['member_payment_id' => $memberPaymentId]);
        $currentStatus = $locked->fetchColumn();
        if ($currentStatus === false) {
            throw new InvalidArgumentException('Daraja member payment was not found.');
        }
        if ($currentStatus === 'successful') {
            $status = 'successful';
        }
        $loanData = null;
        if ($currentStatus !== 'successful' && $status === 'successful' &&
            (string) $payment['payment_type'] === 'loan_repayment') {
            $loan = $pdo->prepare(
                'SELECT loan_id, member_id, outstanding_balance, due_date, status
                 FROM loans
                 WHERE loan_id = :loan_id AND member_id = :member_id
                 FOR UPDATE'
            );
            $loan->execute([
                'loan_id' => (int) $payment['related_id'],
                'member_id' => (int) $payment['member_id'],
            ]);
            $loanData = $loan->fetch();
            if (!$loanData || (float) ($loanData['outstanding_balance'] ?? 0) + 0.00001 < (float) $payment['amount']) {
                $status = 'review_required';
                $message = 'Daraja verified this payment, but the remaining loan balance is lower than the repayment. No loan credit was made; admin review is required.';
            }
        }

        $update = $pdo->prepare(
            'UPDATE member_daraja_payments
             SET status = :status,
                 mpesa_receipt_number = CASE WHEN :receipt_status IN (\'successful\', \'review_required\') THEN callback_receipt_number ELSE mpesa_receipt_number END,
                 verified_at = CASE WHEN :verified_status IN (\'successful\', \'review_required\') THEN COALESCE(verified_at, NOW()) ELSE verified_at END
             WHERE member_payment_id = :member_payment_id'
        );
        $update->execute([
            'status' => $status,
            'receipt_status' => $status,
            'verified_status' => $status,
            'member_payment_id' => $memberPaymentId,
        ]);

        if ($status === 'successful') {
            $paymentType = (string) $payment['payment_type'];
            $paymentMethodId = (int) $pdo->query("SELECT payment_method_id FROM payment_methods WHERE code = 'daraja' LIMIT 1")->fetchColumn();
            $receipt = trim((string) $payment['callback_receipt_number']);

            if ($paymentType === 'contribution') {
                $duplicateContribution = $pdo->prepare('SELECT contribution_id FROM contributions WHERE reference = :reference LIMIT 1');
                $duplicateContribution->execute(['reference' => $receipt]);
                if (!$duplicateContribution->fetch()) {
                    $insertContribution = $pdo->prepare(
                        'INSERT INTO contributions (member_id, amount, currency_code, payment_method_id, contribution_date, status, reference, notes)
                         VALUES (:member_id, :amount, :currency_code, :payment_method_id, CURDATE(), :status, :reference, :notes)'
                    );
                    $insertContribution->execute([
                        'member_id' => (int) $payment['member_id'],
                        'amount' => $payment['amount'],
                        'currency_code' => 'KES',
                        'payment_method_id' => $paymentMethodId > 0 ? $paymentMethodId : null,
                        'status' => 'recorded',
                        'reference' => $receipt !== '' ? $receipt : $payment['transaction_reference'],
                        'notes' => 'Recorded via verified Daraja M-Pesa payment.',
                    ]);
                    sync_contribution_penalty((int) $pdo->lastInsertId(), (int) $payment['member_id']);
                }
            }

            if ($paymentType === 'loan_repayment' && $loanData) {
                $duplicateRepayment = $pdo->prepare('SELECT repayment_id FROM loan_repayments WHERE loan_id = :loan_id AND reference = :reference LIMIT 1');
                $duplicateRepayment->execute([
                    'loan_id' => (int) $payment['related_id'],
                    'reference' => $receipt !== '' ? $receipt : $payment['transaction_reference'],
                ]);
                if (!$duplicateRepayment->fetch()) {
                    if ($loanData) {
                        $newAmount = round((float) $payment['amount'], 2);
                        $newBalance = round((float) ($loanData['outstanding_balance'] ?? 0) - $newAmount, 2);
                        $repaymentStmt = $pdo->prepare(
                            'INSERT INTO loan_repayments (loan_id, amount, payment_date, payment_method_id, reference, recorded_by)
                             VALUES (:loan_id, :amount, CURDATE(), :payment_method_id, :reference, NULL)'
                        );
                        $repaymentStmt->execute([
                            'loan_id' => (int) $payment['related_id'],
                            'amount' => $newAmount,
                            'payment_method_id' => $paymentMethodId > 0 ? $paymentMethodId : null,
                            'reference' => $receipt !== '' ? $receipt : $payment['transaction_reference'],
                        ]);
                        $statusValue = $newBalance <= 0 ? 'completed' : ((string) ($loanData['due_date'] ?? '') < date('Y-m-d') ? 'overdue' : 'approved');
                        $updateLoan = $pdo->prepare('UPDATE loans SET outstanding_balance = :balance, status = :status WHERE loan_id = :loan_id');
                        $updateLoan->execute([
                            'balance' => max(0.0, $newBalance),
                            'status' => $statusValue,
                            'loan_id' => (int) $payment['related_id'],
                        ]);
                    }
                }
            }
        }

        $pdo->commit();
        daraja_log_event('payment_reconciled', [
            'payment_reference' => (string) $payment['transaction_reference'],
            'payment_purpose' => (string) $payment['payment_type'],
            'processing_result' => $status,
        ]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['status' => $status, 'message' => $message];
}

/**
 * @return array{payment_id: int, status: string, message: string}
 */
function start_investment_stk_payment(
    int $memberId,
    ?int $goalId,
    ?int $opportunityId,
    ?int $groupPlanId,
    string $amountInput,
    string $phoneInput,
    string $submissionKey
): array {
    $targetCount = (int) (($goalId ?? 0) > 0) + (int) (($opportunityId ?? 0) > 0) + (int) (($groupPlanId ?? 0) > 0);
    if ($memberId < 1 || $targetCount !== 1 || !preg_match('/^[a-f0-9]{64}$/', $submissionKey)) {
        throw new InvalidArgumentException('Choose one investment goal, opportunity or group plan and try again.');
    }
    $amount = daraja_payment_amount($amountInput);
    $phone = daraja_phone_number($phoneInput);
    $config = daraja_sandbox_config();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $member = $pdo->prepare('SELECT status FROM members WHERE member_id = :member_id FOR UPDATE');
        $member->execute(['member_id' => $memberId]);
        if ($member->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Only active members can initiate investment payments.');
        }

        $existing = $pdo->prepare('SELECT payment_id, member_id, status FROM investment_payments WHERE submission_key = :key LIMIT 1');
        $existing->execute(['key' => $submissionKey]);
        $duplicate = $existing->fetch();
        if ($duplicate) {
            if ((int) $duplicate['member_id'] !== $memberId) {
                throw new InvalidArgumentException('This payment request key has already been used.');
            }
            $pdo->commit();
            return [
                'payment_id' => (int) $duplicate['payment_id'],
                'status' => (string) $duplicate['status'],
                'message' => 'This payment request was already submitted; no second STK Push was sent.',
            ];
        }

        $selectedGoalId = ($goalId ?? 0) > 0 ? $goalId : null;
        $selectedOpportunityId = ($opportunityId ?? 0) > 0 ? $opportunityId : null;
        $selectedGroupPlanId = ($groupPlanId ?? 0) > 0 ? $groupPlanId : null;
        if ($selectedGoalId !== null) {
            $selection = $pdo->prepare(
                "SELECT goal_id FROM investment_goals
                 WHERE goal_id = :goal_id AND member_id = :member_id AND status = 'active' FOR UPDATE"
            );
            $selection->execute(['goal_id' => $selectedGoalId, 'member_id' => $memberId]);
            if (!$selection->fetchColumn()) {
                throw new InvalidArgumentException('Choose one of your active investment goals.');
            }
        } elseif ($selectedOpportunityId !== null) {
            $selection = $pdo->prepare('SELECT opportunity_id FROM investment_opportunities WHERE opportunity_id = :id AND is_active = 1');
            $selection->execute(['id' => $selectedOpportunityId]);
            if (!$selection->fetchColumn()) {
                throw new InvalidArgumentException('Choose an active investment opportunity.');
            }
        } else {
            $selection = $pdo->prepare("SELECT plan_id FROM investment_group_plans WHERE plan_id = :id AND status = 'active'");
            $selection->execute(['id' => $selectedGroupPlanId]);
            if (!$selection->fetchColumn()) {
                throw new InvalidArgumentException('Choose an active group investment plan.');
            }
        }

        $reference = daraja_reference();
        $insert = $pdo->prepare(
            "INSERT INTO investment_payments
                (member_id, goal_id, opportunity_id, group_plan_id, transaction_reference, amount, phone_number, status, submission_key,
                 request_result_description)
             VALUES
                (:member_id, :goal_id, :opportunity_id, :group_plan_id, :reference, :amount, :phone, 'pending', :submission_key,
                 'Investment STK request submitted to Daraja Sandbox.')"
        );
        $insert->execute([
            'member_id' => $memberId,
            'goal_id' => $selectedGoalId,
            'opportunity_id' => $selectedOpportunityId,
            'group_plan_id' => $selectedGroupPlanId,
            'reference' => $reference,
            'amount' => $amount,
            'phone' => $phone,
            'submission_key' => $submissionKey,
        ]);
        $paymentId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $timestamp = daraja_timestamp();
    $logContext = [
        'payment_reference' => $reference,
        'payment_purpose' => 'investment',
    ];
    try {
        $response = daraja_api_request(
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            'POST',
            [
                'BusinessShortCode' => $config['shortcode'],
                'Password' => daraja_stk_password($config, $timestamp),
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $amount,
                'PartyA' => $phone,
                'PartyB' => $config['shortcode'],
                'PhoneNumber' => $phone,
                'CallBackURL' => $config['callback_url'],
                'AccountReference' => $reference,
                'TransactionDesc' => 'ChamaWise investment',
            ],
            daraja_access_token($logContext),
            $logContext
        );
    } catch (Throwable $exception) {
        $update = $pdo->prepare(
            'UPDATE investment_payments
             SET request_result_description = :description
             WHERE payment_id = :payment_id AND status = \'pending\''
        );
        $update->execute([
            'description' => mb_substr('Daraja response is unconfirmed: ' . $exception->getMessage(), 0, 255),
            'payment_id' => $paymentId,
        ]);
        daraja_log_event('stk_initiation_unconfirmed', $logContext + [
            'processing_result' => 'pending_confirmation',
            'error_type' => get_class($exception),
        ]);
        return [
            'payment_id' => $paymentId,
            'status' => 'pending',
            'message' => 'Daraja could not confirm the STK request. Its status is pending; do not retry with a new payment until you check the transaction history.',
        ];
    }

    $responseCode = isset($response['ResponseCode']) ? (string) $response['ResponseCode'] : null;
    $checkoutId = isset($response['CheckoutRequestID']) ? trim((string) $response['CheckoutRequestID']) : '';
    $merchantId = isset($response['MerchantRequestID']) ? trim((string) $response['MerchantRequestID']) : '';
    $description = mb_substr(trim((string) ($response['CustomerMessage'] ?? $response['ResponseDescription'] ?? '')), 0, 255);
    $status = $responseCode === '0' && $checkoutId !== '' && $merchantId !== '' ? 'pending' : 'failed';
    $update = $pdo->prepare(
        'UPDATE investment_payments
         SET status = :status, merchant_request_id = :merchant_id, checkout_request_id = :checkout_id,
             request_result_code = :result_code, request_result_description = :description
         WHERE payment_id = :payment_id AND status = \'pending\''
    );
    $update->execute([
        'status' => $status,
        'merchant_id' => $merchantId !== '' ? $merchantId : null,
        'checkout_id' => $checkoutId !== '' ? $checkoutId : null,
        'result_code' => $responseCode,
        'description' => $description !== '' ? $description : 'Daraja did not accept the investment STK request.',
        'payment_id' => $paymentId,
    ]);
    daraja_log_event('stk_initiation_result', $logContext + [
        'checkout_request_id' => $checkoutId,
        'merchant_request_id' => $merchantId,
        'processing_result' => $status,
    ]);

    return [
        'payment_id' => $paymentId,
        'status' => $status,
        'message' => $status === 'pending'
            ? 'STK Push sent to ' . $phone . '. Approve the prompt on your phone; your investment updates only after Daraja verification.'
            : 'Daraja did not accept the STK request. No investment payment was recorded.',
    ];
}

/** @param callable|null $provider */
function daraja_set_test_query_result_provider(?callable $provider): void
{
    if (PHP_SAPI !== 'cli' || !defined('APP_ENV') || APP_ENV !== 'test') {
        throw new LogicException('Simulated Daraja query results are available only in CLI test mode.');
    }

    $GLOBALS['daraja_test_query_result_provider'] = $provider;
}

/**
 * @return array{result_code: string, result_description: string}
 */
function daraja_query_stk_payment(string $checkoutRequestId, array $logContext = []): array
{
    if (defined('APP_ENV') && APP_ENV === 'test') {
        if (PHP_SAPI !== 'cli') {
            throw new LogicException('Simulated Daraja query results are restricted to CLI.');
        }
        $provider = $GLOBALS['daraja_test_query_result_provider'] ?? null;
        if (!is_callable($provider)) {
            throw new RuntimeException('No test Daraja query result provider has been configured.');
        }
        $result = $provider($checkoutRequestId);
        if (!is_array($result) ||
            !isset($result['result_code'], $result['result_description']) ||
            !is_string($result['result_code']) ||
            !is_string($result['result_description']) ||
            ($result['result_code'] !== '' && !preg_match('/^\d+$/', $result['result_code']))) {
            throw new RuntimeException('The test Daraja query provider returned an invalid result.');
        }
        daraja_log_event('stk_query_result', $logContext + [
            'checkout_request_id' => $checkoutRequestId,
            'processing_result' => 'TEST DATA ' . $result['result_code'],
        ]);
        return [
            'result_code' => $result['result_code'],
            'result_description' => mb_substr(trim($result['result_description']), 0, 255),
        ];
    }

    $config = daraja_sandbox_config();
    $timestamp = daraja_timestamp();
    $logContext['checkout_request_id'] = $checkoutRequestId;
    $response = daraja_api_request(
        'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query',
        'POST',
        [
            'BusinessShortCode' => $config['shortcode'],
            'Password' => daraja_stk_password($config, $timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ],
        daraja_access_token($logContext),
        $logContext
    );

    return [
        'result_code' => isset($response['ResultCode']) ? (string) $response['ResultCode'] : '',
        'result_description' => mb_substr(trim((string) ($response['ResultDesc'] ?? $response['ResponseDescription'] ?? 'Daraja returned no transaction result.')), 0, 255),
    ];
}

function handle_daraja_investment_callback(array $payload, string $rawBody): bool
{
    $callback = $payload['Body']['stkCallback'] ?? null;
    if (!is_array($callback)) {
        return false;
    }
    $checkoutId = trim((string) ($callback['CheckoutRequestID'] ?? ''));
    if ($checkoutId === '' || mb_strlen($checkoutId) > 100) {
        return false;
    }
    $resultCode = isset($callback['ResultCode']) ? (string) $callback['ResultCode'] : '';
    if ($resultCode === '' || strlen($resultCode) > 20 || !preg_match('/^\d+$/', $resultCode)) {
        daraja_log_event('callback_rejected', [
            'checkout_request_id' => $checkoutId,
            'payment_purpose' => 'investment',
            'processing_result' => 'invalid_result_code',
        ]);
        return false;
    }
    $callbackMerchantId = isset($callback['MerchantRequestID'])
        ? trim((string) $callback['MerchantRequestID'])
        : '';
    $resultDescription = mb_substr(trim((string) ($callback['ResultDesc'] ?? '')), 0, 255);
    $metadata = [];
    $metadataItems = $callback['CallbackMetadata']['Item'] ?? [];
    foreach (is_array($metadataItems) ? $metadataItems : [] as $item) {
        if (is_array($item) && isset($item['Name'])) {
            $metadata[(string) $item['Name']] = $item['Value'] ?? null;
        }
    }
    $callbackAmount = isset($metadata['Amount']) && is_numeric($metadata['Amount'])
        ? (float) $metadata['Amount']
        : null;
    $callbackPhone = null;
    if (isset($metadata['PhoneNumber'])) {
        try {
            $callbackPhone = daraja_phone_number((string) $metadata['PhoneNumber']);
        } catch (InvalidArgumentException $exception) {
            $callbackPhone = null;
        }
    }
    $receipt = isset($metadata['MpesaReceiptNumber'])
        ? strtoupper(trim((string) $metadata['MpesaReceiptNumber']))
        : null;
    if ($receipt !== null && !preg_match('/^[A-Z0-9]{6,32}$/', $receipt)) {
        $receipt = null;
    }

    $pdo = db();
    $paymentStmt = $pdo->prepare(
        'SELECT payment_id, merchant_request_id, transaction_reference
         FROM investment_payments
         WHERE checkout_request_id = :checkout_id
         LIMIT 1'
    );
    $paymentStmt->execute(['checkout_id' => $checkoutId]);
    $payment = $paymentStmt->fetch();
    if (!$payment) {
        daraja_log_event('callback_rejected', [
            'checkout_request_id' => $checkoutId,
            'payment_purpose' => 'investment',
            'processing_result' => 'unknown_checkout_request',
        ]);
        return false;
    }
    $paymentId = (int) $payment['payment_id'];
    $merchantMatches = $callbackMerchantId !== '' &&
        hash_equals((string) ($payment['merchant_request_id'] ?? ''), $callbackMerchantId);

    $pdo->beginTransaction();
    try {
        $locked = $pdo->prepare('SELECT status, merchant_request_id FROM investment_payments WHERE payment_id = :id FOR UPDATE');
        $locked->execute(['id' => $paymentId]);
        $lockedPayment = $locked->fetch();
        if (!$lockedPayment) {
            $pdo->rollBack();
            return false;
        }
        $merchantMatches = $callbackMerchantId !== '' &&
            hash_equals((string) ($lockedPayment['merchant_request_id'] ?? ''), $callbackMerchantId);
        if (!$merchantMatches) {
            $event = $pdo->prepare(
                "INSERT INTO investment_payment_audit
                    (payment_id, action, result_code, result_description, reason)
                 VALUES (:payment_id, 'callback_rejected', :result_code, :description, :reason)"
            );
            $event->execute([
                'payment_id' => $paymentId,
                'result_code' => $resultCode,
                'description' => $resultDescription,
                'reason' => 'Callback MerchantRequestID did not match the stored Daraja request.',
            ]);
            $pdo->commit();
            daraja_log_event('callback_rejected', [
                'payment_reference' => (string) $payment['transaction_reference'],
                'checkout_request_id' => $checkoutId,
                'payment_purpose' => 'investment',
                'processing_result' => 'merchant_request_id_mismatch',
            ]);
            return true;
        }
        $update = $pdo->prepare(
            'UPDATE investment_payments
             SET callback_result_code = :result_code, callback_result_description = :description,
                 callback_amount = :amount, callback_phone_number = :phone, callback_receipt_number = :receipt,
                 callback_received_at = NOW(), callback_payload_hash = :payload_hash
             WHERE payment_id = :payment_id'
        );
        $update->execute([
            'result_code' => $resultCode,
            'description' => $resultDescription,
            'amount' => $callbackAmount,
            'phone' => $callbackPhone,
            'receipt' => $receipt,
            'payload_hash' => hash('sha256', $rawBody),
            'payment_id' => $paymentId,
        ]);
        $event = $pdo->prepare(
            "INSERT INTO investment_payment_audit
                (payment_id, action, result_code, result_description, reason)
             VALUES (:payment_id, 'callback_received', :result_code, :description, :reason)"
        );
        $event->execute([
            'payment_id' => $paymentId,
            'result_code' => $resultCode,
            'description' => $resultDescription,
            'reason' => 'Callback recorded; payment remains uncredited until Daraja Sandbox query verification.',
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    try {
        $result = reconcile_daraja_investment_payment($paymentId);
        daraja_log_event('callback_reconciled', [
            'payment_reference' => (string) $payment['transaction_reference'],
            'checkout_request_id' => $checkoutId,
            'payment_purpose' => 'investment',
            'processing_result' => (string) $result['status'],
        ]);
    } catch (Throwable $exception) {
        daraja_log_event('callback_reconciliation_error', [
            'payment_reference' => (string) $payment['transaction_reference'],
            'checkout_request_id' => $checkoutId,
            'payment_purpose' => 'investment',
            'processing_result' => 'verification_pending',
            'error_type' => get_class($exception),
        ]);
    }

    return true;
}

/**
 * Reconcile by Daraja's server-side STK query. A successful query alone is
 * insufficient: the callback's amount, phone and receipt must also match.
 *
 * @return array{status: string, message: string}
 */
function reconcile_daraja_investment_payment(int $paymentId, ?int $adminId = null): array
{
    if ($paymentId < 1 || ($adminId !== null && $adminId < 1)) {
        throw new InvalidArgumentException('Choose a valid investment payment to reconcile.');
    }
    $stmt = db()->prepare('SELECT * FROM investment_payments WHERE payment_id = :id LIMIT 1');
    $stmt->execute(['id' => $paymentId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        throw new InvalidArgumentException('Investment payment was not found.');
    }
    if (empty($payment['checkout_request_id'])) {
        throw new InvalidArgumentException('Daraja has not provided a checkout request ID for this payment.');
    }

    $logContext = [
        'payment_reference' => (string) $payment['transaction_reference'],
        'checkout_request_id' => (string) $payment['checkout_request_id'],
        'payment_purpose' => 'investment',
    ];
    $query = daraja_query_stk_payment((string) $payment['checkout_request_id'], $logContext);
    $queryCode = $query['result_code'];
    $queryDescription = $query['result_description'];
    $callbackMatches = $payment['callback_result_code'] === '0' &&
        $payment['callback_amount'] !== null &&
        (float) $payment['callback_amount'] === (float) $payment['amount'] &&
        $payment['callback_phone_number'] === $payment['phone_number'] &&
        !empty($payment['callback_receipt_number']);

    $status = (string) $payment['status'];
    $message = 'Payment remains pending until Daraja confirms the transaction and matching callback details are available.';
    if ($queryCode === '0' && $callbackMatches) {
        $status = 'successful';
        $message = 'Daraja confirmed the payment and its amount, phone and receipt match the request.';
    } elseif ($queryCode !== '' && $queryCode !== '0' && $payment['callback_result_code'] !== null &&
        $payment['callback_result_code'] !== '0') {
        $status = daraja_failure_status((string) $payment['callback_result_code']);
        $message = match ($status) {
            'cancelled' => 'The M-Pesa prompt was cancelled. No investment was recorded.',
            'timed_out' => 'The M-Pesa prompt timed out. No investment was recorded.',
            default => 'Daraja confirmed that the STK payment did not complete.',
        };
    } elseif ($queryCode === '0' && !$callbackMatches) {
        $message = 'Daraja query returned success, but the callback amount, phone or receipt is missing or mismatched. No investment credit was made.';
    } elseif ($queryCode !== '') {
        $message = 'Daraja payment status: ' . $queryDescription;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $locked = $pdo->prepare('SELECT status FROM investment_payments WHERE payment_id = :id FOR UPDATE');
        $locked->execute(['id' => $paymentId]);
        $currentStatus = $locked->fetchColumn();
        if ($currentStatus === false) {
            throw new InvalidArgumentException('Investment payment was not found.');
        }
        if ($currentStatus === 'successful') {
            $status = 'successful';
        }
        $update = $pdo->prepare(
            'UPDATE investment_payments
             SET status = :status,
                 mpesa_receipt_number = CASE WHEN :receipt_status = \'successful\' THEN callback_receipt_number ELSE mpesa_receipt_number END,
                 verified_at = CASE WHEN :verified_status = \'successful\' THEN COALESCE(verified_at, NOW()) ELSE verified_at END
             WHERE payment_id = :payment_id'
        );
        $update->execute([
            'status' => $status,
            'receipt_status' => $status,
            'verified_status' => $status,
            'payment_id' => $paymentId,
        ]);

        if ($status === 'successful') {
            $allocation = $pdo->prepare('SELECT allocation_id FROM investment_payment_allocations WHERE payment_id = :id LIMIT 1');
            $allocation->execute(['id' => $paymentId]);
            if (!$allocation->fetchColumn()) {
                $insert = $pdo->prepare(
                    "INSERT INTO investment_payment_allocations
                        (payment_id, goal_id, opportunity_id, group_plan_id, amount, reason)
                     VALUES (:payment_id, :goal_id, :opportunity_id, :group_plan_id, :amount, 'Automatically allocated after verified Daraja Sandbox payment.')"
                );
                $insert->execute([
                    'payment_id' => $paymentId,
                    'goal_id' => $payment['goal_id'],
                    'opportunity_id' => $payment['opportunity_id'],
                    'group_plan_id' => $payment['group_plan_id'],
                    'amount' => $payment['amount'],
                ]);
            }
        }

        $audit = $pdo->prepare(
            "INSERT INTO investment_payment_audit
                (payment_id, action, result_code, result_description, reason, admin_id)
             VALUES (:payment_id, 'reconciliation', :result_code, :description, :reason, :admin_id)"
        );
        $audit->execute([
            'payment_id' => $paymentId,
            'result_code' => $queryCode !== '' ? $queryCode : null,
            'description' => $queryDescription,
            'reason' => $message,
            'admin_id' => $adminId,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['status' => $status, 'message' => $message];
}

function reallocate_investment_payment(
    int $paymentId,
    ?int $goalId,
    ?int $opportunityId,
    ?int $groupPlanId,
    string $reason,
    int $adminId
): void {
    $targetCount = (int) (($goalId ?? 0) > 0) + (int) (($opportunityId ?? 0) > 0) + (int) (($groupPlanId ?? 0) > 0);
    if ($paymentId < 1 || $adminId < 1 || $targetCount !== 1) {
        throw new InvalidArgumentException('Choose one valid goal, opportunity or group plan allocation.');
    }
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 255) {
        throw new InvalidArgumentException('Enter an allocation reason of up to 255 characters.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $paymentStmt = $pdo->prepare('SELECT member_id, amount, status FROM investment_payments WHERE payment_id = :id FOR UPDATE');
        $paymentStmt->execute(['id' => $paymentId]);
        $payment = $paymentStmt->fetch();
        if (!$payment || $payment['status'] !== 'successful') {
            throw new InvalidArgumentException('Only Daraja-verified successful payments can be allocated.');
        }
        if (($goalId ?? 0) > 0) {
            $goalStmt = $pdo->prepare(
                "SELECT goal_id FROM investment_goals
                 WHERE goal_id = :goal_id AND member_id = :member_id AND status = 'active'"
            );
            $goalStmt->execute(['goal_id' => $goalId, 'member_id' => $payment['member_id']]);
            if (!$goalStmt->fetchColumn()) {
                throw new InvalidArgumentException('Allocation goal must be an active goal belonging to the paying member.');
            }
            $goalId = (int) $goalId;
            $opportunityId = null;
            $groupPlanId = null;
        } elseif (($opportunityId ?? 0) > 0) {
            $opportunityStmt = $pdo->prepare('SELECT opportunity_id FROM investment_opportunities WHERE opportunity_id = :id');
            $opportunityStmt->execute(['id' => $opportunityId]);
            if (!$opportunityStmt->fetchColumn()) {
                throw new InvalidArgumentException('Choose an existing investment opportunity.');
            }
            $goalId = null;
            $opportunityId = (int) $opportunityId;
            $groupPlanId = null;
        } else {
            $planStmt = $pdo->prepare("SELECT plan_id FROM investment_group_plans WHERE plan_id = :id AND status <> 'completed'");
            $planStmt->execute(['id' => $groupPlanId]);
            if (!$planStmt->fetchColumn()) {
                throw new InvalidArgumentException('Choose a group investment plan that is not completed.');
            }
            $goalId = null;
            $opportunityId = null;
            $groupPlanId = (int) $groupPlanId;
        }

        $oldStmt = $pdo->prepare('SELECT * FROM investment_payment_allocations WHERE payment_id = :id FOR UPDATE');
        $oldStmt->execute(['id' => $paymentId]);
        $old = $oldStmt->fetch();
        if ($old && (int) ($old['goal_id'] ?? 0) === (int) ($goalId ?? 0) &&
            (int) ($old['opportunity_id'] ?? 0) === (int) ($opportunityId ?? 0) &&
            (int) ($old['group_plan_id'] ?? 0) === (int) ($groupPlanId ?? 0)) {
            throw new InvalidArgumentException('The payment is already allocated to that investment.');
        }
        if ($old) {
            $save = $pdo->prepare(
                'UPDATE investment_payment_allocations
                 SET goal_id = :goal_id, opportunity_id = :opportunity_id, group_plan_id = :group_plan_id, amount = :amount,
                     allocated_by_admin = :admin_id, reason = :reason
                 WHERE payment_id = :payment_id'
            );
        } else {
            $save = $pdo->prepare(
                'INSERT INTO investment_payment_allocations
                    (goal_id, opportunity_id, group_plan_id, amount, allocated_by_admin, reason, payment_id)
                 VALUES (:goal_id, :opportunity_id, :group_plan_id, :amount, :admin_id, :reason, :payment_id)'
            );
        }
        $save->execute([
            'goal_id' => $goalId,
            'opportunity_id' => $opportunityId,
            'group_plan_id' => $groupPlanId,
            'amount' => $payment['amount'],
            'admin_id' => $adminId,
            'reason' => $reason,
            'payment_id' => $paymentId,
        ]);
        $audit = $pdo->prepare(
            "INSERT INTO investment_payment_audit
                (payment_id, action, previous_goal_id, previous_opportunity_id, previous_group_plan_id, previous_amount,
                 new_goal_id, new_opportunity_id, new_group_plan_id, new_amount, reason, admin_id)
             VALUES
                (:payment_id, 'allocation_changed', :old_goal, :old_opportunity, :old_group_plan, :old_amount,
                 :new_goal, :new_opportunity, :new_group_plan, :new_amount, :reason, :admin_id)"
        );
        $audit->execute([
            'payment_id' => $paymentId,
            'old_goal' => $old['goal_id'] ?? null,
            'old_opportunity' => $old['opportunity_id'] ?? null,
            'old_group_plan' => $old['group_plan_id'] ?? null,
            'old_amount' => $old['amount'] ?? null,
            'new_goal' => $goalId,
            'new_opportunity' => $opportunityId,
            'new_group_plan' => $groupPlanId,
            'new_amount' => $payment['amount'],
            'reason' => $reason,
            'admin_id' => $adminId,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
