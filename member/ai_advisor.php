<?php

declare(strict_types=1);

$aiRequestStartedAt = microtime(true);
require_once dirname(__DIR__) . '/config/app.php';
require_member();
$aiBootstrapMs = (int) round((microtime(true) - $aiRequestStartedAt) * 1000);

function ai_advisor_stream_event(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

function ai_advisor_stream_error_response(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
$sessionId = (int) ($_GET['session'] ?? $_POST['session_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $streamRequest = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream');
    if (!csrf_verify()) {
        if ($streamRequest) {
            ai_advisor_stream_error_response(403, 'Your session expired. Please try again.');
        }
        flash_set('error', 'Your session expired. Please try again.');
        redirect('member/ai_advisor.php');
    }

    $question = trim((string) ($_POST['question'] ?? ''));
    if ($question === '') {
        if ($streamRequest) {
            ai_advisor_stream_error_response(422, 'Enter a question for the financial advisor.');
        }
        flash_set('error', 'Enter a question for the financial advisor.');
        redirect('member/ai_advisor.php' . ($sessionId > 0 ? '?session=' . $sessionId : ''));
    }

    if (!is_chama_question($question)) {
        if ($streamRequest) {
            ai_advisor_stream_error_response(422, 'The AI advisor only answers ChamaWise questions about chama records and finances.');
        }
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

    $recentConversation = [];
    if ($sessionId > 0) {
        $historyStmt = db()->prepare(
            'SELECT sender, message FROM ai_chat_messages
             WHERE session_id = :session_id ORDER BY message_id DESC LIMIT 6'
        );
        $historyStmt->execute(['session_id' => $sessionId]);
        $recentConversation = array_reverse($historyStmt->fetchAll(PDO::FETCH_ASSOC));
        $recentConversation = array_map(static fn (array $message): array => [
            'sender' => (string) $message['sender'],
            'message' => mb_substr((string) $message['message'], 0, 500),
        ], $recentConversation);
    }

    if (!consume_ai_member_daily_quota((int) $user['user_id'])) {
        if ($streamRequest) {
            ai_advisor_stream_error_response(429, 'You have reached today’s AI advisor limit. Please try again tomorrow.');
        }
        flash_set('error', 'You have reached today’s AI advisor limit. Please try again tomorrow.');
        redirect('member/ai_advisor.php' . ($sessionId > 0 ? '?session=' . $sessionId : ''));
    }

    if ($sessionId === 0) {
        $sessionStmt = db()->prepare('INSERT INTO ai_advice_sessions (member_id, title) VALUES (:member_id, :title)');
        $sessionStmt->execute(['member_id' => $user['user_id'], 'title' => mb_substr($question, 0, 160)]);
        $sessionId = (int) db()->lastInsertId();
    }

    $messageStmt = db()->prepare('INSERT INTO ai_chat_messages (session_id, sender, message) VALUES (:session_id, :sender, :message)');
    $messageStmt->execute(['session_id' => $sessionId, 'sender' => 'member', 'message' => $question]);

    if ($streamRequest) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        ini_set('output_buffering', 'off');
        ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ai_advisor_stream_event('start', [
            'session_id' => $sessionId,
            'bootstrap_ms' => $aiBootstrapMs,
        ]);
        try {
            $streamResult = stream_member_question(
                (int) $user['user_id'],
                $question,
                $recentConversation,
                static function (string $token): void {
                    ai_advisor_stream_event('token', ['text' => $token]);
                }
            );
            $messageStmt->execute([
                'session_id' => $sessionId,
                'sender' => 'assistant',
                'message' => $streamResult['answer'],
            ]);
            ai_advisor_stream_event('complete', [
                'session_id' => $sessionId,
                'bootstrap_ms' => $aiBootstrapMs,
                'context_ms' => $streamResult['context_ms'],
                'model_first_token_ms' => $streamResult['first_token_ms'],
                'model_total_ms' => $streamResult['generation_ms'],
                'total_ms' => (int) round((microtime(true) - $aiRequestStartedAt) * 1000),
            ]);
        } catch (Throwable $exception) {
            error_log('Member AI advisor stream failed (' . get_class($exception) . ').');
            $message = str_contains($exception->getMessage(), 'Ollama took too long')
                ? 'The AI advisor took too long to respond. Try a shorter question or a faster local model.'
                : 'The AI advisor is temporarily unavailable. Check that Ollama and the configured model are running.';
            ai_advisor_stream_event('error', ['message' => $message]);
        }
        exit;
    }

    try {
        $answer = answer_member_question((int) $user['user_id'], $question, $recentConversation);
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
    $messageStmt = db()->prepare(
        'SELECT sender, message, created_at FROM (
            SELECT message_id, sender, message, created_at
            FROM ai_chat_messages WHERE session_id = :session_id
            ORDER BY message_id DESC LIMIT 41
         ) recent_messages ORDER BY message_id ASC'
    );
    $messageStmt->execute(['session_id' => $sessionId]);
    $messages = $messageStmt->fetchAll();
    $hasOlderMessages = count($messages) > 40;
    if ($hasOlderMessages) {
        array_shift($messages);
    }
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
    <?php require ROOT_PATH . '/includes/member_sidebar.php'; ?>
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
            <?php if (!empty($hasOlderMessages)): ?>
                <p class="text-muted">Showing the latest 40 messages. Earlier messages remain saved.</p>
            <?php endif; ?>
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
