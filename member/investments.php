<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$memberId = (int) current_user()['user_id'];
$action = (string) ($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('member/investments.php');
    }

    try {
        if ($action === 'create_goal') {
            $goalId = create_investment_goal(
                $memberId,
                is_array($_POST['goal'] ?? null) ? $_POST['goal'] : [],
                (string) ($_POST['submission_key'] ?? '')
            );
            unset($_SESSION['investment_goal_submission_key']);
            flash_set('success', 'Your simulated investment goal was saved. It does not move real money.');
        } elseif ($action === 'investment_payment') {
            if (!daraja_sandbox_is_configured()) {
                throw new InvalidArgumentException('Daraja Sandbox is not configured. The administrator must set the sandbox credentials and public HTTPS callback URL in the server environment.');
            }
            $target = explode(':', (string) ($_POST['investment_target'] ?? ''), 2);
            if (count($target) !== 2 || !in_array($target[0], ['goal', 'opportunity', 'group_plan'], true) ||
                !ctype_digit($target[1]) || (int) $target[1] < 1) {
                throw new InvalidArgumentException('Choose an active investment goal or opportunity.');
            }
            $payment = start_investment_stk_payment(
                $memberId,
                $target[0] === 'goal' ? (int) $target[1] : null,
                $target[0] === 'opportunity' ? (int) $target[1] : null,
                $target[0] === 'group_plan' ? (int) $target[1] : null,
                (string) ($_POST['amount'] ?? ''),
                trim((string) ($_POST['phone_number'] ?? '')),
                (string) ($_POST['submission_key'] ?? '')
            );
            if ($payment['status'] === 'failed') {
                unset($_SESSION['investment_payment_submission_key']);
            }
            flash_set($payment['status'] === 'failed' ? 'error' : 'info', $payment['message']);
        } elseif ($action === 'update_goal') {
            update_investment_goal(
                $memberId,
                (int) ($_POST['goal_id'] ?? 0),
                is_array($_POST['goal'] ?? null) ? $_POST['goal'] : []
            );
            flash_set('success', 'Your investment goal was updated.');
        } elseif ($action === 'close_goal') {
            set_investment_goal_status(
                $memberId,
                (int) ($_POST['goal_id'] ?? 0),
                (string) ($_POST['status'] ?? '')
            );
            flash_set('success', 'Your investment goal status was updated.');
        } elseif ($action === 'group_proposal') {
            submit_group_investment_proposal(
                $memberId,
                is_array($_POST['proposal'] ?? null) ? $_POST['proposal'] : [],
                (string) ($_POST['submission_key'] ?? '')
            );
            unset($_SESSION['group_investment_submission_key']);
            flash_set('success', 'Your simulated group investment proposal was submitted for authorized approval.');
        } elseif (in_array($action, ['contribution', 'withdrawal'], true)) {
            $goalId = (int) ($_POST['goal_id'] ?? 0);
            $keys = $_SESSION['investment_transaction_keys'] ?? [];
            $key = (string) ($keys[(string) $goalId] ?? '');
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($amount === false) {
                throw new InvalidArgumentException('Enter a valid simulated transaction amount.');
            }
            $recorded = record_simulated_investment_transaction(
                $memberId,
                $goalId,
                (float) $amount,
                $action,
                trim((string) ($_POST['note'] ?? '')),
                $key
            );
            unset($_SESSION['investment_transaction_keys'][(string) $goalId]);
            flash_set(
                $recorded ? 'success' : 'info',
                $recorded
                    ? 'Simulated portfolio record updated. No real payment or withdrawal was made.'
                    : 'This simulated transaction was already processed.'
            );
        } else {
            throw new InvalidArgumentException('Choose a valid investment action.');
        }
    } catch (InvalidArgumentException $exception) {
        flash_set('error', $exception->getMessage());
    } catch (Throwable $exception) {
        error_log('Investment planning operation failed: ' . $exception->getMessage());
        flash_set('error', 'The investment planning operation could not be completed.');
    }
    redirect('member/investments.php');
}

