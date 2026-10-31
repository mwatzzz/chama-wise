<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('admin/settings.php');
    }

    $values = $_POST['settings'] ?? null;
    if (!is_array($values)) {
        flash_set('error', 'Submit the financial settings to save them.');
        redirect('admin/settings.php');
    }

    try {
        save_financial_settings($values, (string) ($_POST['reason'] ?? ''), (int) current_user()['user_id']);
        flash_set('success', 'Financial settings saved. Historical transactions and loan decisions were not changed.');
    } catch (InvalidArgumentException $exception) {
        flash_set('error', $exception->getMessage());
    }
    redirect('admin/settings.php');
}

$settings = financial_settings();
$auditRows = db()->query(
    'SELECT a.setting_key, a.previous_value, a.new_value, a.reason, a.changed_at, u.name AS admin_name
     FROM business_setting_audit a
     INNER JOIN admins u ON u.admin_id = a.changed_by
     ORDER BY a.audit_id DESC
     LIMIT 30'
)->fetchAll();

$fields = [
    'loan_min_membership_months' => ['Minimum membership period (months)', 'Minimum completed membership time before applying.'],
    'loan_min_contribution_months' => ['Minimum consecutive contribution months', 'Consecutive calendar months with verified contributions.'],
    'loan_min_contributions' => ['Minimum recorded contributions', 'Count of verified recorded contributions.'],
    'loan_to_savings_multiplier' => ['Loan-to-eligible-savings multiplier', 'Maximum principal is eligible savings multiplied by this value.'],
    'loan_max_amount' => ['Maximum loan amount (KES)', 'Absolute borrowing cap, regardless of savings.'],
    'loan_penalties_block_borrowing' => ['Outstanding penalties block borrowing', 'Default is warning only.'],
    'loan_interest_method' => ['Loan interest method', 'Flat rates are per loan; reducing-balance rates accrue monthly.'],
    'loan_interest_rate' => ['Default loan interest rate (%)', 'The selected interest method determines whether the rate is per loan or per month. Existing loan decisions are unchanged.'],
    'loan_max_term_months' => ['Maximum loan term (months)', 'Maximum period between approval and due date.'],
    'contribution_amount' => ['Monthly contribution amount (KES)', '0 means the group has not configured a required amount.'],
    'contribution_due_day' => ['Monthly contribution deadline day', 'Calendar day of the month; limited to 1-28.'],
    'contribution_grace_days' => ['Contribution grace period (days)', 'Penalties are assessed only after this grace period.'],
    'contribution_penalty_amount' => ['Missed contribution penalty (KES)', 'Penalty applied once for each missed contribution month.'],
    'contribution_penalty_cap' => ['Maximum penalty per missed month (KES)', 'Caps the penalty for a single missed period.'],
    'investment_minimum' => ['Minimum simulated investment (KES)', 'Simulation only; no real transfers are supported.'],
    'investment_goal_minimum' => ['Minimum investment goal (KES)', 'Minimum target for a member investment plan.'],
    'monthly_savings_goal_minimum' => ['Minimum monthly savings goal (KES)', 'Minimum monthly contribution in a savings plan.'],
    'investment_annual_return_assumption' => ['Default assumed annual return (%)', 'Projection assumption only; 0% is supported and is the default.'],
    'investment_default_duration_months' => ['Default projection duration (months)', 'Used when a member creates a new plan.'],
];

$pageTitle = 'Financial Settings | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="admin-layout">
    <aside class="admin-sidebar">
        <nav aria-label="Admin navigation">
            <a class="admin-nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/members.php')); ?>"><span>&#128101;</span> Members</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/loans.php')); ?>"><span>&#9633;</span> Loans</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/repayments.php')); ?>"><span>&#8646;</span> Repayments</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/reports.php')); ?>"><span>&#9646;</span> Reports</a>
            <a class="admin-nav-link active" href="<?php echo e(url('admin/settings.php')); ?>"><span>&#9881;</span> Settings</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/intelligence.php')); ?>"><span>&#10022;</span> ChamaWise Intelligence</a>
        </nav>
        <a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a>
    </aside>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Group Rules</p><h1>Financial Settings</h1><p>Changes apply to new decisions and future periods only.</p></div></div>
        <article class="admin-panel contribution-form-panel">
            <form method="post">
                <?php echo csrf_field(); ?>
                <?php foreach ($fields as $key => [$label, $help]): ?>
                    <label for="<?php echo e($key); ?>"><?php echo e($label); ?>
                        <?php if ($key === 'loan_penalties_block_borrowing'): ?>
                            <select id="<?php echo e($key); ?>" name="settings[<?php echo e($key); ?>]" required>
                                <option value="0" <?php echo $settings[$key] === '0' ? 'selected' : ''; ?>>Warning only</option>
                                <option value="1" <?php echo $settings[$key] === '1' ? 'selected' : ''; ?>>Block loan applications</option>
                            </select>
                        <?php elseif ($key === 'loan_interest_method'): ?>
                            <select id="<?php echo e($key); ?>" name="settings[<?php echo e($key); ?>]" required>
                                <option value="flat" <?php echo $settings[$key] === 'flat' ? 'selected' : ''; ?>>Flat interest per loan</option>
                                <option value="reducing_balance" <?php echo $settings[$key] === 'reducing_balance' ? 'selected' : ''; ?>>Reducing balance</option>
                            </select>
                        <?php else: ?>
                            <input id="<?php echo e($key); ?>" type="number" name="settings[<?php echo e($key); ?>]" value="<?php echo e($settings[$key]); ?>" min="<?php echo $key === 'investment_annual_return_assumption' ? '-100' : '0'; ?>" step="<?php echo in_array($key, ['loan_min_membership_months', 'loan_min_contribution_months', 'loan_min_contributions', 'loan_max_term_months', 'contribution_due_day', 'contribution_grace_days', 'investment_default_duration_months'], true) ? '1' : '0.01'; ?>" required>
                        <?php endif; ?>
                        <small class="text-muted"><?php echo e($help); ?></small>
                    </label>
                <?php endforeach; ?>
                <label for="reason">Reason for this settings update
                    <input id="reason" type="text" name="reason" maxlength="255" required>
                </label>
                <button class="btn btn-brand" type="submit">Save Financial Settings</button>
            </form>
        </article>
        <article class="admin-panel table-panel mt-4">
            <h2>Recent Settings Audit</h2>
            <table><thead><tr><th>Setting</th><th>Previous</th><th>New</th><th>Reason</th><th>Administrator</th><th>Changed</th></tr></thead><tbody>
                <?php if ($auditRows): foreach ($auditRows as $row): ?>
                    <tr><td><?php echo e($fields[$row['setting_key']][0] ?? $row['setting_key']); ?></td><td><?php echo e($row['previous_value']); ?></td><td><?php echo e($row['new_value']); ?></td><td><?php echo e($row['reason']); ?></td><td><?php echo e($row['admin_name']); ?></td><td><?php echo e($row['changed_at']); ?></td></tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6">No financial setting changes have been recorded.</td></tr>
                <?php endif; ?>
            </tbody></table>
        </article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
