<?php
session_start();
include '../dp.php';
if (!isset($_SESSION['developer_id'])) {
    header('Location: index.php');
    exit;
}

// ── HANDLE DELETE ──
$msg = ''; $msgType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_task'])) {
    $task_id = (int)$_POST['task_id'];

    $stmt = $conn->prepare("DELETE FROM tasks WHERE task_id = ?");
    $stmt->bind_param("i", $task_id);
    
    if ($stmt->execute()) {
        $msg = "🗑️ Task <strong>permanently deleted</strong>.";
        $msgType = 'success';
    } else {
        $msg = "❌ Failed to delete task.";
        $msgType = 'error';
    }
    $stmt->close();
}

// ── FILTERS ──
$search = trim($_GET['search'] ?? '');
$fromDate = $_GET['from'] ?? '';
$toDate   = $_GET['to'] ?? '';

// Build Query
$sql = "SELECT t.*, 
               u.name AS created_by_name,
               p.project_name 
        FROM tasks t 
        LEFT JOIN users u ON t.created_by = u.user_id 
        LEFT JOIN projects_x p ON t.project_id = p.project_id";

$where = [];
$params = [];
$types = '';

if ($search) {
    $where[] = "(t.taskTitle LIKE ? OR t.description LIKE ? OR t.assignTo LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "sss";
}
if ($fromDate) {
    $where[] = "t.created_at >= ?";
    $params[] = $fromDate;
    $types .= "s";
}
if ($toDate) {
    $where[] = "t.created_at <= ?";
    $params[] = $toDate . " 23:59:59";
    $types .= "s";
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY t.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delete Tasks · Dev Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --bg:#0c0e13; --surface:#101318; --card:#161a23; --card-h:#1c2132;
    --border:rgba(255,255,255,0.06); --border-h:rgba(255,255,255,0.13);
    --text:#dde1eb; --sub:#52596e;
    --blue:#4f7ef5; --violet:#7c5af5; --green:#10c97a;
    --amber:#f5a623; --rose:#f54f6b; --cyan:#0ac8e0;
    --r:16px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'Syne',sans-serif;
    background:var(--bg); color:var(--text);
    min-height:100vh; overflow-x:hidden;
}
.page{position:relative;z-index:1;max-width:1300px;margin:0 auto;padding:36px 28px;}

/* TOPBAR - SAME AS DASHBOARD */
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:52px;}
.tb-brand{display:flex;align-items:center;gap:14px;}
.tb-logo{
    width:42px;height:42px;border-radius:12px;flex-shrink:0;
    background:linear-gradient(135deg,var(--blue),var(--violet));
    display:flex;align-items:center;justify-content:center;
    font-size:1rem;font-weight:800;color:#fff;
    box-shadow:0 0 22px rgba(79,126,245,.35);
}
.tb-name{font-size:1.1rem;font-weight:800;letter-spacing:-.2px;}
.tb-meta{font-size:10.5px;color:var(--sub);font-family:'JetBrains Mono',monospace;margin-top:2px;}
.tb-right{display:flex;align-items:center;gap:10px;}
.tb-clock{
    padding:7px 14px;border-radius:10px;
    background:var(--card);border:1px solid var(--border);
    font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--sub);
}
.btn-out{
    padding:8px 20px;border-radius:10px;font-size:12px;font-weight:700;
    font-family:'Syne',sans-serif;text-decoration:none;cursor:pointer;
    background:rgba(245,79,107,.1);color:var(--rose);
    border:1px solid rgba(245,79,107,.2);transition:all .2s;
}
.btn-out:hover{background:rgba(245,79,107,.2);border-color:rgba(245,79,107,.45);}

/* GREETING */
.greet{margin-bottom:44px;}
.greet-hi{font-size:2.3rem;font-weight:800;letter-spacing:-.6px;line-height:1.1;}
.greet-hi em{font-style:normal;color:var(--blue);}
.greet-meta{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--sub);margin-top:8px;}