if (empty($_SESSION['investment_goal_submission_key'])) {
    $_SESSION['investment_goal_submission_key'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['group_investment_submission_key'])) {
    $_SESSION['group_investment_submission_key'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['investment_payment_submission_key'])) {
    $_SESSION['investment_payment_submission_key'] = bin2hex(random_bytes(32));
}
$memberPhoneStmt = db()->prepare('SELECT phone FROM members WHERE member_id = :member_id AND status = \'active\' LIMIT 1');
$memberPhoneStmt->execute(['member_id' => $memberId]);
$registeredPhone = (string) ($memberPhoneStmt->fetchColumn() ?: '');

$opportunities = db()->query(
    'SELECT opportunity_id, title, category, description, risk_level, investment_period, liquidity,
            minimum_amount, risks, source_url, verified_at, annual_return_assumption, verification_status
     FROM investment_opportunities
     WHERE is_active = 1
     ORDER BY category, title'
)->fetchAll();
$groupPlans = db()->query(
    "SELECT plan_id, title, description, target_amount, status
     FROM investment_group_plans WHERE status = 'active' ORDER BY title"
)->fetchAll();
$goalStmt = db()->prepare(
    'SELECT g.goal_id, g.opportunity_id, g.title, g.target_amount, g.initial_amount,
            g.monthly_contribution, g.annual_return_assumption, g.duration_months, g.target_date,
            g.status, o.title AS opportunity_title
     FROM investment_goals g
     LEFT JOIN investment_opportunities o ON o.opportunity_id = g.opportunity_id
     WHERE g.member_id = :member_id
     ORDER BY g.created_at DESC, g.goal_id DESC'
);
$goalStmt->execute(['member_id' => $memberId]);
$goals = $goalStmt->fetchAll();
$proposalStmt = db()->prepare(
    'SELECT proposal_id, title, category, description, simulated_amount, status, decision_reason, created_at, decided_at
     FROM group_investment_proposals
     WHERE submitted_by = :member_id
     ORDER BY proposal_id DESC'
);
$proposalStmt->execute(['member_id' => $memberId]);
$proposals = $proposalStmt->fetchAll();
$groupSimulation = db()->query(
    "SELECT COUNT(*) AS approved_count, COALESCE(SUM(simulated_amount), 0) AS approved_total
     FROM group_investment_proposals WHERE status = 'approved'"
)->fetch();
$paidInvestmentStmt = db()->prepare(
    "SELECT COALESCE(SUM(a.amount), 0)
     FROM investment_payment_allocations a
     INNER JOIN investment_payments p ON p.payment_id = a.payment_id AND p.status = 'successful'
     WHERE p.member_id = :member_id"
);
$paidInvestmentStmt->execute(['member_id' => $memberId]);
$verifiedInvestmentTotal = (float) $paidInvestmentStmt->fetchColumn();
$paymentHistoryStmt = db()->prepare(
    'SELECT p.payment_id, p.transaction_reference, p.amount, p.phone_number, p.status,
            p.mpesa_receipt_number, p.request_result_description, p.callback_result_description,
            p.callback_result_code, p.created_at, p.verified_at,
            COALESCE(g.title, o.title, gp.title, \'Investment\') AS investment_title,
            a.amount AS allocated_amount
     FROM investment_payments p
     LEFT JOIN investment_payment_allocations a ON a.payment_id = p.payment_id
     LEFT JOIN investment_goals g ON g.goal_id = COALESCE(a.goal_id, p.goal_id)
     LEFT JOIN investment_opportunities o ON o.opportunity_id = COALESCE(a.opportunity_id, p.opportunity_id)
     LEFT JOIN investment_group_plans gp ON gp.plan_id = COALESCE(a.group_plan_id, p.group_plan_id)
     WHERE p.member_id = :member_id
     ORDER BY p.payment_id DESC'
);
$paymentHistoryStmt->execute(['member_id' => $memberId]);
$investmentPayments = $paymentHistoryStmt->fetchAll();
$totalCapital = 0.0;
$totalProjection = 0.0;
$totalEstimatedGain = 0.0;

foreach ($goals as &$goal) {
    $transactions = db()->prepare(
        'SELECT amount, transaction_type, note, created_at
         FROM investment_transactions
         WHERE goal_id = :goal_id
         ORDER BY transaction_id DESC'
    );
    $transactions->execute(['goal_id' => $goal['goal_id']]);
    $goal['transactions'] = $transactions->fetchAll();
    $capital = 0.0;
    foreach ($goal['transactions'] as $transaction) {
        $capital += $transaction['transaction_type'] === 'contribution'
            ? (float) $transaction['amount']
            : -(float) $transaction['amount'];
    }
    $goal['capital'] = round(max(0, $capital), 2);
    $goal['projection'] = $goal['status'] === 'active'
        ? calculate_investment_projection(
            $goal['capital'],
            (float) $goal['monthly_contribution'],
            (float) $goal['annual_return_assumption'],
            (int) $goal['duration_months']
        )
        : [
            'projected_value' => $goal['capital'],
            'projected_gain' => 0.0,
            'zero_return_value' => $goal['capital'],
        ];
    $goal['remaining'] = max(0, round((float) $goal['target_amount'] - $goal['capital'], 2));
    $goal['progress_percent'] = min(100, round(($goal['capital'] / max(1, (float) $goal['target_amount'])) * 100, 1));
    $paidStmt = db()->prepare(
        "SELECT COALESCE(SUM(a.amount), 0)
         FROM investment_payment_allocations a
         INNER JOIN investment_payments p ON p.payment_id = a.payment_id AND p.status = 'successful'
         WHERE a.goal_id = :goal_id AND p.member_id = :member_id"
    );
    $paidStmt->execute(['goal_id' => $goal['goal_id'], 'member_id' => $memberId]);
    $goal['verified_paid'] = (float) $paidStmt->fetchColumn();
    $totalCapital += $goal['capital'];
    if ($goal['status'] === 'active') {
        $totalProjection += $goal['projection']['projected_value'];
        $totalEstimatedGain += $goal['projection']['projected_gain'];
    }
    $goalKey = (string) $goal['goal_id'];
    if ($goal['status'] === 'active' && empty($_SESSION['investment_transaction_keys'][$goalKey])) {
        $_SESSION['investment_transaction_keys'][$goalKey] = bin2hex(random_bytes(32));
    }
}
unset($goal);
$activeGoalCount = count(array_filter($goals, static fn (array $goal): bool => $goal['status'] === 'active'));

$pageTitle = 'Investment Planning | Member Dashboard';
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar"><nav aria-label="Member navigation">
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="member-nav-link" href="<?php echo e(url('member/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="member-nav-link" href="<?php echo e(url('member/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="member-nav-link" href="<?php echo e(url('member/repayments.php')); ?>"><span>&#8646;</span> Loan Repayment</a>
        <a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
        <a class="member-nav-link" href="<?php echo e(url('member/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
        <a class="member-nav-link" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span>&#10022;</span> AI Advisor</a>
        <a class="member-nav-link active" href="<?php echo e(url('member/investments.php')); ?>"><span>&#9679;</span> Investments</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> Investment Planning</div>
        <div class="member-heading"><div><h1>Investment Planning</h1><p>Build simulated goals and review estimates in KES.</p></div></div>
        <div class="ai-disclaimer" role="note"><strong>Sandbox payments only:</strong> Daraja test transactions do not move real money. Investment payments are credited only after the server verifies both the callback details and Daraja's transaction query. Projections remain estimates, not actual returns or promises.</div>
        <article class="profile-panel investment-summary mt-3">
            <h2>Portfolio summary</h2>
            <p>Total simulated capital: <strong>KES <?php echo e(number_format($totalCapital, 2)); ?></strong></p>
            <p>Verified Daraja Sandbox investment payments: <strong>KES <?php echo e(number_format($verifiedInvestmentTotal, 2)); ?></strong></p>
            <p class="mb-0">Illustrative future value across active plans: <strong>KES <?php echo e(number_format($totalProjection, 2)); ?></strong> · estimated gain/loss: KES <?php echo e(number_format($totalEstimatedGain, 2)); ?>. Based on each plan's stored assumptions and duration, not actual performance.</p>
            <p class="mb-0 mt-2">Active investment plans: <?php echo e((string) $activeGoalCount); ?></p>
            <p class="mb-0 mt-2">Approved simulated group proposals: <?php echo e((string) $groupSimulation['approved_count']); ?> · KES <?php echo e(number_format((float) $groupSimulation['approved_total'], 2)); ?> (planning figures only).</p>
        </article>
        <article class="profile-panel contribution-form-panel mt-3">
            <h2>Pay into an investment (Daraja Sandbox)</h2>
            <p>Choose an active goal or opportunity. An STK prompt is sent to the number below. Only a Daraja-verified payment appears in your paid investment total.</p>
            <?php if (!daraja_sandbox_is_configured()): ?>
                <div class="alert alert-warning" role="status">Sandbox payments are unavailable until an administrator configures the Daraja Sandbox credentials and a public HTTPS callback URL on the server.</div>
            <?php endif; ?>
            <form method="post" class="daraja-payment-form" data-daraja-payment-form>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="investment_payment">
                <input type="hidden" name="submission_key" value="<?php echo e((string) $_SESSION['investment_payment_submission_key']); ?>">
                <label>Investment goal or opportunity
                    <select name="investment_target" required>
                        <option value="">Choose an investment</option>
                        <?php foreach ($goals as $goal): ?>
                            <?php if ($goal['status'] === 'active'): ?><option value="goal:<?php echo e((string) $goal['goal_id']); ?>">Goal: <?php echo e($goal['title']); ?></option><?php endif; ?>
                        <?php endforeach; ?>
                        <?php foreach ($opportunities as $opportunity): ?>
                            <option value="opportunity:<?php echo e((string) $opportunity['opportunity_id']); ?>">Opportunity: <?php echo e($opportunity['title']); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($groupPlans as $groupPlan): ?>
                            <option value="group_plan:<?php echo e((string) $groupPlan['plan_id']); ?>">Group plan: <?php echo e($groupPlan['title']); ?> · target KES <?php echo e(number_format((float) $groupPlan['target_amount'], 2)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Amount (KES, whole shillings)<input type="number" name="amount" min="<?php echo e((string) max(1, (int) ceil((float) financial_setting('investment_minimum')))); ?>" max="150000" step="1" required></label>
                <label>M-Pesa phone number<input type="tel" name="phone_number" value="<?php echo e($registeredPhone); ?>" autocomplete="tel" maxlength="20" placeholder="0712345678" required></label>
                <button class="btn btn-brand" type="submit" <?php echo daraja_sandbox_is_configured() ? '' : 'disabled'; ?>>Send Sandbox STK Push</button>
                <span class="daraja-payment-status" aria-live="polite"></span>
            </form>
        </article>
        <article class="profile-panel table-panel mt-3">
            <h2>Investment payment history</h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Investment</th><th>Amount</th><th>Status</th><th>Reference / receipt</th><th>Phone / time</th></tr></thead>
                    <tbody>
                    <?php if ($investmentPayments): foreach ($investmentPayments as $payment): ?>
                        <tr>
                            <td><?php echo e($payment['investment_title']); ?></td>
                            <td>KES <?php echo e(number_format((float) $payment['amount'], 2)); ?></td>
                            <td><span class="badge text-bg-<?php echo $payment['status'] === 'successful' ? 'success' : ($payment['status'] === 'failed' ? 'danger' : 'warning'); ?>"><?php echo e(ucfirst($payment['status'])); ?></span>
                                <?php if ($payment['status'] === 'pending'): ?><small class="d-block">Awaiting Daraja verification</small><?php endif; ?>
                            </td>
                            <td><strong><?php echo e($payment['transaction_reference']); ?></strong>
                                <?php if ($payment['mpesa_receipt_number']): ?><small class="d-block">M-Pesa receipt: <?php echo e($payment['mpesa_receipt_number']); ?></small><?php endif; ?>
                                <?php if ($payment['status'] === 'successful'): ?><details><summary>View receipt</summary><small class="d-block">ChamaWise Daraja Sandbox investment receipt</small><small class="d-block">Reference: <?php echo e($payment['transaction_reference']); ?></small><small class="d-block">M-Pesa receipt: <?php echo e($payment['mpesa_receipt_number'] ?? ''); ?></small><small class="d-block">Amount: KES <?php echo e(number_format((float) $payment['amount'], 2)); ?></small><small class="d-block">Verified: <?php echo e($payment['verified_at'] ?? ''); ?></small></details><?php endif; ?>
                            </td>
                            <td><?php echo e($payment['phone_number']); ?><small class="d-block"><?php echo e($payment['created_at']); ?></small>
                                <?php if ($payment['verified_at']): ?><small class="d-block">Verified: <?php echo e($payment['verified_at']); ?></small><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5">No investment payments yet. Daraja Sandbox payment receipts will appear here.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <div class="contribution-layout mt-3">
            <article class="profile-panel contribution-form-panel">
                <h2>Create an investment goal</h2>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_goal">
                    <input type="hidden" name="submission_key" value="<?php echo e((string) $_SESSION['investment_goal_submission_key']); ?>">
                    <label>Goal name<input type="text" name="goal[title]" maxlength="160" required></label>
                    <label>Target amount (KES)<input type="number" name="goal[target_amount]" min="<?php echo e(financial_setting('investment_goal_minimum')); ?>" max="1000000000" step="0.01" required></label>
                    <label>Target date (optional)<input type="date" name="goal[target_date]" min="<?php echo e(date('Y-m-d')); ?>"></label>
                    <label>Investment opportunity (optional)
                        <select name="goal[opportunity_id]"><option value="0">General plan</option><?php foreach ($opportunities as $opportunity): ?><option value="<?php echo e((string) $opportunity['opportunity_id']); ?>"><?php echo e($opportunity['category'] . ' — ' . $opportunity['title']); ?></option><?php endforeach; ?></select>
                    </label>
                    <label>Initial simulated amount (KES)<input type="number" name="goal[initial_amount]" min="0" max="1000000000" step="0.01" value="0" required></label>
                    <label>Monthly contribution (KES)<input type="number" name="goal[monthly_contribution]" min="0" max="1000000000" step="0.01" value="<?php echo e(financial_setting('monthly_savings_goal_minimum')); ?>" required></label>
                    <label>Assumed annual return (%; can be negative)<input type="number" name="goal[annual_return_assumption]" min="-100" max="100" step="0.001" placeholder="Blank uses verified opportunity assumption or configured default (<?php echo e(financial_setting('investment_annual_return_assumption')); ?>%)"></label>
                    <label>Projection duration (months)<input type="number" name="goal[duration_months]" min="1" max="600" value="<?php echo e(financial_setting('investment_default_duration_months')); ?>" required></label>
                    <button class="btn btn-brand" type="submit">Save Simulated Goal</button>
                </form>
            </article>
            <article class="profile-panel contribution-form-panel">
                <h2>Record simulated investment</h2>
                <?php if ($goals): ?>
                    <?php foreach ($goals as $goal): ?>
                        <?php if ($goal['status'] === 'active'): ?>
                            <form method="post" class="mb-4">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="goal_id" value="<?php echo e((string) $goal['goal_id']); ?>">
                                <input type="hidden" name="idempotency_key" value="<?php echo e((string) $_SESSION['investment_transaction_keys'][(string) $goal['goal_id']]); ?>">
                                <p><strong><?php echo e($goal['title']); ?></strong> — simulated capital KES <?php echo e(number_format($goal['capital'], 2)); ?> · verified Daraja payments KES <?php echo e(number_format($goal['verified_paid'], 2)); ?></p>
                                <label>Simulated amount (KES)<input type="number" name="amount" min="0.01" max="1000000000" step="0.01" required></label>
                                <label>Note (required for withdrawal)<input type="text" name="note" maxlength="255"></label>
                                <button class="btn btn-brand" type="submit" name="action" value="contribution">Add Simulated Contribution</button>
                                <button class="btn btn-outline-brand" type="submit" name="action" value="withdrawal">Record Simulated Withdrawal</button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Create a goal before recording simulated activity.</p>
                <?php endif; ?>
            </article>
        </div>
        <div class="contribution-layout mt-3">
            <article class="profile-panel contribution-form-panel">
                <h2>Propose a simulated group investment</h2>
                <p>Group proposals need authorized administrator approval. Approval is for planning only and does not authorize or perform a real transfer.</p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="group_proposal">
                    <input type="hidden" name="submission_key" value="<?php echo e((string) $_SESSION['group_investment_submission_key']); ?>">
                    <label>Title<input type="text" name="proposal[title]" maxlength="160" required></label>
                    <label>Category<select name="proposal[category]" required><option value="">Choose category</option><?php foreach (investment_categories() as $category): ?><option value="<?php echo e($category); ?>"><?php echo e($category); ?></option><?php endforeach; ?></select></label>
                    <label>Description<textarea name="proposal[description]" maxlength="1000" required></textarea></label>
                    <label>Proposed simulated amount (KES)<input type="number" name="proposal[amount]" min="<?php echo e(financial_setting('investment_minimum')); ?>" max="1000000000" step="0.01" required></label>
                    <button class="btn btn-brand" type="submit">Submit for Admin Review</button>
                </form>
            </article>
            <article class="profile-panel table-panel">
                <h2>My group proposals</h2>
                <table><thead><tr><th>Proposal</th><th>Amount</th><th>Status</th><th>Decision note</th></tr></thead><tbody>
                    <?php if ($proposals): foreach ($proposals as $proposal): ?>
                        <tr><td><?php echo e($proposal['title']); ?> <small class="d-block"><?php echo e($proposal['category']); ?></small></td><td>KES <?php echo e(number_format((float) $proposal['simulated_amount'], 2)); ?></td><td><?php echo e(ucfirst($proposal['status'])); ?></td><td><?php echo e($proposal['decision_reason'] ?? 'Awaiting administrator review'); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4">You have not submitted a group proposal.</td></tr>
                    <?php endif; ?>
                </tbody></table>
            </article>
        </div>
        <section class="profile-panel investment-calculator mt-4" id="investment-calculator">
            <h2>Illustrative growth calculator</h2>
            <p>Assumes the annual rate compounds monthly, with monthly contributions made at each month-end. These are estimates only, not market predictions or guarantees.</p>
            <form class="contribution-layout" data-investment-calculator-form>
                <label>Initial amount (KES)<input name="initial" type="number" min="0" max="1000000000" step="0.01" value="0" required></label>
                <label>Monthly contribution (KES)<input name="monthly" type="number" min="0" max="1000000000" step="0.01" value="500" required></label>
                <label>Assumed annual return (%)<input name="annual_return" type="number" min="-100" max="100" step="0.001" value="0" required></label>
                <label>Duration (months)<input name="months" type="number" min="1" max="600" step="1" value="12" required></label>
                <button class="btn btn-outline-brand" type="submit">Calculate estimate</button>
            </form>
            <p class="investment-calculator-result" data-investment-calculator-result role="status" aria-live="polite">Enter assumptions to see an illustrative estimate and 0% comparison.</p>
            <canvas data-investment-calculator-chart role="img" aria-label="Illustrative estimated value compared with the zero-return scenario" style="display:block;width:100%;max-width:760px;height:260px;margin:1rem auto;"></canvas>
        </section>
        <section class="profile-panel investment-education mt-4">
            <h2>Investment basics</h2>
            <dl>
                <dt>Risk and return</dt><dd>Potential gains are uncertain; a higher expected return often comes with a greater chance of loss. No estimate is guaranteed.</dd>
                <dt>Diversification</dt><dd>Spreading savings across different assets or providers can reduce reliance on a single investment, but cannot remove all risk.</dd>
                <dt>Liquidity</dt><dd>Liquidity describes how easily you can access money. Check withdrawal restrictions, notice periods and possible exit costs before committing.</dd>
                <dt>Compound growth</dt><dd>Returns can earn further returns over time. Actual results vary and may be negative; this calculator uses a fixed assumption for illustration.</dd>
                <dt>Inflation</dt><dd>When prices rise, the same KES amount may buy less over time. Compare an investment's after-fee return with inflation information from a reliable source.</dd>
                <dt>Time horizon</dt><dd>Choose a time period that fits when you may need the money. Short-term needs generally call for careful attention to access and loss risk.</dd>
                <dt>Fees and charges</dt><dd>Ask for all management, transaction, custody, withdrawal and tax-related charges in writing, and how they affect the amount you receive.</dd>
            </dl>
            <h3>Questions to ask a provider</h3>
            <ul class="mb-0">
                <li>Is the provider appropriately licensed or regulated, and where can I verify this independently?</li>
                <li>What are the full fees, risks, lock-in periods, and withdrawal rules?</li>
                <li>Can I read the official product documents and see how returns are calculated?</li>
                <li>What happens to my money if the provider or product has financial difficulty?</li>
            </ul>
        </section>
        <section class="mt-4">
            <h2>My simulated plans</h2>
            <?php if ($goals): foreach ($goals as $goal): ?>
                <article class="profile-panel investment-goal-card mb-3">
                    <h3><?php echo e($goal['title']); ?> <span class="investment-goal-status"><?php echo e(ucfirst($goal['status'])); ?></span></h3>
                    <p>Opportunity: <?php echo e($goal['opportunity_title'] ?? 'General plan'); ?> · Target: KES <?php echo e(number_format((float) $goal['target_amount'], 2)); ?> · Current simulated capital: KES <?php echo e(number_format($goal['capital'], 2)); ?> · Remaining: KES <?php echo e(number_format($goal['remaining'], 2)); ?></p>
                    <p>Progress: <?php echo e((string) $goal['progress_percent']); ?>%<?php if ($goal['target_date']): ?> · Target date: <?php echo e($goal['target_date']); ?><?php endif; ?></p>
                    <div class="investment-progress" role="progressbar" aria-label="<?php echo e($goal['title']); ?> progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo e((string) $goal['progress_percent']); ?>"><span style="width:<?php echo e((string) $goal['progress_percent']); ?>%;"></span></div>
                    <p>Assumptions: initial amount KES <?php echo e(number_format((float) $goal['initial_amount'], 2)); ?>, monthly contribution KES <?php echo e(number_format((float) $goal['monthly_contribution'], 2)); ?>, annual return <?php echo e((string) $goal['annual_return_assumption']); ?>%, duration <?php echo e((string) $goal['duration_months']); ?> months.</p>
                    <?php if ($goal['status'] === 'active'): ?><p>Projection at assumed return: KES <?php echo e(number_format($goal['projection']['projected_value'], 2)); ?> (estimated gain/loss KES <?php echo e(number_format($goal['projection']['projected_gain'], 2)); ?>). At 0% return: KES <?php echo e(number_format($goal['projection']['zero_return_value'], 2)); ?>. Monthly deposits are assumed at each month-end.</p><?php else: ?><p>Goal closed; current simulated capital is KES <?php echo e(number_format($goal['capital'], 2)); ?>. No future projection is shown.</p><?php endif; ?>
                    <?php if ($goal['transactions']): ?><h4>Simulated history</h4><ul><?php foreach ($goal['transactions'] as $transaction): ?><li><?php echo e(ucfirst($transaction['transaction_type'])); ?>: KES <?php echo e(number_format((float) $transaction['amount'], 2)); ?> on <?php echo e($transaction['created_at']); ?><?php if ($transaction['note']): ?> — <?php echo e($transaction['note']); ?><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <?php if ($goal['status'] === 'active'): ?>
                        <details><summary>Edit goal</summary>
                            <form method="post" class="mt-3">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="update_goal"><input type="hidden" name="goal_id" value="<?php echo e((string) $goal['goal_id']); ?>">
                                <label>Goal name<input type="text" name="goal[title]" maxlength="160" value="<?php echo e($goal['title']); ?>" required></label>
                                <label>Target amount (KES)<input type="number" name="goal[target_amount]" min="<?php echo e(financial_setting('investment_goal_minimum')); ?>" max="1000000000" step="0.01" value="<?php echo e((string) $goal['target_amount']); ?>" required></label>
                                <label>Target date<input type="date" name="goal[target_date]" min="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e($goal['target_date'] ?? ''); ?>"></label>
                                <label>Monthly contribution (KES)<input type="number" name="goal[monthly_contribution]" min="0" max="1000000000" step="0.01" value="<?php echo e((string) $goal['monthly_contribution']); ?>" required></label>
                                <label>Annual return assumption (%)<input type="number" name="goal[annual_return_assumption]" min="-100" max="100" step="0.001" value="<?php echo e((string) $goal['annual_return_assumption']); ?>" required></label>
                                <label>Projection duration (months)<input type="number" name="goal[duration_months]" min="1" max="600" value="<?php echo e((string) $goal['duration_months']); ?>" required></label>
                                <button class="btn btn-brand" type="submit">Update Goal</button>
                            </form>
                            <form method="post" class="mt-3">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="close_goal"><input type="hidden" name="goal_id" value="<?php echo e((string) $goal['goal_id']); ?>">
                                <button class="btn btn-outline-brand" type="submit" name="status" value="completed">Mark Completed</button>
                                <button class="btn btn-outline-brand" type="submit" name="status" value="inactive">Mark Inactive</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; else: ?>
                <article class="profile-panel empty-module"><p>You have not created an investment plan.</p></article>
            <?php endif; ?>
        </section>
        <section class="mt-4">
            <h2>Opportunities</h2>
            <div class="contribution-layout">
                <?php if ($opportunities): foreach ($opportunities as $opportunity): ?>
                    <article class="profile-panel investment-opportunity-card">
                        <h3><?php echo e($opportunity['title']); ?></h3>
                        <p><?php echo e($opportunity['category']); ?></p>
                        <p><?php echo e($opportunity['description']); ?></p>
                        <?php if ($opportunity['verification_status'] === 'verified' && $opportunity['source_url'] && $opportunity['verified_at']): ?>
                            <p>Source: <a href="<?php echo e($opportunity['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($opportunity['source_url']); ?></a> · Last verified: <?php echo e($opportunity['verified_at']); ?></p>
                        <?php else: ?>
                            <p class="text-warning"><?php echo e(ucfirst($opportunity['verification_status'])); ?> information. Check provider terms independently.</p>
                        <?php endif; ?>
                        <p>Risk level: <?php echo e(ucfirst($opportunity['risk_level'])); ?> · Period: <?php echo e($opportunity['investment_period'] ?: 'Unavailable'); ?> · Liquidity: <?php echo e($opportunity['liquidity'] ?: 'Unavailable'); ?> · Minimum: <?php echo $opportunity['minimum_amount'] === null ? 'Unavailable' : 'KES ' . e(number_format((float) $opportunity['minimum_amount'], 2)); ?></p>
                        <p>Risks and considerations: <?php echo e($opportunity['risks'] ?: 'Not supplied; review product documents and ask the provider.'); ?></p>
                        <?php if ($opportunity['annual_return_assumption'] !== null && $opportunity['verification_status'] === 'verified'): ?><p>Annual projection assumption: <?php echo e((string) $opportunity['annual_return_assumption']); ?>% (not a guaranteed or live provider return).</p><?php else: ?><p>Indicative return information: Unavailable or unverified.</p><?php endif; ?>
                    </article>
                <?php endforeach; else: ?>
                    <article class="profile-panel"><p>No investment opportunities have been added yet. The planner remains available without one.</p></article>
                <?php endif; ?>
            </div>
        </section>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
