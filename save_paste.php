<?php
include('database.php');
session_start();

if (!isset($_SESSION['initialized'])) {
    session_regenerate_id(true);
    $_SESSION['initialized'] = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paste_id']) && isset($_POST['content'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $paste_id = filter_var($_POST['paste_id'], FILTER_VALIDATE_INT);
    $content = trim($_POST['content']);

    // Enforce content length and allow only valid UTF-8
    if (empty($paste_id) || empty($content) || strlen($content) > 500000 || !mb_check_encoding($content, 'UTF-8')) {
        echo 'invalid_request';
        exit();
    }

    if (isset($_SESSION['username'])) {
        $current_user = $_SESSION['username'];
        // Strict username validation (defense-in-depth)
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $current_user)) {
            echo 'no_permission';
            exit();
        }

        $sql = "SELECT rank FROM users WHERE username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $current_user);
        $stmt->execute();
        $stmt->bind_result($user_rank);
        $stmt->fetch();
        $stmt->close();

        $stmt = $conn->prepare("SELECT creator FROM pastes WHERE id = ?");
        $stmt->bind_param("i", $paste_id);
        $stmt->execute();
        $stmt->bind_result($paste_creator);
        $stmt->fetch();
        $stmt->close();

        if ($paste_creator !== $current_user && !in_array($user_rank, ['VIP', 'Rich', 'Criminal', 'Manager', 'Admin', 'Clique', 'Founder', 'Council', 'Mod'])) {
            echo 'no_permission';
            exit();
        }

        // Use prepared statement, do not double-escape content (already parameterized)
        $stmt = $conn->prepare("UPDATE pastes SET content = ? WHERE id = ?");
        $stmt->bind_param("si", $content, $paste_id);

        if ($stmt->execute()) {
            echo 'success';
        } else {
            error_log("Database error: " . $stmt->error);
            echo 'failed';
        }
        $stmt->close();
    } else {
        echo 'no_permission';
    }
} else {
    echo 'invalid_request';
}

$conn->close();
?>
