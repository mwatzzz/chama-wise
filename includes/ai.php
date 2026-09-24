<?php

declare(strict_types=1);

interface AIProvider
{
    /** @param array<string, mixed> $context */
    public function answer(string $question, array $context): string;
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

    public function answer(string $question, array $context): string
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
                    'content' => 'You are ChamaWise financial guidance assistant. Provide general educational financial guidance, not professional financial, legal, tax, or investment advice. Clearly remind the member to verify important decisions with a qualified human advisor. Use only the supplied member context. Never invent financial amounts, balances, penalties, repayments, or account facts. If the context does not contain an answer, say that the information is unavailable.',
                ],
                [
                    'role' => 'user',
                    'content' => "Member financial context:\n" . json_encode($context, JSON_PRETTY_PRINT) . "\n\nQuestion:\n" . $question,
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

    public function answer(string $question, array $context): string
    {
        $payload = json_encode([
            'model' => $this->model,
            'stream' => false,
            'options' => ['temperature' => 0.2],
            'system' => 'You are ChamaWise financial guidance assistant. Provide general educational financial guidance, not professional financial, legal, tax, or investment advice. Clearly remind the member to verify important decisions with a qualified human advisor. Use only the supplied member context. Never invent financial amounts, balances, penalties, repayments, or account facts. If the context does not contain an answer, say that the information is unavailable.',
            'prompt' => "Member financial context:\n" . json_encode($context, JSON_PRETTY_PRINT) . "\n\nQuestion:\n" . $question,
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
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $error !== '') {
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

        return $this->provider->answer($question, $context);
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

function answer_member_question(int $memberId, string $question): string
{
    return ai_service()->answer($question, build_member_financial_context($memberId));
}
