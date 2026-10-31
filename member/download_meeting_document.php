<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_login();

$meetingId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT document_path, document_name, document_mime FROM meetings WHERE meeting_id = :meeting_id LIMIT 1');
$stmt->execute(['meeting_id' => $meetingId]);
$meeting = $stmt->fetch();

if (!$meeting || empty($meeting['document_path'])) {
    http_response_code(404);
    exit('Document not found.');
}

$relativePath = ltrim((string) $meeting['document_path'], '/\\');
$documentPath = realpath(ROOT_PATH . '/' . $relativePath);
$documentDirectory = realpath(ROOT_PATH . '/uploads/meeting-documents');

if (!$documentPath || !$documentDirectory || strncmp($documentPath, $documentDirectory . DIRECTORY_SEPARATOR, strlen($documentDirectory . DIRECTORY_SEPARATOR)) !== 0 || !is_file($documentPath)) {
    http_response_code(404);
    exit('Document not found.');
}

header('Content-Type: ' . ((string) ($meeting['document_mime'] ?: 'application/octet-stream')));
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', basename((string) $meeting['document_name'])) . '"');
header('Content-Length: ' . (string) filesize($documentPath));
header('X-Content-Type-Options: nosniff');
readfile($documentPath);
exit;
