<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('admin/penalties.php');
    }
    $penaltyId = (int) ($_POST['penalty_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if ($penaltyId < 1 || !in_array($status, ['outstanding', 'waived', 'paid'], true)) {
        flash_set('error', 'Invalid penalty update.');
        redirect('admin/penalties.php');
    }
    $stmt = db()->prepare('UPDATE penalties SET status = :status WHERE penalty_id = :penalty_id');
    $stmt->execute(['status' => $status, 'penalty_id' => $penaltyId]);
    flash_set('success', 'Penalty status updated.');
    redirect('admin/penalties.php');
}

$penalties = db()->query(
    'SELECT p.penalty_id, p.amount, p.reason, p.penalty_date, p.status, m.name
     FROM penalties p INNER JOIN members m ON m.member_id = p.member_id
     ORDER BY p.penalty_date DESC, p.penalty_id DESC'
)->fetchAll();

$pageTitle = 'Penalties | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="admin-layout">
    <aside class="admin-sidebar"><nav aria-label="Admin navigation">
        <a class="admin-nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/members.php')); ?>"><span>&#128101;</span> Members</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="admin-nav-link active" href="<?php echo e(url('admin/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/loans.php')); ?>"><span>&#9633;</span> Loans</a><a class="admin-nav-link" href="#"><span>&#9635;</span> Meetings</a><a class="admin-nav-link" href="#"><span>&#9646;</span> Reports</a>
    </nav><a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Financial Governance</p><h1>Penalties</h1><p>Review and manage contribution penalties.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <article class="admin-panel table-panel"><table><thead><tr><th>Member</th><th>Amount</th><th>Reason</th><th>Date</th><th>Status</th><th>Update</th></tr></thead><tbody><?php if ($penalties): ?><?php foreach ($penalties as $penalty): ?><tr><td><?php echo e($penalty['name']); ?></td><td>KES <?php echo e(number_format((float) $penalty['amount'], 2)); ?></td><td><?php echo e($penalty['reason']); ?></td><td><?php echo e($penalty['penalty_date']); ?></td><td><?php echo e(ucfirst($penalty['status'])); ?></td><td><form method="post" class="penalty-status-form"><?php echo csrf_field(); ?><input type="hidden" name="penalty_id" value="<?php echo e((string) $penalty['penalty_id']); ?>"><select name="status"><option value="outstanding" <?php echo $penalty['status'] === 'outstanding' ? 'selected' : ''; ?>>Outstanding</option><option value="paid" <?php echo $penalty['status'] === 'paid' ? 'selected' : ''; ?>>Paid</option><option value="waived" <?php echo $penalty['status'] === 'waived' ? 'selected' : ''; ?>>Waived</option></select><button type="submit">Save</button></form></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="6">No penalties have been recorded.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
