<?php

declare(strict_types=1);

function sms_centre_templates(): array
{
    return [
        'meeting_announcement' => [
            'label' => 'Chama meeting announcement',
            'category' => 'meeting',
            'message' => 'ChamaWise: {meeting_title} will be held on {meeting_date}.',
        ],
        'meeting_reminder' => [
            'label' => 'Meeting reminder',
            'category' => 'meeting',
            'message' => 'Reminder: {meeting_title} is scheduled for {meeting_date}.',
        ],
        'monthly_contribution' => [
            'label' => 'Monthly contribution reminder',
            'category' => 'contribution',
            'message' => 'Reminder: your monthly contribution of KES {contribution_amount} is due {due_date}.',
        ],
        'missed_contribution' => [
            'label' => 'Missed contribution reminder',
            'category' => 'contribution',
            'message' => 'Our records show a missed contribution for {contribution_period}. Please contact the chama administrator.',
        ],
        'loan_repayment' => [
            'label' => 'Loan repayment reminder',
            'category' => 'loan',
            'message' => 'Reminder: your outstanding loan balance is KES {loan_amount}, due by {due_date}.',
        ],
        'overdue_loan' => [
            'label' => 'Overdue loan reminder',
            'category' => 'loan',
            'message' => 'Your loan balance of KES {loan_amount} is overdue since {due_date}. Please contact the chama administrator.',
        ],
        'general_announcement' => [
            'label' => 'General announcement',
            'category' => 'announcement',
            'message' => '',
        ],
        'custom' => [
            'label' => 'Custom message',
            'category' => 'custom',
            'message' => '',
        ],
    ];
}

function sms_centre_tables_available(): bool
{
    foreach (['sms_campaigns', 'sms_campaign_batches', 'sms_campaign_recipients'] as $table) {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $stmt->execute(['table_name' => $table]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return false;
        }
    }

    return true;
}

function sms_centre_phone_is_valid(string $phone): bool
{
    $normalized = normalize_sms_phone($phone);
    return preg_match('/^\+[1-9]\d{7,14}$/', $normalized) === 1;
}

function sms_centre_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return '***' . substr($digits, -4);
}

function sms_centre_find_recipients(string $audience, array $memberIds = [], ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $sql = "SELECT DISTINCT m.member_id, m.name, m.phone
            FROM members m
            WHERE m.status = 'active'";
    $params = [];

    if ($audience === 'selected' || $audience === 'individual') {
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn (int $id): bool => $id > 0)));
        if ($audience === 'individual' && count($memberIds) !== 1) {
            throw new InvalidArgumentException('Choose exactly one active member.');
        }
        if ($memberIds === []) {
            throw new InvalidArgumentException('Select at least one member.');
        }
        $placeholders = [];
        foreach ($memberIds as $index => $memberId) {
            $key = 'member_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $memberId;
        }
        $sql .= ' AND m.member_id IN (' . implode(', ', $placeholders) . ')';
    } elseif ($audience === 'outstanding_loans') {
        $sql .= " AND EXISTS (
            SELECT 1 FROM loans l
            WHERE l.member_id = m.member_id
              AND l.status IN ('approved', 'overdue')
              AND COALESCE(l.outstanding_balance, 0) > 0
        )";
    } elseif ($audience === 'overdue_loans') {
        $sql .= " AND EXISTS (
            SELECT 1 FROM loans l
            WHERE l.member_id = m.member_id
              AND l.status = 'overdue'
              AND l.due_date < CURDATE()
              AND COALESCE(l.outstanding_balance, 0) > 0
        )";
    } elseif ($audience === 'missed_contributions') {
        $sql .= " AND EXISTS (
            SELECT 1 FROM penalties p
            WHERE p.member_id = m.member_id
              AND p.status = 'outstanding'
              AND p.contribution_period IS NOT NULL
        )";
    } elseif ($audience === 'upcoming_due') {
        $sql .= " AND EXISTS (
            SELECT 1 FROM loans l
            WHERE l.member_id = m.member_id
              AND l.status = 'approved'
              AND l.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              AND COALESCE(l.outstanding_balance, 0) > 0
        )";
    } elseif ($audience !== 'all') {
        throw new InvalidArgumentException('Choose a supported recipient group.');
    }

    $sql .= ' ORDER BY m.name, m.member_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recipients = [];
    $seenPhones = [];
    foreach ($rows as $row) {
        if (!sms_centre_phone_is_valid((string) $row['phone'])) {
            continue;
        }
        $phone = normalize_sms_phone((string) $row['phone']);
        if (isset($seenPhones[$phone])) {
            continue;
        }
        $seenPhones[$phone] = true;
        $recipients[] = [
            'member_id' => (int) $row['member_id'],
            'name' => (string) $row['name'],
            'phone' => $phone,
        ];
    }

    return $recipients;
}

