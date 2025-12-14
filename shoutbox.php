<?php
include('database.php');
session_start();

if (!isset($_SESSION['username']) && !isset($_COOKIE['username'])) {
    header("Location: login.php");
    exit();
}

if (isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
} elseif (isset($_COOKIE['username'])) {
    $_SESSION['username'] = $_COOKIE['username'];
    $username = $_COOKIE['username'];
} else {
    $username = null;
}

if ($username) {
    $stmt = $conn->prepare("SELECT rank FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($rank);
    $stmt->fetch();
    $stmt->close();
} else {
    $rank = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']));
    }

    if ($_POST['action'] === 'send_message') {
        $stmt = $conn->prepare("SELECT chat_locked FROM settings LIMIT 1");
        $stmt->execute();
        $stmt->bind_result($chat_locked);
        $stmt->fetch();
        $stmt->close();

        if ($chat_locked == 1) {
            exit(json_encode(['status' => 'error', 'message' => 'Chat is currently locked.']));
        }

        // Enforce message length and allow only valid UTF-8
        $message = trim($_POST['message']);
        if (
            empty($message) ||
            strlen($message) > 500 ||
            !mb_check_encoding($message, 'UTF-8')
        ) {
            exit(json_encode(['status' => 'error', 'message' => 'Invalid message or user not logged in.']));
        }

        // Prevent duplicate messages (simple spam protection)
        $stmt = $conn->prepare("SELECT message, created_at FROM messages WHERE username = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($last_message, $last_time);
        $stmt->fetch();
        $stmt->close();
        if ($last_message === $message && time() - strtotime($last_time) < 10) {
            exit(json_encode(['status' => 'error', 'message' => 'Please wait before sending the same message again.']));
        }

        // Escape message for output, but store raw in DB (parameterized)
        $stmt = $conn->prepare("INSERT INTO messages (username, message) VALUES (?, ?)");
        $stmt->bind_param("ss", $username, $message);
        if ($stmt->execute()) {
            exit(json_encode(['status' => 'success']));
        } else {
            error_log("Database error: " . $stmt->error);
            exit(json_encode(['status' => 'error', 'message' => 'An error occurred. Please try again later.']));
        }
        $stmt->close();
    }

    if ($_POST['action'] === 'mute_user') {
        if ($rank && in_array($rank, ['Admin', 'Mod', 'Manager', 'Council'])) {
            $user_id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
            if (!$user_id) {
                exit(json_encode(['status' => 'error', 'message' => 'Invalid user ID.']));
            }
            $stmt = $conn->prepare("UPDATE users SET muted = 1 WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                exit(json_encode(['status' => 'success']));
            } else {
                error_log("Database error: " . $stmt->error);
                exit(json_encode(['status' => 'error', 'message' => 'Failed to mute user.']));
            }
            $stmt->close();
        } else {
            exit(json_encode(['status' => 'error', 'message' => 'You do not have permission to mute this user.']));
        }
    }

    if ($_POST['action'] === 'delete_message') {
        if ($rank && in_array($rank, ['Admin', 'Manager', 'Mod', 'Council'])) {
            $message_id = filter_var($_POST['message_id'], FILTER_VALIDATE_INT);
            if (!$message_id) {
                exit(json_encode(['status' => 'error', 'message' => 'Invalid message ID.']));
            }
            $stmt = $conn->prepare("DELETE FROM messages WHERE id = ?");
            $stmt->bind_param("i", $message_id);
            if ($stmt->execute()) {
                exit(json_encode(['status' => 'success']));
            } else {
                error_log("Database error: " . $stmt->error);
                exit(json_encode(['status' => 'error', 'message' => 'Failed to delete message.']));
            }
            $stmt->close();
        } else {
            exit(json_encode(['status' => 'error', 'message' => 'You do not have permission to delete this message.']));
        }
    }
}

$conn->close();
?>