<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();
ensure_contributions_table();

$user = current_user();
$stmt = db()->prepare(
    'SELECT c.amount, c.contribution_date, c.status, c.reference, c.notes, pm.name AS payment_method
    FROM contributions c
     LEFT JOIN payment_methods pm ON pm.payment_method_id = c.payment_method_id
     WHERE c.member_id = :member_id
     ORDER BY c.contribution_date DESC, c.contribution_id DESC'
);
$stmt->execute(['member_id' => $user['user_id']]);
$contributions = $stmt->fetchAll();
$total = array_sum(array_map(static fn (array $contribution): float => (float) $contribution['amount'], $contributions));

$pageTitle = 'Contributions | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="member-layout">
    <aside class="member-sidebar">
        <nav aria-label="Member navigation">
            <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
            <a class="member-nav-link active" href="<?php echo e(url('member/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
            <a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
            <a class="member-nav-link" href="<?php echo e(url('member/loans.php')); ?>"><span>&#9633;</span> Loans</a>
            <a class="member-nav-link" href="<?php echo e(url('member/repayments.php')); ?>"><span>&#8646;</span> Loan Repayment</a>
            <a class="member-nav-link" href="<?php echo e(url('member/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
            <a class="member-nav-link" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span>&#10022;</span> AI Advisor</a>
            <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#9679;</span> Profile</a>
        </nav>
        <a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a>
    </aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Contributions</div>
        <div class="member-heading"><div><h1>My Contributions</h1><p>Track your chama contribution history.</p></div><a class="btn btn-outline-brand" href="<?php echo e(url('member/dashboard.php')); ?>">&#8592; Profile</a></div>
        <div class="admin-stat-grid contribution-stat-grid"><article class="admin-stat-card"><span class="admin-stat-icon">&#9776;</span><div><span>Total Contributions</span><strong><?php echo count($contributions); ?></strong></div></article><article class="admin-stat-card"><span class="admin-stat-icon">KES</span><div><span>Total Amount</span><strong>KES <?php echo e(number_format($total, 2)); ?></strong></div></article></div>
        <article class="profile-panel table-panel"><table><thead><tr><th>Date</th><th>Amount</th><th>Payment Method</th><th>Reference</th><th>Notes</th><th>Status</th></tr></thead><tbody><?php if ($contributions): ?><?php foreach ($contributions as $contribution): ?><tr><td><?php echo e($contribution['contribution_date']); ?></td><td>KES <?php echo e(number_format((float) $contribution['amount'], 2)); ?></td><td><?php echo e($contribution['payment_method'] ?? 'Not specified'); ?></td><td><?php echo e($contribution['reference'] ?? '-'); ?></td><td><?php echo e($contribution['notes'] ?? '-'); ?></td><td><span class="table-status"><?php echo e(ucfirst($contribution['status'])); ?></span></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="6">No contributions have been recorded for your account yet.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
