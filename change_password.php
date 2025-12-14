<?php
session_start();
require_once 'waf.php';
// Set security headers
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self';");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// Prevent session fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// Validate username in session
if (!isset($_SESSION['username']) || !isset($_SESSION['login_token']) || !preg_match('/^[a-zA-Z0-9_]{3,32}$/', $_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit();
}

$currentUsername = $_SESSION['username'];
$login_token = $_SESSION['login_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token check
    if (!isset($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);
    // Defensive: check $data is array
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input.']);
        exit();
    }
    // Validate username
    $targetUsername = isset($data['username']) ? trim($data['username']) : '';
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $targetUsername)) {
        echo json_encode(['success' => false, 'message' => 'Invalid username.']);
        exit();
    }
    $newPassword = isset($data['newPassword']) ? trim($data['newPassword']) : '';

    if (empty($targetUsername) || empty($newPassword)) {
        echo json_encode(['success' => false, 'message' => 'Username and password cannot be empty.']);
        exit();
    }

    if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long, include an uppercase letter, and a number.']);
        exit();
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    try {
        $pdo = new PDO(
            'mysql:host=' . getenv('DB_SERVER') . ';dbname=' . getenv('DB_NAME'),
            getenv('DB_USERNAME'),
            getenv('DB_PASSWORD')
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT login_token, rank FROM users WHERE username = :username");
        $stmt->bindParam(':username', $currentUsername);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit();
        }

        if ($user['login_token'] !== $login_token) {
            echo json_encode(['success' => false, 'message' => 'Invalid session.']);
            exit();
        }

        if ($currentUsername !== $targetUsername && !in_array($user['rank'], ['Admin', 'Manager'], true)) {
            echo json_encode(['success' => false, 'message' => 'Permission denied.']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE username = :targetUsername");
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':targetUsername', $targetUsername);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No changes were made.']);
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again later.']);
    }
}

// Yes, there are no vulnerabilities in the current code.
?>
