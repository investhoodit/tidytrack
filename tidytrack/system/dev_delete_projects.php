<?php
session_start();
include '../dp.php';
if (!isset($_SESSION['developer_id'])) {
    header('Location: index.php');
    exit;
}

// ── HANDLE DELETE ──
$msg = ''; $msgType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $project_id = (int)$_POST['project_id'];
    $action = $_POST['action']; // 'delete_only' or 'delete_with_tasks'

    if ($action === 'delete_only') {
        $stmt = $conn->prepare("DELETE FROM projects_x WHERE project_id = ?");
        $stmt->bind_param("i", $project_id);
        if ($stmt->execute()) {
            $msg = "✅ Project permanently deleted.";
            $msgType = 'success';
        }
    } 
    elseif ($action === 'delete_with_tasks') {
        // Delete tasks first
        $stmt = $conn->prepare("DELETE FROM tasks WHERE project_id = ?");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();

        // Then delete project
        $stmt = $conn->prepare("DELETE FROM projects_x WHERE project_id = ?");
        $stmt->bind_param("i", $project_id);
        if ($stmt->execute()) {
            $msg = "🗑️ Project and all related tasks permanently deleted.";
            $msgType = 'success';
        }
    }
    $stmt->close();
}

// ── FETCH PROJECTS ──
$projects = [];
$stmt = $conn->prepare("
    SELECT p.*, 
           u.name AS created_by_name,
           l.name AS leader_name
    FROM projects_x p
    LEFT JOIN users u ON p.created_by = u.user_id
    LEFT JOIN users l ON p.teamLeadder_id = l.user_id
    ORDER BY p.start_date DESC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delete Projects · Dev Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
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

/* TOPBAR - Same as others */
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
    background:rgba(245,79,107,.1);color:var(--rose);
    border:1px solid rgba(245,79,107,.2);transition:all .2s;
}
.btn-out:hover{background:rgba(245,79,107,.2);border-color:rgba(245,79,107,.45);}

/* GREETING */
.greet{margin-bottom:44px;}
.greet-hi{font-size:2.3rem;font-weight:800;letter-spacing:-.6px;line-height:1.1;}
.greet-hi em{font-style:normal;color:var(--blue);}
.greet-meta{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--sub);margin-top:8px;}

/* SECTION */
.sh{
    display:flex;align-items:center;gap:10px;
    font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;
    color:var(--sub);font-family:'JetBrains Mono',monospace;margin-bottom:18px;
}
.sh::after{content:'';flex:1;height:1px;background:var(--border);}

