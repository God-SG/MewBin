<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'waf.php';
include('database.php');
session_start();



header('X-Frame-Options: DENY');
header("Content-Security-Policy:'unsafe-inline'; https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self'; data:;");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

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

$rankData = [];
$rankOrder = [];
$rankQuery = $conn->query("SELECT rank_name, rankTag, rankColor, rankGif, rankOrder FROM rank ORDER BY rankOrder ASC");
while ($row = $rankQuery->fetch_assoc()) {
    $rankName = $row['rank_name'];
    $rankData[$rankName] = [
        'tag' => $row['rankTag'],
        'color' => $row['rankColor'],
        'gif' => $row['rankGif'],
        'order' => $row['rankOrder']
    ];
    $rankOrder[] = $rankName;
}
$rankOrder[] = 'All Users';

function timeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;

    $minutes      = round($seconds / 60);
    $hours        = round($seconds / 3600);
    $days         = round($seconds / 86400);
    $weeks        = round($seconds / 604800);
    $months       = round($seconds / 2629440);
    $years        = round($seconds / 31553280);

    if ($seconds <= 60) {
        return "Just Now";
    } else if ($minutes <= 60) {
        return $minutes == 1 ? "1 minute ago" : "$minutes minutes ago";
    } else if ($hours <= 24) {
        return $hours == 1 ? "1 hour ago" : "$hours hours ago";
    } else if ($days <= 7) {
        return $days == 1 ? "1 day ago" : "$days days ago";
    } else if ($weeks <= 4.3) {
        return $weeks == 1 ? "1 week ago" : "$weeks weeks ago";
    } else if ($months <= 12) {
        return $months == 1 ? "1 month ago" : "$months months ago";
    } else {
        return $years == 1 ? "1 year ago" : "$years years ago";
    }
}

$username = $_SESSION['username'] ?? null;
if (!$username && isset($_COOKIE['login_token'])) {
    $login_token = preg_replace('/[^a-f0-9]/', '', $_COOKIE['login_token']);
    if ($login_token) {
        $stmt = $conn->prepare("SELECT username FROM users WHERE login_token = ?");
        $stmt->bind_param("s", $login_token);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $username = $row['username'];
            $_SESSION['username'] = $username;
        }
        $stmt->close();
    }
}

if ($username) {
    $stmt = $conn->prepare("SELECT login_token FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (empty($row['login_token'])) {
            session_destroy();
            $username = null;
        }
    } else {
        session_destroy();
        $username = null;
    }
} else {
}

if (isset($_GET['receiver_username'])) {
    $receiver_username = $_GET['receiver_username'];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $receiver_username)) {
        $receiver_username = $username;
    }
} else {
    $receiver_username = $username;
}

if ($receiver_username) {
    $comments_query = $conn->prepare("SELECT COUNT(*) AS comment_count FROM profile_comments WHERE receiver_username = ?");
    $comments_query->bind_param("s", $receiver_username);
    $comments_query->execute();
    $comments_result = $comments_query->get_result();
    $comments_data = $comments_result->fetch_assoc();
    $comments_count = $comments_data['comment_count'] ?? 0;

    $pastes_query = $conn->prepare("SELECT COUNT(*) AS paste_count FROM pastes WHERE creator = ?");
    $pastes_query->bind_param("s", $receiver_username);
    $pastes_query->execute();
    $pastes_result = $pastes_query->get_result();
    $pastes_data = $pastes_result->fetch_assoc();
    $pastes_count = $pastes_data['paste_count'] ?? 0;

    $comments_query->close();
    $pastes_query->close();
} else {
    $comments_count = 0;
    $pastes_count = 0;
}

$search_query = '';
$search_error = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search'])) {
    $search_query = trim($_POST['search']);
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $search_query)) {
        $search_error = "Invalid search term. Only 3-32 alphanumeric or underscore characters allowed.";
        $search_query = '';
    }
}

$usersPerPage = 100;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);
$offset = ($page - 1) * $usersPerPage;

function getUsersByRank($rank, $conn) {
    $query = "SELECT * FROM users WHERE rank = ? ORDER BY created_at ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $rank);
    $stmt->execute();
    return $stmt->get_result();
}

function getUnrankedUsers($conn, $limit, $offset) {
    $query = "SELECT * FROM users WHERE rank IS NULL OR rank = '' OR rank = 'All Users' ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    return $stmt->get_result();
}

