<?php
include('database.php'); 

echo 'get the fuck out nigga';
exit;

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['order_id'], $input['payment_status']) || $input['payment_status'] !== 'finished') {
    http_response_code(400);
    exit('Invalid callback data');
}

$orderId = $input['order_id'];
$username = explode('_', $orderId)[0];

$orderDesc = $input['order_description'] ?? '';
$descData = json_decode($orderDesc, true);

$upgradeType = $descData['upgradeType'] ?? null;
$setHasColor = $descData['set_has_color'] ?? 0;

if ($upgradeType === 'Change Color') {
    $stmt = $conn->prepare("UPDATE users SET has_color = 1, color = ? WHERE username = ?");
    $defaultColor = '#ffffff';
    $stmt->bind_param('ss', $defaultColor, $username);
} elseif ($upgradeType === 'VIP' || $upgradeType === 'Criminal' || $upgradeType === 'Rich') {
    $stmt = $conn->prepare("UPDATE users SET rank = ? WHERE username = ?");
    $stmt->bind_param('ss', $upgradeType, $username);
} else {
    http_response_code(400);
    exit('Unknown upgrade type');
}

if ($setHasColor && $upgradeType !== 'Change Color') {
    $stmt2 = $conn->prepare("UPDATE users SET has_color = 1 WHERE username = ?");
    $stmt2->bind_param('s', $username);
    $stmt2->execute();
    $stmt2->close();
}

if ($stmt->execute()) {
    http_response_code(200);
    echo 'Success';
} else {
    http_response_code(500);
    echo 'Failed to update user';
}
