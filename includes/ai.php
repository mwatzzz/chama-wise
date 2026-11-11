<?php

declare(strict_types=1);

interface AIProvider
{
    /** @param array<string, mixed> $context */
    public function answer(string $question, array $context, ?string $systemPrompt = null): string;
}

function ensure_ai_usage_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS ai_member_daily_usage (
            member_id INT UNSIGNED NOT NULL,
            usage_date DATE NOT NULL,
            request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (member_id, usage_date),
            CONSTRAINT fk_ai_usage_member FOREIGN KEY (member_id) REFERENCES members (member_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $checked = true;
}

function consume_ai_member_daily_quota(int $memberId): bool
{
    $stmt = db()->prepare(
        'INSERT INTO ai_member_daily_usage (member_id, usage_date, request_count)
         VALUES (:member_id, UTC_DATE(), 1)
         ON DUPLICATE KEY UPDATE request_count = IF(request_count <= :daily_limit, request_count + 1, request_count)'
    );
    $stmt->execute(['member_id' => $memberId, 'daily_limit' => AI_MEMBER_DAILY_LIMIT]);

    $usageStmt = db()->prepare(
        'SELECT request_count FROM ai_member_daily_usage WHERE member_id = :member_id AND usage_date = UTC_DATE()'
    );
    $usageStmt->execute(['member_id' => $memberId]);

    return (int) $usageStmt->fetchColumn() <= AI_MEMBER_DAILY_LIMIT;
}

function ai_system_prompt(): string
{
    return <<<'PROMPT'
You are ChamaWise's warm, practical financial guidance assistant. Use supplied context for member-specific facts; general savings/investment education is allowed. Never invent member data or current rates. Reply in the language of the latest question (English or Kiswahili), using KES for money. If records are missing, say so. For unrelated substantive requests, say you only help with ChamaWise and chama finances. Give general education, not professional financial, legal, tax, or investment advice.
If the user only requests a language preference, acknowledge it in that language and invite a financial question.

Loan eligibility and rejection reasons supplied by the server are authoritative. Explain them but never approve or promise a loan, override a rejection, change a balance, or execute a transaction; approvals require the administrator.

Investment plans are simulations, not actual returns. Distinguish capital from estimated gain/loss and use only supplied assumptions and verified opportunities. Do not invent fees, taxes, compounding, timing, providers, rates, or terms; if a detail is missing, say it is not included or unavailable. Cite supplied opportunity source/date and recommend confirming details with the provider.

For financial guidance, end with a brief reminder in the answer's language that it is AI-generated from chama records and important decisions should be checked with a qualified advisor or chama representative.
PROMPT;
}

function ai_answer_length_instruction(string $question): string
{
    $complex = preg_match('/\b(explain|compare|difference|plan|planning|project|projection|calculate|why|how should|steps|risk|risks|invest|uwekezaji|fafanua|linganisha|mpango|hatari)\b/i', $question) === 1;

    return $complex
        ? 'Give a clear, useful explanation with the relevant figures, assumptions, and risks; stay focused and avoid repetition.'
        : 'Answer directly in 1-3 short sentences, using the most relevant figure when available; avoid a long preamble.';
}

function ai_ensure_financial_disclaimer(string $question, string $answer): string
{
    $languagePreferenceOnly = preg_match(
        '/^\s*(?:please\s+)?(?:answer|reply|respond|speak|write)\s+(?:only\s+)?in\s+(?:english|swahili|kiswahili)\s*(?:please)?[.!?]*\s*$/i',
        $question
    ) === 1;
    if ($languagePreferenceOnly) {
        return $answer;
    }

    $swahili = str_starts_with(ai_response_language_instruction($question), 'Swali')
        || str_starts_with(ai_response_language_instruction($question), 'Mtumiaji');
    $hasDisclaimer = $swahili
        ? preg_match('/\bAI\b/i', $answer) === 1 && preg_match('/mshauri|mwakilishi/i', $answer) === 1
        : preg_match('/AI[- ]generated/i', $answer) === 1 && preg_match('/advisor|representative/i', $answer) === 1;
    if ($hasDisclaimer) {
        return $answer;
    }

    $disclaimer = $swahili
        ? 'Mwongozo huu umetolewa na AI kutokana na rekodi za chama chako; thibitisha maamuzi muhimu na mshauri wa fedha au mwakilishi wa chama.'
        : 'This is AI-generated guidance based on your chama records; verify important decisions with a qualified financial advisor or chama representative.';
    return rtrim($answer) . "\n\n" . $disclaimer;
}

function ai_response_language_instruction(string $question): string
{
    if (preg_match('/\b(?:answer|reply|respond)\s+in\s+(english)\b|\b(?:in|use)\s+(english)\s+please\b/i', $question) === 1) {
        return 'The user explicitly requested English. Write the entire answer in English only.';
    }
    if (preg_match('/\b(?:answer|reply|respond)\s+in\s+(swahili|kiswahili)\b|\b(?:in|kwa|use)\s+(swahili|kiswahili)\b/i', $question) === 1) {
        return 'Mtumiaji ameomba jibu kwa Kiswahili. Andika jibu lote kwa Kiswahili pekee.';
    }

    $swahiliMarkers = [
        'tafadhali', 'naomba', 'nisaidie', 'nifafanulie', 'nieleze', 'niambie',
        'nifupishie', 'nina', 'nime', 'sina', 'yangu', 'zangu', 'wangu',
        'wetu', 'yetu', 'zenu', 'nini', 'nani', 'lini', 'kiasi', 'mchango',
        'michango', 'mkopo', 'mikopo', 'deni', 'madeni', 'malipo', 'akiba',
        'mwezi', 'mwaka', 'jana', 'leo', 'kesho', 'jinsi', 'gani', 'imepokelewa',
        'imerekodiwa', 'imebaki', 'imeongezeka', 'imepungua', 'je', 'hii', 'hiyo',
        'hizi', 'hizo', 'nipe', 'unaweza', 'ninaweza', 'kwa nini', 'shilingi',
    ];
    $normalizedQuestion = mb_strtolower($question);
    $swahiliScore = 0;
    foreach ($swahiliMarkers as $marker) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($marker, '/') . '(?![\p{L}\p{N}])/u', $normalizedQuestion) === 1) {
            $swahiliScore++;
        }
    }

    return $swahiliScore > 0
        ? 'Swali la mwisho la mtumiaji limeulizwa kwa Kiswahili. Andika jibu lote kwa Kiswahili pekee; usijibu kwa Kiingereza.'
        : 'The latest user question is in English. Write the entire answer in English only; do not answer in Swahili.';
}

