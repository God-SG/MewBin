<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include_once("database.php");
require_once 'includes/r2-helper.php';

session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$sql = "SELECT u.*, GROUP_CONCAT(b.image_url) AS badge_images, GROUP_CONCAT(b.name) AS badge_names, GROUP_CONCAT(b.description) AS badge_descriptions 
        FROM users u 
        LEFT JOIN user_badges ub ON u.id = ub.user_id 
        LEFT JOIN badges b ON ub.badge_id = b.id 
        WHERE u.username = ? 
        GROUP BY u.id";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: ../home');
    exit;
}

$user = $result->fetch_assoc();
$badgeImages = explode(',', $user['badge_images'] ?? '');
$badgeNames = explode(',', $user['badge_names'] ?? '');
$badgeDescriptions = explode(',', $user['badge_descriptions'] ?? '');

// Initialize media URLs with default values
$profilePicture = '/assets/default_profile.png';
$bannerPicture = '/assets/default_banner.jpg';
$profile_bg_url = '';
$profile_bg_is_gif = false;
$musicPath = '';

// Set profile picture URL using R2 helper
if (!empty($user['profile_picture'])) {
    try {
        // Use the R2 helper to get the correct URL
        $profilePicture = r2_profile_picture_url($user['profile_picture']);
        error_log("Profile picture URL generated: " . $profilePicture);
    } catch (Exception $e) {
        error_log("Error getting profile picture URL: " . $e->getMessage());
        $profilePicture = '/assets/default_profile.png';
    }
} else {
    $profilePicture = '/assets/default_profile.png';
}

// Set banner picture URL using R2 helper
if (!empty($user['banner_picture'])) {
    try {
        // Use the R2 helper to get the correct URL
        $bannerPicture = r2_banner_url($user['banner_picture']);
        error_log("Banner picture URL generated: " . $bannerPicture);
    } catch (Exception $e) {
        error_log("Error getting banner picture URL: " . $e->getMessage());
        $bannerPicture = '/assets/default_banner.jpg';
    }
} else {
    $bannerPicture = '/assets/default_banner.jpg';
}

// Set profile background URL using R2 helper
if (!empty($user['profile_bg'])) {
    try {
        // Clean the filename and use R2 helper
        $bg_filename = basename($user['profile_bg']);
        $profile_bg_url = r2_profile_bg_url('profile_backgrounds/' . $bg_filename);
        $profile_bg_is_gif = (strtolower(pathinfo($bg_filename, PATHINFO_EXTENSION)) === 'gif');
        error_log("Profile background URL: " . $profile_bg_url . " | Is GIF: " . ($profile_bg_is_gif ? 'yes' : 'no'));
    } catch (Exception $e) {
        error_log("Error getting profile background URL: " . $e->getMessage());
        $profile_bg_url = '';
        $profile_bg_is_gif = false;
    }
} else {
    $profile_bg_url = '';
    $profile_bg_is_gif = false;
}
// Set profile music URL using R2 helper
if (!empty($user['profile_music'])) {
    // Remove any existing path and just use the filename
    $music_filename = basename($user['profile_music']);
    $musicPath = r2_profile_music_url('profile_music/' . $music_filename);
}

$rank = $user['rank'] ?? null;

$isAccountLocked = isset($user['locked']) && $user['locked'] == 1;

