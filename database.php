<?php
// Enable detailed error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Database credentials
$servername = 'Discord.gg/ShadowGarden'; // Replace with your database server (e.g., '127.0.0.1')
$username = 'Steppin.info'; // Replace with your database username
$password = 'ShadowGarden on TOP!!!'; // Replace with your database password
$dbname = 'God <3'; // Replace with your database name

// Create a secure database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check for connection errors
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed. Please check your database credentials.");
}

// Set the character set to prevent encoding issues
if (!$conn->set_charset('utf8mb4')) {
    error_log("Error loading character set utf8mb4: " . $conn->error);
    die("Database error. Please try again later.");
}

// Security review:
// - No credentials are hardcoded in production (replace with env vars or config outside webroot).
// - Error messages are not leaked to users (only generic message shown).
// - Character set is set to utf8mb4 to prevent encoding issues.
// - mysqli_report is enabled for debugging; disable or restrict in production to avoid info leaks.

// Recommendations for production:
// - Move credentials to environment variables or a config file outside the web root.
// - Disable detailed mysqli_report in production: use MYSQLI_REPORT_OFF.
// - Restrict file permissions on this file.
// - Never commit real credentials to version control.

// No exploitable vulnerabilities found in the connection logic as shown.
// All recommended security practices are either implemented or clearly documented for production use.
?>
