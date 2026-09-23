<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

$pageTitle = 'Admin Dashboard | ' . APP_NAME;
$navType = 'admin';
$bodyClass = 'dashboard-body';
$memberCount = (int) db()->query('SELECT COUNT(*) FROM members')->fetchColumn();
$adminCount = (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$activeMemberCount = (int) db()->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();
$recentMembers = db()->query('SELECT name, joined_date, status FROM members ORDER BY member_id DESC LIMIT 5')->fetchAll();
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
            <a class="admin-nav-link" href="#"><span aria-hidden="true">&#9635;</span> Meetings</a>
            <a class="admin-nav-link" href="#"><span aria-hidden="true">&#128196;</span> Documents</a>
            <a class="admin-nav-link" href="#"><span aria-hidden="true">&#9646;</span> Reports</a>
            <a class="admin-nav-link" href="#"><span aria-hidden="true">&#9881;</span> Settings</a>
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
            <button class="admin-date-control" type="button">&#128197; Select Date Range <span>&#8964;</span></button>
        </div>

        <div class="admin-stat-grid">
            <article class="admin-stat-card"><span class="admin-stat-icon">&#128101;</span><div><span>Total Members</span><strong><?php echo $memberCount; ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9776;</span><div><span>Total Contributions</span><strong>--</strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9633;</span><div><span>Total Loans</span><strong>--</strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9635;</span><div><span>Upcoming Meetings</span><strong>--</strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9679;</span><div><span>Active Members</span><strong><?php echo $activeMemberCount; ?></strong></div></article>
        </div>

        <div class="admin-panel-grid admin-panel-grid-top">
            <article class="admin-panel trend-panel">
                <div class="admin-panel-title"><h2>Contributions Trend</h2><button type="button">Last 6 Months &#8964;</button></div>
                <div class="trend-chart" aria-label="Contributions trend placeholder">
                    <span class="chart-label label-500">500K</span><span class="chart-label label-400">400K</span><span class="chart-label label-300">300K</span><span class="chart-label label-200">200K</span><span class="chart-label label-0">0</span>
                    <div class="chart-line"><i></i><i></i><i></i><i></i><i></i><i></i></div>
                    <div class="chart-months"><span>Jan</span><span>Feb</span><span>Mar</span><span>Apr</span><span>May</span><span>Jun</span></div>
                </div>
            </article>
            <article class="admin-panel loan-panel">
                <h2>Loan Status</h2>
                <div class="loan-empty"><span class="loan-donut"></span><div><label><i></i> Active Loans</label><label><i></i> Completed Loans</label><label><i></i> Overdue Loans</label></div></div>
            </article>
            <article class="admin-panel report-panel">
                <h2>&#128196; Generate Reports</h2>
                <label>Report Type<select><option>-- Select Report Type --</option></select></label>
                <label>Date Range<button type="button">&#128197; Select Date Range</button></label>
                <label>Format<select><option>-- Select Format --</option></select></label>
                <button class="admin-generate" type="button">Generate Report</button>
            </article>
        </div>

        <div class="admin-panel-grid admin-panel-grid-bottom">
            <article class="admin-panel table-panel">
                <div class="admin-panel-title"><h2>Recent Members</h2><a href="<?php echo e(url('admin/members.php')); ?>">View All &#8594;</a></div>
                <table><thead><tr><th>#</th><th>Name</th><th>Join Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    <?php if ($recentMembers): ?><?php foreach ($recentMembers as $index => $recentMember): ?><tr><td><?php echo $index + 1; ?></td><td><?php echo e($recentMember['name']); ?></td><td><?php echo e($recentMember['joined_date']); ?></td><td><span class="table-status"><?php echo e(ucfirst($recentMember['status'])); ?></span></td><td>...</td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="5">No members registered yet.</td></tr><?php endif; ?>
                </tbody></table>
            </article>
            <article class="admin-panel table-panel">
                <div class="admin-panel-title"><h2>Upcoming Meetings</h2><a href="#">View All &#8594;</a></div>
                <div class="empty-module"><span>&#9635;</span><p>No meetings scheduled yet.</p><a href="#">Add a meeting</a></div>
            </article>
        </div>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
