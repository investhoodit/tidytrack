<?php
session_start();
require_once __DIR__ . '/../dp.php';

$userId = $_SESSION['user_id'] ?? null; 
$userName = $_SESSION['name'] ?? 'Leader';
$today = date('Y-m-d');

// --- AUTH GUARD (Leader Only) ---
if (!$userId || strpos(strtolower($_SESSION['role'] ?? ''), 'leader') === false) {
    header("Location: ../logout.php");
    exit;
}

// 1. FETCH TEAM MEMBERS & TASK PROGRESS
// We link via the Leader's ID being either the task creator OR the project leader
$team_query = "
    SELECT 
        u.user_id, 
        u.name, 
        u.profile_picture,
        COUNT(t.task_id) AS total_tasks,
        SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_tasks,
        dt.task AS report_content,
        dt.sent_at AS report_time
    FROM users u
    INNER JOIN tasks t ON u.name = t.assignTo
    LEFT JOIN projects_x px ON t.project_id = px.project_id
    LEFT JOIN daily_tasks dt ON u.user_id = dt.user_id AND dt.sent_date = ?
    WHERE (t.created_by = ? OR px.teamLeadder_id = ?) 
      AND u.role = 'member'
    GROUP BY u.user_id, u.name, u.profile_picture, dt.task, dt.sent_at
";

