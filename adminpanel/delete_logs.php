<?php
session_start();
include('../database.php');
include_once(__DIR__ . '/admin_2fa_gate.php');

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

if (!$userData || !in_array($userData['rank'], ['Admin', 'Manager', 'Mod', 'Council', 'Founder'])) {
    header('Location: /login');
    exit();
}

$username = $userData['username'];

$logsQuery = "SELECT * FROM delete_logs ORDER BY created_at DESC";
$logsResult = $conn->query($logsQuery);

// --- SECURITY: Generate CSRF token for forms (if you add any forms in the future) ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Admin Panel - Delete Logs</title>
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

        .log-item {
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
                <a class="nav-link" href="pastes.php">
                    <i class="fas fa-file-code"></i> Pastes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="delete_logs.php">
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
            <h1 class="page-title">Delete Logs</h1>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-info" id="refreshData">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Delete History</h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted"><?= $logsResult->num_rows ?> log entries</span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Deleted By</th>
                                <th>Paste Title</th>
                                <th>Paste Creator</th>
                                <th>Reason</th>
                                <th>Deleted At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $delay = 0; while ($log = $logsResult->fetch_assoc()): $delay += 0.03; ?>
                                <tr class="log-item" style="animation-delay: <?= $delay ?>s">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                <span class="text-white fw-bold"><?= strtoupper(substr($log['deleted_by'] ?? '', 0, 1)) ?></span>
                                            </div>
                                            <span class="username-text"><?= htmlspecialchars($log['deleted_by'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </td>
                                    <td class="title-text"><?= htmlspecialchars($log['paste_title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="creator-text"><?= htmlspecialchars($log['paste_creator'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($log['reason'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endwhile; ?>

                            <?php if ($logsResult->num_rows === 0): ?>
                                <tr>
                                    <td colspan="5" class="no-results">
                                        <i class="fas fa-folder-open"></i>
                                        <p>No logs found</p>
                                        <p class="text-muted">All delete actions will be logged here</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Refresh Data functionality
        const refreshDataBtn = document.getElementById('refreshData');
        if (refreshDataBtn) {
            refreshDataBtn.addEventListener('click', function() {
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
                location.reload();
            });
        }
    });
</script>
</body>
</html>