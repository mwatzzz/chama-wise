<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $production = strtolower((string) env('APP_ENV', 'development')) === 'production';
    $host = env('DB_HOST', $production ? null : '127.0.0.1');
    $port = env('DB_PORT', $production ? null : '3306');
    $name = env('DB_NAME', $production ? null : 'chama_ai_management_system');
    $user = env('DB_USER', $production ? null : 'root');
    $pass = env('DB_PASS', $production ? null : '');

    if ($production && ($host === null || $port === null || $name === null || $user === null || $pass === null || $pass === '')) {
        error_log('Database configuration is incomplete: production requires explicit DB_HOST, DB_PORT, DB_NAME, DB_USER and non-empty DB_PASS values.');
        http_response_code(503);
        echo 'ChamaWise is unavailable because its database configuration is incomplete.';
        exit;
    }

    $host = $host ?? '127.0.0.1';
    $port = $port ?? '3306';
    $name = $name ?? 'chama_ai_management_system';
    $user = $user ?? 'root';
    $pass = $pass ?? '';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        error_log('Database connection failed: ' . $exception->getMessage());

        if ($production) {
            http_response_code(503);
            echo 'ChamaWise is temporarily unavailable. Please try again later.';
            exit;
        }

        $safeHost = htmlspecialchars($host, ENT_QUOTES, 'UTF-8');
        $safePort = htmlspecialchars($port, ENT_QUOTES, 'UTF-8');
        $safeDb = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        http_response_code(503);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Database Connection Error</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f7f8fa;color:#1f2937;padding:24px;}'
            . '.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px;}'
            . 'h1{margin:0 0 12px;font-size:1.4rem;}ol{margin:10px 0 0 22px;}code{background:#f3f4f6;padding:2px 6px;border-radius:4px;}'
            . '</style></head><body><div class="card"><h1>Database connection failed</h1>'
            . '<p>ChamaWise could not reach MySQL at <code>' . $safeHost . ':' . $safePort . '</code> for database <code>' . $safeDb . '</code>.</p>'
            . '<ol><li>Start MySQL in XAMPP Control Panel.</li>'
            . '<li>Confirm your <code>.env</code> values for <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code>, and <code>DB_PASS</code>.</li>'
            . '<li>If this is a new setup, import <code>database/schema.sql</code>.</li></ol>'
            . '</div></body></html>';
        exit;
    }

    return $pdo;
}
