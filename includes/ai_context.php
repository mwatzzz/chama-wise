<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function build_member_financial_context(int $memberId): array
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

    $contributionStmt = db()->prepare(
        'SELECT amount, contribution_date, status, notes
         FROM contributions
         WHERE member_id = :member_id
         ORDER BY contribution_date DESC, contribution_id DESC'
    );
    $contributionStmt->execute(['member_id' => $memberId]);
    $contributions = $contributionStmt->fetchAll();

    $loanStmt = db()->prepare(
        'SELECT loan_id, requested_amount, approved_amount, interest_rate, outstanding_balance,
                purpose, status, application_date, approval_date
         FROM loans
         WHERE member_id = :member_id
         ORDER BY loan_id DESC'
    );
    $loanStmt->execute(['member_id' => $memberId]);
    $loans = $loanStmt->fetchAll();

    $repaymentStmt = db()->prepare(
        'SELECT r.loan_id, r.amount, r.payment_date, r.reference
         FROM loan_repayments r
         INNER JOIN loans l ON l.loan_id = r.loan_id AND l.member_id = :member_id
         ORDER BY r.payment_date DESC, r.repayment_id DESC'
    );
    $repaymentStmt->execute(['member_id' => $memberId]);
    $repayments = $repaymentStmt->fetchAll();

    $penaltyStmt = db()->prepare(
        'SELECT amount, reason, penalty_date, status
         FROM penalties
         WHERE member_id = :member_id
         ORDER BY penalty_date DESC, penalty_id DESC'
    );
    $penaltyStmt->execute(['member_id' => $memberId]);
    $penalties = $penaltyStmt->fetchAll();

    return [
        'member' => [
            'id' => (int) $member['member_id'],
            'name' => (string) $member['name'],
            'status' => (string) $member['status'],
            'joined_date' => (string) $member['joined_date'],
        ],
        'contributions' => [
            'total_count' => count($contributions),
            'total_amount' => round(array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $contributions)), 2),
            'history' => $contributions,
        ],
        'loans' => [
            'total_count' => count($loans),
            'outstanding_balance' => round(array_sum(array_map(static fn (array $row): float => (float) ($row['outstanding_balance'] ?? 0), $loans)), 2),
            'history' => $loans,
        ],
        'repayments' => [
            'total_count' => count($repayments),
            'total_amount' => round(array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $repayments)), 2),
            'history' => $repayments,
        ],
        'penalties' => [
            'outstanding_total' => round(array_sum(array_map(static fn (array $row): float => $row['status'] === 'outstanding' ? (float) $row['amount'] : 0.0, $penalties)), 2),
            'history' => $penalties,
        ],
    ];
}