$loggedInUsername = null;
if (isset($_COOKIE['login_token'])) {
    $loginToken = $_COOKIE['login_token'];
    $stmt = $conn->prepare("SELECT username FROM users WHERE login_token = ?");
    $stmt->bind_param("s", $loginToken);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $loggedInUsername = $row['username'];
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment_id'])) {
    if (
        empty($_SESSION['csrf_token']) ||
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $comment_id = intval($_POST['delete_comment_id']);

    $sql = "SELECT rank FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $loggedInUsername);
    $stmt->execute();
    $stmt->bind_result($user_rank);
    $stmt->fetch();
    $stmt->close();

    $allowed_ranks = ['Admin', 'Manager', 'Council', 'Founder'];

    if (in_array($user_rank, $allowed_ranks)) {
        $sql = "DELETE FROM profile_comments WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $comment_id);
        if ($stmt->execute()) {
            echo 'success';
        } else {
            echo 'failed';
        }
        $stmt->close();
    } else {
        echo 'no_permission';
    }
    exit();
}

$receiver_username = $username;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    if (
        empty($_SESSION['csrf_token']) ||
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $comment_text = trim($_POST['comment']);
    if (!empty($comment_text) && strlen($comment_text) <= 30 && $loggedInUsername) {
        $comment_text = htmlspecialchars($comment_text, ENT_QUOTES, 'UTF-8'); 
        $commenter_username = $loggedInUsername;

        $secret_key = "0x4AAAAAABc2HDvUXxW4YJGiRKLHymFT72A";
        $captcha_response = $_POST['cf-turnstile-response'];
        $verify_url = "https://challenges.cloudflare.com/turnstile/v0/siteverify";
        $post_data = http_build_query([
            'secret' => $secret_key,
            'response' => $captcha_response,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $verify_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $captcha_result = json_decode($response, true);

        if ($captcha_result['success']) {
            $stmt = $conn->prepare("INSERT INTO profile_comments (commenter_username, receiver_username, comment_text) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $commenter_username, $receiver_username, $comment_text);
            $stmt->execute();
            $stmt->close();
        } 
    }
}

$sql = "SELECT commenter_username, comment_text, created_at, id FROM profile_comments WHERE receiver_username = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $receiver_username);
$stmt->execute();
$comments_result = $stmt->get_result();
$stmt->close();

$pastes_sql = "SELECT * FROM pastes WHERE creator = ? ORDER BY date_created DESC";
$pastes_stmt = $conn->prepare($pastes_sql);
$pastes_stmt->bind_param("s", $receiver_username);
$pastes_stmt->execute();
$pastes_result = $pastes_stmt->get_result();
$pastes_stmt->close();

function timeAgo($time) {
    $time = time() - strtotime($time); 
    $seconds = $time;
    $minutes = round($seconds / 60);          
    $hours = round($seconds / 3600);           
    $days = round($seconds / 86400);     
    $weeks = round($seconds / 604800);     
    $months = round($seconds / 2629440);       
    $years = round($seconds / 31553280);      
    $decades = round($seconds / 315532800);  

    if ($seconds <= 60) {
        return "$seconds seconds ago";
    } else if ($minutes <= 60) {
        return "$minutes minutes ago";
    } else if ($hours <= 24) {
        return "$hours hours ago";
    } else if ($days <= 7) {
        return "$days days ago";
    } else if ($weeks <= 4.3) { 
        return "$weeks weeks ago";
    } else if ($months <= 12) {
        return "$months months ago";
    } else if ($years <= 10) {
        return "$years years ago";
    } else {
        return "$decades decades ago";
    }
}

$comments_query = $conn->prepare("SELECT COUNT(*) AS comment_count FROM profile_comments WHERE receiver_username = ?");
$comments_query->bind_param("s", $receiver_username);
$comments_query->execute();
$comments_result = $comments_query->get_result();
$comments_data = $comments_result->fetch_assoc();
$comments_count = $comments_data['comment_count'];

$pastes_query = $conn->prepare("SELECT COUNT(*) AS paste_count FROM pastes WHERE creator = ?");
$pastes_query->bind_param("s", $receiver_username); 
$pastes_query->execute();
$pastes_result = $pastes_query->get_result();
$pastes_data = $pastes_result->fetch_assoc();
$pastes_count = $pastes_data['paste_count'];

$comments_query->close();
$pastes_query->close();

if (!isset($_GET['username']) || empty(trim($_GET['username']))) {
    header('Location: ../home');
    exit;
}

$receiver_username = trim($_GET['username']);
if (!preg_match('/^[a-zA-Z0-9_!]+$/', $receiver_username)) {
    header('Location: ../home');
    exit;
}

$sql = "SELECT u.*, GROUP_CONCAT(b.image_url) AS badge_images, GROUP_CONCAT(b.name) AS badge_names, GROUP_CONCAT(b.description) AS badge_descriptions 
        FROM users u 
        LEFT JOIN user_badges ub ON u.id = ub.user_id 
        LEFT JOIN badges b ON ub.badge_id = b.id 
        WHERE u.username = ? 
        GROUP BY u.id";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $receiver_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: ../home');
    exit;
} else {
    $user = $result->fetch_assoc();
    $badgeImages = explode(',', $user['badge_images'] ?? '');
    $badgeNames = explode(',', $user['badge_names'] ?? '');
    $badgeDescriptions = explode(',', $user['badge_descriptions'] ?? '');
}

$sql = "SELECT bio FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $receiver_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $userBio = $result->fetch_assoc();
    $bio = htmlspecialchars($userBio['bio'] ?? '');
} else {
    $bio = "No bio available."; 
}
$stmt->close();

if (isset($_GET['username'])) {
    $username = $_GET['username'];  
} else {
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : null;
}

if ($username) {
    $sql = "SELECT profile_picture, banner_picture FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($profilePicture, $bannerPicture);
    $stmt->fetch();
    $stmt->close();

    // Get the profile picture path from the database
    $profilePicturePath = $user['profile_picture'] ?? '';

    // Get the full URL using R2 helper
    if (!empty($profilePicturePath)) {
        // If the path already contains 'profile_pictures/', use it as is
        if (strpos($profilePicturePath, 'profile_pictures/') !== false) {
            $profilePicture = r2_url($profilePicturePath);
        } else {
            // Otherwise, prepend 'profile_pictures/' to the filename
            $profilePicture = r2_url('profile_pictures/' . basename($profilePicturePath));
        }
    } else {
        // Fallback to default if empty
        $profilePicture = '/assets/default_profile.png'; // Make sure this exists in your public directory
    }

    // Handle banner picture URL
    if (!empty($bannerPicture)) {
        // If the path already contains 'banner_pictures/', use it as is
        if (strpos($bannerPicture, 'banner_pictures/') !== false) {
            $bannerPicture = r2_url($bannerPicture);
        } else {
            // Otherwise, prepend 'banner_pictures/' to the filename
            $bannerPicture = r2_url('banner_pictures/' . basename($bannerPicture));
        }
    } else {
        $bannerPicture = '../default_banner.jpg';
    }
} else {
    $profilePicture = '../default.png';
    $bannerPicture = '../default_banner.jpg';
}

$sql = "SELECT profile_picture, banner_picture, profile_music, music_name, profile_bg, rank FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $receiver_username);
$stmt->execute();
$stmt->bind_result($profilePicture, $bannerPicture, $profileMusic, $musicName, $profile_bg, $profile_rank);
$stmt->fetch();
$stmt->close();

// Handle profile music display
$musicDisplayName = '';
if (!empty($user['profile_music_name'])) {
    $musicDisplayName = htmlspecialchars($user['profile_music_name']);
} elseif (!empty($user['profile_music'])) {
    $musicDisplayName = htmlspecialchars(basename($user['profile_music']));
}

// Handle profile background permissions
$bg_allowed_ranks = ['Admin', 'Manager', 'Mod', 'Council', 'Clique', 'Rich', 'Founder'];
if (!empty($profile_bg_url) && !in_array($rank, $bg_allowed_ranks)) {
    // User doesn't have permission for custom background
    $profile_bg_url = '';
    $profile_bg_is_gif = false;
}

function formatBio($bio, $rank) {
    $allowedRanks = ['Council', 'Mod', 'Manager', 'Admin'];

    if (in_array($rank, $allowedRanks)) {
        
        $bio = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', function ($matches) {
            $text = htmlspecialchars($matches[1]);
            $url = htmlspecialchars($matches[2]);

            $color = '';
            $glow = '';

            if (preg_match('/color:\s*(#[a-fA-F0-9]{6}|[a-zA-F0-9]+)\s*/', $text, $colorMatch)) {
                $color = $colorMatch[1];
                $text = preg_replace('/color:\s*(#[a-fA-F0-9]{6}|[a-zA-F0-9]+)\s*/', '', $text);
            }

            if (preg_match('/glow:\s*(#[a-fA-F0-9]{6}|[a-zA-F0-9]+)\s*/', $text, $glowMatch)) {
                $glow = $glowMatch[1];
                $text = preg_replace('/glow:\s*(#[a-fA-F0-9]{6}|[a-zA-F0-9]+)\s*/', '', $text);
            }

            $style = 'text-decoration: none;';
            if ($color) {
                $style .= "color: $color;";
            }
            if ($glow) {
                $style .= "text-shadow: 0 0 5px $glow, 0 0 10px $glow, 0 0 15px $glow;";
            }

           
            return '<a href="' . $url . '" style="' . $style . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
        }, $bio);

        
        $bio = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $bio);
    } else {
        $bio = htmlspecialchars($bio);
    }

    return $bio;
}

