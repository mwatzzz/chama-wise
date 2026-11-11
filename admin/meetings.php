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

    $uploadedDocument = $_FILES['meeting_document'] ?? null;
    $documentUploadAttempted = is_array($uploadedDocument) && ($uploadedDocument['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $existingMinutesOrDocument = false;
    if ($action === 'update' && $meetingId > 0) {
        $existingStmt = db()->prepare('SELECT minutes, document_path FROM meetings WHERE meeting_id = :meeting_id LIMIT 1');
        $existingStmt->execute(['meeting_id' => $meetingId]);
        $existingMeeting = $existingStmt->fetch();
        $existingMinutesOrDocument = $existingMeeting && (trim((string) ($existingMeeting['minutes'] ?? '')) !== '' || !empty($existingMeeting['document_path']));
    }
    if ($date >= date('Y-m-d') && ($minutes !== '' || $documentUploadAttempted || $existingMinutesOrDocument)) {
        flash_set('error', 'Minutes and their document can only be added after the meeting. You can schedule it now and edit it after the meeting date.');
        redirect('admin/meetings.php' . ($meetingId > 0 ? '?edit=' . $meetingId : ''));
    }

    $documentPath = null;
    $documentName = null;
    $documentMime = null;
    if (is_array($uploadedDocument) && ($uploadedDocument['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($uploadedDocument['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($uploadedDocument['size'] ?? 0) > 10 * 1024 * 1024) {
            flash_set('error', 'Meeting documents must be 10 MB or smaller.');
            redirect('admin/meetings.php' . ($meetingId > 0 ? '?edit=' . $meetingId : ''));
        }

        $allowedMimes = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        $documentMime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $uploadedDocument['tmp_name']);
        if (!isset($allowedMimes[$documentMime])) {
            flash_set('error', 'Please upload a PDF, DOC, or DOCX file.');
            redirect('admin/meetings.php' . ($meetingId > 0 ? '?edit=' . $meetingId : ''));
        }

        $documentDirectory = ROOT_PATH . '/uploads/meeting-documents';
        if (!is_dir($documentDirectory) && !mkdir($documentDirectory, 0755, true) && !is_dir($documentDirectory)) {
            flash_set('error', 'The meeting document folder could not be created.');
            redirect('admin/meetings.php' . ($meetingId > 0 ? '?edit=' . $meetingId : ''));
        }

        $documentName = basename((string) ($uploadedDocument['name'] ?? 'meeting-document'));
        $documentPath = 'uploads/meeting-documents/' . bin2hex(random_bytes(16)) . '.' . $allowedMimes[$documentMime];
        if (!move_uploaded_file((string) $uploadedDocument['tmp_name'], ROOT_PATH . '/' . $documentPath)) {
            flash_set('error', 'The meeting document could not be saved.');
            redirect('admin/meetings.php' . ($meetingId > 0 ? '?edit=' . $meetingId : ''));
        }
    }

    if ($action === 'update' && $meetingId > 0) {
        if ($documentPath !== null) {
            $stmt = db()->prepare('UPDATE meetings SET title = :title, meeting_date = :meeting_date, minutes = :minutes, document_path = :document_path, document_name = :document_name, document_mime = :document_mime WHERE meeting_id = :meeting_id');
            $stmt->execute(['title' => $title, 'meeting_date' => $date, 'minutes' => $minutes !== '' ? $minutes : null, 'document_path' => $documentPath, 'document_name' => $documentName, 'document_mime' => $documentMime, 'meeting_id' => $meetingId]);
        } else {
            $stmt = db()->prepare('UPDATE meetings SET title = :title, meeting_date = :meeting_date, minutes = :minutes WHERE meeting_id = :meeting_id');
            $stmt->execute(['title' => $title, 'meeting_date' => $date, 'minutes' => $minutes !== '' ? $minutes : null, 'meeting_id' => $meetingId]);
        }
        flash_set('success', 'Meeting updated successfully.');
    } else {
        $stmt = db()->prepare('INSERT INTO meetings (title, meeting_date, minutes, document_path, document_name, document_mime, created_by) VALUES (:title, :meeting_date, :minutes, :document_path, :document_name, :document_mime, :created_by)');
        $stmt->execute(['title' => $title, 'meeting_date' => $date, 'minutes' => $minutes !== '' ? $minutes : null, 'document_path' => $documentPath, 'document_name' => $documentName, 'document_mime' => $documentMime, 'created_by' => current_user()['user_id']]);
        flash_set('success', 'Meeting created successfully.');
    }
    redirect('admin/meetings.php');
}

$editMeeting = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT meeting_id, title, meeting_date, minutes, document_name FROM meetings WHERE meeting_id = :meeting_id LIMIT 1');
    $stmt->execute(['meeting_id' => $editId]);
    $editMeeting = $stmt->fetch() ?: null;
}
$meetings = db()->query('SELECT meeting_id, title, meeting_date, minutes, document_name, status FROM meetings ORDER BY meeting_date DESC, meeting_id DESC')->fetchAll();
$canRecordMinutes = !$editMeeting || $editMeeting['meeting_date'] < date('Y-m-d');

