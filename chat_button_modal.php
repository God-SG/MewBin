<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once(__DIR__ . '/database.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    session_regenerate_id(true);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$user = [
    'username' => 'anonymous',
    'rank' => null,
    'color' => null,
    'has_color' => null,
];
if (!empty($_COOKIE['login_token']) && isset($conn)) {
    $token = $_COOKIE['login_token'];
    $stmt = $conn->prepare("SELECT username, rank, color, has_color FROM users WHERE login_token = ? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        $user['username'] = $row['username'];
        $user['rank'] = $row['rank'];
        $user['color'] = $row['color'];
        $user['has_color'] = $row['has_color'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_action'])) {
    $action = $_POST['chat_action'];

    if ($action === 'users') {
        $users = [];
        $res = $conn->query("SELECT username, rank, color, has_color FROM users WHERE username != 'anonymous'");
        while ($row = $res->fetch_assoc()) {
            $users[] = [
                'username' => $row['username'],
                'rank' => $row['rank'],
                'color' => $row['color'],
                'has_color' => $row['has_color']
            ];
        }
        echo json_encode($users);
        exit;
    }

    $locked = false;
    $statusRes = $conn->query("SELECT locked FROM chat_status WHERE id=1");
    if ($statusRes && $row = $statusRes->fetch_assoc()) {
        $locked = (bool)$row['locked'];
    }

    $muted = false;
    $muteRes = $conn->prepare("SELECT until FROM chat_mutes WHERE username = ? AND until > NOW()");
    $muteRes->bind_param("s", $user['username']);
    $muteRes->execute();
    $muteRes->store_result();
    if ($muteRes->num_rows > 0) $muted = true;

    if ($action === 'send') {
        if ($user['username'] === 'anonymous') {
            exit;
        }
        if (!isset($_SESSION['last_chat_send'])) $_SESSION['last_chat_send'] = 0;
        if (time() - $_SESSION['last_chat_send'] < 2) {
            exit; 
        }
        $_SESSION['last_chat_send'] = time();

        $msg = trim($_POST['msg'] ?? '');
        if (!preg_match('/^[\x20-\x7E\r\n\t]+$/', $msg) || preg_match('/^@\w+$/', $msg)) {
            exit; 
        }
        $muted = false;
        $muteRes = $conn->prepare("SELECT until FROM chat_mutes WHERE username = ? AND until > NOW()");
        $muteRes->bind_param("s", $user['username']);
        $muteRes->execute();
        $muteRes->store_result();
        if ($muteRes->num_rows > 0) $muted = true;
        if ($msg !== '' && !$locked && !$muted) {
            if (mb_strlen($msg) > 500) $msg = mb_substr($msg, 0, 500);
            $stmt = $conn->prepare("INSERT INTO chat_messages (username, text) VALUES (?, ?)");
            $stmt->bind_param("ss", $user['username'], $msg);
            $stmt->execute();
        }
        exit;
    }
    if ($action === 'clear' && in_array($user['rank'], ['Admin','Manager','Mod','Council','Founder'])) {
        $conn->query("TRUNCATE TABLE chat_messages");
        $systemMsg = 'Chat was cleared by ' . $user['username'];
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, text) VALUES ('MewBin [System]', ?)");
        $stmt->bind_param("s", $systemMsg);
        $stmt->execute();
        exit;
    }
    if ($action === 'lock' && in_array($user['rank'], ['Admin','Manager','Mod','Council','Founder'])) {
        $conn->query("UPDATE chat_status SET locked=1 WHERE id=1");
        $systemMsg = 'Chat was locked by ' . $user['username'];
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, text) VALUES ('MewBin [System]', ?)");
        $stmt->bind_param("s", $systemMsg);
        $stmt->execute();
        exit;
    }
    if ($action === 'unlock' && in_array($user['rank'], ['Admin','Manager','Mod','Council','Founder'])) {
        $conn->query("UPDATE chat_status SET locked=0 WHERE id=1");
        $systemMsg = 'Chat was unlocked by ' . $user['username'];
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, text) VALUES ('MewBin [System]', ?)");
        $stmt->bind_param("s", $systemMsg);
        $stmt->execute();
        exit;
    }
    if ($action === 'mute' && in_array($user['rank'], ['Admin','Manager','Mod','Council','Founder'])) {
        $target = trim($_POST['target'] ?? '');
        if ($target && $target !== 'anonymous' && $target !== $user['username']) {
            $until = date('Y-m-d H:i:s', time() + 3600);
            $reason = 'Muted by ' . $user['username'];
            $stmt = $conn->prepare("REPLACE INTO chat_mutes (username, muted_by, until, reason) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $target, $user['username'], $until, $reason);
            $stmt->execute();
            $systemMsg = "$target was temporarily muted for 1 hour.";
            $stmt2 = $conn->prepare("INSERT INTO chat_messages (username, text) VALUES ('MewBin [System]', ?)");
            $stmt2->bind_param("s", $systemMsg);
            $stmt2->execute();
        }
        exit;
    }
    if ($action === 'unmute' && in_array($user['rank'], ['Admin','Manager','Mod','Council','Founder'])) {
        $target = trim($_POST['target'] ?? '');
        if ($target && $target !== 'anonymous') {
            $stmt = $conn->prepare("DELETE FROM chat_mutes WHERE username = ?");
            $stmt->bind_param("s", $target);
            $stmt->execute();
            $systemMsg = "$target was unmuted.";
            $stmt2 = $conn->prepare("INSERT INTO chat_messages (username, text) VALUES ('MewBin [System]', ?)");
            $stmt2->bind_param("s", $systemMsg);
            $stmt2->execute();
        }
        exit;
    }
    if ($action === 'system_message' && in_array($user['rank'], ['Admin','Manager','Mod','Council','Founder'])) {
        $msg = trim($_POST['msg'] ?? '');
        if ($msg !== '') {
            $stmt = $conn->prepare("INSERT INTO chat_messages (username, text) VALUES ('MewBin [System]', ?)");
            $stmt->bind_param("s", $msg);
            $stmt->execute();
        }
        exit;
    }
    if ($action === 'export' && in_array($user['rank'], ['Admin','Manager','Mod','Council','Founder'])) {
        $format = $_POST['format'] ?? 'json';
        $res = $conn->query("SELECT id, username, text, time FROM chat_messages ORDER BY id ASC");
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="chat_export.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id','username','text','time']);
            foreach ($rows as $r) fputcsv($out, $r);
            fclose($out);
        } elseif ($format === 'txt') {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="chat_export.txt"');
            foreach ($rows as $r) {
                echo "[{$r['time']}] {$r['username']}: {$r['text']}\n";
            }
        } else {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="chat_export.json"');
            echo json_encode($rows, JSON_PRETTY_PRINT);
        }
        exit;
    }

    if ($action === 'fetch') {
        $res = $conn->query("SELECT id, username, text, time FROM (SELECT * FROM chat_messages ORDER BY id DESC LIMIT 100) AS t ORDER BY id ASC");
        $messages = [];
        while ($row = $res->fetch_assoc()) {
            $msgUser = $row['username'];
            $msgText = $row['text'];
            $msgTime = '';
            if (!empty($row['time']) && $row['time'] !== '0000-00-00 00:00:00') {
                $msgTime = date('H:i', strtotime($row['time']));
            }
            $msgType = (stripos($msgUser, 'MewBin [system]') !== false) ? 'system' : 'user';
            $rank = null; $color = null; $has_color = null;
            if ($msgType === 'user' && $msgUser !== 'anonymous') {
                $ustmt = $conn->prepare("SELECT rank, color, has_color FROM users WHERE username = ? LIMIT 1");
                $ustmt->bind_param("s", $msgUser);
                $ustmt->execute();
                $ures = $ustmt->get_result();
                if ($ures && $urow = $ures->fetch_assoc()) {
                    $rank = $urow['rank'];
                    $color = $urow['color'];
                    $has_color = $urow['has_color'];
                }
            } elseif ($msgType === 'system') {
                $rank = 'System';
                $color = '#e05f5f';
                $has_color = 0;
            }
            preg_match_all('/@([a-zA-Z0-9_]+)/', $msgText, $mentionMatches);
            $mentions = $mentionMatches[1] ?? [];
            if (strlen(trim($msgText)) > 0) {
                $messages[] = [
                    'id' => $row['id'],
                    'time' => $msgTime,
                    'username' => $msgUser,
                    'rank' => $rank,
                    'color' => $color,
                    'has_color' => $has_color,
                    'msg' => $msgText,
                    'type' => $msgType,
                    'mentions' => $mentions
                ];
            }
        }

        $locked = false;
        $statusRes = $conn->query("SELECT locked FROM chat_status WHERE id=1");
        if ($statusRes && $row = $statusRes->fetch_assoc()) {
            $locked = (bool)$row['locked'];
        }
        $muted = false;
        $muteRes = $conn->prepare("SELECT until FROM chat_mutes WHERE username = ? AND until > NOW()");
        $muteRes->bind_param("s", $user['username']);
        $muteRes->execute();
        $muteRes->store_result();
        if ($muteRes->num_rows > 0) $muted = true;
        echo json_encode([
            'messages' => $messages,
            'locked' => $locked,
            'muted' => $muted,
            'csrf_token' => $_SESSION['csrf_token']
        ]);
        exit;
    }
}
?>
<style>
#chat-btn {
    position: fixed;
    bottom: 24px;
    left: 24px;
    z-index: 9999;
    background: #222;
    color: #fff;
    border: none;
    padding: 7px 16px;
    font-size: 15px;
    font-weight: 500;
    cursor: pointer;
    box-shadow: 0 2px 8px #000a;
    transition: background 0.2s;
}
#chat-btn:hover {
    background: #444;
}
#chat-modal-bg {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0; top: 0; width: 100vw; height: 100vh;
    background: transparent; 
    pointer-events: none; 
}
#chat-modal {
    position: fixed;
    left: 24px;
    bottom: 10px;
    width: 650px;
    max-width: 99vw;
    background: #181818;
    color: #fff;
    box-shadow: 0 2px 16px #000a;
    z-index: 10001;
    display: none;
    flex-direction: column;
    font-family: Arial, sans-serif;
    height: 350px; 
    max-height: 560px; 
    min-height: 340px;
}
#chat-modal-header {
    background: #222;
    padding: 2px 10px; 
    font-size: 14px;   
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 32px;  
    height: 32px;
    gap: 4px;
}
#chat-modal-close {
    background: none;
    border: none;
    color: #fff;
    font-size: 16px;
    cursor: pointer;
    margin-left: 8px;
    transition: color 0.2s;
    height: 28px;
    line-height: 1;
    padding: 0 4px;
}
#chat-modal-close:hover {
    color: #f9243f;
}
#chat-modal-body {
    background: #181818;
    padding: 0;
    flex: 1 1 auto;
    max-height: none;
    min-height: 0;
    overflow-y: auto;
    border-bottom: 1px solid #282828;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
