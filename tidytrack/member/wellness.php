<?php
session_start();
include '../dp.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../PHPMailer-master/PHPMailer-master/src/Exception.php';
require '../PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
require '../PHPMailer-master/PHPMailer-master/src/SMTP.php';

$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'] ?? 'A Member';
if (!$userId) { header("Location: login.php"); exit; }

$msg = "";
$error_msg = "";
$edit_data = null;

// --- 1. COUNT MONTHLY REPORTS ---
$currentMonth = date('m');
$currentYear = date('Y');
$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM work_wellness WHERE user_id = ? AND MONTH(submitted_at) = ? AND YEAR(submitted_at) = ?");
$countStmt->bind_param("iii", $userId, $currentMonth, $currentYear);
$countStmt->execute();
$monthlyCount = $countStmt->get_result()->fetch_assoc()['total'];

// --- 2. HANDLE DELETE ---
if (isset($_GET['delete_id'])) {
    $del_id = $_GET['delete_id'];
    $del = $conn->prepare("DELETE FROM work_wellness WHERE id = ? AND user_id = ?");
    $del->bind_param("ii", $del_id, $userId);
    $del->execute();
    header("Location: wellness.php?msg=Deleted");
    exit;
}

// --- 3. HANDLE EDIT LOAD ---
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $check = $conn->prepare("SELECT * FROM work_wellness WHERE id = ? AND user_id = ?");
    $check->bind_param("ii", $edit_id, $userId);
    $check->execute();
    $edit_data = $check->get_result()->fetch_assoc();
}

// --- 4. HANDLE SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_wellness'])) {
    $report_id = $_POST['report_id'] ?? null;

    if (!$report_id && $monthlyCount >= 4) {
        $error_msg = "Limit Reached: You have already submitted 4 reports this month.";
    } else {
        $pressure    = $_POST['pressure'] ?? 5;
        $pain        = $_POST['pain'] ?? '';
        $env         = $_POST['environment'] ?? '';
        $leader      = $_POST['leader'] ?? 5;
        $workload    = $_POST['workload'] ?? 'Manageable';
        $sponsorship = $_POST['sponsorship'] ?? 'No'; 
        $mental      = $_POST['mental'] ?? 5;
        $comments    = $_POST['comments'] ?? ''; // Added for the new field

        if ($report_id) {
            // Updating all columns including workload and comments
            $stmt = $conn->prepare("UPDATE work_wellness SET work_pressure=?, physical_pain=?, environment_suggestions=?, leader_comfort=?, workload_status=?, sponsorship_status=?, mental_health_rating=?, extra_comments=? WHERE id=? AND user_id=?");
            $stmt->bind_param("iissssssii", $pressure, $pain, $env, $leader, $workload, $sponsorship, $mental, $comments, $report_id, $userId);
        } else {
            // Inserting all columns including workload and comments
            $stmt = $conn->prepare("INSERT INTO work_wellness (user_id, work_pressure, physical_pain, environment_suggestions, leader_comfort, workload_status, sponsorship_status, mental_health_rating, extra_comments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssssis", $userId, $pressure, $pain, $env, $leader, $workload, $sponsorship, $mental, $comments);
        }
        
        if($stmt->execute()){
            if ($sponsorship === 'No' && !$report_id) {
                // (Your PHPMailer Alert Logic stays here)
            }
            header("Location: wellness.php?msg=Success");
            exit;
        }
    }
}

