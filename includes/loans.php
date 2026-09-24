<?php

declare(strict_types=1);

function record_loan_repayment(
    int $loanId,
    float $amount,
    string $paymentDate,
    ?int $paymentMethodId,
    ?string $reference,
    int $adminId
): void {
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $loanStmt = $pdo->prepare(
            "SELECT loan_id, outstanding_balance, status
             FROM loans
             WHERE loan_id = :loan_id
             FOR UPDATE"
        );
        $loanStmt->execute(['loan_id' => $loanId]);
        $loan = $loanStmt->fetch();

        if (!$loan || !in_array($loan['status'], ['approved', 'overdue'], true)) {
            throw new InvalidArgumentException('Only approved loans can receive repayments.');
        }

        $balance = (float) ($loan['outstanding_balance'] ?? 0);
        if ($amount <= 0 || $amount > $balance) {
            throw new InvalidArgumentException('Repayment must be greater than zero and no more than the outstanding balance.');
        }

        $newBalance = round($balance - $amount, 2);
        $repaymentStmt = $pdo->prepare(
            'INSERT INTO loan_repayments (loan_id, amount, payment_date, payment_method_id, reference, recorded_by)
             VALUES (:loan_id, :amount, :payment_date, :payment_method_id, :reference, :recorded_by)'
        );
        $repaymentStmt->execute([
            'loan_id' => $loanId,
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method_id' => $paymentMethodId,
            'reference' => $reference !== '' ? $reference : null,
            'recorded_by' => $adminId,
        ]);

        $status = $newBalance <= 0 ? 'completed' : 'approved';
        $updateStmt = $pdo->prepare('UPDATE loans SET outstanding_balance = :balance, status = :status WHERE loan_id = :loan_id');
        $updateStmt->execute(['balance' => $newBalance, 'status' => $status, 'loan_id' => $loanId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