#chat-modal-body::-webkit-scrollbar,
#chat-messages::-webkit-scrollbar {
    display: none;
}
#chat-messages {
    padding: 8px 12px 0 12px;
    font-size: 14px; 
    min-height: 0;
    max-height: 100%;
    overflow-y: auto;
    height: 100%;
    box-sizing: border-box;
    scrollbar-width: thin;
    -ms-overflow-style: auto;
}
#chat-messages::-webkit-scrollbar {
    display: block;
    width: 8px;
    background: #232323;
}
#chat-messages::-webkit-scrollbar-thumb {
    background: #333;
    border-radius: 4px;
}
.mention-highlight {
    background: #2a3b5c;
    color: #ffd700 !important;
    border-radius: 3px;
    padding: 0 3px;
    font-weight: bold;
    display: inline-flex;
    align-items: center;
    gap: 2px;
}
.mention-rank {
    font-size: 11px;
    font-weight: bold;
    margin-left: 2px;
}
#mention-autofill {
    position: absolute;
    z-index: 10020;
    background: #232323;
    color: #fff;
    border: 1px solid #444;
    border-radius: 4px;
    min-width: 120px;
    max-height: 180px;
    overflow-y: auto;
    font-size: 13px;
    display: none;
    box-shadow: 0 2px 8px #000a;
}
#mention-autofill .mention-item {
    padding: 4px 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}
