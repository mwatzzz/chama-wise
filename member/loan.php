<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();
$loanId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT loan_id, requested_amount, approved_amount, interest_rate, outstanding_balance, purpose, notes, status, application_date, approval_date
     FROM loans WHERE loan_id = :loan_id AND member_id = :member_id LIMIT 1'
);
$stmt->execute(['loan_id' => $loanId, 'member_id' => $user['user_id']]);
$loan = $stmt->fetch();
if (!$loan) {
    flash_set('error', 'Loan application not found.');
    redirect('member/loans.php');
}

$pageTitle = 'Loan Details | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar"><nav aria-label="Member navigation">
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a><a class="member-nav-link" href="<?php echo e(url('member/contributions.php')); ?>"><span>&#9776;</span> Contributions</a><a class="member-nav-link active" href="<?php echo e(url('member/loans.php')); ?>"><span>&#9633;</span> Loans</a><a class="member-nav-link" href="<?php echo e(url('member/repayments.php')); ?>"><span>&#8646;</span> Loan Repayment</a><a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span>&#9888;</span> Penalties</a><a class="member-nav-link" href="#"><span>&#9635;</span> Meetings</a><a class="member-nav-link" href="#"><span>&#10022;</span> AI Advisor</a><a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Loans <span>&#8250;</span> Details</div>
        <div class="member-heading"><div><h1>Loan Details</h1><p>Review your application and current loan status.</p></div><a class="btn btn-outline-brand" href="<?php echo e(url('member/loans.php')); ?>">&#8592; My Loans</a></div>
        <article class="profile-panel loan-detail-panel"><div class="admin-panel-title"><h2>Application #<?php echo e((string) $loan['loan_id']); ?></h2><span class="status-badge"><?php echo e(ucfirst($loan['status'])); ?></span></div><div class="loan-detail-grid"><div><small>Requested Amount</small><strong>KES <?php echo e(number_format((float) $loan['requested_amount'], 2)); ?></strong></div><div><small>Approved Amount</small><strong><?php echo $loan['approved_amount'] !== null ? 'KES ' . e(number_format((float) $loan['approved_amount'], 2)) : 'Pending'; ?></strong></div><div><small>Interest Rate</small><strong><?php echo $loan['interest_rate'] !== null ? e((string) $loan['interest_rate']) . '%' : 'Pending'; ?></strong></div><div><small>Outstanding Balance</small><strong><?php echo $loan['outstanding_balance'] !== null ? 'KES ' . e(number_format((float) $loan['outstanding_balance'], 2)) : 'Pending'; ?></strong></div><div><small>Application Date</small><strong><?php echo e($loan['application_date']); ?></strong></div><div><small>Approval Date</small><strong><?php echo e($loan['approval_date'] ?? 'Pending'); ?></strong></div></div><p class="text-muted mb-0"><strong>Purpose:</strong> <?php echo e($loan['purpose']); ?></p></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
