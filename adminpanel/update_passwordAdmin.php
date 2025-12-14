<?php
session_start();
require_once 'waf.php';
include_once('../database.php'); 

header("Content-Type: application/json");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['login_token'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$loginToken = $_SESSION['login_token'];
$query = "SELECT username, rank FROM users WHERE login_token = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $loginToken);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();

if (!$userData) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$username = $userData['username'];
$loggedInUserRank = $userData['rank'] ?? 'All Users';

if (!in_array($loggedInUserRank, ['Admin', 'Manager', 'Founder'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (
        !isset($data['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        $data['csrf_token'] !== $_SESSION['csrf_token']
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    if (isset($data['username']) && isset($data['newPassword'])) {
        $targetUsername = trim($data['username']);
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $targetUsername)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid username format.']);
            exit();
        }

        $newPassword = trim($data['newPassword']);

        if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long, include an uppercase letter, and a number.']);
            exit();
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $newLoginToken = bin2hex(random_bytes(32));

        $stmt = $conn->prepare("UPDATE users SET password = ?, login_token = ? WHERE username = ?");
        if (!$stmt) {
            error_log("Database error: " . $conn->error);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
            exit();
        }

        $stmt->bind_param("sss", $hashedPassword, $newLoginToken, $targetUsername);
        $result = $stmt->execute();

        if ($result && $stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Password and login token updated successfully!']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Failed to update password and login token.']);
        }

        $stmt->close();
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input parameters.']);
        exit();
    }
}

$conn->close();
?>