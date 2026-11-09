<?php
session_start();
require_once '../dp.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userId     = $_SESSION['user_id'];
$userName   = $_SESSION['name'];
$userRole   = strtolower($_SESSION['role']);
$projectId  = intval($_GET['project_id'] ?? 0);

// Security check (same as before)
$isAuthorized = false;
if ($userRole === 'leader') {
    $check = $conn->prepare("SELECT project_id FROM projects_x WHERE project_id = ? AND teamLeadder_id = ?");
    $check->bind_param("ii", $projectId, $userId);
} else {
    $check = $conn->prepare("SELECT DISTINCT t.project_id FROM tasks t WHERE t.project_id = ? AND t.assignTo = ?");
    $check->bind_param("is", $projectId, $userName);
}
$check->execute();
$isAuthorized = $check->get_result()->num_rows > 0;
$check->close();

if (!$isAuthorized) {
    die("Access Denied: You are not part of this project group.");
}

// Get project name
$projStmt = $conn->prepare("SELECT project_name FROM projects_x WHERE project_id = ?");
$projStmt->bind_param("i", $projectId);
$projStmt->execute();
$project = $projStmt->get_result()->fetch_assoc();
$projectName = $project['project_name'] ?? 'Unknown Project';
$projStmt->close();

// === HANDLE NEW POST (unchanged from working version) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_content'])) {
    $text = trim($_POST['text_msg'] ?? '');
    $file_path = $file_name = $file_type = null;
    $content_type = 'post';

    if (!empty($_FILES['attachment']['tmp_name'])) {
        $targetDir = "uploads/group/";
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
            (user_id, user_name, hub_type, project_id, content_type, text_content, file_path, file_name) 
            VALUES (?, ?, 'group', ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isissss", $userId, $userName, $projectId, $content_type, $text, $file_path, $file_name);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: group_hub.php?project_id=$projectId");
    exit();
}

// === HANDLE LIKE (unchanged) ===
if (isset($_GET['like']) && is_numeric($_GET['like'])) {
    $postId = (int)$_GET['like'];
    $conn->query("UPDATE sharezone_data SET likes = likes + 1 WHERE id = $postId AND hub_type = 'group' AND project_id = $projectId");
    header("Location: group_hub.php?project_id=$projectId#post-$postId");
    exit();
}

// === HANDLE EDIT (new) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_post'])) {
    $postId    = (int)$_POST['post_id'];
    $newText   = trim($_POST['new_content'] ?? '');

    // Security: only owner can edit
    $checkOwn = $conn->prepare("SELECT id FROM sharezone_data WHERE id = ? AND user_id = ? AND hub_type = 'group'");
    $checkOwn->bind_param("ii", $postId, $userId);
    $checkOwn->execute();
    if ($checkOwn->get_result()->num_rows === 0) {
        // Not owner → silent fail or error
        header("Location: group_hub.php?project_id=$projectId");
        exit();
    }
    $checkOwn->close();

    if ($newText !== '') {
        $stmt = $conn->prepare("UPDATE sharezone_data SET text_content = ? WHERE id = ?");
        $stmt->bind_param("si", $newText, $postId);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: group_hub.php?project_id=$projectId#post-$postId");
    exit();
}

// === HANDLE DELETE (new) ===
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $postId = (int)$_GET['delete'];

    // Security: only owner can delete
    $checkOwn = $conn->prepare("SELECT id FROM sharezone_data WHERE id = ? AND user_id = ? AND hub_type = 'group'");
    $checkOwn->bind_param("ii", $postId, $userId);
    $checkOwn->execute();
    if ($checkOwn->get_result()->num_rows === 0) {
        header("Location: group_hub.php?project_id=$projectId");
        exit();
    }
    $checkOwn->close();

    // Also delete file from server if exists
    $fileCheck = $conn->prepare("SELECT file_path FROM sharezone_data WHERE id = ?");
    $fileCheck->bind_param("i", $postId);
    $fileCheck->execute();
    $fileRow = $fileCheck->get_result()->fetch_assoc();
    if ($fileRow && $fileRow['file_path'] && file_exists($fileRow['file_path'])) {
        @unlink($fileRow['file_path']);
    }
    $fileCheck->close();

    // Delete from DB
    $delStmt = $conn->prepare("DELETE FROM sharezone_data WHERE id = ?");
    $delStmt->bind_param("i", $postId);
    $delStmt->execute();
    $delStmt->close();

    header("Location: group_hub.php?project_id=$projectId");
    exit();
}

