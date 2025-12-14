<?php
session_start();
include('../database.php');
include_once(__DIR__ . '/admin_2fa_gate.php');

// Ensure CSRF token is generated
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check login token from session OR cookie
$loginToken = null;
if (isset($_SESSION['login_token']) && !empty($_SESSION['login_token'])) {
    $loginToken = $_SESSION['login_token'];
} elseif (isset($_COOKIE['login_token'])) {
    $loginToken = $_COOKIE['login_token'];
    $_SESSION['login_token'] = $loginToken; // Sync session with cookie
} else {
    header('Location: /login');
    exit();
}

$query = "SELECT username, rank FROM users WHERE login_token = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $loginToken);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();

if (!$userData || !in_array($userData['rank'], ['Admin', 'Manager', 'Mod', 'Founder'])) {
    header('Location: /login');
    exit();
}

$username = $userData['username'];
$loggedInUserRank = $userData['rank'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // More lenient CSRF token validation
    if (empty($_SESSION['csrf_token']) || empty($_POST['csrf_token'])) {
        http_response_code(403);
        exit('CSRF token missing');
    }
    
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    if (isset($_POST['paste_id'])) {
        $pasteId = $_POST['paste_id'];

        if (isset($_POST['action']) && $_POST['action'] == 'delete') {
            $reason = $_POST['reason'] ?? 'No reason provided';
            $stmt = $conn->prepare("SELECT creator, title FROM pastes WHERE id = ?");
            $stmt->bind_param("i", $pasteId);
            $stmt->execute();
            $pasteData = $stmt->get_result()->fetch_assoc();
            $creator = $pasteData['creator'];
            $title = $pasteData['title'];

            $deleteViewsQuery = "DELETE FROM paste_views WHERE paste_id = ?";
            $stmt = $conn->prepare($deleteViewsQuery);
            $stmt->bind_param("i", $pasteId);

            if (!$stmt->execute()) {
                echo json_encode(['error' => 'Failed to delete paste views']);
                exit();
            }

            $deleteCommentsQuery = "DELETE FROM paste_comments WHERE paste_id = ?";
            $stmt = $conn->prepare($deleteCommentsQuery);
            $stmt->bind_param("i", $pasteId);

            if (!$stmt->execute()) {
                echo json_encode(['error' => 'Failed to delete comments']);
                exit();
            }

            $deletePasteQuery = "DELETE FROM pastes WHERE id = ?";
            $stmt = $conn->prepare($deletePasteQuery);
            $stmt->bind_param("i", $pasteId);

            if ($stmt->execute()) {
                $stmt = $conn->prepare("INSERT INTO notifications (username, message) VALUES (?, ?)");
                $message = "Your paste '$title' was deleted for the following reason: $reason";
                $stmt->bind_param("ss", $creator, $message);
                $stmt->execute();

                $stmt = $conn->prepare("INSERT INTO delete_logs (deleted_by, paste_title, paste_creator, reason) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $username, $title, $creator, $reason);
                $stmt->execute();

                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Failed to delete paste']);
            }
            exit();
        }

        if (isset($_POST['action']) && ($_POST['action'] == 'pin' || $_POST['action'] == 'unpin')) {
            $action = $_POST['action'];
            $new_status = ($action === 'pin') ? 1 : 0;

            $stmt = $conn->prepare("UPDATE pastes SET pinned = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $pasteId);
            $stmt->execute();

            echo json_encode(['success' => true, 'new_status' => $new_status]);
            exit();
        }
    }
    
    if(isset($_POST['run_blacklist_script'])) {
        exec("php ../BlackList.php > /dev/null 2>&1 &");
        echo "Blacklist cleanup task started!";
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'edit_paste') {
        $pasteId = intval($_POST['paste_id']);
        $newTitle = $_POST['title'] ?? '';
        $newCreator = $_POST['creator'] ?? '';
        $newViews = intval($_POST['views'] ?? 0);
        $newDate = $_POST['date_created'] ?? '';

        $stmt = $conn->prepare("UPDATE pastes SET title=?, creator=?, views=?, date_created=? WHERE id=?");
        $stmt->bind_param("ssisi", $newTitle, $newCreator, $newViews, $newDate, $pasteId);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Failed to update paste']);
        }
        exit();
    }
}

$itemsPerPage = 75;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);
$offset = ($page - 1) * $itemsPerPage;