function sms_centre_member_context(array $member, array $request = [], ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $context = ['member_name' => (string) $member['name']];

    $settings = [];
    $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM business_settings WHERE setting_key IN ('contribution_amount', 'contribution_due_day')");
    foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }
    if (isset($settings['contribution_amount'])) {
        $context['contribution_amount'] = number_format((float) $settings['contribution_amount'], 2, '.', '');
    }
    if (isset($settings['contribution_due_day'])) {
        $context['due_date'] = date('Y-m-') . str_pad((string) $settings['contribution_due_day'], 2, '0', STR_PAD_LEFT);
    }

    $meetingId = (int) ($request['meeting_id'] ?? 0);
    if ($meetingId > 0) {
        $meetingStmt = $pdo->prepare("SELECT title, meeting_date FROM meetings WHERE meeting_id = :meeting_id AND status = 'scheduled' LIMIT 1");
        $meetingStmt->execute(['meeting_id' => $meetingId]);
    } else {
        $meetingStmt = $pdo->query("SELECT title, meeting_date FROM meetings WHERE status = 'scheduled' AND meeting_date >= CURDATE() ORDER BY meeting_date, meeting_id LIMIT 1");
    }
    $meeting = $meetingStmt->fetch(PDO::FETCH_ASSOC);
    if ($meeting) {
        $context['meeting_title'] = (string) $meeting['title'];
        $context['meeting_date'] = (string) $meeting['meeting_date'];
    }

    $loanStmt = $pdo->prepare(
        "SELECT outstanding_balance, due_date
         FROM loans
         WHERE member_id = :member_id
           AND status IN ('approved', 'overdue')
           AND COALESCE(outstanding_balance, 0) > 0
         ORDER BY due_date IS NULL, due_date
         LIMIT 2"
    );
    $loanStmt->execute(['member_id' => $member['member_id']]);
    $loans = $loanStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($loans) === 1) {
        $context['loan_amount'] = number_format((float) $loans[0]['outstanding_balance'], 2, '.', '');
        if (!empty($loans[0]['due_date'])) {
            $context['due_date'] = (string) $loans[0]['due_date'];
        }
    }

    $penaltyStmt = $pdo->prepare(
        "SELECT contribution_period
         FROM penalties
         WHERE member_id = :member_id
           AND status = 'outstanding'
           AND contribution_period IS NOT NULL
         ORDER BY contribution_period DESC
         LIMIT 2"
    );
    $penaltyStmt->execute(['member_id' => $member['member_id']]);
    $periods = $penaltyStmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($periods) === 1) {
        $context['contribution_period'] = (string) $periods[0];
    }

    return $context;
}

function sms_centre_render_message(string $message, array $context): string
{
    $allowed = [
        'member_name',
        'meeting_title',
        'meeting_date',
        'contribution_amount',
        'contribution_period',
        'loan_amount',
        'due_date',
    ];
    $rendered = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $match) use ($allowed, $context): string {
        $key = $match[1];
        if (!in_array($key, $allowed, true) || !array_key_exists($key, $context) || trim((string) $context[$key]) === '') {
            throw new InvalidArgumentException('A message placeholder cannot be resolved for every recipient.');
        }

        return (string) $context[$key];
    }, $message);
    if (!is_string($rendered) || trim($rendered) === '' || preg_match('/\{[^{}]+\}/', $rendered)) {
        throw new InvalidArgumentException('Enter a non-empty message without unresolved placeholders.');
    }
    if (strlen($rendered) > 1600) {
        throw new InvalidArgumentException('SMS messages must not exceed 1,600 bytes.');
    }

    return trim($rendered);
}