/* PROJECT CARDS */
.projects-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(380px,1fr));
    gap:20px;
}
.project-card{
    background:var(--card);border:1px solid var(--border);
    border-radius:var(--r);padding:24px;
    transition:all .25s;
}
.project-card:hover{
    background:var(--card-h);border-color:var(--violet);
    transform:translateY(-6px);
}
.project-name{font-size:1.25rem;font-weight:700;margin-bottom:8px;}
.project-meta{font-size:13px;font-family:'JetBrains Mono',monospace;color:var(--sub);line-height:1.7;}
.btn-group{
    display:flex;gap:12px;margin-top:20px;
}
.btn-only{
    flex:1;padding:13px;border-radius:12px;background:rgba(245,166,35,.9);color:#fff;
    border:none;font-weight:700;cursor:pointer;
}
.btn-full{
    flex:1;padding:13px;border-radius:12px;background:rgba(245,79,107,.9);color:#fff;
    border:none;font-weight:700;cursor:pointer;
}
.btn-only:hover{background:#f5a623;}
.btn-full:hover{background:#f54f6b;}
</style>
</head>
<body>
<div class="page">

    <div class="topbar">
        <div class="tb-brand">
            <div class="tb-logo">T</div>
            <div>
                <div class="tb-name">TidyTrack Dev Portal</div>
                <div class="tb-meta">investh2_tidytrack 路 Project Deletion</div>
            </div>
        </div>
        <div class="tb-right">
            <div class="tb-clock" id="devClock">--:--:--</div>
            <a href="index.php" class="btn-out"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>

    <div class="greet">
        <div class="greet-hi">Manage Projects <em>🗑️</em></div>
        <div class="greet-meta">// Choose to delete project only or with all tasks</div>
    </div>

    <?php if ($msg): ?>
    <div style="margin-bottom:28px;padding:16px 20px;border-radius:12px;font-size:14px;background:<?= $msgType==='success'?'rgba(16,201,122,.15)':'rgba(245,79,107,.15)' ?>;color:<?= $msgType==='success'?'#10c97a':'#f54f6b' ?>;">
        <?= $msg ?>
    </div>
    <?php endif; ?>

    <div class="sh"><span>Projects (<?= count($projects) ?>)</span></div>
    <div class="projects-grid">
        <?php foreach ($projects as $p): ?>
        <div class="project-card">
            <div class="project-name"><?= htmlspecialchars($p['project_name']) ?></div>
            <div class="project-meta">
                <strong>Deliverable:</strong> <?= htmlspecialchars(substr($p['deliverable'] ?? '—', 0, 120)) ?>...<br>
                <strong>Period:</strong> <?= date('d M Y', strtotime($p['start_date'])) ?> → <?= date('d M Y', strtotime($p['end_date'])) ?><br>
                <strong>Created By:</strong> <?= htmlspecialchars($p['created_by_name'] ?? 'Unknown') ?><br>
                <strong>Team Leader:</strong> <?= htmlspecialchars($p['leader_name'] ?? '—') ?>
            </div>

            <div class="btn-group">
                <button onclick="confirmDelete(<?= $p['project_id'] ?>, '<?= addslashes(htmlspecialchars($p['project_name'])) ?>', 'only')" 
                        class="btn-only">
                    <i class="fas fa-trash"></i> Delete Project Only
                </button>
                <button onclick="confirmDelete(<?= $p['project_id'] ?>, '<?= addslashes(htmlspecialchars($p['project_name'])) ?>', 'with_tasks')" 
                        class="btn-full">
                    <i class="fas fa-bomb"></i> Delete + All Tasks
                </button>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($projects)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:80px;color:var(--sub);">
            No projects found.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- CONFIRM MODAL -->
<div class="modal-bg" id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(6px);align-items:center;justify-content:center;z-index:9999;">
    <div class="modal" style="background:var(--card);border:1px solid var(--border-h);border-radius:20px;width:100%;max-width:480px;">
        <div style="padding:28px 32px;">
            <h3 id="modalTitle" style="color:var(--rose);margin-bottom:12px;">Confirm Deletion</h3>
            <p id="modalText" style="color:var(--sub);line-height:1.6;"></p>
        </div>
        <div style="padding:0 32px 32px;display:flex;gap:12px;">
            <button onclick="hideModal()" style="flex:1;padding:14px;border-radius:12px;background:var(--surface);border:1px solid var(--border);color:var(--text);">Cancel</button>
            <form id="deleteForm" method="POST" style="flex:1;">
                <input type="hidden" name="project_id" id="projId">
                <input type="hidden" name="action" id="actionType">
                <button type="submit" id="confirmBtn" style="width:100%;padding:14px;border-radius:12px;background:#f54f6b;color:#fff;border:none;font-weight:700;">YES, DELETE</button>
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

function confirmDelete(id, name, type) {
    document.getElementById('projId').value = id;
    document.getElementById('actionType').value = type === 'only' ? 'delete_only' : 'delete_with_tasks';
    
    const titleEl = document.getElementById('modalTitle');
    const textEl = document.getElementById('modalText');
    
    if (type === 'only') {
        titleEl.textContent = `Delete "${name}"?`;
        textEl.innerHTML = 'Only the project will be deleted.<br><strong>Tasks will remain</strong> in the system.';
    } else {
        titleEl.textContent = `DELETE "${name}" + ALL TASKS?`;
        textEl.innerHTML = '<strong style="color:#f54f6b;">Warning:</strong> This will permanently delete the project and <strong>ALL tasks</strong> under it. This cannot be undone.';
    }
    
    document.getElementById('modal').style.display = 'flex';
}

function hideModal() {
    document.getElementById('modal').style.display = 'none';
}
</script>
</body>
</html>