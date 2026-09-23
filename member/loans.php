<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('member/loans.php');
    }

    $amount = (float) ($_POST['requested_amount'] ?? 0);
    $purpose = trim((string) ($_POST['purpose'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if ($amount <= 0 || $purpose === '') {
        flash_set('error', 'Enter a valid requested amount and loan purpose.');
        redirect('member/loans.php');
    }

    $stmt = db()->prepare(
        'INSERT INTO loans (member_id, requested_amount, purpose, notes, application_date)
         VALUES (:member_id, :requested_amount, :purpose, :notes, :application_date)'
    );
    $stmt->execute([
        'member_id' => $user['user_id'],
        'requested_amount' => $amount,
        'purpose' => $purpose,
        'notes' => $notes !== '' ? $notes : null,
        'application_date' => date('Y-m-d'),
    ]);
    flash_set('success', 'Your loan application has been submitted.');
    redirect('member/loans.php');
}

$stmt = db()->prepare(
    'SELECT loan_id, requested_amount, approved_amount, interest_rate, outstanding_balance, purpose, status, application_date, approval_date
     FROM loans WHERE member_id = :member_id ORDER BY loan_id DESC'
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
        <a class="member-nav-link" href="#"><span>&#9635;</span> Meetings</a>
        <a class="member-nav-link" href="#"><span>&#10022;</span> AI Advisor</a>
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Loans</div>
        <div class="member-heading"><div><h1>My Loans</h1><p>Apply for a loan and track its status.</p></div></div>
        <div class="contribution-layout">
            <article class="profile-panel contribution-form-panel"><h2>Apply for a Loan</h2><form method="post"><?php echo csrf_field(); ?><label>Requested Amount (KES)<input type="number" name="requested_amount" min="1" step="0.01" required></label><label>Purpose<input type="text" name="purpose" maxlength="255" required></label><label>Additional Notes <span class="text-muted">(optional)</span><textarea name="notes" maxlength="255"></textarea></label><button class="btn btn-brand" type="submit">Submit Application</button></form></article>
            <article class="profile-panel table-panel"><h2>Loan Applications</h2><table><thead><tr><th>Amount</th><th>Purpose</th><th>Date</th><th>Status</th><th>Details</th></tr></thead><tbody><?php if ($loans): ?><?php foreach ($loans as $loan): ?><tr><td>KES <?php echo e(number_format((float) $loan['requested_amount'], 2)); ?></td><td><?php echo e($loan['purpose']); ?></td><td><?php echo e($loan['application_date']); ?></td><td><?php echo e(ucfirst($loan['status'])); ?></td><td><a href="<?php echo e(url('member/loan.php?id=' . $loan['loan_id'])); ?>">View</a></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="5">You have not submitted a loan application.</td></tr><?php endif; ?></tbody></table></article>
        </div>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
