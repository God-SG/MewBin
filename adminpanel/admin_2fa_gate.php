<?php
include_once('../database.php');
require_once '../PHPGangsta/GoogleAuthenticator.php';

if (!isset($_SESSION['login_token']) && isset($_COOKIE['login_token'])) {
    $login_token = preg_replace('/[^a-f0-9]/', '', $_COOKIE['login_token']);
    if ($login_token) {
        $stmt = $conn->prepare("SELECT username, login_token, rank FROM users WHERE login_token = ?");
        $stmt->bind_param("s", $login_token);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (!empty($row['login_token'])) {
                $_SESSION['login_token'] = $row['login_token'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['rank'] = $row['rank'];
                $_SESSION['CREATED'] = time();
                $_SESSION['LAST_ACTIVITY'] = time();
            }
        }
        $stmt->close();
    }
}

if (!isset($_SESSION['login_token'])) {
    header('Location: /login');
    exit;
}

$loginToken = $_SESSION['login_token'];
$stmt = $conn->prepare("SELECT username, rank, 2fa_secret, 2fa_enabled FROM users WHERE login_token = ?");
$stmt->bind_param("s", $loginToken);
$stmt->execute();
$stmt->bind_result($username, $rank, $twofa_secret, $twofa_enabled);
$stmt->fetch();
$stmt->close();

if (!$username || !in_array($rank, ['Admin', 'Manager', 'Mod', 'Council', 'Founder'])) {
    header('Location: /login');
    exit;
}

if (!isset($_SESSION['admin_2fa_lockout'])) {
    $_SESSION['admin_2fa_lockout'] = [];
}
$lockout = $_SESSION['admin_2fa_lockout'][$username] ?? [
    'fail_count' => 0,
    'lock_until' => 0,
    'lock_time' => 1200 
];

$now = time();
if ($lockout['lock_until'] > $now) {
    $remaining = $lockout['lock_until'] - $now;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Admin 2FA Locked</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { background: #181818; color: #fff; font-family: Arial,sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .box { background: #232323; border-radius: 10px; box-shadow: 0 0 24px #000a; padding: 32px 40px; text-align: center; min-width: 340px; }
            .logo { max-width: 120px; margin-bottom: 18px; }
            .title { font-size: 1.5em; font-weight: bold; margin-bottom: 18px; }
            .info { color: #e05fff; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class="box">
            <img src="../assets/images/logo.gif" alt="MewBin" class="logo">
            <div class="title">Admin Panel 2FA Locked</div>
            <div class="info">
                Too many failed 2FA attempts.<br>
                Please wait <?= ceil($remaining / 60) ?> minute(s) before trying again.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (!$twofa_enabled || empty($twofa_secret)) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Admin 2FA Required</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { background: #181818; color: #fff; font-family: Arial,sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .box { background: #232323; border-radius: 10px; box-shadow: 0 0 24px #000a; padding: 32px 40px; text-align: center; min-width: 340px; }
            .title { font-size: 1.5em; font-weight: bold; margin-bottom: 18px; }
            .info { color: #e05fff; margin-bottom: 10px; }
            .btn { background: #34B0DF; color: #fff; border: none; border-radius: 6px; padding: 10px 24px; font-weight: bold; cursor: pointer; }
            .btn:hover { background: #2993ba; }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="title">Admin Panel 2FA Required</div>
            <div class="info">You must enable Two-Factor Authentication (2FA) in your <a href="/settings.php" style="color:#34B0DF;text-decoration:underline;">settings</a> to access the admin panel.</div>
            <a href="/settings.php" class="btn">Go to Settings</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_SESSION['admin_2fa_verified']) && $_SESSION['admin_2fa_verified'] === true && isset($_SESSION['admin_2fa_time']) && ($now - $_SESSION['admin_2fa_time'] < 300)) {
    return;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_2fa_code'])) {
    $code = trim($_POST['admin_2fa_code']);
    $ga = new PHPGangsta_GoogleAuthenticator();
    if ($ga->verifyCode($twofa_secret, $code, 2)) {
        $_SESSION['admin_2fa_verified'] = true;
        $_SESSION['admin_2fa_time'] = time();
        $_SESSION['admin_2fa_lockout'][$username] = [
            'fail_count' => 0,
            'lock_until' => 0,
            'lock_time' => 1200
        ];
        header('Location: ' . ($_GET['redirect'] ?? 'index.php'));
        exit;
    } else {
        $lockout['fail_count']++;
        if ($lockout['fail_count'] >= 3) {
            $lockout['lock_until'] = $now + $lockout['lock_time'];
            $lockout['lock_time'] = min($lockout['lock_time'] * 2, 7200);
            $lockout['fail_count'] = 0;
            $_SESSION['admin_2fa_lockout'][$username] = $lockout;
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Admin 2FA Locked</title>
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <style>
                    body { background: #181818; color: #fff; font-family: Arial,sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                    .box { background: #232323; border-radius: 10px; box-shadow: 0 0 24px #000a; padding: 32px 40px; text-align: center; min-width: 340px; }
                    .logo { max-width: 120px; margin-bottom: 18px; }
                    .title { font-size: 1.5em; font-weight: bold; margin-bottom: 18px; }
                    .info { color: #e05fff; margin-bottom: 10px; }
                </style>
            </head>
            <body>
                <div class="box">
                    <img src="../assets/images/logo.gif" alt="MewBin" class="logo">
                    <div class="title">Admin Panel 2FA Locked</div>
                    <div class="info">
                        Too many failed 2FA attempts.<br>
                        Please wait <?= ceil(($lockout['lock_until'] - $now) / 60) ?> minute(s) before trying again.
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        } else {
            $_SESSION['admin_2fa_lockout'][$username] = $lockout;
            $error = "Invalid 2FA code. Please try again.";
        }
    }
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin 2FA Verification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { background: #181818; color: #fff; font-family: Arial,sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .box { background: #232323; border-radius: 10px; box-shadow: 0 0 24px #000a; padding: 32px 40px; text-align: center; min-width: 340px; }
        .logo { max-width: 120px; margin-bottom: 18px; }
        .title { font-size: 1.5em; font-weight: bold; margin-bottom: 18px; }
        .error { color: #ff6b6b; margin-bottom: 10px; }
        .form-control { padding: 10px; border-radius: 6px; border: 1px solid #444; background: #181818; color: #fff; width: 100%; margin-bottom: 18px; }
        .btn { background: #34B0DF; color: #fff; border: none; border-radius: 6px; padding: 10px 24px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #2993ba; }
    </style>
</head>
<body>
    <div class="box">
        <img src="../assets/images/logo.gif" alt="MewBin" class="logo">
        <div class="title">Admin Panel 2FA Verification</div>
        <?php if ($error): ?>
            <div class="error"><?=htmlspecialchars($error)?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="text" name="admin_2fa_code" class="form-control" placeholder="Enter 2FA code" autocomplete="one-time-code" required autofocus>
            <button type="submit" class="btn">Verify</button>
        </form>
        <div style="margin-top:12px;color:#bbb;font-size:0.95em;">2FA code required for admin access.<br>Code valid for 5 minutes.</div>
    </div>
</body>
</html>
<?php
exit;
