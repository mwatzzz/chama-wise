<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();
$memberId = (int) $user['user_id'];

$contributionStmt = db()->prepare(
    "SELECT COALESCE(SUM(amount), 0)
     FROM contributions
     WHERE member_id = :member_id AND status = 'recorded'"
);
$contributionStmt->execute(['member_id' => $memberId]);
$contributionTotal = (float) $contributionStmt->fetchColumn();

$loanStmt = db()->prepare(
    "SELECT
        COUNT(*) AS active_count,
        COALESCE(SUM(CASE WHEN outstanding_balance IS NOT NULL THEN outstanding_balance ELSE approved_amount END), 0) AS outstanding_balance
     FROM loans
     WHERE member_id = :member_id AND status IN ('approved', 'overdue')"
);
$loanStmt->execute(['member_id' => $memberId]);
$loanSummary = $loanStmt->fetch() ?: ['active_count' => 0, 'outstanding_balance' => 0];

$pendingLoanStmt = db()->prepare(
    "SELECT COUNT(*) FROM loans WHERE member_id = :member_id AND status = 'pending'"
);
$pendingLoanStmt->execute(['member_id' => $memberId]);
$pendingApplications = (int) $pendingLoanStmt->fetchColumn();

$penaltyStmt = db()->prepare(
    "SELECT COALESCE(SUM(amount), 0)
     FROM penalties
     WHERE member_id = :member_id AND status = 'outstanding'"
);
$penaltyStmt->execute(['member_id' => $memberId]);
$outstandingPenalties = (float) $penaltyStmt->fetchColumn();

$goalStmt = db()->prepare(
    "SELECT COUNT(*) FROM investment_goals WHERE member_id = :member_id AND status = 'active'"
);
$goalStmt->execute(['member_id' => $memberId]);
$activeGoals = (int) $goalStmt->fetchColumn();

$investmentStmt = db()->prepare(
    "SELECT COALESCE(SUM(
        CASE WHEN t.transaction_type = 'contribution' THEN t.amount ELSE -t.amount END
     ), 0)
     FROM investment_goals g
     INNER JOIN investment_transactions t ON t.goal_id = g.goal_id
     WHERE g.member_id = :member_id AND g.status = 'active'"
);
$investmentStmt->execute(['member_id' => $memberId]);
$simulatedCapital = max(0, (float) $investmentStmt->fetchColumn());

$recentContributionStmt = db()->prepare(
    "SELECT contribution_date, amount, status, reference
     FROM contributions
     WHERE member_id = :member_id
     ORDER BY contribution_date DESC, contribution_id DESC
     LIMIT 5"
);
$recentContributionStmt->execute(['member_id' => $memberId]);
$recentContributions = $recentContributionStmt->fetchAll();

$nextMeeting = db()->query(
    "SELECT title, meeting_date
     FROM meetings
     WHERE meeting_date >= CURDATE() AND status = 'scheduled'
     ORDER BY meeting_date ASC
     LIMIT 1"
)->fetch();

