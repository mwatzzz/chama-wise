<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/admin_intelligence.php';
require_admin();

$adminId = (int) current_user()['user_id'];
$snapshot = admin_ai_financial_snapshot();
$reviewSignals = admin_ai_transaction_review_signals();
$messagesKey = 'admin_ai_messages_' . $adminId;
$errorKey = 'admin_ai_error_' . $adminId;
$messages = $_SESSION[$messagesKey] ?? [];
if (!is_array($messages)) {
    $messages = [];
}
$errorMessage = (string) ($_SESSION[$errorKey] ?? '');
unset($_SESSION[$errorKey]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION[$errorKey] = 'Your session expired. Refresh the page and submit your question again.';
        redirect('admin/intelligence.php');
    } else {
        $question = trim((string) ($_POST['question'] ?? ''));
        $scenarioInputs = is_array($_POST['scenario'] ?? null) ? $_POST['scenario'] : [];
        if ($question === '' || mb_strlen($question) > 1000) {
            $_SESSION[$errorKey] = 'Enter a question of up to 1,000 characters.';
        } else {
            try {
                $draftType = admin_ai_detect_draft_type($question);
                $targetId = $draftType !== '' ? admin_ai_resolve_draft_target($draftType, $question) : 0;
                $reportType = admin_ai_detect_report_type($question);
                $context = build_admin_ai_context($question, $snapshot, $reportType, $draftType, $targetId, $scenarioInputs);
                $answer = ollama_ai_service()->answerWithSystemPrompt($question, $context, admin_ai_system_prompt());
                $messages[] = [
                    'question' => $question,
                    'answer' => $answer,
                    'created_at' => date('Y-m-d H:i'),
                ];
                $messages = array_slice($messages, -8);
                $_SESSION[$messagesKey] = $messages;
            } catch (InvalidArgumentException $exception) {
                $_SESSION[$errorKey] = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Admin intelligence request failed: ' . $exception->getMessage());
                $_SESSION[$errorKey] = str_contains($exception->getMessage(), 'Ollama took too long')
                    ? 'ChamaWise Intelligence took too long to respond. Try a shorter question or a smaller, faster model.'
                    : 'ChamaWise Intelligence is temporarily unavailable. Check that Ollama is running and the configured model is available.';
            }
        }
    }
    redirect('admin/intelligence.php');
}

