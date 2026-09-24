<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$meetings = db()->query(
    "SELECT meeting_id, title, meeting_date, minutes, status
     FROM meetings
     ORDER BY meeting_date DESC, meeting_id DESC"
)->fetchAll();

$pageTitle = 'Meetings | Member Dashboard';
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
        <a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="member-nav-link active" href="<?php echo e(url('member/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
        <a class="member-nav-link" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span>&#10022;</span> AI Advisor</a>
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Meetings</div>
        <div class="member-heading"><div><h1>Meetings</h1><p>View chama meetings and read recorded minutes.</p></div></div>
        <?php if ($meetings): ?>
            <div class="meeting-list">
                <?php foreach ($meetings as $meeting): ?>
                    <article class="profile-panel meeting-card"><div class="meeting-card-heading"><div><p class="eyebrow mb-1"><?php echo e(ucfirst($meeting['status'])); ?></p><h2><?php echo e($meeting['title']); ?></h2></div><strong><?php echo e($meeting['meeting_date']); ?></strong></div><p class="text-muted mb-0"><?php echo nl2br(e($meeting['minutes'] ?? 'Minutes have not been recorded yet.')); ?></p></article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <article class="profile-panel empty-module"><span>&#9635;</span><p>No meetings have been scheduled yet.</p></article>
        <?php endif; ?>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
