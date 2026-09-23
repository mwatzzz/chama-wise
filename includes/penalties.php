<?php

declare(strict_types=1);

function sync_contribution_penalty(int $contributionId, ?int $adminId = null): ?array
{
    $stmt = db()->prepare('SELECT contribution_id, member_id, contribution_date FROM contributions WHERE contribution_id = :id LIMIT 1');
    $stmt->execute(['id' => $contributionId]);
    $contribution = $stmt->fetch();
    if (!$contribution) {
        return null;
    }

    $dueDay = max(1, min(28, (int) env('CONTRIBUTION_DUE_DAY', '5')));
    $penaltyAmount = max(0, (float) env('LATE_CONTRIBUTION_PENALTY', '100'));
    $date = new DateTimeImmutable($contribution['contribution_date']);
    $isLate = (int) $date->format('j') > $dueDay && $penaltyAmount > 0;

    $existingStmt = db()->prepare('SELECT penalty_id FROM penalties WHERE contribution_id = :contribution_id LIMIT 1');
    $existingStmt->execute(['contribution_id' => $contributionId]);
    $existing = $existingStmt->fetch();

    if (!$isLate) {
        if ($existing) {
            $delete = db()->prepare('DELETE FROM penalties WHERE penalty_id = :penalty_id AND status = \'outstanding\'');
            $delete->execute(['penalty_id' => $existing['penalty_id']]);
        }
        return null;
    }

    $reason = 'Contribution received after day ' . $dueDay . ' of the month.';
    if ($existing) {
        $update = db()->prepare(
            'UPDATE penalties
             SET member_id = :member_id, amount = :amount, reason = :reason, penalty_date = :penalty_date,
                 status = \'outstanding\', created_by = :created_by
             WHERE penalty_id = :penalty_id'
        );
        $update->execute([
            'member_id' => $contribution['member_id'],
            'amount' => $penaltyAmount,
            'reason' => $reason,
            'penalty_date' => $contribution['contribution_date'],
            'created_by' => $adminId,
            'penalty_id' => $existing['penalty_id'],
        ]);
        return ['penalty_id' => (int) $existing['penalty_id'], 'amount' => $penaltyAmount];
    }

    $insert = db()->prepare(
        'INSERT INTO penalties (member_id, contribution_id, amount, reason, penalty_date, created_by)
         VALUES (:member_id, :contribution_id, :amount, :reason, :penalty_date, :created_by)'
    );
    $insert->execute([
        'member_id' => $contribution['member_id'],
        'contribution_id' => $contributionId,
        'amount' => $penaltyAmount,
        'reason' => $reason,
        'penalty_date' => $contribution['contribution_date'],
        'created_by' => $adminId,
    ]);

    return ['penalty_id' => (int) db()->lastInsertId(), 'amount' => $penaltyAmount];
}
