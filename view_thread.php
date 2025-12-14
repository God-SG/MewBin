<?php
session_start();
include('forumbar.php');
require_once 'forumdb.php';

$thread_id = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
if (!$thread_id) exit('Invalid thread.');

// Increment view count
$pdo->prepare("UPDATE threads SET views = views + 1 WHERE id = ?")->execute([$thread_id]);

// Fetch thread info
$stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
$stmt->execute([$thread_id]);
$thread = $stmt->fetch();
if (!$thread) exit('Thread not found.');

// Fetch posts with user info
$stmt = $pdo->prepare("SELECT p.*, u.username, u.rank FROM posts p JOIN users u ON p.user_id = u.id WHERE p.thread_id = ? ORDER BY p.created_at ASC");
$stmt->execute([$thread_id]);
$posts = $stmt->fetchAll();

// Handle new post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $content = trim($_POST['content'] ?? '');
    if ($content) {
        $stmt = $pdo->prepare("INSERT INTO posts (thread_id, user_id, content) VALUES (?, ?, ?)");
        $stmt->execute([$thread_id, $_SESSION['user_id'], $content]);
        header("Location: view_thread.php?thread_id=$thread_id");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($thread['title']) ?> - MewBin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { background: #151822 url('https://www.transparenttextures.com/patterns/stardust.png'); color: #eaeaea; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; }
        .thread-container { max-width: 900px; margin: 36px auto 0 auto; background: #23232e; border-radius: 12px; box-shadow: 0 2px 16px #000a; border: 1.5px solid #3a2a4d; overflow: hidden; padding: 24px; }
        .thread-title { color: #fff; font-size: 1.3rem; font-weight: bold; margin-bottom: 10px; }
        .thread-meta { color: #b1b1b1; font-size: 0.97rem; margin-bottom: 18px; }
        .post-item { background: #2d223a; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; border: 1px solid #3a2a4d; }
        .post-author { color: #8ad0ff; font-weight: bold; }
        .post-rank { color: #b1b1b1; font-size: 0.93em; margin-left: 8px; }
        .post-content { color: #eaeaea; margin: 10px 0; }
        .post-time { color: #b1b1b1; font-size: 0.92em; }
        .reply-form textarea { width: 100%; padding: 10px; background: #23232e; border: 1px solid #3a2a4d; color: #fff; border-radius: 4px; margin-bottom: 10px; }
        .reply-form button { background: linear-gradient(90deg, #6e4bb7 0%, #a98bdb 100%); color: #fff; padding: 10px 24px; border: none; border-radius: 6px; font-weight: 600; font-size: 1rem; cursor: pointer; }
        .reply-form button:hover { background: linear-gradient(90deg, #39ff14 0%, #6e4bb7 100%); }
    </style>
</head>
<body>
    <div class="thread-container">
        <div class="thread-title"><?= htmlspecialchars($thread['title']) ?></div>
        <div class="thread-meta">Views: <?= (int)$thread['views'] ?> | Started: <?= date('j M Y, g:i A', strtotime($thread['created_at'])) ?></div>
        <?php foreach ($posts as $post): ?>
            <div class="post-item">
                <span class="post-author"><?= htmlspecialchars($post['username']) ?></span>
                <span class="post-rank">(<?= htmlspecialchars($post['rank']) ?>)</span>
                <div class="post-content"><?= nl2br(htmlspecialchars($post['content'])) ?></div>
                <div class="post-time"><?= date('j M Y, g:i A', strtotime($post['created_at'])) ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (isset($_SESSION['user_id'])): ?>
            <form class="reply-form" method="post">
                <textarea name="content" required maxlength="2000" rows="4" placeholder="Write your reply..."></textarea>
                <button type="submit">Post Reply</button>
            </form>
        <?php else: ?>
            <div><a href="forumlogin.php">Login</a> to reply.</div>
        <?php endif; ?>
    </div>
</body>
</html>
