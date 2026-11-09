<?php
session_start();
require_once '../dp.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userId   = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$userRole = strtolower($_SESSION['role']);

// Get current user's profile
$stmt = $conn->prepare("SELECT profile_picture, status, role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$currentUser    = $stmt->get_result()->fetch_assoc();
$currentProfilePic = $currentUser['profile_picture'] ?? null;
$currentStatus     = $currentUser['status'] ?? 'offline';
$currentRole       = $currentUser['role'] ?? 'member';
$stmt->close();

// Handle new post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_content'])) {
    $text = trim($_POST['text_msg'] ?? '');
    $file_path = $file_name = $file_type = null;
    $content_type = 'post';

    if (!empty($_FILES['attachment']['tmp_name'])) {
        $targetDir = "uploads/public/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $file_name = basename($_FILES['attachment']['name']);
        $file_path = $targetDir . time() . "_" . $file_name;
        $file_type = mime_content_type($_FILES['attachment']['tmp_name']);
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $file_path)) {
            $content_type = strpos($file_type, 'image/') === 0 ? 'image' : 'file';
        }
    }

    if ($text !== '' || $file_path) {
        $stmt = $conn->prepare("
            INSERT INTO sharezone_data 
            (user_id, user_name, hub_type, content_type, text_content, file_path, file_name) 
            VALUES (?, ?, 'public', ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssss", $userId, $userName, $content_type, $text, $file_path, $file_name);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: public_hub.php");
    exit();
}

// Handle edit (own posts only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_post'])) {
    $postId  = (int)$_POST['post_id'];
    $newText = trim($_POST['new_content'] ?? '');

    $check = $conn->prepare("SELECT id FROM sharezone_data WHERE id = ? AND user_id = ? AND hub_type = 'public'");
    $check->bind_param("ii", $postId, $userId);
    $check->execute();
    if ($check->get_result()->num_rows === 0) { header("Location: public_hub.php"); exit(); }
    $check->close();

    if ($newText !== '') {
        $stmt = $conn->prepare("UPDATE sharezone_data SET text_content = ? WHERE id = ?");
        $stmt->bind_param("si", $newText, $postId);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: public_hub.php#post-$postId");
    exit();
}

// Handle delete (own posts only)
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $postId = (int)$_GET['delete'];
    $check = $conn->prepare("SELECT id, file_path FROM sharezone_data WHERE id = ? AND user_id = ? AND hub_type = 'public'");
    $check->bind_param("ii", $postId, $userId);
    $check->execute();
    $result = $check->get_result();
    if ($result->num_rows === 0) { header("Location: public_hub.php"); exit(); }
    $post = $result->fetch_assoc();
    $check->close();

    if ($post['file_path'] && file_exists($post['file_path'])) @unlink($post['file_path']);
    
    // Also delete likes & comments for this post
    $conn->query("DELETE FROM sharezone_likes WHERE post_id = $postId");
    $conn->query("DELETE FROM sharezone_comments WHERE post_id = $postId");

    $del = $conn->prepare("DELETE FROM sharezone_data WHERE id = ?");
    $del->bind_param("i", $postId);
    $del->execute();
    $del->close();
    header("Location: public_hub.php");
    exit();
}

