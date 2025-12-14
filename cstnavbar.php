<?php
require_once 'waf.php';

if (isset($_GET['logout'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_destroy();
    @setcookie('login_token', '', time() - 3600, "/", "", true, true);
    @header("Location: index.php");
    exit();
}

include('database.php');

$username = $_SESSION['username'] ?? null;
$userRank = $_SESSION['user_rank'] ?? null;
$userColor = $_SESSION['user_color'] ?? null;
$hasColor = $_SESSION['has_color'] ?? null;

if (!$userRank && $username) {
    $stmt = $conn->prepare("SELECT rank, color, has_color FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $userRank = $user['rank'] ?? '';
    $userColor = $user['color'] ?? '';
    $hasColor = $user['has_color'] ?? 0;
    $stmt->close();
}
$rankClass = '';
$rankTag = '';
$rankColor = '';
$backgroundGif = '';

switch ($userRank) {
    case 'Admin':
        $rankClass = 'rank-admin';
        $rankTag = '[Admin]';
        $rankColor = '#ff0000';
        $backgroundGif = 'url(img/red.gif)';
        break;
    case 'Manager':
        $rankClass = 'rank-manager';
        $rankTag = '[Manager]';
        $rankColor = '#453AEE';
        $backgroundGif = 'url(img/purple.gif)';
        break;
    case 'Mod':
        $rankClass = 'rank-mod';
        $rankTag = '[Mod]';
        $rankColor = '#39ff14';
        break;
    case 'Council':
        $rankClass = 'rank-council';
        $rankTag = '[Council]';
        $rankColor = '#87cefa';
        break;
    case 'Founder':
        $rankClass = 'rank-founder';
        $rankTag = '[Founder]';
        $rankColor = '#FFAB02';
        break;
    case 'Clique':
        $rankClass = 'rank-clique';
        $rankTag = '[Clique]';
        $rankColor = '#09569E';
        break;
        
        case 'Shield':
        $rankClass = 'rank-shield';
        $rankTag = '[Clique]';
        $rankColor = '#09569E';
        $backgroundGif = 'url(img/haha.gif)';
        break;
        
            
    case 'Rich':
        $rankClass = 'rank-rich';
        $rankTag = '[Rich]';
        $rankColor = '#FFD700';
        $backgroundGif = 'url(img/gold.gif)';
        break;
    case 'Criminal':
        $rankClass = 'rank-criminal';
        $rankTag = '[Criminal]';
        $rankColor = '#780A48';
        break;
    case 'VIP':
        $rankClass = 'rank-vip';
        $rankTag = '[VIP]';
        $rankColor = '#9B318E';
        break;
    default:
        $rankClass = '';
        $rankTag = '';
        $rankColor = '';
        $backgroundGif = '';
        break;
}
$displayColor = ($hasColor && $userColor) ? $userColor : $rankColor;

$unreadMsgCount = 0;
if ($username) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM messages WHERE receiver = ? AND is_read = 0");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($unreadMsgCount);
    $stmt->fetch();
    $stmt->close();
    if ($unreadMsgCount > 9) $unreadMsgCount = '9+';
}

echo <<<HTML
<div id="main-notification" style="display:none;position:fixed;top:32px;right:32px;z-index:9999;min-width:220px;padding:16px 24px;border-radius:8px;font-weight:bold;font-size:1.05em;box-shadow:0 2px 16px #000a;opacity:0.98;background:#222;color:#fff;"></div>
<script>
function showNotification(message, type = 'info') {
    var notif = document.getElementById('main-notification');
    if (!notif) return;
    notif.style.background = type === 'success' ? '#2ecc40' : (type === 'danger' ? '#e05f5f' : '#222');
    notif.style.color = '#fff';
    notif.textContent = message;
    notif.style.display = 'block';
    notif.style.opacity = '0.98';
    setTimeout(function() {
        notif.style.opacity = '0';
        setTimeout(function() { notif.style.display = 'none'; }, 400);
    }, 2500);
}
</script>
HTML;

if (isset($_SESSION['notification_message'])) {
    $notifMsg = $_SESSION['notification_message'];
    echo "<script>window.addEventListener('DOMContentLoaded',function(){showNotification(" . json_encode($notifMsg) . "," . json_encode($notifType) . ");});</script>";
    unset($_SESSION['notification_message'], $_SESSION['notification_type']);
}
?>
<nav class="custom-navbar">
    <ul class="nav-left">
        <li>
            <a href="/home" class="nav-link<?php if(basename($_SERVER['PHP_SELF']) == 'index.php') echo ' active'; ?>">
                Home
            </a>
        </li>
        <li class="dropdown">
            <a href="#" class="nav-link<?php if(in_array(basename($_SERVER['PHP_SELF']), ['post.php', 'template_loader.php'])) echo ' active'; ?>"
               onclick="event.preventDefault(); document.getElementById('paste-dropdown-menu').classList.toggle('show');">
                Add Paste
            </a>
            <ul class="dropdown-menu" id="paste-dropdown-menu">
                <li><a class="dropdown-item<?php if(basename($_SERVER['PHP_SELF']) == 'post.php') echo ' active'; ?>" href="post.php">Add Paste</a></li>
                <li><a class="dropdown-item<?php if(basename($_SERVER['PHP_SELF']) == 'template_loader.php') echo ' active'; ?>" href="template_loader.php">Template Loader</a></li>
            </ul>
        </li>
        <li>
            <a href="/users" class="nav-link<?php if(basename($_SERVER['PHP_SELF']) == 'users.php') echo ' active'; ?>">
                Users
            </a>
        </li>
        <li>
            <a href="/HOA" class="nav-link<?php if(basename($_SERVER['PHP_SELF']) == 'HOA.php') echo ' active'; ?>">
                Hall of Autism
            </a>
        </li>
    </ul>

    <div class="logo-container">
        <div class="logo">
            <a href="#" id="mewBinLogo">
                <img src="/mbin.png" alt="MewBin" />
            </a>
        </div>
        <div id="animatedMessage">Proudly Revamped by Spades!</div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', (event) => {
            const logo = document.getElementById('mewBinLogo');
            const message = document.getElementById('animatedMessage');

            logo.addEventListener('click', (e) => {
                e.preventDefault(); 
                message.classList.remove('show-message');
                
                void message.offsetWidth;
                setTimeout(() => {
                    message.classList.add('show-message');
                }, 10);
            });

            document.addEventListener('click', function(e) {
                if (message.classList.contains('show-message') && 
                    e.target !== logo && !logo.contains(e.target) &&
                    e.target !== message && !message.contains(e.target)) {
                    message.classList.remove('show-message');
                }
            });
        });
    </script>

    <ul class="nav-right">
        <li class="dropdown">
            <a href="#" class="nav-link<?php if(in_array(basename($_SERVER['PHP_SELF']), ['upgrade.php', 'redeem.php'])) echo ' active'; ?>"
               onclick="event.preventDefault(); document.getElementById('upg-dropdown-menu').classList.toggle('show');">
                Upgrade
            </a>
            <ul class="dropdown-menu" id="upg-dropdown-menu">
                <li><a class="dropdown-item<?php if(basename($_SERVER['PHP_SELF']) == 'upgrades.php') echo ' active'; ?>" href="upgrades.php">Upgrades</a></li>
                <li><a class="dropdown-item<?php if(basename($_SERVER['PHP_SELF']) == 'redeem.php') echo ' active'; ?>" href="redeem.php">Redeem</a></li>
            </ul>
        </li>
        <li>
            <a href="/faq" class="nav-link<?php if(basename($_SERVER['PHP_SELF']) == 'faq.php') echo ' active'; ?>">
                FAQ+TOS
            </a>
        </li>
        
        <?php if (in_array($userRank, ['Admin', 'Manager', 'Mod', 'Founder', 'Council'])): ?>
        <li>
            <a href="/adminpanel/" class="nav-link<?php if(basename($_SERVER['PHP_SELF']) == 'admin.php') echo ' active'; ?>">
                Admin
            </a>
        </li>
        <?php endif; ?>

        <?php if ($username): ?>
            <?php include('notification_script.php'); ?>
            <li class="notification-bell">
                <a href="/messages" class="nav-link" aria-label="Messages">
                    <i class="fas fa-envelope"></i>
                    <?php if ($unreadMsgCount && $unreadMsgCount !== '0'): ?>
                        <span class="notification-badge">
                            <?= htmlspecialchars($unreadMsgCount, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="dropdown user-dropdown">
                <a href="#" class="nav-link user-link"
                   onclick="event.preventDefault(); document.getElementById('user-dropdown-menu').classList.toggle('show');">
                    <span class="username" style="color:<?= htmlspecialchars($displayColor, ENT_QUOTES, 'UTF-8') ?>;">
                        <?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php if ($rankTag): ?>
                        <span class="rank-tag" style="color:<?= htmlspecialchars($rankColor, ENT_QUOTES, 'UTF-8') ?>;">
                            <?= htmlspecialchars($rankTag, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu" id="user-dropdown-menu">
                    <li><a class="dropdown-item<?php if(basename($_SERVER['PHP_SELF']) == 'profile.php') echo ' active'; ?>" href="/user/<?= urlencode($username) ?>">Profile</a></li>
                    <li><a class="dropdown-item<?php if(basename($_SERVER['PHP_SELF']) == 'settings.php') echo ' active'; ?>" href="/settings.php">Settings</a></li>
                    <li><a class="dropdown-item" href="/logout.php">Sign Out</a></li>
                </ul>
            </li>
        <?php else: ?>
            <li>
                <a href="/login" class="btn btn-sm login-btn">Sign In</a>
            </li>
            <li>
                <a href="/register" class="btn btn-sm register-btn">Register</a>
            </li>
        <?php endif; ?>
    </ul>

    <button class="nav-toggle" id="navToggle">
        <i class="fas fa-bars"></i>
    </button>
</nav>

<style>
.custom-navbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: #000;
    padding: 0.5rem 1rem;
    position: relative;
    border-bottom: 1.5px solid #222;
    min-height: 56px;
}

.custom-navbar .nav-left,
.custom-navbar .nav-right {
    display: flex;
    list-style: none;
    padding: 0;
    margin: 0;
    gap: 1rem;
    align-items: center;
}

.custom-navbar .nav-link {
    color: #ccc;
    text-decoration: none;
    font-size: 0.95rem;
    transition: color 0.3s;
    padding: 0.5rem 0.75rem;
    border-radius: 4px;
    font-weight: 500;
}

.custom-navbar .nav-link:hover,
.custom-navbar .nav-link.active {
    color: #8a2be2;
    background: rgba(138, 43, 226, 0.1);
}

.logo-container {
    position: absolute;
    left: 48%;
    transform: translateX(-50%);
    display: flex;
    flex-direction: column;
    align-items: center;
}

.custom-navbar .logo img {
    height: 75px;
    filter: drop-shadow(0 0 8px #8a2be2);
    cursor: pointer;
}

.custom-navbar .logo a {
    text-decoration: none;
    border: none;
    display: block;
}

.custom-navbar .btn {
    color: #fff;
    background: transparent;
    border: 1px solid #8a2be2;
    padding: 0.4rem 0.75rem;
    text-decoration: none;
    border-radius: 4px;
    font-size: 0.9rem;
    transition: background 0.3s, color 0.3s;
    font-weight: 500;
}

.custom-navbar .btn:hover {
    background: #8a2be2;
    color: #000;
}

.custom-navbar .register-btn {
    border-color: #3498ff;
    color: #3498ff;
}

.custom-navbar .register-btn:hover {
    background: #3498ff;
    color: #000;
}

.custom-navbar .dropdown {
    position: relative;
}

.custom-navbar .dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 6px;
    padding: 8px 0;
    margin: 4px 0 0 0;
    list-style: none;
    min-width: 180px;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 10px rgba(0,0,0,0.3);
}

.custom-navbar .dropdown-menu.show {
    display: block;
}

.custom-navbar .dropdown-item {
    color: #eaeaea;
    text-decoration: none;
    padding: 8px 16px;
    display: block;
    font-size: 14px;
    transition: background 0.2s ease;
}

.custom-navbar .dropdown-item:hover,
.custom-navbar .dropdown-item.active {
    background: rgba(138, 43, 226, 0.15);
    color: #8a2be2;
}

.custom-navbar .user-link {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0.5rem 0.75rem;
    height: 100%;
    text-decoration: none;
    color: inherit;
    border-radius: 4px;
    transition: color 0.3s, background 0.3s;
}

.custom-navbar .username {
    font-weight: bold;
    font-family: monospace;
    text-shadow: 0 0 6px currentColor;
}

.custom-navbar .rank-tag {
    font-size: 0.85em;
    font-weight: bold;
}

.custom-navbar .notification-bell {
    position: relative;
}

.custom-navbar .notification-bell .nav-link {
    position: relative;
    padding: 0.5rem;
}

.custom-navbar .notification-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    background: #3498ff;
    color: #fff;
    border-radius: 50%;
    padding: 0 4px;
    font-size: 0.7em;
    font-weight: bold;
    min-width: 16px;
    height: 16px;
    line-height: 16px;
    text-align: center;
    border: 2px solid #000;
}

