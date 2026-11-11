<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$meetings = db()->query(
    "SELECT meeting_id, title, meeting_date, minutes, document_name, status
     FROM meetings
     ORDER BY meeting_date DESC, meeting_id DESC"
)->fetchAll();

$pageTitle = 'Meetings | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="member-layout">
    <?php require ROOT_PATH . '/includes/member_sidebar.php'; ?>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Meetings</div>
        <div class="member-heading"><div><h1>Meetings</h1><p>View chama meetings and read recorded minutes.</p></div></div>
        <?php if ($meetings): ?>
            <div class="meeting-list">
                <?php foreach ($meetings as $meeting): ?>
                    <article class="profile-panel meeting-card"><div class="meeting-card-heading"><div><p class="eyebrow mb-1"><?php echo e(ucfirst($meeting['status'])); ?></p><h2><?php echo e($meeting['title']); ?></h2></div><strong><?php echo e($meeting['meeting_date']); ?></strong></div><p class="text-muted mb-0"><?php echo nl2br(e($meeting['minutes'] ?? 'Minutes have not been recorded yet.')); ?></p><?php if (!empty($meeting['document_name'])): ?><a class="btn btn-outline-brand mt-3" href="<?php echo e(url('member/download_meeting_document.php?id=' . $meeting['meeting_id'])); ?>">Download Minutes Document</a><?php endif; ?></article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <article class="profile-panel empty-module"><span>&#9635;</span><p>No meetings have been scheduled yet.</p></article>
        <?php endif; ?>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
