<?php
// admin_leave_requests.php (UPDATED & FIXED)
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
    $comment = substr(trim($_POST['comment'] ?? ''), 0, 1000);

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
/* =============== GLOBAL =============== */
:root{
  --bg:#f9f9f9;
  --ink:#1f2937;
  --muted:#6b7280;
  --card-bg:#ffffff;
  --border:#e5e7eb;

  --table-even:#f8fafc;
  --table-odd:#ffffff;

  --primary:#1d3557;
  --primary-light:#457b9d;

  --success:#28a745;
  --danger:#dc3545;

  --th-ink:#ffffff; /* header text */
}

/* Dark mode variables (enabled by adding class="dark" on body or html) */
.dark{
  --bg:#0f172a;
  --ink:#f1f5f9;
  --muted:#94a3b8;

  --card-bg:#111827;
  --border:#243244;

  --table-even:#0b1220;
  --table-odd:#0f172a;

  /* Keep header/button backgrounds dark enough so text is readable */
  --primary:#1d3557;
  --primary-light:#274c77;

  --success:#4ade80;
  --danger:#f87171;

  --th-ink:#ffffff;
}

/* =============== BASE PAGE =============== */
*{ box-sizing:border-box; }

body{
  font-family: Arial, sans-serif;
  margin:20px;
  background:var(--bg);
  color:var(--ink);
  transition:background .25s ease, color .25s ease;
}

h1{
  color:var(--primary);
  font-size:24px;
  text-align:center;
  margin: 0 0 8px;
}

p{
  text-align:center;
  margin:0 0 15px;
  font-size:15px;
  color: var(--muted);
}

/* =============== TABLE =============== */
.table-container{
  margin-top:15px;
  background:var(--card-bg);
  border:1px solid var(--border);
  border-radius:12px;
  box-shadow:0 6px 18px rgba(0,0,0,.06);
  overflow-x:auto; /* ✅ keeps headings visible on small screens */
}

table{
  border-collapse:separate;
  border-spacing:0;
  width:100%;
  min-width: 900px; /* ✅ prevents squashed columns */
  background:var(--card-bg);
  color:var(--ink);
}

thead th{
  position: sticky;
  top: 0;
  z-index: 1;
}

th, td{
  border-bottom:1px solid var(--border);
  padding:12px 12px;
  text-align:left;
  font-size:14px;
  vertical-align: middle;
  white-space: nowrap;
}

th{
  background:var(--primary);
  color:var(--th-ink);
  text-transform:uppercase;
  letter-spacing:.04em;
  font-size:12px;
}

/* striping ONLY on tbody rows (so header never gets overridden) */
tbody tr:nth-child(even){ background:var(--table-even); }
tbody tr:nth-child(odd){ background:var(--table-odd); }

tbody tr:hover{
  filter: brightness(0.98);
}

/* STATUS COLORS */
.status-pending{ color: var(--danger); font-weight:700; }
.status-approved{ color: var(--success); font-weight:700; }
.status-rejected{ color: var(--danger); font-weight:700; }

/* VIEW BUTTON */
.btn-view{
  display:inline-flex;
  align-items:center;
  gap:8px;
  background:var(--primary);
  color:#fff;
  border:1px solid rgba(255,255,255,.08);
  padding:8px 12px;
  border-radius:10px;
  cursor:pointer;
  font-size:13px;
  font-weight:600;
  line-height:1;
  box-shadow:0 6px 14px rgba(0,0,0,.12);
  transition: transform .15s ease, background .2s ease, box-shadow .2s ease;
}

.btn-view i{ font-size:14px; }

.btn-view:hover{
  background:var(--primary-light);
  transform: translateY(-1px);
  box-shadow:0 10px 20px rgba(0,0,0,.16);
}

/* =============== MODAL =============== */
.modal{
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.55);
  z-index:9999;
  padding: 18px;
}

