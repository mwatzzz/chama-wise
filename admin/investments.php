<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

$categories = investment_categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('admin/investments.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'reconcile_payment') {
        try {
            $result = reconcile_daraja_investment_payment(
                (int) ($_POST['payment_id'] ?? 0),
                (int) current_user()['user_id']
            );
            flash_set('info', $result['message']);
        } catch (InvalidArgumentException $exception) {
            flash_set('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Daraja investment reconciliation failed: ' . $exception->getMessage());
            flash_set('error', 'Daraja could not verify this payment. It remains uncredited and may be reconciled again.');
        }
        redirect('admin/investments.php');
    }

    if ($action === 'reallocate_payment') {
        $target = explode(':', (string) ($_POST['allocation_target'] ?? ''), 2);
        if (count($target) !== 2 || !in_array($target[0], ['goal', 'opportunity', 'group_plan'], true) ||
            !ctype_digit($target[1]) || (int) $target[1] < 1) {
            flash_set('error', 'Choose a valid goal, opportunity or group plan allocation target.');
            redirect('admin/investments.php');
        }
        try {
            reallocate_investment_payment(
                (int) ($_POST['payment_id'] ?? 0),
                $target[0] === 'goal' ? (int) $target[1] : null,
                $target[0] === 'opportunity' ? (int) $target[1] : null,
                $target[0] === 'group_plan' ? (int) $target[1] : null,
                trim((string) ($_POST['reason'] ?? '')),
                (int) current_user()['user_id']
            );
            flash_set('success', 'Verified payment allocation updated and audited.');
        } catch (InvalidArgumentException $exception) {
            flash_set('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Daraja investment allocation update failed: ' . $exception->getMessage());
            flash_set('error', 'The investment allocation could not be updated.');
        }
        redirect('admin/investments.php');
    }

    if ($action === 'decide_proposal') {
        $proposalId = (int) ($_POST['proposal_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($proposalId < 1 || !in_array($decision, ['approved', 'rejected'], true) ||
            $reason === '' || mb_strlen($reason) > 255) {
            flash_set('error', 'Choose a valid proposal decision and enter a reason.');
            redirect('admin/investments.php');
        }
        $stmt = db()->prepare(
            'UPDATE group_investment_proposals
             SET status = :status, decision_reason = :reason, decided_by = :admin_id, decided_at = NOW()
             WHERE proposal_id = :proposal_id AND status = \'pending\''
        );
        $stmt->execute([
            'status' => $decision,
            'reason' => $reason,
            'admin_id' => (int) current_user()['user_id'],
            'proposal_id' => $proposalId,
        ]);
        flash_set(
            $stmt->rowCount() === 1 ? 'success' : 'error',
            $stmt->rowCount() === 1 ? 'Group investment proposal decision recorded.' : 'Proposal was not found or has already been decided.'
        );
        redirect('admin/investments.php');
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['opportunity_id'] ?? 0);
        $stmt = db()->prepare('UPDATE investment_opportunities SET is_active = IF(is_active = 1, 0, 1) WHERE opportunity_id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() !== 1) {
            flash_set('error', 'Investment opportunity was not found or its status did not change.');
        } else {
            flash_set('success', 'Investment opportunity status updated.');
        }
        redirect('admin/investments.php');
    }

    if (!in_array($action, ['create', 'save_opportunity'], true)) {
        flash_set('error', 'Choose a valid investment opportunity action.');
        redirect('admin/investments.php');
    }

    try {
        $opportunityId = $action === 'save_opportunity' ? (int) ($_POST['opportunity_id'] ?? 0) : null;
        save_investment_opportunity($_POST, (int) current_user()['user_id'], $opportunityId);
        flash_set('success', $action === 'create'
            ? 'Investment opportunity added. It is informational only and does not represent a recommendation or guarantee.'
            : 'Investment opportunity updated.');
    } catch (InvalidArgumentException $exception) {
        flash_set('error', $exception->getMessage());
    } catch (Throwable $exception) {
        error_log('Investment opportunity update failed: ' . $exception->getMessage());
        flash_set('error', 'The investment opportunity could not be saved.');
    }
    redirect('admin/investments.php');
}

