<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

$testEnvironmentFile = PHP_SAPI === 'cli' ? getenv('CHAMAWISE_TEST_ENV_FILE') : false;
load_env(is_string($testEnvironmentFile) && $testEnvironmentFile !== ''
    ? $testEnvironmentFile
    : dirname(__DIR__) . '/.env');

define('ROOT_PATH', dirname(__DIR__));
define('APP_NAME', env('APP_NAME', 'ChamaWise'));
define('APP_ENV', strtolower((string) env('APP_ENV', 'development')));
define('APP_URL', rtrim((string) env('APP_URL', ''), '/'));
define('CSP_NONCE', base64_encode(random_bytes(18)));
define('APP_BASE', rtrim(env('APP_BASE', '/chama-wise'), '/'));
define('SESSION_NAME', env('SESSION_NAME', 'chamawise_session'));
define('SESSION_IDLE_TIMEOUT', max(60, (int) env('SESSION_IDLE_TIMEOUT', '900')));
define('AI_MEMBER_DAILY_LIMIT', max(1, min(1000, (int) env('AI_MEMBER_DAILY_LIMIT', '20'))));
define('LOAN_MIN_MEMBERSHIP_MONTHS', max(0, (int) env('LOAN_MIN_MEMBERSHIP_MONTHS', '3')));
define('LOAN_MIN_CONTRIBUTIONS', max(0, (int) env('LOAN_MIN_CONTRIBUTIONS', '3')));
define('LOAN_MIN_TOTAL_CONTRIBUTIONS', max(0, (float) env('LOAN_MIN_TOTAL_CONTRIBUTIONS', '5000')));
define('LOAN_MAX_MULTIPLE', max(0.0, (float) env('LOAN_MAX_MULTIPLE', '2')));
define('LOAN_MAX_AMOUNT', max(0.0, (float) env('LOAN_MAX_AMOUNT', '100000')));
define('LATE_LOAN_PENALTY_PERCENT', max(0.0, (float) env('LATE_LOAN_PENALTY_PERCENT', '5')));

if (!in_array(APP_ENV, ['development', 'test', 'production'], true)) {
    error_log('APP_ENV must be development, test or production.');
    http_response_code(503);
    echo 'ChamaWise is unavailable because its environment configuration is invalid.';
    exit;
}

if (APP_ENV === 'test' && (
    PHP_SAPI !== 'cli' ||
    !preg_match('/(^|_)test($|_)/i', (string) env('DB_NAME', '')) ||
    !in_array(strtolower((string) env('DB_HOST', '127.0.0.1')), ['localhost', '127.0.0.1', '::1'], true)
)) {
    error_log('APP_ENV=test is restricted to CLI and a local isolated test database.');
    http_response_code(503);
    echo 'ChamaWise test mode requires CLI and a local dedicated test database.';
    exit;
}

function app_request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }

    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    return filter_var(env('TRUST_PROXY_HTTPS', 'false'), FILTER_VALIDATE_BOOLEAN)
        && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
}

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    if (PHP_SAPI !== 'cli' && !app_request_is_https()) {
        $canonicalUrl = parse_url(APP_URL);
        if (!is_array($canonicalUrl)
            || strtolower((string) ($canonicalUrl['scheme'] ?? '')) !== 'https'
            || empty($canonicalUrl['host'])
            || isset($canonicalUrl['user'])
            || isset($canonicalUrl['pass'])
            || isset($canonicalUrl['query'])
            || isset($canonicalUrl['fragment'])
            || !in_array((string) ($canonicalUrl['path'] ?? ''), ['', '/'], true)) {
            error_log('APP_URL must be a valid HTTPS origin to redirect production HTTP requests safely.');
            http_response_code(503);
            echo 'ChamaWise is unavailable because its HTTPS URL is not configured.';
            exit;
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($requestUri === '' || $requestUri[0] !== '/' || preg_match('/[\r\n]/', $requestUri)) {
            $requestUri = '/';
        }
        $origin = 'https://' . $canonicalUrl['host']
            . (isset($canonicalUrl['port']) ? ':' . $canonicalUrl['port'] : '');
        header('Location: ' . $origin . $requestUri, true, 301);
        exit;
    }
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'nonce-" . CSP_NONCE . "' https://cdn.jsdelivr.net; font-src 'self' data: https://cdn.jsdelivr.net; connect-src 'self'");
    if (APP_ENV === 'production' && app_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

require_once __DIR__ . '/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/penalties.php';
require_once dirname(__DIR__) . '/includes/loans.php';
require_once dirname(__DIR__) . '/includes/business_rules.php';
require_once dirname(__DIR__) . '/includes/investments.php';
require_once dirname(__DIR__) . '/includes/daraja.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/ai_context.php';
require_once dirname(__DIR__) . '/includes/member_erasure.php';

ensure_auth_login_attempts_schema();
ensure_ai_usage_schema();
ensure_member_erasure_schema();
ensure_contributions_table();
ensure_loan_penalties_schema();
ensure_business_rules_schema();
ensure_daraja_investment_schema();
ensure_loan_member_view();
sync_contribution_penalties();
sync_reducing_balance_loan_interest();
sync_overdue_loan_penalties();

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE === '' ? '/' : APP_BASE,
        'secure' => APP_ENV === 'production' || app_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    enforce_session_timeout();
}

restore_remembered_login();
