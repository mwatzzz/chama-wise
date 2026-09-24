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
        flash_set('error', 'The AI advisor is temporarily unavailable. Start Ollama and verify the configured model.');
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
if ($sessionId > 0) {
    $messageStmt = db()->prepare('SELECT sender, message, created_at FROM ai_chat_messages WHERE session_id = :session_id ORDER BY message_id ASC');
    $messageStmt->execute(['session_id' => $sessionId]);
    $messages = $messageStmt->fetchAll();
}

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
        <a class="member-nav-link active" href="<?php echo e(url('member/ai_advisor.php')); ?>"><span>&#10022;</span> AI Advisor</a>
        <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span>&#9679;</span> Profile</a>
    </nav><a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span>&#8250;</span> AI Advisor</div>
        <div class="member-heading"><div><h1>AI Financial Advisor</h1><p>Ask questions about your ChamaWise financial records.</p></div><a class="btn btn-outline-brand" href="<?php echo e(url('member/ai_advisor.php')); ?>">New Conversation</a></div>
        <div class="ai-disclaimer" role="note"><strong>Important:</strong> This is AI-generated general financial guidance, not professional financial, legal, tax, or investment advice. Verify important decisions with a qualified human advisor.</div>
        <article class="profile-panel ai-chat-panel">
            <div class="ai-chat-messages"><?php if ($messages): ?><?php foreach ($messages as $message): ?><div class="ai-message <?php echo $message['sender'] === 'member' ? 'ai-message-member' : 'ai-message-assistant'; ?>"><strong><?php echo $message['sender'] === 'member' ? 'You' : 'ChamaWise Advisor'; ?></strong><p><?php echo nl2br(e($message['message'])); ?></p><small><?php echo e($message['created_at']); ?></small></div><?php endforeach; ?><?php else: ?><div class="empty-module"><span>&#10022;</span><p>Ask how your contributions, loans, repayments, or penalties are progressing.</p></div><?php endif; ?></div>
            <form method="post" class="ai-chat-form"><?php echo csrf_field(); ?><input type="hidden" name="session_id" value="<?php echo e((string) $sessionId); ?>"><textarea name="question" maxlength="1000" placeholder="Ask a financial question..." required></textarea><button class="btn btn-brand" type="submit">Send Question</button></form>
        </article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
