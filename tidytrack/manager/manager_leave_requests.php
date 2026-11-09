<?php
// admin_leave_requests.php (UPDATED & DARK MODE FIXED)
session_start();
require_once __DIR__ . '/../dp.php';

// Access control
$userId = $_SESSION['user_id'] ?? null;
$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || !in_array($role, ['admin','manager'], true)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// Helper to check if manager_id exists
function column_exists(mysqli $conn, $table, $column) {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c' LIMIT 1";
    $res = $conn->query($sql);
    $exists = $res && $res->num_rows > 0;
    if ($res) $res->free();
    return $exists;
}

// Handle approve/reject POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['action'])) {
    header('Content-Type: text/plain; charset=utf-8');

    $id = (int) $_POST['id'];
    $actionRaw = strtolower(trim($_POST['action']));

    if (!in_array($actionRaw, ['approve', 'reject'])) {
        http_response_code(400);
        echo "Invalid action";
        exit;
    }

    $status = ($actionRaw === 'approve') ? 'approved' : 'rejected';

    // accepts both comment and manager_comment
    $commentInput = $_POST['comment'] ?? ($_POST['manager_comment'] ?? '');
    $comment = substr(trim($commentInput), 0, 1000);

    if (!isset($conn) || !($conn instanceof mysqli)) {
        http_response_code(500);
        echo "Database connection missing.";
        exit;
    }

    $has_manager_id = column_exists($conn, 'leave_requests', 'manager_id');

    if ($has_manager_id) {
        $stmt = $conn->prepare("
            UPDATE leave_requests
            SET status = ?, manager_id = ?, comment = ?, decided_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        if (!$stmt) {
            http_response_code(500);
            echo "Prepare failed: " . $conn->error;
            exit;
        }
        $stmt->bind_param('sisi', $status, $userId, $comment, $id);

    } else {
        $stmt = $conn->prepare("
            UPDATE leave_requests
            SET status = ?, comment = ?, decided_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        if (!$stmt) {
            http_response_code(500);
            echo "Prepare failed: " . $conn->error;
            exit;
        }
        $stmt->bind_param('ssi', $status, $comment, $id);
    }

    if (!$stmt->execute()) {
        echo "Execute failed: " . htmlspecialchars($stmt->error);
        exit;
    }

    echo ($stmt->affected_rows > 0) ? "OK" : "No rows updated";
    exit;
}

// Fetch leave requests
$rows = [];
$sql = "
    SELECT lr.*, COALESCE(u.name, u.email) AS employee_ref, u.name AS employee_name
    FROM leave_requests lr
    LEFT JOIN users u ON u.user_id = lr.employee_id
    ORDER BY lr.created_at DESC
";
if ($res = $conn->query($sql)) $rows = $res->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Leave Requests</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
    --overlay: rgba(0,0,0,0.55);
    --success: #22c55e;
    --success-hover: #16a34a;
    --danger: #ef4444;
    --danger-hover: #dc2626;
    --pending: #f59e0b;
}

[data-theme="dark"] {
    --bg: #0f172a;
    --card: #1e293b;
    --card-2: #0f172a;
    --text: #e2e8f0;
    --muted: #94a3b8;
    --border: #334155;
    --shadow: 0 12px 30px rgba(0,0,0,0.35);
    --accent: #60a5fa;
    --accent-hover: #3b82f6;
    --overlay: rgba(2,6,23,0.75);
    --success: #22c55e;
    --success-hover: #16a34a;
    --danger: #f87171;
    --danger-hover: #ef4444;
    --pending: #fbbf24;
}

* {
    box-sizing: border-box;
}

html, body {
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    padding: 20px;
    background: var(--bg);
    color: var(--text);
    transition: background .25s ease, color .25s ease;
}

h1 {
    color: var(--accent);
    font-size: 24px;
    text-align: center;
    margin: 0 0 10px;
}

p {
    text-align: center;
    margin-bottom: 15px;
    font-size: 15px;
    color: var(--muted);
}

