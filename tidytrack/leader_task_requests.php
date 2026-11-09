<?php
// leader_task_requests.php
// Leader panel: review delete requests + overdue reasons
session_start();
include '../dp.php';
include '../track.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['name'];
$userRole = strtolower($_SESSION['role'] ?? 'member');

if (!in_array($userRole, ['leader','admin'])) {
    die("<h2 style='font-family:sans-serif;color:#c53030;padding:40px;'>Access Denied — Leaders & Admins only.</h2>");
}


log_activity($userId, "Viewed Task Requests Panel");

// Fetch pending delete requests
$deleteReqs = $conn->query("
    SELECT t.task_id, t.taskTitle, t.assignTo, t.delete_reason, t.delete_requested_at,
           t.dueDate, t.priority, p.project_name
    FROM tasks t
    JOIN projects_x p ON t.project_id = p.project_id
    WHERE t.delete_requested = 1 AND t.status = 'Delete Requested'
    ORDER BY t.delete_requested_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Fetch overdue explanations pending review
$overdueExps = $conn->query("
    SELECT t.task_id, t.taskTitle, t.assignTo, t.overdue_reason, t.overdue_reason_at,
           t.dueDate, t.priority, p.project_name
    FROM tasks t
    JOIN projects_x p ON t.project_id = p.project_id
    WHERE t.overdue_reason IS NOT NULL 
      AND t.status = 'In review'
      AND t.comment NOT LIKE '%[Overdue reason accepted%'
    ORDER BY t.overdue_reason_at DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Task Requests — Leader Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --accent:#1d3557; --bg:#eef2f5; --danger:#e94560; --warning:#f39c12; --success:#27ae60; }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Poppins',sans-serif;background:var(--bg);min-height:100vh;padding:28px 24px;}
        .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:14px;}
        .page-title{font-size:1.5rem;font-weight:700;color:var(--accent);}
        .back-btn{background:white;border:1px solid #ddd;color:#555;padding:9px 18px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:7px;}
        .back-btn:hover{background:#f0f4f8;color:var(--accent);}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;}
        @media(max-width:900px){.grid{grid-template-columns:1fr;}}
        .panel{background:white;border-radius:14px;box-shadow:0 3px 12px rgba(0,0,0,0.06);overflow:hidden;}
        .panel-header{padding:18px 22px;display:flex;align-items:center;gap:10px;}
        .panel-title{font-size:1rem;font-weight:700;flex:1;}
        .panel-count{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;color:white;}
        .panel-body{padding:18px 22px;}
        .request-card{background:#fafafa;border-radius:10px;padding:15px;margin-bottom:14px;border-left:5px solid #ccc;}
        .request-card:last-child{margin-bottom:0;}
        .req-title{font-weight:600;font-size:.95rem;margin-bottom:3px;}
        .req-meta{font-size:11px;color:#aaa;margin-bottom:8px;}
        .reason-box{background:white;border:1px solid #eee;border-radius:8px;padding:11px;font-size:13px;color:#444;margin-bottom:12px;line-height:1.5;}
        .action-row{display:flex;gap:10px;}
        .btn{padding:8px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:12px;transition:.2s;font-family:inherit;}
        .btn-approve{background:var(--success);color:white;} .btn-approve:hover{background:#219a52;}
        .btn-reject{background:var(--danger);color:white;} .btn-reject:hover{background:#c53030;}
        .btn-accept{background:#3498db;color:white;} .btn-accept:hover{background:#2380c4;}
        .empty-state{text-align:center;padding:36px;color:#ccc;}
        .empty-state i{font-size:2.4rem;display:block;margin-bottom:10px;}
        /* Reject reason input (hidden by default) */
        .reject-reason-wrap{display:none;margin-top:10px;}
        .reject-reason-wrap textarea{width:100%;padding:8px;border:1px solid #ddd;border-radius:8px;font-family:inherit;font-size:12px;resize:vertical;min-height:60px;}
        .reject-reason-wrap textarea:focus{outline:none;border-color:var(--danger);}
        .confirm-reject-btn{margin-top:8px;background:var(--danger);color:white;border:none;padding:7px 14px;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;font-family:inherit;}
        /* Priority badge */
        .priority-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:12px;margin-left:6px;}
        .p-high{background:#fff0f0;color:#c53030;border:1px solid #fecaca;}
        .p-medium{background:#fffbeb;color:#d97706;border:1px solid #fbd38d;}
        .p-low{background:#f0fff4;color:#27ae60;border:1px solid #c6f6d5;}
        /* Accepted overlay */
        .accepted-overlay{text-align:center;padding:16px;color:#27ae60;font-weight:600;font-size:13px;display:none;}
    </style>
</head>
<body>

<div class="page-header">
    <div>
        <div class="page-title">📋 Task Requests Panel</div>
        <div style="font-size:12px;color:#aaa;margin-top:3px;">Review deletion requests & overdue explanations from your team</div>
    </div>
    <a href="leader_dashboard.php" class="back-btn"><i class="fas fa-chevron-left"></i> Back to Dashboard</a>
</div>

<div class="grid">

    <!-- ══ DELETE REQUESTS ══ -->
    <div class="panel">
        <div class="panel-header" style="background:linear-gradient(135deg,#fff5f5,#ffe4e4);border-bottom:1px solid #fed7d7;">
            <span style="font-size:1.3rem;">🗑️</span>
            <span class="panel-title" style="color:#c53030;">Deletion Requests</span>
            <span class="panel-count" style="background:#c53030;"><?php echo count($deleteReqs); ?></span>
        </div>
        <div class="panel-body">
            <?php if (empty($deleteReqs)): ?>
                <div class="empty-state"><i class="fas fa-check-circle" style="color:#27ae60;"></i>No pending deletion requests</div>
            <?php else: ?>
                <?php foreach ($deleteReqs as $req):
                    $pri = strtolower($req['priority'] ?? '');
                    $priClass = $pri==='high' ? 'p-high' : ($pri==='medium' ? 'p-medium' : 'p-low');
                ?>
                <div class="request-card" style="border-left-color:#e94560;" id="del-card-<?php echo $req['task_id']; ?>">
                    <div class="req-title">
                        <?php echo htmlspecialchars($req['taskTitle']); ?>
                        <?php if ($req['priority']): ?><span class="priority-badge <?php echo $priClass; ?>"><?php echo ucfirst($req['priority']); ?></span><?php endif; ?>
                    </div>
                    <div class="req-meta">
                        👤 <?php echo htmlspecialchars($req['assignTo']); ?>
                        &nbsp;|&nbsp; 📁 <?php echo htmlspecialchars($req['project_name']); ?>
                        &nbsp;|&nbsp; 📅 Due: <?php echo htmlspecialchars($req['dueDate'] ?? 'N/A'); ?>
                        <br>🕐 Requested: <?php echo date('d M Y H:i', strtotime($req['delete_requested_at'])); ?>
                    </div>
                    <div class="reason-box">
                        <strong style="font-size:11px;color:#999;display:block;margin-bottom:4px;">REASON FOR DELETION:</strong>
                        <?php echo nl2br(htmlspecialchars($req['delete_reason'])); ?>
                    </div>
                    <div class="action-row">
                        <button class="btn btn-approve" onclick="approveDelete(<?php echo $req['task_id']; ?>)">
                            <i class="fas fa-check"></i> Approve Delete
                        </button>
                        <button class="btn btn-reject" onclick="showRejectForm(<?php echo $req['task_id']; ?>)">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                    <div class="reject-reason-wrap" id="reject-form-<?php echo $req['task_id']; ?>">
                        <textarea placeholder="Tell the member why you're rejecting this deletion request..." id="reject-text-<?php echo $req['task_id']; ?>"></textarea>
                        <button class="confirm-reject-btn" onclick="confirmReject(<?php echo $req['task_id']; ?>)">Send Rejection</button>
                    </div>
                    <div class="accepted-overlay" id="del-done-<?php echo $req['task_id']; ?>">✅ Done</div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>


    <!-- ══ OVERDUE EXPLANATIONS ══ -->
    <div class="panel">
        <div class="panel-header" style="background:linear-gradient(135deg,#fff5f5,#ffecd2);border-bottom:1px solid #fbd38d;">
            <span style="font-size:1.3rem;">⚠️</span>
            <span class="panel-title" style="color:#d97706;">Overdue Explanations</span>
            <span class="panel-count" style="background:#d97706;"><?php echo count($overdueExps); ?></span>
        </div>
        <div class="panel-body">
            <?php if (empty($overdueExps)): ?>
                <div class="empty-state"><i class="fas fa-smile" style="color:#27ae60;"></i>No overdue explanations pending</div>
            <?php else: ?>
                <?php foreach ($overdueExps as $exp):
                    $daysOv = $exp['dueDate'] ? (int)((strtotime('today') - strtotime($exp['dueDate'])) / 86400) : null;
                    $pri = strtolower($exp['priority'] ?? '');
                    $priClass = $pri==='high' ? 'p-high' : ($pri==='medium' ? 'p-medium' : 'p-low');
                ?>
                <div class="request-card" style="border-left-color:#f39c12;" id="ov-card-<?php echo $exp['task_id']; ?>">
                    <div class="req-title">
                        <?php echo htmlspecialchars($exp['taskTitle']); ?>
                        <?php if ($exp['priority']): ?><span class="priority-badge <?php echo $priClass; ?>"><?php echo ucfirst($exp['priority']); ?></span><?php endif; ?>
                    </div>
                    <div class="req-meta">
                        👤 <?php echo htmlspecialchars($exp['assignTo']); ?>
                        &nbsp;|&nbsp; 📁 <?php echo htmlspecialchars($exp['project_name']); ?>
                        <br>
                        📅 Was due: <?php echo htmlspecialchars($exp['dueDate'] ?? 'N/A'); ?>
                        <?php if ($daysOv !== null): ?><span style="color:#c53030;font-weight:700;"> (<?php echo $daysOv; ?> days overdue)</span><?php endif; ?>
                        <br>🕐 Explanation on: <?php echo date('d M Y H:i', strtotime($exp['overdue_reason_at'])); ?>
                    </div>
                    <div class="reason-box">
                        <strong style="font-size:11px;color:#999;display:block;margin-bottom:4px;">MEMBER'S EXPLANATION:</strong>
                        <?php echo nl2br(htmlspecialchars($exp['overdue_reason'])); ?>
                    </div>
                    <div class="action-row">
                        <button class="btn btn-accept" onclick="acceptOverdue(<?php echo $exp['task_id']; ?>)">
                            <i class="fas fa-thumbs-up"></i> Accept Explanation
                        </button>
                    </div>
                    <div class="accepted-overlay" id="ov-done-<?php echo $exp['task_id']; ?>">✅ Accepted</div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ── APPROVE DELETE ────────────────────────────────────────────
async function approveDelete(taskId) {
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Approve Deletion?',
        text: 'This will permanently mark the task as deleted and notify the member.',
        showCancelButton: true,
        confirmButtonColor: '#e94560',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, Delete It'
    });
    if (!result.isConfirmed) return;

    const fd = new FormData();
    fd.append('action', 'approve_delete');
    fd.append('task_id', taskId);

    try {
        const res  = await fetch('task_actions.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        const card = document.getElementById('del-card-' + taskId);
        if (card) {
            card.style.transition = 'opacity .4s';
            card.style.opacity = '0.4';
            card.style.pointerEvents = 'none';
            document.getElementById('del-done-' + taskId).style.display = 'block';
        }

        Swal.fire({ icon:'success', title:'Approved!', text:'Task deleted and member notified.', timer:2000, showConfirmButton:false, toast:true, position:'top-end' });

    } catch(err) {
        Swal.fire({ icon:'error', title:'Error', text: err.message });
    }
}

// ── SHOW REJECT FORM ──────────────────────────────────────────
function showRejectForm(taskId) {
    const form = document.getElementById('reject-form-' + taskId);
    form.style.display = form.style.display === 'block' ? 'none' : 'block';
}

// ── CONFIRM REJECT ────────────────────────────────────────────
async function confirmReject(taskId) {
    const reason = document.getElementById('reject-text-' + taskId).value.trim();

    const fd = new FormData();
    fd.append('action', 'reject_delete');
    fd.append('task_id', taskId);
    fd.append('reject_reason', reason);

    try {
        const res  = await fetch('task_actions.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        const card = document.getElementById('del-card-' + taskId);
        if (card) {
            card.style.transition = 'opacity .4s';
            card.style.opacity = '0.3';
            card.style.pointerEvents = 'none';
        }

        Swal.fire({ icon:'info', title:'Rejected', text:'Task restored to In Progress. Member notified.', timer:2000, showConfirmButton:false, toast:true, position:'top-end' });

    } catch(err) {
        Swal.fire({ icon:'error', title:'Error', text: err.message });
    }
}

// ── ACCEPT OVERDUE EXPLANATION ────────────────────────────────
async function acceptOverdue(taskId) {
    const fd = new FormData();
    fd.append('action', 'approve_overdue');
    fd.append('task_id', taskId);

    try {
        const res  = await fetch('task_actions.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        const card = document.getElementById('ov-card-' + taskId);
        if (card) {
            card.style.transition = 'opacity .4s';
            card.style.opacity = '0.4';
            card.style.pointerEvents = 'none';
            document.getElementById('ov-done-' + taskId).style.display = 'block';
        }

        Swal.fire({ icon:'success', title:'Accepted', text:'Overdue explanation noted.', timer:1800, showConfirmButton:false, toast:true, position:'top-end' });

    } catch(err) {
        Swal.fire({ icon:'error', title:'Error', text: err.message });
    }
}
</script>

</body>
</html>