$stmt = $conn->prepare($team_query);
$stmt->bind_param("sii", $today, $userId, $userId);
$stmt->execute();
$team_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 2. CHECK IF LEADER ALREADY SUBMITTED THEIR OWN REPORT
$leaderReportContent = "";
$stmt = $conn->prepare("SELECT task FROM daily_tasks WHERE user_id = ? AND sent_date = ?");
$stmt->bind_param("is", $userId, $today);
$stmt->execute();
$leaderResult = $stmt->get_result();
$leaderReported = $leaderResult->num_rows > 0;
if ($leaderReported) {
    $leaderData = $leaderResult->fetch_assoc();
    $leaderReportContent = $leaderData['task'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Wrap-up | Team Oversight</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee; --success: #2ecc71; --danger: #e74c3c;
            --bg: #f6f8fb; --card: #ffffff; --ink: #1d3557; --muted: #6b7b93;
        }
        body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--ink); margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .glass-card { background: var(--card); border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); padding: 30px; border: 1px solid #eef2f6; }
        
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #f0f4f8; padding-bottom: 20px; }
        .header h1 { margin: 0; color: var(--primary); font-size: 24px; }
        
        /* Table Styling */
        .oversight-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .oversight-table th { text-align: left; padding: 12px; background: #f8fafd; color: var(--muted); font-size: 13px; text-transform: uppercase; letter-spacing: 1px; }
        .oversight-table td { padding: 15px 12px; border-bottom: 1px solid #f0f4f8; vertical-align: middle; }
        
        .member-profile { display: flex; align-items: center; gap: 10px; }
        .member-img { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; background: #eee; }
        
        /* Initials Fallback Style */
        .member-initials {
            width: 35px; height: 35px; border-radius: 50%; background: var(--primary);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 14px; text-transform: uppercase;
        }
        
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge-success { background: #e6fef0; color: var(--success); }
        .badge-pending { background: #fff1f0; color: var(--danger); }
        
        .task-count { font-weight: 600; font-size: 14px; }
        .count-total { color: var(--muted); }
        .count-done { color: var(--success); }

        .view-btn { background: var(--primary); color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: 0.2s; }
        .view-btn:hover { opacity: 0.8; }

        /* Report Form */
        .report-section { background: #f8fafd; padding: 25px; border-radius: 12px; border: 1px solid #eef2f6; }
        textarea { width: 100%; border: 1px solid #dce4ec; border-radius: 8px; padding: 15px; resize: none; margin-top: 10px; font-family: inherit; box-sizing: border-box; }
        textarea:focus { outline: none; border-color: var(--primary); }
        
        .actions { display: flex; justify-content: space-between; margin-top: 20px; }
        .btn { padding: 12px 25px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.3s; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-logout { background: var(--primary); color: white; }
        .btn-back { background: white; border: 1px solid #dce4ec; color: var(--muted); }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 10% auto; padding: 25px; width: 50%; border-radius: 12px; position: relative; }
        .close { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; color: #aaa; }
        #modal-body { background: #f9fbff; padding: 15px; border-radius: 8px; margin-top: 15px; white-space: pre-wrap; line-height: 1.6; border: 1px solid #eef2f6; }
    </style>
</head>
<body>

<div class="container">
    <div class="glass-card">
        <div class="header">
            <h1><i class="fas fa-clipboard-check"></i> Daily Team Oversight</h1>
            <p>Reviewing members assigned to <strong><?= htmlspecialchars($userName) ?></strong></p>
        </div>

        <?php if (empty($team_members)): ?>
            <div style="text-align:center; padding: 40px; color: var(--muted);">
                <i class="fas fa-users-slash" style="font-size: 40px; margin-bottom: 10px;"></i>
                <p>No team members found with tasks created by you today.</p>
            </div>
        <?php else: ?>
            <table class="oversight-table">
                <thead>
                    <tr>
                        <th>Team Member</th>
                        <th>Tasks</th>
                        <th>Report Status</th>
                        <th>Sent At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($team_members as $m): ?>
                        <tr>
                            <td>
                                <div class="member-profile">
                                    <?php if (!empty($m['profile_picture']) && file_exists("../" . $m['profile_picture'])): ?>
                                        <img src="../<?= htmlspecialchars($m['profile_picture']) ?>" class="member-img">
                                    <?php else: ?>
                                        <div class="member-initials">
                                            <?= strtoupper(substr(trim($m['name']), 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <span style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="task-count">
                                    <span class="count-done"><?= $m['completed_tasks'] ?? 0 ?></span> / 
                                    <span class="count-total"><?= $m['total_tasks'] ?></span>
                                </span>
                            </td>
                            <td>
                                <?php if($m['report_content']): ?>
                                    <span class="badge badge-success">Submitted</span>
                                <?php else: ?>
                                    <span class="badge badge-pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 13px; color: var(--muted);">
                                <?= $m['report_time'] ? date('H:i', strtotime($m['report_time'])) : '—' ?>
                            </td>
                            <td>
                                <?php if($m['report_content']): ?>
                                    <button class="view-btn" onclick="openReport('<?= htmlspecialchars($m['name']) ?>', `<?= htmlspecialchars($m['report_content']) ?>`)">
                                        View Report
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form action="process_leader_logout.php" method="POST" class="report-section">
            <h3 style="margin-top:0; font-size: 16px;">
                <i class="fas fa-feather-alt"></i> Final Leader Wrap-up 
                <span style="font-weight:400; font-size:12px; color:var(--muted); margin-left:10px;">(Required)</span>
            </h3>

            <?php if($leaderReported): ?>
                <div style="background:#e6fef0; color:var(--success); padding:10px; border-radius:8px; font-size:13px; margin-bottom:10px; font-weight:600;">
                    <i class="fas fa-check-circle"></i> You have already submitted today's report.
                </div>
                <textarea name="leader_report" rows="4" readonly style="background:#f1f4f9; color:var(--muted); cursor:not-allowed;"><?= htmlspecialchars($leaderReportContent) ?></textarea>
            <?php else: ?>
                <p style="font-size: 13px; color: var(--muted); margin-bottom: 5px;">
                    Summarize the day for the Admin or just click sign out to finish.
                </p>
                <textarea name="leader_report" rows="4" placeholder="Type your summary here (Leave blank to skip)..."></textarea>
            <?php endif; ?>

            <div class="actions">
                <a href="leader_index.php" class="btn btn-back">Cancel</a>
                <button type="submit" class="btn btn-logout">
                    <?= $leaderReported ? 'Finish & Sign Out' : 'Submit & Sign Out' ?> 
                    <i class="fas fa-sign-out-alt"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<div id="reportModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeReport()">&times;</span>
        <h2 id="modal-title" style="margin:0; font-size: 18px;"></h2>
        <div id="modal-body"></div>
    </div>
</div>

<script>
    function openReport(name, content) {
        document.getElementById('modal-title').innerText = "Daily Report: " + name;
        document.getElementById('modal-body').innerText = content;
        document.getElementById('reportModal').style.display = 'block';
    }
    function closeReport() {
        document.getElementById('reportModal').style.display = 'none';
    }
    window.onclick = function(event) {
        if (event.target == document.getElementById('reportModal')) closeReport();
    }
</script>

</body>
</html>