$pageTitle = 'Member Dashboard | ' . APP_NAME;
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="member-layout">
    <aside class="member-sidebar">
        <nav aria-label="Member navigation">
            <a class="member-nav-link active" href="<?php echo e(url('member/dashboard.php')); ?>" aria-current="page"><span aria-hidden="true">&#8962;</span> Dashboard</a>
            <a class="member-nav-link" href="<?php echo e(url('member/contributions.php')); ?>"><span aria-hidden="true">&#9776;</span> Contributions</a>
            <a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span aria-hidden="true">&#9888;</span> Penalties</a>
            <a class="member-nav-link" href="<?php echo e(url('member/loans.php')); ?>"><span aria-hidden="true">&#9633;</span> Loans</a>
            <a class="member-nav-link" href="<?php echo e(url('member/repayments.php')); ?>"><span aria-hidden="true">&#8646;</span> Loan Repayment</a>
            <a class="member-nav-link" href="<?php echo e(url('member/meetings.php')); ?>"><span aria-hidden="true">&#9635;</span> Meetings</a>
            <a class="member-nav-link" href="<?php echo e(url('member/investments.php')); ?>"><span aria-hidden="true">&#9679;</span> Investments</a>
            <a class="member-nav-link" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span aria-hidden="true">&#10022;</span> AI Advisor</a>
            <a class="member-nav-link" href="<?php echo e(url('member/profile.php')); ?>"><span aria-hidden="true">&#9679;</span> Profile</a>
        </nav>
        <a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span aria-hidden="true">&#10132;</span> Logout</a>
    </aside>

    <section class="member-content">
        <div class="member-breadcrumb">Dashboard</div>
        <div class="member-heading">
            <div>
                <p class="eyebrow mb-2">Your Chama at a glance</p>
                <h1>Karibu, <?php echo e($user['name']); ?>.</h1>
                <p>Here is a clear view of your recorded activity and what needs your attention.</p>
            </div>
            <a class="btn btn-outline-brand" href="<?php echo e(url('member/profile.php')); ?>">View Profile</a>
        </div>

        <div class="admin-stat-grid member-overview-grid">
            <article class="admin-stat-card"><span class="admin-stat-icon" aria-hidden="true">KES</span><div><span>Recorded Contributions</span><strong>KES <?php echo e(number_format($contributionTotal, 2)); ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon" aria-hidden="true">&#9633;</span><div><span>Outstanding Loan Balance</span><strong>KES <?php echo e(number_format((float) $loanSummary['outstanding_balance'], 2)); ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon" aria-hidden="true">&#9888;</span><div><span>Outstanding Penalties</span><strong>KES <?php echo e(number_format($outstandingPenalties, 2)); ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon" aria-hidden="true">&#9679;</span><div><span>Active Simulated Goals</span><strong><?php echo $activeGoals; ?></strong></div></article>
        </div>

        <div class="member-overview-grid member-overview-panels">
            <article class="profile-panel">
                <div class="member-overview-panel-heading">
                    <div><h2>Recent Contributions</h2><p>Your latest recorded contribution activity.</p></div>
                    <a href="<?php echo e(url('member/contributions.php')); ?>">View history</a>
                </div>
                <?php if ($recentContributions): ?>
                    <div class="table-panel">
                        <table>
                            <thead><tr><th>Date</th><th>Reference</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentContributions as $contribution): ?>
                                <tr>
                                    <td><?php echo e($contribution['contribution_date']); ?></td>
                                    <td><?php echo e($contribution['reference'] ?: '—'); ?></td>
                                    <td><span class="table-status"><?php echo e(ucfirst($contribution['status'])); ?></span></td>
                                    <td class="text-end">KES <?php echo e(number_format((float) $contribution['amount'], 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-module"><span aria-hidden="true">&#9776;</span><p>No contribution records yet.</p><a href="<?php echo e(url('member/contributions.php')); ?>">View contributions</a></div>
                <?php endif; ?>
            </article>

            <article class="profile-panel member-attention-panel">
                <h2>Coming up</h2>
                <?php if ($nextMeeting): ?>
                    <div class="member-agenda-item">
                        <span class="member-agenda-icon" aria-hidden="true">&#9635;</span>
                        <div><small>Next scheduled meeting</small><strong><?php echo e($nextMeeting['title']); ?></strong><span><?php echo e(date('D, j M Y', strtotime((string) $nextMeeting['meeting_date']))); ?></span></div>
                    </div>
                <?php else: ?>
                    <div class="empty-module"><span aria-hidden="true">&#9635;</span><p>No upcoming meetings scheduled.</p><a href="<?php echo e(url('member/meetings.php')); ?>">View meetings</a></div>
                <?php endif; ?>
                <?php if ($pendingApplications > 0): ?>
                    <div class="member-notice"><strong><?php echo $pendingApplications; ?> loan application<?php echo $pendingApplications === 1 ? '' : 's'; ?> awaiting review</strong><span>Applications remain subject to the chama's existing approval process.</span></div>
                <?php endif; ?>
                <div class="member-capital-note"><span>Simulated investment capital</span><strong>KES <?php echo e(number_format($simulatedCapital, 2)); ?></strong><small>Record-keeping only; no real money is invested.</small></div>
                <a class="btn btn-brand w-100" href="<?php echo e(url('member/investments.php')); ?>">Explore investment goals <span aria-hidden="true">&#8594;</span></a>
            </article>
        </div>

        <div class="member-quick-actions" aria-label="Quick actions">
            <a href="<?php echo e(url('member/contributions.php')); ?>"><span aria-hidden="true">&#9776;</span><strong>Contributions</strong><small>Review your history</small></a>
            <a href="<?php echo e(url('member/loans.php')); ?>"><span aria-hidden="true">&#9633;</span><strong>Loans</strong><small><?php echo (int) $loanSummary['active_count']; ?> active · <?php echo $pendingApplications; ?> pending</small></a>
            <a href="<?php echo e(url('member/penalties.php')); ?>"><span aria-hidden="true">&#9888;</span><strong>Penalties</strong><small>Review your account</small></a>
            <a href="<?php echo e(url('member/ai_advisor.php')); ?>"><span aria-hidden="true">&#10022;</span><strong>AI Financial Advisor</strong><small>Ask about your finances</small></a>
        </div>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