if ($search_query !== '') {
    $search_query_for_sql = "%$search_query%";
    $users_stmt = $conn->prepare("SELECT * FROM users WHERE username LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $users_stmt->bind_param("sii", $search_query_for_sql, $usersPerPage, $offset);
    $users_stmt->execute();
    $users = $users_stmt->get_result();

    $totalUsersQuery = "SELECT COUNT(*) as total FROM users WHERE username LIKE ?";
    $stmt = $conn->prepare($totalUsersQuery);
    $stmt->bind_param("s", $search_query_for_sql);
    $stmt->execute();
    $totalUsersResult = $stmt->get_result();
    $totalUsers = $totalUsersResult->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    $users_stmt->close();
} else {
    $users = getUnrankedUsers($conn, $usersPerPage, $offset);
    $totalUsersQuery = "SELECT COUNT(*) as total FROM users WHERE rank IS NULL OR rank = '' OR rank = 'All Users'";
    $totalUsersResult = $conn->query($totalUsersQuery);
    $totalUsers = $totalUsersResult->fetch_assoc()['total'] ?? 0;
}
$totalPages = ceil($totalUsers / $usersPerPage);

$query = "SELECT rank FROM users WHERE username = ?";
$loggedInUserRank = 'All Users';
if ($username) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    $loggedInUserRank = $userData['rank'] ?? 'All Users';
    $stmt->close();

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self';">
    <title>Users</title>
    <link rel="canonical" href="https://nebulabin.org/users" />
    <meta property="og:site_name" content="nebulabin"/>
    <meta property="og:type" content="website"/>
    <meta name="theme-color" content="#2a0033"/>
    <meta name="robots" content="index, follow"/>
    <meta name="twitter:card" content="summary"/>
    <meta name="description" content="Nebulabin is a document sharing and publishing website for text-based information such as dox, code-snippets and other stuff.">
    <meta name="twitter:title" content="Users">
    <meta name="twitter:description" content="Nebulabin is a document sharing and publishing website for text-based information such as dox, code-snippets and other stuff.">
    <!-- Add Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="ajax/libs/font-awesome/5.12.0/css/all.min.css" referrerpolicy="no-referrer"/>
    <link rel="stylesheet" href="assets/css/styles.min.css">

    <style>
        :root {
            --primary-purple: #5a0d7a;
            --secondary-purple: #aaa9aa;
            --dark-purple: #17001c;
            --accent-purple: #8a2be2;
            --neon-purple: #ab28e3;
            --text-primary: #e0d6ed;
            --text-secondary: #7300ff;
            --card-bg: rgba(6, 6, 6, 0.54);
            --card-border: rgba(90, 13, 122, 0.4);
            --card-hover: rgba(90, 13, 122, 0.2);
        }
        
        body {
            background: #000000;
            color: #e0d6ed;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 20px; /* Add some padding for navbar */
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .page-title {
            color: #e0d6ed;
            margin: 30px 0;
            font-weight: 700;
            text-align: center;
        }
        
        .search-section {
            text-align: center;
            margin: 30px auto;
            max-width: 1000px;
        }
        
        .search-form {
            display: inline-flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .search-input {
            background: rgba(10, 0, 15, 0.9);
            border: 1px solid rgba(90, 13, 122, 0.4);
            color: #e0d6ed;
            padding: 10px 15px;
            width: 250px;
        }
        
        .search-button {
            background: rgba(10, 0, 15, 0.9);
            border: 1px solid #5a0d7a;
            color: #e0d6ed;
            padding: 10px 20px;
            cursor: pointer;
        }
        
        .search-button:hover {
            background: rgba(90, 13, 122, 0.3);
            border-color: #ab28e3;
        }
        
        .info-bar {
            color: #7300ff;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .pagination-top {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .page-btn {
            background: rgba(10, 0, 15, 0.9);
            border: 1px solid #5a0d7a;
            color: #e0d6ed;
            padding: 8px 15px;
            margin: 0 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .page-btn:hover {
            background: rgba(90, 13, 122, 0.3);
            border-color: #ab28e3;
            color: #e0d6ed;
            text-decoration: none;
        }
        
        .page-btn.active {
            background: rgba(90, 13, 122, 0.5);
            border-color: #ab28e3;
        }
        
        .user-section {
            margin: 30px auto;
            max-width: 1000px;
        }
        
        .section-title {
            color: #e0d6ed;
            margin: 25px 0 15px 0;
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(6, 6, 6, 0.54);
            border: 1px solid rgba(90, 13, 122, 0.4);
        }
        
        .users-table th {
            background-color: rgba(42, 0, 51, 0.7);
            color: #e0d6ed;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #5a0d7a;
        }
        
        .users-table td {
            padding: 12px 15px;
            border-bottom: 1px solid rgba(90, 13, 122, 0.3);
        }
        
        .users-table tr:hover {
            background-color: rgba(90, 13, 122, 0.1);
        }
        
        .users-table tr:last-child td {
            border-bottom: none;
        }
        
        .user-id {
            color: #7300ff;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            width: 80px;
        }
        
        .username {
            font-weight: 600;
        }
        
        .username a {
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .username a:hover {
            filter: brightness(1.2);
        }
        
        .pastes-count, .comments-count {
            text-align: center;
            width: 100px;
        }
        
        .joined-date {
            color: #7300ff;
            font-family: 'Courier New', monospace;
            width: 150px;
        }
        
        .no-results {
            text-align: center;
            padding: 40px;
            color: #7300ff;
            font-style: italic;
        }
        
        .pagination-bottom {
            margin: 40px auto;
            max-width: 1000px;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
                gap: 10px;
            }
            
            .search-input {
                width: 200px;
            }
            
            .users-table {
                display: block;
                overflow-x: auto;
            }
            
            .page-btn {
                padding: 6px 10px;
                margin: 0 2px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>

    <!-- Navigation from index.php -->
    <?php
    $navbarPath = __DIR__ . '/cstnavbar.php';
    if (file_exists($navbarPath)) {
        include($navbarPath);
    }
    ?>

    <div class="container">
        <div class="row">
            <div class="col-12">
                <center>
                    <h1 class="page-title">Users</h1>
                </center>
                
                <div class="search-section">
                    <form action="users.php" method="post" class="search-form">
                        <input class="form-control search-input" type="text" name="search" 
                               placeholder="Username" 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                        <button class="btn search-button" type="submit">Search</button>
                    </form>
                    <div class="info-bar">
                        Showing <?php echo min($usersPerPage, $totalUsers); ?> (of <?php echo $totalUsers; ?>) users
                    </div>
                    
                    <!-- Top Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination-top">
                        <?php if ($page > 1): ?>
                            <a class="page-btn" href="?page=<?= $page - 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>">Previous</a>
                        <?php endif; ?>
                        
                        <?php 
                        // Show page numbers
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                            <a class="page-btn <?= $i == $page ? 'active' : '' ?>" 
                               href="?page=<?= $i ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a class="page-btn" href="?page=<?= $page + 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>">Next</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($search_error): ?>
                    <div class="alert alert-danger text-center" style="max-width: 1000px; margin: 20px auto;">
                        <?php echo $search_error; ?>
                    </div>
                <?php endif; ?>
                
                <div class="users-container">
                    <?php if ($search_query !== ''): ?>
                        <!-- Search Results -->
                        <div class="user-section">
                            <div class="section-title">Search Results for: "<?php echo htmlspecialchars($search_query); ?>"</div>
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Pastes</th>
                                        <th>Comments</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if ($result->num_rows > 0):
                                        while ($row = $result->fetch_assoc()):
                                            $user_id = htmlspecialchars($row['id']);
                                            $username = htmlspecialchars($row['username']);
                                            $date_joined = date('M j, Y', strtotime($row['created_at']));
                                            $user_rank = $row['rank'] ?? '';
                                            $username_color = '';
                                            
                                            // Check for custom user color first
                                            $color_query = $conn->prepare("SELECT has_color, color FROM users WHERE username = ?");
                                            $color_query->bind_param("s", $username);
                                            $color_query->execute();
                                            $color_result = $color_query->get_result();
                                            $user_data = $color_result->fetch_assoc();
                                            
                                            // Use custom color if available, otherwise use rank color
                                            if ($user_data && $user_data['has_color'] == 1 && !empty($user_data['color'])) {
                                                $username_color = $user_data['color'];
                                            } elseif ($user_rank && isset($rankData[$user_rank]) && !empty($rankData[$user_rank]['color'])) {
                                                $username_color = $rankData[$user_rank]['color'];
                                            }
                                            
                                            // Get user stats
                                            $comments_query = $conn->prepare("SELECT COUNT(*) AS comment_count FROM profile_comments WHERE receiver_username = ?");
                                            $comments_query->bind_param("s", $username);
                                            $comments_query->execute();
                                            $comments_result = $comments_query->get_result();
                                            $comments_data = $comments_result->fetch_assoc();
                                            $comments_count = $comments_data['comment_count'];
                                            
                                            $pastes_query = $conn->prepare("SELECT COUNT(*) AS paste_count FROM pastes WHERE creator = ?");
                                            $pastes_query->bind_param("s", $username);
                                            $pastes_query->execute();
                                            $pastes_result = $pastes_query->get_result();
                                            $pastes_data = $pastes_result->fetch_assoc();
                                            $pastes_count = $pastes_data['paste_count'];
                                    ?>
                                    <tr>
                                        <td class="user-id"><?php echo $user_id; ?></td>
                                        <td class="username">
                                            <a href="user/<?php echo $username; ?>" 
                                               style="color: <?php echo $username_color ? htmlspecialchars($username_color) : '#e0d6ed'; ?>">
                                                <?php echo $username; ?>
                                            </a>
                                        </td>
                                        <td class="pastes-count"><?php echo $pastes_count; ?></td>
                                        <td class="comments-count"><?php echo $comments_count; ?></td>
                                        <td class="joined-date"><?php echo $date_joined; ?></td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else: 
                                    ?>
                                    <tr>
                                        <td colspan="5" class="no-results">
                                            No users found matching "<?php echo htmlspecialchars($search_query); ?>"
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <!-- Rank-based User Sections -->
                        <?php 
                        // Display users by rank in the specified order
                        foreach ($rankOrder as $rank):
                            if ($rank === 'All Users') {
                                $users_result = getUnrankedUsers($conn, $usersPerPage, $offset);
                                $section_title = 'All Users';
                            } else {
                                $users_result = getUsersByRank($rank, $conn);
                                $section_title = $rank;
                            }
                            
                            if ($users_result->num_rows > 0):
                        ?>
                        <div class="user-section">
                            <div class="section-title"><?php echo htmlspecialchars($section_title); ?></div>
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Pastes</th>
                                        <th>Comments</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    while ($row = $users_result->fetch_assoc()): 
                                        $user_id = htmlspecialchars($row['id']);
                                        $username = htmlspecialchars($row['username']);
                                        $date_joined = date('M j, Y', strtotime($row['created_at']));
                                        $user_rank = $row['rank'] ?? '';
                                        $username_color = '';
                                        
                                        // Check for custom user color first
                                        $color_query = $conn->prepare("SELECT has_color, color FROM users WHERE username = ?");
                                        $color_query->bind_param("s", $username);
                                        $color_query->execute();
                                        $color_result = $color_query->get_result();
                                        $user_data = $color_result->fetch_assoc();
                                        
                                        // Use custom color if available, otherwise use rank color
                                        if ($user_data && $user_data['has_color'] == 1 && !empty($user_data['color'])) {
                                            $username_color = $user_data['color'];
                                        } elseif ($user_rank && isset($rankData[$user_rank]) && !empty($rankData[$user_rank]['color'])) {
                                            $username_color = $rankData[$user_rank]['color'];
                                        }
                                        
                                        // Get user stats
                                        $comments_query = $conn->prepare("SELECT COUNT(*) AS comment_count FROM profile_comments WHERE receiver_username = ?");
                                        $comments_query->bind_param("s", $username);
                                        $comments_query->execute();
                                        $comments_result = $comments_query->get_result();
                                        $comments_data = $comments_result->fetch_assoc();
                                        $comments_count = $comments_data['comment_count'];
                                        
                                        $pastes_query = $conn->prepare("SELECT COUNT(*) AS paste_count FROM pastes WHERE creator = ?");
                                        $pastes_query->bind_param("s", $username);
                                        $pastes_query->execute();
                                        $pastes_result = $pastes_query->get_result();
                                        $pastes_data = $pastes_result->fetch_assoc();
                                        $pastes_count = $pastes_data['paste_count'];
                                    ?>
                                    <tr>
                                        <td class="user-id"><?php echo $user_id; ?></td>
                                        <td class="username">
                                            <a href="user/<?php echo $username; ?>" 
                                               style="color: <?php echo $username_color ? htmlspecialchars($username_color) : '#e0d6ed'; ?>">
                                                <?php echo $username; ?>
                                            </a>
                                        </td>
                                        <td class="pastes-count"><?php echo $pastes_count; ?></td>
                                        <td class="comments-count"><?php echo $comments_count; ?></td>
                                        <td class="joined-date"><?php echo $date_joined; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    <?php endif; ?>
                </div>
                
                <!-- Bottom Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination-bottom">
                    <?php if ($page > 1): ?>
                        <a class="page-btn" href="?page=<?= $page - 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>">Previous</a>
                    <?php endif; ?>
                    
                    <?php 
                    // Show page numbers
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                        <a class="page-btn <?= $i == $page ? 'active' : '' ?>" 
                           href="?page=<?= $i ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="?page=<?= $page + 1 ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?>">Next</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>