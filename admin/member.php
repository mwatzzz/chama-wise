<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

try {
    member_erasure_retry_profile_picture_cleanup(db(), 10);
} catch (Throwable $exception) {
    error_log('Member profile-picture cleanup retry failed (' . get_class($exception) . ').');
    flash_set('error', 'A queued profile-picture cleanup could not be retried. Erased accounts remain inaccessible.');
}

$memberId = (int) ($_GET['id'] ?? $_POST['member_id'] ?? 0);
if ($memberId < 1) {
    flash_set('error', 'A valid member was not selected.');
    redirect('admin/members.php');
}

$memberStmt = db()->prepare('SELECT member_id, name, phone, email, joined_date, status FROM members WHERE member_id = :member_id LIMIT 1');
$memberStmt->execute(['member_id' => $memberId]);
$member = $memberStmt->fetch();
if (!$member) {
    flash_set('error', 'Member not found.');
    redirect('admin/members.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('admin/member.php?id=' . $memberId);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($member['status'] === 'erased' && in_array($action, ['toggle_status', 'update', 'erase_member'], true)) {
        flash_set('error', 'An erased member account cannot be reactivated or edited.');
        redirect('admin/members.php');
    }
    if ($action === 'erase_member') {
        if ((string) ($_POST['confirm_erasure'] ?? '') !== '1') {
            flash_set('error', 'Confirm the member name and ID before erasing the account.');
            redirect('admin/member.php?id=' . $memberId);
        }

        try {
            $erasure = erase_member_account(
                db(),
                $memberId,
                (int) current_user()['user_id']
            );
            $cleanupRetryFailed = false;
            try {
                member_erasure_retry_profile_picture_cleanup(db(), 100, $erasure['member_id']);
                $cleanupRetryFailed = member_erasure_has_pending_profile_picture_cleanup(
                    db(),
                    $erasure['member_id']
                );
            } catch (Throwable $cleanupException) {
                error_log('Member profile-picture cleanup failed (' . get_class($cleanupException) . ').');
                $cleanupRetryFailed = true;
            }
            flash_set(
                $cleanupRetryFailed ? 'error' : 'success',
                !$cleanupRetryFailed
                    ? 'Member account permanently erased and anonymized. Financial and audit records were retained.'
                    : 'Member account erased and anonymized. Its profile picture is inaccessible and queued for automatic cleanup retry.'
            );
            redirect('admin/members.php');
        } catch (MemberErasureBlocked | InvalidArgumentException $exception) {
            flash_set('error', $exception->getMessage());
            redirect('admin/member.php?id=' . $memberId);
        } catch (Throwable $exception) {
            error_log('Member account erasure failed (' . get_class($exception) . ').');
            flash_set('error', 'The member account could not be erased. No changes were committed; please try again or contact support.');
            redirect('admin/member.php?id=' . $memberId);
        }
    }

    if ($action === 'toggle_status') {
        $newStatus = $member['status'] === 'active' ? 'inactive' : 'active';
        $stmt = db()->prepare("UPDATE members SET status = :status WHERE member_id = :member_id AND status <> 'erased'");
        $stmt->execute(['status' => $newStatus, 'member_id' => $memberId]);
        if ($stmt->rowCount() !== 1) {
            $statusStmt = db()->prepare('SELECT status FROM members WHERE member_id = :member_id LIMIT 1');
            $statusStmt->execute(['member_id' => $memberId]);
            if ($statusStmt->fetchColumn() === 'erased') {
                flash_set('error', 'An erased member account cannot be reactivated or edited.');
                redirect('admin/members.php');
            }
        }
        flash_set('success', 'Member status updated successfully.');
        redirect('admin/member.php?id=' . $memberId);
    }

    if ($action === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = normalize_phone(trim((string) ($_POST['phone'] ?? '')));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $errors = [
            validate_required($name, 'Full name'),
            validate_phone($phone),
            validate_email($email),
        ];
        if (email_taken($email, $memberId, 'Member')) {
            $errors[] = 'An account with that email already exists.';
        }
        $phoneStmt = db()->prepare('SELECT member_id FROM members WHERE phone = :phone AND member_id <> :member_id LIMIT 1');
        $phoneStmt->execute(['phone' => $phone, 'member_id' => $memberId]);
        if ($phoneStmt->fetch()) {
            $errors[] = 'An account with that phone number already exists.';
        }

        $errors = first_error($errors);
        if ($errors !== []) {
            flash_set('error', $errors[0]);
            redirect('admin/member.php?id=' . $memberId);
        }

        $stmt = db()->prepare(
            "UPDATE members
             SET name = :name, phone = :phone, email = :email
             WHERE member_id = :member_id AND status <> 'erased'"
        );
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'member_id' => $memberId,
        ]);
        if ($stmt->rowCount() !== 1) {
            $statusStmt = db()->prepare('SELECT status FROM members WHERE member_id = :member_id LIMIT 1');
            $statusStmt->execute(['member_id' => $memberId]);
            if ($statusStmt->fetchColumn() === 'erased') {
                flash_set('error', 'An erased member account cannot be reactivated or edited.');
                redirect('admin/members.php');
            }
        }
        flash_set('success', 'Member details updated successfully.');
        redirect('admin/member.php?id=' . $memberId);
    }
}

