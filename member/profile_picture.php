<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$memberId = (int) current_user()['user_id'];
$stmt = db()->prepare(
    "SELECT profile_picture FROM members
     WHERE member_id = :member_id AND status = 'active' LIMIT 1"
);
$stmt->execute(['member_id' => $memberId]);
$relativePath = $stmt->fetchColumn();
if (!is_string($relativePath) || !member_erasure_profile_picture_path_is_valid($relativePath, $memberId)) {
    http_response_code(404);
    exit;
}

$configuredDirectory = member_profile_picture_directory();
$directory = is_link($configuredDirectory) ? false : realpath($configuredDirectory);
$path = $directory === false ? false : realpath(
    $directory . DIRECTORY_SEPARATOR . basename($relativePath)
);
if ($path === false || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)
    || !is_file($path) || is_link($directory . DIRECTORY_SEPARATOR . basename($relativePath))) {
    http_response_code(404);
    exit;
}

$extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
$contentTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
if (!isset($contentTypes[$extension])) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $contentTypes[$extension]);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($path);