// Fetch posts with avatars
$posts = $conn->prepare("
    SELECT s.*, u.profile_picture 
    FROM sharezone_data s
    LEFT JOIN users u ON s.user_id = u.user_id
    WHERE s.hub_type = 'group' AND s.project_id = ?
    ORDER BY s.created_at DESC
");
$posts->bind_param("i", $projectId);
$posts->execute();
$postsResult = $posts->get_result();

// Fetch group members (same as before)
$membersStmt = $conn->prepare("
    SELECT DISTINCT u.user_id, u.name, u.role, u.profile_picture, u.status
    FROM tasks t
    JOIN users u ON t.assignTo = u.name
    WHERE t.project_id = ?
    UNION
    SELECT u.user_id, u.name, u.role, u.profile_picture, u.status
    FROM projects_x p
    JOIN users u ON p.teamLeadder_id = u.user_id
    WHERE p.project_id = ?
");
$membersStmt->bind_param("ii", $projectId, $projectId);
$membersStmt->execute();
$members = $membersStmt->get_result();

// timeAgo function (unchanged)
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
    <title><?= htmlspecialchars($projectName) ?> • Team Square</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --mac-bg: linear-gradient(135deg, #f5f5f7 0%, #e5e5ea 100%);
            --card-bg: rgba(255,255,255,0.82);
            --accent: #007aff;
            --accent-dark: #0062cc;
            --text: #1d1d1f;
            --text-sec: #6e6e73;
            --border: rgba(0,0,0,0.10);
            --shadow: 0 12px 48px rgba(0,0,0,0.12);
            --radius: 24px;
            --blur: blur(24px);
        }

        * { box-sizing: border-box; margin:0; padding:0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--mac-bg);
            color: var(--text);
            min-height: 100vh;
            padding: 32px 20px;
        }

        .main-layout {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 40px;
        }

        @media (max-width: 980px) {
            .main-layout { grid-template-columns: 1fr; }
        }

        /* Header */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .title {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -0.6px;
        }

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
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }

        .back-btn:hover {
            transform: translateY(-2px);
            background: white;
            box-shadow: 0 8px 24px rgba(0,0,0,0.14);
        }

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
        }

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

        .post-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
        }

        .post-avatar, .avatar-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
        }

        .avatar-placeholder {
            background: linear-gradient(135deg, #007aff, #00c6ff);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: bold;
        }

        .post-meta .name { font-weight: 600; font-size: 17px; }
        .post-meta .time { font-size: 13px; color: var(--text-sec); }

        .post-content { font-size: 17px; line-height: 1.48; margin: 10px 0 16px; }

        .post-image { max-width: 100%; border-radius: 16px; margin: 12px 0; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }

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

        .post-actions {
            display: flex;
            gap: 32px;
            color: var(--text-sec);
            margin-top: 16px;
        }

        .action {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 15px;
            transition: color 0.2s;
        }

        .action:hover { color: var(--accent); }
        .liked { color: #ff385c; }

        /* Sidebar - Members */
        .sidebar-card {
            background: var(--card-bg);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 32px;
            height: fit-content;
        }

        .sidebar-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .member-list { list-style: none; }
        .member-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .member-item:last-child { border-bottom: none; }

        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: #e0e0e0;
        }

        .member-info { flex: 1; }
        .member-name { font-weight: 600; font-size: 15px; }
        .member-role { font-size: 13px; color: var(--text-sec); }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #ff3b30;
        }
        .status-dot.online { background: #34c759; }

        @media (max-width: 980px) {
            .sidebar-card { position: static; }
        }
   .post-actions-extended {
            display: flex;
            gap: 32px;
            margin-top: 16px;
            color: var(--text-sec);
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 15px;
            transition: color 0.2s;
        }

        .action-btn:hover { color: var(--accent); }
        .delete-btn { color: #ff3b30 !important; }
        .delete-btn:hover { color: #d32f2f !important; }

        .edit-form {
            display: none;
            margin-top: 16px;
        }
        .edit-form textarea {
            width: 100%;
            min-height: 80px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 16px;
            resize: vertical;
        }
        .edit-buttons {
            margin-top: 12px;
            display: flex;
            gap: 12px;
        }
        .save-btn, .cancel-btn {
            padding: 8px 20px;
            border-radius: 999px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }
        .save-btn { background: var(--accent); color: white; }
        .cancel-btn { background: #e8e8ed; color: var(--text); }
        
        /* Delete Confirmation Modal */
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

.modal-title {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 12px;
    color: var(--danger);
}

.modal-text {
    font-size: 16px;
    color: var(--text-sec);
    margin-bottom: 32px;
}

.modal-buttons {
    display: flex;
    gap: 16px;
    justify-content: center;
}

.modal-btn {
    padding: 12px 32px;
    border-radius: 999px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.modal-cancel {
    background: #e8e8ed;
    color: var(--text);
}

.modal-delete {
    background: var(--danger);
    color: white;
}

.modal-delete:hover {
    background: #d32f2f;
}

    </style>
</head>
<body>

<div class="main-layout">

    <div class="feed">

         <div class="page-header">
            <h1 class="title"><?= htmlspecialchars($projectName) ?></h1>
            <button class="back-btn" onclick="location.href='group_picker.php'">
                <i class="fas fa-chevron-left"></i> Back to Projects
            </button>
        </div>

        <!-- Composer (same as before) -->
        <form class="composer" method="POST" enctype="multipart/form-data">
            <textarea name="text_msg" placeholder="Share an update with the team..." rows="3"></textarea>
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
        <?php while ($row = $postsResult->fetch_assoc()): 
            $avatar = $row['profile_picture'] ?? null;
            $avatarHtml = $avatar 
                ? "<img src='$avatar' class='post-avatar' alt='Avatar'>"
                : "<div class='avatar-placeholder'>" . strtoupper(substr($row['user_name'], 0, 1)) . "</div>";

            $isOwner = ($row['user_id'] == $userId);
        ?>
            <div class="post-card" id="post-<?= $row['id'] ?>">
                <div class="post-header">
                    <?= $avatarHtml ?>
                    <div class="post-meta">
                        <div class="name"><?= htmlspecialchars($row['user_name']) ?></div>
                        <div class="time"><?= timeAgo($row['created_at']) ?> ago</div>
                    </div>
                </div>

                <div class="post-content" id="content-<?= $row['id'] ?>">
                    <?= nl2br(htmlspecialchars($row['text_content'])) ?>
                </div>

                <?php if ($row['content_type'] === 'image' && $row['file_path']): ?>
                    <img src="<?= $row['file_path'] ?>" alt="Image" class="post-image">
                <?php elseif ($row['file_path']): ?>
                    <a href="download_zip.php?id=<?= $row['id'] ?>" class="file-link">
                        <i class="fas fa-file-zipper" style="font-size:32px; color:#ff9500;"></i>
                        <div>
                            <div style="font-weight:600;"><?= htmlspecialchars($row['file_name']) ?></div>
                            <div style="font-size:13px; color:var(--text-sec);">Download ZIP</div>
                        </div>
                    </a>
                <?php endif; ?>

                <div class="post-actions-extended">
                    <div class="action-btn" onclick="location.href='?project_id=<?= $projectId ?>&like=<?= $row['id'] ?>'">
                        <i class="fas fa-heart<?= $row['likes'] > 0 ? '' : '-regular' ?>"></i>
                        <span><?= $row['likes'] ?: 0 ?></span>
                    </div>
                    <div class="action-btn">
                        <i class="fas fa-comment-dots"></i>
                        <span>0</span>
                    </div>

                    <?php if ($isOwner): ?>
                        <div class="action-btn" onclick="toggleEdit(<?= $row['id'] ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </div>
                        <div class="action-btn delete-btn" onclick="showDeleteModal('?project_id=<?= $projectId ?>&delete=<?= $row['id'] ?>'); return false;">
    <i class="fas fa-trash-alt"></i> Delete
</div>
                    <?php endif; ?>
                </div>

                <!-- Edit form (hidden by default) -->
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
            </div>
        <?php endwhile; ?>

    </div>

    <!-- Sidebar: Current Group Members -->
    <div class="sidebar">
        <div class="sidebar-card">
            <div class="sidebar-title">Team Members (<?= $members->num_rows ?>)</div>
            <ul class="member-list">
                <?php while ($member = $members->fetch_assoc()): 
                    $avatar = $member['profile_picture'] 
                        ? "<img src='{$member['profile_picture']}' class='member-avatar' alt='Avatar'>"
                        : "<div class='avatar-placeholder' style='width:40px;height:40px;font-size:16px;'>" . strtoupper(substr($member['name'],0,1)) . "</div>";

                    $statusClass = ($member['status'] === 'online') ? 'online' : '';
                ?>
                    <li class="member-item">
                        <?= $avatar ?>
                        <div class="member-info">
                            <div class="member-name"><?= htmlspecialchars($member['name']) ?></div>
                            <div class="member-role"><?= ucfirst($member['role']) ?></div>
                        </div>
                        <div class="status-dot <?= $statusClass ?>"></div>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>
    </div>

</div>

<script>
// File preview 
function previewFile() {
    const file = document.getElementById('attachment').files[0];
    if (!file) return;
    const area = document.getElementById('preview-area');
    const img = document.getElementById('preview-img');
    const info = document.getElementById('preview-file');
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

// Toggle edit form
function toggleEdit(postId) {
    const content = document.getElementById('content-' + postId);
    const form = document.getElementById('edit-form-' + postId);
    if (form.style.display === 'block') {
        form.style.display = 'none';
        content.style.display = 'block';
    } else {
        form.style.display = 'block';
        content.style.display = 'none';
    }
}

// Modal delete logic
let deleteUrl = '';

function showDeleteModal(url) {
    deleteUrl = url;
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('deleteModal').style.display = 'none';
    deleteUrl = '';
}

// Attach listener once DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (deleteUrl) {
                this.disabled = true;
                this.textContent = 'Deleting...';
                window.location.href = deleteUrl;
            }
        });
    }

    // Optional: close modal when clicking outside content
    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
});
</script>

<div class="modal-overlay" id="deleteModal">
    <div class="modal-content">
        <div class="modal-title">Delete Post?</div>
        <p class="modal-text">This action cannot be undone. The post (and any file) will be permanently removed.</p>
        <div class="modal-buttons">
            <button class="modal-btn modal-cancel" onclick="closeModal()">Cancel</button>
            <button class="modal-btn modal-delete" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>
</body>
</html>