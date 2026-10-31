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

function send_sms(string $to, string $message): array
{
    $provider = strtolower((string) env('SMS_PROVIDER', 'log'));
    $number = normalize_sms_phone($to);
    $content = trim((string) $message);

    if ($number === '' || $content === '') {
        return ['success' => false, 'provider' => $provider, 'message' => 'Phone number or SMS text is empty.'];
    }

    if ($provider === 'log' || $provider === 'dev') {
        error_log('SMS to ' . $number . ': ' . $content);
        return ['success' => true, 'provider' => 'log', 'message' => 'SMS queued in local log mode.'];
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

        $payload = [
            'username' => $username,
            'to' => $number,
            'message' => $content,
        ];

        if ($senderId !== '') {
            $payload['from'] = $senderId;
        }

        $ch = curl_init('https://api.africastalking.com/version1/messaging');
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
        $ok = $httpCode >= 200 && $httpCode < 300 && is_array($decoded) && (!isset($decoded['errorMessage']) || $decoded['errorMessage'] === '');

        if (!$ok) {
            return ['success' => false, 'provider' => 'africastalking', 'message' => $decoded['errorMessage'] ?? 'Africa\'s Talking delivery failed.'];
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

    return ['success' => false, 'provider' => $provider, 'message' => 'Unsupported SMS provider configured. Use log, africastalking, or twilio.'];
}

function send_sms_to_member(int $memberId, string $message): array
{
    $stmt = db()->prepare('SELECT phone FROM members WHERE member_id = :member_id LIMIT 1');
    $stmt->execute(['member_id' => $memberId]);
    $member = $stmt->fetch();

    if (!$member || empty($member['phone'])) {
        return ['success' => false, 'provider' => (string) env('SMS_PROVIDER', 'log'), 'message' => 'Member phone number is not set.'];
    }

    return send_sms((string) $member['phone'], $message);
}
