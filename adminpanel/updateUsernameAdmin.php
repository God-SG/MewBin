<?php
session_start();
include('../database.php');

header("Content-Type: application/json");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

if (!in_array($loggedInUserRank, ['Admin', 'Manager', 'Founder'])) {
    error_log("User not authorized: $loggedInUserRank");
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;
    if (
        empty($data) &&
        isset($_SERVER['CONTENT_TYPE']) &&
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false
    ) {
        $data = json_decode(file_get_contents('php://input'), true);
    }

    // Accept CSRF token from multiple places: POST field, JSON body, common headers, or cookie
    $csrfToken = null;
    if (isset($data['csrf_token'])) {
        $csrfToken = $data['csrf_token'];
    } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (!empty($_SERVER['HTTP_X_CSRFTOKEN'])) {
        $csrfToken = $_SERVER['HTTP_X_CSRFTOKEN'];
    } elseif (!empty($_COOKIE['csrf_token'])) {
        $csrfToken = $_COOKIE['csrf_token'];
    } else {
        // try getallheaders for different capitalizations
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $headerKeys = array_change_key_case($headers, CASE_UPPER);
            if (!empty($headerKeys['X-CSRF-TOKEN'])) {
                $csrfToken = $headerKeys['X-CSRF-TOKEN'];
            } elseif (!empty($headerKeys['X-CSRF-TOKEN'])) {
                $csrfToken = $headerKeys['X-CSRF-TOKEN'];
            }
        }
    }

    if (empty($_SESSION['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token missing']);
        exit();
    }

    if (empty($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token missing']);
        exit();
    }

    // Use hash_equals for timing-safe comparison
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token.']);
        exit();
    }

    $newUsername = trim($data['newUsername'] ?? '');
    $currentUsername = trim($data['currentUsername'] ?? '');

    if (
        !preg_match('/^[a-zA-Z0-9_]{1,32}$/', $newUsername) ||
        !preg_match('/^[a-zA-Z0-9_]{1,32}$/', $currentUsername)
    ) {
        echo json_encode(['error' => 'Invalid username format.']);
        exit;
    }

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


        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Username updated successfully.']);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Transaction failed: " . $e->getMessage());
        echo json_encode(['error' => 'An error occurred. Please try again later.']);
    }
}
?>
