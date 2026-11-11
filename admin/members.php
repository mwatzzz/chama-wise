<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

$search = trim((string) ($_GET['search'] ?? ''));
$sql = "SELECT member_id, name, phone, email, joined_date, status FROM members WHERE status <> 'erased'";
$params = [];
if ($search !== '') {
    $sql .= ' AND (name LIKE :search OR phone LIKE :search OR email LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
$sql .= ' ORDER BY member_id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$pageTitle = 'Members | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="admin-layout">
    <?php require ROOT_PATH . '/includes/admin_sidebar.php'; ?>

    <section class="admin-content">
        <div class="admin-heading">
            <div>
                <p class="eyebrow mb-2">Member Management</p>
                <h1>Members</h1>
                <p>View the people registered in your chama.</p>
            </div>
            <a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a>
        </div>

        <article class="admin-panel table-panel members-table-panel">
            <div class="admin-panel-title">
                <h2>All Members <span class="member-count-badge"><?php echo count($members); ?></span></h2>
                <form method="get" class="member-search-form">
                    <input class="member-search" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search members..." aria-label="Search members">
                    <button type="submit">Search</button>
                </form>
            </div>
            <table>
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Phone</th><th>Email</th><th>Join Date</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if ($members): ?>
                        <?php foreach ($members as $member): ?>
                            <tr>
                                <td><?php echo e((string) $member['member_id']); ?></td>
                                <td><a href="<?php echo e(url('admin/member.php?id=' . $member['member_id'])); ?>"><strong><?php echo e($member['name']); ?></strong></a></td>
                                <td><?php echo e($member['phone']); ?></td>
                                <td><?php echo e($member['email']); ?></td>
                                <td><?php echo e($member['joined_date']); ?></td>
                                <td><span class="table-status"><?php echo e(ucfirst($member['status'])); ?></span></td>
                                <td><a href="<?php echo e(url('admin/member.php?id=' . $member['member_id'])); ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7">No members registered yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </article>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
