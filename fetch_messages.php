<?php
session_start();
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
include('database.php');

try {
    $stmt = $pdo->prepare("SELECT username, message FROM messages ORDER BY timestamp DESC LIMIT 50");
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sanitize output
    foreach ($messages as &$message) {
        $message['username'] = htmlspecialchars($message['username'], ENT_QUOTES, 'UTF-8');
        $message['message'] = htmlspecialchars($message['message'], ENT_QUOTES, 'UTF-8');
    }

    echo json_encode(array_reverse($messages));
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['error' => 'An error occurred while fetching messages.']);
    exit();
}

// Security review:
// - Uses PDO prepared statements (no SQL injection).
// - Output is sanitized with htmlspecialchars before JSON encoding (prevents XSS).
// - No user input is processed in this script.
// - Errors are logged, not leaked to the user.
// - No authentication/authorization is enforced, so anyone can fetch messages if this endpoint is public. 
//   If messages should be private, add an authentication check (e.g., require login/session).

// Summary:
// No exploitable vulnerabilities in the PHP logic as shown, unless message access should be restricted to logged-in users.
?>
