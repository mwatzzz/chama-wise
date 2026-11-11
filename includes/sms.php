<?php

declare(strict_types=1);

function normalize_sms_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '254')) {
        return '+' . $digits;
    }

    if (str_starts_with($digits, '0')) {
        return '+254' . substr($digits, 1);
    }

    if (str_starts_with($digits, '7')) {
        return '+254' . $digits;
    }

    return '+' . $digits;
}

function mobilesasa_sms_response_result(string|false $response, int $httpCode, bool $networkError = false): array
{
    if ($networkError || $response === false) {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa network request failed.'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa authentication failed or account access was denied.'];
        }

        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa returned a malformed response.'];
    }

    $responseText = [];
    $collectResponseText = static function (array $values) use (&$collectResponseText, &$responseText): void {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $collectResponseText($value);
            } elseif (is_scalar($value) && is_string($key) && preg_match('/message|error|description|detail/i', $key)) {
                $responseText[] = (string) $value;
            }
        }
    };
    $collectResponseText($decoded);
    $providerMessage = strtolower(implode(' ', $responseText));

    if (preg_match('/insufficient\s+(?:(?:account|sms)\s+)?balance|low\s+balance|balance\s+(?:is\s+)?(?:insufficient|low)|not\s+enough\s+(?:account\s+)?balance/i', $providerMessage)) {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa account has insufficient SMS balance.'];
    }

    if (preg_match('/invalid\s+(?:mobile\s+)?(?:phone|number)|(?:phone|number)\s+(?:is\s+)?(?:invalid|not valid)|invalid\s+recipient/i', $providerMessage)) {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa rejected the phone number as invalid.'];
    }

    if ($httpCode === 401 || $httpCode === 403 || preg_match('/unauthori[sz]ed|authentication failed|invalid api token|invalid token/i', $providerMessage)) {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa authentication failed or account access was denied.'];
    }

    if ($httpCode >= 500) {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa service is temporarily unavailable.'];
    }

    if (in_array($httpCode, [400, 422], true)) {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa rejected the request. Check the phone number, sender ID, and message.'];
    }

    $responseCode = $decoded['responseCode'] ?? null;
    if ($httpCode >= 200 && $httpCode < 300
        && ($decoded['status'] ?? null) === true
        && is_scalar($responseCode)
        && (string) $responseCode === '0200') {
        return ['success' => true, 'provider' => 'mobilesasa', 'message' => 'SMS accepted by MobileSasa for processing.'];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa rejected the SMS request.'];
    }

    return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa returned an unexpected response.'];
}