// Fetch posts WITH like counts and whether current user liked each post
$posts = $conn->query("
    SELECT p.*, u.profile_picture,
           COUNT(DISTINCT l.id) AS like_count,
           MAX(CASE WHEN l.user_id = $userId THEN 1 ELSE 0 END) AS user_liked,
           COUNT(DISTINCT c.id) AS comment_count
    FROM sharezone_data p
    LEFT JOIN users u ON p.user_id = u.user_id
    LEFT JOIN sharezone_likes l ON l.post_id = p.id
    LEFT JOIN sharezone_comments c ON c.post_id = p.id
    WHERE p.hub_type = 'public'
    GROUP BY p.id
    ORDER BY p.created_at DESC
");

// Fetch community members
$usersQuery = $conn->query("
    SELECT user_id, name, role, profile_picture, status 
    FROM users 
    ORDER BY role ASC, name ASC
");

function timeAgo($datetime) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->y) return $diff->y . 'y';
    if ($diff->m) return $diff->m . 'mo';
    if ($diff->d) return $diff->d . 'd';
    if ($diff->h) return $diff->h . 'h';
    if ($diff->i) return $diff->i . 'm';
    return 'now';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Square | TidyTrack</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --mac-bg: linear-gradient(135deg, #f5f5f7 0%, #e5e5ea 100%);
            --card-bg: rgba(255,255,255,0.84);
            --accent: #007aff;
            --accent-dark: #0062cc;
            --danger: #ff3b30;
            --text: #1d1d1f;
            --text-sec: #6e6e73;
            --border: rgba(0,0,0,0.10);
            --shadow: 0 12px 48px rgba(0,0,0,0.12);
            --radius: 22px;
            --blur: blur(24px);
        }

        * { box-sizing: border-box; margin:0; padding:0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--mac-bg);
            color: var(--text);
            min-height: 100vh;
            padding: 32px 20px;
            line-height: 1.5;
        }

        .main-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 40px;
        }

        @media (max-width: 980px) { .main-container { grid-template-columns: 1fr; } }

        .feed-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .title { font-size: 32px; font-weight: 800; letter-spacing: -0.6px; margin: 0; }

        .back-btn {
            background: rgba(255,255,255,0.92);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 12px 24px;
            border-radius: 999px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .back-btn:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 24px rgba(0,0,0,0.14);
            background: white;
        }

        .search-bar { flex: 1; min-width: 240px; max-width: 420px; }

        .search-input {
            width: 100%;
            padding: 12px 20px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.9);
            font-size: 16px;
            backdrop-filter: var(--blur);
        }

        .search-input:focus { outline: none; border-color: var(--accent); }

        /* Composer */
        .composer {
            background: var(--card-bg);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 36px;
        }

        textarea {
            width: 100%;
            min-height: 90px;
            border: none;
            outline: none;
            resize: none;
            font-size: 18px;
            background: transparent;
            color: var(--text);
        }

        .preview-area {
            margin: 16px 0;
            border-radius: 16px;
            overflow: hidden;
            border: 1px dashed var(--border);
            padding: 8px;
            display: none;
            text-align: center;
        }

        .preview-area.visible { display: block; }
        .preview-img { max-width: 100%; max-height: 220px; border-radius: 12px; }
        .preview-file { padding: 20px; color: var(--text-sec); font-size: 15px; }

        .composer-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border);
            padding-top: 16px;
            margin-top: 16px;
        }

        .attach-btn { color: var(--accent); font-size: 22px; cursor: pointer; }
        .post-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 11px 32px;
            border-radius: 999px;
            font-weight: 600;
            cursor: pointer;
            font-size: 15px;
            transition: background 0.2s;
        }
        .post-btn:hover { background: var(--accent-dark); }

        /* Post card */
        .post-card {
            background: var(--card-bg);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .post-card:hover { transform: translateY(-4px); box-shadow: 0 20px 60px rgba(0,0,0,0.14); }

        .post-header { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }

        .post-avatar, .post-avatar-placeholder {
            width: 44px; height: 44px; border-radius: 50%; object-fit: cover;
        }

        .post-avatar-placeholder {
            background: linear-gradient(135deg, #007aff, #00c6ff);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            flex-shrink: 0;
        }

        .post-meta .name { font-weight: 600; font-size: 17px; }
        .post-meta .time { font-size: 13px; color: var(--text-sec); }
        .post-content { font-size: 17px; line-height: 1.48; margin: 10px 0 16px; }

        .post-image {
            max-width: 100%;
            border-radius: 16px;
            margin: 12px 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            display: block;
        }

        .file-link {
            display: flex;
            align-items: center;
            gap: 14px;
            background: rgba(0,0,0,0.04);
            padding: 14px 18px;
            border-radius: 16px;
            text-decoration: none;
            color: var(--text);
            margin: 12px 0;
        }

        .file-link:hover { background: rgba(0,0,0,0.07); }

        /* Post Actions */
        .post-actions {
            display: flex;
            gap: 28px;
            color: var(--text-sec);
            margin-top: 16px;
            flex-wrap: wrap;
            align-items: center;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 15px;
            transition: color 0.2s;
            user-select: none;
            background: none;
            border: none;
            color: var(--text-sec);
            padding: 0;
            font-family: inherit;
        }

        .action-btn:hover { color: var(--accent); }
        .action-btn.liked { color: #ff3b30; }
        .action-btn.liked i { color: #ff3b30; }
        .action-btn.liked:hover { color: #d32f2f; }
        .delete-btn { color: #ff3b30 !important; }
        .delete-btn:hover { color: #d32f2f !important; }

        /* Edit form */
        .edit-form { display: none; margin-top: 16px; }
        .edit-form textarea {
            width: 100%;
            min-height: 80px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 16px;
            resize: vertical;
        }
        .edit-buttons { margin-top: 12px; display: flex; gap: 12px; }
        .save-btn, .cancel-btn {
            padding: 8px 20px;
            border-radius: 999px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }
        .save-btn { background: var(--accent); color: white; }
        .cancel-btn { background: #e8e8ed; color: var(--text); }

        /* =====================
           COMMENTS SECTION
        ===================== */
        .comments-section {
            display: none;
            margin-top: 18px;
            border-top: 1px solid var(--border);
            padding-top: 18px;
        }

        .comments-section.open { display: block; }

        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 16px;
            max-height: 320px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .comments-list::-webkit-scrollbar { width: 4px; }
        .comments-list::-webkit-scrollbar-thumb { background: #ddd; border-radius: 4px; }

        .comment-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            animation: fadeSlideIn 0.25s ease;
        }

        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .comment-avatar {
            width: 32px; height: 32px; border-radius: 50%;
            object-fit: cover; flex-shrink: 0;
        }

        .comment-avatar-placeholder {
            width: 32px; height: 32px; border-radius: 50%;
            background: linear-gradient(135deg, #34c759, #007aff);
            color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; flex-shrink: 0;
        }

        .comment-bubble {
            background: rgba(0,0,0,0.04);
            border-radius: 14px;
            padding: 10px 14px;
            flex: 1;
        }

        .comment-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .comment-name { font-weight: 600; font-size: 14px; }
        .comment-time { font-size: 12px; color: var(--text-sec); }

        .comment-text { font-size: 15px; line-height: 1.4; color: var(--text); }

        .comment-delete {
            background: none;
            border: none;
            cursor: pointer;
            color: #ccc;
            font-size: 12px;
            padding: 2px 6px;
            border-radius: 4px;
            transition: color 0.2s;
            margin-left: auto;
            flex-shrink: 0;
        }

        .comment-delete:hover { color: #ff3b30; }

        .no-comments {
            text-align: center;
            color: var(--text-sec);
            font-size: 14px;
            padding: 12px 0;
        }

        /* Comment Input Area */
        .comment-input-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .comment-input-row .comment-avatar,
        .comment-input-row .comment-avatar-placeholder {
            margin-top: 4px;
        }

        .comment-input-wrap { flex: 1; position: relative; }

        .comment-input {
            width: 100%;
            padding: 10px 50px 10px 14px;
            border: 1px solid var(--border);
            border-radius: 22px;
            font-size: 15px;
            background: rgba(255,255,255,0.8);
            resize: none;
            font-family: inherit;
            line-height: 1.4;
            transition: border-color 0.2s;
            min-height: 42px;
            max-height: 120px;
            overflow-y: auto;
        }

        .comment-input:focus { outline: none; border-color: var(--accent); }

        .comment-send-btn {
            position: absolute;
            right: 8px;
            bottom: 8px;
            background: var(--accent);
            border: none;
            border-radius: 50%;
            width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            color: white;
            font-size: 12px;
            transition: background 0.2s, transform 0.15s;
        }

        .comment-send-btn:hover { background: var(--accent-dark); transform: scale(1.1); }

        .comments-loading {
            text-align: center;
            padding: 16px;
            color: var(--text-sec);
            font-size: 14px;
        }

        /* Sidebar */
        .sidebar-card {
            background: var(--card-bg);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 32px;
        }

        .sidebar-title { font-size: 20px; font-weight: 700; margin-bottom: 16px; }

        .filter-buttons { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }

        .filter-btn {
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.6);
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .filter-btn.active, .filter-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .user-list { list-style: none; }

        .user-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .user-item:last-child { border-bottom: none; }

        .user-avatar-small {
            width: 36px; height: 36px; border-radius: 50%; object-fit: cover;
        }

        .user-info { flex: 1; }
        .user-name { font-weight: 500; font-size: 15px; }
        .user-role { font-size: 12px; color: var(--text-sec); }

        .status-dot {
            width: 10px; height: 10px;
            border-radius: 50%;
            background: #ff3b30;
            flex-shrink: 0;
        }

        .status-dot.online { background: #34c759; }

        /* Delete Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: var(--card-bg);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px;
            max-width: 400px;
            width: 90%;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .modal-title { font-size: 20px; font-weight: 700; margin-bottom: 12px; }
        .modal-text { font-size: 16px; color: var(--text-sec); margin-bottom: 32px; }

        .modal-buttons { display: flex; gap: 16px; justify-content: center; }

        .modal-btn {
            padding: 12px 32px;
            border-radius: 999px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .modal-cancel { background: #e8e8ed; color: var(--text); }
        .modal-delete { background: var(--danger); color: white; }
        .modal-delete:hover { background: #d32f2f; }

        @media (max-width: 980px) { .sidebar-card { position: static; } }
    </style>
</head>
<body>

<div class="main-container">
    <div class="feed">

        <div class="feed-header">
            <h1 class="title">Public Square</h1>
            <div class="search-bar">
                <input type="text" id="searchInput" class="search-input" placeholder="Search by poster name...">
            </div>
            <button class="back-btn" onclick="location.href='index.php'">
                <i class="fas fa-chevron-left"></i> Back to Hubs
            </button>
        </div>

        <!-- Composer -->
        <form class="composer" method="POST" enctype="multipart/form-data">
            <textarea name="text_msg" placeholder="Share something with the community..." rows="3"></textarea>
            <div id="preview-area" class="preview-area">
                <img id="preview-img" class="preview-img" src="" alt="" style="display:none;">
                <div id="preview-file" class="preview-file"></div>
            </div>
            <div class="composer-actions">
                <label class="attach-btn">
                    <i class="fas fa-paperclip"></i>
                    <input type="file" id="attachment" name="attachment" style="display:none;" onchange="previewFile()">
                </label>
                <button type="submit" name="post_content" class="post-btn">Post</button>
            </div>
        </form>

        <!-- Posts -->
        <?php while ($row = $posts->fetch_assoc()):
            $avatar    = $row['profile_picture'] ?? null;
            $isOwner   = ($row['user_id'] == $userId);
            $userLiked = (bool)$row['user_liked'];
            $likeCount = (int)$row['like_count'];
            $commentCount = (int)$row['comment_count'];
        ?>
            <div class="post-card" id="post-<?= $row['id'] ?>" data-user="<?= htmlspecialchars(strtolower($row['user_name'])) ?>">
                <div class="post-header">
                    <?php if ($avatar): ?>
                        <img src="<?= htmlspecialchars($avatar) ?>" class="post-avatar" alt="Avatar">
                    <?php else: ?>
                        <div class="post-avatar-placeholder"><?= strtoupper(substr($row['user_name'], 0, 1)) ?></div>
                    <?php endif; ?>
                    <div class="post-meta">
                        <div class="name"><?= htmlspecialchars($row['user_name']) ?></div>
                        <div class="time"><?= timeAgo($row['created_at']) ?> ago</div>
                    </div>
                </div>

                <div class="post-content" id="content-<?= $row['id'] ?>">
                    <?= nl2br(htmlspecialchars($row['text_content'])) ?>
                </div>

                <?php if ($row['content_type'] === 'image' && $row['file_path']): ?>
                    <img src="<?= htmlspecialchars($row['file_path']) ?>" alt="Posted image" class="post-image">
                <?php elseif ($row['file_path']): ?>
                    <a href="download_zip.php?id=<?= $row['id'] ?>" class="file-link">
                        <i class="fas fa-file-zipper" style="font-size:32px; color:#ff9500;"></i>
                        <div>
                            <div style="font-weight:600;"><?= htmlspecialchars($row['file_name']) ?></div>
                            <div style="font-size:13px; color:var(--text-sec);">Download ZIP file</div>
                        </div>
                    </a>
                <?php endif; ?>

                <!-- Post Actions -->
                <div class="post-actions">
                    <!-- LIKE BUTTON -->
                    <button class="action-btn <?= $userLiked ? 'liked' : '' ?>" 
                            onclick="toggleLike(this, <?= $row['id'] ?>)"
                            data-post-id="<?= $row['id'] ?>">
                        <i class="fa<?= $userLiked ? 's' : 'r' ?> fa-heart"></i>
                        <span class="like-count"><?= $likeCount ?></span>
                    </button>

                    <!-- COMMENT BUTTON -->
                    <button class="action-btn" onclick="toggleComments(<?= $row['id'] ?>)">
                        <i class="far fa-comment-dots"></i>
                        <span class="comment-count-<?= $row['id'] ?>"><?= $commentCount ?></span>
                    </button>

                    <?php if ($isOwner): ?>
                        <button class="action-btn" onclick="toggleEdit(<?= $row['id'] ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="action-btn delete-btn" onclick="showDeleteModal('?delete=<?= $row['id'] ?>')">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Edit Form -->
                <?php if ($isOwner): ?>
                    <form method="POST" class="edit-form" id="edit-form-<?= $row['id'] ?>">
                        <input type="hidden" name="post_id" value="<?= $row['id'] ?>">
                        <textarea name="new_content"><?= htmlspecialchars($row['text_content']) ?></textarea>
                        <div class="edit-buttons">
                            <button type="submit" name="edit_post" class="save-btn">Save Changes</button>
                            <button type="button" class="cancel-btn" onclick="toggleEdit(<?= $row['id'] ?>)">Cancel</button>
                        </div>
                    </form>
                <?php endif; ?>

                <!-- COMMENTS SECTION -->
                <div class="comments-section" id="comments-<?= $row['id'] ?>">
                    <div class="comments-loading" id="comments-loading-<?= $row['id'] ?>">
                        <i class="fas fa-spinner fa-spin"></i> Loading comments...
                    </div>
                    <div class="comments-list" id="comments-list-<?= $row['id'] ?>" style="display:none;"></div>

                    <!-- Current user's comment input -->
                    <div class="comment-input-row">
                        <?php if ($currentProfilePic): ?>
                            <img src="<?= htmlspecialchars($currentProfilePic) ?>" class="comment-avatar" alt="">
                        <?php else: ?>
                            <div class="comment-avatar-placeholder"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="comment-input-wrap">
                            <textarea class="comment-input" 
                                      id="comment-input-<?= $row['id'] ?>" 
                                      placeholder="Write a comment..."
                                      rows="1"
                                      onkeydown="handleCommentKey(event, <?= $row['id'] ?>)"></textarea>
                            <button class="comment-send-btn" onclick="postComment(<?= $row['id'] ?>)">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        <?php endwhile; ?>

    </div><!-- end feed -->

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-card">
            <div class="sidebar-title">Community</div>

            <div class="filter-buttons">
                <button class="filter-btn active" data-filter="all">All</button>
                <button class="filter-btn" data-filter="admin">Admins</button>
                <button class="filter-btn" data-filter="leader">Leaders</button>
                <button class="filter-btn" data-filter="manager">Managers</button>
                <button class="filter-btn" data-filter="member">Members</button>
            </div>

            <ul class="user-list">
                <?php while ($user = $usersQuery->fetch_assoc()):
                    $statusClass = ($user['status'] === 'online') ? 'online' : '';
                ?>
                    <li class="user-item" data-role="<?= strtolower($user['role']) ?>">
                        <?php if ($user['profile_picture']): ?>
                            <img src="<?= htmlspecialchars($user['profile_picture']) ?>" class="user-avatar-small" alt="">
                        <?php else: ?>
                            <div class="post-avatar-placeholder" style="width:36px;height:36px;font-size:14px;">
                                <?= strtoupper(substr($user['name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="user-info">
                            <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                            <div class="user-role"><?= ucfirst($user['role']) ?></div>
                        </div>
                        <div class="status-dot <?= $statusClass ?>"></div>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>
    </div>

</div><!-- end main-container -->

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-content">
        <div class="modal-title">Delete Post?</div>
        <p class="modal-text">This action cannot be undone. The post and any attached file will be permanently removed.</p>
        <div class="modal-buttons">
            <button class="modal-btn modal-cancel" onclick="closeModal()">Cancel</button>
            <button class="modal-btn modal-delete" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<script>
const CURRENT_USER_ID = <?= $userId ?>;

// ─────────────────────────────────────────
// FILE PREVIEW
// ─────────────────────────────────────────
function previewFile() {
    const file = document.getElementById('attachment').files[0];
    if (!file) return;
    const area  = document.getElementById('preview-area');
    const img   = document.getElementById('preview-img');
    const info  = document.getElementById('preview-file');
    const reader = new FileReader();
    reader.onload = e => {
        if (file.type.startsWith('image/')) {
            img.src = e.target.result;
            img.style.display = 'block';
            info.style.display = 'none';
        } else {
            img.style.display = 'none';
            info.style.display = 'block';
            info.textContent = `Selected: ${file.name} (${(file.size/1024).toFixed(1)} KB)`;
        }
        area.classList.add('visible');
    };
    reader.readAsDataURL(file);
}

// ─────────────────────────────────────────
// TOGGLE EDIT FORM
// ─────────────────────────────────────────
function toggleEdit(postId) {
    const content = document.getElementById('content-' + postId);
    const form    = document.getElementById('edit-form-' + postId);
    if (form.style.display === 'block') {
        form.style.display = 'none';
        content.style.display = 'block';
    } else {
        form.style.display = 'block';
        content.style.display = 'none';
    }
}

// ─────────────────────────────────────────
// DELETE MODAL
// ─────────────────────────────────────────
let deleteUrl = '';

function showDeleteModal(url) {
    deleteUrl = url;
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('deleteModal').style.display = 'none';
    deleteUrl = '';
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('confirmDeleteBtn').addEventListener('click', function () {
        if (deleteUrl) {
            this.disabled = true;
            this.textContent = 'Deleting...';
            window.location.href = deleteUrl;
        }
    });

    document.getElementById('deleteModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
});

// ─────────────────────────────────────────
// LIKES — AJAX TOGGLE
// ─────────────────────────────────────────
async function toggleLike(btn, postId) {
    btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('action', 'toggle_like');
        fd.append('post_id', postId);

        const res  = await fetch('sharezone_actions.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) { console.error(data.error); return; }

        // Update button state
        const icon = btn.querySelector('i');
        const count = btn.querySelector('.like-count');

        if (data.liked) {
            btn.classList.add('liked');
            icon.className = 'fas fa-heart';
        } else {
            btn.classList.remove('liked');
            icon.className = 'far fa-heart';
        }

        count.textContent = data.count;

        // Heart pop animation
        btn.style.transform = 'scale(1.3)';
        setTimeout(() => btn.style.transform = '', 200);

    } catch (err) {
        console.error('Like error:', err);
    } finally {
        btn.disabled = false;
    }
}

// ─────────────────────────────────────────
// COMMENTS — TOGGLE OPEN/CLOSE
// ─────────────────────────────────────────
const loadedComments = {}; // track which posts have been loaded

async function toggleComments(postId) {
    const section = document.getElementById('comments-' + postId);
    const isOpen  = section.classList.contains('open');

    if (isOpen) {
        section.classList.remove('open');
        return;
    }

    section.classList.add('open');

    // Load if not yet loaded
    if (!loadedComments[postId]) {
        await loadComments(postId);
    }

    // Focus comment input
    setTimeout(() => {
        const inp = document.getElementById('comment-input-' + postId);
        if (inp) inp.focus();
    }, 100);
}

async function loadComments(postId) {
    const loading = document.getElementById('comments-loading-' + postId);
    const list    = document.getElementById('comments-list-' + postId);

    loading.style.display = 'block';
    list.style.display = 'none';

    try {
        const res  = await fetch(`sharezone_actions.php?action=get_comments&post_id=${postId}`);
        const data = await res.json();

        loading.style.display = 'none';
        list.style.display    = 'flex';

        renderComments(postId, data.comments);
        loadedComments[postId] = true;

    } catch (err) {
        loading.textContent = 'Failed to load comments.';
        console.error(err);
    }
}

function renderComments(postId, comments) {
    const list = document.getElementById('comments-list-' + postId);
    list.innerHTML = '';

    if (!comments || comments.length === 0) {
        list.innerHTML = '<div class="no-comments">No comments yet. Be the first!</div>';
        return;
    }

    comments.forEach(c => {
        list.appendChild(buildCommentEl(c));
    });

    // Scroll to bottom
    list.scrollTop = list.scrollHeight;
}

function buildCommentEl(c) {
    const div = document.createElement('div');
    div.className = 'comment-item';
    div.id = 'comment-item-' + c.id;

    const initial = c.user_name.charAt(0).toUpperCase();
    const avatarHtml = c.profile_picture
        ? `<img src="${escapeHtml(c.profile_picture)}" class="comment-avatar" alt="">`
        : `<div class="comment-avatar-placeholder">${initial}</div>`;

    const isOwn = parseInt(c.user_id) === CURRENT_USER_ID;
    const deleteBtn = isOwn
        ? `<button class="comment-delete" onclick="deleteComment(${c.id})" title="Delete">
               <i class="fas fa-times"></i>
           </button>`
        : '';

    div.innerHTML = `
        ${avatarHtml}
        <div class="comment-bubble">
            <div class="comment-header">
                <span class="comment-name">${escapeHtml(c.user_name)}</span>
                <span class="comment-time">${escapeHtml(c.time_ago)}</span>
                ${deleteBtn}
            </div>
            <div class="comment-text">${escapeHtml(c.content).replace(/\n/g, '<br>')}</div>
        </div>`;

    return div;
}

// ─────────────────────────────────────────
// POST A COMMENT
// ─────────────────────────────────────────
async function postComment(postId) {
    const input   = document.getElementById('comment-input-' + postId);
    const content = input.value.trim();
    if (!content) return;

    const sendBtn = input.parentElement.querySelector('.comment-send-btn');
    sendBtn.disabled = true;
    input.disabled   = true;

    try {
        const fd = new FormData();
        fd.append('action', 'post_comment');
        fd.append('post_id', postId);
        fd.append('content', content);

        const res  = await fetch('sharezone_actions.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) { console.error(data.error); return; }

        // Clear input
        input.value = '';

        // Add comment to list
        const list = document.getElementById('comments-list-' + postId);
        list.style.display = 'flex';

        // Remove "no comments" placeholder if present
        const placeholder = list.querySelector('.no-comments');
        if (placeholder) placeholder.remove();

        list.appendChild(buildCommentEl(data));
        list.scrollTop = list.scrollHeight;

        // Update comment count badge
        const countEl = document.querySelector('.comment-count-' + postId);
        if (countEl) countEl.textContent = data.comment_count;

        loadedComments[postId] = true;

    } catch (err) {
        console.error('Comment error:', err);
        alert('Failed to post comment. Please try again.');
    } finally {
        sendBtn.disabled = false;
        input.disabled   = false;
        input.focus();
    }
}

// Submit comment on Enter (Shift+Enter for newline)
function handleCommentKey(e, postId) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        postComment(postId);
    }
}

// ─────────────────────────────────────────
// DELETE A COMMENT
// ─────────────────────────────────────────
async function deleteComment(commentId) {
    if (!confirm('Delete this comment?')) return;

    try {
        const fd = new FormData();
        fd.append('action', 'delete_comment');
        fd.append('comment_id', commentId);

        const res  = await fetch('sharezone_actions.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.deleted) {
            const el = document.getElementById('comment-item-' + commentId);
            if (el) {
                el.style.transition = 'opacity 0.3s, transform 0.3s';
                el.style.opacity = '0';
                el.style.transform = 'scale(0.95)';
                setTimeout(() => el.remove(), 300);
            }

            // Update all comment counts for this post by re-fetching
            // (simple: just decrement)
            const postCard = document.getElementById('comment-item-' + commentId)?.closest('.post-card');
            if (postCard) {
                const postId  = postCard.id.replace('post-', '');
                const countEl = document.querySelector('.comment-count-' + postId);
                if (countEl) countEl.textContent = Math.max(0, parseInt(countEl.textContent) - 1);
            }
        }
    } catch (err) {
        console.error('Delete comment error:', err);
    }
}

// ─────────────────────────────────────────
// SEARCH POSTS
// ─────────────────────────────────────────
document.getElementById('searchInput').addEventListener('input', function () {
    const term = this.value.toLowerCase().trim();
    document.querySelectorAll('.post-card').forEach(card => {
        const user = card.dataset.user || '';
        card.style.display = (term === '' || user.includes(term)) ? '' : 'none';
    });
});

// ─────────────────────────────────────────
// FILTER USERS BY ROLE
// ─────────────────────────────────────────
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const filter = this.dataset.filter;
        document.querySelectorAll('.user-item').forEach(item => {
            item.style.display = (filter === 'all' || item.dataset.role === filter) ? '' : 'none';
        });
    });
});

// ─────────────────────────────────────────
// UTILITY: Escape HTML to prevent XSS
// ─────────────────────────────────────────
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

</body>
</html>