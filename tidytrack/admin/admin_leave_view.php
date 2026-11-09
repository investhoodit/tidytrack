<?php
session_start();
require_once __DIR__ . '/../dp.php';
header('Content-Type: text/html; charset=utf-8');

if (!isset($_GET['id'])) { echo "<div class='alert alert-danger'>Missing ID.</div>"; exit; }
$id = (int) $_GET['id'];

$userId = $_SESSION['user_id'] ?? null;
$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || !in_array($role, ['admin','manager'], true)) {
    echo "<div class='alert alert-danger'>Forbidden.</div>";
    exit;
}

$sql = "SELECT lr.*, 
               COALESCE(u.name, u.email) AS employee_ref,
               u.name AS employee_name
        FROM leave_requests lr
        LEFT JOIN users u ON u.user_id = lr.employee_id
        WHERE lr.id = ? LIMIT 1";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$detail) { echo "<div class='alert alert-danger'>Request not found.</div>"; exit; }


/* =======================================================================
   FIXED ATTACHMENTS SECTION — SUPPORTS BOTH leave_files + attachment_path
   ======================================================================= */

$filesHtml = "<p class='text-muted'>No attachments found.</p>";

$hasLeaveFilesTable = $conn->query("
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_files'
");

// 1️⃣ Check multi-file system first
if ($hasLeaveFilesTable && $hasLeaveFilesTable->num_rows > 0) {

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
        $list = "<ul class='list-group'>";
        foreach ($files as $f) {
            $path = htmlspecialchars($f['file_path']);
            $name = htmlspecialchars($f['file_name']);
            $created = htmlspecialchars($f['created_at']);

            $list .= "
                <li class='list-group-item d-flex justify-content-between align-items-center'>
                    <a href='{$path}' target='_blank' class='fw-bold text-primary'>
                        <i class='fa fa-file'></i> {$name}
                    </a>
                    <span class='badge bg-secondary'>{$created}</span>
                </li>";
        }
        $list .= "</ul>";
        $filesHtml = $list;
    }

// 2️⃣ Fallback: use old single attachment field
} else {

    if (!empty($detail['attachment_path'])) {

        $path = htmlspecialchars($detail['attachment_path']);
        $name = htmlspecialchars(basename($detail['attachment_path']));

        $filesHtml = "
        <ul class='list-group'>
            <li class='list-group-item'>
                <a href='{$path}' target='_blank' class='fw-bold text-primary'>
                    <i class='fa fa-file'></i> {$name}
                </a>
            </li>
        </ul>";
    }
}

?>
<!-- STYLING + HTML BELOW (unchanged from your version) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>

<style>
:root {
    --bg: #f9f9f9;
    --card-bg: #ffffff;
    --ink: #333;
    --border: #ddd;
    --primary: #1d3557;
    --primary-light: #457b9d;
}
.dark {
    --bg: #0f172a;
    --card-bg: #1e293b;
    --ink: #f1f5f9;
    --border: #334155;
    --primary: #a5d8ff;
    --primary-light: #74c0fc;
}
body {
    background: var(--bg) !important;
    color: var(--ink) !important;
    padding: 20px;
}
.view-card {
    max-width: 900px;
    margin: 20px auto;
    padding: 25px;
    border-radius: 14px;
    background: var(--card-bg);
    color: var(--ink);
    border: 1px solid var(--border);
    box-shadow: 0 2px 12px rgba(0,0,0,0.15);
}
.section-title {
    font-size: 17px;
    font-weight: 700;
    margin-top: 18px;
    color: var(--primary);
}
.export-btn {
    float: right;
    margin-top: -10px;
    background: var(--primary) !important;
}
.export-btn:hover {
    background: var(--primary-light) !important;
}
.list-group-item {
    background: var(--card-bg) !important;
    border-color: var(--border);
}
.text-primary { color: var(--primary) !important; }
.badge.bg-secondary { background: var(--primary-light) !important; }
</style>

<div class="view-card">

    <a href="leave_export_pdf.php?id=<?= $id ?>" class="btn btn-success export-btn">
        <i class="fa fa-file-export"></i> Export Full Report (PDF)
    </a>

    <h4><i class="fa fa-eye"></i> Leave Request Details</h4>
    <hr>

    <div class="section-title">Employee Information</div>
    <div><strong>Name:</strong> <?= htmlspecialchars($detail['employee_name']); ?></div>
    <div><strong>Reference:</strong> <?= htmlspecialchars($detail['employee_ref']); ?></div>

    <hr>

    <div class="section-title">Leave Duration</div>
    <div><strong>From:</strong> <?= htmlspecialchars($detail['start_date']); ?></div>
    <div><strong>To:</strong> <?= htmlspecialchars($detail['end_date']); ?></div>
    <div><strong>Type:</strong> <?= htmlspecialchars(str_replace('_',' ', $detail['leave_type'])); ?></div>

    <hr>

    <div class="section-title">Reason</div>
    <div><?= htmlspecialchars($detail['reason_category']); ?></div>

    <?php if (!empty($detail['reason_text'])): ?>
        <blockquote class="text-muted fst-italic p-2 border-start border-3">
            <?= nl2br(htmlspecialchars($detail['reason_text'])); ?>
        </blockquote>
    <?php endif; ?>

    <hr>

    <div class="section-title">Status & Manager Decision</div>
    <div><strong>Status:</strong> <?= htmlspecialchars($detail['status']); ?></div>
    <div><strong>Comment:</strong><br><?= nl2br(htmlspecialchars($detail['comment'] ?: 'No comment')); ?></div>
    <div><strong>Decided At:</strong> <?= htmlspecialchars($detail['decided_at'] ?: '—'); ?></div>

    <hr>

    <div class="section-title">Supporting Documents</div>
    <?= $filesHtml ?>

    <hr>

    <div class="text-muted small">
        <strong>Submitted At:</strong> <?= htmlspecialchars($detail['created_at']); ?>
    </div>
</div>