/* SECTION LABEL */
.sh{
    display:flex;align-items:center;gap:10px;
    font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;
    color:var(--sub);font-family:'JetBrains Mono',monospace;margin-bottom:18px;
}
.sh::after{content:'';flex:1;height:1px;background:var(--border);}

/* FILTERS */
.filters{
    background:var(--card);border:1px solid var(--border);
    border-radius:20px;padding:24px;margin-bottom:36px;
}

/* TASK CARDS */
.tasks-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(380px,1fr));
    gap:18px;
}
.task-card{
    background:var(--card);border:1px solid var(--border);
    border-radius:var(--r);padding:24px;
    transition:all .25s;
}
.task-card:hover{
    background:var(--card-h);border-color:var(--blue);
    transform:translateY(-6px);
}
.task-title{font-size:1.2rem;font-weight:700;margin-bottom:8px;}
.task-desc{
    font-size:13.5px;color:var(--sub);margin-bottom:18px;
    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;
}
.meta{font-size:12.8px;font-family:'JetBrains Mono',monospace;color:var(--sub);line-height:1.65;}
.status{
    display:inline-block;padding:5px 14px;border-radius:30px;
    font-size:11px;font-weight:700;margin-top:10px;
}
.status.Completed{background:rgba(16,201,122,.15);color:var(--green);}
.status.In\ progress{background:rgba(245,166,35,.15);color:var(--amber);}
.status.In\ review{background:rgba(79,126,245,.15);color:var(--blue);}

