<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();
ensure_contributions_table();

$user = current_user();
$darajaConfigured = daraja_sandbox_is_configured();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('member/contributions.php');
    }

    if (!$darajaConfigured) {
        flash_set('error', 'Daraja Sandbox is not configured. Ask the administrator to check the sandbox credentials and callback URL.');
        redirect('member/contributions.php');
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'daraja_contribution') {
            $payment = start_member_daraja_payment(
                (int) $user['user_id'],
                'contribution',
                null,
                (string) ($_POST['amount'] ?? ''),
                trim((string) ($_POST['phone_number'] ?? '')),
                (string) ($_POST['submission_key'] ?? '')
            );
            unset($_SESSION['member_daraja_contribution_submission_key']);
            flash_set($payment['status'] === 'failed' ? 'error' : 'info', $payment['message']);
        } else {
            throw new InvalidArgumentException('Choose a valid contribution action.');
        }
    } catch (InvalidArgumentException $exception) {
        flash_set('error', $exception->getMessage());
    }
    redirect('member/contributions.php');
}

if (empty($_SESSION['member_daraja_contribution_submission_key'])) {
    $_SESSION['member_daraja_contribution_submission_key'] = bin2hex(random_bytes(32));
}

$stmt = db()->prepare(
    'SELECT c.amount, c.contribution_date, c.status, c.reference, c.notes, pm.name AS payment_method
    FROM contributions c
     LEFT JOIN payment_methods pm ON pm.payment_method_id = c.payment_method_id
     WHERE c.member_id = :member_id
     ORDER BY c.contribution_date DESC, c.contribution_id DESC'
);
$stmt->execute(['member_id' => $user['user_id']]);
$contributions = $stmt->fetchAll();
$verifiedContributions = array_values(array_filter($contributions, static fn (array $contribution): bool => $contribution['status'] === 'recorded'));
$total = array_sum(array_map(static fn (array $contribution): float => (float) $contribution['amount'], $verifiedContributions));
$darajaStmt = db()->prepare(
    "SELECT transaction_reference, amount, status, checkout_request_id, request_result_description,
            callback_result_description, mpesa_receipt_number, created_at, verified_at
     FROM member_daraja_payments
     WHERE member_id = :member_id AND payment_type = 'contribution'
     ORDER BY created_at DESC, member_payment_id DESC
     LIMIT 10"
);
$darajaStmt->execute(['member_id' => $user['user_id']]);
$darajaPayments = $darajaStmt->fetchAll();

$pageTitle = 'Contributions | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="member-layout">
    <?php require ROOT_PATH . '/includes/member_sidebar.php'; ?>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Contributions</div>
        <div class="member-heading"><div><h1>My Contributions</h1><p>Track your chama contribution history.</p></div><a class="btn btn-outline-brand" href="<?php echo e(url('member/profile.php')); ?>">&#8592; Profile</a></div>
        <div class="admin-stat-grid contribution-stat-grid"><article class="admin-stat-card"><span class="admin-stat-icon">KES</span><div><span>Recorded Contributions</span><strong><?php echo count($verifiedContributions); ?></strong></div></article><article class="admin-stat-card"><span class="admin-stat-icon">KES</span><div><span>Total Amount</span><strong>KES <?php echo e(number_format($total, 2)); ?></strong></div></article></div>
        <article class="profile-panel contribution-form-panel">
            <h2>Pay Contribution with M-Pesa</h2>
            <?php if (!$darajaConfigured): ?><p class="alert alert-warning">M-Pesa Sandbox payments are unavailable. Ask the administrator to check the Daraja Sandbox configuration.</p><?php endif; ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="daraja_contribution">
                <input type="hidden" name="submission_key" value="<?php echo e((string) $_SESSION['member_daraja_contribution_submission_key']); ?>">
                <label>Amount (KES)<input type="number" name="amount" min="1" step="1" required></label>
                <label>Phone Number<input type="tel" name="phone_number" maxlength="20" placeholder="0712345678" required></label>
                <button class="btn btn-brand" type="submit" <?php echo $darajaConfigured ? '' : 'disabled'; ?>>Pay via M-Pesa</button>
            </form>
        </article>
        <article class="profile-panel table-panel mt-4">
            <h2>Recent M-Pesa payment requests</h2>
            <div class="table-responsive"><table><thead><tr><th>Date</th><th>Amount</th><th>Reference</th><th>Status</th><th>M-Pesa receipt</th><th>Details</th></tr></thead><tbody>
                <?php if ($darajaPayments): foreach ($darajaPayments as $payment): ?>
                    <tr>
                        <td><?php echo e((string) $payment['created_at']); ?></td>
                        <td>KES <?php echo e(number_format((float) $payment['amount'], 2)); ?></td>
                        <td><?php echo e((string) $payment['transaction_reference']); ?></td>
                        <td><span class="table-status"><?php echo e(daraja_payment_status_label((string) $payment['status'], !empty($payment['checkout_request_id']))); ?></span></td>
                        <td><?php echo e($payment['mpesa_receipt_number'] ?: 'Pending verification'); ?></td>
                        <td><?php echo e($payment['callback_result_description'] ?: $payment['request_result_description'] ?: 'Awaiting Daraja update.'); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6">No M-Pesa contribution requests yet.</td></tr>
                <?php endif; ?>
            </tbody></table></div>
            <p class="text-muted mb-0 mt-2">A contribution is added to your records only after Daraja confirms the payment and its callback details match.</p>
        </article>
        <article class="profile-panel table-panel"><table><thead><tr><th>Date</th><th>Amount</th><th>Payment Method</th><th>Reference</th><th>Notes</th><th>Status</th></tr></thead><tbody><?php if ($contributions): ?><?php foreach ($contributions as $contribution): ?><tr><td><?php echo e($contribution['contribution_date']); ?></td><td>KES <?php echo e(number_format((float) $contribution['amount'], 2)); ?></td><td><?php echo e($contribution['payment_method'] ?? 'Not specified'); ?></td><td><?php echo e($contribution['reference'] ?? '-'); ?></td><td><?php echo e($contribution['notes'] ?? '-'); ?></td><td><span class="table-status"><?php echo e(ucfirst($contribution['status'])); ?></span></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="6">No contributions have been recorded for your account yet.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
