<?php
session_start();
include '../dp.php';
if (!isset($_SESSION['developer_id'])) {
    header('Location: index.php');
    exit;
}

$devName = explode('@', $_SESSION['developer_email'])[0];
$devName = ucfirst(strtolower($devName));
$dbName  = 'investh2_tidytrack';

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM information_schema.tables WHERE table_schema = ?");
$stmt->bind_param("s", $dbName);
$stmt->execute();
$totalTables = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

$roles = ['admin'=>0,'manager'=>0,'leader'=>0,'member'=>0];
$stmt  = $conn->prepare("SELECT role, COUNT(*) as cnt FROM users GROUP BY role");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $r = strtolower(trim($row['role']));
    if (isset($roles[$r])) $roles[$r] = $row['cnt'];
}
$stmt->close();

$stmt = $conn->prepare("SELECT
    (SELECT COUNT(*) FROM developers WHERE approved=1) as approved,
    (SELECT COUNT(*) FROM developers WHERE approved=0) as pending");
$stmt->execute();
$devStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total,
    SUM(CASE WHEN LOWER(status)='in progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN LOWER(status)='in review'   THEN 1 ELSE 0 END) as in_review,
    SUM(CASE WHEN LOWER(status)='completed'   THEN 1 ELSE 0 END) as completed
FROM tasks");
$stmt->execute();
$taskStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalUsers = array_sum($roles);
$msg = ''; $msgType = 'info';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['approve_dev'])) {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Please enter a valid email."; $msgType='error';
    } else {
        $check = $conn->prepare("SELECT id FROM developers WHERE email=?");
        $check->bind_param("s",$email); $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $msg="This email is already registered."; $msgType='error';
        } else {
            $tempPass = bin2hex(random_bytes(8));
            $hashed   = password_hash($tempPass, PASSWORD_DEFAULT);
            $insert   = $conn->prepare("INSERT INTO developers (email,password,approved) VALUES (?,?,1)");
            $insert->bind_param("ss",$email,$hashed);
            if ($insert->execute()) {
                $subject  = "Developer Portal Access Approved";
                $message  = "Your developer account has been approved!\n\nEmail: $email\nTemp Password: $tempPass\n\nChange password after first login.";
                $headers  = "From: no-reply@investhoodprojects.com\r\nReply-To: deandie17@gmail.com";
                if (mail($email,$subject,$message,$headers)) {
                    $msg="Approved! Credentials sent to <strong>$email</strong>."; $msgType='success';
                } else {
                    $msg="Approved, but email failed. Temp password: <strong>$tempPass</strong>"; $msgType='warning';
                }
            } else { $msg="Database error."; $msgType='error'; }
            $insert->close();
        }
        $check->close();
    }
}

$h = (int)date('H');
$greeting = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dev Portal · TidyTrack</title>
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
html{scroll-behavior:smooth;}
body{
    font-family:'Syne',sans-serif;
    background:var(--bg); color:var(--text);
    min-height:100vh; overflow-x:hidden;
}
body::before{
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:radial-gradient(circle,rgba(79,126,245,.055) 1px,transparent 1px);
    background-size:28px 28px;
}
body::after{
    content:''; position:fixed; top:-250px; right:-250px; z-index:0; pointer-events:none;
    width:700px; height:700px;
    background:radial-gradient(circle,rgba(79,126,245,.065) 0%,transparent 68%);
}
.page{position:relative;z-index:1;max-width:1300px;margin:0 auto;padding:36px 28px;}

/* TOPBAR */
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
.greet{margin-bottom:44px;animation:up .5s ease both;}
.greet-hi{font-size:2.3rem;font-weight:800;letter-spacing:-.6px;line-height:1.1;}
.greet-hi em{font-style:normal;color:var(--blue);}
.greet-meta{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--sub);margin-top:8px;}
.greet-meta span{color:var(--green);}

