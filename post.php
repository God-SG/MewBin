<?php
include_once("database.php");
require_once 'waf.php';

if (!isset($conn) || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? "Connection not established"));
    http_response_code(500);
    exit("Database connection error");
}

session_start();

// Ensure session cookies are secure
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => 0,
        'path' => $params["path"],
        'domain' => $params["domain"],
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Regenerate session ID once
if (!isset($_SESSION['initialized'])) {
    session_regenerate_id(true);
    $_SESSION['initialized'] = true;
}

// CSRF token
if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper to hash IP
function hashIP($ip) {
    $pepper = getenv('IP_HASH_PEPPER') ?: 'MEWBIN_Pepper_2024';
    $salt = getenv('IP_HASH_SALT') ?: 'MEWBIN_Salt_2024'; 
    return hash_hmac('sha256', $ip . $salt, $pepper);
}

// Determine user
$creator = 'Anonymous';
$user_rank = null;

if (isset($_COOKIE['login_token'])) {
    $loginToken = $_COOKIE['login_token'];
    $stmt = $conn->prepare("SELECT username, rank, locked FROM users WHERE login_token = ?");
    $stmt->bind_param("s", $loginToken);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['locked'] == 1) {
            session_destroy();
            setcookie('login_token', '', time() - 3600, "/", "", true, true);
            $creator = 'Anonymous';
            $user_rank = null;
        } else {
            $creator = $row['username'];
            $user_rank = $row['rank'];
        }
    }
    $stmt->close();
} elseif (isset($_SESSION['username'])) {
    $creator = $_SESSION['username'];
    $user_rank = $_SESSION['rank'] ?? null;
}

// Edit paste detection
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$paste_to_edit = null;
if ($edit_id && $creator !== 'Anonymous') {
    $stmt = $conn->prepare("SELECT * FROM pastes WHERE id = ? AND creator = ?");
    $stmt->bind_param("is", $edit_id, $creator);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $paste_to_edit = $result->fetch_assoc();
    }
    $stmt->close();
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit("CSRF token invalid. Please refresh the page.");
    }

    $title = trim($_POST['paste-title'] ?? '');
    $paste_content = trim($_POST['paste'] ?? '');
    $visibility = (int)($_POST['visibility'] ?? 0);
    $edit_paste_id = (int)($_POST['edit_id'] ?? null);
    $password = trim($_POST['password'] ?? '');
    $password_enabled = !empty($password) ? 1 : 0;

    // Validate input
    if (empty($title) || empty($paste_content)) {
        http_response_code(400);
        exit("Title and content cannot be empty.");
    }
    if (!preg_match('/^[A-Za-z0-9\s\-_.,!?]{1,50}$/u', $title)) {
        http_response_code(400);
        exit("Invalid title format");
    }
    if (!in_array($visibility, [0, 2, 3])) {
        http_response_code(400);
        exit("Invalid visibility value");
    }
    if (strlen($title) > 50 || strlen($paste_content) > 500000) {
        http_response_code(400);
        exit("Content exceeds maximum length");
    }

    // Captcha check for non-privileged users
    $bypass_captcha = in_array($user_rank, ['Admin','Manager','Mod','Council','Founder','Rich','Clique','Criminal']);
    if (!$bypass_captcha) {
        $captcha_response = $_POST['cf-turnstile-response'] ?? '';
        if (empty($captcha_response)) exit("Captcha verification required");

        $secret_key = '0x4AAAAAABc2HDvUXxW4YJGiRKLHymFT72A'; 
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'secret' => $secret_key,
                'response' => $captcha_response,
                'remoteip' => $_SERVER['REMOTE_ADDR']
            ]
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($http_code !== 200 || empty($data['success'])) {
            http_response_code(400);
            exit("Captcha verification failed.");
        }
    }

    $user_ip = $_SERVER['REMOTE_ADDR'];
    if (!filter_var($user_ip, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        exit("Invalid IP address");
    }
    $hashed_ip = hashIP($user_ip);

    // Rate limiting
    $stmt = $conn->prepare("SELECT date_created FROM pastes WHERE (creator = ? OR hashed_ip = ?) ORDER BY date_created DESC LIMIT 1");
    $stmt->bind_param("ss", $creator, $hashed_ip);
    $stmt->execute();
    $stmt->bind_result($last_paste_time);
    $stmt->fetch();
    $stmt->close();
    if ($last_paste_time && !$edit_paste_id) {
        $last_time = strtotime($last_paste_time);
        if (time() - $last_time < 60) {
            http_response_code(429);
            exit("You must wait 1 minute before posting another paste.");
        }
    }

    // Duplicate title check (new paste)
    if (!$edit_paste_id) {
        $stmt = $conn->prepare("SELECT id FROM pastes WHERE title = ? AND creator = ?");
        $stmt->bind_param("ss", $title, $creator);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            http_response_code(409);
            exit("A paste with this title already exists.");
        }
        $stmt->close();
    }

    $hashed_password = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;

    // Insert or update paste
    if ($edit_paste_id && $creator !== 'Anonymous') {
        $stmt = $conn->prepare("UPDATE pastes SET title = ?, content = ?, visibility = ?, hashed_ip = ?, password = ?, password_enabled = ? WHERE id = ? AND creator = ?");
        $stmt->bind_param("ssisisis", $title, $paste_content, $visibility, $hashed_ip, $hashed_password, $password_enabled, $edit_paste_id, $creator);
        if (!$stmt->execute()) {
            error_log("Error updating paste: " . $stmt->error);
            http_response_code(500);
            exit("Error updating paste");
        }
        $stmt->close();
    } else {
        $date_created = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO pastes (creator, title, content, date_created, visibility, hashed_ip, password, password_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssissi", $creator, $title, $paste_content, $date_created, $visibility, $hashed_ip, $hashed_password, $password_enabled);
        if (!$stmt->execute()) {
            error_log("Error saving paste: " . $stmt->error);
            http_response_code(500);
            exit("Error saving paste");
        }
        $stmt->close();
    }

    // Regenerate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Redirect to view.php
    $redirectUrl = "view.php?title=" . urlencode($title);
    if (!headers_sent()) {
        header("Location: $redirectUrl");
        exit();
    } else {
        echo '<script>window.location.href = "' . $redirectUrl . '";</script>';
        exit();
    }
}

