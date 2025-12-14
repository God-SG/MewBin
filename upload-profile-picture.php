<?php
include('database.php');
session_start();

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'User not logged in.']);
    exit();
}

// Strict username validation (defense-in-depth)
$username = $_SESSION['username'];
if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
    echo json_encode(['success' => false, 'error' => 'Invalid username.']);
    exit();
}
$rank = $_SESSION['rank'] ?? 'All Users';

$allowedMimeTypes = ['image/jpeg', 'image/png'];
$allowedExtensions = ['jpg', 'jpeg', 'png'];
$maxFileSize = 15 * 1024 * 1024;

if (in_array($rank, ['Admin', 'Manager', 'Mod'])) {
    $allowedMimeTypes[] = 'image/gif';
    $allowedExtensions[] = 'gif';
}

if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No valid file was uploaded.']);
    exit();
}

$file = $_FILES['profile_image'];
$fileName = $file['name'];
$fileTmpName = $file['tmp_name'];
$fileSize = $file['size'];
$fileMimeType = mime_content_type($fileTmpName);
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// Enforce extension and MIME match, and sanitize filename
if (!in_array($fileMimeType, $allowedMimeTypes) || !in_array($fileExtension, $allowedExtensions)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type or extension.']);
    exit();
}

if ($fileSize > $maxFileSize) {
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 15MB.']);
    exit();
}

// Include R2 helper
require_once 'includes/r2-helper.php';

// Generate a unique file key for R2
$fileKey = 'profile_pictures/' . uniqid() . '.' . $fileExtension;

// Upload to R2
$r2 = R2Helper::getInstance();
$publicUrl = $r2->uploadFile(
    $fileTmpName,
    $fileKey,
    $fileMimeType
);

if (!$publicUrl) {
    error_log("R2 upload error: Failed to upload file to R2");
    echo json_encode(['success' => false, 'error' => 'Failed to upload file to storage.']);
    exit();
}

$stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE username = ?");
$fileDestination = $fileKey; // Use the R2 file key
if (!$stmt) {
    error_log("Database error: " . $conn->error);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit();
}

$stmt->bind_param("ss", $fileDestination, $username);
if ($stmt->execute()) {
    echo json_encode(['success' => true, 'profilePictureUrl' => htmlspecialchars($fileDestination, ENT_QUOTES, 'UTF-8')]);
} else {
    error_log("Database error: " . $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Failed to update profile picture.']);
}

$stmt->close();
?>
