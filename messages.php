<?php
include_once("database.php");
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if (!isset($_SESSION['username'])) {
    if (!empty($_COOKIE['login_token'])) {
        $login_token = $_COOKIE['login_token'];
        $stmt = $conn->prepare("SELECT username, profile_picture FROM users WHERE login_token = ?");
        $stmt->bind_param("s", $login_token);
        $stmt->execute();
        $user_result = $stmt->get_result();
        if ($user_result->num_rows === 0) {
            echo "Invalid login_token. <a href='login.php'>Login</a>";
            exit;
        }
        $user = $user_result->fetch_assoc();
        session_regenerate_id(true);
        $_SESSION['username'] = $user['username'];
    } else {
        echo "No login_token set. <a href='login.php'>Login</a>";
        exit;
    }
} else {
    $stmt = $conn->prepare("SELECT username, profile_picture FROM users WHERE username = ?");
    $stmt->bind_param("s", $_SESSION['username']);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $user = $user_result->fetch_assoc();
    if (!$user) {
        session_destroy();
        header("Location: login.php");
        exit;
    }
}

$loggedInUsername = $user['username'];
$loggedInPfp = !empty($user['profile_picture']) ? htmlspecialchars($user['profile_picture'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'default.png';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'], $_POST['new_status'])) {
    $allowed_statuses = ['online', 'dnd', 'offline'];
    $new_status = in_array($_POST['new_status'], $allowed_statuses, true) ? $_POST['new_status'] : 'online';
    $stmt = $conn->prepare("UPDATE users SET status=? WHERE username=?");
    $stmt->bind_param("ss", $new_status, $loggedInUsername);
    $stmt->execute();
    $stmt->close();
    $_SESSION['user_status'] = $new_status;
    header("Location: messages.php" . (isset($_GET['user']) ? "?user=" . urlencode($_GET['user']) : ""));
    exit;
}

if (!isset($_SESSION['user_status'])) {
    $stmt = $conn->prepare("SELECT status FROM users WHERE username=?");
    $stmt->bind_param("s", $loggedInUsername);
    $stmt->execute();
    $stmt->bind_result($userStatus);
    $stmt->fetch();
    $stmt->close();
    $_SESSION['user_status'] = $userStatus ?: 'online';
} else {
    $userStatus = $_SESSION['user_status'];
}

$friends = [];
$stmt = $conn->prepare("
    SELECT u.username, u.profile_picture, u.status
    FROM users u
    WHERE u.username IN (
        SELECT CASE 
            WHEN from_user = ? THEN to_user
            WHEN to_user = ? THEN from_user
        END
        FROM friend_requests
        WHERE (from_user = ? OR to_user = ?) AND status = 'accepted'
    )
    ORDER BY u.username
");
$stmt->bind_param("ssss", $loggedInUsername, $loggedInUsername, $loggedInUsername, $loggedInUsername);
$stmt->execute();
$friends_result = $stmt->get_result();
while ($row = $friends_result->fetch_assoc()) {
    $row['profile_picture'] = !empty($row['profile_picture']) ? htmlspecialchars($row['profile_picture']) : 'default.png';
    $row['status'] = $row['status'] ?? 'online';
    $friends[] = $row;
}
$stmt->close();

$selectedFriend = isset($_GET['user']) ? trim($_GET['user']) : (isset($friends[0]['username']) ? $friends[0]['username'] : null);
if ($selectedFriend && $selectedFriend === $loggedInUsername) $selectedFriend = null;

$friendUsernames = array_column($friends, 'username');
if ($selectedFriend && !in_array($selectedFriend, $friendUsernames, true)) {
    $selectedFriend = null;
    $notFriendError = true;
} else {
    $notFriendError = false;
}

if (!isset($_SESSION['last_message_time'])) {
    $_SESSION['last_message_time'] = 0;
}
define('MESSAGE_RATE_LIMIT_SECONDS', 2);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_messages' && isset($_GET['user']) && isset($_GET['before'])) {
    header('Content-Type: application/json');
    $friend = trim($_GET['user']);
    $before = $_GET['before'];
    $limit = 20;
    if (!in_array($friend, $friendUsernames, true)) {
        echo json_encode(['error' => 'Not friends']);
        exit;
    }
    $stmt = $conn->prepare("
        SELECT m.*, u.profile_picture AS sender_pfp
        FROM messages m
        LEFT JOIN users u ON m.sender = u.username
        WHERE ((m.sender = ? AND m.receiver = ?) OR (m.sender = ? AND m.receiver = ?))
          AND m.id < ?
        ORDER BY m.id DESC
        LIMIT $limit
    ");
    $stmt->bind_param("ssssi", $loggedInUsername, $friend, $friend, $loggedInUsername, $before);
    $stmt->execute();
    $messages_result = $stmt->get_result();
    $msgs = [];
    while ($row = $messages_result->fetch_assoc()) {
        $row['sender_pfp'] = !empty($row['sender_pfp']) ? htmlspecialchars($row['sender_pfp']) : 'default.png';
        $row['message'] = htmlspecialchars($row['message'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $row['sent_at'] = htmlspecialchars(date('H:i', strtotime($row['sent_at'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $msgs[] = $row;
    }
    $stmt->close();
    echo json_encode(array_reverse($msgs));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'], $_POST['to_user'], $_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }
    if (time() - $_SESSION['last_message_time'] < MESSAGE_RATE_LIMIT_SECONDS) {
        header("Location: messages.php?user=" . urlencode($_POST['to_user']) . "&error=ratelimit");
        exit;
    }
    $to_user = trim($_POST['to_user']);
    $message = trim($_POST['message']);
    $allowedPattern = '/^[\x20-\x7E\r\n\t]*$/'; 
    if (mb_strlen($message) > 800) {
        header("Location: messages.php?user=" . urlencode($to_user) . "&error=toolong");
        exit;
    }
    if (!preg_match($allowedPattern, $message)) {
        header("Location: messages.php?user=" . urlencode($to_user) . "&error=invalidchars");
        exit;
    }
    if ($to_user && $message && $to_user !== $loggedInUsername && in_array($to_user, $friendUsernames, true)) {
        $stmt = $conn->prepare("INSERT INTO messages (sender, receiver, message) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $loggedInUsername, $to_user, $message);
        $stmt->execute();
        $stmt->close();
        $_SESSION['last_message_time'] = time();
        $redirect_user = urlencode($to_user);
        header("Location: messages.php?user=$redirect_user");
        exit;
    }
}

$messages = [];
$messages_limit = 20;
$messages_offset_id = null;
if ($selectedFriend) {
    $stmt = $conn->prepare("
        SELECT m.*, u.profile_picture AS sender_pfp
        FROM (
            SELECT * FROM messages
            WHERE (sender = ? AND receiver = ?) OR (sender = ? AND receiver = ?)
            ORDER BY id DESC
            LIMIT $messages_limit
        ) m
        LEFT JOIN users u ON m.sender = u.username
        ORDER BY m.id ASC
    ");
    $stmt->bind_param("ssss", $loggedInUsername, $selectedFriend, $selectedFriend, $loggedInUsername);
    $stmt->execute();
    $messages_result = $stmt->get_result();
    while ($row = $messages_result->fetch_assoc()) {
        $row['sender_pfp'] = !empty($row['sender_pfp']) ? htmlspecialchars($row['sender_pfp']) : 'default.png';
        $messages[] = $row;
        $messages_offset_id = $row['id'];
    }
    $stmt->close();
}

if ($selectedFriend) {
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE receiver = ? AND sender = ? AND is_read = 0");
    $stmt->bind_param("ss", $loggedInUsername, $selectedFriend);
    $stmt->execute();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Messages - MewBin</title>
    <link rel="stylesheet" href="../bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        body {
            background: #111;
            color: #eee;
            font-family: 'Segoe UI', Arial, sans-serif;
            height: 100vh;
            min-height: 100vh;
        }
        .messages-container {
            display: flex;
            height: 100vh;
            width: 100vw;
            margin: 0;
            background: #181818;
            border-radius: 0;
            box-shadow: none;
            overflow: hidden;
        }
        .friends-list {
            width: 320px;
            background: #151515;
            border-right: 1.5px solid #222;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            padding: 0;
            height: 100vh;
        }
        .friend {
            display: flex;
            align-items: center;
            padding: 8px 12px; 
            cursor: pointer;
            border-bottom: 1px solid #222;
            transition: background 0.18s, box-shadow 0.18s, transform 0.12s;
            text-decoration: none;
            color: #eee;
            background: #181818;
            border-radius: 12px;
            margin: 4px 6px 4px 6px; 
            box-shadow: 0 2px 12px #00000022;
            position: relative;
        }
        .friend.selected, .friend:hover {
            background: #232323; 
            color: #fff;
            box-shadow: 0 4px 18px #2a006655;
            transform: translateY(-2px) scale(1.025);
        }
        .friend-pfp {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 8px; 
            border: 2.5px solid #2a0066;
            background: #222;
            box-shadow: 0 2px 8px #2a006622;
            position: relative;
            display: block;
        }
        .friend-pfp-container {
            position: relative;
            display: inline-block;
        }
        .status-dot {
            display: inline-block;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            border: 2.5px solid #151515;
            vertical-align: middle;
        }
        .status-online { background: #4caf50; box-shadow: 0 0 6px #4caf50cc; }
        .status-dnd { background: #ff3333; box-shadow: 0 0 6px #ff3333cc; }
        .status-offline { background: #888; box-shadow: 0 0 6px #88888855; }
        .friend-pfp-container .status-dot {
            position: absolute;
            bottom: 2px;
            right: 2px;
            margin: 0;
            z-index: 2;
        }
        .friend-username {
            font-size: 1.18em;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            letter-spacing: 0.5px;
        }
        .friend-bio {
            display: none;
        }
        .friend-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
            margin-left: 14px; 
        }
        .friend-unread {
            margin-left: auto;
            color: #ff4d4d;
            font-size: 1.1em;
            display: flex;
            align-items: center;
        }
        .friend-unread .fa-envelope {
            margin-right: 4px;
        }

        .friend-online-dot {
            position: absolute;
            bottom: 7px;
            right: 10px;
            width: 13px;
            height: 13px;
            border-radius: 50%;
            border: 2px solid #181818;
            background: #4caf50;
            box-shadow: 0 0 6px #4caf50cc;
        }
        .friend-offline-dot {
            position: absolute;
            bottom: 7px;
            right: 10px;
            width: 13px;
            height: 13px;
            border-radius: 50%;
            border: 2px solid #181818;
            background: #888;
            box-shadow: 0 0 6px #88888855;
        }
        .no-friends {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #aaa;
            padding: 40px 10px;
        }
        .no-friends img {
            width: 120px;
            margin-bottom: 18px;
            opacity: 0.7;
        }
        .chat-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #181818;
            position: relative;
            height: 100vh;
            min-width: 0;
        }
        .chat-header {
            padding: 18px 24px;
            border-bottom: 1.5px solid #222;
            background: #181818;
            font-size: 1.2em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .chat-header .friend-pfp {
            width: 44px;
            height: 44px;
            margin-right: 0;
        }
        .chat-header .fa-comments {
            color: #2a0066;
            margin-right: 10px;
        }
        .chat-header .fa-user-friends {
            color: #2a0066;
            margin-right: 10px;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 24px 32px 24px 32px;
            display: flex;
            flex-direction: column;
            gap: 18px;
            background: #181818;
        }
        .message-row {
            display: flex;
            align-items: flex-end;
            gap: 12px;
        }
        .message-row.own {
            flex-direction: row-reverse;
        }
        .message-pfp {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #222;
            background: #222;
        }
        .message-bubble {
            max-width: 420px;
            padding: 12px 18px;
            border-radius: 16px;
            background: #232323;
            color: #fff;
            font-size: 1em;
            word-break: break-word;
            box-shadow: 0 2px 8px #00000033;
        }
        .message-row.own .message-bubble {
            background: #2a0066;
        }
        .message-time {
            font-size: 0.85em;
            color: #aaa;
            margin: 0 6px;
            min-width: 80px;
            text-align: right;
        }
        .chat-input-section {
            padding: 18px 24px;
            border-top: 1.5px solid #222;
            background: #191919;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .chat-input-section input[type="text"] {
            flex: 1;
            background: #232323;
            color: #fff;
            border: 1px solid #333;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 1em;
            outline: none;
        }
        .chat-input-section button {
            background: #2a0066;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 10px 24px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .chat-input-section button:hover {
            background: #3d0099;
        }
        @media (max-width: 900px) {
            .messages-container {
                flex-direction: column;
                height: 100vh;
                width: 100vw;
            }
            .friends-list {
                width: 100vw;
                min-width: 0;
                max-width: 100vw;
                flex-direction: row;
                overflow-x: auto;
                border-right: none;
                border-bottom: 1.5px solid #222;
                height: 90px;
            }
            .friend {
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-width: 100px;
                padding: 10px 8px;
                border-bottom: none;
                border-right: 1px solid #222;
            }
            .chat-section {
                min-width: 0;
                height: calc(100vh - 90px);
                display: flex;
                flex-direction: column;
            }
            .chat-messages {
                padding: 12px 6vw 12px 6vw;
                flex: 1 1 auto;
                min-height: 0;
                max-height: none;
                overflow-y: auto;
                background: #181818;
            }
            .chat-input-section {
                position: sticky;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 10;
                background: #191919;
                border-top: 1.5px solid #222;
                padding-bottom: env(safe-area-inset-bottom, 0);
            }
        }
        .chat-section {
            display: flex;
            flex-direction: column;
            height: 100vh;
            min-width: 0;
        }
        .chat-messages {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            background: #181818;
        }
        .chat-input-section {
            flex-shrink: 0;
        }
        .message-group {
            margin-bottom: 2px;
        }
        .message-group.own {
            flex-direction: row-reverse;
        }
        .message-group .message-pfp {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #222;
            background: #222;
            margin-right: 10px;
            margin-left: 10px;
        }
        .message-group.own .message-pfp {
            margin-right: 0;
            margin-left: 10px;
        }
        .message-bubble {
            max-width: 420px;
            padding: 10px 16px;
            border-radius: 14px;
            background: #232323;
            color: #fff;
            font-size: 1em;
            word-break: break-word;
            box-shadow: 0 2px 8px #00000033;
            margin-bottom: 2px;
            margin-top: 0;
        }
        .message-group.own .message-bubble {
            background: #2a0066;
        }
        .message-status {
            margin-left: 8px;
            font-size: 1em;
            vertical-align: middle;
        }
        .message-status.read {
            color: #4caf50;
        }
        .message-status.unread {
            color: #aaa;
        }
        .status-dot {
            display: inline-block;
            width: 13px;
            height: 13px;
            border-radius: 50%;
            margin-right: 0; 
            border: 2px solid #181818;
            vertical-align: middle;
        }
        .status-online { background: #4caf50; box-shadow: 0 0 6px #4caf50cc; }
        .status-dnd { background: #ff3333; box-shadow: 0 0 6px #ff3333cc; } 
        .status-offline { background: #888; box-shadow: 0 0 6px #88888855; }
        .self-section {
            border-top: 1.5px solid #222;
            padding: 18px 18px 12px 18px;
            background: #151515;
            display: flex;
            align-items: center;
            gap: 8px; 
            margin-top: auto;
        }
        .self-pfp {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #2a0066;
            background: #222;
            margin-right: 0; 
        }
        .self-status-dot {
            display: inline-block;
            width: 13px;
            height: 13px;
            border-radius: 50%;
            border: 2px solid #181818;
            vertical-align: middle;
            margin-right: 6px; 
        }
        .self-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .self-username {
            font-size: 1.08em;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .self-status {
            font-size: 0.98em;
            color: #aaa;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .change-status-btn {
            margin-left: auto;
            background: #232323;
            color: #fff;
            border: 1px solid #333;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: 0.98em;
            cursor: pointer;
            transition: background 0.18s;
        }
        .change-status-btn:hover {
            background: #2a0066;
        }
        #status-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0; top: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.55);
            align-items: center;
            justify-content: center;
        }
        #status-modal .modal-content {
            background: #232323;
            padding: 28px 24px 22px 24px;
            border-radius: 12px;
            min-width: 220px;
            max-width: 340px;
            width: 95vw;
            box-shadow: 0 4px 32px #000a;
            position: relative;
            color: #fff;
        }
        #status-modal .close-modal {
            position: absolute;
            top: 10px; right: 14px;
            background: none;
            border: none;
            color: #fff;
            font-size: 1.3em;
            cursor: pointer;
        }
        .status-option {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            cursor: pointer;
        }
        .status-option:last-child { 
            margin-bottom: 0 !important; 
        }
        .friend-unread-badge {
            text-align: center;
            font-weight: bold;
            display: inline-block;
            position: relative;
            top: -1px; 
        }
    </style>
</head>
<body>
<div class="messages-container">
    <div class="friends-list">
        <div style="padding:18px 18px 8px 18px;font-size:1.2em;font-weight:600;color:#fff;letter-spacing:1px;">
            <i class="fa-solid fa-user-friends"></i> Friends List
            <?php
            $pendingCount = 0;
            $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM friend_requests WHERE to_user = ? AND status = 'pending'");
            $stmt->bind_param("s", $loggedInUsername);
            $stmt->execute();
            $stmt->bind_result($pendingCount);
            $stmt->fetch();
            $stmt->close();
            ?>
            <button id="manage-friends-btn" style="float:right;background:#2a0066;color:#fff;border:none;border-radius:6px;padding:4px 12px;font-size:0.9em;cursor:pointer;margin-left:10px;position:relative;">
                <i class="fa-solid fa-user-plus"></i> Manage
                <?php if ($pendingCount > 0): ?>
                    <span style="position:absolute;top:-8px;right:-8px;background:#ff4d4d;color:#fff;border-radius:50%;font-size:0.85em;padding:2px 3px;min-width:12px;text-align:center;font-weight:bold;display:inline-block;z-index:2;">
                        <?= (int)$pendingCount ?>
                    </span>
                <?php endif; ?>
            </button>
        </div>
        <div id="manage-friends-modal" style="display:none;position:fixed;z-index:1000;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,0.55);align-items:center;justify-content:center;">
            <div style="background:#232323;padding:32px 24px 24px 24px;border-radius:12px;min-width:320px;max-width:95vw;box-shadow:0 4px 32px #000a;position:relative;">
                <button id="close-manage-friends" style="position:absolute;top:10px;right:14px;background:none;border:none;color:#fff;font-size:1.3em;cursor:pointer;"><i class="fa fa-times"></i></button>
                <h4 style="color:#fff;margin-bottom:18px;"><i class="fa-solid fa-user-friends"></i> Manage Friends</h4>
                <div>
                    <b style="color:#fff;">Add Friend</b>
                    <form method="POST" style="margin-top:8px;display:flex;gap:8px;">
                        <input type="text" name="add_friend_username" placeholder="Username" required maxlength="32" style="flex:1;background:#181818;color:#fff;border:1px solid #333;border-radius:4px;padding:6px 10px;">
                        <button type="submit" name="add_friend" value="1" style="background:#2a0066;color:#fff;border:none;border-radius:4px;padding:6px 16px;cursor:pointer;">Add</button>
                    </form>
                    <?php if (isset($addFriendMsg)): ?>
                        <div style="color:#fff;margin-top:6px;"><?= htmlspecialchars($addFriendMsg) ?></div>
                    <?php endif; ?>
                </div>
                <div style="margin-top:22px;margin-bottom:18px;">
                    <b style="color:#fff;">Pending Friend Requests</b>
                    <div style="margin-top:8px;">
                        <?php
                        $stmt = $conn->prepare("SELECT fr.id, fr.from_user, u.profile_picture FROM friend_requests fr LEFT JOIN users u ON fr.from_user = u.username WHERE fr.to_user = ? AND fr.status = 'pending'");
                        $stmt->bind_param("s", $loggedInUsername);
                        $stmt->execute();
                        $pending = $stmt->get_result();
                        if ($pending->num_rows === 0) {
                            echo '<div style="color:#aaa;">No pending requests.</div>';
                        } else {
                            while ($req = $pending->fetch_assoc()) {
                                $pfp = !empty($req['profile_picture']) ? htmlspecialchars($req['profile_picture'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'default.png';
                                $from = htmlspecialchars($req['from_user'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">';
                                echo '<img src="'.$pfp.'" alt="pfp" style="width:32px;height:32px;border-radius:50%;border:1px solid #222;background:#222;">';
                                echo '<span style="color:#fff;">'.$from.'</span>';
                                echo '<form method="POST" style="display:inline;margin-left:auto;">';
                                echo '<input type="hidden" name="friend_request_id" value="'.(int)$req['id'].'">';
                                echo '<button name="accept_friend" value="1" style="background:#2a0066;color:#fff;border:none;border-radius:4px;padding:3px 10px;margin-right:4px;cursor:pointer;">Accept</button>';
                                echo '<button name="decline_friend" value="1" style="background:#444;color:#fff;border:none;border-radius:4px;padding:3px 10px;cursor:pointer;">Decline</button>';
                                echo '</form>';
                                echo '</div>';
                            }
                        }
                        $stmt->close();
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var btn = document.getElementById('manage-friends-btn');
            var modal = document.getElementById('manage-friends-modal');
            var closeBtn = document.getElementById('close-manage-friends');
            if (btn && modal && closeBtn) {
                btn.onclick = function() { modal.style.display = 'flex'; };
                closeBtn.onclick = function() { modal.style.display = 'none'; };
                window.onclick = function(e) { if (e.target === modal) modal.style.display = 'none'; };
            }
        });
        </script>
        <?php
        $unreadCounts = [];
        if (!empty($friends)) {
            $usernames = array_column($friends, 'username');
            $placeholders = implode(',', array_fill(0, count($usernames), '?'));
            $types = 's' . str_repeat('s', count($usernames));
            $params = $usernames;
            $sql = "SELECT sender, COUNT(*) as unread_count FROM messages WHERE receiver = ? AND sender IN ($placeholders) AND is_read = 0 GROUP BY sender";
            $stmt = $conn->prepare($sql);
            $bind_names = [];
            $bind_names[] = $loggedInUsername;
            foreach ($params as $val) {
                $bind_names[] = $val;
            }
            $refs = [];
            $refs[] = &$types;
            foreach ($bind_names as $i => $v) {
                $refs[] = &$bind_names[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $unreadCounts[$row['sender']] = (int)$row['unread_count'];
            }
            $stmt->close();
        }
        ?>
        <?php if (empty($friends)): ?>
            <div class="no-friends">
                <img src="https://mewbin.ru/assets/images/logo.gif" alt="No friends">
                <div>
                    <b>No friends yet!</b><br>
                    Add friends to start chatting.
                </div>
            </div>
        <?php else: ?>
            <?php
            $friendBios = [];
            if (!empty($friends)) {
                $usernames = array_column($friends, 'username');
                $placeholders = implode(',', array_fill(0, count($usernames), '?'));
                $types = str_repeat('s', count($usernames));
                $sql = "SELECT username, bio FROM users WHERE username IN ($placeholders)";
                $stmt = $conn->prepare($sql);
                $refs = [];
                $refs[] = &$types;
                foreach ($usernames as $i => $v) {
                    $refs[] = &$usernames[$i];
                }
                call_user_func_array([$stmt, 'bind_param'], $refs);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $friendBios[$row['username']] = $row['bio'] ?? '';
                }
                $stmt->close();
            }
            ?>
            <?php foreach ($friends as $friend): ?>
                <?php
                $isOnline = ($friend['status'] === 'online');
                $isDnd = ($friend['status'] === 'dnd');
                $isOffline = ($friend['status'] === 'offline');
                $bio = isset($friendBios[$friend['username']]) && trim($friendBios[$friend['username']]) !== ''
                    ? htmlspecialchars($friendBios[$friend['username']], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    : '';
                $unread = !empty($unreadCounts[$friend['username']]) ? (int)$unreadCounts[$friend['username']] : 0;
                if ($unread > 9) $unread = '9+';
                ?>
                <a class="friend<?= ($selectedFriend === $friend['username']) ? ' selected' : '' ?>"
                   href="?user=<?= urlencode($friend['username']) ?>">
                    <div class="friend-pfp-container">
                        <img class="friend-pfp" src="<?= htmlspecialchars($friend['profile_picture'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" alt="pfp">
                        <span class="status-dot status-<?= $friend['status'] ?>" title="<?= ucfirst($friend['status']) ?>"></span>
                    </div>
                    <div class="friend-info">
                        <span class="friend-username"><?= htmlspecialchars($friend['username'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
                        <span class="friend-bio"><?= $bio ?></span>
                    </div>
                    <?php if ($unread): ?>
                        <span class="friend-unread" title="Unread messages">
                            <i class="fa-solid fa-envelope"></i>
                            <span class="friend-unread-badge"><?= htmlspecialchars($unread, ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <div style="border-top:1.5px solid #222;margin:0 0 0 0;"></div>
        <div class="self-section">
            <img class="self-pfp" src="<?= $loggedInPfp ?>" alt="pfp">
            <span class="self-status-dot status-<?= $userStatus ?>"></span>
            <div class="self-info">
                <span class="self-username"><?= htmlspecialchars($loggedInUsername, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
                <span class="self-status">
                    <?= ucfirst($userStatus) ?>
                </span>
            </div>
            <button class="change-status-btn" id="open-status-modal" title="Change status">
                <i class="fa-solid fa-circle-half-stroke"></i>
            </button>
        </div>
        <div id="status-modal" style="display:none;position:fixed;z-index:2000;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,0.55);align-items:center;justify-content:center;">
            <div class="modal-content" style="background:#232323;padding:28px 24px 22px 24px;border-radius:12px;min-width:220px;max-width:340px;width:95vw;box-shadow:0 4px 32px #000a;position:relative;color:#fff;">
                <button class="close-modal" id="close-status-modal"><i class="fa fa-times"></i></button>
                <h5 style="margin-bottom:18px;">Change Status</h5>
                <form method="POST" style="margin:0;">
                    <div class="status-option">
                        <input type="radio" id="status-online" name="new_status" value="online" <?= $userStatus === 'online' ? 'checked' : '' ?>>
                        <span class="status-dot status-online"></span>
                        <label for="status-online" style="margin:0;cursor:pointer;">Online</label>
                    </div>
                    <div class="status-option">
                        <input type="radio" id="status-dnd" name="new_status" value="dnd" <?= $userStatus === 'dnd' ? 'checked' : '' ?>>
                        <span class="status-dot status-dnd"></span>
                        <label for="status-dnd" style="margin:0;cursor:pointer;">Do Not Disturb</label>
                    </div>
                    <div class="status-option">
                        <input type="radio" id="status-offline" name="new_status" value="offline" <?= $userStatus === 'offline' ? 'checked' : '' ?>>
                        <span class="status-dot status-offline"></span>
                        <label for="status-offline" style="margin:0;cursor:pointer;">Offline</label>
                    </div>
                    <input type="hidden" name="change_status" value="1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                    <button type="submit" class="change-status-btn" style="margin-top:16px;width:100%;">Save</button>
                </form>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var openBtn = document.getElementById('open-status-modal');
            var modal = document.getElementById('status-modal');
            var closeBtn = document.getElementById('close-status-modal');
            if (openBtn && modal && closeBtn) {
                openBtn.onclick = function() { modal.style.display = 'flex'; };
                closeBtn.onclick = function() { modal.style.display = 'none'; };
                window.onclick = function(e) { if (e.target === modal) modal.style.display = 'none'; };
            }
        });
        </script>
<?php
// Secure friend actions with CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }
    if (isset($_POST['accept_friend'], $_POST['friend_request_id'])) {
        $reqid = (int)$_POST['friend_request_id'];
        $stmt = $conn->prepare("UPDATE friend_requests SET status='accepted' WHERE id=? AND to_user=?");
        $stmt->bind_param("is", $reqid, $loggedInUsername);
        $stmt->execute();
        $stmt->close();
        header("Location: messages.php");
        exit;
    }
    if (isset($_POST['decline_friend'], $_POST['friend_request_id'])) {
        $reqid = (int)$_POST['friend_request_id'];
        $stmt = $conn->prepare("UPDATE friend_requests SET status='declined' WHERE id=? AND to_user=?");
        $stmt->bind_param("is", $reqid, $loggedInUsername);
        $stmt->execute();
        $stmt->close();
        header("Location: messages.php");
        exit;
    }
    if (isset($_POST['add_friend'], $_POST['add_friend_username'])) {
        $target = trim($_POST['add_friend_username']);
        if ($target === $loggedInUsername) {
            $addFriendMsg = "You can't add yourself.";
        } else {
            $stmt = $conn->prepare("SELECT username FROM users WHERE username=?");
            $stmt->bind_param("s", $target);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) {
                $addFriendMsg = "User not found.";
            } else {
                $stmt2 = $conn->prepare("SELECT status FROM friend_requests WHERE (from_user=? AND to_user=?) OR (from_user=? AND to_user=?)");
                $stmt2->bind_param("ssss", $loggedInUsername, $target, $target, $loggedInUsername);
                $stmt2->execute();
                $stmt2->store_result();
                if ($stmt2->num_rows > 0) {
                    $addFriendMsg = "Already requested or friends.";
                } else {
                    $stmt3 = $conn->prepare("INSERT INTO friend_requests (from_user, to_user, status) VALUES (?, ?, 'pending')");
                    $stmt3->bind_param("ss", $loggedInUsername, $target);
                    $stmt3->execute();
                    $stmt3->close();
                    $addFriendMsg = "Friend request sent!";
                }
                $stmt2->close();
            }
            $stmt->close();
        }
    }
}
?>
    </div>
    <div class="chat-section">
        <?php if ($notFriendError): ?>
            <div class="chat-header">
                <i class="fa-solid fa-comments"></i>
                <span style="color:#ff3333;">You can only message your friends.</span>
            </div>
            <div class="chat-messages" style="justify-content:center;align-items:center;">
                <div style="color:#aaa;text-align:center;margin-top:40px;">
                    Select a friend to start chatting.
                </div>
            </div>
        <?php elseif ($selectedFriend): ?>
            <div class="chat-header">
                <?php
                $pfp = 'default.png';
                foreach ($friends as $f) {
                    if ($f['username'] === $selectedFriend) {
                        $pfp = $f['profile_picture'];
                        break;
                    }
                }
                ?>
                <img class="friend-pfp" src="<?= htmlspecialchars($pfp, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" alt="pfp">
                <span><?= htmlspecialchars($selectedFriend, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
            </div>
            <div class="chat-messages" id="chat-messages" data-earliest-id="<?= isset($messages[0]['id']) ? (int)$messages[0]['id'] : 0 ?>">
                <div id="load-older-messages" style="text-align:center;color:#aaa;display:none;padding:8px 0;">Loading...</div>
                <?php if (empty($messages)): ?>
                    <div style="color:#aaa;text-align:center;margin-top:40px;">No messages yet. Say hi!</div>
                <?php else: ?>
                    <?php
                    $lastSender = null;
                    foreach ($messages as $i => $msg):
                        $isOwn = ($msg['sender'] === $loggedInUsername);
                        $showPfp = ($lastSender !== $msg['sender']);
                        $groupClass = $isOwn ? 'own' : '';
                        $lastSender = $msg['sender'];
                        $nextSender = isset($messages[$i+1]) ? $messages[$i+1]['sender'] : null;
                        $isLastInGroup = ($nextSender !== $msg['sender']);
                        $showStatus = $isOwn && $isLastInGroup;
                        $isRead = ($msg['is_read'] ?? 0) ? true : false;
                    ?>
                        <?php if ($showPfp): ?>
                        <div class="message-group <?= $groupClass ?>" style="display:flex;align-items:flex-end;<?= $isOwn ? 'flex-direction:row-reverse;' : '' ?>">
                            <img class="message-pfp" src="<?= htmlspecialchars($msg['sender_pfp'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" alt="pfp" style="margin-bottom:4px;">
                            <div style="display:flex;flex-direction:column;align-items:<?= $isOwn ? 'flex-end' : 'flex-start' ?>;">
                        <?php endif; ?>
                        <div class="message-bubble" style="margin-bottom:2px;">
                            <?= nl2br(htmlspecialchars($msg['message'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?>
                            <span class="message-time" style="display:block;font-size:0.85em;color:#aaa;margin-top:2px;text-align:right;">
                                <?= htmlspecialchars(date('H:i', strtotime($msg['sent_at'])), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                                <?php if ($showStatus): ?>
                                    <?php if ($isRead): ?>
                                        <span class="message-status read" title="Read"><i class="fa-solid fa-check-double"></i></span>
                                    <?php else: ?>
                                        <span class="message-status unread" title="Delivered"><i class="fa-solid fa-check"></i></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($isLastInGroup): ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <form class="chat-input-section" method="POST" autocomplete="off" id="message-form">
                <input type="hidden" name="to_user" value="<?= htmlspecialchars($selectedFriend, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                <input type="text" name="message" id="message-input" placeholder="Type your message..." maxlength="800" required autocomplete="off">
                <button type="submit"><i class="fa-solid fa-paper-plane"></i>Send</button>
            </form>
            <script>
                document.getElementById('message-form').addEventListener('submit', function(e) {
                    var input = document.getElementById('message-input');
                    var value = input.value;
                    if (value.length > 800) {
                        alert("Message too long (max 800 characters).");
                        e.preventDefault();
                        return false;
                    }
                    var allowed = /^[\x20-\x7E\r\n\t]*$/;
                    if (!allowed.test(value)) {
                        alert("Message contains invalid characters. Only standard keyboard characters are allowed.");
                        e.preventDefault();
                        return false;
                    }
                });
                document.getElementById('message-input').addEventListener('input', function(e) {
                    var allowed = /^[\x20-\x7E\r\n\t]*$/;
                    if (!allowed.test(this.value)) {
                        this.value = this.value.replace(/[^\x20-\x7E\r\n\t]/g, '');
                    }
                    if (this.value.length > 800) {
                        this.value = this.value.substring(0, 800);
                    }
                });
            </script>
            <?php if (isset($_GET['error']) && $_GET['error'] === 'ratelimit'): ?>
                <script>
                    alert("You're sending messages too fast. Please wait a moment.");
                </script>
            <?php elseif (isset($_GET['error']) && $_GET['error'] === 'toolong'): ?>
                <script>
                    alert("Message too long. Maximum 800 characters allowed.");
                </script>
            <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalidchars'): ?>
                <script>
                    alert("Message contains invalid characters. Only standard keyboard characters are allowed.");
                </script>
            <?php endif; ?>
        <?php else: ?>
            <div class="chat-header">
                <i class="fa-solid fa-comments"></i>
                <img class="friend-pfp" src="<?= $loggedInPfp ?>" alt="pfp">
                <span>Messages</span>
            </div>
            <div class="chat-messages" style="justify-content:center;align-items:center;">
                <div style="color:#aaa;text-align:center;margin-top:40px;">
                    Select a friend to start chatting.
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
