<?php
session_start();
require_once 'database.php';
require_once 'includes/R2Uploader.php';

header('Content-Type: application/json');

// Enable detailed error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

function respond($success, $message, $url = null, $debug = null) {
    echo json_encode([
        'success' => $success, 
        'message' => $message, 
        'url' => $url,
        'debug' => $debug
    ]);
    exit;
}

// Debug info
$debug_info = [];

// 1. Authentication
$username = $_SESSION['username'] ?? null;
$debug_info['session_username'] = $username;

if (!$username && isset($_COOKIE['login_token'])) {
    $token = $_COOKIE['login_token'];
    $debug_info['has_login_token'] = true;
    
    $stmt = $conn->prepare("SELECT username FROM users WHERE login_token = ?");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $username = $row['username'];
                $_SESSION['username'] = $username;
                $debug_info['username_from_token'] = $username;
            } else {
                $debug_info['token_not_found'] = true;
            }
        } else {
            $debug_info['stmt_execute_error'] = $stmt->error;
        }
        $stmt->close();
    } else {
        $debug_info['stmt_prepare_error'] = $conn->error;
    }
} else {
    $debug_info['no_login_token'] = true;
}

if (!$username) {
    respond(false, 'Not authenticated', null, $debug_info);
}

// 2. POST method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method', null, $debug_info);
}

// 3. File upload check
$debug_info['files'] = $_FILES;
if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
    $debug_info['upload_error'] = $_FILES['profile_picture']['error'] ?? 'no_file';
    respond(false, 'No file uploaded or upload error', null, $debug_info);
}

// File validation
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$file_type = $_FILES['profile_picture']['type'];
$file_size = $_FILES['profile_picture']['size'];
$debug_info['file_type'] = $file_type;
$debug_info['file_size'] = $file_size;

if (!in_array($file_type, $allowed_types)) {
    respond(false, 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.', null, $debug_info);
}

if ($file_size > 5 * 1024 * 1024) {
    respond(false, 'File too large. Maximum size is 5MB.', null, $debug_info);
}

// 4. Upload file to R2
try {
    $r2Uploader = new \App\R2Uploader();
    $debug_info['r2_uploader_created'] = true;

    // Generate unique path
    $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
    $bucketPath = "profile_pictures/{$username}_" . time() . ".{$ext}";
    $debug_info['bucket_path'] = $bucketPath;

    // Check if file is readable
    $tmp_path = $_FILES['profile_picture']['tmp_name'];
    $debug_info['tmp_path'] = $tmp_path;
    $debug_info['tmp_readable'] = is_readable($tmp_path);
    $debug_info['tmp_exists'] = file_exists($tmp_path);

    // Attempt upload
    $publicUrl = $r2Uploader->uploadFile($tmp_path, $bucketPath, $file_type);
    $debug_info['public_url'] = $publicUrl;
    
    if (!$publicUrl) {
        throw new Exception('R2 upload returned false');
    }

} catch (Exception $e) {
    $debug_info['upload_exception'] = $e->getMessage();
    respond(false, 'Upload failed: ' . $e->getMessage(), null, $debug_info);
}

// 5. Get old profile picture
$oldPicture = '';
$stmt = $conn->prepare("SELECT profile_picture FROM users WHERE username = ?");
if (!$stmt) {
    $r2Uploader->deleteFile($bucketPath);
    respond(false, "DB prepare failed: " . $conn->error, null, $debug_info);
}
$stmt->bind_param("s", $username);
if (!$stmt->execute()) {
    $r2Uploader->deleteFile($bucketPath);
    respond(false, "DB execute failed: " . $stmt->error, null, $debug_info);
}
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    $oldPicture = $row['profile_picture'] ?? '';
    $debug_info['old_picture'] = $oldPicture;
}
$stmt->close();

// 6. Update database
$updateStmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE username = ?");
if (!$updateStmt) {
    $r2Uploader->deleteFile($bucketPath);
    respond(false, "DB prepare failed: " . $conn->error, null, $debug_info);
}
$updateStmt->bind_param("ss", $bucketPath, $username);
if (!$updateStmt->execute()) {
    $r2Uploader->deleteFile($bucketPath);
    respond(false, "DB execute failed: " . $updateStmt->error, null, $debug_info);
}
$updateStmt->close();

// 7. Delete old picture
if (!empty($oldPicture) && strpos($oldPicture, 'profile_pictures/') !== false) {
    try {
        $delete_result = $r2Uploader->deleteFile($oldPicture);
        $debug_info['old_picture_deleted'] = $delete_result;
    } catch (Exception $e) {
        $debug_info['delete_error'] = $e->getMessage();
    }
}

// Success
respond(true, 'Profile picture updated successfully', $publicUrl, $debug_info);
?>