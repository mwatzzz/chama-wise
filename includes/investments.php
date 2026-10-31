<?php

declare(strict_types=1);

function investment_categories(): array
{
    return [
        'Treasury bills',
        'Government bonds',
        'Money market funds',
        'Fixed deposits',
        'Unit trusts',
        'SACCO products',
        'Diversified long-term funds',
    ];
}

function save_investment_opportunity(array $input, int $adminId, ?int $opportunityId = null): void
{
    $title = trim((string) ($input['title'] ?? ''));
    $category = trim((string) ($input['category'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $riskLevel = (string) ($input['risk_level'] ?? 'unknown');
    $investmentPeriod = trim((string) ($input['investment_period'] ?? ''));
    $liquidity = trim((string) ($input['liquidity'] ?? ''));
    $minimumInput = trim((string) ($input['minimum_amount'] ?? ''));
    $risks = trim((string) ($input['risks'] ?? ''));
    $source = trim((string) ($input['source_url'] ?? ''));
    $verifiedAt = trim((string) ($input['verified_at'] ?? ''));
    $verificationStatus = (string) ($input['verification_status'] ?? 'unverified');
    $annualReturnInput = trim((string) ($input['annual_return_assumption'] ?? ''));
    $activeInput = (string) ($input['is_active'] ?? '1');
    $active = $activeInput === '1';
    $minimum = $minimumInput === '' ? null : filter_var($minimumInput, FILTER_VALIDATE_FLOAT);
    $annualReturn = $annualReturnInput === '' ? null : filter_var($annualReturnInput, FILTER_VALIDATE_FLOAT);
    $verifiedDate = $verifiedAt === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $verifiedAt);
    $sourceIsValid = $source === '' || (
        filter_var($source, FILTER_VALIDATE_URL) &&
        in_array(strtolower((string) parse_url($source, PHP_URL_SCHEME)), ['http', 'https'], true)
    );

    if ($adminId < 1 || ($opportunityId !== null && $opportunityId < 1) ||
        $title === '' || mb_strlen($title) > 160 ||
        !in_array($category, investment_categories(), true) ||
        $description === '' || mb_strlen($description) > 1000 ||
        !in_array($riskLevel, ['unknown', 'low', 'moderate', 'high'], true) ||
        mb_strlen($investmentPeriod) > 120 || mb_strlen($liquidity) > 120 ||
        !in_array($activeInput, ['0', '1'], true) ||
        mb_strlen($risks) > 1000 || !$sourceIsValid ||
        (($source === '') !== ($verifiedAt === '')) ||
        ($verifiedAt !== '' && (
            !$verifiedDate || $verifiedDate->format('Y-m-d') !== $verifiedAt ||
            $verifiedDate > new DateTimeImmutable('today')
        )) ||
        !in_array($verificationStatus, ['verified', 'unverified', 'expired'], true) ||
        ($verificationStatus === 'verified' && ($source === '' || $verifiedAt === '')) ||
        ($minimumInput !== '' && (
            $minimum === false || !is_finite((float) $minimum) ||
            $minimum < 0 || $minimum > 1000000000
        )) ||
        ($annualReturnInput !== '' && (
            $annualReturn === false || !is_finite((float) $annualReturn) ||
            $annualReturn < -100 || $annualReturn > 100 ||
            $source === '' || $verifiedAt === ''
        ))) {
        throw new InvalidArgumentException('Enter valid opportunity details. Verified information and return assumptions require a source and verification date.');
    }

    $values = [
        'title' => $title,
        'category' => $category,
        'description' => $description,
        'risk_level' => $riskLevel,
        'investment_period' => $investmentPeriod !== '' ? $investmentPeriod : null,
        'liquidity' => $liquidity !== '' ? $liquidity : null,
        'minimum_amount' => $minimum !== null ? round((float) $minimum, 2) : null,
        'risks' => $risks !== '' ? $risks : null,
        'source_url' => $source !== '' ? $source : null,
        'verified_at' => $verifiedAt !== '' ? $verifiedAt : null,
        'annual_return_assumption' => $annualReturn !== null ? round((float) $annualReturn, 3) : null,
        'verification_status' => $verificationStatus,
        'is_active' => $active ? 1 : 0,
    ];
    if ($opportunityId === null) {
        $values['created_by'] = $adminId;
        $stmt = db()->prepare(
            'INSERT INTO investment_opportunities
                (title, category, description, risk_level, investment_period, liquidity, minimum_amount,
                 risks, source_url, verified_at, annual_return_assumption, verification_status, is_active, created_by)
             VALUES
                (:title, :category, :description, :risk_level, :investment_period, :liquidity, :minimum_amount,
                 :risks, :source_url, :verified_at, :annual_return_assumption, :verification_status, :is_active, :created_by)'
        );
    } else {
        $values['opportunity_id'] = $opportunityId;
        $stmt = db()->prepare(
            'UPDATE investment_opportunities
             SET title = :title, category = :category, description = :description, risk_level = :risk_level,
                 investment_period = :investment_period, liquidity = :liquidity, minimum_amount = :minimum_amount,
                 risks = :risks, source_url = :source_url, verified_at = :verified_at,
                 annual_return_assumption = :annual_return_assumption, verification_status = :verification_status,
                 is_active = :is_active
             WHERE opportunity_id = :opportunity_id'
        );
    }
    $stmt->execute($values);
    if ($opportunityId !== null && $stmt->rowCount() !== 1) {
        $exists = db()->prepare('SELECT opportunity_id FROM investment_opportunities WHERE opportunity_id = :id LIMIT 1');
        $exists->execute(['id' => $opportunityId]);
        if (!$exists->fetchColumn()) {
            throw new InvalidArgumentException('Investment opportunity was not found.');
        }
    }
}

function submit_group_investment_proposal(int $memberId, array $input, string $submissionKey): int
{
    $title = trim((string) ($input['title'] ?? ''));
    $category = (string) ($input['category'] ?? '');
    $description = trim((string) ($input['description'] ?? ''));
    $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($memberId < 1 || !preg_match('/^[a-f0-9]{64}$/', $submissionKey) ||
        $title === '' || mb_strlen($title) > 160 || !in_array($category, investment_categories(), true) ||
        $description === '' || mb_strlen($description) > 1000 ||
        $amount === false || !is_finite((float) $amount) ||
        $amount < (float) financial_setting('investment_minimum') || $amount > 1000000000) {
        throw new InvalidArgumentException('Enter valid group investment proposal details and an amount at or above the configured minimum.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $memberStmt = $pdo->prepare("SELECT status FROM members WHERE member_id = :member_id FOR UPDATE");
        $memberStmt->execute(['member_id' => $memberId]);
        if ($memberStmt->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Only active members can submit group investment proposals.');
        }
        $duplicateStmt = $pdo->prepare('SELECT proposal_id, submitted_by FROM group_investment_proposals WHERE submission_key = :key LIMIT 1');
        $duplicateStmt->execute(['key' => $submissionKey]);
        $duplicate = $duplicateStmt->fetch();
        if ($duplicate) {
            if ((int) $duplicate['submitted_by'] !== $memberId) {
                throw new InvalidArgumentException('This proposal submission was already used.');
            }
            $pdo->commit();
            return (int) $duplicate['proposal_id'];
        }

        $insert = $pdo->prepare(
            "INSERT INTO group_investment_proposals
                (submitted_by, title, category, description, simulated_amount, submission_key)
             VALUES
                (:submitted_by, :title, :category, :description, :simulated_amount, :submission_key)"
        );
        $insert->execute([
            'submitted_by' => $memberId,
            'title' => $title,
            'category' => $category,
            'description' => $description,
            'simulated_amount' => round((float) $amount, 2),
            'submission_key' => $submissionKey,
        ]);
        $proposalId = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $proposalId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function create_investment_goal(int $memberId, array $input, string $submissionKey): int
{
    $title = trim((string) ($input['title'] ?? ''));
    $target = filter_var($input['target_amount'] ?? null, FILTER_VALIDATE_FLOAT);
    $initial = filter_var($input['initial_amount'] ?? null, FILTER_VALIDATE_FLOAT);
    $monthly = filter_var($input['monthly_contribution'] ?? null, FILTER_VALIDATE_FLOAT);
    $duration = filter_var($input['duration_months'] ?? null, FILTER_VALIDATE_INT);
    $opportunityId = (int) ($input['opportunity_id'] ?? 0);
    $returnInput = trim((string) ($input['annual_return_assumption'] ?? ''));
    $targetDateInput = trim((string) ($input['target_date'] ?? ''));
    $targetDate = $targetDateInput === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $targetDateInput);

    if ($memberId < 1 || !preg_match('/^[a-f0-9]{64}$/', $submissionKey) ||
        $title === '' || mb_strlen($title) > 160 ||
        $target === false || $initial === false || $monthly === false ||
        !is_finite((float) $target) || !is_finite((float) $initial) || !is_finite((float) $monthly) ||
        $duration === false || $duration < 1 || $duration > 600 ||
        $target < (float) financial_setting('investment_goal_minimum') ||
        $target > 1000000000 || $initial < 0 || $initial > 1000000000 ||
        $monthly < 0 || $monthly > 1000000000 ||
        ($targetDateInput !== '' && (
            !$targetDate || $targetDate->format('Y-m-d') !== $targetDateInput ||
            $targetDate < new DateTimeImmutable('today')
        ))) {
        throw new InvalidArgumentException('Enter valid investment goal details and amounts.');
    }
    if ($initial > 0 && $initial < (float) financial_setting('investment_minimum')) {
        throw new InvalidArgumentException('An initial simulated investment must be at least KES ' . number_format((float) financial_setting('investment_minimum'), 2) . '.');
    }
    if ($monthly > 0 && $monthly < (float) financial_setting('monthly_savings_goal_minimum')) {
        throw new InvalidArgumentException('The monthly savings goal must be zero or at least KES ' . number_format((float) financial_setting('monthly_savings_goal_minimum'), 2) . '.');
    }
    $annualReturn = $returnInput === ''
        ? (float) financial_setting('investment_annual_return_assumption')
        : filter_var($returnInput, FILTER_VALIDATE_FLOAT);
    if ($annualReturn === false || !is_finite((float) $annualReturn) || $annualReturn < -100 || $annualReturn > 100) {
        throw new InvalidArgumentException('Enter a projected annual return between -100 and 100 percent.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $memberStmt = $pdo->prepare("SELECT status FROM members WHERE member_id = :member_id FOR UPDATE");
        $memberStmt->execute(['member_id' => $memberId]);
        $memberStatus = $memberStmt->fetchColumn();
        if ($memberStatus !== 'active') {
            throw new InvalidArgumentException('Only active members can create investment plans.');
        }
        $duplicateStmt = $pdo->prepare('SELECT goal_id, member_id FROM investment_goals WHERE submission_key = :key LIMIT 1');
        $duplicateStmt->execute(['key' => $submissionKey]);
        $duplicate = $duplicateStmt->fetch();
        if ($duplicate) {
            if ((int) $duplicate['member_id'] !== $memberId) {
                throw new InvalidArgumentException('This investment submission was already used.');
            }
            $pdo->commit();
            return (int) $duplicate['goal_id'];
        }

        $selectedOpportunity = null;
        if ($opportunityId > 0) {
            $opportunityStmt = $pdo->prepare('SELECT opportunity_id, annual_return_assumption, source_url, verified_at, verification_status FROM investment_opportunities WHERE opportunity_id = :id AND is_active = 1 LIMIT 1');
            $opportunityStmt->execute(['id' => $opportunityId]);
            $selectedOpportunity = $opportunityStmt->fetch();
            if (!$selectedOpportunity) {
                throw new InvalidArgumentException('The selected investment opportunity is unavailable.');
            }
        }
        if ($returnInput === '' && $selectedOpportunity &&
            $selectedOpportunity['annual_return_assumption'] !== null &&
            $selectedOpportunity['verification_status'] === 'verified' &&
            !empty($selectedOpportunity['source_url']) && !empty($selectedOpportunity['verified_at'])) {
            $annualReturn = (float) $selectedOpportunity['annual_return_assumption'];
        }

        $insertGoal = $pdo->prepare(
            'INSERT INTO investment_goals
                (member_id, opportunity_id, title, target_amount, initial_amount, monthly_contribution, annual_return_assumption, duration_months, submission_key, target_date)
             VALUES
                (:member_id, :opportunity_id, :title, :target_amount, :initial_amount, :monthly_contribution, :annual_return_assumption, :duration_months, :submission_key, :target_date)'
        );
        $insertGoal->execute([
            'member_id' => $memberId,
            'opportunity_id' => $opportunityId > 0 ? $opportunityId : null,
            'title' => $title,
            'target_amount' => round((float) $target, 2),
            'initial_amount' => round((float) $initial, 2),
            'monthly_contribution' => round((float) $monthly, 2),
            'annual_return_assumption' => round((float) $annualReturn, 3),
            'duration_months' => $duration,
            'submission_key' => $submissionKey,
            'target_date' => $targetDate?->format('Y-m-d'),
        ]);
        $goalId = (int) $pdo->lastInsertId();
        if ($initial > 0) {
            $transaction = $pdo->prepare(
                "INSERT INTO investment_transactions (goal_id, amount, transaction_type, note)
                 VALUES (:goal_id, :amount, 'contribution', :note)"
            );
            $transaction->execute([
                'goal_id' => $goalId,
                'amount' => round((float) $initial, 2),
                'note' => 'Initial simulated investment.',
            ]);
        }
        $pdo->commit();
        return $goalId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function update_investment_goal(int $memberId, int $goalId, array $input): void
{
    $title = trim((string) ($input['title'] ?? ''));
    $target = filter_var($input['target_amount'] ?? null, FILTER_VALIDATE_FLOAT);
    $monthly = filter_var($input['monthly_contribution'] ?? null, FILTER_VALIDATE_FLOAT);
    $duration = filter_var($input['duration_months'] ?? null, FILTER_VALIDATE_INT);
    $annualReturn = filter_var($input['annual_return_assumption'] ?? null, FILTER_VALIDATE_FLOAT);
    $targetDateInput = trim((string) ($input['target_date'] ?? ''));
    $targetDate = $targetDateInput === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $targetDateInput);
    if ($memberId < 1 || $goalId < 1 || $title === '' || mb_strlen($title) > 160 ||
        $target === false || !is_finite((float) $target) ||
        $target < (float) financial_setting('investment_goal_minimum') || $target > 1000000000 ||
        $monthly === false || !is_finite((float) $monthly) || $monthly < 0 || $monthly > 1000000000 ||
        ($monthly > 0 && $monthly < (float) financial_setting('monthly_savings_goal_minimum')) ||
        $duration === false || $duration < 1 || $duration > 600 ||
        $annualReturn === false || !is_finite((float) $annualReturn) || $annualReturn < -100 || $annualReturn > 100 ||
        ($targetDateInput !== '' && (
            !$targetDate || $targetDate->format('Y-m-d') !== $targetDateInput ||
            $targetDate < new DateTimeImmutable('today')
        ))) {
        throw new InvalidArgumentException('Enter valid goal details, amounts and target date.');
    }

    $stmt = db()->prepare(
        'UPDATE investment_goals
         SET title = :title, target_amount = :target_amount, monthly_contribution = :monthly_contribution,
             annual_return_assumption = :annual_return_assumption, duration_months = :duration_months,
             target_date = :target_date
         WHERE goal_id = :goal_id AND member_id = :member_id AND status = \'active\''
    );
    $stmt->execute([
        'title' => $title,
        'target_amount' => round((float) $target, 2),
        'monthly_contribution' => round((float) $monthly, 2),
        'annual_return_assumption' => round((float) $annualReturn, 3),
        'duration_months' => $duration,
        'target_date' => $targetDate?->format('Y-m-d'),
        'goal_id' => $goalId,
        'member_id' => $memberId,
    ]);
    if ($stmt->rowCount() !== 1) {
        $exists = db()->prepare(
            'SELECT goal_id FROM investment_goals
             WHERE goal_id = :goal_id AND member_id = :member_id AND status = \'active\' LIMIT 1'
        );
        $exists->execute(['goal_id' => $goalId, 'member_id' => $memberId]);
        if (!$exists->fetchColumn()) {
            throw new InvalidArgumentException('Active investment goal was not found.');
        }
    }
}

function set_investment_goal_status(int $memberId, int $goalId, string $status): void
{
    if ($memberId < 1 || $goalId < 1 || !in_array($status, ['completed', 'inactive'], true)) {
        throw new InvalidArgumentException('Choose a valid investment goal status.');
    }
    $stmt = db()->prepare(
        'UPDATE investment_goals SET status = :status
         WHERE goal_id = :goal_id AND member_id = :member_id AND status = \'active\''
    );
    $stmt->execute(['status' => $status, 'goal_id' => $goalId, 'member_id' => $memberId]);
    if ($stmt->rowCount() !== 1) {
        throw new InvalidArgumentException('Active investment goal was not found or has already been closed.');
    }
}

function record_simulated_investment_transaction(
    int $memberId,
    int $goalId,
    float $amount,
    string $type,
    string $note,
    string $submissionKey
): bool {
    if ($memberId < 1 || $goalId < 1 || !in_array($type, ['contribution', 'withdrawal'], true) ||
        !preg_match('/^[a-f0-9]{64}$/', $submissionKey)) {
        throw new InvalidArgumentException('Invalid simulated investment transaction.');
    }
    $amount = round($amount, 2);
    if (!is_finite($amount) || $amount <= 0 || $amount > 1000000000 ||
        ($type === 'contribution' && $amount < (float) financial_setting('investment_minimum'))) {
        throw new InvalidArgumentException('Enter a valid simulated investment amount. Contributions must meet the configured minimum.');
    }
    if (mb_strlen($note) > 255 || ($type === 'withdrawal' && trim($note) === '')) {
        throw new InvalidArgumentException('Enter a reason for the simulated withdrawal.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $duplicate = $pdo->prepare('SELECT transaction_id FROM investment_transactions WHERE submission_key = :key LIMIT 1');
        $duplicate->execute(['key' => $submissionKey]);
        if ($duplicate->fetch()) {
            $pdo->commit();
            return false;
        }

        $goalStmt = $pdo->prepare(
            'SELECT g.goal_id
             FROM investment_goals g
             INNER JOIN members m ON m.member_id = g.member_id
             WHERE g.goal_id = :goal_id AND g.member_id = :member_id
               AND g.status = \'active\' AND m.status = \'active\'
             FOR UPDATE'
        );
        $goalStmt->execute(['goal_id' => $goalId, 'member_id' => $memberId]);
        if (!$goalStmt->fetch()) {
            throw new InvalidArgumentException('Active investment goal was not found.');
        }

        if ($type === 'withdrawal') {
            $balanceStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'contribution' THEN amount ELSE -amount END), 0)
                 FROM investment_transactions WHERE goal_id = :goal_id"
            );
            $balanceStmt->execute(['goal_id' => $goalId]);
            if ($amount > round((float) $balanceStmt->fetchColumn(), 2)) {
                throw new InvalidArgumentException('A simulated withdrawal cannot exceed the simulated portfolio capital.');
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO investment_transactions
                (goal_id, amount, transaction_type, note, submission_key)
             VALUES
                (:goal_id, :amount, :transaction_type, :note, :submission_key)'
        );
        $insert->execute([
            'goal_id' => $goalId,
            'amount' => $amount,
            'transaction_type' => $type,
            'note' => trim($note) !== '' ? trim($note) : null,
            'submission_key' => $submissionKey,
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

/** @return array{capital: float, projected_value: float, projected_gain: float, zero_return_value: float} */
function calculate_investment_projection(float $initial, float $monthly, float $annualReturn, int $months): array
{
    if (!is_finite($initial) || !is_finite($monthly) || !is_finite($annualReturn) ||
        $initial < 0 || $initial > 1000000000 || $monthly < 0 || $monthly > 1000000000 ||
        $annualReturn < -100 || $annualReturn > 100 ||
        $months < 1 || $months > 600) {
        throw new InvalidArgumentException('Enter valid non-negative amounts, an annual assumption from -100% to 100%, and a duration from 1 to 600 months.');
    }

    $monthlyRate = pow(1 + ($annualReturn / 100), 1 / 12) - 1;
    $zeroReturnValue = $initial + ($monthly * $months);
    $projectedValue = $monthlyRate === 0.0
        ? $zeroReturnValue
        : ($initial * pow(1 + $monthlyRate, $months)) +
            ($monthly * ((pow(1 + $monthlyRate, $months) - 1) / $monthlyRate));
    $capital = round($zeroReturnValue, 2);
    $projectedValue = round($projectedValue, 2);
    return [
        'capital' => $capital,
        'projected_value' => $projectedValue,
        'projected_gain' => round($projectedValue - $capital, 2),
        'zero_return_value' => $capital,
    ];
}
