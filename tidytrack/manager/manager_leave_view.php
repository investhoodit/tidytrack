<?php
session_start();
require_once __DIR__ . '/../dp.php';
header('Content-Type: text/html; charset=utf-8');

if (!isset($_GET['id'])) {
    echo "<div class='alert-box alert-danger-custom'>Missing ID.</div>";
    exit;
}

$id = (int) $_GET['id'];

$userId = $_SESSION['user_id'] ?? null;
$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!$userId || !in_array($role, ['admin', 'manager'], true)) {
    echo "<div class='alert-box alert-danger-custom'>Forbidden.</div>";
    exit;
}

$sql = "SELECT lr.*, 
               COALESCE(u.name, u.email) AS employee_ref,
               u.name AS employee_name
        FROM leave_requests lr
        LEFT JOIN users u ON u.user_id = lr.employee_id
        WHERE lr.id = ? LIMIT 1";

$detail = null;
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$detail) {
    echo "<div class='alert-box alert-danger-custom'>Request not found.</div>";
    exit;
}

/* ---------------------------------------------------
   Supporting Documents
--------------------------------------------------- */
$filesHtml = "<p class='empty-note'>No attachments found.</p>";
$check = $conn->query("
    SELECT 1 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'leave_files'
");

if ($check && $check->num_rows) {
    $fstmt = $conn->prepare("
        SELECT id, file_name, file_path, created_at
        FROM leave_files
        WHERE leave_request_id = ?
        ORDER BY id DESC
    ");
    $fstmt->bind_param('i', $detail['id']);
    $fstmt->execute();
    $files = $fstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fstmt->close();

    if (!empty($files)) {
        $list = "<ul class='files-list'>";
        foreach ($files as $f) {
            $filePath = htmlspecialchars($f['file_path']);
            $fileName = htmlspecialchars($f['file_name']);
            $created  = htmlspecialchars($f['created_at']);

            $list .= "
                <li class='file-item'>
                    <a href='{$filePath}' target='_blank' class='file-link'>
                        <i class='fa fa-file'></i> {$fileName}
                    </a>
                    <span class='file-badge'>{$created}</span>
                </li>";
        }
        $list .= "</ul>";
        $filesHtml = $list;
    }
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>

<style>
:root {
    --bg: #f9fafb;
    --card: #ffffff;
    --card-2: #f3f4f6;
    --text: #1f2937;
    --muted: #6b7280;
    --border: #d1d5db;
    --shadow: 0 8px 24px rgba(0,0,0,0.08);
    --accent: #1d3557;
    --accent-hover: #457b9d;
    --success: #22c55e;
    --success-hover: #16a34a;
    --danger: #ef4444;
    --warning: #f59e0b;
    --blockquote-bg: #f8fafc;
    --badge-bg: #e5e7eb;
}

:root[data-theme="dark"] {
    --bg: #0f172a;
    --card: #1e293b;
    --card-2: #0b1220;
    --text: #e2e8f0;
    --muted: #94a3b8;
    --border: #334155;
    --shadow: 0 12px 30px rgba(0,0,0,0.35);
    --accent: #60a5fa;
    --accent-hover: #3b82f6;
    --success: #22c55e;
    --success-hover: #16a34a;
    --danger: #f87171;
    --warning: #fbbf24;
    --blockquote-bg: #111827;
    --badge-bg: #334155;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: transparent;
    color: var(--text);
    font-family: Arial, sans-serif;
}

.view-card {
    max-width: 900px;
    margin: 20px auto;
    padding: 25px;
    border-radius: 14px;
    background: var(--card);
    color: var(--text);
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
}

.header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.page-title {
    margin: 0;
    font-size: 22px;
    color: var(--text);
}

.section-title {
    font-size: 17px;
    font-weight: 700;
    margin-top: 18px;
    margin-bottom: 10px;
    color: var(--accent);
}

.field-text {
    font-size: 15px;
    margin-bottom: 8px;
    color: var(--text);
    line-height: 1.5;
}

.field-text strong {
    color: var(--text);
}

hr {
    border: none;
    border-top: 1px solid var(--border);
    margin: 18px 0;
}

.export-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    background: var(--success);
    color: #fff;
    padding: 10px 14px;
    border-radius: 8px;
    font-weight: 600;
    transition: background .2s ease;
}

.export-btn:hover {
    background: var(--success-hover);
}

.reason-box {
    background: var(--blockquote-bg);
    color: var(--muted);
    font-style: italic;
    padding: 12px 14px;
    border-left: 4px solid var(--accent);
    border-radius: 8px;
    margin-top: 10px;
    white-space: pre-wrap;
}

.files-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    gap: 10px;
}

.file-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--card-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    flex-wrap: wrap;
}

