<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

load_env(dirname(__DIR__) . '/.env');

define('ROOT_PATH', dirname(__DIR__));
define('APP_NAME', env('APP_NAME', 'ChamaWise'));
define('APP_BASE', rtrim(env('APP_BASE', '/chama-wise'), '/'));
define('SESSION_NAME', env('SESSION_NAME', 'chamawise_session'));

require_once __DIR__ . '/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/penalties.php';
require_once dirname(__DIR__) . '/includes/loans.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/ai_context.php';

ensure_contributions_table();

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE === '' ? '/' : APP_BASE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

restore_remembered_login();
