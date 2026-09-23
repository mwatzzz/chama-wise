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

    if ($loanId < 1 || !in_array($action, ['approve', 'reject'], true)) {
        flash_set('error', 'Invalid loan decision.');
        redirect('admin/loans.php');
    }

    if ($action === 'approve' && ($approvedAmount <= 0 || $interestRate < 0)) {
        flash_set('error', 'Enter a valid approved amount and interest rate.');
        redirect('admin/loans.php');
    }

    if ($action === 'approve') {
        $stmt = db()->prepare(
            "UPDATE loans
             SET status = 'approved', approved_amount = :approved_amount, interest_rate = :interest_rate,
                 outstanding_balance = :approved_amount, approval_date = :approval_date, approved_by = :approved_by
             WHERE loan_id = :loan_id AND status = 'pending'"
        );
        $stmt->execute([
            'approved_amount' => $approvedAmount,
            'interest_rate' => $interestRate,
            'approval_date' => date('Y-m-d'),
            'approved_by' => current_user()['user_id'],
            'loan_id' => $loanId,
        ]);
        flash_set('success', 'Loan approved successfully.');
    } else {
        $stmt = db()->prepare("UPDATE loans SET status = 'rejected', approval_date = :approval_date, approved_by = :approved_by WHERE loan_id = :loan_id AND status = 'pending'");
        $stmt->execute([
            'approval_date' => date('Y-m-d'),
            'approved_by' => current_user()['user_id'],
            'loan_id' => $loanId,
        ]);
        flash_set('success', 'Loan rejected successfully.');
    }
    redirect('admin/loans.php');
}

$loans = db()->query(
    'SELECT l.loan_id, l.requested_amount, l.approved_amount, l.interest_rate, l.outstanding_balance,
            l.purpose, l.status, l.application_date, l.approval_date, m.name, m.email, m.phone
     FROM loans l INNER JOIN members m ON m.member_id = l.member_id
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
        <a class="admin-nav-link" href="<?php echo e(url('admin/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="admin-nav-link" href="#"><span>&#9635;</span> Meetings</a><a class="admin-nav-link" href="#"><span>&#9646;</span> Reports</a>
    </nav><a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Loan Management</p><h1>Loans</h1><p>Review, approve and reject member loan applications.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <article class="admin-panel table-panel"><table><thead><tr><th>Member</th><th>Requested</th><th>Approved</th><th>Purpose</th><th>Application Date</th><th>Status</th><th>Decision</th></tr></thead><tbody><?php if ($loans): ?><?php foreach ($loans as $loan): ?><tr><td><strong><?php echo e($loan['name']); ?></strong><small class="d-block text-muted"><?php echo e($loan['email']); ?></small></td><td>KES <?php echo e(number_format((float) $loan['requested_amount'], 2)); ?></td><td><?php echo $loan['approved_amount'] === null ? '-' : 'KES ' . e(number_format((float) $loan['approved_amount'], 2)); ?></td><td><?php echo e($loan['purpose']); ?></td><td><?php echo e($loan['application_date']); ?></td><td><?php echo e(ucfirst($loan['status'])); ?></td><td><?php if ($loan['status'] === 'pending'): ?><form method="post" class="loan-decision-form"><input type="hidden" name="loan_id" value="<?php echo e((string) $loan['loan_id']); ?>"><?php echo csrf_field(); ?><input type="number" name="approved_amount" min="1" step="0.01" placeholder="Amount"><input type="number" name="interest_rate" min="0" step="0.01" placeholder="Rate %"><button name="action" value="approve" type="submit">Approve</button><button class="danger-action" name="action" value="reject" type="submit">Reject</button></form><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="7">No loan applications have been submitted.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