#mention-autofill .mention-item.selected,
#mention-autofill .mention-item:hover {
    background: #444;
}
#mention-autofill .mention-rank {
    font-size: 11px;
    font-weight: bold;
    margin-left: 4px;
}
#mention-autofill .mention-color {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 4px;
    border: 1px solid #555;
}
#chat-modal-footer {
    display: flex;
    align-items: center;
    padding: 6px 10px;
    background: #181818;
}
#chat-input {
    flex: 1;
    border: none;
    padding: 5px 8px;
    font-size: 13px;
    background: #232323;
    color: #fff;
    margin-right: 6px;
    outline: none;
    height: 28px;
    min-width: 0;
}
#chat-send-btn {
    background: #222;
    color: #fff;
    border: none;
    padding: 5px 13px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s;
    height: 28px;
}
#chat-clear-btn, #chat-lock-btn, #chat-unlock-btn, #chat-mute-btn, #chat-unmute-btn {
    background: #222;
    color: #fff;
    border: none;
    padding: 5px 13px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s, color 0.2s;
    height: 28px;
    margin-left: 8px;
    margin-right: 0;
    outline: none;
}
#chat-clear-btn:hover,
#chat-lock-btn:hover,
#chat-unlock-btn:hover,
#chat-mute-btn:hover,
#chat-unmute-btn:hover {
    background: #f9243f;
    color: #fff;
}
#chat-mute-user, #chat-unmute-user {
    background: #232323;
    color: #fff;
    border: none;
    padding: 5px 8px;
    font-size: 13px;
    margin-left: 8px;
    margin-right: 0;
    outline: none;
    height: 28px;
    width: 100px;
}
#chat-mute-user::placeholder, #chat-unmute-user::placeholder {
    color: #aaa;
    opacity: 1;
}
#chat-modal-header {
    gap: 4px;
}
#chat-admin-modal-bg {
    display: none;
    position: fixed;
    z-index: 10010;
    left: 0; top: 0; width: 100vw; height: 100vh;
    background: transparent;
    pointer-events: none;
}
#chat-admin-modal {
    display: none;
    position: fixed;
    left: 100px;
    top: 100px;
    width: 340px;
    background: #232323;
    color: #fff;
    box-shadow: 0 2px 16px #000a;
    z-index: 10011;
    flex-direction: column;
    font-family: Arial, sans-serif;
    border-radius: 6px;
    min-width: 260px;
    user-select: none;
}
#chat-admin-modal-header {
    background: #222;
    padding: 4px 10px;
    font-size: 14px;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: move;
    border-radius: 6px 6px 0 0;
}
#chat-admin-modal-close, #chat-admin-modal-min {
    background: none;
    border: none;
    color: #fff;
    font-size: 16px;
    cursor: pointer;
    margin-left: 8px;
    transition: color 0.2s;
    height: 28px;
    line-height: 1;
    padding: 0 4px;
}
#chat-admin-modal-close:hover {
    color: #f9243f;
}
#chat-admin-modal-min:hover {
    color: #aaa;
}
#chat-admin-modal-body {
    padding: 12px 10px 10px 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
#chat-admin-modal-body input[type="text"] {
    background: #181818;
    color: #fff;
    border: none;
    padding: 5px 8px;
    font-size: 13px;
    border-radius: 3px;
    outline: none;
    width: 120px;
}
#chat-admin-modal-body button {
    background: #222;
    color: #fff;
    border: none;
    padding: 5px 13px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s, color 0.2s;
    height: 28px;
    margin-left: 0;
    margin-right: 0;
    outline: none;
    border-radius: 3px;
}
#chat-admin-modal-body button:hover {
    background: #f9243f;
    color: #fff;
}
#chat-admin-modal-body .admin-row {
    display: flex;
    align-items: center;
    gap: 6px;
}
#chat-admin-modal-body .export-row {
    margin-top: 10px;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
}
#chat-admin-modal-body .export-btns button {
    margin-right: 6px;
    margin-bottom: 4px;
}
#chat-admin-modal-body .system-msg-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
}
@media (max-width: 800px) {
    #chat-admin-modal {
        width: 98vw;
        left: 1vw;
        top: 10vw;
        min-width: 0;
    }
    #chat-admin-modal-body input[type="text"] {
        width: 70px;
        font-size: 12px;
        padding: 3px 6px;
    }
    #chat-admin-modal-body button {
        padding: 3px 7px;
        font-size: 12px;
    }
}
.chat-time {
    color: #aaa !important;
}
#chat-message-modal-bg {
    display: none;
    position: fixed;
    z-index: 11000;
    left: 0; top: 0; width: 100vw; height: 100vh;
    background: rgba(0,0,0,0.45);
}
#chat-message-modal {
    display: none;
    position: fixed;
    left: 50%; top: 50%;
    transform: translate(-50%, -50%);
    min-width: 520px;
    max-width: 99vw;
    background: #181818;
    color: #fff;
    box-shadow: 0 2px 16px #000a;
    z-index: 11001;
    border-radius: 7px;
    font-family: Arial, sans-serif;
    padding: 0;
    min-height: 180px;
    max-height: 320px;
}
#chat-message-modal-content {
    padding: 18px 22px 16px 22px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-height: 80px;
}
#chat-message-modal .msg-modal-header {
    font-size: 15px;
    font-weight: bold;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 8px;
}
#chat-message-modal .msg-modal-time {
    color: #aaa;
    font-size: 13px;
    font-weight: normal;
}
#chat-message-modal .msg-modal-username {
    font-weight: bold;
    font-size: 15px;
}
#chat-message-modal .msg-modal-rank {
    font-size: 12px;
    font-weight: bold;
    margin-left: 4px;
}
#chat-message-modal .msg-modal-body {
    font-size: 14px;
    margin: 8px 0 0 0;
    white-space: pre-line;
    word-break: break-word;
}
#chat-message-modal .msg-modal-btns {
    display: flex;
    gap: 8px;
    margin-top: 10px;
}
#chat-message-modal-close-btn {
    background: #222;
    color: #fff;
    border: none;
    font-size: 15px;
    font-weight: 500;
    cursor: pointer;
    border-radius: 3px;
    padding: 5px 18px;
    transition: background 0.2s;
}
#chat-message-modal-close-btn:hover {
    background: #f9243f;
    color: #fff;
}
</style>