function send_mobilesasa_sms(string $number, string $message, ?callable $httpRequest = null): array
{
    $apiToken = trim((string) env('MOBILESASA_API_TOKEN', ''));
    $senderId = trim((string) env('MOBILESASA_SENDER_ID', ''));

    if ($apiToken === '') {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa API token is not configured.'];
    }

    if ($senderId === '') {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'MobileSasa sender ID is not configured.'];
    }

    if ($httpRequest === null && !function_exists('curl_init')) {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'cURL is not available for SMS delivery.'];
    }

    $requestBody = json_encode([
        'senderID' => $senderId,
        'phone' => $number,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($requestBody === false) {
        return ['success' => false, 'provider' => 'mobilesasa', 'message' => 'SMS content could not be encoded for MobileSasa.'];
    }

    $url = 'https://api.mobilesasa.com/v1/send/message';
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
    ];

    if ($httpRequest !== null) {
        $result = $httpRequest($url, $headers, $requestBody);
        $response = $result['response'] ?? false;
        $httpCode = (int) ($result['http_code'] ?? 0);
        $networkError = (bool) ($result['network_error'] ?? false);
    } else {
        $ch = curl_init($url);
        if ($ch === false) {
            return mobilesasa_sms_response_result(false, 0, true);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $networkError = $response === false || curl_errno($ch) !== 0;
        curl_close($ch);
    }

    return mobilesasa_sms_response_result(is_string($response) ? $response : false, $httpCode, $networkError);
}

function send_sms(string $to, string $message, ?string $providerOverride = null): array
{
    $provider = strtolower($providerOverride ?? (string) env('SMS_PROVIDER', 'log'));
    $number = normalize_sms_phone($to);
    $content = trim((string) $message);

    if ($number === '' || $content === '') {
        return ['success' => false, 'provider' => $provider, 'message' => 'Phone number or SMS text is empty.'];
    }

    if ($provider === 'log' || $provider === 'dev') {
        error_log('SMS queued in local log mode.');
        return ['success' => true, 'provider' => 'log', 'message' => 'SMS queued in local log mode.'];
    }

    if ($provider === 'mobilesasa') {
        return send_mobilesasa_sms($number, $content);
    }

    if ($provider === 'africastalking') {
        $username = (string) env('AFRICASTALKING_USERNAME', '');
        $apiKey = (string) env('AFRICASTALKING_API_KEY', '');
        $senderId = (string) env('AFRICASTALKING_SENDER_ID', '');

        if ($username === '' || $apiKey === '') {
            return ['success' => false, 'provider' => 'africastalking', 'message' => 'Africa\'s Talking username or API key is missing.'];
        }

        if (!function_exists('curl_init')) {
            return ['success' => false, 'provider' => 'africastalking', 'message' => 'cURL is not available for SMS delivery.'];
        }

        $environment = strtolower((string) env('AFRICASTALKING_ENV', 'production'));
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            return ['success' => false, 'provider' => 'africastalking', 'message' => 'AFRICASTALKING_ENV must be set to sandbox or production.'];
        }

        $payload = [
            'username' => $username,
            'to' => $number,
            'message' => $content,
        ];

        if ($senderId !== '') {
            $payload['from'] = $senderId;
        }

        $apiHost = $environment === 'sandbox'
            ? 'https://api.sandbox.africastalking.com'
            : 'https://api.africastalking.com';
        $ch = curl_init($apiHost . '/version1/messaging');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'apiKey: ' . $apiKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $error !== '') {
            return ['success' => false, 'provider' => 'africastalking', 'message' => $error !== '' ? $error : 'Africa\'s Talking request failed.'];
        }

        $decoded = json_decode((string) $response, true);
        $providerError = is_array($decoded) && isset($decoded['errorMessage']) && is_scalar($decoded['errorMessage'])
            ? trim((string) $decoded['errorMessage'])
            : '';
        $responseError = static function (string $fallback) use ($response, $httpCode, $apiKey): string {
            if (!defined('APP_ENV') || APP_ENV !== 'development') {
                return $fallback;
            }

            $body = trim((string) $response);
            if ($apiKey !== '') {
                $body = str_replace($apiKey, '[redacted]', $body);
            }
            $body = preg_replace('/\b(?:apiKey|Authorization)\s*:\s*[^,\r\n}]+/i', '[authentication header redacted]', $body) ?? $body;

            return 'Africa\'s Talking returned HTTP ' . $httpCode . '. Provider response: '
                . ($body !== '' ? $body : '[empty response body]');
        };

        if ($providerError !== '') {
            return ['success' => false, 'provider' => 'africastalking', 'message' => $responseError('Africa\'s Talking request failed.')];
        }

        if ($httpCode === 401) {
            return ['success' => false, 'provider' => 'africastalking', 'message' => $responseError('Africa\'s Talking rejected the request. Check the API credentials and account configuration.')];
        }

        if ($httpCode === 403) {
            return ['success' => false, 'provider' => 'africastalking', 'message' => $responseError('Africa\'s Talking denied the request. Check the account permissions and SMS service configuration.')];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'provider' => 'africastalking', 'message' => $responseError('Africa\'s Talking rejected the request. Check the API credentials and SMS account configuration.')];
        }

        if (!is_array($decoded)) {
            return ['success' => false, 'provider' => 'africastalking', 'message' => $responseError('Africa\'s Talking returned an unreadable response.')];
        }

        return ['success' => true, 'provider' => 'africastalking', 'message' => 'SMS sent via Africa\'s Talking.'];
    }

    if ($provider === 'twilio') {
        $sid = (string) env('TWILIO_SID', '');
        $token = (string) env('TWILIO_TOKEN', '');
        $from = (string) env('TWILIO_FROM', '');

        if ($sid === '' || $token === '' || $from === '') {
            return ['success' => false, 'provider' => 'twilio', 'message' => 'Twilio SID, token, or sender number is missing.'];
        }

        if (!function_exists('curl_init')) {
            return ['success' => false, 'provider' => 'twilio', 'message' => 'cURL is not available for SMS delivery.'];
        }

        $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'To' => $number,
                'From' => $from,
                'Body' => $content,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $sid . ':' . $token,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $error !== '') {
            return ['success' => false, 'provider' => 'twilio', 'message' => $error !== '' ? $error : 'Twilio request failed.'];
        }

        $decoded = json_decode((string) $response, true);
        $ok = $httpCode >= 200 && $httpCode < 300 && is_array($decoded) && isset($decoded['sid']);

        if (!$ok) {
            return ['success' => false, 'provider' => 'twilio', 'message' => $decoded['message'] ?? 'Twilio delivery failed.'];
        }

        return ['success' => true, 'provider' => 'twilio', 'message' => 'SMS sent via Twilio.'];
    }

    return ['success' => false, 'provider' => $provider, 'message' => 'Unsupported SMS provider configured. Use log, africastalking, mobilesasa, or twilio.'];
}