function getUserRankDetails($rank) {
    static $rankData = null;
    global $conn;

    if ($rankData === null) {
        $rankData = [];
        $query = $conn->query("SELECT rank_name, rankTag, rankColor, tableColor, tableHoverColor FROM rank");
        while ($row = $query->fetch_assoc()) {
            $rankData[$row['rank_name']] = [
                'tag' => $row['rankTag'],
                'color' => $row['rankColor'],
                'tableColor' => $row['tableColor'] ?? '#000',
                'tableHoverColor' => $row['tableHoverColor'] ?? '#FF99FF'
            ];
        }
    }

    if (empty($rank) || !isset($rankData[$rank])) {
        return [
            '#000',       
            '#FF99FF',  
            '',            
            '#fff',         
            ''            
        ];
    }

    $data = $rankData[$rank];

    return [
        $data['tableColor'],
        $data['tableHoverColor'],
        '', 
        $data['color'],
        $data['tag']
    ];
}

$query = "SELECT rank FROM users WHERE username = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$loggedInUserRank = $userData['rank'] ?? 'All Users';

$can_delete = false;
if ($loggedInUsername) {
    $current_user = $loggedInUsername;
    $allowed_ranks = ['Manager', 'Admin', 'Council', 'Founder'];

    $stmt = $conn->prepare("SELECT rank FROM users WHERE username = ?");
    $stmt->bind_param("s", $current_user);
    $stmt->execute();
    $stmt->bind_result($user_rank);
    $stmt->fetch();
    $stmt->close();

    if (in_array($user_rank, $allowed_ranks)) {
        $can_delete = true;
    }
}