$pageTitle = 'Meetings | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="admin-layout">
    <?php require ROOT_PATH . '/includes/admin_sidebar.php'; ?>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Meeting Management</p><h1>Meetings</h1><p>Create meetings and record minutes for members.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <div class="contribution-layout">
            <article class="admin-panel contribution-form-panel"><h2><?php echo $editMeeting ? 'Edit Meeting' : 'Create Meeting'; ?></h2><form method="post" enctype="multipart/form-data"><?php echo csrf_field(); ?><input type="hidden" name="action" value="<?php echo $editMeeting ? 'update' : 'create'; ?>"><?php if ($editMeeting): ?><input type="hidden" name="meeting_id" value="<?php echo e((string) $editMeeting['meeting_id']); ?>"><?php endif; ?><label>Meeting Title<input type="text" name="title" maxlength="160" value="<?php echo e((string) ($editMeeting['title'] ?? '')); ?>" required></label><label>Meeting Date<input class="meeting-date-input" type="date" name="meeting_date" data-today="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e((string) ($editMeeting['meeting_date'] ?? date('Y-m-d'))); ?>" required></label><p class="text-muted" data-meeting-minutes-notice<?php echo $canRecordMinutes ? ' hidden' : ''; ?>>Minutes and the minutes document can be added after the meeting date.</p><div data-meeting-minutes-fields<?php echo $canRecordMinutes ? '' : ' hidden'; ?>><label>Minutes <span class="text-muted">(optional)</span><textarea name="minutes"><?php echo e((string) ($editMeeting['minutes'] ?? '')); ?></textarea></label><label>Minutes Document <span class="text-muted">(PDF, DOC, DOCX; max 10 MB)</span><input type="file" name="meeting_document" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></label><?php if (!empty($editMeeting['document_name'])): ?><p class="text-muted">Current document: <?php echo e($editMeeting['document_name']); ?></p><?php endif; ?></div><button class="btn btn-brand" type="submit"><?php echo $editMeeting ? 'Update Meeting' : 'Create Meeting'; ?></button><?php if ($editMeeting): ?><a class="btn btn-outline-brand" href="<?php echo e(url('admin/meetings.php')); ?>">Cancel Edit</a><?php endif; ?></form></article>
            <article class="admin-panel table-panel"><h2>Meeting History</h2><table><thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Minutes</th><th>Document</th><th>Action</th></tr></thead><tbody><?php if ($meetings): ?><?php foreach ($meetings as $meeting): ?><tr><td><?php echo e($meeting['title']); ?></td><td><?php echo e($meeting['meeting_date']); ?></td><td><?php echo e(ucfirst($meeting['status'])); ?></td><td><?php echo e($meeting['minutes'] ? 'Recorded' : 'Not recorded'); ?></td><td><?php echo e($meeting['document_name'] ? 'Uploaded' : 'None'); ?></td><td><?php if (!empty($meeting['document_name'])): ?><a href="<?php echo e(url('member/download_meeting_document.php?id=' . $meeting['meeting_id'])); ?>">Download</a> | <?php endif; ?><a href="<?php echo e(url('admin/meetings.php?edit=' . $meeting['meeting_id'])); ?>">Edit</a></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="6">No meetings have been scheduled.</td></tr><?php endif; ?></tbody></table></article>
        </div>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
