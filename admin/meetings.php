<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('admin/meetings.php');
    }

    $action = (string) ($_POST['action'] ?? 'create');
    $meetingId = (int) ($_POST['meeting_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $date = trim((string) ($_POST['meeting_date'] ?? ''));
    $minutes = trim((string) ($_POST['minutes'] ?? ''));
    $dateValue = DateTime::createFromFormat('!Y-m-d', $date);

    if ($title === '' || !$dateValue || $dateValue->format('Y-m-d') !== $date) {
        flash_set('error', 'Enter a meeting title and valid date.');
        redirect('admin/meetings.php');
    }

    if ($action === 'update' && $meetingId > 0) {
        $stmt = db()->prepare('UPDATE meetings SET title = :title, meeting_date = :meeting_date, minutes = :minutes WHERE meeting_id = :meeting_id');
        $stmt->execute(['title' => $title, 'meeting_date' => $date, 'minutes' => $minutes !== '' ? $minutes : null, 'meeting_id' => $meetingId]);
        flash_set('success', 'Meeting updated successfully.');
    } else {
        $stmt = db()->prepare('INSERT INTO meetings (title, meeting_date, minutes, created_by) VALUES (:title, :meeting_date, :minutes, :created_by)');
        $stmt->execute(['title' => $title, 'meeting_date' => $date, 'minutes' => $minutes !== '' ? $minutes : null, 'created_by' => current_user()['user_id']]);
        flash_set('success', 'Meeting created successfully.');
    }
    redirect('admin/meetings.php');
}

$editMeeting = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT meeting_id, title, meeting_date, minutes FROM meetings WHERE meeting_id = :meeting_id LIMIT 1');
    $stmt->execute(['meeting_id' => $editId]);
    $editMeeting = $stmt->fetch() ?: null;
}
$meetings = db()->query('SELECT meeting_id, title, meeting_date, minutes, status FROM meetings ORDER BY meeting_date DESC, meeting_id DESC')->fetchAll();

$pageTitle = 'Meetings | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="admin-layout">
    <aside class="admin-sidebar"><nav aria-label="Admin navigation">
        <a class="admin-nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/members.php')); ?>"><span>&#128101;</span> Members</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="admin-nav-link active" href="<?php echo e(url('admin/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/repayments.php')); ?>"><span>&#8646;</span> Repayments</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
    </nav><a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Meeting Management</p><h1>Meetings</h1><p>Create meetings and record minutes for members.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <div class="contribution-layout">
            <article class="admin-panel contribution-form-panel"><h2><?php echo $editMeeting ? 'Edit Meeting' : 'Create Meeting'; ?></h2><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="<?php echo $editMeeting ? 'update' : 'create'; ?>"><?php if ($editMeeting): ?><input type="hidden" name="meeting_id" value="<?php echo e((string) $editMeeting['meeting_id']); ?>"><?php endif; ?><label>Meeting Title<input type="text" name="title" maxlength="160" value="<?php echo e((string) ($editMeeting['title'] ?? '')); ?>" required></label><label>Meeting Date<input type="date" name="meeting_date" value="<?php echo e((string) ($editMeeting['meeting_date'] ?? date('Y-m-d'))); ?>" required></label><label>Minutes <span class="text-muted">(optional)</span><textarea name="minutes"><?php echo e((string) ($editMeeting['minutes'] ?? '')); ?></textarea></label><button class="btn btn-brand" type="submit"><?php echo $editMeeting ? 'Update Meeting' : 'Create Meeting'; ?></button><?php if ($editMeeting): ?><a class="btn btn-outline-brand" href="<?php echo e(url('admin/meetings.php')); ?>">Cancel Edit</a><?php endif; ?></form></article>
            <article class="admin-panel table-panel"><h2>Meeting History</h2><table><thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Minutes</th><th>Action</th></tr></thead><tbody><?php if ($meetings): ?><?php foreach ($meetings as $meeting): ?><tr><td><?php echo e($meeting['title']); ?></td><td><?php echo e($meeting['meeting_date']); ?></td><td><?php echo e(ucfirst($meeting['status'])); ?></td><td><?php echo e($meeting['minutes'] ? 'Recorded' : 'Not recorded'); ?></td><td><a href="<?php echo e(url('admin/meetings.php?edit=' . $meeting['meeting_id'])); ?>">Edit</a></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="5">No meetings have been scheduled.</td></tr><?php endif; ?></tbody></table></article>
        </div>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