if (isset($_COOKIE['login_token'])) {
    $loginToken = $_COOKIE['login_token'];
    $stmt = $conn->prepare("SELECT username FROM users WHERE login_token = ?");
    $stmt->bind_param("s", $loginToken);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $loggedInUsername = $row['username'];
    } else {
        $loggedInUsername = null;
    }
    $stmt->close();
} else {
    $loggedInUsername = null;
}

$isFollowing = false;
if ($loggedInUsername && $loggedInUsername !== $user['username']) {
    $stmt = $conn->prepare("SELECT 1 FROM followers WHERE follower = ? AND following = ?");
    $stmt->bind_param("ss", $loggedInUsername, $user['username']);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $isFollowing = true;
    }
    $stmt->close();
}

$follower_count_stmt = $conn->prepare("SELECT COUNT(*) AS follower_count FROM followers WHERE following = ?");
$follower_count_stmt->bind_param("s", $user['username']);
$follower_count_stmt->execute();
$follower_count_result = $follower_count_stmt->get_result();
$follower_count_data = $follower_count_result->fetch_assoc();
$follower_count = $follower_count_data['follower_count'];
$follower_count_stmt->close();

$following_count_stmt = $conn->prepare("SELECT COUNT(*) AS following_count FROM followers WHERE follower = ?");
$following_count_stmt->bind_param("s", $user['username']);
$following_count_stmt->execute();
$following_count_result = $following_count_stmt->get_result();
$following_count_data = $following_count_result->fetch_assoc();
$following_count = $following_count_data['following_count'];
$following_count_stmt->close();

$followers_stmt = $conn->prepare("SELECT u.username, u.rank, u.color, u.has_color FROM followers f JOIN users u ON f.follower = u.username WHERE f.following = ?");
$followers_stmt->bind_param("s", $user['username']);
$followers_stmt->execute();
$followers_result = $followers_stmt->get_result();
$followers_stmt->close();

$following_stmt = $conn->prepare("SELECT u.username, u.rank, u.color, u.has_color FROM followers f JOIN users u ON f.following = u.username WHERE f.follower = ?");
$following_stmt->bind_param("s", $user['username']);
$following_stmt->execute();
$following_result = $following_stmt->get_result();
$following_stmt->close();

$sql = "SELECT badge_id FROM user_display_badges WHERE user_id = (SELECT id FROM users WHERE username = ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $receiver_username);
$stmt->execute();
$stmt->bind_result($badge_id_str);
$stmt->fetch();
$stmt->close();

$badges = [];
if (!empty($badge_id_str)) {
    $badge_ids = array_unique(array_filter(array_map('trim', explode(',', $badge_id_str))));
    if (!empty($badge_ids)) {
        $placeholders = implode(',', array_fill(0, count($badge_ids), '?'));
        $types = str_repeat('i', count($badge_ids));
        $sql = "SELECT id, image_url, name, description FROM badges WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$badge_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['image_url']) && strpos($row['image_url'], 'uploads/badges/') === 0) {
                $row['image_url'] = '../' . $row['image_url'];
            }
            $badges[] = $row;
        }
        $stmt->close();
    }
}