$opportunities = db()->query(
    'SELECT opportunity_id, title, category, description, risk_level, investment_period, liquidity,
            minimum_amount, risks, source_url, verified_at, annual_return_assumption,
            verification_status, is_active, created_at
     FROM investment_opportunities
     ORDER BY is_active DESC, category, title'
)->fetchAll();
$summary = db()->query(
    "SELECT COUNT(DISTINCT g.goal_id) AS goal_count,
            COUNT(DISTINCT g.member_id) AS member_count,
            COALESCE(SUM(CASE WHEN t.transaction_type = 'contribution' THEN t.amount ELSE -t.amount END), 0) AS simulated_capital
     FROM investment_goals g
     LEFT JOIN investment_transactions t ON t.goal_id = g.goal_id"
)->fetch();
$proposals = db()->query(
    'SELECT p.proposal_id, p.title, p.category, p.description, p.simulated_amount, p.status,
            p.decision_reason, p.created_at, m.name AS member_name
     FROM group_investment_proposals p
     INNER JOIN members m ON m.member_id = p.submitted_by
     ORDER BY CASE WHEN p.status = \'pending\' THEN 0 ELSE 1 END, p.proposal_id DESC'
)->fetchAll();
$paymentSummary = db()->query(
    "SELECT COUNT(*) AS payment_count,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'successful' THEN 1 ELSE 0 END) AS successful_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
            COALESCE(SUM(CASE WHEN status = 'successful' THEN amount ELSE 0 END), 0) AS successful_total
     FROM investment_payments"
)->fetch();
$investmentPayments = db()->query(
    'SELECT p.payment_id, p.member_id, p.goal_id, p.opportunity_id, p.group_plan_id, p.transaction_reference,
            p.amount, p.phone_number, p.status, p.merchant_request_id, p.checkout_request_id,
            p.mpesa_receipt_number, p.request_result_code, p.request_result_description,
            p.callback_result_code, p.callback_result_description, p.callback_amount,
            p.callback_phone_number, p.callback_receipt_number, p.callback_received_at,
            p.callback_payload_hash, p.verified_at, p.created_at,
            m.name AS member_name, m.email AS member_email,
            COALESCE(allocated_goal.title, requested_goal.title, allocated_opportunity.title, requested_opportunity.title, allocated_group_plan.title, requested_group_plan.title, \'Unallocated\') AS investment_title,
            a.goal_id AS allocated_goal_id, a.opportunity_id AS allocated_opportunity_id, a.group_plan_id AS allocated_group_plan_id,
            a.amount AS allocated_amount, a.reason AS allocation_reason,
            a.allocated_by_admin AS allocation_admin_id
     FROM investment_payments p
     INNER JOIN members m ON m.member_id = p.member_id
     LEFT JOIN investment_payment_allocations a ON a.payment_id = p.payment_id
     LEFT JOIN investment_goals requested_goal ON requested_goal.goal_id = p.goal_id
     LEFT JOIN investment_goals allocated_goal ON allocated_goal.goal_id = a.goal_id
     LEFT JOIN investment_opportunities requested_opportunity ON requested_opportunity.opportunity_id = p.opportunity_id
     LEFT JOIN investment_opportunities allocated_opportunity ON allocated_opportunity.opportunity_id = a.opportunity_id
     LEFT JOIN investment_group_plans requested_group_plan ON requested_group_plan.plan_id = p.group_plan_id
     LEFT JOIN investment_group_plans allocated_group_plan ON allocated_group_plan.plan_id = a.group_plan_id
     ORDER BY CASE WHEN p.status = \'pending\' THEN 0 WHEN p.status = \'failed\' THEN 1 ELSE 2 END, p.payment_id DESC'
)->fetchAll();
$allocationGoals = db()->query(
    "SELECT g.goal_id, g.member_id, g.title, m.name AS member_name
     FROM investment_goals g INNER JOIN members m ON m.member_id = g.member_id
     WHERE g.status = 'active' ORDER BY m.name, g.title"
)->fetchAll();
$allocationOpportunities = db()->query(
    'SELECT opportunity_id, title, category FROM investment_opportunities ORDER BY is_active DESC, title'
)->fetchAll();
$allocationGroupPlans = db()->query(
    "SELECT plan_id, title, status FROM investment_group_plans WHERE status <> 'completed' ORDER BY title"
)->fetchAll();

$pageTitle = 'Investment Planning | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="admin-layout">
    <aside class="admin-sidebar"><nav aria-label="Admin navigation">
        <a class="admin-nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/members.php')); ?>"><span>&#128101;</span> Members</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/repayments.php')); ?>"><span>&#8646;</span> Repayments</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/reports.php')); ?>"><span>&#9646;</span> Reports</a>
        <a class="admin-nav-link active" href="<?php echo e(url('admin/investments.php')); ?>"><span>&#9679;</span> Investments</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/settings.php')); ?>"><span>&#9881;</span> Settings</a>
    </nav><a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Simulation only</p><h1>Investment Planning</h1><p>Manage informational opportunities and view authorized aggregate simulated figures.</p></div></div>
        <div class="contribution-layout">
            <article class="admin-panel">
                <h2>Aggregate simulated summary</h2>
                <p>Plans: <strong><?php echo e((string) $summary['goal_count']); ?></strong></p>
                <p>Members with plans: <strong><?php echo e((string) $summary['member_count']); ?></strong></p>
                <p class="mb-0">Net simulated capital: <strong>KES <?php echo e(number_format((float) $summary['simulated_capital'], 2)); ?></strong></p>
            </article>
            <article class="admin-panel">
                <h2>Daraja Sandbox payments</h2>
                <p>Transactions: <strong><?php echo e((string) $paymentSummary['payment_count']); ?></strong></p>
                <p>Pending: <strong><?php echo e((string) $paymentSummary['pending_count']); ?></strong> · Failed: <strong><?php echo e((string) $paymentSummary['failed_count']); ?></strong></p>
                <p class="mb-0">Verified payments: <strong><?php echo e((string) $paymentSummary['successful_count']); ?></strong> · KES <?php echo e(number_format((float) $paymentSummary['successful_total'], 2)); ?></p>
            </article>
            <article class="admin-panel contribution-form-panel">
                <h2>Add an opportunity</h2>
                <p>Do not enter an unverified provider rate as a current or guaranteed return.</p>
                <form method="post">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="create">
                    <label>Title<input type="text" name="title" maxlength="160" required></label>
                    <label>Category<select name="category" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?php echo e($category); ?>"><?php echo e($category); ?></option><?php endforeach; ?></select></label>
                    <label>Description<textarea name="description" maxlength="1000" required></textarea></label>
                    <label>Risk level<select name="risk_level"><option value="unknown">Unknown / not assessed</option><option value="low">Low</option><option value="moderate">Moderate</option><option value="high">High</option></select></label>
                    <label>Investment period<input type="text" name="investment_period" maxlength="120" placeholder="Unavailable unless confirmed"></label>
                    <label>Liquidity information<input type="text" name="liquidity" maxlength="120" placeholder="Unavailable unless confirmed"></label>
                    <label>Minimum amount (KES; optional)<input type="number" name="minimum_amount" min="0" step="0.01"></label>
                    <label>Risks and considerations<textarea name="risks" maxlength="1000"></textarea></label>
                    <label>Source URL (optional, required with a verification date)<input type="url" name="source_url" maxlength="500"></label>
                    <label>Last verified (optional, required with a source URL)<input type="date" name="verified_at"></label>
                    <label>Information status<select name="verification_status"><option value="unverified">Unverified</option><option value="verified">Verified</option><option value="expired">Expired</option></select></label>
                    <label>Annual return assumption (%) (optional; requires verified source)<input type="number" name="annual_return_assumption" min="-100" max="100" step="0.001"></label>
                    <button class="btn btn-brand" type="submit">Add Opportunity</button>
                </form>
            </article>
        </div>
        <article class="admin-panel table-panel mt-4">
            <h2>Investment opportunities</h2>
            <table><thead><tr><th>Opportunity</th><th>Category / risk</th><th>Source / verification</th><th>Annual assumption</th><th>Status</th><th>Manage</th></tr></thead><tbody>
                <?php if ($opportunities): foreach ($opportunities as $opportunity): ?>
                    <tr>
                        <td><strong><?php echo e($opportunity['title']); ?></strong><small class="d-block"><?php echo e($opportunity['description']); ?></small><small class="d-block">Period: <?php echo e($opportunity['investment_period'] ?: 'Unavailable'); ?> · Liquidity: <?php echo e($opportunity['liquidity'] ?: 'Unavailable'); ?></small><small class="d-block">Minimum: <?php echo $opportunity['minimum_amount'] === null ? 'Unavailable' : 'KES ' . e(number_format((float) $opportunity['minimum_amount'], 2)); ?></small><small class="d-block">Risks: <?php echo e($opportunity['risks'] ?: 'Not supplied'); ?></small></td>
                        <td><?php echo e($opportunity['category']); ?><small class="d-block">Risk: <?php echo e(ucfirst($opportunity['risk_level'])); ?></small></td>
                        <td><?php if ($opportunity['source_url']): ?><a href="<?php echo e($opportunity['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($opportunity['source_url']); ?></a><?php else: ?>No source supplied<?php endif; ?><small class="d-block"><?php echo e(ucfirst($opportunity['verification_status'])); ?><?php if ($opportunity['verified_at']): ?> · <?php echo e($opportunity['verified_at']); ?><?php endif; ?></small></td>
                        <td><?php echo $opportunity['annual_return_assumption'] === null ? 'Not set' : e((string) $opportunity['annual_return_assumption']) . '% estimate'; ?></td>
                        <td><?php echo $opportunity['is_active'] ? 'Active' : 'Inactive'; ?></td>
                        <td>
                            <details><summary>Edit opportunity</summary>
                                <form method="post" class="mt-3">
                                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="save_opportunity"><input type="hidden" name="opportunity_id" value="<?php echo e((string) $opportunity['opportunity_id']); ?>">
                                    <label>Title<input type="text" name="title" maxlength="160" value="<?php echo e($opportunity['title']); ?>" required></label>
                                    <label>Category<select name="category" required><?php foreach ($categories as $category): ?><option value="<?php echo e($category); ?>" <?php echo $opportunity['category'] === $category ? 'selected' : ''; ?>><?php echo e($category); ?></option><?php endforeach; ?></select></label>
                                    <label>Description<textarea name="description" maxlength="1000" required><?php echo e($opportunity['description']); ?></textarea></label>
                                    <label>Risk level<select name="risk_level"><?php foreach (['unknown' => 'Unknown / not assessed', 'low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High'] as $value => $label): ?><option value="<?php echo e($value); ?>" <?php echo $opportunity['risk_level'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
                                    <label>Investment period<input type="text" name="investment_period" maxlength="120" value="<?php echo e($opportunity['investment_period'] ?? ''); ?>"></label>
                                    <label>Liquidity<input type="text" name="liquidity" maxlength="120" value="<?php echo e($opportunity['liquidity'] ?? ''); ?>"></label>
                                    <label>Minimum amount (KES)<input type="number" name="minimum_amount" min="0" step="0.01" value="<?php echo e($opportunity['minimum_amount'] ?? ''); ?>"></label>
                                    <label>Risks<textarea name="risks" maxlength="1000"><?php echo e($opportunity['risks'] ?? ''); ?></textarea></label>
                                    <label>Source URL<input type="url" name="source_url" maxlength="500" value="<?php echo e($opportunity['source_url'] ?? ''); ?>"></label>
                                    <label>Last verified<input type="date" name="verified_at" max="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e($opportunity['verified_at'] ?? ''); ?>"></label>
                                    <label>Verification status<select name="verification_status"><?php foreach (['verified' => 'Verified', 'unverified' => 'Unverified', 'expired' => 'Expired'] as $value => $label): ?><option value="<?php echo e($value); ?>" <?php echo $opportunity['verification_status'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
                                    <label>Annual return assumption (%)<input type="number" name="annual_return_assumption" min="-100" max="100" step="0.001" value="<?php echo e($opportunity['annual_return_assumption'] ?? ''); ?>"></label>
                                    <label>Opportunity status<select name="is_active"><option value="1" <?php echo $opportunity['is_active'] ? 'selected' : ''; ?>>Active</option><option value="0" <?php echo !$opportunity['is_active'] ? 'selected' : ''; ?>>Inactive</option></select></label>
                                    <button class="btn btn-brand" type="submit">Save Opportunity</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6">No opportunities have been added.</td></tr>
                <?php endif; ?>
            </tbody></table>
        </article>
        <article class="admin-panel table-panel mt-4">
            <h2>Investment payments and reconciliation</h2>
            <p>Only payments confirmed by the Daraja Sandbox transaction query and matching callback amount, phone and receipt are credited. Admins cannot manually mark a transaction successful.</p>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Member / investment</th><th>Amount / status</th><th>Daraja references</th><th>Callback verification</th><th>Reconcile / allocation</th></tr></thead>
                    <tbody>
                    <?php if ($investmentPayments): foreach ($investmentPayments as $payment): ?>
                        <?php
                        $auditStmt = db()->prepare(
                            'SELECT action, result_code, result_description, reason, admin_id, created_at
                             FROM investment_payment_audit WHERE payment_id = :id ORDER BY audit_id DESC'
                        );
                        $auditStmt->execute(['id' => $payment['payment_id']]);
                        $paymentAudit = $auditStmt->fetchAll();
                        ?>
                        <tr>
                            <td><strong><?php echo e($payment['member_name']); ?></strong><small class="d-block"><?php echo e($payment['member_email']); ?></small><small class="d-block"><?php echo e($payment['investment_title']); ?></small><small class="d-block">Created: <?php echo e($payment['created_at']); ?></small></td>
                            <td>KES <?php echo e(number_format((float) $payment['amount'], 2)); ?><span class="badge text-bg-<?php echo $payment['status'] === 'successful' ? 'success' : ($payment['status'] === 'failed' ? 'danger' : 'warning'); ?> d-block mt-1"><?php echo e(ucfirst($payment['status'])); ?></span><?php if ($payment['verified_at']): ?><small class="d-block">Verified: <?php echo e($payment['verified_at']); ?></small><?php endif; ?></td>
                            <td><strong><?php echo e($payment['transaction_reference']); ?></strong><small class="d-block">Phone: <?php echo e($payment['phone_number']); ?></small><small class="d-block">Checkout: <?php echo e($payment['checkout_request_id'] ?: 'Not available'); ?></small><small class="d-block">Receipt: <?php echo e($payment['mpesa_receipt_number'] ?: 'Not verified'); ?></small><small class="d-block">Request: <?php echo e(($payment['request_result_code'] ?? '—') . ' ' . ($payment['request_result_description'] ?? '')); ?></small></td>
                            <td><?php if ($payment['callback_received_at']): ?><small class="d-block">Received: <?php echo e($payment['callback_received_at']); ?></small><small class="d-block">Result: <?php echo e(($payment['callback_result_code'] ?? '—') . ' ' . ($payment['callback_result_description'] ?? '')); ?></small><small class="d-block">Amount: <?php echo $payment['callback_amount'] === null ? 'Missing' : 'KES ' . e(number_format((float) $payment['callback_amount'], 2)); ?></small><small class="d-block">Phone: <?php echo e($payment['callback_phone_number'] ?: 'Missing'); ?> · Receipt: <?php echo e($payment['callback_receipt_number'] ?: 'Missing'); ?></small><small class="d-block">Payload SHA-256: <?php echo e($payment['callback_payload_hash'] ?: '—'); ?></small><?php else: ?>No callback received<?php endif; ?></td>
                            <td>
                                <?php if ($payment['checkout_request_id'] && $payment['status'] !== 'successful'): ?>
                                    <form method="post" class="mb-2"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reconcile_payment"><input type="hidden" name="payment_id" value="<?php echo e((string) $payment['payment_id']); ?>"><button class="btn btn-outline-brand" type="submit">Query Daraja status</button></form>
                                <?php endif; ?>
                                <?php if ($payment['status'] === 'successful'): ?>
                                    <small class="d-block mb-1">Allocated: <?php echo e($payment['allocation_reason'] ?: 'Not allocated'); ?></small>
                                    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reallocate_payment"><input type="hidden" name="payment_id" value="<?php echo e((string) $payment['payment_id']); ?>">
                                        <label>Authorized allocation<select name="allocation_target" required>
                                            <option value="">Choose target</option>
                                            <?php foreach ($allocationGoals as $goal): ?><option value="goal:<?php echo e((string) $goal['goal_id']); ?>" <?php echo (int) $payment['allocated_goal_id'] === (int) $goal['goal_id'] ? 'selected' : ''; ?>>Goal · <?php echo e($goal['member_name'] . ' — ' . $goal['title']); ?></option><?php endforeach; ?>
                                            <?php foreach ($allocationOpportunities as $opportunity): ?><option value="opportunity:<?php echo e((string) $opportunity['opportunity_id']); ?>" <?php echo (int) $payment['allocated_opportunity_id'] === (int) $opportunity['opportunity_id'] ? 'selected' : ''; ?>>Opportunity · <?php echo e($opportunity['title']); ?></option><?php endforeach; ?>
                                            <?php foreach ($allocationGroupPlans as $groupPlan): ?><option value="group_plan:<?php echo e((string) $groupPlan['plan_id']); ?>" <?php echo (int) ($payment['allocated_group_plan_id'] ?? 0) === (int) $groupPlan['plan_id'] ? 'selected' : ''; ?>>Group plan · <?php echo e($groupPlan['title']); ?></option><?php endforeach; ?>
                                        </select></label>
                                        <label>Reason for allocation change<input type="text" name="reason" maxlength="255" required></label>
                                        <button class="btn btn-outline-brand" type="submit">Save allocation</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($paymentAudit): ?><details class="mt-2"><summary>Audit trail (<?php echo e((string) count($paymentAudit)); ?>)</summary><?php foreach ($paymentAudit as $audit): ?><small class="d-block mt-1"><?php echo e($audit['created_at'] . ' · ' . $audit['action'] . ' · ' . ($audit['reason'] ?? $audit['result_description'] ?? '')); ?><?php if ($audit['admin_id']): ?> · Admin #<?php echo e((string) $audit['admin_id']); ?><?php endif; ?></small><?php endforeach; ?></details><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5">No investment payment requests have been made.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <article class="admin-panel table-panel mt-4">
            <h2>Simulated group proposals</h2>
            <p>Approvals are planning decisions only and do not authorize real-money transactions.</p>
            <table><thead><tr><th>Member</th><th>Proposal</th><th>Requested simulation</th><th>Status</th><th>Decision</th></tr></thead><tbody>
                <?php if ($proposals): foreach ($proposals as $proposal): ?>
                    <tr>
                        <td><?php echo e($proposal['member_name']); ?></td>
                        <td><strong><?php echo e($proposal['title']); ?></strong><small class="d-block"><?php echo e($proposal['category'] . ' — ' . $proposal['description']); ?></small></td>
                        <td>KES <?php echo e(number_format((float) $proposal['simulated_amount'], 2)); ?></td>
                        <td><?php echo e(ucfirst($proposal['status'])); ?><?php if ($proposal['decision_reason']): ?><small class="d-block"><?php echo e($proposal['decision_reason']); ?></small><?php endif; ?></td>
                        <td><?php if ($proposal['status'] === 'pending'): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="decide_proposal"><input type="hidden" name="proposal_id" value="<?php echo e((string) $proposal['proposal_id']); ?>"><label>Reason<input type="text" name="reason" maxlength="255" required></label><button type="submit" name="decision" value="approved">Approve</button><button type="submit" name="decision" value="rejected">Reject</button></form><?php else: ?>-<?php endif; ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5">No group proposals have been submitted.</td></tr>
                <?php endif; ?>
            </tbody></table>
        </article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
