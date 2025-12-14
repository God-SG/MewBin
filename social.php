<?php
include('forumbar.php');
require_once 'forumdb.php';
session_start();

// Get category stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE category_slug = ?");
$stmt->execute(['social']);
$thread_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(p.id) FROM posts p JOIN threads t ON p.thread_id = t.id WHERE t.category_slug = ?");
$stmt->execute(['social']);
$post_count = $stmt->fetchColumn();

// Get last post info
$stmt = $pdo->prepare("
    SELECT t.title, p.created_at, u.username 
    FROM posts p 
    JOIN threads t ON p.thread_id = t.id 
    JOIN users u ON p.user_id = u.id 
    WHERE t.category_slug = 'social' 
    ORDER BY p.created_at DESC 
    LIMIT 1
");
$last_post = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Social Forums - MewBin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { background: #151822 url('https://www.transparenttextures.com/patterns/stardust.png'); color: #eaeaea; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; }
        .forums-category-container { max-width: 1100px; margin: 32px auto 40px auto; background: #23232e; border-radius: 12px; box-shadow: 0 2px 16px #000a; border: 1.5px solid #3a2a4d; overflow: hidden; }
        .forums-header-bar { background: linear-gradient(90deg, #e91e63 0%, #f06292 100%); color: #fff; font-size: 1.13rem; font-weight: bold; padding: 16px 28px; letter-spacing: 1.2px; border-bottom: 2px solid #3a2a4d; }
        .forums-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .forums-table th, .forums-table td { padding: 12px 16px; text-align: left; }
        .forums-table th { background: #2d223a; color: #c18aff; font-size: 1.01rem; font-weight: 600; border-bottom: 1.5px solid #3a2a4d; }
        .forum-row { background: #23232e; border-bottom: 1px solid #292a3d; transition: background 0.18s, box-shadow 0.18s; cursor: pointer; }
        .forum-row:hover { background: #2d223a; box-shadow: 0 0 12px 2px #a98bdb80; z-index: 2; }
        .forums-forum-icon { font-size: 1.6em; margin-right: 16px; min-width: 38px; text-align: center; vertical-align: middle; }
        .forums-forum-title { font-size: 1.08rem; font-weight: bold; color: #fff; margin-bottom: 2px; display: inline-block; }
        .forums-subforums-list { margin: 6px 0 0 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 18px 32px; }
        .forums-subforums-list li { display: flex; align-items: center; font-size: 0.97rem; color: #b1b1b1; transition: text-shadow 0.18s, color 0.18s; cursor: pointer; }
        .forums-subforums-list li:hover { color: #fff; text-shadow: 0 0 8px #39ff14, 0 0 16px #a98bdb; }
        .forums-online-dot { display: inline-block; width: 10px; height: 10px; background: #39ff14; border-radius: 50%; margin-right: 7px; margin-left: 2px; vertical-align: middle; box-shadow: 0 0 6px #39ff14a0; }
        .forums-stats, .forums-lastpost { font-size: 0.97rem; color: #b1e1ff; text-align: left; min-width: 120px; }
        .forums-lastpost a { color: #fff; font-weight: bold; text-decoration: none; }
        .forums-lastpost a:hover { text-decoration: underline; }
        .forums-lastpost .lastpost-user { color: #8ad0ff; font-weight: bold; }
        .forums-lastpost .lastpost-time { color: #b1b1b1; font-size: 0.93em; }
        .forums-forum-title, .forums-subforums-list li {
            cursor: pointer;
        }
        .forums-forum-title a, .forums-subforums-list li a {
            color: #fff;
            text-decoration: none;
            transition: color 0.18s, text-shadow 0.18s;
        }
        .forums-forum-title a:hover, .forums-subforums-list li a:hover {
            color: #39ff14;
            text-shadow: 0 0 8px #39ff14, 0 0 16px #a98bdb;
        }
        @media (max-width: 900px) { .forums-category-container { margin: 0 2px 24px 2px; } .forums-table th, .forums-table td { padding: 8px 4px; } }
    </style>
</head>
<body>
    <div class="forums-category-container">
        <div class="forums-header-bar">Social</div>
        <table class="forums-table">
            <thead>
                <tr>
                    <th>Forum</th>
                    <th>Threads</th>
                    <th>Posts</th>
                    <th>Last Post</th>
                </tr>
            </thead>
            <tbody>
                <tr class="forum-row">
                    <td>
                        <div style="display:flex;align-items:center;margin-bottom:4px;">
                            <span class="forums-forum-icon" style="color:#e91e63;"><i class="fas fa-users"></i></span>
                            <span class="forums-forum-title">
                                <a href="forum_threads.php?category=social">Social</a>
                            </span>
                        </div>
                        <ul class="forums-subforums-list">
                            <?php foreach (['General Discussion', 'Introductions', 'Off Topic', 'Community Events'] as $sub): 
                                $sub_slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $sub));
                            ?>
                            <li>
                                <span class="forums-online-dot"></span>
                                <a href="forum_threads.php?category=social&subforum=<?= urlencode($sub_slug) ?>">
                                    <?= htmlspecialchars($sub) ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                    <td class="forums-stats"><?php echo number_format($thread_count); ?></td>
                    <td class="forums-stats"><?php echo number_format($post_count); ?></td>
                    <td class="forums-lastpost">
                        <?php if ($last_post): ?>
                            <a href="#"><?php echo htmlspecialchars(substr($last_post['title'], 0, 30)) . (strlen($last_post['title']) > 30 ? '...' : ''); ?></a><br>
                            <span class="forums-lastpost-time"><?php echo date('j M Y, g:i A', strtotime($last_post['created_at'])); ?><br>by <span class="lastpost-user"><?php echo htmlspecialchars($last_post['username']); ?></span></span>
                        <?php else: ?>
                            <span style="color:#666;">No posts yet</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>