$friendRequestStatus = null;
$friendRequestId = null;
$showAcceptDecline = false;
if ($loggedInUsername && $loggedInUsername !== $user['username']) {
    $stmt = $conn->prepare("SELECT id, from_user, to_user, status FROM friend_requests WHERE 
        (from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?)");
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("ssss", $loggedInUsername, $user['username'], $user['username'], $loggedInUsername);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $friendRequestId = $row['id'];
        if ($row['from_user'] === $loggedInUsername && $row['status'] === 'pending') {
            $friendRequestStatus = 'pending_sent';
        } elseif ($row['from_user'] === $user['username'] && $row['to_user'] === $loggedInUsername && $row['status'] === 'pending') {
            $friendRequestStatus = 'pending_received';
            $showAcceptDecline = true;
        } elseif ($row['status'] === 'accepted') {
            $friendRequestStatus = 'friends';
        }
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['friend_action']) && $loggedInUsername && $loggedInUsername !== $user['username']) {
    if (
        empty($_SESSION['csrf_token']) ||
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $action = $_POST['friend_action'];
    if ($action === 'add_friend') {
        $stmt = $conn->prepare("SELECT id FROM friend_requests WHERE from_user = ? AND to_user = ? AND status = 'pending'");
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ss", $loggedInUsername, $user['username']);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 0) {
            $stmt->close();
        $stmt = $conn->prepare("DELETE FROM friend_requests WHERE from_user = ? AND to_user = ? AND status = 'pending'");
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ss", $loggedInUsername, $user['username']);
        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }
        $stmt->close();
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } elseif ($action === 'accept_request') {
        $stmt = $conn->prepare("UPDATE friend_requests SET status = 'accepted', responded_at = NOW() WHERE from_user = ? AND to_user = ? AND status = 'pending'");
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ss", $user['username'], $loggedInUsername);
        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }
        $stmt->close();
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } elseif ($action === 'decline_request') {
        $stmt = $conn->prepare("UPDATE friend_requests SET status = 'declined', responded_at = NOW() WHERE from_user = ? AND to_user = ? AND status = 'pending'");
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ss", $user['username'], $loggedInUsername);
        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }
        $stmt->close();
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } elseif ($action === 'unadd_friend') {
        $stmt = $conn->prepare("DELETE FROM friend_requests WHERE ((from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?)) AND status = 'accepted'");
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ssss", $loggedInUsername, $user['username'], $user['username'], $loggedInUsername);
        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }
        $stmt->close();
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>User Profile - MewBin</title>
    <link rel="stylesheet" href="../styles.min.css">
    <link rel="stylesheet" href="../bootstrap.min.css">

    <style>
    body {
        color: #b1b1b1;
        background: #000;
        <?php if ($profile_bg_url && !$profile_bg_is_gif): ?>
        background-image: url('<?= htmlspecialchars($profile_bg_url) ?>');
        background-size: cover;
        background-repeat: no-repeat;
        background-position: center center;
        background-attachment: fixed;
        <?php endif; ?>
        margin: 0;
        padding: 0;
        min-height: 100vh;
    }

    <?php if ($profile_bg_url && $profile_bg_is_gif): ?>
    .profile-bg-gif {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        object-fit: cover;
        z-index: -1;
        pointer-events: none;
    }
    body {
        background: #000 !important;
    }
    <?php endif; ?>

    .main-center-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        min-height: 100vh;
        width: 100vw;
        margin: 0 auto;
        padding-top: 40px;
        position: relative;
        z-index: 1;
    }

    .profile-section {
        display: flex;
        align-items: center;
        background: url('<?php echo htmlspecialchars($bannerPicture); ?>') no-repeat center center;
        background-size: cover;
        padding: 18px 32px; 
        border-radius: 6px;
        margin-bottom: 32px;
        border: 2px solid #111;
        width: 900px; 
        max-width: 98vw;
        margin-left: 0;
        margin-right: 0;
        box-shadow: 0 0 24px #000;
        position: relative;
        background-color: rgba(0,0,0,0.96);
        min-height: 140px; 
    }

    .profile-picture {
        width: 120px;  
        height: 120px; 
        margin-right: 32px;
        border-radius: 4px;
        border: 2px solid #111;
        background: #111;
        object-fit: cover;
    }

        .profile-details {
            text-align: left;
            flex-grow: 1;
            min-width: 0;
        }

        .profile-details strong {
            font-size: 1.3em; 
        }

        .profile-bio {
            margin-top: 8px;
            font-size: 17px;
            color: #fff;
            word-break: break-word;
        }

        .follow-button, .unfollow-button {
            background-color: #000;
            color: #fff;
            border: 2px solid #222;
            padding: 7px 20px;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.3s, border-color 0.3s;
            margin-top: 14px;
            margin-left: 0;
            display: block;
            width: 140px;
            border-radius: 4px;
        }

        .follow-button:hover, .unfollow-button:hover {
            background-color: #181818;
            border-color: #fff;
        }

        .follow-button:focus, .unfollow-button:focus {
            outline: none;
        }

        .follow-button:active, .unfollow-button:active {
            background-color: #111;
            border-color: #fff;
        }

        .profile-stats {
            margin-top: 10px;
            color: #fff;
            font-size: 16px;
        }

        .content-section {
            display: flex;
            flex-direction: row;
            justify-content: center;
            align-items: flex-start;
            gap: 32px; 
            width: 100%;
            margin-top: 0;
        }

        .col-xl-8, .col-xl-4 {
            float: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            max-width: 900px; 
            padding: 0;
        }

        .pastes-box {
            width: 100%;
            max-width: 900px; 
            margin: 0 auto 24px auto;
            background: #050505;
            border-radius: 6px;
            box-shadow: 0 0 16px #000;
            border: 1.5px solid #111;
            padding: 18px 0;
        }
        .user-info, .comments-section {
            width: 100%;
            max-width: 900px; 
            margin: 0 auto 24px auto;
            background: #050505; 
            border-radius: 6px;
            box-shadow: 0 0 16px #000;
            border: 1.5px solid #111;
            padding: 18px 18px;
        }

        .table-responsive {
            width: 100%;
            padding: 0;
            margin: 0;
        }

        .badge-image {
            width: 32px;
            height: 32px;
            margin-left: 7px;
            cursor: pointer;
            position: relative;
            border-radius: 4px;
        }
        .badge-container {
            display: inline-block;
            position: relative;
        }
		.btn-primary {
			color: #fff;
		background-color: #4a0055;
		border-color: #820095;
		}
        .badge-tooltip {
            display: none;
            position: absolute;
            left: 50%;
            bottom: 110%;
            transform: translateX(-50%);
            background-color: #111;
            color: #FFFFFF;
            padding: 12px;
            border-radius: 4px;
            font-size: 13px;
            z-index: 1000;
            box-shadow: 0 4px 16px #000;
            white-space: nowrap;
            min-width: 160px;
            pointer-events: none;
        }

        .badge-tooltip .tooltip-header {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .badge-tooltip .tooltip-body {
            font-size: 13px;
            color: #CCCCCC;
        }

        .comment-box {
            background:rgb(10, 10, 10);
            border: 1px solid #333;
            border-radius: 2px;
            padding: 6px 12px;
            margin-bottom: 4px;
            box-shadow: 0 2px 8px #00000033;
            min-height: unset;
            line-height: 1.3;
        }

        .pastes-box table tr {
		border-bottom: 1px solid #222 !important;
		}
		
		.pastes-box table tr:last-child {
			border-bottom: 1px solid #222 !important;
		}

        @media (max-width: 900px) {
            .profile-section, .pastes-box, .user-info, .comments-section {
                width: 99vw;
                max-width: 99vw;
                padding: 8px;
            }
            .content-section {
                flex-direction: column;
                align-items: center;
                gap: 0;
            }
        }

    </style>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>

<body>
<?php if (!empty($musicPath)): ?>
    <audio id="profileMusic" src="<?= htmlspecialchars($musicPath) ?>" type="audio/mpeg" preload="none" loop></audio>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var audio = document.getElementById('profileMusic');
        if (audio) {
            audio.volume = 0.5;
            audio.play().then(function() {
                audio.muted = false;
            }).catch(function() {
                var tryUnmute = function() {
                    audio.muted = false;
                    audio.play();
                    document.removeEventListener('click', tryUnmute);
                };
                document.addEventListener('click', tryUnmute);
            });
        }
    });
    </script>
