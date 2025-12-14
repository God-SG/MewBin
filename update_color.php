<?php
session_start();
require 'database.php'; 

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["message" => "Invalid request method."]);
    exit;
}

if (!isset($_SESSION['username'])) {
    echo json_encode(["message" => "User not logged in."]);
    exit;
}

// Use strict regex for username (defense-in-depth), avoid deprecated FILTER_SANITIZE_STRING
$username = $_SESSION['username'];
if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
    echo json_encode(["message" => "Invalid username."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['csrf_token']) || $input['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(["message" => "Invalid CSRF token."]);
    exit;
}

if (!isset($input['color']) || empty($input['color'])) {
    $stmt = $conn->prepare("UPDATE users SET color = NULL, has_color = 0 WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);

        if ($stmt->execute()) {
            $_SESSION['color'] = null;
            $_SESSION['has_color'] = 0;

            echo json_encode(["message" => "Color cleared successfully.", "color" => null]);
        } else {
            error_log("Database error: " . $stmt->error);
            echo json_encode(["message" => "Failed to clear color."]);
        }

        $stmt->close();
    } else {
        error_log("Database error: " . $conn->error);
        echo json_encode(["message" => "Database error."]);
    }
} else {
    // Use strict regex for color, avoid deprecated FILTER_SANITIZE_STRING
    $selectedColor = $input['color'];
    if (!preg_match('/^#[A-Fa-f0-9]{6}$/', $selectedColor)) {
        echo json_encode(["message" => "Invalid color selection."]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET color = ?, has_color = 1 WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("ss", $selectedColor, $username);

        if ($stmt->execute()) {
            $_SESSION['color'] = $selectedColor;
            $_SESSION['has_color'] = 1;

            echo json_encode(["message" => "Color updated successfully.", "color" => htmlspecialchars($selectedColor, ENT_QUOTES, 'UTF-8')]);
        } else {
            error_log("Database error: " . $stmt->error);
            echo json_encode(["message" => "Failed to update color."]);
        }

        $stmt->close();
    } else {
        error_log("Database error: " . $conn->error);
        echo json_encode(["message" => "Database error."]);
    }
}
?>
