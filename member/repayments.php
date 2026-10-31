<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();
$darajaConfigured = daraja_sandbox_is_configured();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('member/repayments.php');
    }

    if (!$darajaConfigured) {
        flash_set('error', 'Daraja Sandbox is not configured. Ask the administrator to check the sandbox credentials and callback URL.');
        redirect('member/repayments.php');
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'daraja_repayment') {
            $payment = start_member_daraja_payment(
                (int) $user['user_id'],
                'loan_repayment',
                (int) ($_POST['loan_id'] ?? 0),
                (string) ($_POST['amount'] ?? ''),
                trim((string) ($_POST['phone_number'] ?? '')),
                (string) ($_POST['submission_key'] ?? '')
            );
            unset($_SESSION['member_daraja_repayment_submission_key']);
            flash_set($payment['status'] === 'failed' ? 'error' : 'info', $payment['message']);
        } else {
            throw new InvalidArgumentException('Choose a valid repayment action.');
        }
    } catch (InvalidArgumentException $exception) {
        flash_set('error', $exception->getMessage());
    }
    redirect('member/repayments.php');
}

if (empty($_SESSION['member_daraja_repayment_submission_key'])) {
    $_SESSION['member_daraja_repayment_submission_key'] = bin2hex(random_bytes(32));
}

$loanOptions = db()->prepare(
    'SELECT loan_id, outstanding_balance, purpose FROM loans WHERE member_id = :member_id AND status IN (\'approved\', \'overdue\') AND outstanding_balance > 0 ORDER BY loan_id DESC'
);
$loanOptions->execute(['member_id' => $user['user_id']]);
$loanOptions = $loanOptions->fetchAll();

$stmt = db()->prepare(
    'SELECT r.amount, r.payment_date, r.reference, r.loan_id, pm.name AS payment_method,
            l.requested_amount, l.approved_amount, l.outstanding_balance, l.status
     FROM loan_repayments r
     INNER JOIN loans l ON l.loan_id = r.loan_id AND l.member_id = :member_id
     LEFT JOIN payment_methods pm ON pm.payment_method_id = r.payment_method_id
     ORDER BY r.payment_date DESC, r.repayment_id DESC'
);
$stmt->execute(['member_id' => $user['user_id']]);
$repayments = $stmt->fetchAll();
$total = array_sum(array_map(static fn (array $repayment): float => (float) $repayment['amount'], $repayments));
$darajaStmt = db()->prepare(
    "SELECT p.transaction_reference, p.related_id, p.amount, p.status,
            p.request_result_description, p.callback_result_description,
            p.mpesa_receipt_number, p.created_at, p.verified_at, l.purpose
     FROM member_daraja_payments p
     LEFT JOIN loans l ON l.loan_id = p.related_id AND l.member_id = p.member_id
     WHERE p.member_id = :member_id AND p.payment_type = 'loan_repayment'
     ORDER BY p.created_at DESC, p.member_payment_id DESC
     LIMIT 10"
);
$darajaStmt->execute(['member_id' => $user['user_id']]);
$darajaPayments = $darajaStmt->fetchAll();

$pageTitle = 'Repayments | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar"><nav aria-label="Member navigation">
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="member-nav-link" href="<?php echo e(url('member/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="member-nav-link" href="<?php echo e(url('member/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="member-nav-link active" href="<?php echo e(url('member/repayments.php')); ?>"><span>&#8646;</span> Loan Repayment</a>
        <a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="member-nav-link" href="<?php echo e(url('member/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
        <a class="member-nav-link" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span>&#10022;</span> AI Advisor</a>
        <a class="member-nav-link" href="<?php echo e(url('member/profile.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Loan Repayment</div>
        <div class="member-heading"><div><h1>Loan Repayments</h1><p>Review repayments recorded against your loans.</p></div><span class="pill">Total paid: KES <?php echo e(number_format($total, 2)); ?></span></div>
        <article class="profile-panel contribution-form-panel">
            <h2>Pay Loan with M-Pesa</h2>
            <?php if (!$darajaConfigured): ?><p class="alert alert-warning">M-Pesa Sandbox payments are unavailable. Ask the administrator to check the Daraja Sandbox configuration.</p><?php endif; ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="daraja_repayment">
                <input type="hidden" name="submission_key" value="<?php echo e((string) $_SESSION['member_daraja_repayment_submission_key']); ?>">
                <label>Loan<select name="loan_id" required><option value="">Select loan</option><?php foreach ($loanOptions as $loan): ?><option value="<?php echo e((string) $loan['loan_id']); ?>">#<?php echo e((string) $loan['loan_id']); ?> - KES <?php echo e(number_format((float) $loan['outstanding_balance'], 2)); ?> remaining</option><?php endforeach; ?></select></label>
                <label>Amount (KES)<input type="number" name="amount" min="1" step="1" required></label>
                <label>Phone Number<input type="tel" name="phone_number" maxlength="20" placeholder="0712345678" required></label>
                <button class="btn btn-brand" type="submit" <?php echo $darajaConfigured ? '' : 'disabled'; ?>>Pay via M-Pesa</button>
            </form>
        </article>
        <article class="profile-panel table-panel mt-4">
            <h2>Recent M-Pesa repayment requests</h2>
            <div class="table-responsive"><table><thead><tr><th>Date</th><th>Loan</th><th>Amount</th><th>Status</th><th>M-Pesa receipt</th><th>Details</th></tr></thead><tbody>
                <?php if ($darajaPayments): foreach ($darajaPayments as $payment): ?>
                    <tr>
                        <td><?php echo e((string) $payment['created_at']); ?></td>
                        <td>#<?php echo e((string) $payment['related_id']); ?><?php if (!empty($payment['purpose'])): ?><small class="d-block"><?php echo e((string) $payment['purpose']); ?></small><?php endif; ?></td>
                        <td>KES <?php echo e(number_format((float) $payment['amount'], 2)); ?></td>
                        <td><span class="table-status"><?php echo e(ucfirst((string) $payment['status'])); ?></span></td>
                        <td><?php echo e($payment['mpesa_receipt_number'] ?: 'Pending verification'); ?></td>
                        <td><?php echo e($payment['callback_result_description'] ?: $payment['request_result_description'] ?: 'Awaiting Daraja update.'); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6">No M-Pesa repayment requests yet.</td></tr>
                <?php endif; ?>
            </tbody></table></div>
            <p class="text-muted mb-0 mt-2">A loan repayment is recorded only after Daraja confirms the payment and its callback details match.</p>
        </article>
        <article class="profile-panel table-panel"><table><thead><tr><th>Loan</th><th>Repayment Amount</th><th>Payment Date</th><th>Payment Method</th><th>Reference</th><th>Remaining Balance</th></tr></thead><tbody><?php if ($repayments): ?><?php foreach ($repayments as $repayment): ?><tr><td>#<?php echo e((string) $repayment['loan_id']); ?></td><td>KES <?php echo e(number_format((float) $repayment['amount'], 2)); ?></td><td><?php echo e($repayment['payment_date']); ?></td><td><?php echo e($repayment['payment_method'] ?? 'Not specified'); ?></td><td><?php echo e($repayment['reference'] ?? '-'); ?></td><td><?php echo $repayment['outstanding_balance'] === null ? 'Pending' : 'KES ' . e(number_format((float) $repayment['outstanding_balance'], 2)); ?></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="6">No repayments have been recorded for your loans.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