function send_sms_batch(
    array $recipients,
    ?string $message = null,
    ?string $providerOverride = null,
    ?callable $httpRequest = null,
    ?string $trackingId = null
): array {
    $provider = strtolower($providerOverride ?? (string) env('SMS_PROVIDER', 'log'));
    $normalized = [];
    $seen = [];

    foreach ($recipients as $recipient) {
        if (!is_array($recipient)) {
            continue;
        }
        $phone = normalize_sms_phone((string) ($recipient['phone'] ?? ''));
        $content = trim((string) ($recipient['message'] ?? $message ?? ''));
        if ($phone === '' || $content === '' || isset($seen[$phone])) {
            continue;
        }
        $seen[$phone] = true;
        $normalized[] = ['phone' => $phone, 'message' => $content];
    }

    if ($normalized === []) {
        return ['success' => false, 'provider' => $provider, 'status' => 'failed', 'message' => 'No valid SMS recipients or messages were provided.', 'results' => []];
    }

    if ($provider === 'mobilesasa') {
        return send_mobilesasa_sms_batch($normalized, $httpRequest, $trackingId);
    }

    $results = [];
    foreach ($normalized as $recipient) {
        if ($httpRequest !== null) {
            $singleResult = $httpRequest($recipient['phone'], $recipient['message'], $provider);
            if (!is_array($singleResult)) {
                $singleResult = ['success' => false, 'message' => 'SMS provider returned an invalid response.'];
            }
        } else {
            $singleResult = send_sms($recipient['phone'], $recipient['message'], $provider);
        }

        $results[] = [
            'phone' => $recipient['phone'],
            'success' => (bool) ($singleResult['success'] ?? false),
            'status' => !empty($singleResult['success']) ? 'accepted' : 'failed',
            'message' => (string) ($singleResult['message'] ?? 'SMS request failed.'),
        ];
    }

    $successCount = count(array_filter($results, static fn (array $result): bool => $result['success']));
    $failedCount = count($results) - $successCount;

    return [
        'success' => $failedCount === 0,
        'provider' => $provider === 'dev' ? 'log' : $provider,
        'status' => $failedCount === 0 ? 'accepted' : ($successCount > 0 ? 'partial' : 'failed'),
        'message' => $failedCount === 0 ? 'SMS requests processed.' : 'Some SMS requests failed.',
        'results' => $results,
    ];
}