/* SECTION LABEL */
.sh{
    display:flex;align-items:center;gap:10px;
    font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;
    color:var(--sub);font-family:'JetBrains Mono',monospace;margin-bottom:18px;
}
.sh::after{content:'';flex:1;height:1px;background:var(--border);}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:12px;margin-bottom:44px;}
.sc{
    background:var(--card);border:1px solid var(--border);border-radius:var(--r);
    padding:22px 20px;position:relative;overflow:hidden;
    transition:transform .22s,background .22s,border-color .22s;
    animation:up .5s ease both;cursor:default;
}
.sc::before{
    content:'';position:absolute;top:0;left:0;right:0;height:2px;
    border-radius:var(--r) var(--r) 0 0;
    background:linear-gradient(90deg,var(--blue),var(--violet));
    transform:scaleX(0);transform-origin:left;transition:transform .3s ease;
}
.sc:hover{background:var(--card-h);border-color:var(--border-h);transform:translateY(-4px);}
.sc:hover::before{transform:scaleX(1);}
.sc-lbl{font-size:10px;font-family:'JetBrains Mono',monospace;color:var(--sub);font-weight:500;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;}
.sc-num{font-size:2.6rem;font-weight:800;color:var(--text);line-height:1;}
.sc-ico{position:absolute;bottom:14px;right:16px;font-size:1.5rem;opacity:.07;}
.sc:nth-child(1){animation-delay:.04s}.sc:nth-child(2){animation-delay:.08s}
.sc:nth-child(3){animation-delay:.12s}.sc:nth-child(4){animation-delay:.16s}
.sc:nth-child(5){animation-delay:.20s}.sc:nth-child(6){animation-delay:.24s}
.sc:nth-child(7){animation-delay:.28s}.sc:nth-child(8){animation-delay:.32s}

/* PILLS */
.pills{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:44px;}
.pill{
    display:flex;align-items:center;gap:7px;padding:7px 15px;border-radius:30px;
    font-size:12px;font-weight:700;font-family:'JetBrains Mono',monospace;
}
.pd{width:6px;height:6px;border-radius:50%;flex-shrink:0;}
.p-a{background:rgba(245,166,35,.1);color:var(--amber);} .p-a .pd{background:var(--amber);}
.p-v{background:rgba(124,90,245,.1);color:#a48af7;}      .p-v .pd{background:#a48af7;}
.p-g{background:rgba(16,201,122,.1);color:var(--green);} .p-g .pd{background:var(--green);}

/* ACTIONS */
.actions{display:grid;grid-template-columns:repeat(auto-fill,minmax(152px,1fr));gap:12px;margin-bottom:44px;}
.ac{
    background:var(--card);border:1px solid var(--border);border-radius:var(--r);
    padding:26px 16px;display:flex;flex-direction:column;align-items:center;gap:13px;
    text-decoration:none;color:var(--text);
    transition:all .28s cubic-bezier(.34,1.56,.64,1);
    animation:up .5s ease both;cursor:pointer;
}
.ac:hover{
    background:var(--card-h);border-color:var(--blue);
    transform:translateY(-8px) scale(1.03);
    box-shadow:0 22px 52px rgba(79,126,245,.14);
}
.ac-ico{
    width:54px;height:54px;border-radius:15px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.35rem;transition:transform .2s;
}
.ac:hover .ac-ico{transform:scale(1.12);}
.ac-lbl{font-size:13px;font-weight:700;text-align:center;}
.ac-sub{font-size:10px;color:var(--sub);font-family:'JetBrains Mono',monospace;text-align:center;}
.i-blue  {background:rgba(79,126,245,.14);color:var(--blue);}
.i-violet{background:rgba(124,90,245,.14);color:#a48af7;}
.i-green {background:rgba(16,201,122,.14);color:var(--green);}
.i-amber {background:rgba(245,166,35,.14);color:var(--amber);}
.i-rose  {background:rgba(245,79,107,.14);color:var(--rose);}
.i-cyan  {background:rgba(10,200,224,.14);color:var(--cyan);}
.ac:nth-child(1){animation-delay:.10s}.ac:nth-child(2){animation-delay:.14s}
.ac:nth-child(3){animation-delay:.18s}.ac:nth-child(4){animation-delay:.22s}
.ac:nth-child(5){animation-delay:.26s}.ac:nth-child(6){animation-delay:.30s}

/* APPROVE */
.approve-wrap{
    background:var(--card);border:1px solid var(--border);border-radius:20px;
    padding:32px;max-width:600px;animation:up .5s .35s ease both;
}
.approve-wrap h3{font-size:1rem;font-weight:700;margin-bottom:20px;}
.approve-row{display:flex;gap:10px;flex-wrap:wrap;}
.approve-row input{
    flex:1;min-width:220px;padding:13px 16px;
    background:var(--surface);border:1px solid var(--border);border-radius:12px;
    color:var(--text);font-size:13px;font-family:'JetBrains Mono',monospace;
    outline:none;transition:border .2s;
}
.approve-row input:focus{border-color:var(--blue);}
.approve-row input::placeholder{color:var(--sub);}
.approve-row button{
    padding:13px 26px;border-radius:12px;border:none;
    background:linear-gradient(135deg,var(--blue),var(--violet));
    color:#fff;font-size:13px;font-weight:700;font-family:'Syne',sans-serif;
    cursor:pointer;transition:opacity .2s,transform .15s;white-space:nowrap;
}
.approve-row button:hover{opacity:.88;transform:translateY(-1px);}
.approve-msg{margin-top:14px;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;}
.approve-msg.success{background:rgba(16,201,122,.1);color:var(--green);border:1px solid rgba(16,201,122,.22);}
.approve-msg.error  {background:rgba(245,79,107,.1);color:var(--rose); border:1px solid rgba(245,79,107,.22);}
.approve-msg.warning{background:rgba(245,166,35,.1);color:var(--amber);border:1px solid rgba(245,166,35,.22);}

@keyframes up{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);}}

