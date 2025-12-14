<?php
require_once __DIR__ . '/database.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('fetchNotifications')) {
    function fetchNotifications($conn, $login_token) {
        $stmt = $conn->prepare("
            SELECT notifications.id, notifications.message, notifications.is_read, notifications.created_at 
            FROM notifications 
            JOIN users ON notifications.username = users.username 
            WHERE users.login_token = ? 
            ORDER BY notifications.created_at DESC
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("s", $login_token);
        if (!$stmt->execute()) {
            return false;
        }
        return $stmt->get_result();
    }
}

if (!function_exists('markNotificationAsRead')) {
    function markNotificationAsRead($conn, $notification_id) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $notification_id);
        if (!$stmt->execute()) {
            return false;
        }
        return true;
    }
}

if (!function_exists('markAllNotificationsAsRead')) {
    function markAllNotificationsAsRead($conn, $login_token) {
        $user = null;
        $stmt = $conn->prepare("SELECT username FROM users WHERE login_token = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $login_token);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && $row = $res->fetch_assoc()) {
                    $user = $row['username'];
                }
            }
            $stmt->close();
        }
        if (!$user) return false;
        $stmt2 = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE username = ?");
        if (!$stmt2) {
            return false;
        }
        $stmt2->bind_param("s", $user);
        $ok = $stmt2->execute();
        $stmt2->close();
        return $ok;
    }
}

if (!function_exists('timeAgo')) {
    function timeAgo($time) {
        $time = time() - strtotime($time); 
        $seconds = $time;
        $minutes = round($seconds / 60);          
        $hours = round($seconds / 3600);           
        $days = round($seconds / 86400);     
        $weeks = round($seconds / 604800);     
        $months = round($seconds / 2629440);       
        $years = round($seconds / 31553280);      

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
        } else {
            return "$years years ago";
        }
    }
}

function humanTimeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;

    if ($diff < 60) return "just now";
    if ($diff < 120) return "a minute ago";
    if ($diff < 3600) return floor($diff/60) . " mins ago";
    if ($diff < 7200) return "an hour ago";
    if ($diff < 86400) return floor($diff/3600) . " hours ago";
    if ($diff < 172800) return "yesterday";
    if ($diff < 604800) return floor($diff/86400) . " days ago";
    return date("M j, Y", $timestamp);
}

if (isset($_GET['mark_read']) && $_GET['mark_read'] == '1' && isset($_SESSION['login_token'])) {
    if (function_exists('markAllNotificationsAsRead')) {
        $result = markAllNotificationsAsRead($conn, $_SESSION['login_token']);
        header('Content-Type: application/json');
        echo json_encode(['success' => $result]);
        exit;
    }
}

$notifications = [];
$unreadCount = 0;
$unreadNotifications = [];
if (isset($_SESSION['login_token'])) {
    $notifications = fetchNotifications($conn, $_SESSION['login_token']);
    while ($notification = $notifications->fetch_assoc()) {
        if ($notification['is_read'] == 0) {
            $unreadCount++;
            $unreadNotifications[] = $notification;
        }
    }
    $notifications->data_seek(0);
}
$notificationCount = isset($unreadCount) ? (int)$unreadCount : 0;
?>
<style>
.notification-bell {
    position: relative;
    display: inline-block;
    margin-right: 10px;
    min-width: 28px;
    min-height: 28px;
    vertical-align: middle;
}
.notification-bell-btn {
    background: transparent;
    border: none;
    outline: none;
    color: #fff;
    font-size: 1.15em; 
    cursor: pointer;
    position: relative;
    min-width: 28px;
    min-height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: none;
    transition: color 0.18s;
}
.notification-bell-btn:focus,
.notification-bell-btn.active {
    color: #2196f3;
}
.notification-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #2196f3;
    color: #fff;
    border-radius: 50%;
    padding: 0 6px;
    font-size: 0.68em;
    font-weight: bold;
    min-width: 18px;
    height: 20px;
    line-height: 18px;
    text-align: center;
    z-index: 2;
    border: 2px solid #111;
    box-shadow: 0 0 0 2px #111;
    pointer-events: none;
    transition: opacity 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.notification-dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 120%;
    background: #181818;
    min-width: 360px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.18);
    border-radius: 8px;
    z-index: 100;
    padding: 10px 0;
    color: #fff;
    font-size: 15px;
    border: 1.5px solid #333;
}
.notification-bell.open .notification-dropdown-menu {
    display: block;
}
.notification-item {
    padding: 8px 18px;
    display: flex;
    align-items: center;
    border-bottom: 1px solid #232323;
}
.notification-item:last-child {
    border-bottom: none;
}
.notification-item .icon {
    margin-right: 8px;
    color: #2196f3;
}
.no-notifications {
    color: #b3b3b3;
    text-align: center;
    padding: 16px 0;
}
</style>

<div class="notification-bell" id="notificationBell">
    <button class="notification-bell-btn" id="notificationBellBtn" aria-label="Notifications" type="button">
        <i class="fas fa-bell"></i>
        <?php if ($notificationCount > 0): ?>
            <span class="notification-badge" id="notificationBadge"><?= $notificationCount ?></span>
        <?php endif; ?>
    </button>
    <div class="notification-dropdown-menu" id="notificationDropdownMenu">
        <?php if ($unreadCount > 0): ?>
            <ul style="list-style: none; padding: 0; margin: 0;">
            <?php foreach ($unreadNotifications as $notification): ?>
                <li class="notification-item">
                    <i class="fas fa-exclamation-circle icon"></i>
                    <?= htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8') ?>
                    <span style="margin-left:auto; color:#888; font-size:12px;">
                        <?= htmlspecialchars(humanTimeAgo($notification['created_at']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="no-notifications">No notifications.</div>
        <?php endif; ?>
    </div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<script>
document.addEventListener('DOMContentLoaded', function() {
    var bell = document.getElementById('notificationBell');
    var btn = document.getElementById('notificationBellBtn');
    var menu = document.getElementById('notificationDropdownMenu');
    var badge = document.getElementById('notificationBadge');
    var hasUnread = <?= $notificationCount > 0 ? 'true' : 'false' ?>;
    var markedRead = false;

    function toggleDropdown(e) {
        e.stopPropagation();
        bell.classList.toggle('open');
        if (bell.classList.contains('open') && badge && hasUnread && !markedRead) {
            badge.style.opacity = '0.5';
            fetch('notification_script.php?mark_read=1', {method:'GET', credentials:'same-origin'})
                .then(function(res) {
                    markedRead = true;
                });
        }
    }
    btn.addEventListener('click', toggleDropdown);

    document.addEventListener('click', function(e) {
        if (!bell.contains(e.target)) {
            bell.classList.remove('open');
        }
    });
});
</script>