function send_mobilesasa_sms_batch(array $recipients, ?callable $httpRequest = null, ?string $trackingId = null): array
{
    $apiToken = trim((string) env('MOBILESASA_API_TOKEN', ''));
    $senderId = trim((string) env('MOBILESASA_SENDER_ID', ''));
    if ($apiToken === '' || $senderId === '') {
        return ['success' => false, 'provider' => 'mobilesasa', 'status' => 'failed', 'message' => 'MobileSasa API token or sender ID is not configured.', 'results' => []];
    }
    if ($httpRequest === null && !function_exists('curl_init')) {
        return ['success' => false, 'provider' => 'mobilesasa', 'status' => 'failed', 'message' => 'cURL is not available for SMS delivery.', 'results' => []];
    }

    $trackingId = $trackingId ?? bin2hex(random_bytes(16));
    $sameMessage = count(array_unique(array_column($recipients, 'message'))) === 1;
    if ($sameMessage) {
        $endpoint = 'https://api.mobilesasa.com/v2/send/bulk';
        $payload = [
            'senderID' => $senderId,
            'phones' => implode(',', array_column($recipients, 'phone')),
            'message' => $recipients[0]['message'],
            'trackingId' => $trackingId,
        ];
    } else {
        $endpoint = 'https://api.mobilesasa.com/v2/send/bulk-personalized';
        $payload = [
            'senderID' => $senderId,
            'messageBody' => $recipients,
            'trackingId' => $trackingId,
        ];
    }

    try {
        $requestBody = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (JsonException $exception) {
        return ['success' => false, 'provider' => 'mobilesasa', 'status' => 'failed', 'message' => 'SMS content could not be encoded for MobileSasa.', 'results' => []];
    }

    if ($httpRequest !== null) {
        $responseResult = $httpRequest($endpoint, [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
        ], $requestBody);
        $response = $responseResult['response'] ?? false;
        $httpCode = (int) ($responseResult['http_code'] ?? 0);
        $networkError = (bool) ($responseResult['network_error'] ?? false);
    } else {
        $curl = curl_init($endpoint);
        if ($curl === false) {
            return ['success' => false, 'provider' => 'mobilesasa', 'status' => 'uncertain', 'tracking_id' => $trackingId, 'message' => 'MobileSasa request could not be initialized.', 'results' => []];
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $networkError = $response === false || curl_errno($curl) !== 0;
        curl_close($curl);
    }

    if ($networkError || !is_string($response)) {
        return ['success' => false, 'provider' => 'mobilesasa', 'status' => 'uncertain', 'tracking_id' => $trackingId, 'message' => 'MobileSasa batch result is uncertain; do not retry automatically.', 'results' => []];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'provider' => 'mobilesasa', 'status' => 'uncertain', 'tracking_id' => $trackingId, 'message' => 'MobileSasa returned an unreadable batch response.', 'results' => []];
    }

    $responseCode = $decoded['responseCode'] ?? null;
    $accepted = $httpCode >= 200 && $httpCode < 300
        && ($decoded['status'] ?? null) === true
        && is_scalar($responseCode)
        && (string) $responseCode === '0200';
    if (!$accepted) {
        $messageText = strtolower((string) ($decoded['message'] ?? ''));
        if (preg_match('/insufficient|low balance/', $messageText)) {
            $safeMessage = 'MobileSasa account has insufficient SMS balance.';
        } elseif ($httpCode === 401 || $httpCode === 403 || preg_match('/unauthori[sz]ed|invalid token/', $messageText)) {
            $safeMessage = 'MobileSasa authentication failed or account access was denied.';
        } elseif ($httpCode >= 500) {
            $safeMessage = 'MobileSasa service is temporarily unavailable.';
        } else {
            $safeMessage = 'MobileSasa rejected the batch request.';
        }

        return ['success' => false, 'provider' => 'mobilesasa', 'status' => 'failed', 'tracking_id' => $trackingId, 'response_code' => is_scalar($responseCode) ? (string) $responseCode : null, 'message' => $safeMessage, 'results' => []];
    }

    $bulkId = isset($decoded['bulkId']) && is_scalar($decoded['bulkId']) ? (string) $decoded['bulkId'] : null;
    $results = [];
    foreach ($recipients as $recipient) {
        $results[] = [
            'phone' => $recipient['phone'],
            'success' => true,
            'status' => 'accepted',
            'message' => 'SMS accepted for processing; delivery is not yet confirmed.',
        ];
    }

    return [
        'success' => true,
        'provider' => 'mobilesasa',
        'status' => 'accepted',
        'tracking_id' => $trackingId,
        'bulk_id' => $bulkId,
        'response_code' => '0200',
        'message' => 'SMS batch accepted for processing; delivery is not yet confirmed.',
        'results' => $results,
    ];
}

function send_sms_to_member(int $memberId, string $message, ?string $providerOverride = null): array
{
    $stmt = db()->prepare('SELECT phone FROM members WHERE member_id = :member_id LIMIT 1');
    $stmt->execute(['member_id' => $memberId]);
    $member = $stmt->fetch();

    if (!$member || empty($member['phone'])) {
        return ['success' => false, 'provider' => $providerOverride ?? (string) env('SMS_PROVIDER', 'log'), 'message' => 'Member phone number is not set.'];
    }

    return send_sms((string) $member['phone'], $message, $providerOverride);
}
