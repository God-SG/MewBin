<?php
session_start();
include('database.php');

header("Content-Type: application/json");

if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$username = $_SESSION['username'];
$query = "SELECT rank, login_token FROM users WHERE username = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();

if (!$userData || empty($userData['login_token'])) {
    header('Location: /login');
    exit();
}

$loggedInUserRank = $userData['rank'] ?? 'All Users';

if (!in_array($loggedInUserRank, ['Admin', 'Manager', 'Mod', 'Council'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    if (isset($data['username']) && isset($data['newPassword'])) {
        // Use strict regex for username, avoid deprecated FILTER_SANITIZE_STRING
        $targetUsername = trim($data['username']);
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $targetUsername)) {
            echo json_encode(['success' => false, 'message' => 'Invalid username format.']);
            exit;
        }

        $newPassword = trim($data['newPassword']);

        if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long, include an uppercase letter, and a number.']);
            exit;
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $newLoginToken = bin2hex(random_bytes(32));

        $stmt = $conn->prepare("UPDATE users SET password = ?, login_token = ? WHERE username = ?");
        if (!$stmt) {
            error_log("Database error: " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
            exit;
        }

        $stmt->bind_param("sss", $hashedPassword, $newLoginToken, $targetUsername);
        $result = $stmt->execute();

        if ($result && $stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Password and login token updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update password and login token.']);
        }

        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid input parameters.']);
    }
}

$conn->close();
?>