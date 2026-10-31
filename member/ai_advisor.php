<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();
$sessionId = (int) ($_GET['session'] ?? $_POST['session_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash_set('error', 'Your session expired. Please try again.');
        redirect('member/ai_advisor.php');
    }

    $question = trim((string) ($_POST['question'] ?? ''));
    if ($question === '') {
        flash_set('error', 'Enter a question for the financial advisor.');
        redirect('member/ai_advisor.php' . ($sessionId > 0 ? '?session=' . $sessionId : ''));
    }

    if (!is_chama_question($question)) {
        flash_set('error', 'The AI advisor only answers ChamaWise questions about chama records and finances.');
        redirect('member/ai_advisor.php' . ($sessionId > 0 ? '?session=' . $sessionId : ''));
    }

    if ($sessionId > 0) {
        $sessionStmt = db()->prepare('SELECT session_id FROM ai_advice_sessions WHERE session_id = :session_id AND member_id = :member_id LIMIT 1');
        $sessionStmt->execute(['session_id' => $sessionId, 'member_id' => $user['user_id']]);
        if (!$sessionStmt->fetch()) {
            $sessionId = 0;
        }
    }

    if ($sessionId === 0) {
        $sessionStmt = db()->prepare('INSERT INTO ai_advice_sessions (member_id, title) VALUES (:member_id, :title)');
        $sessionStmt->execute(['member_id' => $user['user_id'], 'title' => mb_substr($question, 0, 160)]);
        $sessionId = (int) db()->lastInsertId();
    }

    $messageStmt = db()->prepare('INSERT INTO ai_chat_messages (session_id, sender, message) VALUES (:session_id, :sender, :message)');
    $messageStmt->execute(['session_id' => $sessionId, 'sender' => 'member', 'message' => $question]);

    try {
        $answer = answer_member_question((int) $user['user_id'], $question);
        $messageStmt->execute(['session_id' => $sessionId, 'sender' => 'assistant', 'message' => $answer]);
        flash_set('success', 'The financial advisor responded.');
    } catch (Throwable $exception) {
        error_log('Member AI advisor request failed: ' . $exception->getMessage());
        $message = str_contains($exception->getMessage(), 'Ollama took too long')
            ? 'The AI advisor took too long to respond. Try a shorter question or a smaller, faster model.'
            : 'The AI advisor is temporarily unavailable. Start Ollama and verify the configured model.';
        flash_set('error', $message);
    }
    redirect('member/ai_advisor.php?session=' . $sessionId);
}

if ($sessionId > 0) {
    $sessionStmt = db()->prepare('SELECT session_id FROM ai_advice_sessions WHERE session_id = :session_id AND member_id = :member_id LIMIT 1');
    $sessionStmt->execute(['session_id' => $sessionId, 'member_id' => $user['user_id']]);
    if (!$sessionStmt->fetch()) {
        $sessionId = 0;
    }
}

$messages = [];
$previousSessions = [];
if ($sessionId > 0) {
    $messageStmt = db()->prepare('SELECT sender, message, created_at FROM ai_chat_messages WHERE session_id = :session_id ORDER BY message_id ASC');
    $messageStmt->execute(['session_id' => $sessionId]);
    $messages = $messageStmt->fetchAll();
}

$sessionListStmt = db()->prepare(
    'SELECT session_id, title, created_at FROM ai_advice_sessions WHERE member_id = :member_id ORDER BY created_at DESC, session_id DESC LIMIT 10'
);
$sessionListStmt->execute(['member_id' => $user['user_id']]);
$previousSessions = $sessionListStmt->fetchAll();