<button id="chat-btn">Chat</button>

<div id="chat-modal-bg"></div>
<div id="chat-modal">
    <div id="chat-modal-header">
        <div style="display:flex;align-items:center;">
            Chat
            <?php if (in_array($user['rank'], ['Admin','Manager','Mod','Council','Founder'])): ?>
                <button id="chat-admin-controls-btn"
                    style="margin-left:8px;font-size:17px;padding:0 6px;background:none;border:none;color:#fff;line-height:1;vertical-align:middle;cursor:pointer;"
                    title="Admin Controls">
                    <span style="font-family:monospace;font-weight:bold;font-size:17px;letter-spacing:1px;line-height:1;">
                        &#9776;
                    </span>
                </button>
            <?php endif; ?>
        </div>
        <button id="chat-modal-close" title="Close">&times;</button>
    </div>
    <div id="chat-modal-body">
        <div id="chat-messages"></div>
    </div>
    <div id="chat-modal-footer">
        <input id="chat-input" type="text" placeholder="Message MewBin" autocomplete="off" maxlength="150">
        <button id="chat-send-btn">Send</button>
        <div id="mention-autofill"></div>
    </div>
</div>

<?php if (in_array($user['rank'], ['Admin','Manager','Mod','Council','Founder'])): ?>
<div id="chat-admin-modal-bg"></div>
<div id="chat-admin-modal">
    <div id="chat-admin-modal-header">
        Admin Controls
        <span>
            <button id="chat-admin-modal-min" title="Minimize">&#8211;</button>
            <button id="chat-admin-modal-close" title="Close">&times;</button>
        </span>
    </div>
    <div id="chat-admin-modal-body">
        <div class="admin-row">
            <button id="chat-clear-btn">Clear</button>
            <button id="chat-lock-btn">Lock</button>
            <button id="chat-unlock-btn">Unlock</button>
        </div>
        <div class="admin-row">
            <input id="chat-mute-user" type="text" placeholder="Mute user">
            <button id="chat-mute-btn">Mute</button>
        </div>
        <div class="admin-row">
            <input id="chat-unmute-user" type="text" placeholder="Unmute user">
            <button id="chat-unmute-btn">Unmute</button>
        </div>
        <div class="admin-row system-msg-row">
            <input id="chat-system-msg" type="text" placeholder="System message" style="width:140px;">
            <button id="chat-system-msg-btn">Send System Msg</button>
        </div>
        <div class="admin-row export-row">
            <div style="font-size:13px;font-weight:bold;margin-bottom:2px;">Export Chat Data:</div>
            <div class="export-btns">
                <button id="chat-export-json-btn">Export JSON</button>
                <button id="chat-export-csv-btn">Export CSV</button>
                <button id="chat-export-txt-btn">Export TXT</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="chat-message-modal-bg"></div>
<div id="chat-message-modal">
    <div id="chat-message-modal-content">
    </div>
</div>

