<?php

declare(strict_types=1);

/** @return array{eligible: bool, reasons: array<int, string>, warnings: array<int, string>, contribution_count: int, consecutive_contribution_months: int, eligible_savings: float, membership_months: int, maximum_amount: float} */
function evaluate_loan_application(int $memberId, ?float $requestedAmount): array
{
    $memberStmt = db()->prepare('SELECT joined_date, status FROM members WHERE member_id = :member_id LIMIT 1');
    $memberStmt->execute(['member_id' => $memberId]);
    $member = $memberStmt->fetch();
    if (!$member) {
        throw new InvalidArgumentException('Member was not found.');
    }

    $contributionStmt = db()->prepare(
        "SELECT COUNT(*) AS contribution_count, COALESCE(SUM(amount), 0) AS eligible_savings
         FROM contributions
         WHERE member_id = :member_id AND status = 'recorded'"
    );
    $contributionStmt->execute(['member_id' => $memberId]);
    $contributions = $contributionStmt->fetch();

    $monthsStmt = db()->prepare(
        "SELECT DISTINCT DATE_FORMAT(contribution_date, '%Y-%m') AS contribution_month
         FROM contributions
         WHERE member_id = :member_id AND status = 'recorded'
         ORDER BY contribution_month DESC"
    );
    $monthsStmt->execute(['member_id' => $memberId]);
    $months = array_column($monthsStmt->fetchAll(), 'contribution_month');
    $consecutiveMonths = 0;
    $expectedMonth = null;
    foreach ($months as $month) {
        if ($expectedMonth !== null && $month !== $expectedMonth) {
            break;
        }
        $consecutiveMonths++;
        $expectedMonth = (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
    }

    $penaltyStmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM penalties WHERE member_id = :member_id AND status = 'outstanding'");
    $penaltyStmt->execute(['member_id' => $memberId]);
    $outstandingPenalties = (float) $penaltyStmt->fetchColumn();

    $activeLoanStmt = db()->prepare("SELECT COUNT(*) FROM loans WHERE member_id = :member_id AND status NOT IN ('rejected', 'cancelled') AND COALESCE(outstanding_balance, 0) > 0");
    $activeLoanStmt->execute(['member_id' => $memberId]);
    $hasActiveLoan = (int) $activeLoanStmt->fetchColumn() > 0;

    $pendingStmt = db()->prepare(
        "SELECT COUNT(*) FROM loans
         WHERE member_id = :member_id AND status IN ('pending', 'eligible', 'pending_second_approval')"
    );
    $pendingStmt->execute(['member_id' => $memberId]);
    $hasPendingApplication = (int) $pendingStmt->fetchColumn() > 0;

    $membershipDate = new DateTimeImmutable((string) $member['joined_date']);
    $membershipDifference = $membershipDate->diff(new DateTimeImmutable('today'));
    $membershipMonths = $membershipDifference->invert === 1
        ? 0
        : ($membershipDifference->y * 12) + $membershipDifference->m;
    $contributionCount = (int) ($contributions['contribution_count'] ?? 0);
    $eligibleSavings = round((float) ($contributions['eligible_savings'] ?? 0), 2);
    $maximumAmount = min(
        $eligibleSavings * (float) financial_setting('loan_to_savings_multiplier'),
        (float) financial_setting('loan_max_amount')
    );
    $reasons = [];
    $warnings = [];

    if (strtolower((string) $member['status']) !== 'active') {
        $reasons[] = 'Your account is inactive or suspended.';
    }
    if ($hasActiveLoan) {
        $reasons[] = 'You have an outstanding previous loan.';
    }
    if ($hasPendingApplication) {
        $reasons[] = 'You already have a loan application awaiting a decision.';
    }
    if ($membershipMonths < (int) financial_setting('loan_min_membership_months')) {
        $reasons[] = 'You have not yet met the minimum membership period of ' .
            financial_setting('loan_min_membership_months') . ' months.';
    }
    if ($contributionCount < (int) financial_setting('loan_min_contributions')) {
        $reasons[] = 'You need at least ' . financial_setting('loan_min_contributions') . ' recorded contributions.';
    }
    if ($consecutiveMonths < (int) financial_setting('loan_min_contribution_months')) {
        $reasons[] = 'You need contributions in at least ' .
            financial_setting('loan_min_contribution_months') . ' consecutive months.';
    }
    if ($requestedAmount !== null && $requestedAmount > $maximumAmount) {
        $reasons[] = 'The requested amount exceeds your eligible limit of KES ' . number_format($maximumAmount, 2) . '.';
    }
    if ($outstandingPenalties > 0) {
        if (financial_setting('loan_penalties_block_borrowing') === '1') {
            $reasons[] = 'You have outstanding penalties that must be cleared before applying.';
        } else {
            $warnings[] = 'You have outstanding penalties totaling KES ' . number_format($outstandingPenalties, 2) . '.';
        }
    }
    if ($requestedAmount !== null && $requestedAmount <= 0) {
        $reasons[] = 'The requested loan amount must be greater than zero.';
    }

    return [
        'eligible' => $reasons === [],
        'reasons' => $reasons,
        'warnings' => $warnings,
        'contribution_count' => $contributionCount,
        'consecutive_contribution_months' => $consecutiveMonths,
        'eligible_savings' => $eligibleSavings,
        'membership_months' => $membershipMonths,
        'maximum_amount' => round($maximumAmount, 2),
    ];
}

/** @return array{loan_id: int, eligible: bool, reasons: array<int, string>, warnings: array<int, string>, outcome: array<string, mixed>, duplicate: bool} */
function submit_loan_application(
    int $memberId,
    float $requestedAmount,
    string $purpose,
    ?string $notes,
    string $submissionKey
): array {
    if ($memberId < 1 || !preg_match('/^[a-f0-9]{64}$/', $submissionKey)) {
        throw new InvalidArgumentException('Invalid loan application submission.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $memberLock = $pdo->prepare('SELECT member_id FROM members WHERE member_id = :member_id FOR UPDATE');
        $memberLock->execute(['member_id' => $memberId]);
        if (!$memberLock->fetch()) {
            throw new InvalidArgumentException('Member was not found.');
        }

        $duplicateStmt = $pdo->prepare('SELECT loan_id, member_id, status, rejection_reason, eligibility_outcome FROM loans WHERE submission_key = :submission_key LIMIT 1');
        $duplicateStmt->execute(['submission_key' => $submissionKey]);
        $duplicate = $duplicateStmt->fetch();
        if ($duplicate) {
            if ((int) $duplicate['member_id'] !== $memberId) {
                throw new InvalidArgumentException('This loan application submission was already used.');
            }
            $outcome = json_decode((string) ($duplicate['eligibility_outcome'] ?? ''), true);
            $pdo->commit();
            return [
                'loan_id' => (int) $duplicate['loan_id'],
                'eligible' => $duplicate['status'] !== 'rejected',
                'reasons' => $duplicate['rejection_reason'] ? [$duplicate['rejection_reason']] : [],
                'warnings' => is_array($outcome) ? ($outcome['warnings'] ?? []) : [],
                'outcome' => is_array($outcome) ? $outcome : [],
                'duplicate' => true,
            ];
        }

        $eligibility = evaluate_loan_application($memberId, $requestedAmount);
        $reason = $eligibility['eligible'] ? null : implode(' ', $eligibility['reasons']);
        $outcome = $eligibility + [
            'requested_amount' => round($requestedAmount, 2),
            'checked_at' => date(DATE_ATOM),
            'settings' => [
                'minimum_membership_months' => (int) financial_setting('loan_min_membership_months'),
                'minimum_contribution_months' => (int) financial_setting('loan_min_contribution_months'),
                'minimum_contributions' => (int) financial_setting('loan_min_contributions'),
                'loan_to_savings_multiplier' => (float) financial_setting('loan_to_savings_multiplier'),
                'maximum_loan_amount' => (float) financial_setting('loan_max_amount'),
            ],
        ];
        $status = $eligibility['eligible'] ? 'eligible' : 'rejected';
        $insert = $pdo->prepare(
            'INSERT INTO loans
                (member_id, requested_amount, purpose, notes, status, rejection_reason,
                 eligibility_decided_at, eligibility_outcome, submission_key, application_date)
             VALUES
                (:member_id, :requested_amount, :purpose, :notes, :status, :rejection_reason,
                 :eligibility_decided_at, :eligibility_outcome, :submission_key, :application_date)'
        );
        $insert->execute([
            'member_id' => $memberId,
            'requested_amount' => round($requestedAmount, 2),
            'purpose' => $purpose,
            'notes' => $notes,
            'status' => $status,
            'rejection_reason' => $reason,
            'eligibility_decided_at' => date('Y-m-d H:i:s'),
            'eligibility_outcome' => json_encode($outcome, JSON_THROW_ON_ERROR),
            'submission_key' => $submissionKey,
            'application_date' => date('Y-m-d'),
        ]);
        $loanId = (int) $pdo->lastInsertId();
        $pdo->commit();

        if (!$eligibility['eligible']) {
            $message = 'Your ChamaWise loan application #' . $loanId . ' was rejected: ' . $reason;
            $smsResult = send_sms_to_member($memberId, $message);
            if (!$smsResult['success']) {
                error_log('Automatic loan rejection notification failed for loan #' . $loanId . ': ' . $smsResult['message']);
            }
        }

        return [
            'loan_id' => $loanId,
            'eligible' => $eligibility['eligible'],
            'reasons' => $eligibility['reasons'],
            'warnings' => $eligibility['warnings'],
            'outcome' => $outcome,
            'duplicate' => false,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function correct_loan_repayment(
    int $repaymentId,
    float $correctedAmount,
    string $correctedDate,
    string $reason,
    int $adminId
): void {
    $correctedAmount = round($correctedAmount, 2);
    $reason = trim($reason);
    $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $correctedDate);
    if ($repaymentId < 1 || $correctedAmount <= 0 || $reason === '' || mb_strlen($reason) > 255 ||
        !$dateValue || $dateValue->format('Y-m-d') !== $correctedDate || $dateValue > new DateTimeImmutable('today')) {
        throw new InvalidArgumentException('Enter a valid corrected amount, date and reason.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lookup = $pdo->prepare('SELECT loan_id FROM loan_repayments WHERE repayment_id = :id LIMIT 1');
        $lookup->execute(['id' => $repaymentId]);
        $loanId = $lookup->fetchColumn();
        if (!$loanId) {
            throw new InvalidArgumentException('Repayment was not found.');
        }

        $loanStmt = $pdo->prepare(
            'SELECT loan_id, approved_amount, interest_rate, interest_method, approval_date,
                    interest_accrued_until, due_date, status
             FROM loans WHERE loan_id = :loan_id FOR UPDATE'
        );
        $loanStmt->execute(['loan_id' => $loanId]);
        $loan = $loanStmt->fetch();
        if (!$loan || !in_array($loan['status'], ['approved', 'overdue', 'completed'], true)) {
            throw new InvalidArgumentException('Only a repayment against an approved loan can be corrected.');
        }
        $approvalDate = new DateTimeImmutable($loan['approval_date']);
        if ($dateValue < $approvalDate) {
            throw new InvalidArgumentException('Corrected payment date cannot be before loan approval.');
        }

        $repaymentStmt = $pdo->prepare(
            'SELECT repayment_id, amount, payment_date
             FROM loan_repayments WHERE loan_id = :loan_id
             ORDER BY payment_date, repayment_id FOR UPDATE'
        );
        $repaymentStmt->execute(['loan_id' => $loanId]);
        $repayments = $repaymentStmt->fetchAll();
        $previous = null;
        foreach ($repayments as &$repayment) {
            if ((int) $repayment['repayment_id'] === $repaymentId) {
                $previous = $repayment;
                $repayment['amount'] = $correctedAmount;
                $repayment['payment_date'] = $correctedDate;
            }
        }
        unset($repayment);
        if (!$previous) {
            throw new InvalidArgumentException('Repayment was not found.');
        }
        usort($repayments, static fn (array $left, array $right): int =>
            [$left['payment_date'], (int) $left['repayment_id']] <=> [$right['payment_date'], (int) $right['repayment_id']]
        );

        $interestMethod = $loan['interest_method'] ?? 'none';
        $rate = (float) ($loan['interest_rate'] ?? 0) / 100;
        $balance = (float) $loan['approved_amount'];
        if ($interestMethod === 'flat') {
            $balance = round($balance * (1 + $rate), 2);
        }
        $lastInterestDate = $approvalDate;
        foreach ($repayments as $repayment) {
            $repaymentDate = new DateTimeImmutable($repayment['payment_date']);
            if ($interestMethod === 'reducing_balance') {
                for ($period = 1; $period <= 600; $period++) {
                    $interestDate = maximum_loan_due_date($approvalDate, $period);
                    if ($interestDate > $repaymentDate) {
                        break;
                    }
                    if ($interestDate <= $lastInterestDate) {
                        continue;
                    }
                    $balance = round($balance * (1 + $rate), 2);
                    $lastInterestDate = $interestDate;
                }
            }
            $balance = round($balance - (float) $repayment['amount'], 2);
            if ($balance < 0) {
                throw new InvalidArgumentException('This correction would make repayments exceed the total loan balance.');
            }
        }
        if ($interestMethod === 'reducing_balance') {
            for ($period = 1; $period <= 600; $period++) {
                $interestDate = maximum_loan_due_date($approvalDate, $period);
                if ($interestDate > new DateTimeImmutable('today')) {
                    break;
                }
                if ($interestDate <= $lastInterestDate) {
                    continue;
                }
                $balance = round($balance * (1 + $rate), 2);
                $lastInterestDate = $interestDate;
            }
        }

        $audit = $pdo->prepare(
            'INSERT INTO loan_repayment_audit
                (repayment_id, previous_amount, new_amount, previous_payment_date, new_payment_date, reason, changed_by)
             VALUES
                (:repayment_id, :previous_amount, :new_amount, :previous_payment_date, :new_payment_date, :reason, :changed_by)'
        );
        $audit->execute([
            'repayment_id' => $repaymentId,
            'previous_amount' => $previous['amount'],
            'new_amount' => $correctedAmount,
            'previous_payment_date' => $previous['payment_date'],
            'new_payment_date' => $correctedDate,
            'reason' => $reason,
            'changed_by' => $adminId,
        ]);
        $updateRepayment = $pdo->prepare('UPDATE loan_repayments SET amount = :amount, payment_date = :payment_date WHERE repayment_id = :repayment_id');
        $updateRepayment->execute(['amount' => $correctedAmount, 'payment_date' => $correctedDate, 'repayment_id' => $repaymentId]);
        $newStatus = $balance === 0.0
            ? 'completed'
            : (!empty($loan['due_date']) && $loan['due_date'] < date('Y-m-d') ? 'overdue' : 'approved');
        $updateLoan = $pdo->prepare('UPDATE loans SET outstanding_balance = :balance, interest_accrued_until = :interest_accrued_until, status = :status WHERE loan_id = :loan_id');
        $updateLoan->execute([
            'balance' => $balance,
            'interest_accrued_until' => $lastInterestDate->format('Y-m-d'),
            'status' => $newStatus,
            'loan_id' => $loanId,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function maximum_loan_due_date(DateTimeImmutable $from, int $maximumTermMonths): DateTimeImmutable
{
    $monthIndex = ((int) $from->format('Y') * 12) + ((int) $from->format('n') - 1) + $maximumTermMonths;
    $year = intdiv($monthIndex, 12);
    $month = ($monthIndex % 12) + 1;
    $targetMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $day = min((int) $from->format('j'), (int) $targetMonth->format('t'));
    return $targetMonth->setDate($year, $month, $day);
}

function calculate_monthly_loan_installment(float $principal, float $ratePercent, string $method, int $termMonths): float
{
    if ($principal <= 0 || $ratePercent < 0 || $termMonths < 1) {
        throw new InvalidArgumentException('Loan installment inputs are invalid.');
    }
    if ($method === 'flat') {
        return round(($principal * (1 + ($ratePercent / 100))) / $termMonths, 2);
    }
    if ($method !== 'reducing_balance') {
        throw new InvalidArgumentException('Unknown loan interest method.');
    }
    $monthlyRate = $ratePercent / 100;
    if ($monthlyRate === 0.0) {
        return round($principal / $termMonths, 2);
    }
    return round(($principal * $monthlyRate) / (1 - pow(1 + $monthlyRate, -$termMonths)), 2);
}

function sync_reducing_balance_loan_interest(): void
{
    $loans = db()->query(
        "SELECT loan_id
         FROM loans
         WHERE status IN ('approved', 'overdue')
           AND interest_method = 'reducing_balance'
           AND COALESCE(outstanding_balance, 0) > 0
           AND approval_date IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($loans as $loanId) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT outstanding_balance, interest_rate, interest_accrued_until, approval_date
                 FROM loans
                 WHERE loan_id = :loan_id
                   AND status IN ('approved', 'overdue')
                   AND interest_method = 'reducing_balance'
                 FOR UPDATE"
            );
            $stmt->execute(['loan_id' => $loanId]);
            $loan = $stmt->fetch();
            if (!$loan) {
                $pdo->commit();
                continue;
            }

            $balance = round((float) $loan['outstanding_balance'], 2);
            $approvalDate = new DateTimeImmutable($loan['approval_date']);
            $lastInterestDate = new DateTimeImmutable($loan['interest_accrued_until'] ?: $loan['approval_date']);
            $today = new DateTimeImmutable('today');
            $changed = false;
            for ($period = 1; $period <= 600; $period++) {
                $interestDate = maximum_loan_due_date($approvalDate, $period);
                if ($interestDate > $today) {
                    break;
                }
                if ($interestDate <= $lastInterestDate) {
                    continue;
                }
                $balance = round($balance * (1 + ((float) $loan['interest_rate'] / 100)), 2);
                $lastInterestDate = $interestDate;
                $changed = true;
            }
            if ($changed) {
                $update = $pdo->prepare('UPDATE loans SET outstanding_balance = :balance, interest_accrued_until = :interest_accrued_until WHERE loan_id = :loan_id');
                $update->execute([
                    'balance' => $balance,
                    'interest_accrued_until' => $lastInterestDate->format('Y-m-d'),
                    'loan_id' => $loanId,
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
}

function record_loan_repayment(
    int $loanId,
    float $amount,
    string $paymentDate,
    ?int $paymentMethodId,
    ?string $reference,
    int $adminId,
    string $idempotencyKey
): bool {
    if (!preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
        throw new InvalidArgumentException('Invalid repayment submission.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $duplicateStmt = $pdo->prepare('SELECT repayment_id FROM loan_repayments WHERE idempotency_key = :idempotency_key LIMIT 1');
        $duplicateStmt->execute(['idempotency_key' => $idempotencyKey]);
        if ($duplicateStmt->fetch()) {
            $pdo->commit();
            return false;
        }

        $loanStmt = $pdo->prepare(
            "SELECT loan_id, outstanding_balance, status, interest_method, interest_rate,
                    interest_accrued_until, approval_date, due_date
             FROM loans
             WHERE loan_id = :loan_id
             FOR UPDATE"
        );
        $loanStmt->execute(['loan_id' => $loanId]);
        $loan = $loanStmt->fetch();

        if (!$loan || !in_array($loan['status'], ['approved', 'overdue'], true)) {
            throw new InvalidArgumentException('Only approved loans can receive repayments.');
        }

        $amount = round($amount, 2);
        $balance = round((float) ($loan['outstanding_balance'] ?? 0), 2);
        $paymentDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        $approvalDate = !empty($loan['approval_date']) ? new DateTimeImmutable($loan['approval_date']) : null;
        if (!$paymentDateValue || $paymentDateValue->format('Y-m-d') !== $paymentDate ||
            $paymentDateValue > new DateTimeImmutable('today') ||
            ($approvalDate && $paymentDateValue < $approvalDate)) {
            throw new InvalidArgumentException('Payment date must be valid and between loan approval and today.');
        }

        $interestAccruedUntil = $loan['interest_accrued_until'] ?: $loan['approval_date'];
        if ($loan['interest_method'] === 'reducing_balance') {
            if (!$approvalDate || !$interestAccruedUntil) {
                throw new InvalidArgumentException('The reducing-balance loan is missing its interest schedule date.');
            }
            $lastInterestDate = new DateTimeImmutable($interestAccruedUntil);
            $rate = (float) $loan['interest_rate'] / 100;
            for ($period = 1; $period <= 600; $period++) {
                $interestDate = maximum_loan_due_date($approvalDate, $period);
                if ($interestDate > $paymentDateValue) {
                    break;
                }
                if ($interestDate <= $lastInterestDate) {
                    continue;
                }
                $balance = round($balance * (1 + $rate), 2);
                $lastInterestDate = $interestDate;
            }
            $interestAccruedUntil = $lastInterestDate->format('Y-m-d');
        }
        if ($amount <= 0 || $amount > $balance) {
            throw new InvalidArgumentException('Repayment must be greater than zero and no more than the outstanding balance.');
        }
        if ($reference !== null && $reference !== '') {
            $referenceStmt = $pdo->prepare('SELECT repayment_id FROM loan_repayments WHERE loan_id = :loan_id AND reference = :reference LIMIT 1');
            $referenceStmt->execute(['loan_id' => $loanId, 'reference' => $reference]);
            if ($referenceStmt->fetch()) {
                throw new InvalidArgumentException('A repayment with this reference has already been recorded for the loan.');
            }
        }

        $newBalance = round($balance - $amount, 2);
        $repaymentStmt = $pdo->prepare(
            'INSERT INTO loan_repayments (loan_id, amount, payment_date, payment_method_id, reference, recorded_by, idempotency_key)
             VALUES (:loan_id, :amount, :payment_date, :payment_method_id, :reference, :recorded_by, :idempotency_key)'
        );
        $repaymentStmt->execute([
            'loan_id' => $loanId,
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method_id' => $paymentMethodId,
            'reference' => $reference !== '' ? $reference : null,
            'recorded_by' => $adminId,
            'idempotency_key' => $idempotencyKey,
        ]);

        $status = $newBalance <= 0
            ? 'completed'
            : (!empty($loan['due_date']) && $loan['due_date'] < date('Y-m-d') ? 'overdue' : 'approved');
        $updateStmt = $pdo->prepare('UPDATE loans SET outstanding_balance = :balance, interest_accrued_until = :interest_accrued_until, status = :status WHERE loan_id = :loan_id');
        $updateStmt->execute([
            'balance' => $newBalance,
            'interest_accrued_until' => $interestAccruedUntil,
            'status' => $status,
            'loan_id' => $loanId,
        ]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
