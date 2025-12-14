<?php
session_start();
include('../database.php');
include_once(__DIR__ . '/admin_2fa_gate.php');

// --- SECURITY: Generate CSRF token for forms ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['login_token']) || empty($_SESSION['login_token'])) {
    header('Location: /login');
    exit();
}

$loginToken = $_SESSION['login_token'];

$query = "SELECT username, rank FROM users WHERE login_token = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $loginToken);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();

if (!$userData || !in_array($userData['rank'], ['Admin', 'Manager', 'Founder'])) {
    header('Location: /login');
    exit();
}

$username = $userData['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- SECURITY: CSRF Protection ---
    if (
        empty($_SESSION['csrf_token']) ||
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $successMessage = null; 
    $errorMessage = null;   

    // --- FIX: Support multiple deletions via array ---
    if (isset($_POST['delete_link_id'])) {
        $ids = $_POST['delete_link_id'];
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $stmt = $conn->prepare("DELETE FROM links WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $successMessage = "Link deleted successfully!";
                } else {
                    $errorMessage = "Failed to delete link.";
                }
            }
        }
    } elseif (isset($_POST['text'], $_POST['href'], $_POST['order']) && 
        is_array($_POST['text']) && is_array($_POST['href']) && is_array($_POST['order'])) {
        foreach ($_POST['text'] as $index => $text) {
            if (isset($_POST['id'][$index]) && is_numeric($_POST['id'][$index])) {
                $id = intval($_POST['id'][$index]);
                $text = htmlspecialchars(trim($text));
                $href = htmlspecialchars(trim($_POST['href'][$index]));
                $color = htmlspecialchars(trim($_POST['color'][$index] ?? '#000000'));
                $font_size = htmlspecialchars(trim($_POST['font_size'][$index] ?? '16px'));
                $order = intval($_POST['order'][$index]);

                // --- SECURITY: Validate URL and color ---
                if (!filter_var($href, FILTER_VALIDATE_URL)) {
                    $errorMessage = "Invalid URL for one or more links.";
                    continue;
                }
                if (strlen($color) > 128 || strlen($font_size) > 16) {
                    $errorMessage = "Color or font size too long.";
                    continue;
                }

                $stmt = $conn->prepare("UPDATE links SET text = ?, href = ?, color = ?, font_size = ?, `order` = ? WHERE id = ?");
                $stmt->bind_param("ssssii", $text, $href, $color, $font_size, $order, $id);
                if (!$stmt->execute()) {
                    $errorMessage = "Failed to update some links.";
                }
            }
        }
        if (!$errorMessage) {
            $successMessage = "All links updated successfully!";
        }
    } elseif (isset($_POST['text'], $_POST['href'], $_POST['color'], $_POST['font_size'], $_POST['order']) &&
              !is_array($_POST['text']) && !is_array($_POST['href']) && !is_array($_POST['order'])) {
        $text = htmlspecialchars(trim($_POST['text']));
        $href = htmlspecialchars(trim($_POST['href']));
        $color = htmlspecialchars(trim($_POST['color'] ?? '#000000'));
        $font_size = htmlspecialchars(trim($_POST['font_size'] ?? '16px'));
        $order = intval($_POST['order']);

        // --- SECURITY: Validate URL and color ---
        if (!filter_var($href, FILTER_VALIDATE_URL)) {
            $errorMessage = "Invalid URL.";
        } elseif (strlen($color) > 128 || strlen($font_size) > 16) {
            $errorMessage = "Color or font size too long.";
        } else {
            $stmt = $conn->prepare("INSERT INTO links (text, href, color, font_size, `order`) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $text, $href, $color, $font_size, $order);
            if ($stmt->execute()) {
                $successMessage = "Link added successfully!";
            } else {
                $errorMessage = "Failed to add the link.";
            }
        }
    }
}