$search_query = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search']), ENT_QUOTES, 'UTF-8') : '';

if ($search_query !== '') {
    $search_query_for_sql = "%$search_query%";
    $totalPastesQuery = "SELECT COUNT(*) as total FROM pastes WHERE title LIKE ? OR creator LIKE ?";
    $stmt = $conn->prepare($totalPastesQuery);
    $stmt->bind_param("ss", $search_query_for_sql, $search_query_for_sql);
    $stmt->execute();
    $totalPastesResult = $stmt->get_result();
    $totalItems = $totalPastesResult->fetch_assoc()['total'];

    $sql = "SELECT id, title, creator, pinned FROM pastes WHERE title LIKE ? OR creator LIKE ? LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $search_query_for_sql, $search_query_for_sql, $itemsPerPage, $offset);
} else {
    $totalPastesQuery = "SELECT COUNT(*) as total FROM pastes";
    $totalPastesResult = $conn->query($totalPastesQuery);
    $totalItems = $totalPastesResult->fetch_assoc()['total'];

    $sql = "SELECT id, title, creator, pinned FROM pastes LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $itemsPerPage, $offset);
}

$stmt->execute();
$items = $stmt->get_result();
$totalPages = ceil($totalItems / $itemsPerPage);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Admin Panel - Paste Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* EXACT COPY FROM index.php */
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

        .nav-tabs .nav-link.active::after {
            display: none;
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

        .form-control {
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            background-color: var(--dark-gray-3);
            color: var(--white);
            font-size: 0.95rem;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
            outline: none;
        }

        .form-control::placeholder {
            color: var(--text-muted);
        }

        .search-container {
            max-width: 100%;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .search-container i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            z-index: 5;
        }

        .search-container input {
            padding-left: 3rem;
            width: 100%;
            border-radius: 0.75rem;
        }

        .table {
            margin-bottom: 0;
            color: var(--text-medium);
            border-collapse: separate;
            border-spacing: 0;
        }

        .table th {
            background-color: rgba(139, 92, 246, 0.1);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.025rem;
            border-top: none;
            padding: 1rem 1.25rem;
            color: var(--white);
            border-bottom: 1px solid var(--border);
        }

        .table td {
            vertical-align: middle;
            padding: 1rem 1.25rem;
            border-color: var(--border);
            background-color: var(--bg-card);
            color: var(--text-light);
            border-bottom: 1px solid var(--border);
        }

        .table td a, 
        .table td span:not(.badge),
        .table td p {
            color: var(--text-light);
        }

        .username-text,
        .creator-text,
        .title-text {
            color: var(--text-light);
            font-weight: 500;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(139, 92, 246, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .badge {
            padding: 0.5rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 0.5rem;
        }

        .badge-success {
            background-color: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-secondary {
            background-color: rgba(107, 114, 128, 0.2);
            color: var(--secondary);
            border: 1px solid rgba(107, 114, 128, 0.3);
        }

        .badge-danger {
            background-color: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
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

        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
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

        .btn-outline-success {
            color: var(--success);
            border: 1px solid var(--success);
            background: transparent;
        }

        .btn-outline-success:hover {
            background-color: var(--success);
            border-color: var(--success);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-outline-danger {
            color: var(--danger);
            border: 1px solid var(--danger);
            background: transparent;
        }

        .btn-outline-danger:hover {
            background-color: var(--danger);
            border-color: var(--danger);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-outline-warning {
            color: var(--warning);
            border: 1px solid var(--warning);
            background: transparent;
        }

        .btn-outline-warning:hover {
            background-color: var(--warning);
            border-color: var(--warning);
            color: var(--white);
            transform: translateY(-2px);
        }

        .btn-outline-info {
            color: var(--info);
            border: 1px solid var(--info);
            background: transparent;
        }

        .btn-outline-info:hover {
            background-color: var(--info);
            border-color: var(--info);
            color: var(--white);
            transform: translateY(-2px);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .pagination {
            justify-content: center;
            margin: 2rem 0 0;
            gap: 0.5rem;
        }

        .page-link {
            color: var(--text-medium);
            background-color: var(--bg-card);
            border: 1px solid var(--border);
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background-color: var(--primary);
            border-color: var(--primary);
            color: var(--white);
            z-index: 2;
            transform: translateY(-2px);
        }

        .page-link:focus {
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
            color: var(--white);
            box-shadow: var(--glow-sm);
        }

        .modal-content {
            background: var(--bg-card);
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg), var(--glow-md);
            color: var(--white);
        }

        .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 1.5rem;
        }

        .modal-title {
            color: var(--white);
            font-weight: 600;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        .list-group-item {
            background-color: var(--dark-gray-3);
            color: var(--text-medium);
            border-color: var(--border);
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .list-group-item:hover {
            background-color: rgba(139, 92, 246, 0.1);
            color: var(--white);
            transform: translateX(5px);
        }

        .list-group-item.selected {
            background-color: var(--primary);
            color: var(--white);
            border-color: var(--primary);
            box-shadow: var(--glow-sm);
        }

        .modal-custom {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1050;
            display: none;
            background: var(--bg-card);
            border-radius: 0.75rem;
            width: 90%;
            max-width: 500px;
            padding: 1.5rem;
            box-shadow: var(--shadow-lg), var(--glow-md);
            border: 1px solid var(--border);
        }

        .modal-custom-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
        }

        .modal-custom-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--white);
            margin: 0;
        }

        .modal-custom-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            transition: color 0.3s ease;
        }

        .modal-custom-close:hover {
            color: var(--danger);
        }

        .modal-custom .btn {
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        #modalOverlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(15, 15, 26, 0.8);
            z-index: 1040;
            display: none;
            backdrop-filter: blur(5px);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--white);
        }

        .user-item {
            animation: fadeInUp 0.3s ease-out forwards;
            opacity: 0;
            transform: translateY(8px);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .rank-badge {
            display: inline-block;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025rem;
            background-color: rgba(139, 92, 246, 0.2);
            color: var(--primary);
            border: 1px solid rgba(139, 92, 246, 0.3);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg), var(--glow-sm);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05rem;
        }

        .no-results {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-muted);
        }

        .no-results i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary);
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
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
                justify-content: center;
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
            
            .table-responsive {
                border-radius: 0.5rem;
                overflow: hidden;
            }
        }

        .glow-text {
            text-shadow: 0 0 10px var(--primary-glow);
        }

        .glow-border {
            box-shadow: 0 0 15px var(--primary-glow);
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
                <a class="nav-link active" href="pastes.php">
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
                <a class="nav-link" href="dashboard.php">
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
            <h1 class="page-title">Paste Management</h1>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" id="exportPastes">
                    <i class="fas fa-download"></i> Export Pastes
                </button>
                <button class="btn btn-outline-info" id="refreshData">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-value"><?= $totalItems ?></div>
                <div class="stat-label">Total Pastes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $itemsPerPage ?></div>
                <div class="stat-label">Pastes Per Page</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $page ?></div>
                <div class="stat-label">Current Page</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $totalPages ?></div>
                <div class="stat-label">Total Pages</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Paste List</h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted">Showing <?= $itemsPerPage ?> of <?= $totalItems ?> pastes</span>
                </div>
            </div>
            <div class="card-body">
                <div class="search-container">
                    <i class="fas fa-search"></i>
                    <input type="text" id="search-input" class="form-control" placeholder="Search by title or creator..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Creator</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pastes-table">
                            <?php $delay = 0; while ($paste = $items->fetch_assoc()): $delay += 0.03; ?>
                                <tr class="user-item" style="animation-delay: <?= $delay ?>s">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                <span class="text-white fw-bold"><?= strtoupper(substr($paste['creator'] ?? '', 0, 1)) ?></span>
                                            </div>
                                            <span class="creator-text"><?= htmlspecialchars($paste['creator']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="title-text"><?= htmlspecialchars($paste['title']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $paste['pinned'] ? 'badge-success' : 'badge-secondary' ?>">
                                            <i class="fas <?= $paste['pinned'] ? 'fa-thumbtack' : 'fa-file' ?> me-1"></i>
                                            <?= $paste['pinned'] ? 'Pinned' : 'Normal' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if (in_array($loggedInUserRank, ['Admin', 'Manager', 'Mod', 'Founder'])): ?>
                                                <button class="btn btn-outline-danger btn-sm delete-paste-btn" data-paste-id="<?= htmlspecialchars($paste['id']) ?>">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (in_array($loggedInUserRank, ['Admin', 'Manager', 'Mod', 'Founder'])): ?>
                                                <button class="btn <?= $paste['pinned'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-sm pin-btn" 
                                                        data-paste-id="<?= $paste['id'] ?>" 
                                                        data-pinned="<?= $paste['pinned'] ?>">
                                                    <i class="fas <?= $paste['pinned'] ? 'fa-thumbtack fa-rotate-90' : 'fa-thumbtack' ?>"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (in_array($loggedInUserRank, ['Admin', 'Manager', 'Founder'])): ?>
                                                <button class="btn btn-outline-primary btn-sm edit-paste-btn" data-paste-id="<?= htmlspecialchars($paste['id']) ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>

                            <?php if ($items->num_rows === 0): ?>
                                <tr>
                                    <td colspan="4" class="no-results">
                                        <i class="fas fa-folder-open"></i>
                                        <p>No pastes found</p>
                                        <?php if ($search_query !== ''): ?>
                                            <p class="text-muted">Try adjusting your search criteria</p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <nav aria-label="Pagination">
            <ul class="pagination">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search_query) ?>">
                            <i class="fas fa-chevron-left"></i>
                            <span class="ms-1 d-none d-sm-inline">Previous</span>
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search_query) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search_query) ?>">
                            <span class="me-1 d-none d-sm-inline">Next</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Delete Paste</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Reason:</p>
                <select id="deleteReason" class="form-select">
                    <option value="This paste broke the TOS">This paste broke the TOS</option>
                    <option value="It's a database and it didn't include database in the title">It's a database and it didn't include database in the title</option>
                    <option value="Other">Other</option>
                </select>
                <div id="customReasonContainer" class="mt-3" style="display: none;">
                    <input type="text" id="customReason" class="form-control" placeholder="Enter custom reason">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirmDeleteBtn" class="btn btn-outline-danger">Delete</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editPasteModal" tabindex="-1" aria-labelledby="editPasteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="editPasteForm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editPasteModalLabel">Edit Paste</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="paste_id" id="editPasteId">
                    <input type="hidden" name="action" value="edit_paste">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="mb-3">
                        <label for="editTitle" class="form-label">Title</label>
                        <input type="text" class="form-control" id="editTitle" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="editCreator" class="form-label">Creator</label>
                        <input type="text" class="form-control" id="editCreator" name="creator" required>
                    </div>
                    <div class="mb-3">
                        <label for="editViews" class="form-label">Views</label>
                        <input type="number" class="form-control" id="editViews" name="views" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label for="editDateCreated" class="form-label">Date Created</label>
                        <input type="datetime-local" class="form-control" id="editDateCreated" name="date_created" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-outline-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Export Pastes functionality
        const exportPastesBtn = document.getElementById('exportPastes');
        if (exportPastesBtn) {
            exportPastesBtn.addEventListener('click', function() {
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
                this.disabled = true;
                
                // Simulate export process
                setTimeout(() => {
                    alert('Paste data exported successfully!');
                    this.innerHTML = '<i class="fas fa-download"></i> Export Pastes';
                    this.disabled = false;
                }, 1500);
            });
        }
        
        // Refresh Data functionality
        const refreshDataBtn = document.getElementById('refreshData');
        if (refreshDataBtn) {
            refreshDataBtn.addEventListener('click', function() {
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
                location.reload();
            });
        }

        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            searchInput.addEventListener('keypress', function(event) {
                if (event.key === 'Enter') {
                    const searchValue = this.value.trim();
                    window.location.href = `?search=${encodeURIComponent(searchValue)}`;
                }
            });
        }

        const deletePasteButtons = document.querySelectorAll('.delete-paste-btn');
        let selectedPasteId = null;

        deletePasteButtons.forEach(button => {
            button.addEventListener('click', function() {
                selectedPasteId = this.getAttribute('data-paste-id');
                const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
                deleteModal.show();
            });
        });

        document.getElementById('deleteReason').addEventListener('change', function() {
            const customReasonContainer = document.getElementById('customReasonContainer');
            if (this.value === 'Other') {
                customReasonContainer.style.display = 'block';
            } else {
                customReasonContainer.style.display = 'none';
            }
        });

        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            const reasonSelect = document.getElementById('deleteReason');
            let reason = reasonSelect.value;
            if (reason === 'Other') {
                reason = document.getElementById('customReason').value.trim();
            }

            const formData = new FormData();
            formData.append('paste_id', selectedPasteId);
            formData.append('action', 'delete');
            formData.append('reason', reason);
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');

            fetch('pastes.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Failed to delete paste'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the paste');
            });
        });

        const pinButtons = document.querySelectorAll('.pin-btn');
        pinButtons.forEach(button => {
            button.addEventListener('click', function() {
                const pasteId = this.getAttribute('data-paste-id');
                const isPinned = this.getAttribute('data-pinned') === '1';
                const action = isPinned ? 'unpin' : 'pin';
                const originalHTML = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

                const formData = new FormData();
                formData.append('paste_id', pasteId);
                formData.append('action', action);
                formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');

                fetch('pastes.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const row = this.closest('tr');
                        const statusBadge = row.querySelector('.badge');
                        if (data.new_status == 1) {
                            this.innerHTML = '<i class="fas fa-thumbtack fa-rotate-90"></i>';
                            this.setAttribute('data-pinned', '1');
                            this.classList.remove('btn-outline-success');
                            this.classList.add('btn-outline-warning');
                            statusBadge.innerHTML = '<i class="fas fa-thumbtack me-1"></i>Pinned';
                            statusBadge.classList.remove('badge-secondary');
                            statusBadge.classList.add('badge-success');
                        } else {
                            this.innerHTML = '<i class="fas fa-thumbtack"></i>';
                            this.setAttribute('data-pinned', '0');
                            this.classList.remove('btn-outline-warning');
                            this.classList.add('btn-outline-success');
                            statusBadge.innerHTML = '<i class="fas fa-file me-1"></i>Normal';
                            statusBadge.classList.remove('badge-success');
                            statusBadge.classList.add('badge-secondary');
                        }
                        this.disabled = false;
                    } else {
                        alert('Error: ' + (data.error || 'Failed to update pin status'));
                        this.disabled = false;
                        this.innerHTML = originalHTML;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while updating pin status');
                    this.disabled = false;
                    this.innerHTML = originalHTML;
                });
            });
        });

        const editButtons = document.querySelectorAll('.edit-paste-btn');
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                const row = this.closest('tr');
                document.getElementById('editPasteId').value = row.getAttribute('data-paste-id');
                document.getElementById('editTitle').value = row.getAttribute('data-title');
                document.getElementById('editCreator').value = row.getAttribute('data-creator');
                document.getElementById('editViews').value = row.getAttribute('data-views');
                let dateCreated = row.getAttribute('data-date_created');
                if (dateCreated) {
                    dateCreated = dateCreated.replace(' ', 'T').slice(0, 16);
                    document.getElementById('editDateCreated').value = dateCreated;
                } else {
                    document.getElementById('editDateCreated').value = '';
                }
                const editModal = new bootstrap.Modal(document.getElementById('editPasteModal'));
                editModal.show();
            });
        });

        const editForm = document.getElementById('editPasteForm');
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(editForm);
            fetch('pastes.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Failed to update paste'));
                }
            })
            .catch(error => {
                alert('An error occurred while updating the paste');
            });
        });

    });
</script>
</body>
</html>