final class OpenAIProvider implements AIProvider
{
    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? (string) env('OPENAI_API_KEY', '');
        $this->model = $model ?? (string) env('OPENAI_MODEL', 'gpt-4o-mini');
    }

    public function answer(string $question, array $context, ?string $systemPrompt = null): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('OpenAI is not configured. Add OPENAI_API_KEY to your .env file.');
        }

        $payload = json_encode([
            'model' => $this->model,
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => ($systemPrompt ?? ai_system_prompt()) . "\n\n" . ai_response_language_instruction($question),
                ],
                [
                    'role' => 'user',
                    'content' => "ChamaWise context:\n" . json_encode($context, JSON_PRETTY_PRINT) . "\n\nQuestion:\n" . $question,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $curl = curl_init('https://api.openai.com/v1/chat/completions');
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the OpenAI request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $error !== '') {
            throw new RuntimeException('OpenAI request failed.');
        }

        $decoded = json_decode($response, true);
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            throw new RuntimeException('OpenAI returned an invalid response.');
        }

        $answer = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($answer) || trim($answer) === '') {
            throw new RuntimeException('OpenAI returned no answer.');
        }

        return trim($answer);
    }
}

final class OllamaProvider implements AIProvider
{
    private string $baseUrl;
    private string $model;

    public function __construct(?string $baseUrl = null, ?string $model = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) env('OLLAMA_URL', 'http://127.0.0.1:11434'), '/');
        $this->model = $model ?? (string) env('OLLAMA_MODEL', 'llama3.2');
    }

    public function answer(string $question, array $context, ?string $systemPrompt = null): string
    {
        $timeout = $this->timeout();
        $payload = $this->requestPayload($question, $context, $systemPrompt, false);

        $curl = curl_init($this->baseUrl . '/api/generate');
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the Ollama request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        $errorCode = curl_errno($curl);
        curl_close($curl);

        if ($response === false || $error !== '') {
            if ($errorCode === CURLE_OPERATION_TIMEDOUT) {
                throw new RuntimeException('Ollama took too long to answer. Try a shorter question or a smaller, faster model.');
            }
            throw new RuntimeException('Ollama is unavailable. Start Ollama and verify the configured model.');
        }

        $decoded = json_decode($response, true);
        $answer = is_array($decoded) ? ($decoded['response'] ?? null) : null;
        if ($statusCode < 200 || $statusCode >= 300 || !is_string($answer) || trim($answer) === '') {
            throw new RuntimeException('Ollama returned no answer.');
        }

        return trim($answer);
    }

    /**
     * @param array<string, mixed> $context
     * @param callable(string): void $onToken
     * @return array{answer: string, first_token_ms: int, generation_ms: int}
     */
    public function streamAnswer(string $question, array $context, callable $onToken, ?string $systemPrompt = null): array
    {
        $timeout = $this->timeout();
        $payload = $this->requestPayload($question, $context, $systemPrompt, true);
        $curl = curl_init($this->baseUrl . '/api/generate');
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the Ollama request.');
        }

        $answer = '';
        $buffer = '';
        $providerError = null;
        $firstTokenAt = null;
        $startedAt = microtime(true);
        $completed = false;
        $processLine = static function (string $line) use (&$answer, &$providerError, &$firstTokenAt, &$completed, $startedAt, $onToken): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                $providerError = 'Ollama returned an invalid streaming response.';
                return;
            }
            if (isset($data['error'])) {
                $providerError = 'Ollama could not generate a response. Check that the local service and configured model are available.';
                return;
            }
            if (isset($data['response']) && is_string($data['response']) && $data['response'] !== '') {
                if ($firstTokenAt === null) {
                    $firstTokenAt = microtime(true);
                }
                $answer .= $data['response'];
                $onToken($data['response']);
            }
            if (($data['done'] ?? false) === true) {
                $completed = true;
            }
        };

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffer, &$providerError, $processLine): int {
                $buffer .= $chunk;
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $processLine($line);
                    if ($providerError !== null) {
                        return 0;
                    }
                }
                return strlen($chunk);
            },
        ]);

        $response = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errorCode = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($buffer !== '') {
            $processLine($buffer);
        }
        if ($providerError !== null) {
            throw new RuntimeException($providerError);
        }
        if ($response === false || $error !== '') {
            if ($errorCode === CURLE_OPERATION_TIMEDOUT) {
                throw new RuntimeException('Ollama took too long to answer. Try a shorter question or a faster local model.');
            }
            throw new RuntimeException('Ollama is unavailable. Start Ollama and verify the configured model.');
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('Ollama could not generate a response. Check that the local service and configured model are available.');
        }
        if (!$completed || trim($answer) === '') {
            throw new RuntimeException('Ollama response ended before it was complete.');
        }

        return [
            'answer' => trim($answer),
            'first_token_ms' => $firstTokenAt === null ? 0 : (int) round(($firstTokenAt - $startedAt) * 1000),
            'generation_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function timeout(): int
    {
        $timeout = filter_var(env('OLLAMA_TIMEOUT', '90'), FILTER_VALIDATE_INT);
        if ($timeout === false || $timeout < 5 || $timeout > 90) {
            throw new RuntimeException('OLLAMA_TIMEOUT must be a whole number between 5 and 90 seconds.');
        }
        return $timeout;
    }

    /** @param array<string, mixed> $context */
    private function requestPayload(string $question, array $context, ?string $systemPrompt, bool $stream): string
    {
        $numPredict = filter_var(env('OLLAMA_NUM_PREDICT', '256'), FILTER_VALIDATE_INT);
        if ($numPredict === false || $numPredict < 64 || $numPredict > 512) {
            throw new RuntimeException('OLLAMA_NUM_PREDICT must be a whole number between 64 and 512.');
        }
        if (preg_match('/\b(explain|compare|difference|plan|planning|project|projection|calculate|why|how should|steps|risk|risks|invest|uwekezaji|fafanua|linganisha|mpango|hatari)\b/i', $question) !== 1) {
            $numPredict = min($numPredict, 96);
        }

        $conversation = $context['recent_conversation'] ?? [];
        unset($context['recent_conversation']);
        $prompt = "Member data:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_array($conversation) && $conversation !== []) {
            $prompt .= "\n\nRecent conversation:\n";
            foreach ($conversation as $message) {
                if (is_array($message) && isset($message['sender'], $message['message'])) {
                    $speaker = $message['sender'] === 'assistant' ? 'Advisor' : 'Member';
                    $prompt .= $speaker . ': ' . (string) $message['message'] . "\n";
                }
            }
        }
        $prompt .= "\nQuestion:\n" . $question;

        return json_encode([
            'model' => $this->model,
            'stream' => $stream,
            'keep_alive' => '5m',
            'options' => [
                'temperature' => 0.2,
                'num_ctx' => 4096,
                'num_predict' => $numPredict,
            ],
            'system' => ($systemPrompt ?? ai_system_prompt()) . "\n\n"
                . ai_response_language_instruction($question) . "\n"
                . ai_answer_length_instruction($question),
            'prompt' => $prompt,
        ], JSON_THROW_ON_ERROR);
    }
}