$linksQuery = "SELECT * FROM links ORDER BY `order` ASC";
$linksResult = $conn->query($linksQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Admin Panel - Link Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .table td, .table th {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .table th {
            width: 20%;
        }

        .table td:nth-child(1) { 
            width: 10%;
        }

        .table td:nth-child(2) { 
            width: 25%;
        }

        .table td:nth-child(3) { 
            width: 35%;
        }

        .table td:nth-child(4), .table td:nth-child(5) { 
            width: 15%;
        }

        .table td:nth-child(6) { 
            width: 25%; 
        }

        .draggable {
            cursor: grab;
        }

        .dragging {
            opacity: 0.5;
        }

        .placeholder {
            background-color: var(--gray-2);
            height: 50px;
            border: 2px dashed var(--primary);
        }

        .gradient-preview {
            height: 30px;
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 4px;
            margin-top: 5px;
        }

        .invalid-color {
            border-color: var(--danger) !important;
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
                <a class="nav-link active" href="links.php">
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
            <h1 class="page-title">Manage Links</h1>
        </div>

        <?php if (isset($successMessage)): ?>
            <div class="alert alert-success"><?= $successMessage ?></div>
        <?php elseif (isset($errorMessage)): ?>
            <div class="alert alert-danger"><?= $errorMessage ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="mb-3">
                        <label for="text" class="form-label">Link Text</label>
                        <input type="text" id="text" name="text" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="href" class="form-label">Link URL</label>
                        <input type="url" id="href" name="href" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="color" class="form-label">Color (or Gradient)</label>
                        <input type="text" id="color" name="color" class="form-control gradient-input" placeholder="e.g., #ff0000 or linear-gradient(to right, red, blue)" required>
                        <div class="gradient-preview"></div>
                    </div>
                    <div class="mb-3">
                        <label for="font_size" class="form-label">Font Size</label>
                        <input type="text" id="font_size" name="font_size" class="form-control" placeholder="e.g., 16px">
                    </div>
                    <div class="mb-3">
                        <label for="order" class="form-label">Order</label>
                        <input type="number" id="order" name="order" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Link
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-body">
                <h5 class="card-title">Edit Existing Links</h5>
                <form id="saveAllForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-bars"></i></th>
                                    <th>Text</th>
                                    <th>URL</th>
                                    <th>Color</th>
                                    <th>Font Size</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($link = $linksResult->fetch_assoc()): ?>
                                    <tr class="draggable" draggable="true">
                                        <input type="hidden" name="order[]" value="<?= htmlspecialchars($link['order']) ?>">
                                        <input type="hidden" name="id[]" value="<?= $link['id'] ?>">
                                        <td><i class="fas fa-bars"></i></td>
                                        <td><input type="text" name="text[]" value="<?= htmlspecialchars($link['text']) ?>" class="form-control"></td>
                                        <td><input type="url" name="href[]" value="<?= htmlspecialchars($link['href']) ?>" class="form-control"></td>
                                        <td>
                                            <input type="text" name="color[]" value="<?= htmlspecialchars($link['color']) ?>" class="form-control gradient-input" placeholder="e.g., #ff0000 or linear-gradient(to right, red, blue)">
                                            <div class="gradient-preview" style="background: <?= htmlspecialchars($link['color']) ?>"></div>
                                        </td>
                                        <td><input type="text" name="font_size[]" value="<?= htmlspecialchars($link['font_size']) ?>" class="form-control"></td>
                                        <td>
                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteRow(this, <?= $link['id'] ?>)">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>

                                <?php if ($linksResult->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="fas fa-folder-open fa-2x mb-3 text-muted"></i>
                                            <p>No links found</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" id="saveAllButton" class="btn btn-primary mt-3">
                        <i class="fas fa-save"></i> Save All
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const tableBody = document.querySelector('tbody');
        let draggedRow = null;
        let placeholder = document.createElement('tr');
        placeholder.classList.add('placeholder');

        tableBody.addEventListener('dragstart', (e) => {
            if (e.target.tagName === 'TR') {
                draggedRow = e.target;
                e.target.classList.add('dragging');
                tableBody.insertBefore(placeholder, draggedRow.nextSibling);
            }
        });

        tableBody.addEventListener('dragend', (e) => {
            if (e.target.tagName === 'TR') {
                e.target.classList.remove('dragging');
                placeholder.remove();
            }
        });

        tableBody.addEventListener('dragover', (e) => {
            e.preventDefault();
            const targetRow = e.target.closest('tr');
            if (targetRow && targetRow !== draggedRow && targetRow !== placeholder) {
                const bounding = targetRow.getBoundingClientRect();
                const offset = e.clientY - bounding.top;
                const height = bounding.height / 2;
                if (offset > height) {
                    targetRow.after(placeholder);
                } else {
                    targetRow.before(placeholder);
                }
            }
        });

        tableBody.addEventListener('drop', () => {
            if (placeholder.parentNode) {
                placeholder.replaceWith(draggedRow);
                updateOrderInputs();
            }
        });

        function updateOrderInputs() {
            const rows = tableBody.querySelectorAll('tr');
            rows.forEach((row, index) => {
                const orderInput = row.querySelector('input[name="order[]"]');
                if (orderInput) {
                    orderInput.value = index + 1;
                }
            });
        }

        document.getElementById('saveAllButton').addEventListener('click', () => {
            document.getElementById('saveAllForm').submit();
        });

        function updateColorPreview(input) {
            const preview = input.nextElementSibling;
            if (preview && preview.classList.contains('gradient-preview')) {
                const value = input.value.trim();
                if (isValidColorOrGradient(value)) {
                    preview.style.background = value;
                    input.classList.remove('invalid-color');
                } else {
                    preview.style.background = 'none';
                    input.classList.add('invalid-color');
                }
            }
        }

        function isValidColorOrGradient(value) {
            const s = new Option().style;
            s.background = value;
            return s.background !== '';
        }

        document.querySelectorAll('.gradient-input').forEach(input => {
            input.addEventListener('input', () => updateColorPreview(input));
            updateColorPreview(input); 
        });
    });

    function deleteRow(button, id) {
        const row = button.closest('tr');
        row.remove();
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_link_id[]';
        input.value = id;
        document.getElementById('saveAllForm').appendChild(input);
        // Submit the form immediately after delete
        document.getElementById('saveAllForm').submit();
    }
</script>
</body>
</html>