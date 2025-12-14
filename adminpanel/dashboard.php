<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include('../database.php');
include_once(__DIR__ . '/admin_2fa_gate.php');

$timeout = 900;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $timeout)) {
    session_unset();
    session_destroy();
    header('Location: /login');
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} elseif (time() - $_SESSION['CREATED'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();
}

if (!isset($_SESSION['username']) && isset($_COOKIE['login_token'])) {
    $login_token = preg_replace('/[^a-f0-9]/', '', $_COOKIE['login_token']);
    if ($login_token) {
        $stmt = $conn->prepare("SELECT username, login_token, rank, locked FROM users WHERE login_token = ?");
        $stmt->bind_param("s", $login_token);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (!empty($row['login_token'])) {
                if ($row['locked'] == 1) {
                    session_destroy();
                    setcookie('login_token', '', time() - 3600, "/", "", true, true);
                    header('Location: /login');
                    exit();
                }
                
                $_SESSION['username'] = $row['username'];
                $_SESSION['CREATED'] = time();
                $_SESSION['LAST_ACTIVITY'] = time();
                $_SESSION['rank'] = $row['rank'];
            }
        }
        $stmt->close();
    }
}

$requiredRanks = ['Admin', 'Manager', 'Mod', 'Council', 'Founder'];
if (!in_array($loggedInUserRank, $requiredRanks)) {
    header('Location: /login');
    exit();
}

function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

$usersHasCreatedAt = columnExists($conn, 'users', 'created_at');
$pastesHasDateCreated = columnExists($conn, 'pastes', 'date_created');

$totalUsers = $conn->query("SELECT COUNT(*) as cnt FROM users")->fetch_assoc()['cnt'];
$totalPastes = $conn->query("SELECT COUNT(*) as cnt FROM pastes")->fetch_assoc()['cnt'];
$totalComments = $conn->query("SELECT COUNT(*) as cnt FROM profile_comments")->fetch_assoc()['cnt'];
$totalLocked = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE locked = 1")->fetch_assoc()['cnt'];

$usersToday = 0;
$pastesToday = 0;
$commentsToday = 0;

if ($usersHasCreatedAt) {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE DATE(created_at) = CURDATE()");
    $usersToday = $res ? $res->fetch_assoc()['cnt'] : 0;
}

if ($pastesHasDateCreated) {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM pastes WHERE DATE(date_created) = CURDATE()");
    $pastesToday = $res ? $res->fetch_assoc()['cnt'] : 0;
}

$res = $conn->query("SELECT COUNT(*) as cnt FROM profile_comments WHERE DATE(created_at) = CURDATE()");
$commentsToday = $res ? $res->fetch_assoc()['cnt'] : 0;

$rankStats = [];
$rankResult = $conn->query("SELECT rank, COUNT(*) as count FROM users WHERE rank IS NOT NULL GROUP BY rank ORDER BY count DESC");
while ($row = $rankResult->fetch_assoc()) {
    $rankStats[$row['rank']] = $row['count'];
}

$recentUsers = [];
$userResult = $conn->query("SELECT username, rank, created_at FROM users ORDER BY created_at DESC LIMIT 5");
while ($row = $userResult->fetch_assoc()) {
    $recentUsers[] = $row;
}

$recentPastes = [];
$pasteResult = $conn->query("SELECT title, creator, date_created FROM pastes ORDER BY date_created DESC LIMIT 5");
while ($row = $pasteResult->fetch_assoc()) {
    $recentPastes[] = $row;
}

$userGrowth = [];
$pasteGrowth = [];
$labels = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('M j', strtotime($date));
    
    if ($usersHasCreatedAt) {
        $res = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE DATE(created_at) = '$date'");
        $userGrowth[] = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
    } else {
        $userGrowth[] = 0;
    }
    
    if ($pastesHasDateCreated) {
        $res = $conn->query("SELECT COUNT(*) as cnt FROM pastes WHERE DATE(date_created) = '$date'");
        $pasteGrowth[] = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
    } else {
        $pasteGrowth[] = 0;
    }
}