.nav-toggle {
    display: none;
    border: none;
    background: transparent;
    color: #8a2be2;
    font-size: 1.2rem;
    padding: 0.5rem;
    cursor: pointer;
}

@media (max-width: 768px) {
    .custom-navbar {
        flex-wrap: wrap;
        padding: 0.5rem;
    }
    
    .custom-navbar .nav-left,
    .custom-navbar .nav-right {
        display: none;
        width: 100%;
        flex-direction: column;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }
    
    .custom-navbar .nav-left {
        order: 1;
    }
    
    .logo-container {
        position: static;
        transform: none;
        order: 0;
        margin: 0 auto;
    }
    
    .custom-navbar .nav-right {
        order: 2;
    }
    
    .nav-toggle {
        display: block;
        order: 3;
    }
    
    .custom-navbar .dropdown-menu {
        position: static;
        width: 100%;
        margin-top: 4px;
        border: none;
        background: #151515;
    }
    
    
}

.custom-navbar .nav-left li,
.custom-navbar .nav-right li {
    display: flex;
    align-items: center;
}

#animatedMessage {
  display: none;
  opacity: 0;
  transform: translateY(-10px);
  font-family: Arial, sans-serif;
  font-weight: bold;
  font-size: 1.2em;
  color: #8a2be2; 
  text-shadow: 
    0 0 1px #8a2be2,
    0 0 2px #8a2be2; 
  
  background-color: #000000;
  padding: 10px 20px;
  border-radius: 8px;
  border: 1px solid #8a2be2;
  box-shadow: 
    0 0 8px rgba(138, 43, 226, 0.3),
    inset 0 0 5px rgba(138, 43, 226, 0.1);
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%) translateY(-10px);
  z-index: 1000;
  text-align: center;
  white-space: nowrap;
  margin-top: 10px;
}

.show-message {
  display: block !important;
  animation: slide-down 0.5s forwards;
}

@keyframes slide-down {
  0% {
    opacity: 0;
    transform: translateX(-50%) translateY(-10px);
  }
  100% {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
  }
}
</style>

<script>
document.getElementById('navToggle')?.addEventListener('click', function() {
    const navLeft = document.querySelector('.nav-left');
    const navRight = document.querySelector('.nav-right');
    const icon = this.querySelector('i');
    
    [navLeft, navRight].forEach(nav => {
        if (nav) {
            nav.style.display = nav.style.display === 'flex' ? 'none' : 'flex';
        }
    });
    
    if (icon) {
        icon.className = icon.className === 'fas fa-bars' ? 'fas fa-times' : 'fas fa-bars';
    }
});

document.addEventListener('click', function(event) {
    if (!event.target.matches('.nav-link, .user-link, .dropdown-item, .btn')) {
        const dropdowns = document.querySelectorAll('.dropdown-menu');
        dropdowns.forEach(dropdown => {
            dropdown.classList.remove('show');
        });
    }
});
</script>