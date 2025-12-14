<?php
session_start();
include('database.php');

// Enforce HTTPS
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

// Check if the user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Validate CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit(json_encode(['error' => 'Invalid CSRF token']));
    }

    // Strict validation for names and address (defense-in-depth)
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (
        empty($first_name) || empty($last_name) || empty($address) ||
        !preg_match('/^[a-zA-Z\s\-]{1,64}$/', $first_name) ||
        !preg_match('/^[a-zA-Z\s\-]{1,64}$/', $last_name) ||
        strlen($address) > 255
    ) {
        echo json_encode(['error' => 'Invalid input.']);
        exit();
    }

    // Insert into the database
    $query = "INSERT INTO blacklist (first_name, last_name, address) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sss", $first_name, $last_name, $address);

    if ($stmt->execute()) {
        header('Location: admin_blacklist.php');
    } else {
        error_log("Database error: " . $stmt->error);
        echo json_encode(['error' => 'An error occurred. Please try again later.']);
    }

    $stmt->close();
}

$conn->close();
?>