.btn-delete{
    margin-top:16px;padding:13px;border-radius:12px;
    background:rgba(245,79,107,.9);color:#fff;
    border:none;font-weight:700;cursor:pointer;width:100%;
    transition:all .2s;
}
.btn-delete:hover{background:#f54f6b;transform:scale(1.02);}

/* Modal */
.modal-bg{
    display:none;position:fixed;inset:0;z-index:9999;
    background:rgba(0,0,0,.8);backdrop-filter:blur(6px);
    align-items:center;justify-content:center;
}
.modal-bg.open{display:flex;}
.modal{
    background:var(--card);border:1px solid var(--border-h);
    border-radius:20px;width:100%;max-width:480px;
}
</style>
</head>
<body>
<div class="page">

    <!-- TOPBAR - NOW MATCHES DASHBOARD PERFECTLY -->
    <div class="topbar">
        <div class="tb-brand">
            <div class="tb-logo">T</div>
            <div>
                <div class="tb-name">TidyTrack Dev Portal</div>
                <div class="tb-meta">investh2_tidytrack 路 Task Deletion</div>
            </div>
        </div>
        <div class="tb-right">
            <div class="tb-clock" id="devClock">--:--:--</div>
            <a href="index.php" class="btn-out"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>

    <!-- GREETING -->
    <div class="greet">
        <div class="greet-hi">Delete Tasks <em>🗑️</em></div>
        <div class="greet-meta">// Search, filter by date, and permanently remove tasks</div>
    </div>

    <?php if ($msg): ?>
    <div style="margin-bottom:28px;padding:16px 20px;border-radius:12px;font-size:14px;background:<?= $msgType==='success' ? 'rgba(16,201,122,.15)' : 'rgba(245,79,107,.15)' ?>;color:<?= $msgType==='success' ? '#10c97a' : '#f54f6b' ?>;">
        <?= $msg ?>
    </div>
    <?php endif; ?>

    <!-- FILTERS -->
    <div class="filters">
        <form method="GET">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search task title, description or assigned to..." 
                       style="padding:14px 18px;background:var(--surface);border:1px solid var(--border);border-radius:12px;color:var(--text);font-size:15px;">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <small style="color:var(--sub);display:block;margin-bottom:6px;">From</small>
                        <input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>" 
                               style="width:100%;padding:13px;background:var(--surface);border:1px solid var(--border);border-radius:12px;color:var(--text);">
                    </div>
                    <div>
                        <small style="color:var(--sub);display:block;margin-bottom:6px;">To</small>
                        <input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>" 
                               style="width:100%;padding:13px;background:var(--surface);border:1px solid var(--border);border-radius:12px;color:var(--text);">
                    </div>
                </div>
            </div>

            <button type="submit" style="margin-top:20px;padding:14px 32px;background:linear-gradient(135deg,var(--blue),var(--violet));color:#fff;border:none;border-radius:12px;font-weight:700;cursor:pointer;">
                <i class="fas fa-filter"></i> Apply Filters
            </button>
        </form>
    </div>

    <div class="sh"><span>Tasks (<?= count($tasks) ?>)</span></div>
    <div class="tasks-grid">
        <?php if (empty($tasks)): ?>
            <div style="grid-column:1/-1;text-align:center;padding:100px 20px;color:var(--sub);">
                No tasks found with current filters.
            </div>
        <?php else: ?>
            <?php foreach ($tasks as $t): ?>
            <div class="task-card">
                <div class="task-title"><?= htmlspecialchars($t['taskTitle']) ?></div>
                <div class="task-desc"><?= htmlspecialchars(substr($t['description'] ?? 'No description', 0, 160)) ?>...</div>
                
                <div class="meta">
                    <strong>Assigned To:</strong> <?= htmlspecialchars($t['assignTo'] ?? '—') ?><br>
                    <strong>Created By:</strong> <?= htmlspecialchars($t['created_by_name'] ?? 'Unknown') ?><br>
                    <strong>Project:</strong> <?= htmlspecialchars($t['project_name'] ?? 'No Project') ?><br>
                    <strong>Created:</strong> <?= date('d M Y', strtotime($t['created_at'])) ?>
                </div>

                <div class="status <?= str_replace(' ', '\\ ', $t['status']) ?>"><?= htmlspecialchars($t['status']) ?></div>

                <button onclick="confirmDelete(<?= $t['task_id'] ?>, '<?= addslashes(htmlspecialchars($t['taskTitle'])) ?>')" 
                        class="btn-delete">
                    <i class="fas fa-trash-alt"></i> DELETE PERMANENTLY
                </button>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- CONFIRMATION MODAL -->
<div class="modal-bg" id="modal">
    <div class="modal">
        <div style="padding:28px 32px;">
            <h3 id="modalTitle" style="color:var(--rose);margin-bottom:10px;">Delete this task?</h3>
            <p id="modalText" style="color:var(--sub);line-height:1.6;"></p>
        </div>
        <div style="padding:0 32px 32px;display:flex;gap:12px;">
            <button onclick="hideModal()" style="flex:1;padding:14px;border-radius:12px;background:var(--surface);border:1px solid var(--border);color:var(--text);">Cancel</button>
            <form id="deleteForm" method="POST" style="flex:1;">
                <input type="hidden" name="task_id" id="taskId">
                <input type="hidden" name="delete_task" value="1">
                <button type="submit" style="width:100%;padding:14px;border-radius:12px;background:#f54f6b;color:#fff;border:none;font-weight:700;">YES, DELETE FOREVER</button>
            </form>
        </div>
    </div>
</div>

<script>
function tick(){
    document.getElementById('devClock').textContent = new Date().toLocaleTimeString('en-ZA',{
        hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Africa/Johannesburg'
    });
}
setInterval(tick,1000); tick();

function confirmDelete(id, title) {
    document.getElementById('modalTitle').innerHTML = `Delete <em>${title}</em>?`;
    document.getElementById('modalText').innerHTML = 'This action is <strong>permanent</strong> and cannot be undone.';
    document.getElementById('taskId').value = id;
    document.getElementById('modal').classList.add('open');
}

function hideModal() {
    document.getElementById('modal').classList.remove('open');
}
</script>
</body>
</html>