$history = $conn->prepare("SELECT * FROM work_wellness WHERE user_id = ? ORDER BY submitted_at DESC");
$history->bind_param("i", $userId);
$history->execute();
$historyResult = $history->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Wellness Sentinel Elite | Investhood IT</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap');
        
        :root {
            --primary: #4361ee;
            --bg-dark: #0f172a;
            --accent-teal: #00d4aa;
            --danger: #ef4444;
            --glass: rgba(255, 255, 255, 0.95);
        }

        body { 
            background: radial-gradient(circle at top left, #f1f5f9, #e2e8f0);
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0; min-height: 100vh; color: #1e293b;
        }

        .top-nav {
            background: var(--bg-dark);
            padding: 1.2rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .container {
            max-width: 1400px;
            margin: 40px auto;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 40px;
            padding: 0 25px;
        }

        .glass-card {
            background: var(--glass);
            backdrop-filter: blur(15px);
            border-radius: 35px;
            padding: 50px;
            border: 1px solid rgba(255,255,255,0.7);
            box-shadow: 0 30px 60px rgba(0,0,0,0.08);
        }

        .progress-banner {
            background: #ffffff;
            padding: 20px 30px;
            border-radius: 20px;
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid #edf2f7;
        }

        .bar-outer { width: 150px; height: 10px; background: #edf2f7; border-radius: 10px; overflow: hidden; margin-top: 5px; }
        .bar-inner { height: 100%; background: var(--primary); width: <?php echo ($monthlyCount/4)*100; ?>%; transition: 1s ease-in-out; }

        .mood-grid { display: flex; justify-content: space-between; gap: 15px; margin-bottom: 30px; }
        .mood-item { 
            flex: 1; text-align: center; padding: 20px; background: #fff; border: 2px solid #f1f5f9;
            border-radius: 20px; cursor: pointer; transition: 0.3s;
        }
        .mood-item:hover { transform: translateY(-5px); border-color: var(--primary); }
        .mood-item.active { background: var(--primary); border-color: var(--primary); color: white; }
        .mood-item i { font-size: 28px; display: block; margin-bottom: 8px; }
        .mood-item span { font-size: 12px; font-weight: 800; }

        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
        .full { grid-column: span 2; }
        label { font-weight: 800; font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 12px; }
        
        .stipend-toggle { display: flex; background: #f1f5f9; border-radius: 15px; padding: 5px; gap: 5px; }
        .stipend-toggle label { flex: 1; margin: 0; }
        .stipend-toggle input { display: none; }
        .stipend-toggle b { 
            display: block; padding: 15px; text-align: center; border-radius: 12px; 
            cursor: pointer; font-weight: 800; transition: 0.3s; 
        }
        .stipend-toggle input:checked + b { background: var(--primary); color: white; }

        textarea { 
            width: 100%; padding: 20px; border-radius: 18px; border: 2px solid #f1f5f9; 
            font-family: inherit; font-size: 14px; box-sizing: border-box; transition: 0.3s;
        }
        textarea:focus { border-color: var(--primary); outline: none; background: white; }

        .suggest-pill { 
            display: inline-block; padding: 6px 14px; background: white; border: 1.5px solid #e2e8f0;
            border-radius: 30px; font-size: 11px; font-weight: 700; color: var(--primary);
            margin: 8px 5px 0 0; cursor: pointer; transition: 0.2s;
        }
        .suggest-pill:hover { background: var(--primary); color: white; border-color: var(--primary); }

        .btn-submit {
            background: var(--bg-dark); color: white; border: none; padding: 22px; border-radius: 20px;
            font-weight: 800; font-size: 16px; width: 100%; cursor: pointer; transition: 0.4s; margin-top: 30px;
            box-shadow: 0 15px 30px rgba(15, 23, 42, 0.2);
        }
        .btn-submit:hover:not(:disabled) { background: var(--primary); transform: translateY(-4px); box-shadow: 0 20px 40px rgba(67, 97, 238, 0.3); }

        .history-card { 
            background: white; padding: 25px; border-radius: 25px; margin-bottom: 20px; 
            border-left: 6px solid var(--accent-teal); box-shadow: 0 10px 20px rgba(0,0,0,0.03);
            position: relative;
        }
        .history-card .date { font-size: 11px; font-weight: 800; color: var(--primary); margin-bottom: 10px; }
        .actions { margin-top: 20px; display: flex; gap: 20px; border-top: 1px solid #f1f5f9; padding-top: 15px; }
        .act-link { font-size: 11px; font-weight: 800; text-decoration: none; text-transform: uppercase; }
    </style>
</head>
<body>

<div class="top-nav">
    <a href="member_dashboard.php" style="color:white; text-decoration:none;"><i class="fas fa-chevron-left"></i> SYSTEM</a>
    <div style="font-weight:800; letter-spacing:2px;">WELLNESS <span style="color:var(--accent-teal)">SENTINEL</span></div>
</div>

<div class="container">
    <div class="glass-card">
        <div class="progress-banner">
            <div>
                <span style="font-weight:800; font-size:16px;">Activity: <?php echo $monthlyCount; ?> / 4 Reports</span>
                <div class="bar-outer"><div class="bar-inner"></div></div>
            </div>
            <div style="text-align:right">
                <span style="display:block; font-size:11px; font-weight:700; color:#64748b;">CURRENT MONTH</span>
                <span style="font-weight:800; color:var(--primary);"><?php echo date('F Y'); ?></span>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="report_id" value="<?php echo $edit_data['id'] ?? ''; ?>">
            
            <label>How are you feeling right now?</label>
            <div class="mood-grid">
                <div class="mood-item <?php echo ($edit_data['mental_health_rating'] ?? 0) == 10 ? 'active' : ''; ?>" onclick="setMood(10, this)">
                    <i class="fas fa-face-laugh-beam" style="color:#22c55e"></i><span>Amazing</span>
                </div>
                <div class="mood-item <?php echo ($edit_data['mental_health_rating'] ?? 0) == 7 ? 'active' : ''; ?>" onclick="setMood(7, this)">
                    <i class="fas fa-face-smile" style="color:#3b82f6"></i><span>Good</span>
                </div>
                <div class="mood-item <?php echo (!isset($edit_data) || ($edit_data['mental_health_rating'] ?? 5) == 5) ? 'active' : ''; ?>" onclick="setMood(5, this)">
                    <i class="fas fa-face-meh" style="color:#f59e0b"></i><span>Neutral</span>
                </div>
                <div class="mood-item <?php echo (isset($edit_data) && $edit_data['mental_health_rating'] <= 3) ? 'active' : ''; ?>" onclick="setMood(3, this)">
                    <i class="fas fa-face-frown" style="color:#ef4444"></i><span>Stressed</span>
                </div>
            </div>

            <input type="hidden" name="mental" id="mental_val" value="<?php echo $edit_data['mental_health_rating'] ?? 5; ?>">

            <div class="grid">
                <div class="form-group full">
                    <label>Workload Capacity (How busy are you?)</label>
                    <div class="stipend-toggle">
                        <label>
                            <input type="radio" name="workload" value="Manageable" <?php echo ($edit_data['workload_status'] ?? 'Manageable') == 'Manageable' ? 'checked' : ''; ?>>
                            <b>MANAGEABLE</b>
                        </label>
                        <label>
                            <input type="radio" name="workload" value="Too Much" <?php echo ($edit_data['workload_status'] ?? '') == 'Too Much' ? 'checked' : ''; ?>>
                            <b>TOO MUCH</b>
                        </label>
                    </div>
                </div>

                <div class="form-group full">
                    <label>Stipend Received for <?php echo date('F'); ?>?</label>
                    <div class="stipend-toggle">
                        <label>
                            <input type="radio" name="sponsorship" value="Yes" <?php echo ($edit_data['sponsorship_status'] ?? 'Yes') == 'Yes' ? 'checked' : ''; ?>>
                            <b>YES, PAID</b>
                        </label>
                        <label>
                            <input type="radio" name="sponsorship" value="No" <?php echo ($edit_data['sponsorship_status'] ?? '') == 'No' ? 'checked' : ''; ?>>
                            <b>NO, PENDING</b>
                        </label>
                    </div>
                </div>

                <div class="form-group full">
                    <label>Workspace & Environment</label>
                    <textarea name="environment" id="env_box" rows="2"><?php echo $edit_data['environment_suggestions'] ?? ''; ?></textarea>
                    <div class="suggest-pill" onclick="addText('env_box', 'Better internet stability required.')">WiFi Issue</div>
                    <div class="suggest-pill" onclick="addText('env_box', 'Desk ergonomics could be improved.')">Furniture</div>
                </div>

                <div class="form-group">
                    <label>Work Pressure (1-10)</label>
                    <input type="range" name="pressure" min="1" max="10" value="<?php echo $edit_data['work_pressure'] ?? 5; ?>" style="width:100%; accent-color:var(--primary);">
                </div>

                <div class="form-group">
                    <label>Leader Support (1-10)</label>
                    <input type="range" name="leader" min="1" max="10" value="<?php echo $edit_data['leader_comfort'] ?? 8; ?>" style="width:100%; accent-color:var(--primary);">
                </div>

                <div class="form-group full">
                    <label>Extra Comments (Optional)</label>
                    <textarea name="comments" rows="3" placeholder="Anything else you want to share?"><?php echo $edit_data['extra_comments'] ?? ''; ?></textarea>
                </div>
            </div>

            <button type="submit" name="submit_wellness" class="btn-submit" <?php echo (!$edit_data && $monthlyCount >= 4) ? 'disabled' : ''; ?>>
                <?php echo $edit_data ? 'Update Analysis' : ($monthlyCount >= 4 ? 'Monthly Limit Reached' : 'Transmit Insights'); ?>
            </button>
        </form>
    </div>

    <div class="history-sidebar">
        <h3 style="margin: 0 0 25px 0; font-weight:800; color:var(--bg-dark)">Transmission Logs</h3>
        <?php while($h = $historyResult->fetch_assoc()): ?>
            <div class="history-card">
                <div class="date"><?php echo date("D, d M Y", strtotime($h['submitted_at'])); ?></div>
                <div style="font-size:13px; font-weight:700;">
                    Stipend: <span style="color:<?php echo $h['sponsorship_status'] == 'Yes' ? 'var(--accent-teal)' : 'var(--danger)'; ?>">
                        <?php echo $h['sponsorship_status']; ?>
                    </span>
                </div>
                <div style="font-size:12px; margin-top:5px; color:#64748b;">Load: <b><?php echo $h['workload_status']; ?></b></div>
                <div class="actions">
                    <a href="?edit_id=<?php echo $h['id']; ?>" class="act-link" style="color:var(--primary)">Modify</a>
                    <a href="?delete_id=<?php echo $h['id']; ?>" class="act-link" style="color:var(--danger)" onclick="return confirm('Confirm permanent deletion?')">Delete</a>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<script>
    function setMood(val, el) {
        document.getElementById('mental_val').value = val;
        document.querySelectorAll('.mood-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
    }
    function addText(id, text) {
        document.getElementById(id).value = text;
    }
</script>

</body>
</html>