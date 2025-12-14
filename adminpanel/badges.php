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

if (!$userData || !in_array($userData['rank'], ['Admin', 'Manager', 'Mod', 'Founder'])) {
    header('Location: /login');
    exit();
}

$username = $userData['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (
        empty($_SESSION['csrf_token']) ||
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    
    if (isset($_POST['name'], $_POST['description'])) {
        $name = htmlspecialchars(trim($_POST['name']));
        $description = htmlspecialchars(trim($_POST['description']));
        $image_url = htmlspecialchars(trim($_POST['image_url'] ?? ''));

        
        if (strlen($name) < 1 || strlen($name) > 64) {
            $errorMessage = "Badge name must be between 1 and 64 characters.";
        } elseif (strlen($description) < 1 || strlen($description) > 255) {
            $errorMessage = "Description must be between 1 and 255 characters.";
        } elseif (!empty($image_url) && !filter_var($image_url, FILTER_VALIDATE_URL)) {
            $errorMessage = "Invalid image URL.";
        } else {
            
            if (!empty($image_url)) {
                $stmt = $conn->prepare("INSERT INTO badges (name, description, image_url) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $name, $description, $image_url);
                if ($stmt->execute()) {
                    $successMessage = "Badge added successfully!";
                } else {
                    $errorMessage = "Failed to add badge.";
                }
            } elseif (isset($_FILES['badge_image']) && $_FILES['badge_image']['error'] === 0) {
                $uploadDir = '../uploads/badges/';
                $allowedTypes = ['image/png', 'image/jpeg'];
                $file = $_FILES['badge_image'];
                $fileType = mime_content_type($file['tmp_name']);
                $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

              
                if ($file['size'] > 2 * 1024 * 1024) {
                    $errorMessage = "File too large. Max 2MB allowed.";
                } elseif (in_array($fileType, $allowedTypes) && in_array($fileExtension, ['png', 'jpg', 'jpeg'])) {
                    require_once __DIR__ . '/../includes/r2-helper.php';
                    
                    // Generate a unique file key for R2
                    $fileKey = 'badges/' . uniqid('badge_', true) . '.' . $fileExtension;
                    
                    // Upload to R2
                    $r2 = R2Helper::getInstance();
                    $publicUrl = $r2->uploadFile(
                        $file['tmp_name'],
                        $fileKey,
                        $fileType
                    );

                    if ($publicUrl) {
                        $image_url = $fileKey; // Store the R2 key
                        $stmt = $conn->prepare("INSERT INTO badges (name, description, image_url) VALUES (?, ?, ?)");
                        $stmt->bind_param("sss", $name, $description, $image_url);
                        if ($stmt->execute()) {
                            $successMessage = "Badge added successfully!";
                        } else {
                            $errorMessage = "Failed to add badge.";
                        }
                    } else {
                        $errorMessage = "Failed to upload image.";
                    }
                } else {
                    $errorMessage = "Invalid file type. Only PNG and JPG are allowed.";
                }
            } else {
                $errorMessage = "You must provide either an image URL or upload an image.";
            }
        }
    } elseif (isset($_POST['assign_badge'])) {
        $username = htmlspecialchars(trim($_POST['username']));
        $badge_id = intval($_POST['badge_id']);

       
        if ($badge_id <= 0) {
            $errorMessage = "Invalid badge selected.";
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $userResult = $stmt->get_result();
            if ($userResult->num_rows > 0) {
                $userId = $userResult->fetch_assoc()['id'];
                
                $stmt = $conn->prepare("SELECT 1 FROM user_badges WHERE user_id = ? AND badge_id = ?");
                $stmt->bind_param("ii", $userId, $badge_id);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $errorMessage = "User already has this badge.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO user_badges (user_id, badge_id) VALUES (?, ?)");
                    $stmt->bind_param("ii", $userId, $badge_id);
                    if ($stmt->execute()) {
                        $successMessage = "Badge assigned successfully!";
                    } else {
                        $errorMessage = "Failed to assign badge.";
                    }
                }
            } else {
                $errorMessage = "User not found.";
            }
        }
    } elseif (isset($_POST['edit_badge'])) {
        $badge_id = intval($_POST['badge_id']);
        $name = htmlspecialchars(trim($_POST['name']));
        $description = htmlspecialchars(trim($_POST['description']));
        $image_url = htmlspecialchars(trim($_POST['image_url']));

       
        if ($badge_id <= 0) {
            $errorMessage = "Invalid badge ID.";
        } elseif (strlen($name) < 1 || strlen($name) > 64) {
            $errorMessage = "Badge name must be between 1 and 64 characters.";
        } elseif (strlen($description) < 1 || strlen($description) > 255) {
            $errorMessage = "Description must be between 1 and 255 characters.";
        } elseif (!empty($image_url) && !filter_var($image_url, FILTER_VALIDATE_URL)) {
            $errorMessage = "Invalid image URL.";
        } else {
            $stmt = $conn->prepare("UPDATE badges SET name = ?, description = ?, image_url = ? WHERE id = ?");
            $stmt->bind_param("sssi", $name, $description, $image_url, $badge_id);
            if ($stmt->execute()) {
                $successMessage = "Badge updated successfully!";
            } else {
                $errorMessage = "Failed to update badge.";
            }
        }
    } elseif (isset($_POST['remove_badge'])) {
        $username = htmlspecialchars(trim($_POST['username']));
        $badge_name = htmlspecialchars(trim($_POST['badge_name']));

        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $userResult = $stmt->get_result();
        if ($userResult->num_rows > 0) {
            $userId = $userResult->fetch_assoc()['id'];
            $stmt = $conn->prepare("SELECT id FROM badges WHERE name = ?");
            $stmt->bind_param("s", $badge_name);
            $stmt->execute();
            $badgeResult = $stmt->get_result();
            if ($badgeResult->num_rows > 0) {
                $badgeId = $badgeResult->fetch_assoc()['id'];
                $stmt = $conn->prepare("DELETE FROM user_badges WHERE user_id = ? AND badge_id = ?");
                $stmt->bind_param("ii", $userId, $badgeId);
                if ($stmt->execute()) {
                    $successMessage = "Badge removed successfully!";
                } else {
                    $errorMessage = "Failed to remove badge.";
                }
            } else {
                $errorMessage = "Badge not found.";
            }
        } else {
            $errorMessage = "User not found.";
        }
    } elseif (isset($_POST['delete_badge_id'])) {
        $badge_id = intval($_POST['delete_badge_id']);

     
        if ($badge_id <= 0) {
            $errorMessage = "Invalid badge ID.";
        } else {
            $stmt = $conn->prepare("DELETE FROM badges WHERE id = ?");
            $stmt->bind_param("i", $badge_id);
            if ($stmt->execute()) {
                $successMessage = "Badge deleted successfully!";
            } else {
                $errorMessage = "Failed to delete badge.";
            }
        }
    }
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$badgesQuery = "SELECT * FROM badges ORDER BY created_at DESC";
$badgesResult = $conn->query($badgesQuery);

