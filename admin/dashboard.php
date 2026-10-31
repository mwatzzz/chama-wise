<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('admin/dashboard.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'test_sms') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $customPhone = trim((string) ($_POST['phone'] ?? ''));

        $smsResult = ['success' => false, 'message' => 'No test SMS was sent.'];
        if ($memberId > 0) {
            $smsResult = send_sms_to_member($memberId, 'ChamaWise SMS test: this is a test message from Africa\'s Talking.');
        } elseif ($customPhone !== '') {
            $smsResult = send_sms($customPhone, 'ChamaWise SMS test: this is a test message from Africa\'s Talking.');
        }

        if ($smsResult['success'] ?? false) {
            flash_set('success', 'Test SMS sent successfully via ' . e((string) ($smsResult['provider'] ?? 'SMS provider')) . '.');
        } else {
            flash_set('error', 'Test SMS failed: ' . e((string) ($smsResult['message'] ?? 'Unknown SMS error.')));
        }

        redirect('admin/dashboard.php');
    }
}

$membersForSms = db()->query('SELECT member_id, name, phone FROM members WHERE status = "active" ORDER BY name')->fetchAll();
$pageTitle = 'Admin Dashboard | ' . APP_NAME;
$navType = 'admin';
$bodyClass = 'dashboard-body';
$memberCount = (int) db()->query('SELECT COUNT(*) FROM members')->fetchColumn();
$adminCount = (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$activeMemberCount = (int) db()->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();
$totalContributions = (float) db()->query("SELECT COALESCE(SUM(amount), 0) FROM contributions WHERE status = 'recorded'")->fetchColumn();
$totalLoans = (int) db()->query('SELECT COUNT(*) FROM loans')->fetchColumn();
$outstandingLoans = (float) db()->query("SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans WHERE status IN ('approved', 'overdue')")->fetchColumn();
$totalRepayments = (float) db()->query('SELECT COALESCE(SUM(amount), 0) FROM loan_repayments')->fetchColumn();
$totalPenalties = (float) db()->query("SELECT COALESCE(SUM(amount), 0) FROM penalties WHERE status = 'outstanding'")->fetchColumn();
$upcomingMeetingCount = (int) db()->query("SELECT COUNT(*) FROM meetings WHERE meeting_date >= CURDATE() AND status = 'scheduled'")->fetchColumn();
$recentMembers = db()->query('SELECT member_id, name, joined_date, status FROM members ORDER BY member_id DESC LIMIT 5')->fetchAll();
$upcomingMeetings = db()->query("SELECT title, meeting_date, status FROM meetings WHERE meeting_date >= CURDATE() ORDER BY meeting_date ASC LIMIT 3")->fetchAll();
$monthlyContributions = [];
for ($monthOffset = 5; $monthOffset >= 0; $monthOffset--) {
    $monthDate = new DateTimeImmutable('first day of this month -' . $monthOffset . ' months');
    $monthKey = $monthDate->format('Y-m');
    $nextMonthDate = $monthDate->modify('+1 month');
    $monthStmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM contributions WHERE status = 'recorded' AND contribution_date >= :month_start AND contribution_date < :next_month");
    $monthStmt->execute(['month_start' => $monthDate->format('Y-m-01'), 'next_month' => $nextMonthDate->format('Y-m-01')]);
    $monthlyContributions[] = ['label' => $monthDate->format('M'), 'amount' => (float) $monthStmt->fetchColumn()];
}
$chartMaximum = max(1, ...array_column($monthlyContributions, 'amount'));
$chartTickLabel = static function (float $amount): string {
    if ($amount >= 1000) {
        $precision = $amount >= 100000 ? 0 : 1;
        return number_format($amount / 1000, $precision) . 'K';
    }

    return number_format($amount, 0);
};
require ROOT_PATH . '/includes/header.php';
?>

<div class="admin-layout">
    <aside class="admin-sidebar">
        <nav aria-label="Admin navigation">
            <a class="admin-nav-link active" href="<?php echo e(url('admin/dashboard.php')); ?>"><span aria-hidden="true">&#8962;</span> Dashboard</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/members.php')); ?>"><span aria-hidden="true">&#128101;</span> Members</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/contributions.php')); ?>"><span aria-hidden="true">&#9776;</span> Contributions</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/penalties.php')); ?>"><span aria-hidden="true">&#9888;</span> Penalties</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/loans.php')); ?>"><span aria-hidden="true">&#9633;</span> Loans</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/repayments.php')); ?>"><span aria-hidden="true">&#8646;</span> Repayments</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/meetings.php')); ?>"><span aria-hidden="true">&#9635;</span> Meetings</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/reports.php')); ?>"><span aria-hidden="true">&#9646;</span> Reports</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/investments.php')); ?>"><span aria-hidden="true">&#9679;</span> Investments</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/settings.php')); ?>"><span aria-hidden="true">&#9881;</span> Settings</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/intelligence.php')); ?>"><span aria-hidden="true">&#10022;</span> ChamaWise Intelligence</a>
        </nav>
        <a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span aria-hidden="true">&#10132;</span> Logout</a>
    </aside>

    <section class="admin-content">
        <div class="admin-heading">
            <div>
                <p class="eyebrow mb-2">Admin Dashboard</p>
                <h1>Welcome, <?php echo e(current_user()['name'] ?? 'Admin'); ?>.</h1>
                <p>Here is an overview of your chama activities.</p>
            </div>
            <span class="admin-overview-badge">All-time overview</span>
        </div>

        <div class="admin-stat-grid">
            <article class="admin-stat-card"><span class="admin-stat-icon">&#128101;</span><div><span>Total Members</span><strong><?php echo $memberCount; ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9776;</span><div><span>Total Contributions</span><strong>KES <?php echo e(number_format($totalContributions, 0)); ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9633;</span><div><span>Total Loans</span><strong><?php echo $totalLoans; ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9635;</span><div><span>Upcoming Meetings</span><strong><?php echo $upcomingMeetingCount; ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9679;</span><div><span>Active Members</span><strong><?php echo $activeMemberCount; ?></strong></div></article>
        </div>

        <div class="admin-panel-grid admin-panel-grid-top">
            <article class="admin-panel trend-panel">
                <div class="admin-panel-title"><h2>Contributions Trend</h2><span class="chart-period">Last 6 months</span></div>
                <div class="trend-chart" role="img" aria-label="Monthly recorded contributions for the last six months in Kenyan shillings">
                    <?php for ($tick = 4; $tick >= 0; $tick--): ?>
                        <?php $tickValue = $chartMaximum * $tick / 4; ?>
                        <span class="chart-label" style="top: <?php echo e((string) ((4 - $tick) * 25)); ?>%; transform: translateY(<?php echo $tick === 4 ? '0' : ($tick === 0 ? '-100%' : '-50%'); ?>);"><?php echo e($chartTickLabel($tickValue)); ?></span>
                    <?php endfor; ?>
                    <div class="chart-bars"><?php foreach ($monthlyContributions as $monthlyContribution): ?><div class="chart-bar-wrap"><span class="chart-value">KES <?php echo e(number_format($monthlyContribution['amount'], 0)); ?></span><i class="chart-bar" style="height: <?php echo e((string) max(2, round(($monthlyContribution['amount'] / $chartMaximum) * 100))); ?>%"></i></div><?php endforeach; ?></div>
                    <div class="chart-months"><?php foreach ($monthlyContributions as $monthlyContribution): ?><span><?php echo e($monthlyContribution['label']); ?></span><?php endforeach; ?></div>
                </div>
            </article>
            <article class="admin-panel loan-panel">
                <h2>Loan Status</h2>
                <?php $activeLoans = (int) db()->query("SELECT COUNT(*) FROM loans WHERE status = 'approved'")->fetchColumn(); $completedLoans = (int) db()->query("SELECT COUNT(*) FROM loans WHERE status = 'completed'")->fetchColumn(); $overdueLoans = (int) db()->query("SELECT COUNT(*) FROM loans WHERE status = 'overdue'")->fetchColumn(); ?>
                <div class="loan-empty"><span class="loan-donut"></span><div><label><i></i> Active Loans: <?php echo $activeLoans; ?></label><label><i></i> Completed Loans: <?php echo $completedLoans; ?></label><label><i></i> Overdue Loans: <?php echo $overdueLoans; ?></label><small class="text-muted">Outstanding: KES <?php echo e(number_format($outstandingLoans, 0)); ?></small></div></div>
            </article>
            <article class="admin-panel report-panel">
                <h2>&#128196; Generate Reports</h2>
                <div class="dashboard-financial-summary"><span>Repayments: <strong>KES <?php echo e(number_format($totalRepayments, 0)); ?></strong></span><span>Outstanding penalties: <strong>KES <?php echo e(number_format($totalPenalties, 0)); ?></strong></span></div>
                <label>Report Type<select id="dashboard-report-type"><option value="contributions">Contribution Report</option><option value="loans">Loan Report</option><option value="repayments">Repayment Report</option><option value="penalties">Penalty Report</option><option value="summary">Financial Summary</option></select></label>
                <a class="admin-generate" id="dashboard-report-link" href="<?php echo e(url('admin/reports.php?report=contributions')); ?>">Generate Report</a>
            </article>
        </div>

        <div class="admin-panel-grid admin-panel-grid-bottom">
            <article class="admin-panel table-panel">
                <div class="admin-panel-title"><h2>Send Test SMS</h2></div>
                <form method="post" class="sms-test-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="test_sms">
                    <label>Member<select name="member_id">
                        <option value="">Choose a member</option>
                        <?php foreach ($membersForSms as $member): ?>
                            <option value="<?php echo e((string) $member['member_id']); ?>"><?php echo e($member['name']); ?><?php echo $member['phone'] ? ' - ' . e($member['phone']) : ''; ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Or direct phone number <input type="text" name="phone" value="" placeholder="+254712345678"></label>
                    <button class="btn btn-brand" type="submit">Send Test SMS</button>
                </form>
            </article>

        </div>

        <div class="admin-panel-grid admin-panel-grid-bottom">
            <article class="admin-panel table-panel">
                <div class="admin-panel-title"><h2>Recent Members</h2><a href="<?php echo e(url('admin/members.php')); ?>">View All &#8594;</a></div>
                <table><thead><tr><th>#</th><th>Name</th><th>Join Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    <?php if ($recentMembers): ?><?php foreach ($recentMembers as $index => $recentMember): ?><tr><td><?php echo $index + 1; ?></td><td><?php echo e($recentMember['name']); ?></td><td><?php echo e($recentMember['joined_date']); ?></td><td><span class="table-status"><?php echo e(ucfirst($recentMember['status'])); ?></span></td><td><a href="<?php echo e(url('admin/member.php?id=' . $recentMember['member_id'])); ?>">View</a></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="5">No members registered yet.</td></tr><?php endif; ?>
                </tbody></table>
            </article>
            <article class="admin-panel table-panel">
                <div class="admin-panel-title"><h2>Upcoming Meetings</h2><a href="<?php echo e(url('admin/meetings.php')); ?>">View All &#8594;</a></div>
                <?php if ($upcomingMeetings): ?><div class="admin-upcoming-list"><?php foreach ($upcomingMeetings as $upcomingMeeting): ?><div><strong><?php echo e($upcomingMeeting['title']); ?></strong><span><?php echo e($upcomingMeeting['meeting_date']); ?></span></div><?php endforeach; ?></div><?php else: ?><div class="empty-module"><span>&#9635;</span><p>No meetings scheduled yet.</p><a href="<?php echo e(url('admin/meetings.php')); ?>">Add a meeting</a></div><?php endif; ?>
            </article>
        </div>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
