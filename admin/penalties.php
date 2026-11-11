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
    $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($penaltyId < 1 || !in_array($status, ['outstanding', 'waived', 'paid'], true) ||
        $amount === false || $amount < 0 || $reason === '' || mb_strlen($reason) > 255) {
        flash_set('error', 'Invalid penalty update.');
        redirect('admin/penalties.php');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $read = $pdo->prepare('SELECT amount, status FROM penalties WHERE penalty_id = :penalty_id FOR UPDATE');
        $read->execute(['penalty_id' => $penaltyId]);
        $previous = $read->fetch();
        if (!$previous) {
            throw new InvalidArgumentException('Penalty was not found.');
        }
        $update = $pdo->prepare('UPDATE penalties SET amount = :amount, status = :status WHERE penalty_id = :penalty_id');
        $update->execute(['amount' => round((float) $amount, 2), 'status' => $status, 'penalty_id' => $penaltyId]);
        $audit = $pdo->prepare(
            'INSERT INTO penalty_audit
                (penalty_id, previous_amount, new_amount, previous_status, new_status, reason, changed_by)
             VALUES
                (:penalty_id, :previous_amount, :new_amount, :previous_status, :new_status, :reason, :changed_by)'
        );
        $audit->execute([
            'penalty_id' => $penaltyId,
            'previous_amount' => $previous['amount'],
            'new_amount' => round((float) $amount, 2),
            'previous_status' => $previous['status'],
            'new_status' => $status,
            'reason' => $reason,
            'changed_by' => (int) current_user()['user_id'],
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof InvalidArgumentException) {
            flash_set('error', $exception->getMessage());
        } else {
            throw $exception;
        }
        redirect('admin/penalties.php');
    }
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
    <?php require ROOT_PATH . '/includes/admin_sidebar.php'; ?>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Financial Governance</p><h1>Penalties</h1><p>Review and manage contribution penalties.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <article class="admin-panel table-panel"><table><thead><tr><th>Member</th><th>Amount</th><th>Reason</th><th>Date</th><th>Status</th><th>Update / correction</th></tr></thead><tbody><?php if ($penalties): ?><?php foreach ($penalties as $penalty): ?><tr><td><?php echo e($penalty['name']); ?></td><td>KES <?php echo e(number_format((float) $penalty['amount'], 2)); ?></td><td><?php echo e($penalty['reason']); ?></td><td><?php echo e($penalty['penalty_date']); ?></td><td><?php echo e(ucfirst($penalty['status'])); ?></td><td><form method="post" class="penalty-status-form"><?php echo csrf_field(); ?><input type="hidden" name="penalty_id" value="<?php echo e((string) $penalty['penalty_id']); ?>"><label>Amount (KES)<input type="number" name="amount" min="0" step="0.01" value="<?php echo e((string) $penalty['amount']); ?>" required></label><label>Status<select name="status"><option value="outstanding" <?php echo $penalty['status'] === 'outstanding' ? 'selected' : ''; ?>>Outstanding</option><option value="paid" <?php echo $penalty['status'] === 'paid' ? 'selected' : ''; ?>>Paid</option><option value="waived" <?php echo $penalty['status'] === 'waived' ? 'selected' : ''; ?>>Waived</option></select></label><label>Reason for change<input type="text" name="reason" maxlength="255" required></label><button type="submit">Save</button></form></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="6">No penalties have been recorded.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