<?php endif; ?>
<?php if ($profile_bg_url): ?>
    <?php if ($profile_bg_is_gif): ?>
        <img src="<?= htmlspecialchars($profile_bg_url) ?>" class="profile-bg-gif" alt="Profile Background" />
    <?php else: ?>
        <style>
            body::before {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background-image: url('<?= htmlspecialchars($profile_bg_url) ?>');
                background-size: cover;
                background-position: center;
                background-repeat: no-repeat;
                z-index: -1;
                pointer-events: none;
            }
        </style>
    <?php endif; ?>
<?php endif; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">

<nav class="navbar navbar-light navbar-expand-xl" style="background: rgb(6,6,6); border-bottom: 1px solid rgb(40, 40, 40);">
    <div class="container-fluid">
        <button data-bs-toggle="collapse" class="navbar-toggler" data-bs-target="#navcol-2" style="color: #ffffff; background: #d0d0d0;">
            <span class="visually-hidden">Toggle navigation</span>
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navcol-2">
            <ul class="navbar-nav d-flex flex-row gap-4 me-auto">
                <li>
                    <a class="nav-link" href="home" style="color: white; text-shadow: 0 0 5px #a200ff, 0 0 10px #a200ff, 0 0 15px #a200ff;">
                      MewBin
                    </a>
                <li><a class="nav-link" href="../home">Home</a></li>
                <li><a class="nav-link" href="../upload">Add Paste</a></li>
                <li><a class="nav-link" href="../users">Users</a></li>
                <li><a class="nav-link" href="../hoa">Hall of Autism</a></li>
                <li><a class="nav-link" href="../upgrades">Upgrade</a></li>
                <li><a class="nav-link" href="../faq">FAQ+TOS</a></li>


            </ul>
            <div class="ms-auto">
                <ul class="navbar-nav" style="color: #ffffff;">
                    <?php if ($loggedInUsername): ?>
    <li class="dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <?php echo htmlspecialchars($loggedInUsername); ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item" href="../user/<?php echo htmlspecialchars($loggedInUsername); ?>">Profile</a></li>
                            <li><a class="dropdown-item" href="../settings">Settings</a></li>
                                <li><a class="dropdown-item" href="../logout">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li><a class="nav-link" href="../login">Login</a></li>
                        <li><a class="nav-link" href="../register">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <span class="navbar-text" style="padding-right: 200px;"></span>
        </div>
    </div>
</nav>

