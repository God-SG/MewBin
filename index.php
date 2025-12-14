<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
require_once 'waf.php';
$waf_output = ob_get_clean();

if (strpos($waf_output, 'ACCESS BLOCKED') !== false || strpos($waf_output, 'Blocked at:') !== false) {
    echo $waf_output;
    exit;
}

session_start();

include('database.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "<h2 style='color:red;text-align:center;margin-top:40px;'>Database connection failed. Please check your database configuration.</h2>";
    exit;
}

session_regenerate_id(true);

if (!isset($_SESSION['viewed_pastes']) || !is_array($_SESSION['viewed_pastes'])) {
    $_SESSION['viewed_pastes'] = [];
}

if (isset($_SESSION['login_token'])) {
    $login_token = preg_replace('/[^a-zA-Z0-9]/', '', $_SESSION['login_token']);
} elseif (isset($_COOKIE['login_token'])) {
    $_SESSION['login_token'] = preg_replace('/[^a-zA-Z0-9]/', '', $_COOKIE['login_token']);
    $login_token = $_SESSION['login_token'];
} else {
    $login_token = null;
}

if ($login_token) {
    $stmt = $conn->prepare("SELECT username, locked FROM users WHERE login_token = ?");
    $stmt->bind_param("s", $login_token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        if ($user['locked'] == 1) {
            $login_token = null;
            session_destroy();
            setcookie('login_token', '', time() - 3600, "/", "", true, true);
            header("Location: /login.php");
            exit;
        }
        
        $_SESSION['username'] = $user['username']; 
        $_SESSION['login_token'] = $login_token;
    } else {
        $login_token = null;
        session_destroy();
        setcookie('login_token', '', time() - 3600, "/", "", true, true);
    }
} else {
    $_SESSION = [];
}

$username = $_SESSION['username'] ?? null;

function timeAgo($time) {
    $timestamp = strtotime($time);
    return date("F j", $timestamp); 
}

$perPage = 100; 
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$page = ($page > 0) ? $page : 1; 
$offset = ($page - 1) * $perPage;

$result = null;
$totalPastes = 0;
$searchTerm = '';
$searchInTitle = true;
$searchInContent = false;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search'])) {
    $searchTerm = trim($_POST['search']);
    $searchOption = $_POST['searchOption'] ?? 'Search in title';
    $searchInTitle = ($searchOption === 'Search in title');
    $searchInContent = ($searchOption === 'Search in paste');

    $searchTermWithWildcards = '%' . $searchTerm . '%';
    $conditions = [];
    $params = [];

    if ($searchInTitle) {
        $conditions[] = "title LIKE ?";
        $params[] = $searchTermWithWildcards;
    } elseif ($searchInContent) {
        $conditions[] = "content LIKE ?";
        $params[] = $searchTermWithWildcards;
    }

    $where = $conditions ? implode(' OR ', $conditions) : '1';
    $query = "SELECT * FROM pastes WHERE pinned = 0 AND visibility = 0 AND ($where) ORDER BY date_created DESC LIMIT $perPage OFFSET $offset";
    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $totalQuery = "SELECT COUNT(*) AS total FROM pastes WHERE pinned = 0 AND visibility = 0 AND ($where)";
    $totalStmt = $conn->prepare($totalQuery);
    if ($params) {
        $totalStmt->bind_param(str_repeat("s", count($params)), ...$params);
    }
    $totalStmt->execute();
    $totalResult = $totalStmt->get_result()->fetch_assoc();
    $totalPastes = $totalResult['total'];
} else {
    $query = "SELECT * FROM pastes WHERE pinned = 0 AND visibility = 0 ORDER BY date_created DESC LIMIT $perPage OFFSET $offset";
    $result = $conn->query($query);

    $totalQuery = "SELECT COUNT(*) AS total FROM pastes WHERE pinned = 0 AND visibility = 0";
    $totalResult = $conn->query($totalQuery)->fetch_assoc();
    $totalPastes = $totalResult['total'];
}

$totalPages = ceil($totalPastes / $perPage);

