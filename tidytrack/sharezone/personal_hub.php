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

// Dashboard link (outside sharezone folder)
$dashboardLink = '../logout.php'; // fallback
if ($userRole === 'member') {
    $dashboardLink = '../member/member_dashboard.php';
} elseif ($userRole === 'leader') {
    $dashboardLink = '../leader/leader_index.php';
} elseif ($userRole === 'manager') {
    $dashboardLink = '../manager/manager_index.php';
} elseif ($userRole === 'admin') {
    $dashboardLink = '../admin/admin_index.php';
}

// === HANDLE NEW ITEM ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_personal'])) {
    $text = trim($_POST['personal_note'] ?? '');
    $file_path = $file_name = $file_type = null;
    $content_type = 'post';

    if (!empty($_FILES['personal_file']['tmp_name'])) {
        $targetDir = "uploads/personal/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $file_name = basename($_FILES['personal_file']['name']);
        $file_path = $targetDir . time() . "_" . $file_name;
        $file_type = mime_content_type($_FILES['personal_file']['tmp_name']);

        if (move_uploaded_file($_FILES['personal_file']['tmp_name'], $file_path)) {
            $content_type = strpos($file_type, 'image/') === 0 ? 'image' : 'file';
        }
    }

    if ($text !== '' || $file_path) {
        $stmt = $conn->prepare("
            INSERT INTO sharezone_data 
            (user_id, user_name, hub_type, content_type, text_content, file_path, file_name) 
            VALUES (?, ?, 'personal', ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssss", $userId, $userName, $content_type, $text, $file_path, $file_name);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: personal_hub.php");
    exit();
}

// === HANDLE EDIT ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_personal'])) {
    $itemId   = (int)$_POST['item_id'];
    $newText  = trim($_POST['new_note'] ?? '');

    // Security: only owner
    $check = $conn->prepare("SELECT id FROM sharezone_data WHERE id = ? AND user_id = ? AND hub_type = 'personal'");
    $check->bind_param("ii", $itemId, $userId);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        header("Location: personal_hub.php");
        exit();
    }
    $check->close();

    $stmt = $conn->prepare("UPDATE sharezone_data SET text_content = ? WHERE id = ?");
    $stmt->bind_param("si", $newText, $itemId);
    $stmt->execute();
    $stmt->close();

    header("Location: personal_hub.php#item-$itemId");
    exit();
}

// === HANDLE DELETE ===
if (isset($_GET['delete_id'])) {
    $delId = intval($_GET['delete_id']);

    $stmt = $conn->prepare("SELECT file_path FROM sharezone_data WHERE id = ? AND user_id = ? AND hub_type = 'personal'");
    $stmt->bind_param("ii", $delId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if ($row['file_path'] && file_exists($row['file_path'])) {
            @unlink($row['file_path']);
        }
        $delStmt = $conn->prepare("DELETE FROM sharezone_data WHERE id = ? AND user_id = ?");
        $delStmt->bind_param("ii", $delId, $userId);
        $delStmt->execute();
        $delStmt->close();
    }
    $stmt->close();
    header("Location: personal_hub.php");
    exit();
}

// === FETCH PERSONAL ITEMS ===
$itemsStmt = $conn->prepare("
    SELECT * FROM sharezone_data 
    WHERE user_id = ? AND hub_type = 'personal' 
    ORDER BY created_at DESC
");
$itemsStmt->bind_param("i", $userId);
$itemsStmt->execute();
$personalItems = $itemsStmt->get_result();

// === TASK & PROJECT STATS ===
$taskStatsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN (dueDate < CURDATE() AND status != 'Completed') THEN 1 ELSE 0 END) as overdue
    FROM tasks 
    WHERE assignTo = ?
");
$taskStatsStmt->bind_param("s", $userName);
$taskStatsStmt->execute();
$taskStats = $taskStatsStmt->get_result()->fetch_assoc();
$taskStatsStmt->close();

