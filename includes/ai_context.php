<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function build_member_financial_context(int $memberId, string $question = ''): array
{
    if ($memberId < 1) {
        throw new InvalidArgumentException('A valid member is required.');
    }

    $memberStmt = db()->prepare('SELECT member_id, name, status, joined_date FROM members WHERE member_id = :member_id LIMIT 1');
    $memberStmt->execute(['member_id' => $memberId]);
    $member = $memberStmt->fetch();
    if (!$member) {
        throw new InvalidArgumentException('Member was not found.');
    }

    $normalizedQuestion = mb_strtolower($question);
    $contributionQuestion = preg_match('/contribut|mchango|michango|paid|payment|saving|akiba/i', $normalizedQuestion) === 1;
    $loanQuestion = preg_match('/loan|mkopo|debt|deni|repay|pay.?back|borrow|eligib/i', $normalizedQuestion) === 1;
    $penaltyQuestion = preg_match('/penalt|fine|faini|adhabu/i', $normalizedQuestion) === 1;
    $investmentQuestion = preg_match('/invest|uwekezaji|opportunit|portfolio|project|scenario|return|goal|faida/i', $normalizedQuestion) === 1;
    $overviewQuestion = $question === '' || (!$contributionQuestion && !$loanQuestion && !$penaltyQuestion && !$investmentQuestion);
    $includeContributions = $overviewQuestion || $contributionQuestion;
    $includeLoans = $overviewQuestion || $loanQuestion;
    $includePenalties = $overviewQuestion || $penaltyQuestion;
    $includeInvestments = $investmentQuestion;
    $includeHistory = $question === '';

    $contributions = [];
    if ($includeContributions) {
        $contributionSummaryStmt = db()->prepare(
            "SELECT COUNT(*) AS total_count, COALESCE(SUM(amount), 0) AS total_amount
             FROM contributions
             WHERE member_id = :member_id AND status = 'recorded'"
        );
        $contributionSummaryStmt->execute(['member_id' => $memberId]);
        $contributionSummary = $contributionSummaryStmt->fetch();
        $contributions = [
            'total_count' => (int) $contributionSummary['total_count'],
            'total_amount' => round((float) $contributionSummary['total_amount'], 2),
        ];
    }
    if ($contributionQuestion || $includeHistory) {
        $contributionStmt = db()->prepare(
            'SELECT amount, contribution_date, status, notes
             FROM contributions
             WHERE member_id = :member_id
             ORDER BY contribution_date DESC, contribution_id DESC
             LIMIT 5'
        );
        $contributionStmt->execute(['member_id' => $memberId]);
        $contributionHistory = $contributionStmt->fetchAll();
    } else {
        $contributionHistory = [];
    }

    $loanSummary = null;
    $repaymentSummary = null;
    $loans = [];
    $repayments = [];
    if ($includeLoans) {
        $loanSummaryStmt = db()->prepare(
            'SELECT COUNT(*) AS total_count, COALESCE(SUM(outstanding_balance), 0) AS outstanding_balance
             FROM loans
             WHERE member_id = :member_id'
        );
        $loanSummaryStmt->execute(['member_id' => $memberId]);
        $loanSummary = $loanSummaryStmt->fetch();
        $repaymentSummaryStmt = db()->prepare(
            'SELECT COUNT(*) AS total_count, COALESCE(SUM(r.amount), 0) AS total_amount
             FROM loan_repayments r
             INNER JOIN loans l ON l.loan_id = r.loan_id AND l.member_id = :member_id'
        );
        $repaymentSummaryStmt->execute(['member_id' => $memberId]);
        $repaymentSummary = $repaymentSummaryStmt->fetch();
    }
    if ($loanQuestion || $includeHistory) {
        $loanStmt = db()->prepare(
            'SELECT loan_id, requested_amount, approved_amount, interest_rate, outstanding_balance,
                    purpose, status, rejection_reason, application_date, approval_date
             FROM loans
             WHERE member_id = :member_id
             ORDER BY loan_id DESC
             LIMIT 5'
        );
        $loanStmt->execute(['member_id' => $memberId]);
        $loans = $loanStmt->fetchAll();
        $repaymentStmt = db()->prepare(
            'SELECT r.loan_id, r.amount, r.payment_date, r.reference
             FROM loan_repayments r
             INNER JOIN loans l ON l.loan_id = r.loan_id AND l.member_id = :member_id
             ORDER BY r.payment_date DESC, r.repayment_id DESC
             LIMIT 5'
        );
        $repaymentStmt->execute(['member_id' => $memberId]);
        $repayments = $repaymentStmt->fetchAll();
    }

    $penaltySummary = null;
    $penalties = [];
    if ($includePenalties) {
        $penaltySummaryStmt = db()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN status = 'outstanding' THEN amount ELSE 0 END), 0) AS outstanding_total
             FROM penalties
             WHERE member_id = :member_id"
        );
        $penaltySummaryStmt->execute(['member_id' => $memberId]);
        $penaltySummary = $penaltySummaryStmt->fetch();
    }
    if ($penaltyQuestion || $includeHistory) {
        $penaltyStmt = db()->prepare(
            'SELECT amount, reason, penalty_date, status
             FROM penalties
             WHERE member_id = :member_id
             ORDER BY penalty_date DESC, penalty_id DESC
             LIMIT 5'
        );
        $penaltyStmt->execute(['member_id' => $memberId]);
        $penalties = $penaltyStmt->fetchAll();
    }

    $eligibility = $loanQuestion ? evaluate_loan_application($memberId, null) : null;
    $investmentPlans = [];
    $verifiedOpportunities = [];
    if ($includeInvestments) {
        $investmentStmt = db()->prepare(
            'SELECT g.goal_id, g.title, g.target_amount, g.monthly_contribution,
                    g.annual_return_assumption, g.duration_months, g.target_date, g.status,
                    o.title AS opportunity_title, o.source_url, o.verified_at,
                    COALESCE(SUM(CASE WHEN t.transaction_type = \'contribution\' THEN t.amount ELSE -t.amount END), 0) AS simulated_capital
             FROM investment_goals g
             LEFT JOIN investment_opportunities o ON o.opportunity_id = g.opportunity_id
             LEFT JOIN investment_transactions t ON t.goal_id = g.goal_id
             WHERE g.member_id = :member_id
             GROUP BY g.goal_id, g.title, g.target_amount, g.monthly_contribution,
                      g.annual_return_assumption, g.duration_months, g.target_date, g.status, g.created_at,
                      o.title, o.source_url, o.verified_at
             ORDER BY g.created_at DESC
             LIMIT 5'
        );
        $investmentStmt->execute(['member_id' => $memberId]);
        foreach ($investmentStmt->fetchAll() as $plan) {
            $capital = round((float) $plan['simulated_capital'], 2);
            $active = $plan['status'] === 'active';
            $projection = $active
                ? calculate_investment_projection(
                    $capital,
                    (float) $plan['monthly_contribution'],
                    (float) $plan['annual_return_assumption'],
                    (int) $plan['duration_months']
                )
                : [
                    'projected_value' => $capital,
                    'projected_gain' => 0.0,
                    'zero_return_value' => $capital,
                ];
            $investmentPlans[] = [
                'title' => (string) $plan['title'],
                'status' => (string) $plan['status'],
                'opportunity' => $plan['opportunity_title'],
                'target_kes' => (float) $plan['target_amount'],
                'target_date' => $plan['target_date'],
                'simulated_capital_kes' => $capital,
                'remaining_to_goal_kes' => max(0, round((float) $plan['target_amount'] - $capital, 2)),
                'monthly_contribution_kes' => (float) $plan['monthly_contribution'],
                'assumed_annual_return_percent' => (float) $plan['annual_return_assumption'],
                'duration_months' => (int) $plan['duration_months'],
                'projected_future_value_kes' => $projection['projected_value'],
                'projected_gain_or_loss_kes' => $projection['projected_gain'],
                'zero_return_value_kes' => $projection['zero_return_value'],
                'future_projection_is_active' => $active,
                'projection_is_estimate_not_actual_return' => true,
                'opportunity_source_url' => $plan['source_url'],
                'opportunity_verified_at' => $plan['verified_at'],
            ];
        }
        $opportunityStmt = db()->query(
            "SELECT title, category, description, risk_level, investment_period, liquidity, minimum_amount,
                    risks, annual_return_assumption, source_url, verified_at
             FROM investment_opportunities
             WHERE is_active = 1 AND verification_status = 'verified'
               AND source_url IS NOT NULL AND verified_at IS NOT NULL
             ORDER BY category, title
             LIMIT 5"
        );
        $verifiedOpportunities = array_map(
            static fn (array $opportunity): array => [
                'title' => (string) $opportunity['title'],
                'category' => (string) $opportunity['category'],
                'description' => (string) $opportunity['description'],
                'risk_level' => (string) $opportunity['risk_level'],
                'investment_period' => $opportunity['investment_period'],
                'liquidity' => $opportunity['liquidity'],
                'minimum_kes' => $opportunity['minimum_amount'] !== null ? (float) $opportunity['minimum_amount'] : null,
                'risks_and_considerations' => $opportunity['risks'],
                'annual_return_assumption_percent' => $opportunity['annual_return_assumption'] !== null
                    ? (float) $opportunity['annual_return_assumption']
                    : null,
                'source_url' => (string) $opportunity['source_url'],
                'verified_at' => (string) $opportunity['verified_at'],
            ],
            $opportunityStmt->fetchAll()
        );
    }

    $context = [
        'member' => [
            'name' => (string) $member['name'],
            'status' => (string) $member['status'],
            'joined_date' => (string) $member['joined_date'],
        ],
    ];
    if ($includeContributions) {
        $context['contributions'] = $contributions + ['history' => $contributionHistory];
    }
    if ($includeLoans) {
        $context['loans'] = [
            'total_count' => (int) $loanSummary['total_count'],
            'outstanding_balance' => round((float) $loanSummary['outstanding_balance'], 2),
            'history' => $loans,
        ];
        $context['repayments'] = [
            'total_count' => (int) $repaymentSummary['total_count'],
            'total_amount' => round((float) $repaymentSummary['total_amount'], 2),
            'history' => $repayments,
        ];
    }
    if ($includePenalties) {
        $context['penalties'] = [
            'outstanding_total' => round((float) $penaltySummary['outstanding_total'], 2),
            'history' => $penalties,
        ];
    }

    if ($eligibility !== null) {
        $context['loan_eligibility'] = [
            'eligible_for_new_application' => $eligibility['eligible'],
            'mandatory_reasons' => $eligibility['reasons'],
            'warnings' => $eligibility['warnings'],
            'eligible_savings_kes' => $eligibility['eligible_savings'],
            'maximum_loan_kes' => $eligibility['maximum_amount'],
            'membership_months' => $eligibility['membership_months'],
            'consecutive_contribution_months' => $eligibility['consecutive_contribution_months'],
            'recorded_contribution_count' => $eligibility['contribution_count'],
            'settings' => [
                'minimum_membership_months' => (int) financial_setting('loan_min_membership_months'),
                'minimum_contribution_months' => (int) financial_setting('loan_min_contribution_months'),
                'minimum_contributions' => (int) financial_setting('loan_min_contributions'),
                'loan_to_savings_multiplier' => (float) financial_setting('loan_to_savings_multiplier'),
                'maximum_loan_kes' => (float) financial_setting('loan_max_amount'),
                'unpaid_penalties_block_borrowing' => financial_setting('loan_penalties_block_borrowing') === '1',
            ],
        ];
    }

    if ($includeInvestments) {
        $context['investment_plans'] = [
            'type' => 'simulated_planning_only',
            'real_money_transactions_supported' => false,
            'projection_method' => 'Monthly compounding at the equivalent monthly rate derived from the annual assumption; monthly contributions are treated as end-of-month deposits.',
            'fees_and_taxes_included' => false,
            'plans' => $investmentPlans,
            'verified_active_opportunities' => $verifiedOpportunities,
        ];
    }

    return $context;
}
