<?php
require '../database.php'; 
require_once 'waf.php';
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['login_token'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$loginToken = $_SESSION['login_token'];
$stmtPerm = $conn->prepare("SELECT rank FROM users WHERE login_token = ?");
$stmtPerm->bind_param("s", $loginToken);
$stmtPerm->execute();
$resPerm = $stmtPerm->get_result();
$userPerm = $resPerm->fetch_assoc();
$stmtPerm->close();

if (!$userPerm || !in_array($userPerm['rank'], ['Admin', 'Manager', 'Founder'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['username']) || !isset($data['color'])) {
    echo json_encode(['success' => false, 'error' => 'Username or color is missing.']);
    exit;
}

$username = $data['username'];
$color = $data['color'];

$stmt = $conn->prepare("SELECT has_color FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}

$row = $result->fetch_assoc();
$newHasColor = 1;

$updateStmt = $conn->prepare("UPDATE users SET has_color = ?, color = ? WHERE username = ?");
$updateStmt->bind_param("iss", $newHasColor, $color, $username);

if ($updateStmt->execute()) {
    echo json_encode(['success' => true, 'newColor' => $color]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to update database.']);
}

$stmt->close();
$updateStmt->close();
$conn->close();
?>