$pageTitle = 'Member Details | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="admin-layout">
    <?php require ROOT_PATH . '/includes/admin_sidebar.php'; ?>

    <section class="admin-content">
        <div class="admin-heading">
            <div><p class="eyebrow mb-2">Member Management</p><h1>Member Details</h1><p>Review and update permitted member information.</p></div>
            <a class="btn btn-outline-brand" href="<?php echo e(url('admin/members.php')); ?>">&#8592; All Members</a>
        </div>

        <div class="member-management-grid">
            <article class="admin-panel">
                <div class="admin-panel-title"><h2><?php echo e($member['name']); ?></h2><span class="table-status"><?php echo e(ucfirst($member['status'])); ?></span></div>
                <p class="text-muted">Member ID: <?php echo e((string) $member['member_id']); ?></p>
                <p class="text-muted">Joined: <?php echo e($member['joined_date']); ?></p>
                <?php if ($member['status'] !== 'erased'): ?>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="member_id" value="<?php echo e((string) $member['member_id']); ?>">
                    <input type="hidden" name="action" value="toggle_status">
                    <button class="btn btn-outline-brand" type="submit"><?php echo $member['status'] === 'active' ? 'Deactivate Member' : 'Activate Member'; ?></button>
                </form>
                <?php endif; ?>
            </article>

            <?php if ($member['status'] !== 'erased'): ?>
            <article class="admin-panel member-edit-panel">
                <h2>Edit Member Information</h2>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="member_id" value="<?php echo e((string) $member['member_id']); ?>">
                    <input type="hidden" name="action" value="update">
                    <label>Full Name<input type="text" name="name" value="<?php echo e($member['name']); ?>" required></label>
                    <label>Phone Number<input type="text" name="phone" value="<?php echo e($member['phone']); ?>" required></label>
                    <label>Email Address<input type="email" name="email" value="<?php echo e($member['email']); ?>" required></label>
                    <button class="btn btn-brand" type="submit">Save Changes</button>
                </form>
            </article>
            <?php endif; ?>
            <?php if ($member['status'] !== 'erased'): ?>
            <article class="admin-panel">
                <h2>Delete Member Permanently</h2>
                <p>This erases the account's personal information and disables login. Financial transactions and required audit history will be retained.</p>
                <p><strong>Confirm account:</strong> <?php echo e($member['name']); ?> (Member ID #<?php echo e((string) $member['member_id']); ?>)</p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="member_id" value="<?php echo e((string) $member['member_id']); ?>">
                    <input type="hidden" name="action" value="erase_member">
                    <label class="d-flex align-items-start gap-2">
                        <input type="checkbox" name="confirm_erasure" value="1" required>
                        <span>I confirm permanent erasure of this member account.</span>
                    </label>
                    <button class="btn btn-danger mt-2" type="submit">Delete Member Permanently</button>
                </form>
            </article>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