function sms_centre_prepare_campaign_recipients(array $request, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $audience = (string) ($request['audience'] ?? '');
    $members = sms_centre_find_recipients($audience, (array) ($request['member_ids'] ?? []), $pdo);
    if (strtolower((string) env('SMS_PROVIDER', 'log')) === 'mobilesasa'
        && filter_var(env('MOBILESASA_TRIAL_MODE', 'true'), FILTER_VALIDATE_BOOLEAN)) {
        $trialPhone = normalize_sms_phone((string) env('MOBILESASA_TRIAL_PHONE', ''));
        if ($trialPhone === '') {
            throw new InvalidArgumentException('MobileSasa trial mode is enabled. Configure the registered trial phone or confirm full sending access before creating campaigns.');
        }
        $members = array_values(array_filter(
            $members,
            static fn (array $member): bool => hash_equals($trialPhone, (string) $member['phone'])
        ));
    }
    if ($members === []) {
        throw new InvalidArgumentException('No active members with valid phone numbers match this selection.');
    }

    $templates = sms_centre_templates();
    $templateKey = (string) ($request['template'] ?? '');
    if (!isset($templates[$templateKey])) {
        throw new InvalidArgumentException('Choose a supported message template.');
    }
    $message = trim((string) ($request['message'] ?? $templates[$templateKey]['message']));
    if ($templateKey === 'custom' || $templateKey === 'general_announcement') {
        if ($message === '') {
            throw new InvalidArgumentException('Enter a message before continuing.');
        }
    }

    $recipients = [];
    foreach ($members as $member) {
        $context = sms_centre_member_context($member, $request, $pdo);
        $rendered = sms_centre_render_message($message, $context);
        $recipients[] = $member + ['message' => $rendered];
    }

    return [
        'template_key' => $templateKey,
        'category' => (string) $templates[$templateKey]['category'],
        'audience' => $audience,
        'message' => $message,
        'recipients' => $recipients,
    ];
}

/**
 * Lock recipient members in ascending ID order so campaign creation and erasure
 * serialize on the same member rows before recipient history is written.
 *
 * @param list<array<string, mixed>> $recipients
 */
function sms_centre_lock_active_recipients(PDO $pdo, array $recipients): void
{
    $memberIds = array_values(array_unique(array_map(
        static fn (array $recipient): int => (int) ($recipient['member_id'] ?? 0),
        $recipients
    )));
    sort($memberIds, SORT_NUMERIC);
    $lock = $pdo->prepare(
        'SELECT status FROM members WHERE member_id = :member_id FOR UPDATE'
    );

    foreach ($memberIds as $memberId) {
        if ($memberId < 1) {
            throw new InvalidArgumentException('A campaign recipient is no longer available.');
        }
        $lock->execute(['member_id' => $memberId]);
        if ($lock->fetchColumn() !== 'active') {
            throw new InvalidArgumentException(
                'One or more selected members are no longer active. Refresh the recipient selection before creating a campaign.'
            );
        }
    }
}

