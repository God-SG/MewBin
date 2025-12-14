<?php
session_start();
header('Content-Type: application/json');
include_once 'database.php';

$user = null;
if (isset($_COOKIE['login_token'])) {
    $token = $_COOKIE['login_token'];
    $stmt = $conn->prepare("SELECT username FROM users WHERE login_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($found_username);
    if ($stmt->fetch()) {
        $user = $found_username;
    }
    $stmt->close();
}

$data = json_decode(file_get_contents('php://input'), true);
$upgradeType = $data['upgradeType'] ?? null;

if (!$user || !$upgradeType) {
    echo json_encode(['success' => false, 'error' => 'You must be logged in to purchase upgrades.']);
    exit;
}

$prices = [
    'VIP' => 10,
    'Criminal' => 30,
    'Rich' => 50,
    'Change Color' => 5
];

if (!isset($prices[$upgradeType])) {
    echo json_encode(['success' => false, 'error' => 'Invalid upgrade type']);
    exit;
}

$price = $prices[$upgradeType];

$apiKey = 'FZRN17V-0YSMVKJ-HVPZ1A2-1R19YXR'; 

$callbackUrl = 'https://mewbin.ru/payment_callback.php'; 
$paymentData = [
    'price_amount' => $price,
    'price_currency' => 'USD',
    'pay_currency' => 'LTC',
    'order_id' => uniqid($user . '_'),
    'order_description' => json_encode([
        'upgradeType' => $upgradeType,
        'set_has_color' => ($upgradeType === 'Change Color') ? 1 : 0
    ]),
    'ipn_callback_url' => $callbackUrl
];

$ch = curl_init('https://api.nowpayments.io/v1/invoice');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'x-api-key: ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paymentData));

$response = curl_exec($ch);
curl_close($ch);

if ($response) {
    $responseData = json_decode($response, true);
    if (isset($responseData['invoice_url'])) {
        echo json_encode([
            'success' => true,
            'payment_url' => $responseData['invoice_url'],
            'order_id' => $paymentData['order_id']
        ]);
        exit;
    } else {
        $errorMsg = $responseData['message'] ?? 'Failed to create payment link';
        echo json_encode(['success' => false, 'error' => $errorMsg]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Failed to create payment link']);
