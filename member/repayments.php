<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();
$stmt = db()->prepare(
    'SELECT r.amount, r.payment_date, r.reference, r.loan_id, pm.name AS payment_method,
            l.requested_amount, l.approved_amount, l.outstanding_balance, l.status
     FROM loan_repayments r
     INNER JOIN loans l ON l.loan_id = r.loan_id AND l.member_id = :member_id
     LEFT JOIN payment_methods pm ON pm.payment_method_id = r.payment_method_id
     ORDER BY r.payment_date DESC, r.repayment_id DESC'
);
$stmt->execute(['member_id' => $user['user_id']]);
$repayments = $stmt->fetchAll();
$total = array_sum(array_map(static fn (array $repayment): float => (float) $repayment['amount'], $repayments));

$pageTitle = 'Repayments | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar"><nav aria-label="Member navigation">
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="member-nav-link" href="<?php echo e(url('member/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="member-nav-link" href="<?php echo e(url('member/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="member-nav-link active" href="<?php echo e(url('member/repayments.php')); ?>"><span>&#8646;</span> Loan Repayment</a>
        <a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="member-nav-link" href="#"><span>&#9635;</span> Meetings</a>
        <a class="member-nav-link" href="#"><span>&#10022;</span> AI Advisor</a>
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Loan Repayment</div>
        <div class="member-heading"><div><h1>Loan Repayments</h1><p>Review repayments recorded against your loans.</p></div><span class="pill">Total paid: KES <?php echo e(number_format($total, 2)); ?></span></div>
        <article class="profile-panel table-panel"><table><thead><tr><th>Loan</th><th>Repayment Amount</th><th>Payment Date</th><th>Payment Method</th><th>Reference</th><th>Remaining Balance</th></tr></thead><tbody><?php if ($repayments): ?><?php foreach ($repayments as $repayment): ?><tr><td>#<?php echo e((string) $repayment['loan_id']); ?></td><td>KES <?php echo e(number_format((float) $repayment['amount'], 2)); ?></td><td><?php echo e($repayment['payment_date']); ?></td><td><?php echo e($repayment['payment_method'] ?? 'Not specified'); ?></td><td><?php echo e($repayment['reference'] ?? '-'); ?></td><td><?php echo $repayment['outstanding_balance'] === null ? 'Pending' : 'KES ' . e(number_format((float) $repayment['outstanding_balance'], 2)); ?></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="6">No repayments have been recorded for your loans.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
