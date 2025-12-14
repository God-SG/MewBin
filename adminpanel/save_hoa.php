<?php
include __DIR__ . '/../database.php';
require_once 'waf.php';
require_once __DIR__ . '/../includes/r2-helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($conn) || !$conn) {
        die("Database connection not initialized.");
    }

    $username = $_POST['username'];
    $about_me = $_POST['about_me'];
    $link = $_POST['link'];
    $picturePath = '';

    if (!isset($_FILES['picture']) || $_FILES['picture']['error'] !== UPLOAD_ERR_OK) {
        die("No file uploaded or an error occurred.");
    }

    $r2Helper = R2Helper::getInstance();
    $uploadResult = $r2Helper->uploadFile($_FILES['picture'], 'hoa');

    if (!$uploadResult['success']) {
        die("Error uploading the file: " . $uploadResult['message']);
    }

    $picturePath = $uploadResult['path'];

    $stmt = $conn->prepare("INSERT INTO hoa (link, picture, username, about_me) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        die("Error preparing the statement: " . $conn->error);
    }

    $stmt->bind_param("ssss", $link, $picturePath, $username, $about_me);

    if ($stmt->execute()) {
        header("Location: index.php");
        exit;
    } else {
        die("Error executing query: " . $stmt->error);
    }

    $stmt->close();
}

$conn->close();