/* Table Styling */
.table-container {
    margin-top: 15px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
    overflow: hidden;
}

table {
    border-collapse: collapse;
    width: 100%;
    background: var(--card);
}

th, td {
    border: 1px solid var(--border);
    padding: 10px;
    text-align: left;
    font-size: 14px;
    color: var(--text);
}

th {
    background: var(--accent);
    color: #fff;
    text-transform: uppercase;
}

tbody tr {
    background: var(--card);
}

tbody tr:nth-child(even) {
    background: var(--card-2);
}

tbody tr:hover {
    background: rgba(96, 165, 250, 0.08);
}

/* Status Colors */
.status-pending { color: var(--pending); font-weight: bold; }
.status-approved { color: var(--success); font-weight: bold; }
.status-rejected { color: var(--danger); font-weight: bold; }

/* Buttons */
.btn-view {
    background: var(--accent);
    color: #fff;
    border: none;
    padding: 7px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.25s ease;
}
.btn-view:hover { background: var(--accent-hover); }

@media(max-width:768px){
    h1 { font-size: 20px; }
    th,td { padding: 8px; font-size: 13px; }
}
@media(max-width:480px){
    body { padding: 12px; }
    th,td { padding: 6px; font-size: 12px; }
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    background: var(--overlay);
    z-index: 9999;
    padding: 20px;
}

.modal-content {
    background: var(--card);
    color: var(--text);
    max-width: 900px;
    margin: 30px auto;
    border-radius: 12px;
    padding: 20px;
    position: relative;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
}

.modal-content button.close-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    font-size: 22px;
    border: none;
    background: transparent;
    color: var(--text);
    cursor: pointer;
}

.modal-footer {
    margin-top: 20px;
    text-align: right;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}

.modal-footer button {
    padding: 8px 14px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    color: #fff;
}

.modal-footer .approve-btn { background: var(--success); }
.modal-footer .reject-btn { background: var(--danger); }
.modal-footer .approve-btn:hover { background: var(--success-hover); }
.modal-footer .reject-btn:hover { background: var(--danger-hover); }

#viewBody {
    color: var(--text);
}

#viewBody table,
#viewBody td,
#viewBody th,
#viewBody p,
#viewBody div,
#viewBody span,
#viewBody strong,
#viewBody small,
#viewBody label {
    color: inherit;
}

#viewBody table {
    width: 100%;
    border-collapse: collapse;
    background: var(--card);
}

#viewBody td,
#viewBody th {
    border: 1px solid var(--border);
    padding: 10px;
}

#viewBody input,
#viewBody textarea,
#viewBody select {
    width: 100%;
    background: var(--card-2);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 8px 10px;
}

.text-muted {
    color: var(--muted);
}
</style>
</head>
<body>

<h1>Leave Requests</h1>
<p>Managers can view all leave requests and approve or reject them.</p>

<div class="table-container">
<table>
    <thead>
        <tr>
            <th>Employee</th>
            <th>From</th>
            <th>To</th>
            <th>Type</th>
            <th>Status</th>
            <th>Submitted</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if($rows): ?>
            <?php foreach($rows as $r):
                $status = $r['status'];
                if($status === 'pending') $statusClass = 'status-pending';
                elseif($status === 'approved') $statusClass = 'status-approved';
                elseif($status === 'rejected') $statusClass = 'status-rejected';
                else $statusClass = '';
            ?>
            <tr>
                <td><?php echo htmlspecialchars($r['employee_name'] . ' — ' . $r['employee_ref']); ?></td>
                <td><?php echo htmlspecialchars($r['start_date']); ?></td>
                <td><?php echo htmlspecialchars($r['end_date']); ?></td>
                <td><?php echo htmlspecialchars(str_replace('_',' ',$r['leave_type'])); ?></td>
                <td class="<?php echo $statusClass; ?>"><?php echo ucfirst($r['status']); ?></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td>
                    <button class="btn-view" onclick="openView(<?php echo (int)$r['id']; ?>)">
                        <i class="fas fa-eye"></i> View
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="7" style="text-align:center;">No leave requests found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
</div>