<style>
.navbar-nav .dropdown:hover > .dropdown-menu {
    display: block;
    opacity: 1;
    visibility: visible;
}
</style>
Details;

        $comments_display = (isset($paste['comments_enabled']) && $paste['comments_enabled'] == 0) ? '-' : htmlspecialchars($paste['comments']);

        echo "<tr style='
                background-color: " . htmlspecialchars($tableColor, ENT_QUOTES, 'UTF-8') . ";
                transition: background-color 0.2s ease-in-out;'
                onmouseover='this.style.backgroundColor=\"" . htmlspecialchars($tableColorHover, ENT_QUOTES, 'UTF-8') . "\"' 
                onmouseout='this.style.backgroundColor=\"" . htmlspecialchars($tableColor, ENT_QUOTES, 'UTF-8') . "\"'>
            <td class='ellipsis' style='padding-left:40px;'>
                <div style='display: flex; align-items: center;'>
                    <a style='color: #FFF !important; text-decoration: none;' 
                       href='../view.php?title=" . urlencode($paste['title']) . "' 
                       target='_blank'>
                       " . $pinned_icon . $visibility_icon . htmlspecialchars($paste['title']) . "
                    </a>
                </div>
            </td>
            <td title='" . htmlspecialchars($paste['date_created']) . "'>" . htmlspecialchars($formattedTime) . "</td>
            <td>" . htmlspecialchars($paste['views']) . "</td>
            <td>" . $comments_display . "</td>
        </tr>";
    }
} else {
    echo "<tr><td colspan='4'>No pastes found for this user.</td></tr>";
}
?>


                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div style="overflow-y:scroll;" class="comments-section">
                <h2 style="font-size: 21px; text-align: left;">
                    Comments (<?php echo $comments_count; ?>)
                </h2>
                <hr>
                <?php
                $sql = "
                    SELECT 
                        pc.id, pc.commenter_username, pc.comment_text, pc.created_at, 
                        u.rank, u.color, u.has_color 
                    FROM 
                        profile_comments pc 
                    LEFT JOIN 
                        users u 
                    ON 
                        pc.commenter_username = u.username 
                    WHERE 
                        pc.receiver_username = ? 
                    ORDER BY 
                        pc.created_at DESC
                ";
                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    echo "<p style='color: red;'>Error preparing query: " . $conn->error . "</p>";
                    exit();
                }

                $stmt->bind_param("s", $receiver_username);
                $stmt->execute();

                if ($stmt->error) {
                    echo "<p style='color: red;'>Error executing query: " . $stmt->error . "</p>";
                    exit();
                }

                $comments_result = $stmt->get_result();
                $stmt->close();

                if ($comments_result->num_rows > 0):
                    while ($comment = $comments_result->fetch_assoc()):
                        $commenter_username = htmlspecialchars($comment['commenter_username']);
                        $commenter_rank = $comment['rank'] ?? '';
                        $commenter_rank_lc = strtolower($commenter_rank);
                        $commenterRankDetails = getUserRankDetails($commenter_rank);
                        list(, , , $commenterRankColor, $commenterRankTag) = $commenterRankDetails;
                        $commenter_color = ($comment['has_color'] && !empty($comment['color'])) ? $comment['color'] : $commenterRankColor;
                        $rank_class = "rank-" . $commenter_rank_lc;
                        ?>
                        <div class="comment-box" id="comment-<?php echo $comment['id']; ?>">
                            <span class="<?php echo $rank_class; ?>">
                                <strong>
                                    <a href="../user/<?php echo urlencode($commenter_username); ?>" style="color: <?php echo htmlspecialchars($commenter_color, ENT_QUOTES, 'UTF-8'); ?>; text-decoration: none;">
                                        <?php echo $commenter_username; ?>
                                    </a>
                                </strong>
                                <?php if (!empty($commenterRankTag)) { ?>
                                    <span style="color: <?php echo htmlspecialchars($commenterRankColor, ENT_QUOTES, 'UTF-8'); ?>; font-size: 1em; margin-left: 4px;">
                                        <?php echo htmlspecialchars($commenterRankTag); ?>
                                    </span>
                                <?php } ?>
                            </span>
                            <span style="color: #FFFFFF;">: <?= htmlspecialchars($comment['comment_text']) ?></span>
                            <?php if ($can_delete): ?>
                                <button type="button" class="btn btn-danger btn-sm delete-comment" data-comment-id="<?= $comment['id'] ?>" style="margin-left: 20px; font-size: 10px; padding: 2px 6px;">Delete</button>
                            <?php endif; ?>
                        </div>
                <?php
                    endwhile;
                else:
                ?>
                    <p>No comments yet.</p>
                <?php endif; ?>
                <hr>
                <?php if ($loggedInUsername): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="mb-3">
                            <label for="commentInput" class="form-label" style="color: #b1b1b1;">Add a comment (max 1000 characters):</label>
                            <textarea class="form-control no-resize" id="commentInput" name="comment" rows="3" maxlength="2000" style="background: rgb(0,0,0); color: #fff; overflow-y: scroll;" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Submit</button>
                    </form>
                <?php else: ?>
                    <p style="color: #ff3333;">You must be logged in to comment.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="../bootstrap.bundle.min.js" referrerpolicy="no-referrer"></script>