$projCountStmt = $conn->prepare("
    SELECT COUNT(DISTINCT p.project_id) as count
    FROM projects_x p
    LEFT JOIN tasks t ON p.project_id = t.project_id
    WHERE p.teamLeadder_id = ? 
       OR t.assignTo = ?
");
$projCountStmt->bind_param("is", $userId, $userName);
$projCountStmt->execute();
$projCount = $projCountStmt->get_result()->fetch_assoc()['count'] ?? 0;
$projCountStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Vault | ShareZone</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --mac-bg: linear-gradient(135deg, #f5f5f7 0%, #e5e5ea 100%);
            --card-bg: rgba(255,255,255,0.84);
            --accent: #007aff;
            --danger: #ff3b30;
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
            padding: 24px 16px;
        }

        .main-layout {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 32px;
        }

        @media (max-width: 980px) {
            .main-layout { grid-template-columns: 1fr; }
        }

        .vault-header {
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

        .btn {
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }

        .btn:hover {
            transform: translateY(-2px);
            background: white;
            box-shadow: 0 8px 24px rgba(0,0,0,0.14);
        }

        .save-btn {
            background: var(--accent);
            color: white;
            border: none;
        }

        .upload-card {
            background: var(--card-bg);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            margin-bottom: 40px;
        }

        textarea {
            width: 100%;
            min-height: 100px;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 16px;
            font-size: 17px;
            margin-bottom: 16px;
            background: rgba(255,255,255,0.5);
            resize: vertical;
        }

        input[type="file"] {
            font-size: 15px;
            color: var(--text-sec);
        }

        .item-card {
            background: var(--card-bg);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            transition: all 0.3s;
        }

        .item-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 60px rgba(0,0,0,0.14);
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .item-content { flex: 1; }

        .item-title {
            font-size: 17px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .item-note {
            font-size: 15px;
            color: var(--text-sec);
            white-space: pre-wrap;
        }

        .expiry-tag {
            font-size: 13px;
            color: var(--text-sec);
            background: rgba(0,0,0,0.05);
            padding: 4px 12px;
            border-radius: 999px;
            margin-top: 8px;
            display: inline-block;
        }

        .item-actions {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .action-icon {
            font-size: 20px;
            cursor: pointer;
            transition: color 0.2s;
        }

        .download-icon { color: var(--accent); }
        .edit-icon { color: var(--accent); }
        .delete-icon { color: var(--danger); }

        .sidebar-card {
            background: var(--card-bg);
            backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 32px;
        }

        .sidebar-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: rgba(0,122,255,0.08);
            border-radius: 16px;
            padding: 16px;
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 800;
            color: var(--accent);
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-sec);
        }

        .task-breakdown .task-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .task-row:last-child { border-bottom: none; }

        .task-status.completed { color: #34c759; }
        .task-status.inprogress { color: #ff9500; }
        .task-status.overdue { color: #ff3b30; }

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

        @media (max-width: 980px) {
            .sidebar-card { position: static; }
            .stats-grid { grid-template-columns: 1fr; }
        }
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

    <div class="vault-content">

        <div class="vault-header">
            <h1 class="title">My Private Vault</h1>
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <a href="<?= htmlspecialchars($dashboardLink) ?>" class="btn">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <button class="btn back-btn" onclick="location.href='index.php'">
                    <i class="fas fa-chevron-left"></i> Back to Hubs
                </button>
            </div>
        </div>

        <!-- Upload / Create form -->
        <form class="upload-card" method="POST" enctype="multipart/form-data">
            <textarea name="personal_note" placeholder="Write a private note, reminder or idea..." rows="3"></textarea>
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <input type="file" name="personal_file" style="font-size:15px; color:var(--text-sec);">
                <button type="submit" name="save_personal" class="save-btn">Save to Vault</button>
            </div>
        </form>

        <!-- Items -->
        <h3 style="font-size:16px; color:var(--text-sec); margin:0 0 20px 4px;">
            Your Items <small>(auto-delete in 7 days)</small>
        </h3>

        <?php if ($personalItems->num_rows === 0): ?>
            <div style="text-align:center; padding:60px 20px; background:var(--card-bg); border-radius:var(--radius); border:1px solid var(--border);">
                <i class="fas fa-lock" style="font-size:48px; color:var(--accent); margin-bottom:16px;"></i>
                <p style="font-size:18px; color:var(--text-sec);">Vault is empty</p>
                <p style="color:var(--text-sec);">Add notes or files — they stay private and expire after 7 days.</p>
            </div>
        <?php else: ?>
            <?php while ($item = $personalItems->fetch_assoc()): 
                $itemId = $item['id'];
                $isImage = $item['content_type'] === 'image' && $item['file_path'];
            ?>
                <div class="item-card" id="item-<?= $itemId ?>">
                    <div class="item-header">
                        <div class="item-content">
                            <?php if ($isImage): ?>
                                <img src="<?= htmlspecialchars($item['file_path']) ?>" alt="Image" style="max-width:100%; border-radius:12px; margin-bottom:12px; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                            <?php endif; ?>

                            <div class="item-title">
                                <?php if ($item['content_type'] === 'file'): ?>
                                    <i class="fas fa-file" style="color:var(--accent); margin-right:8px;"></i>
                                    <?= htmlspecialchars($item['file_name']) ?>
                                <?php else: ?>
                                    <i class="fas fa-sticky-note" style="color:#ffcc00; margin-right:8px;"></i>
                                    Private Note
                                <?php endif; ?>
                            </div>

                            <?php if ($item['text_content']): ?>
                                <div class="item-note" id="note-<?= $itemId ?>">
                                    <?= nl2br(htmlspecialchars($item['text_content'])) ?>
                                </div>
                            <?php endif; ?>

                            <div class="expiry-tag">
                                Expires: <?= date('d M Y', strtotime($item['expires_at'])) ?>
                            </div>
                        </div>

                        <div class="item-actions">
                            <?php if ($item['file_path']): ?>
                                <a href="<?= htmlspecialchars($item['file_path']) ?>" download class="action-icon download-icon">
                                    <i class="fas fa-download"></i>
                                </a>
                            <?php endif; ?>

                            <div class="action-icon edit-icon" onclick="toggleEdit(<?= $itemId ?>)">
                                <i class="fas fa-edit"></i>
                            </div>

                            <a href="#" class="action-icon delete-icon" onclick="showDeleteModal('?delete_id=<?= $itemId ?>'); return false;">
    <i class="fas fa-trash-alt"></i>
</a>
                        </div>
                    </div>

                    <!-- Edit form -->
                    <form method="POST" class="edit-form" id="edit-form-<?= $itemId ?>">
                        <input type="hidden" name="item_id" value="<?= $itemId ?>">
                        <textarea name="new_note" id="edit-text-<?= $itemId ?>"><?= htmlspecialchars($item['text_content'] ?? '') ?></textarea>
                        <div class="edit-buttons">
                            <button type="submit" name="edit_personal" class="save-btn">Save</button>
                            <button type="button" class="cancel-btn" onclick="toggleEdit(<?= $itemId ?>)">Cancel</button>
                        </div>
                    </form>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>

    </div>

    <!-- Sidebar: Task & Project Stats -->
    <div class="sidebar">
        <div class="sidebar-card">
            <div class="sidebar-title">Your Activity</div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $projCount ?></div>
                    <div class="stat-label">Projects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $taskStats['total'] ?? 0 ?></div>
                    <div class="stat-label">Tasks</div>
                </div>
            </div>

            <div class="task-breakdown">
                <div class="task-row">
                    <span>In Progress</span>
                    <span class="task-status inprogress"><?= $taskStats['in_progress'] ?? 0 ?></span>
                </div>
                <div class="task-row">
                    <span>Completed</span>
                    <span class="task-status completed"><?= $taskStats['completed'] ?? 0 ?></span>
                </div>
                <div class="task-row">
                    <span>Overdue</span>
                    <span class="task-status overdue"><?= $taskStats['overdue'] ?? 0 ?></span>
                </div>
            </div>

            <a href="<?= htmlspecialchars($dashboardLink) ?>" class="btn" style="margin-top:24px; width:100%; justify-content:center; display:flex;">
                <i class="fas fa-tachometer-alt"></i> Open Dashboard
            </a>
        </div>
    </div>

</div>

<script>
// Toggle edit (unchanged)
function toggleEdit(itemId) {
    const note = document.getElementById('note-' + itemId);
    const form = document.getElementById('edit-form-' + itemId);
    if (form.style.display === 'block') {
        form.style.display = 'none';
        if (note) note.style.display = 'block';
    } else {
        form.style.display = 'block';
        if (note) note.style.display = 'none';
    }
}

let deleteUrl = '';

// Show modal and store the URL to delete
function showDeleteModal(url) {
    deleteUrl = url;
    document.getElementById('deleteModal').style.display = 'flex';
}

// Close modal
function closeModal() {
    document.getElementById('deleteModal').style.display = 'none';
    deleteUrl = '';
}

// Attach listener to Delete button (run once DOM is ready)
document.addEventListener('DOMContentLoaded', function() {
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (deleteUrl) {
                window.location.href = deleteUrl;
            }
        });
    }
});
</script>


<div class="modal-overlay" id="deleteModal">
    <div class="modal-content">
        <div class="modal-title">Delete Item?</div>
        <p class="modal-text">This action cannot be undone. The item will be permanently removed from your vault.</p>
        <div class="modal-buttons">
            <button class="modal-btn modal-cancel" onclick="closeModal()">Cancel</button>
            <button class="modal-btn modal-delete" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>
</body>
</html>