<!-- View Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <button class="close-btn" onclick="closeModal()">&times;</button>
        <div id="viewBody"><div class="text-muted" style="text-align:center;">Loading...</div></div>
        <div id="decisionButtons" class="modal-footer" style="display:none;">
            <button id="approveBtn" class="approve-btn" onclick="openDecision('approve')">
                <i class="fas fa-check"></i> Approve
            </button>
            <button id="rejectBtn" class="reject-btn" onclick="openDecision('reject')">
                <i class="fas fa-times"></i> Reject
            </button>
        </div>
    </div>
</div>

<script>
function applyTheme(theme) {
    const finalTheme = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', finalTheme);
    document.body.setAttribute('data-theme', finalTheme);
}

const savedTheme = localStorage.getItem('theme') || localStorage.getItem('mode') || 'light';
applyTheme(savedTheme);

window.addEventListener('storage', function (e) {
    if (e.key === 'theme' || e.key === 'mode') {
        applyTheme(e.newValue || 'light');
    }
});

let currentLeaveId = null;

function openView(id){
    currentLeaveId = id;

    const modal = document.getElementById('viewModal');
    const body = document.getElementById('viewBody');
    const decisionButtons = document.getElementById('decisionButtons');

    body.innerHTML = '<div class="text-muted" style="text-align:center;">Loading...</div>';
    decisionButtons.style.display = 'none';
    modal.style.display = 'block';

    const url = './manager_leave_view.php?id=' + encodeURIComponent(id);

    fetch(url, { credentials: 'same-origin' })
    .then(response => response.text().then(text => ({ ok: response.ok, status: response.status, text: text })))
    .then(obj => {
        if (!obj.ok) {
            body.innerHTML =
                '<div style="color:var(--danger);">Error loading details (status: ' + obj.status + ')</div>' +
                '<div style="white-space:pre-wrap;margin-top:8px;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--card-2);color:var(--text);">' +
                escapeHtml(obj.text) + '</div>';
            return;
        }

        body.innerHTML = obj.text;

        // force dark styles inside loaded view content
        body.querySelectorAll('*').forEach(el => {
            if (!el.style.backgroundColor || el.style.backgroundColor === 'white' || el.style.backgroundColor === 'rgb(255, 255, 255)') {
                el.style.color = 'inherit';
            }
        });

        const statusEl = document.getElementById('leaveStatus');
        let status = '';
        if (statusEl) status = statusEl.textContent.trim().toLowerCase();

        if (status === 'pending' || status === '') {
            decisionButtons.style.display = 'flex';
        } else {
            decisionButtons.style.display = 'none';
        }
    })
    .catch(err => {
        body.innerHTML = '<div style="color:var(--danger);">Network error: ' + String(err) + '</div>';
        console.error('openView fetch error:', err);
    });
}

function closeModal(){
    document.getElementById('viewModal').style.display = 'none';
    document.getElementById('viewBody').innerHTML = '';
    currentLeaveId = null;
}

function openDecision(action){
    if (!currentLeaveId) return alert('No leave selected.');

    var comment = prompt('Optional comment for ' + action + ':', '');
    if (comment === null) return;

    const data = new FormData();
    data.append('id', currentLeaveId);
    data.append('action', action);
    data.append('manager_comment', comment);
    data.append('comment', comment);

    fetch('./manager_leave_requests.php', { method: 'POST', body: data, credentials: 'same-origin' })
    .then(r => r.text())
    .then(text => {
        if (text && text.toLowerCase().indexOf('ok') === -1) {
            alert('Server response: ' + text);
        }
        closeModal();

        if (typeof loadPage === 'function') {
            loadPage('manager_leave_requests.php');
        } else {
            location.reload();
        }
    })
    .catch(err => {
        alert('Error sending decision: ' + err);
    });
}

function escapeHtml(unsafe) {
    return String(unsafe)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
}
</script>

</body>
</html>