<script>
// Handle profile picture upload
document.addEventListener('DOMContentLoaded', function() {
    const profilePicInput = document.getElementById('profilePictureInput');
    if (profilePicInput) {
        profilePicInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            // Check file size (max 5MB)
            if (file.size > 5 * 1024 * 1024) {
                alert('Image size should be less than 5MB');
                return;
            }
            
            // Check file type
            if (!file.type.match('image.*')) {
                alert('Please select an image file');
                return;
            }
            
            const formData = new FormData();
            formData.append('profile_picture', file);
            
            // Show loading state
            const profilePic = document.getElementById('profilePicture');
            const originalSrc = profilePic.src;
            
            // Create and show loading spinner
            const spinner = document.createElement('div');
            spinner.className = 'spinner-border text-light';
            spinner.style.position = 'absolute';
            spinner.style.top = '50%';
            spinner.style.left = '50%';
            spinner.style.transform = 'translate(-50%, -50%)';
            spinner.style.zIndex = '10';
            profilePic.parentNode.style.position = 'relative';
            profilePic.parentNode.appendChild(spinner);
            
            // Make the request
            fetch('update_profile_picture.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the profile picture
                    profilePic.src = data.url + '?t=' + new Date().getTime();
                    // Show success message
                    const toast = document.createElement('div');
                    toast.className = 'alert alert-success';
                    toast.style.position = 'fixed';
                    toast.style.top = '20px';
                    toast.style.right = '20px';
                    toast.style.zIndex = '9999';
                    toast.textContent = 'Profile picture updated successfully!';
                    document.body.appendChild(toast);
                    setTimeout(() => toast.remove(), 3000);
                } else {
                    alert(data.message || 'Failed to update profile picture');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the profile picture');
            })
            .finally(() => {
                // Remove loading spinner
                if (spinner.parentNode) {
                    spinner.parentNode.removeChild(spinner);
                }
                // Reset the input
                profilePicInput.value = '';
            });
        });
    }
});
</script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.delete-comment').forEach(button => {
            button.addEventListener('click', function() {
                var commentId = this.getAttribute('data-comment-id');
                var confirmed = confirm('Are you sure you want to delete this comment?');
                if (confirmed) {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState == 4 && xhr.status == 200) {
                            if (xhr.responseText === 'success') {
                                document.getElementById('comment-' + commentId).remove();
                            } else if (xhr.responseText === 'no_permission') {
                                alert('You do not have permission to delete this comment.');
                            } else {
                                alert('Failed to delete the comment.');
                            }
                        }
                    };
                    xhr.send('delete_comment_id=' + commentId + '&csrf_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
                }
            });
        });

        document.getElementById('showFollowers').addEventListener('click', function() {
            var followersModal = new bootstrap.Modal(document.getElementById('followersModal'));
            followersModal.show();
        });

        document.getElementById('showFollowing').addEventListener('click', function() {
            var followingModal = new bootstrap.Modal(document.getElementById('followingModal'));
            followingModal.show();
        });

        document.querySelectorAll('.delete-follower').forEach(button => {
            button.addEventListener('click', function() {
                var username = this.getAttribute('data-username');
                var target = "<?php echo htmlspecialchars($user['username']); ?>";
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '../unfollow.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState == 4 && xhr.status == 200) {
                        if (xhr.responseText === 'success') {
                            button.parentElement.remove();
                        } else {
                            alert('Failed to remove the follower.');
                        }
                    }
                };
                xhr.send('action=delete_follower&username=' + username + '&target=' + target);
            });
        });

        document.querySelectorAll('.delete-following').forEach(button => {
            button.addEventListener('click', function() {
                var username = this.getAttribute('data-username');
                var target = "<?php echo htmlspecialchars($user['username']); ?>";
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '../unfollow.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState == 4 && xhr.status == 200) {
                        if (xhr.responseText === 'success') {
                            button.parentElement.remove();
                        } else {
                            alert('Failed to unfollow the user.');
                        }
                    }
                };
                xhr.send('action=delete_following&username=' + username + '&target=' + target);
            });
        });

        document.querySelectorAll('.badge-container').forEach(function(container) {
            var image = container.querySelector('.badge-image');
            var tooltip = container.querySelector('.badge-tooltip');
            image.addEventListener('mouseenter', function () {
                tooltip.style.display = 'block';
            });
            image.addEventListener('mouseleave', function () {
                tooltip.style.display = 'none';
            });
        });
    });
</script>
</body>

</html>