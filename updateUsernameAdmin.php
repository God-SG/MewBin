<?php
session_start();
include('database.php');

header("Content-Type: application/json");

if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$username = $_SESSION['username'];
$query = "SELECT rank FROM users WHERE username = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    error_log("Username not found in database: $username");
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$userData = $result->fetch_assoc();
$loggedInUserRank = $userData['rank'] ?? 'All Users';

if (!in_array($loggedInUserRank, ['Admin', 'Manager', 'Mod', 'Council'])) {
    error_log("User not authorized: $loggedInUserRank");
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Use JSON input for consistency and security
    $data = $_POST;
    if (empty($data) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
        $data = json_decode(file_get_contents('php://input'), true);
    }

    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token.']);
        exit;
    }

    // Use strict regex for usernames, avoid deprecated FILTER_SANITIZE_STRING
    $newUsername = trim($data['newUsername'] ?? '');
    $currentUsername = trim($data['currentUsername'] ?? '');

    if (
        !preg_match('/^[a-zA-Z0-9_]{3,32}$/', $newUsername) ||
        !preg_match('/^[a-zA-Z0-9_]{3,32}$/', $currentUsername)
    ) {
        echo json_encode(['error' => 'Invalid username format.']);
        exit;
    }

    // Prevent changing to reserved usernames
    if (strtolower($newUsername) === 'anonymous') {
        echo json_encode(['error' => 'This username is not allowed.']);
        exit;
    }

    $newLoginToken = bin2hex(random_bytes(32));

    mysqli_begin_transaction($conn);

    try {
        $stmt = $conn->prepare("UPDATE users SET username = ?, login_token = ? WHERE username = ?");
        $stmt->bind_param("sss", $newUsername, $newLoginToken, $currentUsername);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE profile_comments SET commenter_username = ?, receiver_username = ? WHERE commenter_username = ? OR receiver_username = ?");
        $stmt->bind_param("ssss", $newUsername, $newUsername, $currentUsername, $currentUsername);
        $stmt->execute();

        // Repeat for other tables...

        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Username updated successfully.']);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Transaction failed: " . $e->getMessage());
        echo json_encode(['error' => 'An error occurred. Please try again later.']);
    }
}
?>