// Logout logic
if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time()-3600, '/');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>MEWBIN - <?= $paste_to_edit ? 'Edit Paste' : 'Create New Paste' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Fira+Code:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../npm/bootswatch-5.0.1/dist/darkly/bootstrap.min.css" referrerpolicy="no-referrer"/>
    <link rel="stylesheet" href="../assets/css/styles.min.css/">
    <script async src="../cdn-cgi/challenge-platform/h/g/scripts/invisible.js"></script>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    <style>
:root {
  --bg-primary: #0a0a0a;
  --bg-secondary: #1a1a1a;
  --bg-tertiary: #2a2a2a;
  --accent-primary: #9e00b5;
  --accent-secondary: #5c0275;
  --accent-blue: #34B0DF;
  --text-primary: #ffffff;
  --text-secondary: #a0a0a0;
  --error: #ff4444;
  --success: #00ff88;
  --border-radius: 12px;
  --transition: all 0.2s ease;
  --glass-bg: rgba(15, 15, 15, 0.85);
  --glass-border: rgba(255, 255, 255, 0.1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    color: var(--text-primary);
    background: linear-gradient(135deg, var(--bg-primary) 0%, #151515 100%);
    font-family: 'Inter', sans-serif;
    margin: 0;
    padding: 0;
    overflow-x: hidden;
    min-height: 100vh;
}

/* Main Editor Container */
.editor-container {
    display: flex;
    height: 100vh;
    position: relative;
}

.text-editor {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: var(--bg-primary);
    position: relative;
}

#textbox {
    flex: 1;
    width: 100%;
    color: var(--text-primary);
    background: var(--bg-secondary);
    border: none;
    line-height: 1.5;
    outline: none;
    resize: none;
    overflow: auto;
    white-space: pre;
    scrollbar-width: thin;
    font-family: 'Fira Code', monospace;
    font-size: 14px;
    font-weight: 400;
    padding: 24px;
    border-radius: 0;
    transition: var(--transition);
    tab-size: 4;
}

#textbox:focus {
    background: var(--bg-tertiary);
}

.sidebar-toggle {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1060;
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: var(--border-radius);
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
}

