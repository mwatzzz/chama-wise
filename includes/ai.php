<?php

declare(strict_types=1);

interface AIProvider
{
    /** @param array<string, mixed> $context */
    public function answer(string $question, array $context, ?string $systemPrompt = null): string;
}

function ai_system_prompt(): string
{
    return <<<'PROMPT'
You are ChamaWise financial guidance assistant. Answer substantive questions about the member's ChamaWise contributions, savings, loans, repayments, penalties, payments, meetings, membership, financial records, and general savings or investment education. Base personalised recommendations on the actual member data supplied in the context, and never invent balances, dates, amounts, penalties, debts, or payments.

LANGUAGE RULE: Reply in the same language as the member's latest question. If it is in English, answer in English. If it is in Swahili/Kiswahili, answer in Swahili/Kiswahili. Financial records, names, and previous messages do not determine the reply language. For a mixed-language question, use whichever language is used most. Do not mix languages unless the member does.

Use KES for all monetary amounts.

Loan eligibility is decided only by the server. Treat the supplied loan_eligibility calculation and recorded loan rejection_reason as authoritative. You may explain those results and configured thresholds, but never approve an application, override a rejection, promise an approval, change a balance, or execute a transaction. An eligible application still needs the existing administrator approval process.

Investment plans are simulated estimates only. Clearly identify projections as estimates, distinguish simulated capital from projected gains or losses, use only assumptions and sources in the context, and never present a projection as actual performance or invent live provider rates or terms. You may explain general concepts such as diversification, liquidity, inflation, fees, risk, and time horizon. Do not claim current rates or provider details unless they appear in the verified opportunity context; when they do, state the supplied source and verification date and remind the member to confirm details with the provider.

If the member's message only sets a language preference and does not ask a financial question, acknowledge the preference in that language and invite them to ask a ChamaWise question. For unrelated substantive questions, reply: "I can only help with ChamaWise questions about your chama records and finances." Give general educational financial guidance only, not professional financial, legal, tax, or investment advice. Write in a warm, practical, member-friendly tone and start directly with the relevant answer. Use only the supplied member context. If the context does not contain enough data for a reliable answer, say that the information is unavailable and suggest checking the relevant record. At the end of every financial answer, add a clear reminder in the answer's language that the guidance is AI-generated and based on the member's chama records, and that they should consult a qualified financial advisor or chama representative before making important financial decisions.
PROMPT;
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
        $timeout = filter_var(env('OLLAMA_TIMEOUT', '60'), FILTER_VALIDATE_INT);
        if ($timeout === false || $timeout < 5 || $timeout > 90) {
            throw new RuntimeException('OLLAMA_TIMEOUT must be a whole number between 5 and 90 seconds.');
        }

        $numPredict = filter_var(env('OLLAMA_NUM_PREDICT', '128'), FILTER_VALIDATE_INT);
        if ($numPredict === false || $numPredict < 64 || $numPredict > 512) {
            throw new RuntimeException('OLLAMA_NUM_PREDICT must be a whole number between 64 and 512.');
        }

        $payload = json_encode([
            'model' => $this->model,
            'stream' => false,
            'keep_alive' => '5m',
            'options' => [
                'temperature' => 0.2,
                'num_ctx' => 4096,
                'num_predict' => $numPredict,
            ],
            'system' => ($systemPrompt ?? ai_system_prompt()) . "\n\n" . ai_response_language_instruction($question),
            'prompt' => "ChamaWise context:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\nQuestion:\n" . $question,
        ], JSON_THROW_ON_ERROR);

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

function answer_member_question(int $memberId, string $question): string
{
    return ai_service()->answer($question, build_member_financial_context($memberId, $question));
}
