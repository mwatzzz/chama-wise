<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'POST required.']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '' || strlen($rawBody) > 65536) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback body.']);
    exit;
}

try {
    $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new JsonException('Callback body must be a JSON object.');
    }
    $matched = handle_daraja_member_callback($payload, $rawBody) || handle_daraja_investment_callback($payload, $rawBody);
    if (!$matched) {
        error_log('Daraja callback could not be matched to a member or investment payment.');
    }
} catch (Throwable $exception) {
    error_log('Daraja callback processing failed: ' . $exception->getMessage());
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted.']);