.file-link {
    color: var(--accent);
    text-decoration: none;
    font-weight: 600;
    word-break: break-word;
}

.file-link:hover {
    color: var(--accent-hover);
    text-decoration: underline;
}

.file-badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 999px;
    background: var(--badge-bg);
    color: var(--text);
    font-size: 12px;
    white-space: nowrap;
}

.empty-note,
.footer-note {
    color: var(--muted);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    text-transform: capitalize;
    border: 1px solid var(--border);
}

.status-pending {
    color: #fff;
    background: var(--warning);
    border-color: var(--warning);
}

.status-approved {
    color: #fff;
    background: var(--success);
    border-color: var(--success);
}

.status-rejected {
    color: #fff;
    background: var(--danger);
    border-color: var(--danger);
}

.status-other {
    color: var(--text);
    background: var(--badge-bg);
}

.alert-box {
    margin: 20px;
    padding: 12px 14px;
    border-radius: 10px;
    font-weight: 600;
    border: 1px solid transparent;
}

.alert-danger-custom {
    background: rgba(239, 68, 68, 0.12);
    color: var(--danger);
    border-color: rgba(239, 68, 68, 0.3);
}

.meta-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(220px, 1fr));
    gap: 10px 20px;
}

@media (max-width: 700px) {
    .view-card {
        padding: 18px;
        margin: 10px;
    }

    .meta-grid {
        grid-template-columns: 1fr;
    }

    .header-row {
        align-items: flex-start;
    }

    .export-btn {
        width: 100%;
        justify-content: center;
    }

    .file-item {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<?php
$status = strtolower(trim((string)($detail['status'] ?? '')));
$statusClass = 'status-other';
if ($status === 'pending') $statusClass = 'status-pending';
elseif ($status === 'approved') $statusClass = 'status-approved';
elseif ($status === 'rejected') $statusClass = 'status-rejected';
?>

<div class="view-card">
    <div class="header-row">
        <h4 class="page-title"><i class="fa fa-eye"></i> Leave Request Details</h4>

        <a href="leave_export_pdf.php?id=<?= $id ?>" class="export-btn">
            <i class="fa fa-file-export"></i> Export Full Report (PDF)
        </a>
    </div>

    <hr>

    <div class="section-title">Employee Information</div>
    <div class="meta-grid">
        <div class="field-text"><strong>Name:</strong> <?= htmlspecialchars($detail['employee_name']); ?></div>
        <div class="field-text"><strong>Reference:</strong> <?= htmlspecialchars($detail['employee_ref']); ?></div>
    </div>

    <hr>

    <div class="section-title">Leave Duration</div>
    <div class="meta-grid">
        <div class="field-text"><strong>From:</strong> <?= htmlspecialchars($detail['start_date']); ?></div>
        <div class="field-text"><strong>To:</strong> <?= htmlspecialchars($detail['end_date']); ?></div>
        <div class="field-text"><strong>Type:</strong> <?= htmlspecialchars(str_replace('_', ' ', $detail['leave_type'])); ?></div>
    </div>

    <hr>

    <div class="section-title">Reason</div>
    <div class="field-text"><?= htmlspecialchars($detail['reason_category']); ?></div>

    <?php if (!empty($detail['reason_text'])): ?>
        <div class="reason-box"><?= nl2br(htmlspecialchars($detail['reason_text'])); ?></div>
    <?php endif; ?>

    <hr>

    <div class="section-title">Status & Manager Decision</div>

    <div class="field-text">
        <strong>Status:</strong>
        <span id="leaveStatus" class="status-badge <?= $statusClass; ?>">
            <?= htmlspecialchars($detail['status']); ?>
        </span>
    </div>

    <div class="field-text">
        <strong>Manager Comment:</strong><br>
        <?= nl2br(htmlspecialchars($detail['comment'] ?: 'No comment')); ?>
    </div>

    <div class="field-text">
        <strong>Decided At:</strong> <?= htmlspecialchars($detail['decided_at'] ?: '—'); ?>
    </div>

    <hr>

    <div class="section-title">Supporting Documents</div>
    <?= $filesHtml ?>

    <hr>

    <div class="footer-note">
        <strong>Submitted At:</strong> <?= htmlspecialchars($detail['created_at']); ?>
    </div>
</div>

<script>
(function() {
    function applyTheme(theme) {
        const finalTheme = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', finalTheme);
        document.body.setAttribute('data-theme', finalTheme);
    }

    const savedTheme = localStorage.getItem('theme') || localStorage.getItem('mode') || 'light';
    applyTheme(savedTheme);

    window.addEventListener('storage', function(e) {
        if (e.key === 'theme' || e.key === 'mode') {
            applyTheme(e.newValue || 'light');
        }
    });
})();
</script>