final class AIService
{
    public function __construct(private AIProvider $provider)
    {
    }

    /** @param array<string, mixed> $context */
    public function answer(string $question, array $context): string
    {
        $question = trim($question);
        if ($question === '') {
            throw new InvalidArgumentException('A question is required.');
        }

        if (!is_chama_question($question)) {
            throw new InvalidArgumentException('The AI advisor only answers ChamaWise questions about chama records and finances.');
        }

        return $this->provider->answer($question, $context);
    }

    /** @param array<string, mixed> $context */
    public function answerWithSystemPrompt(string $question, array $context, string $systemPrompt): string
    {
        $question = trim($question);
        if ($question === '' || $systemPrompt === '') {
            throw new InvalidArgumentException('A question and system prompt are required.');
        }

        return $this->provider->answer($question, $context, $systemPrompt);
    }

    /** @param array<string, mixed> $context
     *  @param callable(string): void $onToken
     *  @return array{answer: string, first_token_ms: int, generation_ms: int}
     */
    public function streamAnswer(string $question, array $context, callable $onToken): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new InvalidArgumentException('A question is required.');
        }
        if (!is_chama_question($question)) {
            throw new InvalidArgumentException('The AI advisor only answers ChamaWise questions about chama records and finances.');
        }
        if (!$this->provider instanceof OllamaProvider) {
            $startedAt = microtime(true);
            $answer = $this->provider->answer($question, $context);
            $onToken($answer);
            return [
                'answer' => $answer,
                'first_token_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'generation_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }
        return $this->provider->streamAnswer($question, $context, $onToken);
    }
}