$rankData = [];
$rankQuery = $conn->query("SELECT rank_name, rankTag, rankColor, tableColor, tableHoverColor FROM rank");
while ($row = $rankQuery->fetch_assoc()) {
    $rankData[$row['rank_name']] = [
        'tag' => $row['rankTag'],
        'color' => $row['rankColor'],
        'tableColor' => $row['tableColor'] ?? '',
        'tableHoverColor' => $row['tableHoverColor'] ?? ''
    ];
}

include ('chat_button_modal.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>MewBin</title>
    <link rel="canonical" href="https://MewBin.org" />
    <meta property="og:site_name" content="MewBin"/>
    <meta property="og:type" content="website"/>
    <meta name="robots" content="index, follow"/>
    <meta name="theme-color" content="#000000"/>
    <meta name="twitter:card" content="summary"/>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <meta name="description" content="MewBin is a document sharing and publishing website for text-based information such as dox, code-snippets and other stuff!">
    <meta name="twitter:title" content="MewBin">
    <meta name="twitter:description" content="MewBin is a document sharing and publishing website for text-based information such as dox, code-snippets and other stuff.">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.0.1/dist/darkly/bootstrap.min.css" referrerpolicy="no-referrer"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.12.0/css/all.min.css" referrerpolicy="no-referrer"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/typicons/2.0.9/typicons.min.css" referrerpolicy="no-referrer"/>
    <link rel="stylesheet" href="assets/css/styles.min.css">
    <script src="snow.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    
    <style>
    /* Reset any WAF transformations - ONLY FIX FOR WAF INTERFERING WITH INDEX*/
    html, body {
        zoom: 1 !important;
        transform: none !important;
        font-size: 16px !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        height: 100% !important;
    }
    
    body * {
        zoom: 1 !important;
        transform: none !important;
        font-size: inherit !important;
        box-sizing: border-box !important;
    }
    
    /* Force containers to stay centered */
    .container {
        margin: 0 auto !important;
        float: none !important;
        position: static !important;
        width: 100% !important;
        max-width: 1400px !important;
        text-align: center !important;
    }
    
    body {
        background: #000000 !important;
        color: #fff;
        text-align: center !important;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    body #particles-js {
        position: fixed !important;
        width: 100% !important;
        height: 100% !important;
        top: 0 !important;
        left: 0 !important;
        z-index: -1 !important;
        background: linear-gradient(to bottom, rgba(5, 5, 8, 0.95) 0%, #000000 100%) !important;
        opacity: 1 !important;
        display: block !important;
    }
    
    #particles-js canvas {
        -webkit-filter: drop-shadow(0 0 8px #8a2be2) drop-shadow(0 0 16px #8a2be2) !important;
        filter: drop-shadow(0 0 8px #8a2be2) drop-shadow(0 0 16px #8a2be2) !important;
        will-change: filter;
        pointer-events: none;
    }

    body .gradient-overlay {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
        background:radial-gradient(circle at 10% 20%, rgba(42, 0, 51, 0.2) 0%, transparent 20%), radial-gradient(circle at 90% 80%, rgba(90, 13, 122, 0.15) 0%, transparent 20%), radial-gradient(circle at 50% 50%, rgba(58, 0, 82, 0.1) 0%, transparent 50%);
        z-index: -1 !important;
        pointer-events: none !important;
        opacity: 1 !important;
    }
    
    .table-responsive {
    max-width: 1100px !important;
    min-width: 1350px !important;
    margin: 0 auto 18px auto !important;
    display: inline-block !important;
    text-align: left;
	}

	.table-pinned1, .table-dark1 {
		font-size: 12px !important;
		line-height: 1.1 !important;
		border-radius: 8px !important;
		overflow: hidden;
		margin-bottom: 12px !important;
		width: 100% !important;
	}

	.table-pinned1 th, .table-pinned1 td,
	.table-dark1 th, .table-dark1 td {
		max-width: 240px;
		min-width: 60px;
		font-size: 13.5px !important;
		padding: 7px 12px !important;
		height: 28px !important;
		vertical-align: middle !important;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.table-pinned1 th, .table-dark1 th {
		font-size: 12.5px !important;
		font-weight: 700;
		background: #181818 !important;
		color: #fff !important;
		border-bottom: 1px solid #282828 !important;
		letter-spacing: 0.01em;
		padding: 7px 12px !important;
		height: 28px !important;
	}

	.table-dark {
		--bs-table-bg: #0d0d0d;
		--bs-table-striped-bg: #000;
		--bs-table-striped-color: #fff;
		--bs-table-hover-bg: #FF99FF;
		--bs-table-hover-color: #fff; 
		color: #fff;
	}
	.table-dark1 {
		--bs-table-bg: #0d0d0d;
		--bs-table-striped-bg: #000;
		--bs-table-striped-color: #fff;
		--bs-table-hover-color: #fff;
		color: #fff;
	}
	.table-pinned {
		--bs-table-bg: #FF99FF;
		--bs-table-striped-bg: #FF99FF;
		--bs-table-striped-color: #fff;
		--bs-table-hover-bg: #FF99FF;
		--bs-table-hover-color: #fff;
		color: #fff;
	}
	.table-pinned1 {
		--bs-table-bg: #0d0d0d;
		--bs-table-striped-bg: #000;
		--bs-table-striped-color: #fff;
		--bs-table-hover-bg: #FF99FF;
		--bs-table-hover-color: #fff;
		color: #fff;
	}

	.table > :not(caption) > * > * {
		border-bottom-width: 0.01px !important;
		border-bottom-color: #282828 !important;
	}

	.table-pinned1 tr, .table-dark1 tr {
		border-bottom: 1px solid #282828 !important;
		transition: background 0.12s;
	}

	.table-pinned1 tr:hover, .table-dark1 tr:hover {
		background: #232323 !important;
	}

	.table-pinned1 td.ellipsis, .table-dark1 td.ellipsis {
		max-width: 240px !important;
	}

	/* Remove the glow effects and animations from index.php */
	.table-row-glow {
		transition: none !important;
		position: static !important;
	}

	.table-row-glow:hover {
		transform: none !important;
		box-shadow: none !important;
		z-index: auto !important;
		border-left: none !important;
		border-right: none !important;
	}

	.table-row-glow::before {
		display: none !important;
	}

	.table-pinned1 tbody tr,
	.table-dark1 tbody tr {
		animation: none !important;
		opacity: 1 !important;
	}
    
    .text-center {
        text-align: center !important;
    }
    
    .justify-content-center {
        justify-content: center !important;
    }
    
    .mx-auto {
        margin-left: auto !important;
        margin-right: auto !important;
    }
    
    .input-group {
        transition: all 0.3s ease;
        margin: 0 auto 25px auto !important;
        display: flex;
        justify-content: center;
    }
    
    .input-group:focus-within {
        transform: scale(1.02);
    }
    
    .input-group:focus-within .form-control {
        box-shadow: 0 0 15px rgba(138, 43, 226, 0.4) !important;
        border-color: #8a2be2 !important;
    }
    
    .pagination-container {
        font-family: Arial, sans-serif;
        text-align: center;
        margin: 30px 0 !important;
    }
    
    .pagination-info p {
        color: #ccc;
        margin-bottom: 15px !important;
        font-size: 16px !important;
    }
    
    .pagination {
        display: inline-flex;
        list-style-type: none;
        padding: 0;
        margin: 0;
        justify-content: center;
    }
    
    .page-link {
        display: block;
        padding: 8px 12px !important; 
        min-width: 40px !important;   
        max-width: 50px !important;   
        text-align: center;
        text-decoration: none;
        color: #fff;
        background-color: #111 !important;
        border: 1px solid #333 !important;
        border-right: none;
        border-radius: 0;
        font-weight: bold;
        font-size: 14px !important;   
        transition: background-color 0.3s, color 0.3s;
        margin: 0;
    }
    
    .page-item.active .page-link {
        background-color: #222 !important;
        border-color: #8a2be2 !important;
    }
    
    .table-row-glow {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        position: relative;
    }

    .table-row-glow:hover {
        transform: translateY(-2px) scale(1.005);
        box-shadow: 
            0 0 15px rgba(138, 43, 226, 0.6),
            0 0 30px rgba(138, 43, 226, 0.4),
            0 0 45px rgba(138, 43, 226, 0.2),
            inset 0 0 15px rgba(138, 43, 226, 0.1) !important;
        z-index: 10;
        border-left: 2px solid #8a2be2 !important;
        border-right: 2px solid #8a2be2 !important;
    }

    .table-row-glow::before {
        content: '';
        position: absolute;
        top: 0;
        left: -2px;
        right: -2px;
        bottom: 0;
        background: linear-gradient(45deg, transparent, rgba(138, 43, 226, 0.1), transparent);
        opacity: 0;
        transition: opacity 0.3s ease;
        z-index: -1;
    }

    .table-row-glow:hover::before {
        opacity: 1;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .table-pinned1 tbody tr,
    .table-dark1 tbody tr {
        animation: fadeInUp 0.5s ease-out forwards;
        opacity: 0;
    }

    .table-pinned1 tbody tr:nth-child(1) { animation-delay: 0.1s; }
    .table-pinned1 tbody tr:nth-child(2) { animation-delay: 0.15s; }
    .table-pinned1 tbody tr:nth-child(3) { animation-delay: 0.2s; }
    .table-pinned1 tbody tr:nth-child(4) { animation-delay: 0.25s; }
    .table-pinned1 tbody tr:nth-child(5) { animation-delay: 0.3s; }

    .table-dark1 tbody tr:nth-child(1) { animation-delay: 0.1s; }
    .table-dark1 tbody tr:nth-child(2) { animation-delay: 0.15s; }
    .table-dark1 tbody tr:nth-child(3) { animation-delay: 0.2s; }
    .table-dark1 tbody tr:nth-child(4) { animation-delay: 0.25s; }
    .table-dark1 tbody tr:nth-child(5) { animation-delay: 0.3s; }
    .table-dark1 tbody tr:nth-child(6) { animation-delay: 0.35s; }
    .table-dark1 tbody tr:nth-child(7) { animation-delay: 0.4s; }
    .table-dark1 tbody tr:nth-child(8) { animation-delay: 0.45s; }
    .table-dark1 tbody tr:nth-child(9) { animation-delay: 0.5s; }
    .table-dark1 tbody tr:nth-child(10) { animation-delay: 0.55s; }

    input[type="radio"]:checked + label {
        color: #8a2be2 !important;
        text-shadow: 0 0 10px rgba(138, 43, 226, 0.5);
        transition: all 0.3s ease;
    }

    html {
        scroll-behavior: smooth;
    }
    
    .container {
        background: rgba(0, 0, 0, 0.7);
        border-radius: 8px;
        padding: 20px !important; /* Increased padding */
        margin-bottom: 25px !important; /* Increased margin */
    }
    
    .no-rank-user-link:hover,
    .no-rank-user-link:focus {
        color: inherit !important;
        text-decoration: none !important;
    }
    
    a { color: #FFF; }
    a:hover { color: #f9243f; }
    
    input[type="radio"]:checked + label {
        color: #8a2be2 !important;
    }
    
    @media (max-width: 1200px) {
        .table-responsive {
            max-width: 95vw !important;
        }
        .table-pinned1 th, .table-pinned1 td,
        .table-dark1 th, .table-dark1 td {
            font-size: 14px !important;
            padding: 10px 12px !important;
            max-width: 200px;
        }
    }
    
    @media (max-width: 800px) {
        .table-responsive {
            max-width: 98vw !important;
        }
        .table-pinned1 th, .table-pinned1 td,
        .table-dark1 th, .table-dark1 td {
            font-size: 12px !important;
            padding: 8px 6px !important;
            max-width: 120px;
        }
        .table-pinned1 td.ellipsis, .table-dark1 td.ellipsis {
            max-width: 120px;
        }
    }
    </style>
    <script async src="cdn-cgi/challenge-platform/h/b/scripts/invisible.js"></script>
</head>
<body>
<?php
if (isset($_SESSION['notification_message'])) {
    $notifMsg = $_SESSION['notification_message'];
    $notifType = $_SESSION['notification_type'] ?? 'info';
    echo "<script>window.addEventListener('DOMContentLoaded',function(){showNotification(" . json_encode($notifMsg) . "," . json_encode($notifType) . ");});</script>";
    unset($_SESSION['notification_message'], $_SESSION['notification_type']);
}
?>
<div id="main-notification"></div>
<div id="particles-js"></div>
<div class="gradient-overlay"></div>
<?php
$navbarPath = __DIR__ . '/cstnavbar.php';
if (file_exists($navbarPath)) {
    include($navbarPath);
}
?>
<br>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12 text-center">
            <svg width="93.2947902mm" height="21.14593236mm" viewbox="0 0 172.76813 39.159134" version="1.1" shape-rendering="crispEdges" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" xmlns="http://www.w3.org/2000/svg" xmlns:svg="http://www.w3.org/2000/svg">
                <defs id="defs189">
                    <filter inkscape:label="Sharpen" inkscape:menu="Image Effects" inkscape:menu-tooltip="Sharpen edges and boundaries within the object, force=1" style="color-interpolation-filters:sRGB;" id="filter120" x="0" y="0" width="1" height="1">
                        <feconvolvematrix order="3 3" kernelmatrix="0 -0.15 0 -0.15 1.6 -0.15 0 -0.15 0" divisor="1" in="SourceGraphic" targetx="1" targety="1" id="feConvolveMatrix118"/>
                    </filter>
                </defs>
            </svg>
			<center>
				<img src="https://files.catbox.moe/91w4zc.png" width="170" class="mx-auto d-block">
			</center>
            <div style="margin-top: 15px;"></div>
            <div class="container">
                <div class="text-center">
                <?php
                $linksQuery = "SELECT text, href, color, font_size FROM links ORDER BY `order` ASC";
                $linksResult = $conn->query($linksQuery);
                ?>
                <div class="container">
                    <div class="text-center">
                        <?php while ($link = $linksResult->fetch_assoc()): ?>
                            <h4 style="background: <?= htmlspecialchars($link['color'], ENT_QUOTES, 'UTF-8') ?>; -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-size: <?= htmlspecialchars($link['font_size'], ENT_QUOTES, 'UTF-8') ?>;">
                                <b>
                                    <a 
                                       style="background: <?= htmlspecialchars($link['color'], ENT_QUOTES, 'UTF-8') ?>; -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-size: <?= htmlspecialchars($link['font_size'], ENT_QUOTES, 'UTF-8') ?>;" 
                                       href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>" 
                                       rel="noreferrer" 
                                       target="_blank">
                                       <?= htmlspecialchars($link['text'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </b>
                            </h4>
                        <?php endwhile; ?>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </div>

    <form action="index.php" method="post" class="text-center">
        <div style="margin-bottom:15px;">
            <input type="radio" id="searchTitle" name="searchOption" value="Search in title" <?php if ($searchInTitle) echo 'checked'; ?>>
            <label for="searchTitle">Search in title</label>
            <input type="radio" id="searchPaste" name="searchOption" value="Search in paste" <?php if ($searchInContent) echo 'checked'; ?> style="margin-left:18px;">
            <label for="searchPaste">Search in paste</label>
        </div>
        <div class="input-group input-group-sm font-monospace justify-content-center" style="max-width: 400px; margin: 0 auto;">
            <input class="form-control" type="text" name="search" placeholder="Search for..." style="color: var(--bs-white);background:#111;border:1px solid #333;" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
            <button class="btn btn-dark rounded" style="border-radius: 0 3px 3px 0 !important;background:#222;border:1px solid #333;" type="submit">Search</button>
            <input type="hidden" name="token" value="8029427d4a172c32ba33b115464f3a46bc22950bb340b319fc3ea5955ac34ed6">
        </div>
    </form>

    <div class="pagination-container text-center">
        <?php
        $startItem = (($page - 1) * $perPage) + 1;
        $endItem = min($page * $perPage, $totalPastes);
        ?>
        <div class="pagination-info">
            <p>Showing <?php echo $endItem; ?> (of <?php echo $totalPastes; ?> total) pastes</p>
        </div>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php
                $pagesToShow = 7;
                $startPage = 1;
                $endPage = $totalPages;
                if ($totalPages > $pagesToShow) {
                    if ($page > 4) {
                        $startPage = $page - 3;
                        if ($startPage + $pagesToShow - 1 < $totalPages) {
                            $endPage = $startPage + $pagesToShow - 1;
                        }
                    } else {
                        $endPage = $pagesToShow;
                    }
                }
                if ($startPage > 1) {
                    echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
                    if ($startPage > 2) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                }
                for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor;
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . '">' . $totalPages . '</a></li>';
                }
                ?>
                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>

    <div class="text-center">
        <div class="table-responsive">
            <table class="table table-hover table-pinned1 table-sm table-striped">
                <thead>
                    <tr> 
                        <th style="text-align: left;">Pinned Pastes</th>
                        <th>Comments</th>
                        <th>Views</th>
                        <th>Posted by</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $queryPinned = "SELECT * FROM pastes WHERE pinned = 1 AND visibility = 0 ORDER BY date_created DESC LIMIT 10"; 
                $resultPinned = $conn->query($queryPinned);

                $rowAlt = 0;
                if ($resultPinned && $resultPinned->num_rows > 0) {
                    while ($paste = $resultPinned->fetch_assoc()) {
                        $userRank = null;
                        $userColor = null;
                        $hasColor = null;

                        $formattedTime = timeAgo($paste['date_created']);
                        $pasteTitle = strlen($paste['title']) > 30 ? substr($paste['title'], 0, 30) . '...' : $paste['title'];

                        $userQuery = "SELECT rank, color, has_color FROM users WHERE username = '{$paste['creator']}' LIMIT 1";
                        $userResult = $conn->query($userQuery);
                        $userRank = null; 
                        $userColor = null;
                        $hasColor = null;

                        if ($userResult->num_rows > 0) {
                            $user = $userResult->fetch_assoc();
                            $userRank = $user['rank']; 
                            $userColor = $user['color']; 
                            $hasColor = $user['has_color']; 
                        }

                        $rankClass = $userRank ? 'rank-' . strtolower($userRank) : '';
                        $rankTag = $userRank && isset($rankData[$userRank]) ? $rankData[$userRank]['tag'] : '';
                        $rankColor = $userRank && isset($rankData[$userRank]) ? $rankData[$userRank]['color'] : '';
                        $tableColor = $userRank && isset($rankData[$userRank]) && $rankData[$userRank]['tableColor'] ? $rankData[$userRank]['tableColor'] : '';
                        $tableColorHover = $userRank && isset($rankData[$userRank]) && $rankData[$userRank]['tableHoverColor'] ? $rankData[$userRank]['tableHoverColor'] : '';
                        $backgroundGif = ''; 

                        $isAnonymous = (strtolower($paste['creator']) === 'anonymous');

                        $usernameColor = (!$isAnonymous && $userColor) ? $userColor : $rankColor; 

                        $rowTableColor = (!$isAnonymous && $tableColor) ? $tableColor : (($rowAlt % 2 == 0) ? '#030303' : '#111');
                        $rowTableColorHover = (!$isAnonymous && $tableColorHover) ? $tableColorHover : '#1a1a1a';

                        $pinned_icon = ($paste['pinned'] == 1) ? '<i class="fas fa-thumbtack" style="margin-right: 5px; color: white;"></i>' : '';

                        $comments_display = (isset($paste['comments_enabled']) && $paste['comments_enabled'] == 0) ? '-' : htmlspecialchars($paste['comments'], ENT_QUOTES, 'UTF-8');

                        echo "<tr class='table-row-glow table table-hover table-sm table-striped' style='
                                margin-bottom: 35px; 
                                --bs-table-bg: " . htmlspecialchars($rowTableColor, ENT_QUOTES, 'UTF-8') . ";
                                --bs-table-striped-bg: " . htmlspecialchars($rowTableColor, ENT_QUOTES, 'UTF-8') . ";
                                background-color: " . htmlspecialchars($rowTableColor, ENT_QUOTES, 'UTF-8') . ";
                                transition: background-color 0.2s ease-in-out;'
                                onmouseover='this.style.backgroundColor=\"" . htmlspecialchars($rowTableColorHover, ENT_QUOTES, 'UTF-8') . "\"' 
                                onmouseout='this.style.backgroundColor=\"" . htmlspecialchars($rowTableColor, ENT_QUOTES, 'UTF-8') . "\"'>
                                <td class='ellipsis' title='" . htmlspecialchars($paste['title'], ENT_QUOTES, 'UTF-8') . "'>
                                    <a href='view/" . urlencode($paste['title']) . "' target='_blank' style='color: inherit; text-decoration: none;'>
                                        " . $pinned_icon . htmlspecialchars($pasteTitle, ENT_QUOTES, 'UTF-8') . "
                                    </a>
                                </td>
                                <td>" . $comments_display . "</td>
                                <td>" . htmlspecialchars($paste['views'], ENT_QUOTES, 'UTF-8') . "</td>
                                <td class='" . htmlspecialchars($rankClass, ENT_QUOTES, 'UTF-8') . "'>";
                        if ($isAnonymous) {
                            echo "<span style='color:#bbb;'>anonymous</span>";
                        } else {
                            if ($rankClass === '') {
                                $userLinkExtra = 'class="no-rank-user-link" style="color:' . htmlspecialchars($usernameColor, ENT_QUOTES, 'UTF-8') . '; text-decoration: none;"';
                            } else {
                                $userLinkExtra = 'style="color:' . htmlspecialchars($usernameColor, ENT_QUOTES, 'UTF-8') . '; text-decoration: none;' . ($backgroundGif ? 'background-image: ' . htmlspecialchars($backgroundGif, ENT_QUOTES, 'UTF-8') . '; border-radius: 3px;' : '') . '"';
                            }
                            echo "<a $userLinkExtra
                                    href='user/" . urlencode($paste['creator']) . "' 
                                    target='_blank'>
                                    " . htmlspecialchars($paste['creator'], ENT_QUOTES, 'UTF-8') . " 
                                    <span style='color: " . htmlspecialchars($rankColor, ENT_QUOTES, 'UTF-8') . "; padding: 0 3px;'>
                                        " . htmlspecialchars($rankTag, ENT_QUOTES, 'UTF-8') . "
                                    </span>
                                </a>";
                        }
                        echo "</td>
                                <td>" . htmlspecialchars($formattedTime, ENT_QUOTES, 'UTF-8') . "</td>
                            </tr>";
                        $rowAlt++;
                    }
                } else {
                    echo "<tr>
                    <td colspan='5' style='text-align: center;'>No pinned pastes found.</td>
                </tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="text-center">
        <div class="table-responsive">
            <table class="table table-hover table-dark1 table-sm table-striped">
                <thead>
                    <tr>
                        <th style="text-align: left;">Title</th>
                        <th>Comments</th>
                        <th>Views</th>
                        <th>Posted by</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $rowAlt = 0;
                if ($result && $result->num_rows > 0) {
                    while ($paste = $result->fetch_assoc()) {
                        $userRank = null;
                        $userColor = null;
                        $hasColor = null;

                        if ($paste) {
                            $formattedTime = timeAgo($paste['date_created']);
                            $pasteTitle = strlen($paste['title']) > 30 ? substr($paste['title'], 0, 30) . '...' : $paste['title'];

                            $userQuery = "SELECT rank, color, has_color FROM users WHERE username = '{$paste['creator']}' LIMIT 1";
                            $userResult = $conn->query($userQuery);

                            if ($userResult->num_rows > 0) {
                                $user = $userResult->fetch_assoc();
                                $userRank = $user['rank'];
                                $userColor = $user['color'];
                                $hasColor = $user['has_color'];
                            }

                            $rankClass = $userRank ? 'rank-' . strtolower($userRank) : '';
                            $rankTag = $userRank && isset($rankData[$userRank]) ? $rankData[$userRank]['tag'] : '';
                            $rankColor = $userRank && isset($rankData[$userRank]) ? $rankData[$userRank]['color'] : '';
                            $tableColor = $userRank && isset($rankData[$userRank]) && $rankData[$userRank]['tableColor'] ? $rankData[$userRank]['tableColor'] : '';
                            $tableColorHover = $userRank && isset($rankData[$userRank]) && $rankData[$userRank]['tableHoverColor'] ? $rankData[$userRank]['tableHoverColor'] : '';

                            $backgroundGif = ''; 
                            $isAnonymous = (strtolower($paste['creator']) === 'anonymous');

                            $usernameColor = (!$isAnonymous && $userColor) ? $userColor : $rankColor; 

                            $rowTableColor = (!$isAnonymous && $tableColor) ? $tableColor : (($rowAlt % 2 == 0) ? '#030303' : '#111');
                            $rowTableColorHover = (!$isAnonymous && $tableColorHover) ? $tableColorHover : '#1a1a1a';

                            $comments_display = (isset($paste['comments_enabled']) && $paste['comments_enabled'] == 0) ? '-' : htmlspecialchars($paste['comments'], ENT_QUOTES, 'UTF-8');

                            echo "<tr class='table-row-glow' style='
                                --bs-table-bg: " . htmlspecialchars($rowTableColor, ENT_QUOTES, 'UTF-8') . ";
                                --bs-table-striped-bg: " . htmlspecialchars($rowTableColor, ENT_QUOTES, 'UTF-8') . ";
                                background-color: " . htmlspecialchars($rowTableColor, ENT_QUOTES, 'UTF-8') . ";
                                transition: background-color 0.2s ease-in-out;'
                                onmouseover='this.style.backgroundColor=\"" . htmlspecialchars($rowTableColorHover, ENT_QUOTES, 'UTF-8') . "\"' 
                                onmouseout='this.style.backgroundColor=\"" . htmlspecialchars($rowTableColor, ENT_QUOTES, 'UTF-8') . "\"'>
                                    <td class='ellipsis' title='" . htmlspecialchars($paste['title'], ENT_QUOTES, 'UTF-8') . "'>
                                        <a href='posted/" . urlencode($paste['title']) . "' target='_blank' style='color: inherit; text-decoration: none;'>" . htmlspecialchars($paste['title'], ENT_QUOTES, 'UTF-8') . "</a>
                                    </td>
                                    <td>" . $comments_display . "</td>
                                    <td>" . htmlspecialchars($paste['views'], ENT_QUOTES, 'UTF-8') . "</td>
                                    <td class='" . htmlspecialchars($rankClass, ENT_QUOTES, 'UTF-8') . "'>";
                            if ($isAnonymous) {
                                echo "<span style='color:#bbb;'>anonymous</span>";
                            } else {
                                if ($rankClass === '') {
                                    $userLinkExtra = 'class="no-rank-user-link" style="color:' . htmlspecialchars($usernameColor, ENT_QUOTES, 'UTF-8') . '; text-decoration: none;"';
                                } else {
                                    $userLinkExtra = 'style="color:' . htmlspecialchars($usernameColor, ENT_QUOTES, 'UTF-8') . '; text-decoration: none;' . ($backgroundGif ? 'background-image: ' . htmlspecialchars($backgroundGif, ENT_QUOTES, 'UTF-8') . '; border-radius: 3px;' : '') . '"';
                                }
                                echo "<a $userLinkExtra
                                        href='user/" . urlencode($paste['creator']) . "' 
                                        target='_blank'>
                                        " . htmlspecialchars($paste['creator'], ENT_QUOTES, 'UTF-8') . " 
                                        <span style='color: " . htmlspecialchars($rankColor, ENT_QUOTES, 'UTF-8') . "; padding: 0 3px;'>
                                            " . htmlspecialchars($rankTag, ENT_QUOTES, 'UTF-8') . "
                                        </span>
                                    </a>";
                            }
                            echo "</td>
                                    <td>" . htmlspecialchars($formattedTime, ENT_QUOTES, 'UTF-8') . "</td>
                                </tr>";
                        }
                        $rowAlt++;
                    }
                } else {
                    echo "<tr><td colspan='5'>No pastes found.</td></tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function showNotification(message, type = 'info') {
        let notif = document.getElementById('main-notification');
        if (!notif) return;
        notif.style.background = type === 'success' ? '#2ecc40' : (type === 'danger' ? '#e05f5f' : '#111');
        notif.style.color = '#fff';
        notif.textContent = message;
        notif.style.display = 'block';
        notif.style.opacity = '0.98';
        setTimeout(() => {
            notif.style.opacity = '0';
            setTimeout(() => { notif.style.display = 'none'; }, 400);
        }, 2500);
    }
</script>
</body>
</html>