.sidebar-toggle:hover {
    background: rgba(25, 25, 25, 0.9);
    transform: translateY(-2px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
}

.toggle-arrow {
    color: var(--text-primary);
    font-size: 18px;
    font-weight: 600;
    transition: var(--transition);
}

.sidebar-toggle[aria-expanded="true"] .toggle-arrow {
    transform: rotate(180deg);
}

/* Sidebar Styles */
.sidebar {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border-left: 1px solid var(--glass-border);
    width: 380px;
    height: 100vh;
    position: fixed;
    top: 0;
    right: -380px;
    z-index: 1050;
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    box-shadow: -8px 0 32px rgba(0, 0, 0, 0.5);
}

.sidebar.show {
    right: 0;
}

.sidebar-header {
    padding: 24px;
    border-bottom: 1px solid var(--glass-border);
    background: rgba(10, 10, 10, 0.8);
}

.sidebar-title {
    font-size: 1.5rem;
    font-weight: 700;
    background: linear-gradient(90deg, var(--accent-primary), var(--accent-blue));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 4px;
}

.sidebar-subtitle {
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.sidebar-close {
    background: none;
    border: none;
    color: var(--text-primary);
    font-size: 1.2rem;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: var(--transition);
}

.sidebar-close:hover {
    background: rgba(255, 255, 255, 0.1);
}

.sidebar-body {
    flex: 1;
    padding: 24px;
    overflow-y: auto;
    scrollbar-width: thin;
}

.sidebar-footer {
    padding: 24px;
    border-top: 1px solid var(--glass-border);
    background: rgba(10, 10, 10, 0.8);
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.9rem;
}

.form-control {
    width: 100%;
    padding: 14px 16px;
    background: rgba(30, 30, 30, 0.8);
    border: 1px solid var(--glass-border);
    border-radius: var(--border-radius);
    color: var(--text-primary);
    font-size: 1rem;
    font-family: 'Inter', sans-serif;
    transition: var(--transition);
    backdrop-filter: blur(10px);
}

.form-control:focus {
    outline: none;
    border-color: var(--accent-primary);
    box-shadow: 0 0 0 3px rgba(0, 255, 136, 0.1);
    background: rgba(40, 40, 40, 0.8);
}

.form-control::placeholder {
    color: var(--text-secondary);
}

.btn {
    padding: 14px 24px;
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
    width: 100%;
    font-family: 'Inter', sans-serif;
}

.btn-primary {
    background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
    color: #000;
    box-shadow: 0 4px 15px rgba(128, 0, 255, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(128, 0, 255, 0.3);
}

.btn-secondary {
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-primary);
    border: 1px solid var(--glass-border);
    backdrop-filter: blur(10px);
}

.btn-secondary:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: translateY(-2px);
}

.user-info {
    background: rgba(0, 255, 136, 0.1);
    border: 1px solid rgba(0, 255, 136, 0.2);
    border-radius: var(--border-radius);
    padding: 16px;
    margin-bottom: 20px;
    text-align: center;
}

.user-info .username {
    font-weight: 600;
    color: var(--accent-primary);
}

.logo-section {
    text-align: center;
    margin-bottom: 24px;
    padding: 20px;
    background: rgba(0, 0, 0, 0.3);
    border-radius: var(--border-radius);
}

.logo {
    max-width: 180px;
    height: auto;
    margin-bottom: 12px;
}

.register-link {
    color: var(--accent-blue) !important;
    text-decoration: none;
    font-weight: 500;
}

.register-link:hover {
    text-decoration: underline;
}

/* Warning Section */
.warning-section {
    background: rgba(255, 68, 68, 0.1);
    border: 1px solid rgba(255, 68, 68, 0.3);
    border-radius: var(--border-radius);
    padding: 16px;
    margin: 20px 0;
    text-align: center;
}

.warning-text {
    color: var(--error);
    font-size: 0.9rem;
    font-weight: 500;
}

.admin-controls {
    background: rgba(52, 176, 223, 0.1);
    border: 1px solid rgba(52, 176, 223, 0.3);
    border-radius: var(--border-radius);
    padding: 20px;
    margin: 20px 0;
}

.admin-controls .form-label {
    color: var(--accent-blue);
}

.tos-section {
    background: rgba(255, 255, 255, 0.05);
    border-radius: var(--border-radius);
    padding: 20px;
    margin-top: 20px;
    font-size: 0.85rem;
    text-align: center;
    color: var(--text-secondary);
}

.tos-link {
    color: var(--accent-blue) !important;
    text-decoration: none;
    font-weight: 500;
}

.tos-link:hover {
    text-decoration: underline;
}

/* Scrollbar Styling */
::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.3);
}

