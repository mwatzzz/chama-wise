<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

load_env(dirname(__DIR__) . '/.env');

define('ROOT_PATH', dirname(__DIR__));
define('APP_NAME', env('APP_NAME', 'ChamaWise'));
define('APP_BASE', rtrim(env('APP_BASE', '/chama-wise'), '/'));
define('SESSION_NAME', env('SESSION_NAME', 'chamawise_session'));
define('SESSION_IDLE_TIMEOUT', max(60, (int) env('SESSION_IDLE_TIMEOUT', '900')));
define('LOAN_MIN_MEMBERSHIP_MONTHS', max(0, (int) env('LOAN_MIN_MEMBERSHIP_MONTHS', '3')));
define('LOAN_MIN_CONTRIBUTIONS', max(0, (int) env('LOAN_MIN_CONTRIBUTIONS', '3')));
define('LOAN_MIN_TOTAL_CONTRIBUTIONS', max(0, (float) env('LOAN_MIN_TOTAL_CONTRIBUTIONS', '5000')));
define('LOAN_MAX_MULTIPLE', max(0.0, (float) env('LOAN_MAX_MULTIPLE', '2')));
define('LOAN_MAX_AMOUNT', max(0.0, (float) env('LOAN_MAX_AMOUNT', '100000')));
define('LATE_LOAN_PENALTY_PERCENT', max(0.0, (float) env('LATE_LOAN_PENALTY_PERCENT', '5')));

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
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE === '' ? '/' : APP_BASE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    enforce_session_timeout();
}

restore_remembered_login();