$systemStatus = [
    'database' => $conn ? true : false,
    'sessions' => session_status() === PHP_SESSION_ACTIVE,
    'storage' => disk_free_space(__DIR__) > 104857600, // 100MB free
    'security' => true
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --black: #000000;
            --dark-gray-1: #121212;
            --dark-gray-2: #1E1E1E;
            --dark-gray-3: #252525;
            --gray-1: #333333;
            --gray-2: #555555;
            --gray-3: #777777;
            --light-gray-1: #AAAAAA;
            --light-gray-2: #CCCCCC;
            --light-gray-3: #EEEEEE;
            --white: #FFFFFF;
            
            --primary: #8B5CF6;
            --primary-hover: #7C3AED;
            --primary-glow: rgba(139, 92, 246, 0.3);
            --secondary: #6B7280;
            --secondary-hover: #4B5563;
            --success: #10B981;
            --success-hover: #059669;
            --danger: #EF4444;
            --danger-hover: #DC2626;
            --warning: #F59E0B;
            --warning-hover: #D97706;
            --info: #3B82F6;
            --info-hover: #2563EB;
            
            --bg-dark: #0F0F1A;
            --bg-card: #1A1A2E;
            --bg-nav: #0F0F1A;
            --text-light: #FFFFFF;
            --text-medium: #D1D5DB;
            --text-muted: #9CA3AF;
            --border: #2D2D4D;
            --border-light: #3E3E5E;
            
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.5);
            --glow-sm: 0 0 10px var(--primary-glow);
            --glow-md: 0 0 20px var(--primary-glow);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            color: var(--text-light);
            background: var(--bg-dark);
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            overflow-x: hidden;
            opacity: 0;
            animation: fadeIn 0.6s ease-out forwards;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(139, 92, 246, 0.1) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(139, 92, 246, 0.1) 0%, transparent 20%);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .navbar {
            background: var(--bg-nav);
            box-shadow: var(--shadow-sm), var(--glow-sm);
            padding: 0.75rem 1rem;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            display: flex;
            align-items: center;
            height: 70px;
            border-bottom: 1px solid var(--border-light);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--white);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-shadow: 0 0 10px var(--primary-glow);
        }

        .navbar-brand i {
            font-size: 1.75rem;
            color: var(--primary);
        }

        .nav-tabs {
            margin-left: 2rem;
            border: none;
            gap: 0.5rem;
        }

        .nav-tabs .nav-link {
            border: none;
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            color: var(--text-muted);
            border-radius: 0.5rem;
            position: relative;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-tabs .nav-link:hover {
            color: var(--white);
            background: rgba(139, 92, 246, 0.1);
            transform: translateY(-2px);
        }

        .nav-tabs .nav-link.active {
            color: var(--white);
            background: rgba(139, 92, 246, 0.2);
            box-shadow: var(--glow-sm);
            font-weight: 600;
        }

        .navbar-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .main-content {
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
        }

        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            color: var(--white);
            text-shadow: 0 0 10px var(--primary-glow);
        }

        .card {
            background: var(--bg-card);
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .card:hover {
            box-shadow: var(--shadow-lg), var(--glow-sm);
            transform: translateY(-5px);
        }

        .card-header {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-title {
            font-weight: 600;
            margin: 0;
            font-size: 1.25rem;
            color: var(--white);
        }

        .card-body {
            padding: 1.5rem;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-card);
            border-radius: 0.75rem;
            padding: 1.5rem;
            text-align: center;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-hover));
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg), var(--glow-sm);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 0.5rem;
            text-shadow: 0 0 10px var(--primary-glow);
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05rem;
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 1rem;
            display: inline-block;
        }

        .stat-change.positive {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .stat-change.negative {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .chart-container {
            background: var(--bg-card);
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .chart-container:hover {
            box-shadow: var(--shadow-lg), var(--glow-sm);
        }

        .chart-title {
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--white);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .activity-item {
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(139, 92, 246, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1rem;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: var(--white);
            margin-bottom: 0.25rem;
        }

        .activity-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .status-online {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .status-offline {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .status-warning {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }

        .rank-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(139, 92, 246, 0.2);
            color: var(--primary);
            border: 1px solid rgba(139, 92, 246, 0.3);
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            box-shadow: var(--glow-sm);
            transform: translateY(-2px);
        }

        .btn-outline-primary {
            color: var(--primary);
            border: 1px solid var(--primary);
            background: transparent;
        }

        .btn-outline-primary:hover {
            background-color: var(--primary);
            border-color: var(--primary);
            color: var(--white);
            box-shadow: var(--glow-sm);
            transform: translateY(-2px);
        }

        .refresh-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
            z-index: 10;
        }

        @media (max-width: 992px) {
            .navbar-expand-lg .navbar-nav {
                flex-direction: row;
            }
            
            .navbar {
                flex-wrap: wrap;
                height: auto;
                padding: 1rem;
            }
            
            .nav-tabs {
                margin-left: 0;
                margin-top: 1rem;
                flex-wrap: nowrap;
                overflow-x: auto;
                width: 100%;
                padding-bottom: 0.5rem;
            }
            
            .main-content {
                margin-top: 120px;
                padding: 1rem;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
        }

        .glow-text {
            text-shadow: 0 0 10px var(--primary-glow);
        }

        .fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">
            <i class="fas fa-shield-alt"></i>
            Admin Panel
        </a>
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-users"></i> Users
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="pastes.php">
                    <i class="fas fa-file-code"></i> Pastes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="delete_logs.php">
                    <i class="fas fa-history"></i> Delete Logs
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="badges.php">
                    <i class="fas fa-award"></i> Badges
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="links.php">
                    <i class="fas fa-link"></i> Links
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="hoa.php">
                    <i class="fas fa-crown"></i> Hall of Autism
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="edits.php">
                    <i class="fas fa-edit"></i> Edits
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
        </ul>
        <div class="navbar-actions">
            <a href="../home" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-home"></i> Home
            </a>
        </div>
    </div>
</nav>

<div class="main-content">
    <div class="container-fluid">
        <div class="page-header">
            <h1 class="page-title">Admin Dashboard</h1>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary" id="refreshDashboard">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <button class="btn btn-primary" id="exportReport">
                    <i class="fas fa-download"></i> Export Report
                </button>
            </div>
        </div>

        <!-- Stats Overview -->
        <div class="stats-container">
            <div class="stat-card fade-in" style="animation-delay: 0.1s">
                <i class="fas fa-users stat-icon"></i>
                <div class="stat-value"><?= number_format($totalUsers) ?></div>
                <div class="stat-label">Total Users</div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> +<?= $usersToday ?> today
                </div>
            </div>
            <div class="stat-card fade-in" style="animation-delay: 0.2s">
                <i class="fas fa-file-code stat-icon"></i>
                <div class="stat-value"><?= number_format($totalPastes) ?></div>
                <div class="stat-label">Total Pastes</div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> +<?= $pastesToday ?> today
                </div>
            </div>
            <div class="stat-card fade-in" style="animation-delay: 0.3s">
                <i class="fas fa-comments stat-icon"></i>
                <div class="stat-value"><?= number_format($totalComments) ?></div>
                <div class="stat-label">Total Comments</div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> +<?= $commentsToday ?> today
                </div>
            </div>
            <div class="stat-card fade-in" style="animation-delay: 0.4s">
                <i class="fas fa-lock stat-icon"></i>
                <div class="stat-value"><?= number_format($totalLocked) ?></div>
                <div class="stat-label">Locked Accounts</div>
                <div class="stat-change negative">
                    <i class="fas fa-exclamation-triangle"></i> Needs attention
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-chart-line"></i> Growth Analytics (14 Days)
                        </h5>
                        <div class="chart-controls">
                            <button class="btn btn-sm btn-outline-primary active" data-chart="all">All</button>
                            <button class="btn btn-sm btn-outline-primary" data-chart="users">Users</button>
                            <button class="btn btn-sm btn-outline-primary" data-chart="pastes">Pastes</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <canvas id="growthChart" height="120"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-server"></i> System Status
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="activity-list">
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-database"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Database</div>
                                    <div class="activity-meta">MySQL Connection</div>
                                </div>
                                <span class="status-indicator <?= $systemStatus['database'] ? 'status-online' : 'status-offline' ?>">
                                    <i class="fas fa-circle"></i>
                                    <?= $systemStatus['database'] ? 'Online' : 'Offline' ?>
                                </span>
                            </div>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-user-shield"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Sessions</div>
                                    <div class="activity-meta">User Authentication</div>
                                </div>
                                <span class="status-indicator <?= $systemStatus['sessions'] ? 'status-online' : 'status-offline' ?>">
                                    <i class="fas fa-circle"></i>
                                    <?= $systemStatus['sessions'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-hdd"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Storage</div>
                                    <div class="activity-meta">Disk Space</div>
                                </div>
                                <span class="status-indicator <?= $systemStatus['storage'] ? 'status-online' : 'status-warning' ?>">
                                    <i class="fas fa-circle"></i>
                                    <?= $systemStatus['storage'] ? 'Healthy' : 'Low' ?>
                                </span>
                            </div>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Security</div>
                                    <div class="activity-meta">2FA & Protection</div>
                                </div>
                                <span class="status-indicator <?= $systemStatus['security'] ? 'status-online' : 'status-offline' ?>">
                                    <i class="fas fa-circle"></i>
                                    <?= $systemStatus['security'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-chart-pie"></i> Rank Distribution
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="rankChart" height="250"></canvas>
                    </div>
                </div>
            </div>

            
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-clock"></i> Recent Activity
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="activity-list">
                            <?php foreach($recentUsers as $user): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-user-plus"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">New User Registered</div>
                                    <div class="activity-meta">
                                        <span class="username-text"><?= htmlspecialchars($user['username']) ?></span>
                                        <span class="rank-badge"><?= $user['rank'] ?? 'All Users' ?></span>
                                    </div>
                                </div>
                                <div class="activity-meta">
                                    <?= date('M j, g:i A', strtotime($user['created_at'])) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php foreach($recentPastes as $paste): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-file-medical"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">New Paste Created</div>
                                    <div class="activity-meta">
                                        "<em><?= htmlspecialchars($paste['title']) ?></em>" by 
                                        <span class="username-text"><?= htmlspecialchars($paste['creator']) ?></span>
                                    </div>
                                </div>
                                <div class="activity-meta">
                                    <?= date('M j, g:i A', strtotime($paste['date_created'])) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Growth Chart
    const growthCtx = document.getElementById('growthChart').getContext('2d');
    const growthChart = new Chart(growthCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [
                {
                    label: 'User Growth',
                    data: <?= json_encode($userGrowth) ?>,
                    borderColor: '#8B5CF6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#8B5CF6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                },
                {
                    label: 'Paste Growth',
                    data: <?= json_encode($pasteGrowth) ?>,
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#10B981',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    labels: {
                        color: '#D1D5DB',
                        font: { size: 12 }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#9CA3AF',
                        font: { size: 11 }
                    },
                    grid: { color: '#2D2D4D' }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#9CA3AF',
                        font: { size: 11 }
                    },
                    grid: { color: '#2D2D4D' }
                }
            }
        }
    });

    // Rank Distribution Chart
    const rankCtx = document.getElementById('rankChart').getContext('2d');
    const rankChart = new Chart(rankCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_keys($rankStats)) ?>,
            datasets: [{
                data: <?= json_encode(array_values($rankStats)) ?>,
                backgroundColor: [
                    '#8B5CF6', '#7C3AED', '#6D28D9', '#5B21B6', '#4C1D95',
                    '#10B981', '#059669', '#047857', '#065F46', '#064E3B',
                    '#EF4444', '#DC2626', '#B91C1C', '#991B1B', '#7F1D1D',
                    '#F59E0B', '#D97706', '#B45309', '#92400E', '#78350F'
                ],
                borderColor: '#1A1A2E',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        color: '#D1D5DB',
                        font: { size: 11 },
                        padding: 15
                    }
                }
            }
        }
    });

    // Chart controls
    document.querySelectorAll('.chart-controls .btn').forEach(button => {
        button.addEventListener('click', function() {
            document.querySelectorAll('.chart-controls .btn').forEach(btn => {
                btn.classList.remove('active');
            });
            this.classList.add('active');
            
            const chartType = this.getAttribute('data-chart');
            growthChart.data.datasets.forEach((dataset, index) => {
                dataset.hidden = chartType !== 'all' && 
                    ((chartType === 'users' && index !== 0) || 
                     (chartType === 'pastes' && index !== 1));
            });
            growthChart.update();
        });
    });

    // Refresh dashboard
    document.getElementById('refreshDashboard').addEventListener('click', function() {
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
        setTimeout(() => {
            location.reload();
        }, 1000);
    });

    // Export report
    document.getElementById('exportReport').addEventListener('click', function() {
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
        setTimeout(() => {
            alert('Dashboard report exported successfully!');
            this.innerHTML = '<i class="fas fa-download"></i> Export Report';
        }, 1500);
    });
});
</script>
</body>
</html>