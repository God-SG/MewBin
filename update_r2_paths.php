<?php
// Database configuration
$db_host = 'localhost';
$db_user = 'root';  // Update these with your database credentials
$db_pass = '';     // Update with your database password
$db_name = 'mew';   // Update with your database name

// Create MySQLi connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . ". Make sure the MySQL server is running and the database exists.");
}

// Set charset to utf8mb4
if (!$conn->set_charset("utf8mb4")) {
    die("Error setting charset: " . $conn->error);
}

// Function to update file paths in the database
function updateFilePaths($conn) {
    // Update profile pictures
    $sql = "SELECT id, profile_picture FROM users WHERE profile_picture IS NOT NULL";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $oldPath = $row['profile_picture'];
        if (strpos($oldPath, 'profile_pictures/') !== false) {
            $newPath = 'profile_pictures/' . basename($oldPath);
            $updateSql = "UPDATE users SET profile_picture = ? WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $newPath, $row['id']);
            $updateStmt->execute();
            echo "Updated profile picture for user ID {$row['id']}: $oldPath -> $newPath<br>";
        }
    }
    
    // Update profile backgrounds
    $sql = "SELECT id, profile_bg FROM users WHERE profile_bg IS NOT NULL";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $oldPath = $row['profile_bg'];
        if (strpos($oldPath, 'bg/') !== false) {
            $newPath = 'profile_backgrounds/' . basename($oldPath);
            $updateSql = "UPDATE users SET profile_bg = ? WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $newPath, $row['id']);
            $updateStmt->execute();
            echo "Updated profile background for user ID {$row['id']}: $oldPath -> $newPath<br>";
        }
    }
    
    // Update profile music
    $sql = "SELECT id, profile_music FROM users WHERE profile_music IS NOT NULL";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $oldPath = $row['profile_music'];
        if (strpos($oldPath, 'music/') !== false) {
            $newPath = 'profile_music/' . basename($oldPath);
            $updateSql = "UPDATE users SET profile_music = ? WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $newPath, $row['id']);
            $updateStmt->execute();
            echo "Updated profile music for user ID {$row['id']}: $oldPath -> $newPath<br>";
        }
    }
}

// Run the update
updateFilePaths($conn);

// Close connection
$conn->close();

echo "<br>Update complete. Please delete this file after use for security reasons.";
?>