<script>
(function() {
    var chatBtn = document.getElementById('chat-btn');
    var chatModal = document.getElementById('chat-modal');
    var chatBg = document.getElementById('chat-modal-bg');
    var chatClose = document.getElementById('chat-modal-close');
    var chatInput = document.getElementById('chat-input');
    var chatSend = document.getElementById('chat-send-btn');
    var chatMessages = document.getElementById('chat-messages');
    var chatAdminBtn = document.getElementById('chat-admin-controls-btn');
    var chatAdminModal = document.getElementById('chat-admin-modal');
    var chatAdminBg = document.getElementById('chat-admin-modal-bg');
    var chatAdminClose = document.getElementById('chat-admin-modal-close');
    var chatAdminMin = document.getElementById('chat-admin-modal-min');
    var chatClear = document.getElementById('chat-clear-btn');
    var chatLock = document.getElementById('chat-lock-btn');
    var chatUnlock = document.getElementById('chat-unlock-btn');
    var chatMute = document.getElementById('chat-mute-btn');
    var chatMuteUser = document.getElementById('chat-mute-user');
    var chatUnmute = document.getElementById('chat-unmute-btn');
    var chatUnmuteUser = document.getElementById('chat-unmute-user');
    var chatSystemMsg = document.getElementById('chat-system-msg');
    var chatSystemMsgBtn = document.getElementById('chat-system-msg-btn');
    var chatExportJsonBtn = document.getElementById('chat-export-json-btn');
    var chatExportCsvBtn = document.getElementById('chat-export-csv-btn');
    var chatExportTxtBtn = document.getElementById('chat-export-txt-btn');
    var username = <?php echo json_encode($user['username']); ?>;
    var userRank = <?php echo json_encode($user['rank']); ?>;
    var userColor = <?php echo json_encode($user['color']); ?>;
    var userHasColor = <?php echo json_encode($user['has_color']); ?>;
    var locked = false;
    var muted = false;
    var csrfToken = <?php echo json_encode($csrf_token); ?>;

    var mentionAutofill = document.getElementById('mention-autofill');
    var mentionUsers = [];
    var mentionFiltered = [];
    var mentionIndex = -1;
    var mentionActive = false;
    var mentionStart = -1;
    var mentionQuery = '';

    function fetchMentionUsers() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'chat_button_modal.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            try { mentionUsers = JSON.parse(xhr.responseText); } catch(e){ mentionUsers = []; }
        };
        xhr.send('chat_action=users&csrf_token=' + encodeURIComponent(csrfToken));
    }
    fetchMentionUsers();

    function getCaretPos(input) {
        return input.selectionStart;
    }
    function setCaretPos(input, pos) {
        input.setSelectionRange(pos, pos);
    }

    function showMentionAutofill(list, startPos, query) {
        if (!list.length) { mentionAutofill.style.display = 'none'; mentionActive = false; return; }
        mentionAutofill.innerHTML = '';
        list.forEach(function(u, i) {
            var div = document.createElement('div');
            div.className = 'mention-item' + (i === mentionIndex ? ' selected' : '');
            var color = (u.has_color && u.color) ? u.color : '';
            var rankColor = '';
            switch (u.rank) {
                case 'Admin': rankColor='#ff0000'; break;
                case 'Manager': rankColor='#453AEE'; break;
                case 'Mod': rankColor='#39ff14'; break;
                case 'Council': rankColor='#87cefa'; break;
                case 'Founder': rankColor='#FFAB02'; break;
                case 'Clique': rankColor='#09569E'; break;
                case 'Rich': rankColor='#FFD700'; break;
                case 'Criminal': rankColor='#780A48'; break;
                case 'VIP': rankColor='#9B318E'; break;
            }
            var unameColor = color || rankColor || '#fff';
            div.innerHTML =
                '<span class="mention-color" style="background:' + unameColor + ';"></span>' +
                '<span style="color:' + unameColor + ';font-weight:bold;">' + escapeHtml(u.username) + '</span>' +
                (u.rank ? '<span class="mention-rank" style="color:' + rankColor + ';">[' + u.rank + ']</span>' : '');
            div.onclick = function() {
                insertMention(u.username, startPos, query);
                hideMentionAutofill();
            };
            mentionAutofill.appendChild(div);
        });
        var rect = chatInput.getBoundingClientRect();
        mentionAutofill.style.left = rect.left + window.scrollX + 'px';
        mentionAutofill.style.top = (rect.bottom + window.scrollY) + 'px';
        mentionAutofill.style.display = 'block';
        mentionActive = true;
    }
    function hideMentionAutofill() {
        mentionAutofill.style.display = 'none';
        mentionActive = false;
        mentionIndex = -1;
    }
    function insertMention(username, startPos, query) {
        var val = chatInput.value;
        var caret = getCaretPos(chatInput);
        var before = val.slice(0, startPos);
        var after = val.slice(startPos + 1 + (query ? query.length : 0), val.length);
        var mentionText = '@' + username;
        if (after.length === 0 || !/^[\s]/.test(after)) mentionText += ' ';
        chatInput.value = before + mentionText + after;
        var newCaret = (before + mentionText).length;
        setCaretPos(chatInput, newCaret);
        chatInput.focus();
    }

    chatInput.addEventListener('input', function(e) {
        var val = chatInput.value;
        var caret = getCaretPos(chatInput);
        var at = val.lastIndexOf('@', caret - 1);
        if (at >= 0 && (at === 0 || /[\s]/.test(val[at - 1]))) {
            var query = '';
            for (var i = at + 1; i < caret; ++i) {
                var c = val[i];
                if (!c || !/[a-zA-Z0-9_]/.test(c)) break;
                query += c;
            }
            mentionStart = at;
            mentionQuery = query;
            mentionFiltered = mentionUsers.filter(function(u) {
                return u.username.toLowerCase().startsWith(query.toLowerCase());
            }).slice(0, 10);
            mentionIndex = 0;
            showMentionAutofill(mentionFiltered, at, query);
        } else {
            hideMentionAutofill();
        }
    });
    chatInput.addEventListener('keydown', function(e) {
        if (!mentionActive) return;
        if (e.key === 'ArrowDown') {
            mentionIndex = (mentionIndex + 1) % mentionFiltered.length;
            showMentionAutofill(mentionFiltered, mentionStart, mentionQuery);
            e.preventDefault();
        } else if (e.key === 'ArrowUp') {
            mentionIndex = (mentionIndex - 1 + mentionFiltered.length) % mentionFiltered.length;
            showMentionAutofill(mentionFiltered, mentionStart, mentionQuery);
            e.preventDefault();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (mentionFiltered[mentionIndex]) {
                insertMention(mentionFiltered[mentionIndex].username, mentionStart, mentionQuery);
                hideMentionAutofill();
                e.preventDefault();
            }
        } else if (e.key === 'Escape') {
            hideMentionAutofill();
        }
    });
    document.addEventListener('click', function(e) {
        if (!mentionAutofill.contains(e.target)) hideMentionAutofill();
    });

    var autoScroll = true;
    chatMessages.addEventListener('scroll', function() {
        autoScroll = (chatMessages.scrollTop + chatMessages.clientHeight >= chatMessages.scrollHeight - 10);
    });

    function fetchChat() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'chat_button_modal.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                var data = {};
                try { data = JSON.parse(xhr.responseText); } catch(e){}
                var prevScroll = chatMessages.scrollTop;
                var prevHeight = chatMessages.scrollHeight;
                var atBottom = (chatMessages.scrollTop + chatMessages.clientHeight >= chatMessages.scrollHeight - 10);
                chatMessages.innerHTML = '';
                (data.messages || []).forEach(function(msg) {
                    chatMessages.appendChild(renderMsg(msg));
                });
                if (atBottom || autoScroll) {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                } else {
                    chatMessages.scrollTop = prevScroll + (chatMessages.scrollHeight - prevHeight);
                }
                locked = !!data.locked;
                muted = !!data.muted;
                var notLoggedIn = (username === 'anonymous');
                chatInput.disabled = locked || muted || notLoggedIn;
                chatSend.disabled = locked || muted || notLoggedIn;
                if (notLoggedIn) chatInput.placeholder = "Log in to chat";
                else if (locked) chatInput.placeholder = "Chat is locked";
                else if (muted) chatInput.placeholder = "You are muted";
                else chatInput.placeholder = "Message MewBin";
            }
        };
        xhr.send('chat_action=fetch&csrf_token=' + encodeURIComponent(csrfToken));
    }

    function highlightMentions(msg, text) {
        if (!msg.mentions || !msg.mentions.length) return escapeHtml(text);
        var result = '';
        var lastIdx = 0;
        var regex = /@([a-zA-Z0-9_]+)/g;
        var m;
        while ((m = regex.exec(text)) !== null) {
            var uname = m[1];
            var start = m.index;
            var end = regex.lastIndex;
            result += escapeHtml(text.slice(lastIdx, start));
            var user = mentionUsers.find(function(u) { return u.username === uname; });
            if (!user) {
                result += escapeHtml(text.slice(start, end));
                lastIdx = end;
                continue;
            }
            var color = user.has_color && user.color ? user.color : '';
            var rankColor = '';
            var rankTag = '';
            switch (user.rank) {
                case 'Admin': rankColor='#ff0000'; rankTag='[Admin]'; break;
                case 'Manager': rankColor='#453AEE'; rankTag='[Manager]'; break;
                case 'Mod': rankColor='#39ff14'; rankTag='[Mod]'; break;
                case 'Council': rankColor='#87cefa'; rankTag='[Council]'; break;
                case 'Founder': rankColor='#FFAB02'; rankTag='[Founder]'; break;
                case 'Clique': rankColor='#09569E'; rankTag='[Clique]'; break;
                case 'Rich': rankColor='#FFD700'; rankTag='[Rich]'; break;
                case 'Criminal': rankColor='#780A48'; rankTag='[Criminal]'; break;
                case 'VIP': rankColor='#9B318E'; rankTag='[VIP]'; break;
            }
            var unameColor = color || rankColor || '#ffd700';
            result += '<span class="mention-highlight" style="color:' + unameColor + ';">@' + escapeHtml(uname) +
                (rankTag ? ' <span class="mention-rank" style="color:' + rankColor + ';">' + rankTag + '</span>' : '') +
                '</span>';
            lastIdx = end;
        }
        result += escapeHtml(text.slice(lastIdx));
        return result;
    }

    var msgModalBg = document.getElementById('chat-message-modal-bg');
    var msgModal = document.getElementById('chat-message-modal');
    var msgModalContent = document.getElementById('chat-message-modal-content');

    function getRankInfo(rank) {
        var rankColor = '', rankTag = '';
        switch (rank) {
            case 'Admin': rankColor='#ff0000'; rankTag='[Admin]'; break;
            case 'Manager': rankColor='#453AEE'; rankTag='[Manager]'; break;
            case 'Mod': rankColor='#39ff14'; rankTag='[Mod]'; break;
            case 'Council': rankColor='#87cefa'; rankTag='[Council]'; break;
            case 'Founder': rankColor='#FFAB02'; rankTag='[Founder]'; break;
            case 'Clique': rankColor='#09569E'; rankTag='[Clique]'; break;
            case 'Rich': rankColor='#FFD700'; rankTag='[Rich]'; break;
            case 'Criminal': rankColor='#780A48'; rankTag='[Criminal]'; break;
            case 'VIP': rankColor='#9B318E'; rankTag='[VIP]'; break;
            case 'System': rankColor='#e05f5f'; rankTag=''; break;
        }
        return {color: rankColor, tag: rankTag};
    }

    function showMsgModal(msg) {
        msgModalContent.innerHTML = '';
        msgModal.style.borderRadius = '0';
        var header = document.createElement('div');
        header.className = 'msg-modal-header';
        var time = document.createElement('span');
        time.className = 'msg-modal-time';
        time.textContent = (msg.time ? msg.time + ' UTC' : '');
        var msgId = msg.id;
        if (msg.type === 'system') {
            var sys = document.createElement('span');
            sys.className = 'msg-modal-username';
            sys.style.color = '#e05f5f';
            sys.textContent = 'MewBin [System]';
            header.appendChild(time);
            header.appendChild(sys);
        } else {
            var uname = document.createElement('span');
            uname.className = 'msg-modal-username';
            var rankInfo = getRankInfo(msg.rank);
            var unameColor = (msg.has_color && msg.color) ? msg.color : (rankInfo.color || '#fff');
            uname.style.color = unameColor;
            uname.textContent = msg.username;
            header.appendChild(time);
            header.appendChild(uname);
            if (rankInfo.tag) {
                var rank = document.createElement('span');
                rank.className = 'msg-modal-rank';
                rank.style.color = rankInfo.color;
                rank.textContent = rankInfo.tag;
                header.appendChild(rank);
            }
        }
        msgModalContent.appendChild(header);

        var body = document.createElement('div');
        body.className = 'msg-modal-body';
        var msgLines = [];
        function splitWithBr(str, isSystem, msgObj) {
            var out = [];
            var i = 0;
            while (i < str.length) {
                var chunk = str.slice(i, i + 50);
                if (isSystem) {
                    out.push(escapeHtml(chunk));
                } else {
                    out.push(highlightMentions(msgObj, chunk));
                }
                i += 50;
                if (i < str.length) out.push('<br>');
            }
            return out.join('');
        }
        if (msg.type === 'system') {
            body.innerHTML = splitWithBr(msg.msg, true, msg);
        } else {
            body.innerHTML = splitWithBr(msg.msg, false, msg);
        }
        msgModalContent.appendChild(body);

        var btns = document.createElement('div');
        btns.className = 'msg-modal-btns';
        btns.style.justifyContent = 'center';
        btns.style.marginTop = '16px';

        if (msg.type !== 'system') {
            var viewProfileBtn = document.createElement('button');
            viewProfileBtn.textContent = 'View Profile';
            viewProfileBtn.style.background = '#222';
            viewProfileBtn.style.color = '#fff';
            viewProfileBtn.style.border = 'none';
            viewProfileBtn.style.fontSize = '15px';
            viewProfileBtn.style.fontWeight = '500';
            viewProfileBtn.style.cursor = 'pointer';
            viewProfileBtn.style.borderRadius = '0';
            viewProfileBtn.style.padding = '7px 0';
            viewProfileBtn.style.width = '100%';
            viewProfileBtn.onclick = function() {
                window.location.href = '/user/' + encodeURIComponent(msg.username);
            };
            btns.appendChild(viewProfileBtn);
        }

        var copyIdBtn = document.createElement('button');
        copyIdBtn.textContent = 'Copy Message ID';
        copyIdBtn.style.background = '#222';
        copyIdBtn.style.color = '#fff';
        copyIdBtn.style.border = 'none';
        copyIdBtn.style.fontSize = '15px';
        copyIdBtn.style.fontWeight = '500';
        copyIdBtn.style.cursor = 'pointer';
        copyIdBtn.style.borderRadius = '0';
        copyIdBtn.style.padding = '7px 0';
        copyIdBtn.style.width = '100%';
        copyIdBtn.onclick = function() {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(msgId + '');
            } else {
                var ta = document.createElement('textarea');
                ta.value = msgId + '';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
        };
        btns.appendChild(copyIdBtn);

        btns.style.flexDirection = 'column';
        btns.style.gap = '8px';

        msgModalContent.appendChild(btns);

        msgModalBg.style.display = 'block';
        msgModal.style.display = 'block';
    }
    function closeMsgModal() {
        msgModalBg.style.display = 'none';
        msgModal.style.display = 'none';
    }
    if (msgModalBg) msgModalBg.onclick = closeMsgModal;

    function renderMsg(msg) {
        var div = document.createElement('div');
        var color = msg.color || '';
        var rank = msg.rank || '';
        var hasColor = msg.has_color;
        var uname = msg.username;
        var rankTag = '';
        var rankColor = '';
        var backgroundGif = '';
        switch (rank) {
            case 'Admin': rankTag='[Admin]'; rankColor='#ff0000'; backgroundGif='url(img/red.gif)'; break;
            case 'Manager': rankTag='[Manager]'; rankColor='#453AEE'; backgroundGif='url(img/purple.gif)'; break;
            case 'Mod': rankTag='[Mod]'; rankColor='#39ff14'; break;
            case 'Council': rankTag='[Council]'; rankColor='#87cefa'; break;
            case 'Founder': rankTag='[Founder]'; rankColor='#FFAB02'; break;
            case 'Clique': rankTag='[Clique]'; rankColor='#09569E'; break;
            case 'Rich': rankTag='[Rich]'; rankColor='#FFD700'; backgroundGif='url(img/gold.gif)'; break;
            case 'Criminal': rankTag='[Criminal]'; rankColor='#780A48'; break;
            case 'VIP': rankTag='[VIP]'; rankColor='#9B318E'; break;
            case 'System': rankTag=''; rankColor='#e05f5f'; break;
        }
        var unameColor = (uname.toLowerCase() !== 'anonymous' && hasColor && color) ? color : rankColor;
        var unameStyle = 'color:' + (unameColor || '#fff') + ';font-weight:bold;';
        if (backgroundGif) unameStyle += 'background-image:' + backgroundGif + ';border-radius:3px;padding:1px 3px;';
        var time = '<b class="chat-time">' + (msg.time ? msg.time + ' UTC' : '') + '</b> ';
        var msgLines = [];
        function splitWithBrRender(str, isSystem, msgObj) {
            var out = [];
            var i = 0;
            while (i < str.length) {
                var chunk = str.slice(i, i + 50);
                if (isSystem) {
                    out.push(escapeHtml(chunk));
                } else {
                    out.push(highlightMentions(msgObj, chunk));
                }
                i += 50;
                if (i < str.length) out.push('<br>');
            }
            return out.join('');
        }
        if (msg.type === 'system') {
            div.innerHTML = time + '<span style="color:#e05f5f;">MewBin [System]:</span> ' + splitWithBrRender(msg.msg, true, msg);
        } else {
            div.innerHTML = time +
                '<span style="' + unameStyle + '">' + escapeHtml(uname) + '</span>' +
                (rankTag ? ' <span style="color:' + rankColor + ';">' + rankTag + '</span>' : '') +
                ': ' + splitWithBrRender(msg.msg, false, msg);
        }
        div.style.marginBottom = '2px';
        div.dataset.msg = JSON.stringify(msg);
        div.style.cursor = 'pointer';
        div.onclick = function(e) {
            if (e.target.tagName === 'A') return;
            showMsgModal(msg);
        };
        return div;
    }

    function escapeHtml(str) {
        return (str||'').replace(/[&<>"']/g, function(m) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        });
    }

    function sendChat(msg) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'chat_button_modal.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = fetchChat;
        xhr.send('chat_action=send&msg=' + encodeURIComponent(msg) + '&csrf_token=' + encodeURIComponent(csrfToken));
    }

    if (chatSend) chatSend.onclick = function() {
        var val = chatInput.value.trim();
        if (!val || locked || muted || username === 'anonymous' || /^@\w+$/.test(val)) return;
        if (val.length > 150) {
            val = val.slice(0, 150) + "\n" + val.slice(150, 300);
        }
        sendChat(val);
        chatInput.value = '';
    };
    if (chatInput) chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !(locked || muted || username === 'anonymous')) chatSend.click();
    });

    if (chatClear) chatClear.onclick = function() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'chat_button_modal.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = fetchChat;
        xhr.send('chat_action=clear&csrf_token=' + encodeURIComponent(csrfToken));
    };
    if (chatLock) chatLock.onclick = function() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'chat_button_modal.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = fetchChat;
        xhr.send('chat_action=lock&csrf_token=' + encodeURIComponent(csrfToken));
    };
    if (chatUnlock) chatUnlock.onclick = function() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'chat_button_modal.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = fetchChat;
        xhr.send('chat_action=unlock&csrf_token=' + encodeURIComponent(csrfToken));
    };
    if (chatMute && chatMuteUser) chatMute.onclick = function() {
        var target = chatMuteUser.value.trim();
        if (!target || target === username) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'chat_button_modal.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = fetchChat;
        xhr.send('chat_action=mute&target=' + encodeURIComponent(target) + '&csrf_token=' + encodeURIComponent(csrfToken));
        chatMuteUser.value = '';
    };
    if (chatUnmute && chatUnmuteUser) chatUnmute.onclick = function() {
        var target = chatUnmuteUser.value.trim();
        if (!target) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'chat_button_modal.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = fetchChat;
        xhr.send('chat_action=unmute&target=' + encodeURIComponent(target) + '&csrf_token=' + encodeURIComponent(csrfToken));
        chatUnmuteUser.value = '';
    };

    if (chatAdminBtn) chatAdminBtn.onclick = function() {
        chatAdminModal.style.display = 'flex';
        chatAdminBg.style.display = 'block';
        chatAdminModal.style.opacity = '1';
        chatAdminBg.style.opacity = '1';
        saveAdminModalState(true, chatAdminModal.classList.contains('minimized'));
    };
    if (chatAdminClose) chatAdminClose.onclick = function() {
        chatAdminModal.style.display = 'none';
        chatAdminBg.style.display = 'none';
        saveAdminModalState(false, false);
    };
    if (chatAdminBg) chatAdminBg.onclick = function() {
        chatAdminModal.style.display = 'none';
        chatAdminBg.style.display = 'none';
        saveAdminModalState(false, false);
    };
    if (chatAdminMin) chatAdminMin.onclick = function() {
        var body = chatAdminModal.querySelector('#chat-admin-modal-body');
        if (chatAdminModal.classList.contains('minimized')) {
            chatAdminModal.classList.remove('minimized');
            body.style.display = 'flex';
            saveAdminModalState(true, false);
        } else {
            chatAdminModal.classList.add('minimized');
            body.style.display = 'none';
            saveAdminModalState(true, true);
        }
    };

    (function() {
        var drag = false, offsetX = 0, offsetY = 0;
        var header = document.getElementById('chat-admin-modal-header');
        if (!header) return;
        header.addEventListener('mousedown', function(e) {
            if (e.target === chatAdminClose || e.target === chatAdminMin) return;
            drag = true;
            var rect = chatAdminModal.getBoundingClientRect();
            offsetX = e.clientX - rect.left;
            offsetY = e.clientY - rect.top;
            document.body.style.userSelect = 'none';
        });
        document.addEventListener('mousemove', function(e) {
            if (!drag) return;
            var left = e.clientX - offsetX;
            var top = e.clientY - offsetY;
            chatAdminModal.style.left = left + 'px';
            chatAdminModal.style.top = top + 'px';
            saveAdminModalState(
                chatAdminModal.style.display === 'flex',
                chatAdminModal.classList.contains('minimized'),
                {left:left, top:top}
            );
        });
        document.addEventListener('mouseup', function() {
            drag = false;
            document.body.style.userSelect = '';
        });
    })();

    function saveChatModalState(open) {
        localStorage.setItem('chat_modal_open', open ? '1' : '0');
    }
    function restoreChatModalState() {
        if (localStorage.getItem('chat_modal_open') === '1') openChat();
    }
    function saveAdminModalState(open, minimized, pos) {
        localStorage.setItem('chat_admin_modal_open', open ? '1' : '0');
        localStorage.setItem('chat_admin_modal_min', minimized ? '1' : '0');
        if (pos) localStorage.setItem('chat_admin_modal_pos', JSON.stringify(pos));
    }
    function restoreAdminModalState() {
        if (!chatAdminModal) return;
        var open = localStorage.getItem('chat_admin_modal_open') === '1';
        var min = localStorage.getItem('chat_admin_modal_min') === '1';
        var pos = localStorage.getItem('chat_admin_modal_pos');
        if (open) {
            chatAdminModal.style.display = 'flex';
            chatAdminBg.style.display = 'block';
            chatAdminModal.style.opacity = '1';
            chatAdminBg.style.opacity = '1';
        }
        if (min) {
            chatAdminModal.classList.add('minimized');
            chatAdminModal.querySelector('#chat-admin-modal-body').style.display = 'none';
        }
        if (pos) {
            try {
                var p = JSON.parse(pos);
                if (typeof p.left === 'number' && typeof p.top === 'number') {
                    chatAdminModal.style.left = p.left + 'px';
                    chatAdminModal.style.top = p.top + 'px';
                }
            } catch(e){}
        }
    }

    function openChat() {
        chatModal.style.display = 'flex';
        chatModal.style.flexDirection = 'column';
        chatBg.style.display = 'block';
        setTimeout(function() {
            chatModal.style.opacity = '1';
            chatBg.style.opacity = '1';
        }, 10);
        if (chatInput) chatInput.focus();
        fetchChat();
        saveChatModalState(true);
    }
    function closeChat() {
        chatModal.style.display = 'none';
        chatBg.style.display = 'none';
        chatModal.style.opacity = '';
        chatBg.style.opacity = '';
        saveChatModalState(false);
    }
    if (chatBtn) chatBtn.onclick = openChat;
    if (chatClose) chatClose.onclick = closeChat;
    if (chatBg) chatBg.onclick = closeChat;

    if (chatSystemMsgBtn && chatSystemMsg) chatSystemMsgBtn.onclick = function() {
        var msg = chatSystemMsg.value.trim();
        if (!msg) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'chat_button_modal.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = fetchChat;
        xhr.send('chat_action=system_message&msg=' + encodeURIComponent(msg) + '&csrf_token=' + encodeURIComponent(csrfToken));
        chatSystemMsg.value = '';
    };

    function exportChat(format) {
        var url = 'chat_button_modal.php';
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.target = '_blank';
        form.style.display = 'none';
        var f1 = document.createElement('input');
        f1.name = 'chat_action'; f1.value = 'export'; form.appendChild(f1);
        var f2 = document.createElement('input');
        f2.name = 'format'; f2.value = format; form.appendChild(f2);
        var f3 = document.createElement('input');
        f3.name = 'csrf_token'; f3.value = csrfToken; form.appendChild(f3);
        document.body.appendChild(form);
        form.submit();
        setTimeout(function(){document.body.removeChild(form);}, 1000);
    }
    if (chatExportJsonBtn) chatExportJsonBtn.onclick = function() { exportChat('json'); };
    if (chatExportCsvBtn) chatExportCsvBtn.onclick = function() { exportChat('csv'); };
    if (chatExportTxtBtn) chatExportTxtBtn.onclick = function() { exportChat('txt'); };

    restoreChatModalState();
    restoreAdminModalState();

    setInterval(fetchChat, 3000);
    fetchChat();
})();
</script>
