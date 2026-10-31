<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('admin/repayments.php');
    }

    if (($_POST['action'] ?? '') === 'correct') {
        $correctedAmount = filter_var($_POST['corrected_amount'] ?? null, FILTER_VALIDATE_FLOAT);
        try {
            if ($correctedAmount === false) {
                throw new InvalidArgumentException('Enter a valid corrected repayment amount.');
            }
            correct_loan_repayment(
                (int) ($_POST['repayment_id'] ?? 0),
                (float) $correctedAmount,
                trim((string) ($_POST['corrected_payment_date'] ?? '')),
                trim((string) ($_POST['correction_reason'] ?? '')),
                (int) current_user()['user_id']
            );
            flash_set('success', 'Repayment correction saved with an audit record and the loan balance recalculated.');
        } catch (InvalidArgumentException $exception) {
            flash_set('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Repayment correction failed: ' . $exception->getMessage());
            flash_set('error', 'The repayment correction could not be saved.');
        }
        redirect('admin/repayments.php');
    }

    $loanId = (int) ($_POST['loan_id'] ?? 0);
    $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    $date = trim((string) ($_POST['payment_date'] ?? ''));
    $paymentMethodId = (int) ($_POST['payment_method_id'] ?? 0);
    $reference = trim((string) ($_POST['reference'] ?? ''));
    $idempotencyKey = (string) ($_POST['idempotency_key'] ?? '');
    $dateValue = DateTime::createFromFormat('!Y-m-d', $date);

    if ($loanId < 1 || $amount === false || $amount <= 0 || !$dateValue ||
        $dateValue->format('Y-m-d') !== $date || $paymentMethodId < 1) {
        flash_set('error', 'Enter a valid loan, amount, date and payment method.');
        redirect('admin/repayments.php');
    }

    try {
        $recorded = record_loan_repayment(
            $loanId,
            (float) $amount,
            $date,
            $paymentMethodId,
            $reference,
            (int) current_user()['user_id'],
            $idempotencyKey
        );
        unset($_SESSION['repayment_submission_key']);
        flash_set($recorded ? 'success' : 'info', $recorded ? 'Repayment recorded and loan balance updated.' : 'This repayment submission was already processed.');
    } catch (InvalidArgumentException $exception) {
        flash_set('error', $exception->getMessage());
    } catch (Throwable $exception) {
        error_log('Repayment could not be recorded: ' . $exception->getMessage());
        flash_set('error', 'The repayment could not be recorded.');
    }
    redirect('admin/repayments.php');
}

if (empty($_SESSION['repayment_submission_key'])) {
    $_SESSION['repayment_submission_key'] = bin2hex(random_bytes(32));
}

$loans = db()->query(
    "SELECT l.loan_id, l.outstanding_balance, m.name
     FROM loans l INNER JOIN members m ON m.member_id = l.member_id
     WHERE l.status IN ('approved', 'overdue') AND l.outstanding_balance > 0
     ORDER BY m.name"
)->fetchAll();
$paymentMethods = db()->query('SELECT payment_method_id, name FROM payment_methods WHERE is_active = 1 ORDER BY name')->fetchAll();
$repayments = db()->query(
    'SELECT r.repayment_id, r.amount, r.payment_date, r.reference, m.name, r.loan_id, pm.name AS payment_method
     FROM loan_repayments r
     INNER JOIN loans l ON l.loan_id = r.loan_id
     INNER JOIN members m ON m.member_id = l.member_id
     LEFT JOIN payment_methods pm ON pm.payment_method_id = r.payment_method_id
     ORDER BY r.payment_date DESC, r.repayment_id DESC LIMIT 50'
)->fetchAll();

$pageTitle = 'Repayments | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="admin-layout">
    <aside class="admin-sidebar"><nav aria-label="Admin navigation">
        <a class="admin-nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/members.php')); ?>"><span>&#128101;</span> Members</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="admin-nav-link active" href="<?php echo e(url('admin/repayments.php')); ?>"><span>&#8646;</span> Repayments</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
    </nav><a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Loan Management</p><h1>Repayments</h1><p>Record repayments and keep loan balances current.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <div class="contribution-layout">
            <article class="admin-panel contribution-form-panel"><h2>Record Repayment</h2><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="idempotency_key" value="<?php echo e((string) $_SESSION['repayment_submission_key']); ?>"><label>Loan<select name="loan_id" required><option value="">Select loan</option><?php foreach ($loans as $loan): ?><option value="<?php echo e((string) $loan['loan_id']); ?>">#<?php echo e((string) $loan['loan_id']); ?> - <?php echo e($loan['name']); ?> (KES <?php echo e(number_format((float) $loan['outstanding_balance'], 2)); ?> remaining)</option><?php endforeach; ?></select></label><label>Amount (KES)<input type="number" name="amount" min="0.01" step="0.01" required></label><label>Payment Date<input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required></label><label>Payment Method<select name="payment_method_id" required><option value="">Select payment method</option><?php foreach ($paymentMethods as $method): ?><option value="<?php echo e((string) $method['payment_method_id']); ?>"><?php echo e($method['name']); ?></option><?php endforeach; ?></select></label><label>Reference <span class="text-muted">(optional)</span><input type="text" name="reference" maxlength="80"></label><button class="btn btn-brand" type="submit">Record Repayment</button></form></article>
            <article class="admin-panel table-panel"><h2>Repayment History</h2><table><thead><tr><th>Member</th><th>Loan</th><th>Amount</th><th>Date</th><th>Method</th><th>Reference</th><th>Correction</th></tr></thead><tbody><?php if ($repayments): ?><?php foreach ($repayments as $repayment): ?><tr><td><?php echo e($repayment['name']); ?></td><td>#<?php echo e((string) $repayment['loan_id']); ?></td><td>KES <?php echo e(number_format((float) $repayment['amount'], 2)); ?></td><td><?php echo e($repayment['payment_date']); ?></td><td><?php echo e($repayment['payment_method'] ?? 'Not specified'); ?></td><td><?php echo e($repayment['reference'] ?? '-'); ?></td><td><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="correct"><input type="hidden" name="repayment_id" value="<?php echo e((string) $repayment['repayment_id']); ?>"><label>Correct amount (KES)<input type="number" name="corrected_amount" min="0.01" step="0.01" value="<?php echo e((string) $repayment['amount']); ?>" required></label><label>Correct payment date<input type="date" name="corrected_payment_date" value="<?php echo e($repayment['payment_date']); ?>" required></label><label>Reason<input type="text" name="correction_reason" maxlength="255" required></label><button type="submit">Save Correction</button></form></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="7">No repayments recorded yet.</td></tr><?php endif; ?></tbody></table></article>
        </div>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
