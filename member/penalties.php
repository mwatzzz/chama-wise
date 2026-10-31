<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();
$stmt = db()->prepare(
    'SELECT p.amount, p.reason, p.penalty_date, p.status, c.contribution_date
     FROM penalties p
     LEFT JOIN contributions c ON c.contribution_id = p.contribution_id
     WHERE p.member_id = :member_id
     ORDER BY p.penalty_date DESC, p.penalty_id DESC'
);
$stmt->execute(['member_id' => $user['user_id']]);
$penalties = $stmt->fetchAll();
$outstanding = array_sum(array_map(static fn (array $penalty): float => $penalty['status'] === 'outstanding' ? (float) $penalty['amount'] : 0.0, $penalties));

$pageTitle = 'Penalties | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar"><nav aria-label="Member navigation">
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="member-nav-link" href="<?php echo e(url('member/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="member-nav-link" href="<?php echo e(url('member/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="member-nav-link" href="<?php echo e(url('member/repayments.php')); ?>"><span>&#8646;</span> Loan Repayment</a>
        <a class="member-nav-link active" href="<?php echo e(url('member/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="member-nav-link" href="<?php echo e(url('member/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
        <a class="member-nav-link" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span>&#10022;</span> AI Advisor</a>
        <a class="member-nav-link" href="<?php echo e(url('member/profile.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Penalties</div>
        <div class="member-heading"><div><h1>My Penalties</h1><p>Review penalties associated with your contributions.</p></div><span class="pill">Outstanding: KES <?php echo e(number_format($outstanding, 2)); ?></span></div>
        <article class="profile-panel table-panel"><table><thead><tr><th>Date</th><th>Amount</th><th>Reason</th><th>Status</th></tr></thead><tbody><?php if ($penalties): ?><?php foreach ($penalties as $penalty): ?><tr><td><?php echo e($penalty['penalty_date']); ?></td><td>KES <?php echo e(number_format((float) $penalty['amount'], 2)); ?></td><td><?php echo e($penalty['reason']); ?></td><td><span class="table-status"><?php echo e(ucfirst($penalty['status'])); ?></span></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="4">No penalties have been recorded for your account.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
