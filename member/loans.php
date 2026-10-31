<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/loans.php';
require_member();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('member/loans.php');
    }

    $amount = filter_var($_POST['requested_amount'] ?? null, FILTER_VALIDATE_FLOAT);
    $purpose = trim((string) ($_POST['purpose'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $submissionKey = (string) ($_POST['submission_key'] ?? '');
    if ($amount === false || $amount <= 0 || $purpose === '' || strlen($purpose) > 255) {
        flash_set('error', 'Enter a valid requested amount and loan purpose.');
        redirect('member/loans.php');
    }

    try {
        $application = submit_loan_application(
            (int) $user['user_id'],
            (float) $amount,
            $purpose,
            $notes !== '' ? $notes : null,
            $submissionKey
        );
    } catch (InvalidArgumentException $exception) {
        flash_set('error', $exception->getMessage());
        redirect('member/loans.php');
    }
    unset($_SESSION['loan_application_submission_key']);

    if (!$application['eligible']) {
        flash_set('error', 'Your loan application was automatically rejected: ' . implode(' ', $application['reasons']));
    } else {
        $message = 'Your loan application is eligible and is awaiting the existing admin approval process.';
        if ($application['warnings']) {
            $message .= ' ' . implode(' ', $application['warnings']);
        }
        flash_set('success', $message);
    }
    redirect('member/loans.php');
}

if (empty($_SESSION['loan_application_submission_key'])) {
    $_SESSION['loan_application_submission_key'] = bin2hex(random_bytes(32));
}

$stmt = db()->prepare(
    'SELECT l.loan_id, l.requested_amount, l.approved_amount, l.interest_rate, l.outstanding_balance, l.due_date,
            l.purpose, l.status, l.rejection_reason, l.application_date, l.approval_date, COALESCE(p.amount, 0) AS overdue_penalty,
            p.status AS penalty_status, p.reason AS penalty_reason
     FROM loans l
     LEFT JOIN penalties p ON p.loan_id = l.loan_id
     WHERE l.member_id = :member_id
     ORDER BY l.loan_id DESC'
);
$stmt->execute(['member_id' => $user['user_id']]);
$loans = $stmt->fetchAll();

$pageTitle = 'Loans | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar"><nav aria-label="Member navigation">
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="member-nav-link" href="<?php echo e(url('member/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="member-nav-link active" href="<?php echo e(url('member/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="member-nav-link" href="<?php echo e(url('member/repayments.php')); ?>"><span>&#8646;</span> Loan Repayment</a>
        <a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="member-nav-link" href="<?php echo e(url('member/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
        <a class="member-nav-link" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span>&#10022;</span> AI Advisor</a>
        <a class="member-nav-link" href="<?php echo e(url('member/profile.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Loans</div>
        <div class="member-heading"><div><h1>My Loans</h1><p>Apply for a loan and track its status.</p></div></div>
        <div class="contribution-layout">
            <article class="profile-panel contribution-form-panel"><h2>Apply for a Loan</h2><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="submission_key" value="<?php echo e((string) $_SESSION['loan_application_submission_key']); ?>"><label>Requested Amount (KES)<input type="number" name="requested_amount" min="0.01" step="0.01" required></label><label>Purpose<input type="text" name="purpose" maxlength="255" required></label><label>Additional Notes <span class="text-muted">(optional)</span><textarea name="notes" maxlength="255"></textarea></label><button class="btn btn-brand" type="submit">Submit Application</button></form></article>
                <article class="profile-panel table-panel"><h2>Loan Applications</h2><table><thead><tr><th>Amount</th><th>Purpose</th><th>Due Date</th><th>Status</th><th>Penalty</th><th>Details</th></tr></thead><tbody><?php if ($loans): ?><?php foreach ($loans as $loan): ?><tr><td>KES <?php echo e(number_format((float) $loan['requested_amount'], 2)); ?></td><td><?php echo e($loan['purpose']); ?></td><td><?php echo e($loan['due_date'] ?? 'Not set'); ?></td><td><?php echo e(ucfirst($loan['status'])); ?></td><td><?php if ((float) $loan['overdue_penalty'] > 0): ?>KES <?php echo e(number_format((float) $loan['overdue_penalty'], 2)); ?><?php else: ?>-<?php endif; ?></td><td><a href="<?php echo e(url('member/loan.php?id=' . $loan['loan_id'])); ?>">View</a><?php if ($loan['status'] === 'rejected' && !empty($loan['rejection_reason'])): ?><small class="d-block text-danger"><?php echo e($loan['rejection_reason']); ?></small><?php endif; ?></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="6">You have not submitted a loan application.</td></tr><?php endif; ?></tbody></table></article>
        </div>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
