<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();
$loanId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT l.loan_id, l.requested_amount, l.approved_amount, l.interest_rate, l.interest_method, l.outstanding_balance, l.due_date,
            l.purpose, l.notes, l.status, l.rejection_reason, l.application_date, l.approval_date,
            COALESCE(p.amount, 0) AS overdue_penalty, p.status AS penalty_status, p.reason AS penalty_reason
     FROM loans l
     LEFT JOIN penalties p ON p.loan_id = l.loan_id
     WHERE l.loan_id = :loan_id AND l.member_id = :member_id
     LIMIT 1'
);
$stmt->execute(['loan_id' => $loanId, 'member_id' => $user['user_id']]);
$loan = $stmt->fetch();
if (!$loan) {
    flash_set('error', 'Loan application not found.');
    redirect('member/loans.php');
}

$monthlyInstallment = null;
if ($loan['approved_amount'] !== null && $loan['interest_rate'] !== null && $loan['due_date'] && $loan['approval_date']) {
    $approvalDate = new DateTimeImmutable((string) $loan['approval_date']);
    $dueDate = new DateTimeImmutable((string) $loan['due_date']);
    $termMonths = (($dueDate->format('Y') - $approvalDate->format('Y')) * 12)
        + (int) $dueDate->format('n')
        - (int) $approvalDate->format('n');
    if ((int) $dueDate->format('j') < (int) $approvalDate->format('j')) {
        $termMonths--;
    }
    $monthlyInstallment = calculate_monthly_loan_installment(
        (float) $loan['approved_amount'],
        (float) $loan['interest_rate'],
        (string) ($loan['interest_method'] ?? 'flat'),
        max(1, $termMonths)
    );
}

$pageTitle = 'Loan Details | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar"><nav aria-label="Member navigation">
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a><a class="member-nav-link" href="<?php echo e(url('member/contributions.php')); ?>"><span>&#9776;</span> Contributions</a><a class="member-nav-link active" href="<?php echo e(url('member/loans.php')); ?>"><span>&#9633;</span> Loans</a><a class="member-nav-link" href="<?php echo e(url('member/repayments.php')); ?>"><span>&#8646;</span> Loan Repayment</a><a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span>&#9888;</span> Penalties</a><a class="member-nav-link" href="<?php echo e(url('member/meetings.php')); ?>"><span>&#9635;</span> Meetings</a><a class="member-nav-link" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span>&#10022;</span> AI Advisor</a><a class="member-nav-link" href="<?php echo e(url('member/profile.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Loans <span>&#8250;</span> Details</div>
        <div class="member-heading"><div><h1>Loan Details</h1><p>Review your application and current loan status.</p></div><a class="btn btn-outline-brand" href="<?php echo e(url('member/loans.php')); ?>">&#8592; My Loans</a></div>
        <article class="profile-panel loan-detail-panel"><div class="admin-panel-title"><h2>Application #<?php echo e((string) $loan['loan_id']); ?></h2><span class="status-badge"><?php echo e(ucwords(str_replace('_', ' ', $loan['status']))); ?></span></div><div class="loan-detail-grid"><div><small>Requested Amount</small><strong>KES <?php echo e(number_format((float) $loan['requested_amount'], 2)); ?></strong></div><div><small>Approved Amount</small><strong><?php echo $loan['approved_amount'] !== null ? 'KES ' . e(number_format((float) $loan['approved_amount'], 2)) : 'Pending'; ?></strong></div><div><small>Interest Rate</small><strong><?php echo $loan['interest_rate'] !== null ? e((string) $loan['interest_rate']) . '% ' . (($loan['interest_method'] ?? 'flat') === 'flat' ? 'per loan' : 'per month') : 'Pending'; ?></strong></div><?php if ($monthlyInstallment !== null): ?><div><small>Estimated Monthly Installment</small><strong>KES <?php echo e(number_format($monthlyInstallment, 2)); ?></strong></div><?php endif; ?><div><small>Outstanding Balance</small><strong><?php echo $loan['outstanding_balance'] !== null ? 'KES ' . e(number_format((float) $loan['outstanding_balance'], 2)) : 'Pending'; ?></strong></div><div><small>Due Date</small><strong><?php echo e($loan['due_date'] ?? 'Not set'); ?></strong></div><div><small>Overdue Penalty</small><strong><?php echo (float) $loan['overdue_penalty'] > 0 ? 'KES ' . e(number_format((float) $loan['overdue_penalty'], 2)) : 'None'; ?></strong></div><div><small>Application Date</small><strong><?php echo e($loan['application_date']); ?></strong></div><div><small>Approval Date</small><strong><?php echo e($loan['approval_date'] ?? 'Pending'); ?></strong></div></div><p class="text-muted mb-0"><strong>Purpose:</strong> <?php echo e($loan['purpose']); ?></p><?php if ((float) $loan['overdue_penalty'] > 0 && !empty($loan['penalty_reason'])): ?><p class="text-danger mb-0 mt-3"><strong>Penalty:</strong> <?php echo e($loan['penalty_reason']); ?></p><?php endif; ?><?php if (!empty($loan['rejection_reason'])): ?><p class="text-danger mb-0 mt-3"><strong>Reason:</strong> <?php echo e($loan['rejection_reason']); ?></p><?php endif; ?></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
