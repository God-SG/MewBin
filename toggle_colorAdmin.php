<?php
require 'database.php'; 
header('Content-Type: application/json');

session_start();

// Validate CSRF token
$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// Check if the user is authorized
if (
    !isset($_SESSION['username']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'Admin'
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

// Validate and sanitize input
if (!isset($data['username'])) {
    echo json_encode(['success' => false, 'error' => 'Username is missing.']);
    exit;
}

// Use strict regex, avoid deprecated FILTER_SANITIZE_STRING
$username = trim($data['username']);
if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
    echo json_encode(['success' => false, 'error' => 'Invalid username format.']);
    exit;
}

// Check if the user exists
$stmt = $conn->prepare("SELECT has_color FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}

$row = $result->fetch_assoc();
$currentHasColor = (int) $row['has_color'];
$newHasColor = $currentHasColor === 1 ? 0 : 1;

// Update the user's has_color field
$updateStmt = $conn->prepare("UPDATE users SET has_color = ? WHERE username = ?");
$updateStmt->bind_param("is", $newHasColor, $username);

if ($updateStmt->execute()) {
    echo json_encode(['success' => true, 'newHasColor' => $newHasColor]);
} else {
    error_log("Database error: " . $updateStmt->error);
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again later.']);
}

$stmt->close();
$updateStmt->close();
$conn->close();
?>
