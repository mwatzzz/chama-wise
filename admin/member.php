<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

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
    if ($action === 'toggle_status') {
        $newStatus = $member['status'] === 'active' ? 'inactive' : 'active';
        $stmt = db()->prepare('UPDATE members SET status = :status WHERE member_id = :member_id');
        $stmt->execute(['status' => $newStatus, 'member_id' => $memberId]);
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

        $stmt = db()->prepare('UPDATE members SET name = :name, phone = :phone, email = :email WHERE member_id = :member_id');
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'member_id' => $memberId,
        ]);
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
    <aside class="admin-sidebar">
        <nav aria-label="Admin navigation">
            <a class="admin-nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
            <a class="admin-nav-link active" href="<?php echo e(url('admin/members.php')); ?>"><span>&#128101;</span> Members</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
            <a class="admin-nav-link" href="#"><span>&#9633;</span> Loans</a>
            <a class="admin-nav-link" href="#"><span>&#9635;</span> Meetings</a>
            <a class="admin-nav-link" href="#"><span>&#128196;</span> Documents</a>
            <a class="admin-nav-link" href="#"><span>&#9646;</span> Reports</a>
            <a class="admin-nav-link" href="#"><span>&#9881;</span> Settings</a>
        </nav>
        <a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a>
    </aside>

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
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="member_id" value="<?php echo e((string) $member['member_id']); ?>">
                    <input type="hidden" name="action" value="toggle_status">
                    <button class="btn btn-outline-brand" type="submit"><?php echo $member['status'] === 'active' ? 'Deactivate Member' : 'Activate Member'; ?></button>
                </form>
            </article>

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
        </div>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