function ai_service(): AIService
{
    static $service = null;
    if (!$service instanceof AIService) {
        $providerName = strtolower((string) env('AI_PROVIDER', 'ollama'));
        $provider = $providerName === 'openai' ? new OpenAIProvider() : new OllamaProvider();
        $service = new AIService($provider);
    }

    return $service;
}

function ollama_ai_service(): AIService
{
    static $service = null;
    if (!$service instanceof AIService) {
        $service = new AIService(new OllamaProvider());
    }

    return $service;
}

function answer_member_question(int $memberId, string $question, array $recentConversation = []): string
{
    $context = build_member_financial_context($memberId, $question);
    if ($recentConversation !== []) {
        $context['recent_conversation'] = $recentConversation;
    }
    return ai_ensure_financial_disclaimer($question, ai_service()->answer($question, $context));
}

/** @param array<string, mixed> $recentConversation
 *  @param callable(string): void $onToken
 *  @return array{answer: string, first_token_ms: int, generation_ms: int, context_ms: int}
 */
function stream_member_question(int $memberId, string $question, array $recentConversation, callable $onToken): array
{
    $contextStartedAt = microtime(true);
    $context = build_member_financial_context($memberId, $question);
    if ($recentConversation !== []) {
        $context['recent_conversation'] = $recentConversation;
    }
    $contextMs = (int) round((microtime(true) - $contextStartedAt) * 1000);
    $result = ai_service()->streamAnswer($question, $context, $onToken);
    $answer = ai_ensure_financial_disclaimer($question, $result['answer']);
    if ($answer !== $result['answer']) {
        $onToken(substr($answer, strlen($result['answer'])));
    }
    $result['answer'] = $answer;
    $result['context_ms'] = $contextMs;
    return $result;
}
