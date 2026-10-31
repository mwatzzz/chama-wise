<?php

declare(strict_types=1);

function sync_contribution_penalty(int $contributionId, ?int $adminId = null): ?array
{
    $stmt = db()->prepare("SELECT contribution_id, status FROM contributions WHERE contribution_id = :id LIMIT 1");
    $stmt->execute(['id' => $contributionId]);
    $contribution = $stmt->fetch();
    if (!$contribution || $contribution['status'] !== 'recorded') {
        return null;
    }

    sync_contribution_penalties();
    return null;
}

function sync_contribution_penalties(): void
{
    $penaltyAmount = min(
        max(0, (float) financial_setting('contribution_penalty_amount')),
        max(0, (float) financial_setting('contribution_penalty_cap'))
    );

    $today = new DateTimeImmutable('today');
    $startPeriod = financial_setting('contribution_penalty_start_period');
    $processedThrough = financial_setting('contribution_penalty_processed_through');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $startPeriod) ||
        !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $processedThrough)) {
        throw new RuntimeException('The contribution penalty start period is invalid.');
    }
    $periodStartDate = new DateTimeImmutable($startPeriod . '-01');
    $nextPeriod = (new DateTimeImmutable($processedThrough . '-01'))->modify('+1 month');
    if ($nextPeriod > $periodStartDate) {
        $periodStartDate = $nextPeriod;
    }
    $currentPeriodStart = $today->modify('first day of this month');
    if ($periodStartDate > $currentPeriodStart) {
        return;
    }

    $members = $penaltyAmount > 0
        ? db()->query("SELECT member_id, phone, joined_date FROM members WHERE status = 'active'")->fetchAll()
        : [];
    $paidStmt = db()->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM contributions
         WHERE member_id = :member_id AND status = 'recorded'
           AND contribution_date >= :period_start AND contribution_date < :period_end"
    );
    $insert = $penaltyAmount > 0 ? db()->prepare(
        'INSERT IGNORE INTO penalties
            (member_id, contribution_period, amount, reason, penalty_date)
         VALUES
            (:member_id, :contribution_period, :amount, :reason, :penalty_date)'
    ) : null;
    $existingStmt = $penaltyAmount > 0 ? db()->prepare(
        'SELECT p.penalty_id
         FROM penalties p
         LEFT JOIN contributions c ON c.contribution_id = p.contribution_id
         WHERE p.member_id = :member_id
           AND (p.contribution_period = :contribution_period
                OR (p.contribution_period IS NULL
                    AND c.contribution_date >= :period_start
                    AND c.contribution_date < :period_end))
         LIMIT 1'
    ) : null;
    $markProcessed = db()->prepare(
        'UPDATE business_settings
         SET setting_value = :period
         WHERE setting_key = \'contribution_penalty_processed_through\''
    );
    while ($periodStartDate <= $currentPeriodStart) {
        $period = $periodStartDate->format('Y-m');
        $dueDate = new DateTimeImmutable(
            $period . '-' . str_pad(financial_setting('contribution_due_day'), 2, '0', STR_PAD_LEFT)
        );
        $penaltyDate = $dueDate->modify('+' . (int) financial_setting('contribution_grace_days') . ' days');
        if ($today <= $penaltyDate) {
            break;
        }

        $periodEnd = $periodStartDate->modify('+1 month')->format('Y-m-d');
        $paidThrough = $penaltyDate->modify('+1 day')->format('Y-m-d');
        foreach ($members as $member) {
            if ((new DateTimeImmutable($member['joined_date'])) > $dueDate) {
                continue;
            }
            $paidStmt->execute([
                'member_id' => $member['member_id'],
                'period_start' => $periodStartDate->format('Y-m-d'),
                'period_end' => min($paidThrough, $periodEnd),
            ]);
            $paid = (float) $paidStmt->fetchColumn();
            $requiredContribution = (float) financial_setting('contribution_amount');
            if ($paid > 0 && ($requiredContribution <= 0 || $paid >= $requiredContribution)) {
                continue;
            }
            $existingStmt->execute([
                'member_id' => $member['member_id'],
                'contribution_period' => $period,
                'period_start' => $periodStartDate->format('Y-m-d'),
                'period_end' => $periodEnd,
            ]);
            if ($existingStmt->fetch()) {
                continue;
            }

            $reason = 'Missed contribution for ' . $period . ' after the day ' .
                financial_setting('contribution_due_day') . ' deadline and ' .
                financial_setting('contribution_grace_days') . '-day grace period.';
            $insert->execute([
                'member_id' => $member['member_id'],
                'contribution_period' => $period,
                'amount' => $penaltyAmount,
                'reason' => $reason,
                'penalty_date' => $penaltyDate->format('Y-m-d'),
            ]);
            if ($insert->rowCount() === 1) {
                $smsResult = send_sms((string) $member['phone'], 'ChamaWise alert: a missed contribution penalty of KES ' . number_format($penaltyAmount, 2) . ' was applied for ' . $period . '.');
                if (!$smsResult['success']) {
                    error_log('Contribution penalty notification failed for member #' . $member['member_id'] . ': ' . $smsResult['message']);
                }
            }
        }

        $markProcessed->execute(['period' => $period]);
        $periodStartDate = $periodStartDate->modify('+1 month');
    }
}

function sync_overdue_loan_penalties(): void
{
    $penaltyPercent = max(0.0, (float) LATE_LOAN_PENALTY_PERCENT);
    if ($penaltyPercent <= 0) {
        return;
    }

    db()->exec(
        "UPDATE loans
         SET status = 'overdue'
         WHERE status = 'approved'
           AND due_date < CURDATE()
           AND COALESCE(outstanding_balance, 0) > 0"
    );

    $stmt = db()->query(
        "SELECT l.loan_id, l.member_id, l.outstanding_balance, l.due_date
         FROM loans l
         LEFT JOIN penalties p ON p.loan_id = l.loan_id
         WHERE l.status = 'overdue'
           AND l.due_date < CURDATE()
           AND COALESCE(l.outstanding_balance, 0) > 0
           AND p.penalty_id IS NULL"
    );
    $insert = db()->prepare(
        "INSERT IGNORE INTO penalties (member_id, loan_id, amount, reason, penalty_date)
         VALUES (:member_id, :loan_id, :amount, :reason, :penalty_date)"
    );

    foreach ($stmt->fetchAll() as $loan) {
        $balance = (float) $loan['outstanding_balance'];
        $amount = round($balance * $penaltyPercent / 100, 2);
        if ($amount <= 0) {
            continue;
        }

        $insert->execute([
            'member_id' => $loan['member_id'],
            'loan_id' => $loan['loan_id'],
            'amount' => $amount,
            'reason' => 'Loan #' . $loan['loan_id'] . ' remained unpaid after its due date (' . $penaltyPercent . '% of outstanding balance).',
            'penalty_date' => $loan['due_date'],
        ]);

        if ($insert->rowCount() === 1) {
            $smsResult = send_sms_to_member((int) $loan['member_id'], 'ChamaWise alert: your loan is overdue and a penalty of KES ' . number_format($amount, 2) . ' has been added.');
            if (!$smsResult['success']) {
                error_log('Overdue loan penalty notification failed for loan #' . $loan['loan_id'] . ': ' . $smsResult['message']);
            }
        }
    }
}
