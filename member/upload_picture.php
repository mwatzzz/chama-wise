<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('member/dashboard.php');
}

$file = $_FILES['profile_picture'] ?? null;
$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash_set('error', 'Please choose a profile picture to upload.');
    redirect('member/dashboard.php');
}

if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
    flash_set('error', 'Profile pictures must be 2 MB or smaller.');
    redirect('member/dashboard.php');
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
if (!isset($allowedTypes[$mimeType]) || @getimagesize((string) $file['tmp_name']) === false) {
    flash_set('error', 'Please upload a valid JPG, PNG or WebP image.');
    redirect('member/dashboard.php');
}

$uploadDirectory = ROOT_PATH . '/uploads/profile-pictures';
if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
    flash_set('error', 'The picture upload folder could not be created.');
    redirect('member/dashboard.php');
}

$user = current_user();
$filename = 'member-' . $user['user_id'] . '-' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
$relativePath = 'uploads/profile-pictures/' . $filename;
$destination = ROOT_PATH . '/' . $relativePath;

if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
    flash_set('error', 'The profile picture could not be saved.');
    redirect('member/dashboard.php');
}

try {
    db()->exec('ALTER TABLE members ADD COLUMN profile_picture VARCHAR(255) NULL');
} catch (PDOException $exception) {
    // The column already exists on databases imported from the current schema.
}

$stmt = db()->prepare('UPDATE members SET profile_picture = :profile_picture WHERE member_id = :member_id');
$stmt->execute([
    'profile_picture' => $relativePath,
    'member_id' => $user['user_id'],
]);

flash_set('success', 'Your profile picture has been uploaded.');
redirect('member/dashboard.php');
