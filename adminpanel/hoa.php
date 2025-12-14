<?php
session_start();
include('../database.php');
include_once(__DIR__ . '/admin_2fa_gate.php');

if (isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
} elseif (isset($_COOKIE['username'])) {
    $_SESSION['username'] = $_COOKIE['username'];
    $username = $_COOKIE['username'];
} else {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$query = "SELECT rank, login_token FROM users WHERE username = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();

if (!$userData || empty($userData['login_token'])) {
    header('Location: /login');
    exit();
}

$loggedInUserRank = $userData['rank'] ?? 'All Users';

if (!in_array($loggedInUserRank, ['Admin', 'Manager', 'Mod', 'Council', 'Founder'])) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = $_POST['delete_id'];
    
    // Get the image path before deleting the record
    $getImageQuery = "SELECT picture FROM hoa WHERE id = ?";
    $stmt = $conn->prepare($getImageQuery);
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $imageResult = $stmt->get_result();
    
    if ($imageResult->num_rows > 0) {
        $imageData = $imageResult->fetch_assoc();
        $imagePath = $imageData['picture'];
        
        // Delete the image file if it exists and is not the default
        if (!empty($imagePath) && $imagePath !== 'default.png' && file_exists($imagePath)) {
            unlink($imagePath);
        }
    }
    $stmt->close();
    
    // Delete the database record
    $deleteQuery = "DELETE FROM hoa WHERE id = ?";
    $stmt = $conn->prepare($deleteQuery);
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $stmt->close();
}

$sql = "SELECT id, picture, username, about_me, link FROM hoa ORDER BY id DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Admin Panel - Hall of Autism</title>
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
            flex-wrap: wrap;
            gap: 1rem;
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

        .btn-success {
            background-color: var(--success);
            color: var(--white);
        }

        .btn-success:hover {
            background-color: var(--success-hover);
            transform: translateY(-2px);
        }

        .btn-danger {
            background-color: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            background-color: var(--danger-hover);
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

        .user-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .user-card {
            background: var(--bg-card);
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .user-card:hover {
            box-shadow: var(--shadow-lg), var(--glow-sm);
            transform: translateY(-5px);
        }

        .user-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--info));
        }

        .user-avatar {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border);
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            background: var(--dark-gray-3);
        }

        .user-card:hover .user-avatar {
            border-color: var(--primary);
            box-shadow: var(--glow-sm);
        }

        .user-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .user-bio {
            color: var(--text-medium);
            margin-bottom: 1rem;
            min-height: 60px;
        }

        .user-link {
            color: var(--info);
            font-weight: 500;
            text-decoration: none;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .user-link:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .user-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }

        .form-card {
            background: var(--bg-card);
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            padding: 1.5rem;
            max-width: 500px;
            margin: 0 auto 2rem;
            transition: all 0.3s ease;
        }

        .form-card:hover {
            box-shadow: var(--shadow-lg), var(--glow-sm);
        }

        .form-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 1.5rem;
            text-align: center;
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

        .image-upload-container {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .image-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border);
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 0 auto;
            display: block;
            background: var(--dark-gray-3);
        }

        .image-preview:hover {
            border-color: var(--primary);
            box-shadow: var(--glow-sm);
        }

        .file-input {
            display: none;
        }

        .divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 2rem 0;
        }

        .no-data {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-muted);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .glow-text {
            text-shadow: 0 0 10px var(--primary-glow);
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
            
            .user-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .user-grid {
                grid-template-columns: 1fr;
            }
            
            .form-card {
                padding: 1rem;
            }
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
                <a class="nav-link active" href="hoa.php">
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
            <h1 class="page-title">Hall of Autism</h1>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-info" id="refreshData">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Manage Hall of Autism Members</h5>
                <button class="btn btn-primary" id="new-btn">
                    <i class="fas fa-plus"></i> Add New Member
                </button>
            </div>
            <div class="card-body">
                <div id="form-container"></div>
                
                <hr class="divider">
                
                <form method="POST" action="hoa.php">
                    <div class="user-grid">
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <div class="user-card">
                                    <?php
										$imgPath = $row['picture'];
										// Use the full path for both file_exists check and src attribute
										if (!empty($imgPath) && file_exists($imgPath)) {
											echo '<img src="' . htmlspecialchars($imgPath) . '" alt="' . htmlspecialchars($row['username']) . '" class="user-avatar">';
										} else {
											// Use a path that's accessible from your current directory structure
											echo '<img src="../default.png" alt="Default Avatar" class="user-avatar">';
										}

                                    ?>
                                    <h3 class="user-name"><?= htmlspecialchars($row['username']) ?></h3>
                                    <p class="user-bio"><?= htmlspecialchars($row['about_me']) ?></p>
                                    
                                    <?php
                                    $link = filter_var($row['link'], FILTER_VALIDATE_URL) ? $row['link'] : "#";
                                    ?>
                                    <a href="<?= htmlspecialchars($link) ?>" target="_blank" rel="noopener noreferrer" class="user-link">
                                        <i class="fas fa-external-link-alt"></i> View Profile
                                    </a>
                                    
                                    <div class="user-actions">
                                        <button type="submit" name="delete_id" value="<?= (int)$row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this member? This will also delete their image file.')">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-users"></i>
                                <p>No members found in the Hall of Autism</p>
                                <p class="text-muted">Click "Add New Member" to get started</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const newButton = document.getElementById('new-btn');
        const formContainer = document.getElementById('form-container');
        const refreshButton = document.getElementById('refreshData');
        
        // Refresh functionality
        if (refreshButton) {
            refreshButton.addEventListener('click', function() {
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
                location.reload();
            });
        }
        
        // New member form functionality
        newButton.addEventListener('click', () => {
            if (!formContainer.innerHTML.trim()) {
                formContainer.innerHTML = `
                    <div class="form-card">
                        <h3 class="form-title">Add New Member</h3>
                        <form method="POST" action="save_hoa.php" enctype="multipart/form-data">
                            <div class="image-upload-container">
                                <input type="file" id="file-input" name="picture" accept="image/*" class="file-input">
                                <img id="preview-img" src="default.png" alt="Preview" class="image-preview">
                                <p class="text-muted mt-2">Click the image to upload a new one</p>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="username">Name</label>
                                <input type="text" id="username" name="username" class="form-control" placeholder="Enter member name..." required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="about_me">About</label>
                                <input type="text" id="about_me" name="about_me" class="form-control" placeholder="Enter member description..." required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="link">Profile Link</label>
                                <input type="text" id="link" name="link" class="form-control" placeholder="Enter profile link..." required>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-save"></i> Save Member
                                </button>
                                <button type="button" id="cancel-form" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                `;
                
                // Set up image preview functionality
                const fileInput = document.getElementById('file-input');
                const previewImg = document.getElementById('preview-img');
                
                previewImg.addEventListener('click', () => fileInput.click());
                
                fileInput.addEventListener('change', (event) => {
                    const file = event.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            previewImg.src = e.target.result;
                        };
                        reader.readAsDataURL(file);
                    }
                });
                
                // Cancel form functionality
                const cancelButton = document.getElementById('cancel-form');
                cancelButton.addEventListener('click', () => {
                    formContainer.innerHTML = '';
                });
            } else {
                formContainer.innerHTML = '';
            }
        });
        
        // Add animation to user cards
        const userCards = document.querySelectorAll('.user-card');
        userCards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
            card.classList.add('user-item');
        });
    });
</script>
</body>
</html>
<?php $conn->close(); ?>