$pageTitle = 'AI Financial Advisor | Member Dashboard';
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
        <a class="member-nav-link" href="<?php echo e(url('member/investments.php')); ?>"><span>&#9679;</span> Investments</a>
        <a class="member-nav-link active" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span>&#10022;</span> AI Advisor</a>
        <a class="member-nav-link" href="<?php echo e(url('member/profile.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> AI Advisor</div>
        <div class="member-heading ai-advisor-heading"><div><h1>AI Financial Advisor</h1><p>Ask questions about your ChamaWise financial records.</p></div><a class="btn btn-outline-brand" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span aria-hidden="true">+</span> New Conversation</a></div>
        <div class="ai-disclaimer" role="note"><strong>Important:</strong> This is AI-generated general financial guidance, not professional financial, legal, tax, or investment advice. Verify important decisions with a qualified human advisor.</div>

        <?php if ($previousSessions): ?>
            <section class="ai-history" aria-labelledby="ai-history-title">
                <h2 id="ai-history-title">Recent conversations</h2>
                <nav class="ai-history-list" aria-label="Previous conversations">
                    <?php foreach ($previousSessions as $history): ?>
                        <a class="ai-history-link<?php echo (int) $history['session_id'] === $sessionId ? ' active' : ''; ?>" href="<?php echo e(url('member/ai_advisor.php?session=' . (int) $history['session_id'])); ?>"<?php echo (int) $history['session_id'] === $sessionId ? ' aria-current="page"' : ''; ?>>
                            <span><?php echo e($history['title'] ?: 'Conversation #' . (int) $history['session_id']); ?></span>
                            <small><?php echo e(date('j M', strtotime((string) $history['created_at']))); ?></small>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </section>
        <?php endif; ?>

        <article class="profile-panel ai-chat-panel">
            <div class="ai-chat-heading">
                <div><span class="ai-chat-heading-icon" aria-hidden="true">&#10022;</span><div><h2><?php echo $sessionId > 0 ? 'Your conversation' : 'How can I help?'; ?></h2><p>Answers use your ChamaWise records and are visible only to you.</p></div></div>
                <span class="ai-privacy-note"><span aria-hidden="true">&#128274;</span> Private</span>
            </div>
            <div class="ai-chat-messages<?php echo $messages ? ' has-messages' : ' is-empty'; ?>" aria-live="polite" aria-relevant="additions text">
                <?php if ($messages): ?>
                    <?php foreach ($messages as $message): ?>
                        <div class="ai-message <?php echo $message['sender'] === 'member' ? 'ai-message-member' : 'ai-message-assistant'; ?>">
                            <strong><?php echo $message['sender'] === 'member' ? 'You' : 'ChamaWise Advisor'; ?></strong>
                            <p><?php echo nl2br(e($message['message'])); ?></p>
                            <small><?php echo e($message['created_at']); ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="ai-empty-state">
                        <span class="ai-empty-icon" aria-hidden="true">&#10022;</span>
                        <h3>Let’s make your chama finances clearer.</h3>
                        <p>Ask a question below, or choose a suggested topic to get started.</p>
                        <div class="ai-prompt-list" aria-label="Suggested questions">
                            <button type="button" data-ai-prompt="Summarise my chama contributions.">My contributions</button>
                            <button type="button" data-ai-prompt="What is my outstanding loan balance?">My loan balance</button>
                            <button type="button" data-ai-prompt="Explain my savings and investment goals.">Savings goals</button>
                            <button type="button" data-ai-prompt="How do contribution penalties work for my account?">Contribution penalties</button>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="ai-loading-state" hidden role="status" aria-live="polite">
                    <span class="ai-loading-spinner" aria-hidden="true"></span>
                    <span><strong>ChamaWise Advisor is thinking</strong><small>Checking your records and preparing a helpful answer…</small></span>
                </div>
            </div>
            <form method="post" class="ai-chat-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="session_id" value="<?php echo e((string) $sessionId); ?>">
                <label class="visually-hidden" for="ai-question">Your question</label>
                <textarea id="ai-question" name="question" maxlength="1000" placeholder="Ask about contributions, loans, savings goals..." required></textarea>
                <div class="ai-chat-form-footer"><small>Your question is limited to ChamaWise and chama finances.</small><button class="btn btn-brand" type="submit">Send Question <span aria-hidden="true">&#8594;</span></button></div>
            </form>
        </article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