::-webkit-scrollbar-track {
    background: rgba(20, 20, 20, 0.1);
}

@media (max-width: 768px) {
    .editor-container {
        flex-direction: column;
    }
    
    .sidebar {
        width: 100%;
        right: -100%;
    }
    
    .sidebar-toggle {
        top: 15px;
        right: 15px;
        width: 44px;
        height: 44px;
    }
    
    .sidebar-header,
    .sidebar-body,
    .sidebar-footer {
        padding: 20px;
    }
    
    #textbox {
        padding: 20px;
        font-size: 16px; /* Prevent zoom on iOS */
    }
    
    .logo {
        max-width: 150px;
    }
}

@media (max-width: 480px) {
    .sidebar {
        width: 100%;
    }
    
    .sidebar-header,
    .sidebar-body,
    .sidebar-footer {
        padding: 16px;
    }
    
    #textbox {
        padding: 16px;
    }
    
    .btn {
        padding: 16px 20px;
        min-height: 52px;
    }
    
    .form-control {
        padding: 16px;
        font-size: 16px;
    }
}

.cf-turnstile {
    transform: scale(0.9);
    transform-origin: left center;
}

/* Animation */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.fade-in {
    animation: fadeIn 0.3s ease-out;
}

::selection {
    background:(214, 72, 215, 0.4);
    color: var(--text-primary);
}
</style>
</head>
<body>
    <div class="editor-container">
        <div class="text-editor">
            <textarea 
                id="textbox" 
                name="paste" 
                spellcheck="false" 
                placeholder="Start typing your paste here..."
                required
            ><?= $paste_to_edit ? htmlspecialchars($paste_to_edit['content']) : '' ?></textarea>
        </div>

        <button class="sidebar-toggle" type="button" id="sidebarToggle">
            <span class="toggle-arrow">›</span>
        </button>

        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h2 class="sidebar-title">MEWBIN</h2>
                        <p class="sidebar-subtitle"><?= $paste_to_edit ? 'Edit your paste' : 'Create secure paste' ?></p>
                    </div>
                    <button class="sidebar-close" type="button" id="sidebarClose">
                        ×
                    </button>
                </div>
            </div>

            <form method="post" class="sidebar-body" onsubmit="return validateForm()">
                <!-- Hidden field to carry textarea content (textarea lives outside the form) -->
                <input type="hidden" name="paste" id="paste-hidden" value="<?= $paste_to_edit ? htmlspecialchars($paste_to_edit['content']) : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($paste_to_edit): ?>
                    <input type="hidden" name="edit_id" value="<?= htmlspecialchars($edit_id, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>

                <div class="logo-section fade-in">
                    <img src="../img/logo.gif" alt="MEWBIN" class="logo">
                    <br>
                    <a href="register.php" class="register-link">Create an account for enhanced features</a>
                </div>

                <!-- User Info -->
                <div class="user-info fade-in">
                    Posting as: <span class="username"><?= htmlspecialchars($creator, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="warning-section fade-in">
                    <p class="warning-text">READ TOS BEFORE POSTING<br>
                    Posts that violate our terms WILL be removed.</p>
                </div>
				
                <div class="form-group fade-in">
                    <label class="form-label" for="paste-title">Paste Title</label>
                    <input 
                        class="form-control" 
                        id="paste-title"
                        name="paste-title" 
                        type="text" 
                        placeholder="Enter title (50 characters max)" 
                        maxlength="50" 
                        pattern="[A-Za-z0-9\s\-_.,!?]{1,50}" 
                        value="<?= $paste_to_edit ? htmlspecialchars($paste_to_edit['title']) : '' ?>" 
                        required
                    >
                </div>

                <?php if (isset($creator) && $creator !== 'Anonymous' && isset($user_rank) && in_array($user_rank, ['Admin', 'Manager', 'Mod', 'Council', 'Founder', 'Rich', 'Clique', 'Criminal'])): ?>
                    <div class="admin-controls fade-in">
                        <div class="form-group">
                            <label class="form-label" for="visibility">Visibility Settings</label>
                            <select class="form-control" id="visibility" name="visibility">
                                <?php
                                $visibilities = [
                                    0 => 'Public - Visible to everyone',
                                    3 => 'Unlisted - Only accessible via link',
                                    2 => 'Private - Only visible to you'
                                ];
                                $current_visibility = $paste_to_edit ? $paste_to_edit['visibility'] : 0;
                                foreach ($visibilities as $value => $label) {
                                    $selected = $current_visibility == $value ? 'selected' : '';
                                    echo "<option value=\"$value\" $selected>$label</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="password">Password Protection</label>
                            <input 
                                class="form-control" 
                                id="password"
                                name="password" 
                                type="password" 
                                placeholder="Optional password for extra security"
                            >
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!isset($user_rank) || !in_array($user_rank, ['Admin', 'Manager', 'Mod', 'Council', 'Founder', 'Rich', 'Clique', 'Criminal'])): ?>
                    <div class="form-group fade-in">
                        <label class="form-label">Security Verification</label>
                        <div class="cf-turnstile" data-sitekey="0x4AAAAAABc2HBs4hhiSXz3P"></div>
                    </div>
                <?php endif; ?>
				
                <div class="form-group fade-in">
                    <button class="btn btn-primary" type="submit">
                        <span><?= $paste_to_edit ? 'Update Paste' : 'Create Paste' ?></span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>

                <div class="form-group fade-in">
                    <button class="btn btn-secondary" type="button" onclick="clearText()">
                        <span>Clear Text</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                    </button>
                </div>

                <div class="tos-section fade-in">
                    <p>By posting this paste, you agree to our <a href="tos" target="_blank" rel="noopener noreferrer" class="tos-link">Terms of Service</a>. Your paste(s) will be deleted if you post something that violates the law and/or our ToS.</p>
                </div>
            </form>
        </div>
    </div>

    <script src="../npm/bootstrap-5.0.1/dist/js/bootstrap.bundle.min.js" referrerpolicy="no-referrer"></script>
    <script>
    function validateForm() {
        const title = document.querySelector('input[name="paste-title"]').value.trim();
        const paste = document.querySelector('textarea[name="paste"]').value.trim();
        // copy textarea content into hidden input so it is included in the POST
        const pasteHidden = document.getElementById('paste-hidden');
        if (pasteHidden) pasteHidden.value = document.querySelector('textarea[name="paste"]').value;
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        
        console.log('Form validation - Title:', title, 'Paste length:', paste.length, 'CSRF token length:', csrfToken.length);
        
        if (!csrfToken || csrfToken.length < 10) {
            alert('Security token missing or invalid. Please refresh the page and try again.');
            window.location.reload();
            return false;
        }
        
        if (!title || !paste) {
            alert('Title and paste content are required');
            return false;
        }
        
        if (title.length > 50) {
            alert('Title must be 50 characters or less');
            return false;
        }
        
        if (paste.length > 500000) {
            alert('Paste content is too long');
            return false;
        }
        
        const submitBtn = document.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>Posting...</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>';
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span><?= $paste_to_edit ? 'Update Paste' : 'Create Paste' ?></span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
            }, 5000);
        }
        
        return true;
    }

    function clearText() {
        document.getElementById('textbox').value = '';
        document.getElementById('textbox').focus();
    }

    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarClose = document.getElementById('sidebarClose');
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
        
        sidebarClose.addEventListener('click', function() {
            sidebar.classList.remove('show');
        });
        
        if (window.innerWidth <= 768) {
            document.addEventListener('click', function(e) {
                if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target) && sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                }
            });
        }
        
        const textbox = document.getElementById('textbox');
        if (textbox && !sidebar.classList.contains('show')) {
            textbox.focus();
        }
        
        function setMobileHeight() {
            if (window.innerWidth <= 768) {
                const vh = window.innerHeight * 0.01;
                document.documentElement.style.setProperty('--vh', `${vh}px`);
            }
        }
        
        setMobileHeight();
        window.addEventListener('resize', setMobileHeight);
        window.addEventListener('orientationchange', function() {
            setTimeout(setMobileHeight, 100);
        });
        
        
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                console.log('Form submission started');
                const formData = new FormData(form);
                for (let [key, value] of formData.entries()) {
                    if (key === 'paste') {
                        console.log(key + ': ' + value.substring(0, 100) + '...');
                    } else {
                        console.log(key + ': ' + value);
                    }
                }
            });
        }
    });
    </script>
</body>
</html>