$pageTitle = 'ChamaWise Intelligence | Admin Dashboard';
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
            <a class="admin-nav-link" href="<?php echo e(url('admin/penalties.php')); ?>"><span>&#9888;</span> Penalties</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/loans.php')); ?>"><span>&#9633;</span> Loans</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/repayments.php')); ?>"><span>&#8646;</span> Repayments</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/reports.php')); ?>"><span>&#9646;</span> Reports</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/investments.php')); ?>"><span>&#9679;</span> Investments</a>
            <a class="admin-nav-link" href="<?php echo e(url('admin/settings.php')); ?>"><span>&#9881;</span> Settings</a>
            <a class="admin-nav-link active" href="<?php echo e(url('admin/intelligence.php')); ?>" aria-current="page"><span>&#10022;</span> ChamaWise Intelligence</a>
        </nav>
        <a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a>
    </aside>
    <section class="admin-content">
        <div class="admin-heading ai-advisor-heading">
            <div><p class="eyebrow mb-2">Administrator Decision Support</p><h1>ChamaWise Intelligence</h1><p>Ask questions about the group's records and configured rules.</p></div>
        </div>
        <div class="ai-disclaimer" role="note"><strong>Review before acting:</strong> Insights are generated from recorded ChamaWise data. They do not approve loans, alter balances, verify payments, or prove misconduct.</div>

        <div class="admin-stat-grid">
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9776;</span><div><span>Contributions this month</span><strong>KES <?php echo e(number_format((float) $snapshot['financial_snapshot']['recorded_contributions_this_month_kes'], 2)); ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9633;</span><div><span>Outstanding loans</span><strong>KES <?php echo e(number_format((float) $snapshot['financial_snapshot']['outstanding_loan_balance_kes'], 2)); ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#8646;</span><div><span>Repayments this month</span><strong>KES <?php echo e(number_format((float) $snapshot['financial_snapshot']['repayments_this_month_kes'], 2)); ?></strong></div></article>
            <article class="admin-stat-card"><span class="admin-stat-icon">&#9888;</span><div><span>Outstanding penalties</span><strong>KES <?php echo e(number_format((float) $snapshot['financial_snapshot']['outstanding_penalties_kes'], 2)); ?></strong></div></article>
        </div>

        <article class="admin-panel table-panel intelligence-trend-panel">
            <h2>Recorded contributions · last six months</h2>
            <div class="table-responsive"><table><thead><tr><th>Month</th><th>Recorded total</th></tr></thead><tbody>
                <?php foreach ($snapshot['contribution_trend_last_six_months'] as $month): ?>
                    <tr><td><?php echo e(date('F Y', strtotime((string) $month['month'] . '-01'))); ?></td><td>KES <?php echo e(number_format((float) $month['recorded_contributions_kes'], 2)); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </article>

        <article class="admin-panel table-panel intelligence-trend-panel">
            <h2>Existing report summaries</h2>
            <p class="text-muted">Choose an existing report in the assistant below to include its backend-calculated totals. Review or export reports using the current reports page.</p>
            <p class="mb-0"><a class="btn btn-outline-brand" href="<?php echo e(url('admin/reports.php?report=summary')); ?>">Open current financial summary</a> <a class="btn btn-outline-brand" href="<?php echo e(url('admin/reports.php?report=summary&format=csv')); ?>">Download existing summary CSV</a></p>
        </article>

        <article class="admin-panel table-panel intelligence-trend-panel">
            <h2>Transaction review signals</h2>
            <p class="text-muted">Deterministic checks to help prioritize manual review; these are not findings of fraud.</p>
            <div class="table-responsive"><table><thead><tr><th>Priority</th><th>Signal</th><th>Reference</th><th>Date</th><th>Evidence</th><th>Recommended review</th></tr></thead><tbody>
                <?php if ($reviewSignals): ?>
                    <?php foreach ($reviewSignals as $signal): ?>
                        <tr><td><?php echo e((string) $signal['priority']); ?></td><td><?php echo e((string) $signal['issue']); ?></td><td><?php echo e((string) $signal['reference']); ?></td><td><?php echo e((string) $signal['date']); ?></td><td><?php echo e((string) $signal['evidence']); ?></td><td><?php echo e((string) $signal['recommended_action']); ?></td></tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">No signals were found by the current checks. This is not a guarantee that every transaction is correct.</td></tr>
                <?php endif; ?>
            </tbody></table></div>
            <p class="text-muted mb-0 mt-2">Repeated Daraja callback deliveries cannot be confirmed from the current per-payment callback fields, which retain only the latest callback.</p>
        </article>

        <article class="profile-panel ai-chat-panel intelligence-chat-panel">
            <div class="ai-chat-heading">
                <div><span class="ai-chat-heading-icon" aria-hidden="true">&#10022;</span><div><h2>Ask ChamaWise Intelligence</h2><p>Answers use limited, server-calculated data relevant to your question.</p></div></div>
                <span class="ai-privacy-note"><span aria-hidden="true">&#128274;</span> Admin only</span>
            </div>
            <div class="ai-prompt-list intelligence-prompts" aria-label="Suggested questions">
                <button type="button" data-ai-prompt="Analyse this month's financial performance.">This month's performance</button>
                <button type="button" data-ai-prompt="Compare contributions over the last six months.">Contribution trend</button>
                <button type="button" data-ai-prompt="Identify suspicious transactions that require human review.">Review transaction signals</button>
                <button type="button" data-ai-prompt="Show loans that are overdue or due soon.">Loans to follow up</button>
                <button type="button" data-ai-prompt="Analyse our investment portfolio and distinguish simulated capital from payment records.">Investment overview</button>
                <button type="button" data-ai-prompt="Summarise the overall financial report using its calculated totals.">Summarise overall finances</button>
                <button type="button" data-ai-prompt="What are our current loan eligibility rules and contribution grace period?">Explain configured rules</button>
                <button type="button" data-ai-prompt="Prepare a draft agenda for our next meeting using recent financial priorities.">Meeting agenda</button>
                <button type="button" data-ai-prompt="Draft a contribution reminder for [member full name], using only their recorded contribution status.">Draft a contribution reminder</button>
                <button type="button" data-ai-prompt="Draft a loan repayment reminder for [member full name], using their recorded loan details.">Draft a repayment reminder</button>
                <button type="button" data-ai-prompt="Draft a meeting announcement for [meeting title], using its scheduled details.">Draft a meeting announcement</button>
                <button type="button" data-ai-prompt="Draft a general financial update using the current recorded summary.">Draft a financial update</button>
                <button type="button" data-ai-prompt="Draft a short, balanced investment education message without promising returns.">Draft investment education</button>
            </div>
            <?php if ($errorMessage !== ''): ?>
                <p class="alert alert-danger mx-3" role="alert"><?php echo e($errorMessage); ?></p>
            <?php endif; ?>
            <div class="ai-chat-messages<?php echo $messages ? ' has-messages' : ' is-empty'; ?>" aria-live="polite" aria-relevant="additions text">
                <?php if ($messages): ?>
                    <?php foreach ($messages as $message): ?>
                        <div class="ai-message ai-message-member"><strong>Administrator</strong><p><?php echo nl2br(e((string) $message['question'])); ?></p><small><?php echo e((string) $message['created_at']); ?></small></div>
                        <div class="ai-message ai-message-assistant"><strong>ChamaWise Intelligence</strong><p><?php echo nl2br(e((string) $message['answer'])); ?></p><small><?php echo e((string) $message['created_at']); ?></small></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="ai-empty-state"><span class="ai-empty-icon" aria-hidden="true">&#10022;</span><h3>Turn group records into clearer next steps.</h3><p>Choose a suggested question or ask about recorded contributions, loans, penalties, investments, or business rules.</p></div>
                <?php endif; ?>
                <div class="ai-loading-state" hidden role="status" aria-live="polite">
                    <span class="ai-loading-spinner" aria-hidden="true"></span>
                    <span><strong>ChamaWise Intelligence is thinking</strong><small>Reviewing the selected records and preparing an answer…</small></span>
                </div>
            </div>
            <form method="post" class="ai-chat-form">
                <?php echo csrf_field(); ?>
                <fieldset class="intelligence-scenario-fields">
                    <legend>Optional hypothetical investment scenario</legend>
                    <p class="text-muted">Scenario values are calculated on the server and are not saved. Required only when asking a “what if” question.</p>
                    <label for="scenario-initial">Initial capital (KES)<input id="scenario-initial" name="scenario[initial_amount]" type="number" min="0" max="1000000000" step="0.01"></label>
                    <label for="scenario-monthly">Monthly contribution (KES)<input id="scenario-monthly" name="scenario[monthly_contribution]" type="number" min="0" max="1000000000" step="0.01"></label>
                    <label for="scenario-return">Assumed annual return (%)<input id="scenario-return" name="scenario[annual_return_percent]" type="number" min="-100" max="100" step="0.01"></label>
                    <label for="scenario-months">Duration (months)<input id="scenario-months" name="scenario[duration_months]" type="number" min="1" max="600" step="1"></label>
                </fieldset>
                <label class="visually-hidden" for="ai-question">Your question</label>
                <textarea id="ai-question" name="question" maxlength="1000" placeholder="Ask about your chama records, request a report or message draft..." required></textarea>
                <small class="text-muted">For reminders or meeting announcements, include the member’s full name or meeting title.</small>
                <div class="ai-chat-form-footer"><small>Ollama receives only selected structured records and aggregates for this question.</small><button class="btn btn-brand" type="submit">Ask question <span aria-hidden="true">&#8594;</span></button></div>
            </form>
        </article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