$allBadgesQuery = "SELECT id, name FROM badges ORDER BY created_at DESC";
$allBadgesResult = $conn->query($allBadgesQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Admin Panel - Badges</title>
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

        .badge-item {
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

        #drop-zone {
            transition: box-shadow 0.3s ease, border-color 0.3s ease;
            border: 2px dashed var(--border);
            border-radius: 0.5rem;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            background: rgba(139, 92, 246, 0.05);
        }

        #drop-zone:hover {
            background: rgba(139, 92, 246, 0.1);
        }

        #drop-zone.glow {
            box-shadow: 0 0 15px var(--primary-glow);
            border-color: var(--primary);
        }

        .alert {
            border-radius: 0.5rem;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background-color: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .badge-image-preview {
            max-width: 100px;
            max-height: 100px;
            border-radius: 0.5rem;
            object-fit: cover;
            border: 1px solid var(--border);
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
                <a class="nav-link" href="delete_logs.php">
                    <i class="fas fa-history"></i> Delete Logs
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="badges.php">
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
            <h1 class="page-title">Manage Badges</h1>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-info" id="refreshData">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <?php if (isset($successMessage)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?= $successMessage ?>
            </div>
        <?php elseif (isset($errorMessage)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?= $errorMessage ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">Create New Badge</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <div class="mb-3">
                                <label for="name" class="form-label">Badge Name</label>
                                <input type="text" id="name" name="name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea id="description" name="description" class="form-control" rows="3" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="image_url" class="form-label">Image URL</label>
                                <input type="url" id="image_url" name="image_url" class="form-control" placeholder="https://example.com/badge.png">
                                <div class="text-muted text-center my-2">OR</div>
                                <label for="badge_image" class="form-label">Upload Image</label>
                                <div id="drop-zone" class="p-4">
                                    <i class="fas fa-cloud-upload-alt fa-2x mb-3 text-primary"></i>
                                    <p class="mb-0">Drag and drop an image here, or click to select a file.</p>
                                    <p class="small text-muted mt-1">PNG or JPG, max 2MB</p>
                                    <input type="file" id="badge_image" name="badge_image" class="form-control d-none" accept=".png, .jpg, .jpeg">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-plus"></i> Create Badge
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">Assign Badge to User</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <div class="mb-3">
                                <label for="assign_username" class="form-label">Username</label>
                                <input type="text" id="assign_username" name="username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label for="badge_id" class="form-label">Badge</label>
                                <select id="badge_id" name="badge_id" class="form-control" required>
                                    <?php while ($badge = $allBadgesResult->fetch_assoc()): ?>
                                        <option value="<?= $badge['id'] ?>"><?= htmlspecialchars($badge['name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <button type="submit" name="assign_badge" class="btn btn-outline-primary w-100">
                                <i class="fas fa-user-tag"></i> Assign Badge
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title">Remove Badge from User</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="removeBadgeForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <div class="mb-3">
                                <label for="remove_username" class="form-label">Username</label>
                                <input type="text" id="remove_username" name="username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label for="badge_name" class="form-label">Badge Name</label>
                                <input type="text" id="badge_name" name="badge_name" class="form-control" required>
                            </div>
                            <button type="submit" name="remove_badge" class="btn btn-outline-danger w-100">
                                <i class="fas fa-user-minus"></i> Remove Badge
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h5 class="card-title">Existing Badges</h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted"><?= $badgesResult->num_rows ?> badges</span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Image</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $delay = 0; while ($badge = $badgesResult->fetch_assoc()): $delay += 0.03; ?>
                                <tr class="badge-item" style="animation-delay: <?= $delay ?>s">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <td>
                                            <input type="text" name="name" value="<?= htmlspecialchars($badge['name']) ?>" class="form-control">
                                        </td>
                                        <td>
                                            <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($badge['description']) ?></textarea>
                                        </td>
                                        <td>
                                            <input type="url" name="image_url" value="<?= htmlspecialchars($badge['image_url']) ?>" class="form-control">
                                            <?php if (!empty($badge['image_url'])): ?>
                                                <div class="mt-2">
                                                    <img src="../<?= htmlspecialchars($badge['image_url']) ?>" alt="Badge Image" class="badge-image-preview">
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <input type="hidden" name="badge_id" value="<?= $badge['id'] ?>">
                                                <button type="submit" name="edit_badge" class="btn btn-outline-primary btn-sm">
                                                    <i class="fas fa-save"></i> Save
                                                </button>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="delete_badge_id" value="<?= $badge['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to delete this badge?')">
                                                        <i class="fas fa-trash-alt"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </form>
                                </tr>
                            <?php endwhile; ?>

                            <?php if ($badgesResult->num_rows === 0): ?>
                                <tr>
                                    <td colspan="4" class="no-results">
                                        <i class="fas fa-award"></i>
                                        <p>No badges found</p>
                                        <p class="text-muted">Create your first badge to get started</p>
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
    document.addEventListener('DOMContentLoaded', function () {
        const refreshDataBtn = document.getElementById('refreshData');
        if (refreshDataBtn) {
            refreshDataBtn.addEventListener('click', function() {
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
                location.reload();
            });
        }

        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('badge_image');

        dropZone.addEventListener('click', () => fileInput.click());

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('glow');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('glow');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('glow');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                const fileName = e.dataTransfer.files[0].name;
                dropZone.querySelector('p').textContent = `File selected: ${fileName}`;
            }
        });

        fileInput.addEventListener('change', function() {
            if (this.files.length) {
                const fileName = this.files[0].name;
                dropZone.querySelector('p').textContent = `File selected: ${fileName}`;
            }
        });

        
        const removeUsernameInput = document.getElementById('remove_username');
        const badgeNameInput = document.getElementById('badge_name');

        removeUsernameInput.addEventListener('input', function () {
            const username = this.value.trim();

            if (username.length > 0) {
                fetch(`fetch_user_badges.php?username=${encodeURIComponent(username)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.badges.length > 0) {
                            const badgeNames = data.badges.map(badge => badge.name);
                            badgeNameInput.setAttribute('list', 'badgeNamesList');
                            let dataList = document.getElementById('badgeNamesList');
                            if (!dataList) {
                                dataList = document.createElement('datalist');
                                dataList.id = 'badgeNamesList';
                                document.body.appendChild(dataList);
                            }
                            dataList.innerHTML = badgeNames.map(name => `<option value="${name}">`).join('');
                        } else {
                            badgeNameInput.removeAttribute('list');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching badges:', error);
                        badgeNameInput.removeAttribute('list');
                    });
            } else {
                badgeNameInput.removeAttribute('list');
            }
        });
    });
</script>
</body>
</html>