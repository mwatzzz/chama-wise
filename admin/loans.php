<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('admin/loans.php');
    }

    $loanId = (int) ($_POST['loan_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $approvedAmount = (float) ($_POST['approved_amount'] ?? 0);
    $interestRate = (float) ($_POST['interest_rate'] ?? 0);
    $dueDate = trim((string) ($_POST['due_date'] ?? ''));
    $dueDateValue = DateTime::createFromFormat('!Y-m-d', $dueDate);

    if ($loanId < 1 || !in_array($action, ['approve', 'reject', 'set_due_date'], true)) {
        flash_set('error', 'Invalid loan decision.');
        redirect('admin/loans.php');
    }

    $loanStmt = db()->prepare('SELECT status, requested_amount, first_approved_by, approved_amount, interest_rate, due_date, approval_date, second_approved_by FROM loans WHERE loan_id = :loan_id LIMIT 1');
    $loanStmt->execute(['loan_id' => $loanId]);
    $loan = $loanStmt->fetch();
    $adminId = (int) current_user()['user_id'];
    if (!$loan) {
        flash_set('error', 'Loan application was not found.');
        redirect('admin/loans.php');
    }

    if ($action === 'set_due_date') {
        $approvalDate = new DateTimeImmutable($loan['approval_date'] ?: 'today');
        $latestDueDate = maximum_loan_due_date($approvalDate, (int) financial_setting('loan_max_term_months'));
        if (!in_array($loan['status'], ['approved', 'overdue'], true) || !$dueDateValue ||
            $dueDateValue->format('Y-m-d') !== $dueDate || $dueDate < $approvalDate->format('Y-m-d') ||
            $dueDate > $latestDueDate->format('Y-m-d')) {
            flash_set('error', 'Enter a valid due date within the configured maximum repayment term.');
            redirect('admin/loans.php');
        }
        $stmt = db()->prepare('UPDATE loans SET due_date = :due_date WHERE loan_id = :loan_id');
        $stmt->execute(['due_date' => $dueDate, 'loan_id' => $loanId]);
        flash_set('success', 'Loan due date saved. Any outstanding overdue balance will be penalized automatically.');
    } elseif ($action === 'reject') {
        if (!in_array($loan['status'], ['eligible', 'pending_second_approval'], true)) {
            flash_set('error', 'Only eligible loans awaiting approval can be rejected.');
            redirect('admin/loans.php');
        }
        $stmt = db()->prepare("UPDATE loans SET status = 'rejected', rejection_reason = :rejection_reason, approval_date = :approval_date, approved_by = :approved_by WHERE loan_id = :loan_id AND status IN ('eligible', 'pending_second_approval')");
        $stmt->execute(['rejection_reason' => 'Rejected by admin during approval review.', 'approval_date' => date('Y-m-d'), 'approved_by' => $adminId, 'loan_id' => $loanId]);
        flash_set('success', 'Loan rejected successfully.');
    } elseif ($loan['status'] === 'eligible') {
        $latestDueDate = maximum_loan_due_date(new DateTimeImmutable('today'), (int) financial_setting('loan_max_term_months'));
        if ($approvedAmount <= 0 || $interestRate < 0 || $interestRate > 100 ||
            $approvedAmount > (float) $loan['requested_amount'] || !$dueDateValue ||
            $dueDateValue->format('Y-m-d') !== $dueDate || $dueDate < date('Y-m-d') ||
            $dueDate > $latestDueDate->format('Y-m-d')) {
            flash_set('error', 'Enter a valid approved amount, rate per loan and due date within the configured term.');
            redirect('admin/loans.php');
        }
        $stmt = db()->prepare("UPDATE loans SET status = 'pending_second_approval', approved_amount = :approved_amount, interest_rate = :interest_rate, interest_method = :interest_method, due_date = :due_date, first_approved_by = :first_approved_by, first_approval_date = :first_approval_date WHERE loan_id = :loan_id AND status = 'eligible'");
        $stmt->execute([
            'approved_amount' => $approvedAmount,
            'interest_rate' => $interestRate,
            'interest_method' => financial_setting('loan_interest_method'),
            'due_date' => $dueDate,
            'first_approved_by' => $adminId,
            'first_approval_date' => date('Y-m-d'),
            'loan_id' => $loanId,
        ]);
        flash_set('success', 'First approval recorded. This loan now requires a second, different admin to give final approval.');
    } elseif ($loan['status'] === 'pending_second_approval') {
        if (strtolower((string) current_user()['email']) !== 'admin@goat.com') {
            flash_set('error', 'Only admin@goat.com can provide the final loan approval.');
            redirect('admin/loans.php');
        }
        if ((int) $loan['first_approved_by'] === $adminId || !empty($loan['second_approved_by'])) {
            flash_set('error', 'Final approval can only be given by a different admin, and only once.');
            redirect('admin/loans.php');
        }
        if ((int) $loan['first_approved_by'] === 0 || $loan['first_approved_by'] === null) {
            flash_set('error', 'This loan does not have a valid first approval yet.');
            redirect('admin/loans.php');
        }
        $stmt = db()->prepare("UPDATE loans SET status = 'approved', outstanding_balance = CASE WHEN interest_method = 'flat' THEN ROUND(approved_amount * (1 + interest_rate / 100), 2) ELSE approved_amount END, approval_date = :approval_date, interest_accrued_until = :interest_accrued_until, approved_by = :approved_by, second_approved_by = :second_approved_by, second_approval_date = :second_approval_date WHERE loan_id = :loan_id AND status = 'pending_second_approval'");
        $stmt->execute([
            'approval_date' => date('Y-m-d'),
            'interest_accrued_until' => date('Y-m-d'),
            'approved_by' => $adminId,
            'second_approved_by' => $adminId,
            'second_approval_date' => date('Y-m-d'),
            'loan_id' => $loanId,
        ]);
        flash_set('success', 'Loan received final approval from a second admin successfully.');
    } else {
        flash_set('error', 'This loan is not awaiting approval.');
    }
    redirect('admin/loans.php');
}

$loans = db()->query(
        'SELECT l.loan_id, l.requested_amount, l.approved_amount, l.interest_rate, l.outstanding_balance, l.due_date,
            l.purpose, l.status, l.rejection_reason, l.application_date, l.approval_date,
            l.first_approved_by, l.second_approved_by, a1.name AS first_admin_name, a2.name AS second_admin_name,
            m.name, m.email, m.phone
     FROM loans l
     INNER JOIN members m ON m.member_id = l.member_id
     LEFT JOIN admins a1 ON a1.admin_id = l.first_approved_by
     LEFT JOIN admins a2 ON a2.admin_id = l.second_approved_by
     ORDER BY l.loan_id DESC'
)->fetchAll();

$pageTitle = 'Loans | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="admin-layout">
    <aside class="admin-sidebar"><nav aria-label="Admin navigation">
        <a class="admin-nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/members.php')); ?>"><span>&#128101;</span> Members</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="admin-nav-link active" href="<?php echo e(url('admin/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/repayments.php')); ?>"><span>&#8646;</span> Repayments</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/meetings.php')); ?>"><span>&#9635;</span> Meetings</a><a class="admin-nav-link" href="<?php echo e(url('admin/reports.php')); ?>"><span>&#9646;</span> Reports</a>
    </nav><a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Loan Management</p><h1>Loans</h1><p>Review, approve and reject member loan applications.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <article class="admin-panel table-panel"><table><thead><tr><th>Member</th><th>Requested</th><th>Approved</th><th>Purpose</th><th>Application Date</th><th>Due Date</th><th>Status</th><th>Decision</th></tr></thead><tbody><?php if ($loans): ?><?php foreach ($loans as $loan): ?><tr><td><strong><?php echo e($loan['name']); ?></strong><small class="d-block text-muted"><?php echo e($loan['email']); ?></small></td><td>KES <?php echo e(number_format((float) $loan['requested_amount'], 2)); ?></td><td><?php echo $loan['approved_amount'] === null ? '-' : 'KES ' . e(number_format((float) $loan['approved_amount'], 2)); ?></td><td><?php echo e($loan['purpose']); ?><?php if ($loan['rejection_reason']): ?><small class="d-block text-danger"><?php echo e($loan['rejection_reason']); ?></small><?php endif; ?></td><td><?php echo e($loan['application_date']); ?></td><td><?php echo e($loan['due_date'] ?? 'Not set'); ?></td><td><?php echo e(ucwords(str_replace('_', ' ', $loan['status']))); ?></td><td><?php if (in_array($loan['status'], ['eligible', 'pending_second_approval'], true)): ?><form method="post" class="loan-decision-form"><input type="hidden" name="loan_id" value="<?php echo e((string) $loan['loan_id']); ?>"><?php echo csrf_field(); ?><?php if ($loan['status'] === 'eligible'): ?><input type="number" name="approved_amount" min="1" max="<?php echo e((string) $loan['requested_amount']); ?>" step="0.01" placeholder="Amount" required><label>Interest rate (<?php echo financial_setting('loan_interest_method') === 'flat' ? 'per loan' : 'per month'; ?>)<input type="number" name="interest_rate" min="0" max="100" step="0.01" value="<?php echo e(financial_setting('loan_interest_rate')); ?>" required></label><small><?php echo financial_setting('loan_interest_method') === 'flat' ? 'Flat interest calculated on principal for the loan.' : 'Reducing-balance interest accrues monthly on the outstanding balance.'; ?></small><label>Due date<input type="date" name="due_date" min="<?php echo e(date('Y-m-d')); ?>" max="<?php echo e(maximum_loan_due_date(new DateTimeImmutable('today'), (int) financial_setting('loan_max_term_months'))->format('Y-m-d')); ?>" required></label><?php endif; ?><button name="action" value="approve" type="submit"><?php echo $loan['status'] === 'eligible' ? 'First Approve' : 'Final Approve'; ?></button><button class="danger-action" name="action" value="reject" type="submit">Reject</button></form><?php elseif (in_array($loan['status'], ['approved', 'overdue'], true) && empty($loan['due_date'])): ?><form method="post" class="loan-decision-form"><input type="hidden" name="loan_id" value="<?php echo e((string) $loan['loan_id']); ?>"><?php echo csrf_field(); ?><label>Set due date<input type="date" name="due_date" min="<?php echo e(date('Y-m-d')); ?>" max="<?php echo e(maximum_loan_due_date(new DateTimeImmutable($loan['approval_date'] ?: 'today'), (int) financial_setting('loan_max_term_months'))->format('Y-m-d')); ?>" required></label><button name="action" value="set_due_date" type="submit">Save Due Date</button></form><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="8">No loan applications have been submitted.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