function sms_centre_create_campaign(array $request, int $adminId, ?PDO $pdo = null): int
{
    $pdo = $pdo ?? db();
    if (!sms_centre_tables_available()) {
        throw new RuntimeException('Apply the SMS Centre migration before creating campaigns.');
    }
    if (empty($request['confirmed'])) {
        throw new InvalidArgumentException('Explicit confirmation is required before sending.');
    }
    $submissionKey = (string) ($request['submission_key'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $submissionKey) !== 1) {
        throw new InvalidArgumentException('The campaign submission token is invalid.');
    }

    $prepared = sms_centre_prepare_campaign_recipients($request, $pdo);
    $provider = strtolower((string) env('SMS_PROVIDER', 'log'));
    $batchSize = 100;
    $pdo->beginTransaction();
    try {
        sms_centre_lock_active_recipients($pdo, $prepared['recipients']);
        $campaignStmt = $pdo->prepare(
            "INSERT INTO sms_campaigns
                (created_by, category, template_key, audience_type, provider, submission_key, message_body, status)
             VALUES
                (:created_by, :category, :template_key, :audience_type, :provider, :submission_key, :message_body, 'pending')"
        );
        $campaignStmt->execute([
            'created_by' => $adminId,
            'category' => $prepared['category'],
            'template_key' => $prepared['template_key'],
            'audience_type' => $prepared['audience'],
            'provider' => $provider,
            'submission_key' => $submissionKey,
            'message_body' => $prepared['message'],
        ]);
        $campaignId = (int) $pdo->lastInsertId();
        $insertBatch = $pdo->prepare(
            "INSERT INTO sms_campaign_batches
                (campaign_id, batch_number, provider, tracking_id, status)
             VALUES (:campaign_id, :batch_number, :provider, :tracking_id, 'pending')"
        );
        $insertRecipient = $pdo->prepare(
            "INSERT INTO sms_campaign_recipients
                (campaign_id, batch_id, member_id, recipient_name, normalized_phone, rendered_message, status)
             VALUES
                (:campaign_id, :batch_id, :member_id, :recipient_name, :normalized_phone, :rendered_message, 'pending')"
        );
        foreach (array_chunk($prepared['recipients'], $batchSize) as $index => $batchRecipients) {
            $trackingId = $provider === 'mobilesasa' ? bin2hex(random_bytes(16)) : null;
            $insertBatch->execute([
                'campaign_id' => $campaignId,
                'batch_number' => $index + 1,
                'provider' => $provider,
                'tracking_id' => $trackingId,
            ]);
            $batchId = (int) $pdo->lastInsertId();
            foreach ($batchRecipients as $recipient) {
                $insertRecipient->execute([
                    'campaign_id' => $campaignId,
                    'batch_id' => $batchId,
                    'member_id' => $recipient['member_id'],
                    'recipient_name' => $recipient['name'],
                    'normalized_phone' => $recipient['phone'],
                    'rendered_message' => $recipient['message'],
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
            throw new DomainException('This campaign submission was already received.');
        }
        throw $exception;
    }

    return $campaignId;
}

function sms_centre_update_campaign_status(int $campaignId, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $stmt = $pdo->prepare(
        'SELECT
            SUM(status = \'pending\') AS pending_count,
            SUM(status = \'accepted\') AS accepted_count,
            SUM(status = \'delivered\') AS delivered_count,
            SUM(status = \'failed\') AS failed_count,
            SUM(status = \'uncertain\') AS uncertain_count
         FROM sms_campaign_recipients
         WHERE campaign_id = :campaign_id'
    );
    $stmt->execute(['campaign_id' => $campaignId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $pending = (int) ($counts['pending_count'] ?? 0);
    $accepted = (int) ($counts['accepted_count'] ?? 0);
    $delivered = (int) ($counts['delivered_count'] ?? 0);
    $failed = (int) ($counts['failed_count'] ?? 0);
    $uncertain = (int) ($counts['uncertain_count'] ?? 0);
    $status = 'processing';
    if ($pending === 0) {
        if ($uncertain > 0) {
            $status = 'uncertain';
        } elseif ($failed > 0 && ($accepted + $delivered) > 0) {
            $status = 'partial';
        } elseif ($failed > 0) {
            $status = 'failed';
        } elseif ($accepted > 0) {
            $status = 'accepted';
        } else {
            $status = 'completed';
        }
    } elseif ($accepted === 0 && $delivered === 0 && $failed === 0 && $uncertain === 0) {
        $status = 'pending';
    }
    $update = $pdo->prepare('UPDATE sms_campaigns SET status = :status WHERE campaign_id = :campaign_id');
    $update->execute(['status' => $status, 'campaign_id' => $campaignId]);
}

function sms_centre_process_batch(int $batchId, int $adminId, ?callable $httpRequest = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT b.batch_id, b.campaign_id, b.provider, b.tracking_id, b.status, c.created_by
             FROM sms_campaign_batches b
             INNER JOIN sms_campaigns c ON c.campaign_id = b.campaign_id
             WHERE b.batch_id = :batch_id
             FOR UPDATE'
        );
        $stmt->execute(['batch_id' => $batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch || (int) $batch['created_by'] !== $adminId) {
            throw new RuntimeException('SMS batch was not found.');
        }
        if ($batch['status'] !== 'pending') {
            throw new DomainException($batch['status'] === 'sending' ? 'Batch result is uncertain; automatic retry is disabled.' : 'This SMS batch has already been processed.');
        }
        $recipientsStmt = $pdo->prepare(
            'SELECT recipient_id, normalized_phone, rendered_message
             FROM sms_campaign_recipients
             WHERE batch_id = :batch_id AND status = \'pending\'
             ORDER BY recipient_id'
        );
        $recipientsStmt->execute(['batch_id' => $batchId]);
        $recipients = $recipientsStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($recipients === []) {
            throw new DomainException('This SMS batch has no pending recipients.');
        }
        $pdo->prepare("UPDATE sms_campaign_batches SET status = 'sending', started_at = CURRENT_TIMESTAMP WHERE batch_id = :batch_id")
            ->execute(['batch_id' => $batchId]);
        $pdo->prepare("UPDATE sms_campaigns SET status = 'processing' WHERE campaign_id = :campaign_id")
            ->execute(['campaign_id' => $batch['campaign_id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $sendRecipients = array_map(static fn (array $recipient): array => [
        'phone' => $recipient['normalized_phone'],
        'message' => $recipient['rendered_message'],
    ], $recipients);
    try {
        $result = send_sms_batch(
            $sendRecipients,
            null,
            (string) $batch['provider'],
            $httpRequest,
            $batch['tracking_id'] !== null ? (string) $batch['tracking_id'] : null
        );
    } catch (Throwable $exception) {
        $result = [
            'success' => false,
            'status' => 'uncertain',
            'provider' => (string) $batch['provider'],
            'message' => 'Provider request outcome is uncertain; do not retry automatically.',
            'results' => [],
        ];
    }

    $pdo->beginTransaction();
    try {
        $batchStatus = in_array($result['status'] ?? '', ['accepted', 'failed', 'uncertain'], true)
            ? $result['status']
            : 'uncertain';
        $batchUpdate = $pdo->prepare(
            'UPDATE sms_campaign_batches
             SET status = :status, bulk_id = :bulk_id, response_code = :response_code,
                 safe_response_message = :safe_response_message, completed_at = CURRENT_TIMESTAMP
             WHERE batch_id = :batch_id'
        );
        $batchUpdate->execute([
            'status' => $batchStatus,
            'bulk_id' => $result['bulk_id'] ?? null,
            'response_code' => $result['response_code'] ?? null,
            'safe_response_message' => substr((string) ($result['message'] ?? 'SMS request failed.'), 0, 255),
            'batch_id' => $batchId,
        ]);
        $recipientUpdate = $pdo->prepare(
            'UPDATE sms_campaign_recipients
             SET status = :status, provider_reference = :provider_reference,
                 response_code = :response_code, safe_response_message = :safe_response_message,
                 failure_reason = :failure_reason,
                 sent_at = CASE WHEN :sent_status = \'accepted\' THEN CURRENT_TIMESTAMP ELSE sent_at END
             WHERE recipient_id = :recipient_id AND batch_id = :batch_id'
        );
        foreach ($recipients as $index => $recipient) {
            $individual = $result['results'][$index] ?? [];
            $recipientStatus = in_array($individual['status'] ?? '', ['accepted', 'failed', 'uncertain'], true)
                ? $individual['status']
                : $batchStatus;
            $safeMessage = substr((string) ($individual['message'] ?? $result['message'] ?? 'SMS request failed.'), 0, 255);
            $recipientUpdate->execute([
                'status' => $recipientStatus,
                'provider_reference' => $result['bulk_id'] ?? null,
                'response_code' => $result['response_code'] ?? null,
                'safe_response_message' => $safeMessage,
                'failure_reason' => $recipientStatus === 'failed' ? $safeMessage : null,
                'sent_status' => $recipientStatus,
                'recipient_id' => $recipient['recipient_id'],
                'batch_id' => $batchId,
            ]);
        }
        sms_centre_update_campaign_status((int) $batch['campaign_id'], $pdo);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $result;
}

function sms_centre_apply_delivery_report(array $report, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $reportDetails = is_array($report['dlr'] ?? null) ? $report['dlr'] : [];
    $reference = trim((string) ($report['reference'] ?? $report['messageId'] ?? $report['bulkId'] ?? ''));
    $trackingId = trim((string) ($report['trackingId'] ?? $report['dlr']['tracking_id'] ?? ''));
    $phone = trim((string) ($report['msisdn'] ?? $report['phone'] ?? ''));
    $statusText = strtolower(trim((string) ($report['status'] ?? $report['deliveryStatus'] ?? $reportDetails['status'] ?? $reportDetails['description'] ?? '')));
    $statusMap = [
        'delivered' => 'delivered',
        'failed' => 'failed',
        'rejected' => 'failed',
        'expired' => 'failed',
        'unreachable' => 'failed',
        'sent' => 'accepted',
    ];
    if ($reference === '' || $phone === '' || !isset($statusMap[$statusText])) {
        return ['matched' => false, 'updated' => false, 'reason' => 'incomplete_report'];
    }

    $batchSql = $trackingId !== ''
        ? 'SELECT batch_id, campaign_id FROM sms_campaign_batches
           WHERE provider = \'mobilesasa\'
             AND (tracking_id = :tracking_id OR tracking_id = :reference_tracking OR bulk_id = :bulk_id)
           LIMIT 2'
        : 'SELECT batch_id, campaign_id FROM sms_campaign_batches
           WHERE provider = \'mobilesasa\'
             AND (bulk_id = :bulk_id OR tracking_id = :reference_tracking)
           LIMIT 2';
    $batchStmt = $pdo->prepare($batchSql);
    $batchStmt->execute($trackingId !== ''
        ? ['tracking_id' => $trackingId, 'reference_tracking' => $reference, 'bulk_id' => $reference]
        : ['bulk_id' => $reference, 'reference_tracking' => $reference]);
    $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($batches) !== 1) {
        return ['matched' => false, 'updated' => false, 'reason' => 'ambiguous_batch'];
    }
    $batch = $batches[0];

    if (preg_match('/^[a-f0-9]{64}$/i', $phone)) {
        return ['matched' => false, 'updated' => false, 'reason' => 'hashed_phone_manual_reconciliation'];
    }
    $normalizedPhone = normalize_sms_phone($phone);
    if (!sms_centre_phone_is_valid($normalizedPhone)) {
        return ['matched' => false, 'updated' => false, 'reason' => 'invalid_phone'];
    }

    $recipientStmt = $pdo->prepare(
        'SELECT recipient_id, status, last_dlr_fingerprint
         FROM sms_campaign_recipients
         WHERE batch_id = :batch_id AND normalized_phone = :phone
         LIMIT 2'
    );
    $recipientStmt->execute(['batch_id' => $batch['batch_id'], 'phone' => $normalizedPhone]);
    $recipientRows = $recipientStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($recipientRows) !== 1) {
        return ['matched' => false, 'updated' => false, 'reason' => 'recipient_not_found'];
    }
    $recipient = $recipientRows[0];
    $fingerprint = hash('sha256', implode('|', [
        $reference,
        $trackingId,
        $normalizedPhone,
        $statusText,
        (string) ($report['deliveryTime'] ?? $reportDetails['delivered_at'] ?? ''),
    ]));
    if (hash_equals((string) ($recipient['last_dlr_fingerprint'] ?? ''), $fingerprint)
        || in_array($recipient['status'], ['delivered', 'failed'], true)) {
        return ['matched' => true, 'updated' => false, 'reason' => 'duplicate_or_final'];
    }

    $newStatus = $statusMap[$statusText];
    $update = $pdo->prepare(
        'UPDATE sms_campaign_recipients
         SET status = :status, last_dlr_fingerprint = :fingerprint, last_dlr_status = :report_status,
             delivered_at = CASE WHEN :delivered_status = \'delivered\' THEN CURRENT_TIMESTAMP ELSE delivered_at END,
             failure_reason = CASE WHEN :failed_status = \'failed\' THEN :failure_reason ELSE failure_reason END
         WHERE recipient_id = :recipient_id'
    );
    $update->execute([
        'status' => $newStatus,
        'fingerprint' => $fingerprint,
        'report_status' => $statusText,
        'delivered_status' => $newStatus,
        'failed_status' => $newStatus,
        'failure_reason' => $newStatus === 'failed' ? 'MobileSasa delivery report: ' . substr((string) ($report['deliveryStatus'] ?? $reportDetails['description'] ?? $statusText), 0, 180) : null,
        'recipient_id' => $recipient['recipient_id'],
    ]);
    sms_centre_update_campaign_status((int) $batch['campaign_id'], $pdo);

    return ['matched' => true, 'updated' => true, 'reason' => 'updated'];
}

function sms_centre_campaign_progress(int $campaignId, int $adminId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $campaign = $pdo->prepare('SELECT campaign_id, status FROM sms_campaigns WHERE campaign_id = :campaign_id AND created_by = :admin_id LIMIT 1');
    $campaign->execute(['campaign_id' => $campaignId, 'admin_id' => $adminId]);
    $row = $campaign->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('SMS campaign was not found.');
    }

    $countsStmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS total
         FROM sms_campaign_recipients
         WHERE campaign_id = :campaign_id
         GROUP BY status'
    );
    $countsStmt->execute(['campaign_id' => $campaignId]);
    $counts = ['pending' => 0, 'accepted' => 0, 'delivered' => 0, 'failed' => 0, 'uncertain' => 0];
    foreach ($countsStmt->fetchAll(PDO::FETCH_ASSOC) as $count) {
        $counts[(string) $count['status']] = (int) $count['total'];
    }
    $batchesStmt = $pdo->prepare(
        'SELECT batch_id, batch_number, status
         FROM sms_campaign_batches
         WHERE campaign_id = :campaign_id
         ORDER BY batch_number'
    );
    $batchesStmt->execute(['campaign_id' => $campaignId]);

    return [
        'campaign_id' => $campaignId,
        'status' => (string) $row['status'],
        'counts' => $counts,
        'batches' => $batchesStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function sms_centre_reconcile_batch(
    int $batchId,
    int $adminId,
    bool $confirmedLookup,
    ?callable $httpRequest = null,
    ?PDO $pdo = null
): array {
    if (!$confirmedLookup) {
        throw new InvalidArgumentException('Confirm MobileSasa delivery lookup before continuing.');
    }
    $pdo = $pdo ?? db();
    $stmt = $pdo->prepare(
        'SELECT b.*, c.created_by
         FROM sms_campaign_batches b
         INNER JOIN sms_campaigns c ON c.campaign_id = b.campaign_id
         WHERE b.batch_id = :batch_id AND b.provider = \'mobilesasa\'
         LIMIT 1'
    );
    $stmt->execute(['batch_id' => $batchId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch || (int) $batch['created_by'] !== $adminId) {
        throw new RuntimeException('MobileSasa batch was not found.');
    }
    if (empty($batch['tracking_id'])) {
        throw new RuntimeException('This batch has no tracking ID for delivery lookup.');
    }

    $start = (new DateTimeImmutable((string) ($batch['started_at'] ?: $batch['created_at'])))->modify('-1 day')->format('Y-m-d');
    $end = (new DateTimeImmutable((string) ($batch['completed_at'] ?: 'now')))->modify('+1 day')->format('Y-m-d');
    $token = trim((string) env('MOBILESASA_API_TOKEN', ''));
    if ($token === '') {
        throw new RuntimeException('MobileSasa API token is not configured.');
    }
    $url = 'https://api.mobilesasa.com/v2/dlr';
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'];
    $reports = [];
    $page = 1;
    for (; $page <= 100; $page++) {
        $body = json_encode([
            'start' => $start,
            'end' => $end,
            'messageId' => (string) $batch['tracking_id'],
            'page' => $page,
        ], JSON_THROW_ON_ERROR);
        if ($httpRequest !== null) {
            $result = $httpRequest($url, $headers, $body);
            $response = $result['response'] ?? false;
            $httpCode = (int) ($result['http_code'] ?? 0);
            $networkError = !empty($result['network_error']);
        } else {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('cURL is not available for MobileSasa delivery lookup.');
            }
            $curl = curl_init($url);
            if ($curl === false) {
                throw new RuntimeException('MobileSasa delivery lookup could not be initialized.');
            }
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 20,
            ]);
            $response = curl_exec($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $networkError = $response === false || curl_errno($curl) !== 0;
            curl_close($curl);
        }
        if ($networkError) {
            throw new RuntimeException('MobileSasa delivery lookup failed; recipient statuses were not changed.');
        }
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
            throw new RuntimeException('MobileSasa delivery lookup returned an invalid response.');
        }

        $pageReports = $decoded['data'] ?? $decoded['messages'] ?? $decoded['results'] ?? [];
        if (is_array($pageReports) && isset($pageReports['data']) && is_array($pageReports['data'])) {
            $pageReports = $pageReports['data'];
        }
        if (!is_array($pageReports)) {
            throw new RuntimeException('MobileSasa delivery lookup returned an unexpected report list.');
        }
        if ($pageReports === []) {
            break;
        }
        foreach ($pageReports as $pageReport) {
            if (is_array($pageReport)) {
                $reports[] = $pageReport;
            }
        }
    }
    if ($page > 100) {
        throw new RuntimeException('MobileSasa delivery lookup exceeded the safe pagination limit.');
    }
    $updated = 0;
    $unmatched = 0;
    foreach ($reports as $report) {
        if (!is_array($report)) {
            continue;
        }
        $reportTrackingId = (string) ($report['trackingId'] ?? $report['dlr']['tracking_id'] ?? '');
        if ($reportTrackingId !== '' && !hash_equals((string) $batch['tracking_id'], $reportTrackingId)) {
            continue;
        }
        $reportReference = (string) ($report['reference'] ?? $report['messageId'] ?? $report['bulkId'] ?? '');
        if ($reportReference === '') {
            $report['reference'] = (string) ($batch['bulk_id'] ?: $batch['tracking_id']);
        }
        $report['trackingId'] = (string) ($batch['tracking_id']);
        $phone = (string) ($report['msisdn'] ?? $report['phone'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/i', $phone)) {
            $resolved = sms_centre_mobile_lookup_hash($phone, $httpRequest);
            if ($resolved === null) {
                $unmatched++;
                continue;
            }
            $report['msisdn'] = $resolved;
        }
        $result = sms_centre_apply_delivery_report($report, $pdo);
        if (!empty($result['updated'])) {
            $updated++;
        } elseif (empty($result['matched'])) {
            $unmatched++;
        }
    }

    return ['updated' => $updated, 'unmatched' => $unmatched];
}

function sms_centre_mobile_lookup_hash(string $hash, ?callable $httpRequest = null): ?string
{
    if (preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1) {
        throw new InvalidArgumentException('Phone lookup requires a valid hashed MSISDN.');
    }
    $token = trim((string) env('MOBILESASA_API_TOKEN', ''));
    if ($token === '') {
        throw new RuntimeException('MobileSasa API token is not configured.');
    }
    $url = 'https://api.mobilesasa.com/api/v1/tools/phone?value=' . rawurlencode($hash);
    if ($httpRequest !== null) {
        $result = $httpRequest($url, ['Authorization: Bearer ' . $token, 'Accept: application/json'], null);
        $response = $result['response'] ?? false;
        $code = (int) ($result['http_code'] ?? 0);
        if (!empty($result['network_error'])) {
            return null;
        }
    } else {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is not available for MobileSasa phone lookup.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $networkError = $response === false || curl_errno($curl) !== 0;
        curl_close($curl);
        if ($networkError) {
            return null;
        }
    }

    $decoded = is_string($response) ? json_decode($response, true) : null;
    if ($code !== 200 || !is_array($decoded) || ($decoded['resolved'] ?? false) !== true || empty($decoded['msisdn'])) {
        return null;
    }
    $phone = normalize_sms_phone((string) $decoded['msisdn']);
    return sms_centre_phone_is_valid($phone) ? $phone : null;
}
