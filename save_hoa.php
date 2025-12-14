<?php
session_start();
include("database.php");

// Enforce HTTPS
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

// Validate CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    // Strict validation for username and about_me (defense-in-depth)
    $username = trim($_POST['username'] ?? '');
    $about_me = trim($_POST['about_me'] ?? '');
    $link = trim($_POST['link'] ?? '');

    if (
        empty($username) || empty($about_me) || empty($link) ||
        !preg_match('/^[a-zA-Z0-9_\-]{2,32}$/', $username) ||
        strlen($about_me) > 255 ||
        !filter_var($link, FILTER_VALIDATE_URL) ||
        strlen($link) > 255
    ) {
        die("Invalid input.");
    }

    // Handle file upload
    if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileType = mime_content_type($_FILES['picture']['tmp_name']);
        $maxFileSize = 2 * 1024 * 1024; // 2 MB

        if (!in_array($fileType, $allowedTypes)) {
            die("Invalid file type. Only JPG, PNG, and GIF are allowed.");
        }

        if ($_FILES['picture']['size'] > $maxFileSize) {
            die("File size exceeds the 2MB limit.");
        }

        $uploadDir = 'uploads/hoa/';
        $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\.\-]/', '', basename($_FILES['picture']['name']));
        $targetFilePath = $uploadDir . $fileName;

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        if (!move_uploaded_file($_FILES['picture']['tmp_name'], $targetFilePath)) {
            die("Error uploading the file.");
        }
        $picturePath = $targetFilePath;
    } else {
        die("No file uploaded or an error occurred.");
    }

    // Insert into the database
    $stmt = $conn->prepare("INSERT INTO hoa (link, picture, username, about_me) VALUES (?, ?, ?, ?)");
    if ($stmt === false) {
        error_log("Error preparing the statement: " . $conn->error);
        die("An error occurred. Please try again later.");
    }

    $stmt->bind_param("ssss", $link, $picturePath, $username, $about_me);

    if ($stmt->execute()) {
        echo htmlspecialchars("Data saved successfully!", ENT_QUOTES, 'UTF-8');
        header('Location: index.php');
    } else {
        error_log("Error executing the statement: " . $stmt->error);
        die("An error occurred. Please try again later.");
    }

    $stmt->close();
}

$conn->close();
?>
