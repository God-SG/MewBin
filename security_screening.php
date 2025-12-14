<?php
session_start();

// === CONFIG ===
$abuseipdb_api_key = '598e31d651c828bcdf0ecab70eae973ec2357427d0fe6576ebe76a0942e5a847d09414de89a711a5';
$turnstile_secret = '0x4AAAAAABc2HDvUXxW4YJGiRKLHymFT72A';
$turnstile_sitekey = '0x4AAAAAABc2HBs4hhiSXz3P';
$redirect_url = 'https://mewbin.ru'; // <-- redirect here after success

// === HTTP Security Headers ===
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval' https://static.cloudflareinsights.com https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://em-content.zobj.net; connect-src 'self'; font-src 'self'; base-uri 'self'; form-action 'self';");

// Get user IP
$ip = $_SERVER['REMOTE_ADDR'];

// Skip screening if already done
if (!empty($_SESSION['screened']) && $_SESSION['screened'] === true) {
    header("Location: $redirect_url");
    exit;
}

// Optional: local IP auto-pass
if ($ip === '127.0.0.1' || $ip === '::1') {
    $_SESSION['screened'] = true;
    $_SESSION['screened_time'] = time();
    header("Location: $redirect_url");
    exit;
}

// Optional: AbuseIPDB check
$fraud_score = 0;
$api_url = "https://api.abuseipdb.com/api/v2/check?ip=" . urlencode($ip);
$opts = [
    "http" => [
        "method" => "GET",
        "header" => "Key: $abuseipdb_api_key\r\nAccept: application/json\r\n"
    ]
];
$context = stream_context_create($opts);
$api_response = @file_get_contents($api_url, false, $context);
if ($api_response !== false) {
    $json = json_decode($api_response, true);
    if (isset($json['data']['abuseConfidenceScore'])) {
        $fraud_score = intval($json['data']['abuseConfidenceScore']);
    }
}
if ($fraud_score > 75) {
    header('HTTP/1.1 403 Forbidden');
    echo "<h1>Access Denied</h1><p>Blocked due to suspicious activity.</p>";
    exit;
}

// Handle POST
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response_token = $_POST['cf-turnstile-response'] ?? '';
    if ($response_token) {
        // Verify Turnstile
        $verify_response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query([
                    'secret' => $turnstile_secret,
                    'response' => $response_token,
                    'remoteip' => $ip,
                ]),
            ],
        ]));

        $verify_data = json_decode($verify_response, true);

        if (!empty($verify_data['success']) && $verify_data['success'] === true) {
            $_SESSION['screened'] = true;
            $_SESSION['screened_time'] = time();
            header("Location: $redirect_url");
            exit;
        } else {
            $error = "Verification failed. Please try again.";
        }
    } else {
        $error = "Captcha response missing.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mewbin Security Page</title>
<style>
body { background: #181818; color: #fff; font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
.screening-box { background: #222; border-radius: 12px; box-shadow: 0 0 24px #000a; padding: 32px 40px; text-align: center; min-width: 340px; }
.title { font-size: 2em; font-weight: bold; letter-spacing: 2px; }
.subtitle { color: #e05fff; font-size: 1.2em; margin: 16px 0 24px 0; }
.challenge { margin: 24px 0 0 0; background: #181818; border-radius: 8px; padding: 18px 0; display: flex; flex-direction: column; align-items: center; }
.error-msg { color: #ff6b6b; margin-bottom: 10px; }
.llama { position: absolute; right: 22vw; bottom: 22vh; width: 48px; opacity: 0.95; }
@media (max-width: 600px) { .screening-box { min-width: 90vw; padding: 18px 4vw; } .llama { display: none; } }
</style>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body>
<div class="screening-box">
    <div class="title">Mewbin</div>
    <div class="subtitle">Security Page</div>
    <div>Please verify you are human to proceed to MewBin.ru</div>
    <div class="challenge">
        <?php if ($error): ?>
            <div class="error-msg"><?=htmlspecialchars($error)?></div>
        <?php endif; ?>
        <form method="POST" action="" id="captchaForm">
            <div class="cf-turnstile" data-sitekey="<?=htmlspecialchars($turnstile_sitekey)?>" data-callback="onCaptchaSuccess"></div>
            <!-- Add a fallback submit button -->
            <noscript><button type="submit">Submit</button></noscript>
        </form>
    </div>
</div>
<img src="https://em-content.zobj.net/source/microsoft-teams/363/llama_1f999.png" class="llama" alt="llama">
<script>
function onCaptchaSuccess(token) {
   
    document.getElementById('captchaForm').submit();
}
</script>
</body>
</html>
