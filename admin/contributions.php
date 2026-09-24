<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();
ensure_contributions_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('admin/contributions.php');
    }

    $action = (string) ($_POST['action'] ?? 'create');
    $contributionId = (int) ($_POST['contribution_id'] ?? 0);
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $date = trim((string) ($_POST['contribution_date'] ?? ''));
    $paymentMethodId = (int) ($_POST['payment_method_id'] ?? 0);
    $reference = trim((string) ($_POST['reference'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    $dateValue = DateTime::createFromFormat('!Y-m-d', $date);
    if ($memberId < 1 || $amount <= 0 || !$dateValue || $dateValue->format('Y-m-d') !== $date || $paymentMethodId < 1) {
        flash_set('error', 'Enter a valid member, amount and date.');
        redirect('admin/contributions.php');
    }

    if ($action === 'update' && $contributionId > 0) {
        $stmt = db()->prepare(
            'UPDATE contributions
             SET member_id = :member_id, amount = :amount, payment_method_id = :payment_method_id,
                 contribution_date = :contribution_date, reference = :reference, notes = :notes
             WHERE contribution_id = :contribution_id'
        );
        $stmt->execute([
            'member_id' => $memberId,
            'amount' => $amount,
            'payment_method_id' => $paymentMethodId,
            'contribution_date' => $date,
            'reference' => $reference !== '' ? $reference : null,
            'notes' => $notes !== '' ? $notes : null,
            'contribution_id' => $contributionId,
        ]);
        sync_contribution_penalty($contributionId, (int) current_user()['user_id']);
        flash_set('success', 'Contribution updated successfully.');
    } else {
        $stmt = db()->prepare(
            'INSERT INTO contributions (member_id, amount, payment_method_id, contribution_date, reference, notes)
             VALUES (:member_id, :amount, :payment_method_id, :contribution_date, :reference, :notes)'
        );
        $stmt->execute([
            'member_id' => $memberId,
            'amount' => $amount,
            'payment_method_id' => $paymentMethodId,
            'contribution_date' => $date,
            'reference' => $reference !== '' ? $reference : null,
            'notes' => $notes !== '' ? $notes : null,
        ]);
        sync_contribution_penalty((int) db()->lastInsertId(), (int) current_user()['user_id']);
        flash_set('success', 'Contribution recorded successfully.');
    }
    redirect('admin/contributions.php');
}

$members = db()->query('SELECT member_id, name FROM members WHERE status = \'active\' ORDER BY name')->fetchAll();
$paymentMethods = db()->query('SELECT payment_method_id, name FROM payment_methods WHERE is_active = 1 ORDER BY name')->fetchAll();
$editContribution = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT contribution_id, member_id, amount, payment_method_id, contribution_date, reference, notes FROM contributions WHERE contribution_id = :id LIMIT 1');
    $stmt->execute(['id' => $editId]);
    $editContribution = $stmt->fetch() ?: null;
}
$contributions = db()->query(
    'SELECT c.contribution_id, c.amount, c.contribution_date, c.reference, c.notes, pm.name AS payment_method, m.name
     FROM contributions c
     INNER JOIN members m ON m.member_id = c.member_id
     LEFT JOIN payment_methods pm ON pm.payment_method_id = c.payment_method_id
     ORDER BY c.contribution_date DESC, c.contribution_id DESC
     LIMIT 50'
)->fetchAll();

$pageTitle = 'Contributions | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="admin-layout">
    <aside class="admin-sidebar">
        <nav aria-label="Admin navigation">
            <a class="admin-nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/members.php')); ?>"><span>&#128101;</span> Members</a>
            <a class="admin-nav-link active" href="<?php echo e(url('admin/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/loans.php')); ?>"><span>&#9633;</span> Loans</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/repayments.php')); ?>"><span>&#8646;</span> Repayments</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
            <a class="admin-nav-link" href="#"><span>&#128196;</span> Documents</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/reports.php')); ?>"><span>&#9646;</span> Reports</a>
            <a class="admin-nav-link" href="#"><span>&#9881;</span> Settings</a>
        </nav>
        <a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a>
    </aside>

    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Financial Records</p><h1>Contributions</h1><p>Record and review member contributions.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <div class="contribution-layout">
            <article class="admin-panel contribution-form-panel">
                <h2><?php echo $editContribution ? 'Edit Contribution' : 'Record Contribution'; ?></h2>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editContribution ? 'update' : 'create'; ?>">
                    <?php if ($editContribution): ?><input type="hidden" name="contribution_id" value="<?php echo e((string) $editContribution['contribution_id']); ?>"><?php endif; ?>
                    <label>Member<select name="member_id" required><option value="">Select member</option><?php foreach ($members as $member): ?><option value="<?php echo e((string) $member['member_id']); ?>" <?php echo ((int) ($editContribution['member_id'] ?? 0) === (int) $member['member_id']) ? 'selected' : ''; ?>><?php echo e($member['name']); ?></option><?php endforeach; ?></select></label>
                    <label>Amount (KES)<input type="number" name="amount" min="1" step="0.01" value="<?php echo e((string) ($editContribution['amount'] ?? '')); ?>" required></label>
                    <label>Payment Method<select name="payment_method_id" required><option value="">Select payment method</option><?php foreach ($paymentMethods as $paymentMethod): ?><option value="<?php echo e((string) $paymentMethod['payment_method_id']); ?>" <?php echo ((int) ($editContribution['payment_method_id'] ?? 0) === (int) $paymentMethod['payment_method_id']) ? 'selected' : ''; ?>><?php echo e($paymentMethod['name']); ?></option><?php endforeach; ?></select></label>
                    <label>Date<input type="date" name="contribution_date" value="<?php echo e((string) ($editContribution['contribution_date'] ?? date('Y-m-d'))); ?>" required></label>
                    <label>Reference <span class="text-muted">(optional)</span><input type="text" name="reference" maxlength="80" value="<?php echo e((string) ($editContribution['reference'] ?? '')); ?>"></label>
                    <label>Notes <span class="text-muted">(optional)</span><textarea name="notes" maxlength="255"><?php echo e((string) ($editContribution['notes'] ?? '')); ?></textarea></label>
                    <button class="btn btn-brand" type="submit"><?php echo $editContribution ? 'Update Contribution' : 'Record Contribution'; ?></button>
                    <?php if ($editContribution): ?><a class="btn btn-outline-brand" href="<?php echo e(url('admin/contributions.php')); ?>">Cancel Edit</a><?php endif; ?>
                </form>
            </article>
            <article class="admin-panel table-panel"><div class="admin-panel-title"><h2>Recent Contributions</h2></div><table><thead><tr><th>Member</th><th>Amount</th><th>Date</th><th>Payment Method</th><th>Notes</th><th>Action</th></tr></thead><tbody><?php if ($contributions): ?><?php foreach ($contributions as $contribution): ?><tr><td><?php echo e($contribution['name']); ?></td><td>KES <?php echo e(number_format((float) $contribution['amount'], 2)); ?></td><td><?php echo e($contribution['contribution_date']); ?></td><td><?php echo e($contribution['payment_method'] ?? 'Not specified'); ?></td><td><?php echo e($contribution['notes'] ?? '-'); ?></td><td><a href="<?php echo e(url('admin/contributions.php?edit=' . $contribution['contribution_id'])); ?>">Edit</a></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="6">No contributions recorded yet.</td></tr><?php endif; ?></tbody></table></article>
        </div>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
