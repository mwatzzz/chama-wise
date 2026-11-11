<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$testEnvironmentFile = getenv('CHAMAWISE_TEST_ENV_FILE');
if (!is_string($testEnvironmentFile) || $testEnvironmentFile === '') {
    $testEnvironmentFile = $projectRoot . '/tests/.env.test';
}
if (!is_file($testEnvironmentFile) || !is_readable($testEnvironmentFile)) {
    throw new RuntimeException(
        'Create tests/.env.test from tests/.env.test.example and configure a dedicated test database.'
    );
}

$testEnvironmentFile = realpath($testEnvironmentFile);
if ($testEnvironmentFile === false) {
    throw new RuntimeException('The Daraja test environment file could not be resolved.');
}
putenv('CHAMAWISE_TEST_ENV_FILE=' . $testEnvironmentFile);

require_once $projectRoot . '/config/env.php';
load_env($testEnvironmentFile);
if (PHP_SAPI !== 'cli' || env('APP_ENV') !== 'test' ||
    !preg_match('/(^|_)test($|_)/i', (string) env('DB_NAME', '')) ||
    !in_array(strtolower((string) env('DB_HOST', '127.0.0.1')), ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Daraja tests require CLI and a local test database whose name contains a standalone "test" token.');
}

$host = (string) env('DB_HOST', '127.0.0.1');
$port = (string) env('DB_PORT', '3306');
$database = (string) env('DB_NAME');
$user = (string) env('DB_USER', 'root');
$password = (string) env('DB_PASS', '');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$schemaPath = $projectRoot . '/database/schema.sql';
$schema = file_get_contents($schemaPath);
if ($schema === false) {
    throw new RuntimeException('The ChamaWise schema file could not be read for test setup.');
}
$schema = preg_replace('/\A\s*CREATE DATABASE IF NOT EXISTS [^;]+;\s*USE [^;]+;\s*/i', '', $schema, 1, $removed);
if (!is_string($schema) || $removed !== 1) {
    throw new RuntimeException('The schema database selector could not be safely removed for test setup.');
}

foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [] as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}
unset($pdo);

require_once $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/config/app.php';