.modal-content{
  background:var(--card-bg);
  max-width:900px;
  margin:40px auto;
  border-radius:14px;
  padding:18px 18px 14px;
  position:relative;
  box-shadow:0 18px 60px rgba(0,0,0,0.35);
  color:var(--ink);
  border:1px solid var(--border);
}

.modal-content button.close-btn{
  position:absolute;
  top:10px;
  right:12px;
  font-size:26px;
  border:none;
  background:none;
  cursor:pointer;
  color:var(--ink);
}

.modal-footer{
  margin-top:16px;
  display:flex;
  justify-content:flex-end;
  gap:10px;
}

.modal-footer button{
  padding:10px 12px;
  border:none;
  border-radius:10px;
  cursor:pointer;
  color:#0b1220;
  font-weight:700;
  display:inline-flex;
  align-items:center;
  gap:8px;
}

.modal-footer .approve-btn{ background: var(--success); }
.modal-footer .reject-btn{ background: var(--danger); }

.modal-footer .approve-btn:hover,
.modal-footer .reject-btn:hover{ opacity:.9; }
</style>
</head>

<body>

<h1>Leave Requests</h1>
<p>Admin / Manager can view all leave requests and approve or reject them.</p>

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
    <?php if($rows): foreach($rows as $r): ?>
      <?php
        $cls = ($r['status'] === 'pending') ? 'status-pending' :
               (($r['status'] === 'approved') ? 'status-approved' : 'status-rejected');
      ?>
      <tr>
        <td><?= htmlspecialchars(($r['employee_name'] ?? '')." — ".($r['employee_ref'] ?? '')) ?></td>
        <td><?= htmlspecialchars($r['start_date'] ?? '') ?></td>
        <td><?= htmlspecialchars($r['end_date'] ?? '') ?></td>
        <td><?= htmlspecialchars(str_replace('_',' ', $r['leave_type'] ?? '')) ?></td>
        <td class="<?= $cls ?>"><?= ucfirst($r['status'] ?? '') ?></td>
        <td><?= htmlspecialchars($r['created_at'] ?? '') ?></td>
        <td>
          <button class="btn-view" onclick="openView(<?= (int)$r['id'] ?>)">
            <i class="fas fa-eye"></i> View
          </button>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:18px;">No leave requests found.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

<!-- Modal -->
<div id="viewModal" class="modal">
  <div class="modal-content">
    <button class="close-btn" onclick="closeModal()">&times;</button>
    <div id="viewBody"><div style="text-align:center;color:var(--muted);">Loading…</div></div>

    <div id="decisionButtons" class="modal-footer" style="display:none;">
      <button class="approve-btn" onclick="openDecision('approve')"><i class="fas fa-check"></i> Approve</button>
      <button class="reject-btn" onclick="openDecision('reject')"><i class="fas fa-times"></i> Reject</button>
    </div>
  </div>
</div>

<script>
let currentLeaveId = null;

function openView(id){
  currentLeaveId = id;

  document.getElementById('viewModal').style.display = 'block';
  const body = document.getElementById('viewBody');
  const buttons = document.getElementById('decisionButtons');

  body.innerHTML = "Loading…";
  buttons.style.display = "none";

  fetch('./admin_leave_view.php?id=' + id)
    .then(r => r.text())
    .then(html => {
      body.innerHTML = html;

      let statusEl = document.getElementById('leaveStatus');
      let status = statusEl ? statusEl.textContent.trim().toLowerCase() : '';
      if(status === "pending") buttons.style.display = "flex";
    });
}

function closeModal(){
  document.getElementById('viewModal').style.display = 'none';
}

function openDecision(action){
  if (!currentLeaveId) return alert("No leave selected.");

  let comment = prompt("Comment (optional):", "");
  if (comment === null) return;

  const data = new FormData();
  data.append('id', currentLeaveId);
  data.append('action', action);
  data.append('comment', comment);

  fetch('./admin_leave_requests.php', {
    method: 'POST',
    body: data
  })
  .then(r => r.text())
  .then(text => {
    alert(text);
    closeModal();
    location.reload();
  });
}
</script>

</body>
</html>