@media(max-width:600px){
    .topbar{flex-direction:column;align-items:flex-start;gap:14px;}
    .tb-right{width:100%;justify-content:flex-end;}
    .stats{grid-template-columns:repeat(2,1fr);}
    .greet-hi{font-size:1.75rem;}
}
</style>
</head>
<body>
<div class="page">

    <div class="topbar">
        <div class="tb-brand">
            <div class="tb-logo">T</div>
            <div>
                <div class="tb-name">TidyTrack Dev Portal</div>
                <div class="tb-meta">investh2_tidytrack · <?= $totalTables ?> tables</div>
            </div>
        </div>
        <div class="tb-right">
            <div class="tb-clock" id="devClock">--:--:--</div>
            <a href="logout.php" class="btn-out"><i class="fas fa-power-off"></i> Logout</a>
        </div>
    </div>

    <div class="greet">
        <div class="greet-hi"><?= $greeting ?>, <em><?= htmlspecialchars($devName) ?></em> 👾</div>
        <div class="greet-meta">// <span><?= date('l, d F Y') ?></span> · system nominal</div>
    </div>

    <div class="sh"><span>System Overview</span></div>
    <div class="stats">
        <div class="sc"><div class="sc-lbl">Total Users</div><div class="sc-num"><?= $totalUsers ?></div><i class="fas fa-users sc-ico"></i></div>
        <div class="sc"><div class="sc-lbl">Members</div><div class="sc-num"><?= $roles['member'] ?></div><i class="fas fa-user sc-ico"></i></div>
        <div class="sc"><div class="sc-lbl">Leaders</div><div class="sc-num"><?= $roles['leader'] ?></div><i class="fas fa-user-tie sc-ico"></i></div>
        <div class="sc"><div class="sc-lbl">Managers</div><div class="sc-num"><?= $roles['manager'] ?></div><i class="fas fa-briefcase sc-ico"></i></div>
        <div class="sc"><div class="sc-lbl">Admins</div><div class="sc-num"><?= $roles['admin'] ?></div><i class="fas fa-shield-halved sc-ico"></i></div>
        <div class="sc"><div class="sc-lbl">Total Tasks</div><div class="sc-num"><?= $taskStats['total'] ?></div><i class="fas fa-list-check sc-ico"></i></div>
        <div class="sc"><div class="sc-lbl">Approved Devs</div><div class="sc-num"><?= $devStats['approved'] ?></div><i class="fas fa-code sc-ico"></i></div>
        <div class="sc"><div class="sc-lbl">DB Tables</div><div class="sc-num"><?= $totalTables ?></div><i class="fas fa-database sc-ico"></i></div>
    </div>

    <div class="pills">
        <div class="pill p-a"><div class="pd"></div>In Progress: <?= $taskStats['in_progress'] ?></div>
        <div class="pill p-v"><div class="pd"></div>In Review: <?= $taskStats['in_review'] ?></div>
        <div class="pill p-g"><div class="pd"></div>Completed: <?= $taskStats['completed'] ?></div>
    </div>

    <div class="sh"><span>Quick Actions</span></div>
    <div class="actions">
        <a href="dev_attendance.php" class="ac">
            <div class="ac-ico i-green"><i class="fas fa-calendar-check"></i></div>
            <div class="ac-lbl">Attendance</div>
            <div class="ac-sub">calendar view</div>
        </a>
        <a href="dev_delete_users.php" class="ac">
            <div class="ac-ico i-rose"><i class="fas fa-user-minus"></i></div>
            <div class="ac-lbl">Delete Users</div>
            <div class="ac-sub">manage users</div>
        </a>
        <a href="dev_delete_tasks.php" class="ac">
            <div class="ac-ico i-amber"><i class="fas fa-trash-alt"></i></div>
            <div class="ac-lbl">Delete Tasks</div>
            <div class="ac-sub">view task</div>
        </a>
        <a href="dev_delete_projects.php" class="ac">
            <div class="ac-ico i-violet"><i class="fas fa-folder-minus"></i></div>
            <div class="ac-lbl">Delete Projects</div>
            <div class="ac-sub">view projects</div>
        </a>
        <!-- NEW: Daily Report Appeals -->
    <a href="dev_daily_report_appeals.php" class="ac">
        <div class="ac-ico i-rose"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="ac-lbl">Report Appeals</div>
        <div class="ac-sub">banned members</div>
    </a>
        <a href="#" class="ac" onclick="alert('Coming soon'); return false;">
            <div class="ac-ico i-cyan"><i class="fas fa-video"></i></div>
            <div class="ac-lbl">Meeting Logs</div>
            <div class="ac-sub">coming soon</div>
        </a>
        <a href="#" class="ac" onclick="alert('Coming soon'); return false;">
            <div class="ac-ico i-blue"><i class="fas fa-chart-line"></i></div>
            <div class="ac-lbl">Analytics</div>
            <div class="ac-sub">coming soon</div>
        </a>
    </div>

    <div class="sh"><span>Grant Dev Access</span></div>
    <div class="approve-wrap">
        <h3>🔑 Approve New Developer</h3>
        <form method="POST">
            <div class="approve-row">
                <input type="email" name="email" placeholder="developer@email.com" required>
                <button type="submit" name="approve_dev">Approve &amp; Send</button>
            </div>
        </form>
        <?php if ($msg): ?>
            <div class="approve-msg <?= $msgType ?>"><?= $msg ?></div>
        <?php endif; ?>
    </div>

</div>
<script>
function tick(){
    document.getElementById('devClock').textContent = new Date().toLocaleTimeString('en-ZA',{
        hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Africa/Johannesburg'
    });
}
setInterval(tick,1000); tick();
</script>
</body>
</html>