<?php
session_start();
require_once __DIR__ . '/../dp.php';
@include __DIR__ . '/../profile.php';

$userName      = $userName ?? ($_SESSION['name'] ?? 'Manager');
$userID        = $_SESSION['user_id'] ?? null;
$currentRole   = $currentRole ?? ($_SESSION['role'] ?? 'manager');

if (!$userID || $currentRole !== 'manager') {
    die("Unauthorized. Access restricted to managers.");
}

/* ---------------------------------------------------
   Fetch team leaders (multiple allowed)
--------------------------------------------------- */
$leaders_result = $conn->query("
    SELECT user_id, name 
    FROM users 
    WHERE role='leader' 
    ORDER BY name ASC
");

$leaders = [];
if ($leaders_result && $leaders_result->num_rows > 0) {
    while ($row = $leaders_result->fetch_assoc()) $leaders[] = $row;
}

/* ---------------------------------------------------
   Fetch only projects created by this manager
--------------------------------------------------- */
$stmt_proj = $conn->prepare("
    SELECT project_id, project_name 
    FROM projects_x 
    WHERE created_by = ? 
    ORDER BY project_name ASC
");
$stmt_proj->bind_param("i", $userID);
$stmt_proj->execute();
$projects_result = $stmt_proj->get_result();

$projects = [];
if ($projects_result && $projects_result->num_rows > 0) {
    while ($row = $projects_result->fetch_assoc()) $projects[] = $row;
}
$stmt_proj->close();

/* ---------------------------------------------------
   AJAX HANDLING
--------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {

    /* ---------------------------------------------------
       FULL WEEKEND CREATION BLOCK
       No creation allowed on weekends
    --------------------------------------------------- */
    $todayDay = date('N'); // 6 = Sat, 7 = Sun
    if ($todayDay == 6 || $todayDay == 7) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Project creation is not allowed on weekends.'
        ]);
        exit;
    }

    if (isset($_POST['is_unified'])) {

        $type = $_POST['type'] ?? 'main';
        $project_name = trim($_POST['project_name'] ?? '');
        $deliverable = trim($_POST['deliverable'] ?? '');
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';

        if (!$project_name || !$deliverable || !$start_date || !$end_date) {
            echo json_encode(['status'=>'error', 'message'=>'Please fill all required fields.']);
            exit;
        }

        /* ---------------------------------------------------
           DATE VALIDATION RULES
        --------------------------------------------------- */
        $today = date('Y-m-d');
        $startDay = date('N', strtotime($start_date)); // 6 = Sat, 7 = Sun
        $endDay   = date('N', strtotime($end_date));   // 6 = Sat, 7 = Sun

        // Start cannot be before today
        if ($start_date < $today) {
            echo json_encode(['status'=>'error', 'message'=>'Start date cannot be before today.']);
            exit;
        }

        // Start cannot be on weekend
        if ($startDay == 6 || $startDay == 7) {
            echo json_encode(['status'=>'error', 'message'=>'Projects cannot start on weekends.']);
            exit;
        }

        // End cannot be before today
        if ($end_date < $today) {
            echo json_encode(['status'=>'error', 'message'=>'End date cannot be before today.']);
            exit;
        }

        // End cannot be on weekend
        if ($endDay == 6 || $endDay == 7) {
            echo json_encode(['status'=>'error', 'message'=>'End date cannot fall on weekends.']);
            exit;
        }

        // Start cannot be after end
        if (strtotime($start_date) > strtotime($end_date)) {
            echo json_encode(['status'=>'error','message'=>'Start date cannot be after end date.']);
            exit;
        }

        /* ----------------------------------
           ADD MAIN PROJECT (multiple leaders)
        ---------------------------------- */
        if ($type === 'main') {

            $team_leaders = $_POST['team_leaders'] ?? [];

            if (count($team_leaders) < 1) {
                echo json_encode(['status'=>'error', 'message'=>'Please select at least one team leader.']);
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO projects_x (project_name, deliverable, start_date, end_date, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ssssi", $project_name, $deliverable, $start_date, $end_date, $userID);

            if ($stmt->execute()) {

                $project_id = $stmt->insert_id;

                foreach ($team_leaders as $leader_id) {
                    $ins = $conn->prepare("
                        INSERT INTO project_leaders (project_id, leader_id)
                        VALUES (?, ?)
                    ");
                    $ins->bind_param("ii", $project_id, $leader_id);
                    $ins->execute();
                    $ins->close();
                }

                echo json_encode([
                    'status'=>'success',
                    'message'=>'Project added successfully',
                    'project'=>['id'=>$project_id, 'name'=>$project_name]
                ]);

            } else {
                echo json_encode(['status'=>'error', 'message'=>'Failed to add project.']);
            }

            $stmt->close();
            exit;
        }

        /* ----------------------------------
           ADD SUB-PROJECT
           (inherits leaders from parent)
        ---------------------------------- */
        if ($type === 'sub') {

            $main_project = $_POST['parent_project'] ?? '';

            if (!$main_project) {
                echo json_encode(['status'=>'error','message'=>'Please select a parent project.']);
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO sub_projects (project_id, sub_project_name, deliverable, start_date, end_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issssi", $main_project, $project_name, $deliverable, $start_date, $end_date, $userID);

            if ($stmt->execute()) {

                $sub_id = $stmt->insert_id;

                // Copy leaders from parent
                $leader_query = $conn->prepare("SELECT leader_id FROM project_leaders WHERE project_id = ?");
                $leader_query->bind_param("i", $main_project);
                $leader_query->execute();
                $l_res = $leader_query->get_result();

                while ($r = $l_res->fetch_assoc()) {
                    $leader_id = $r['leader_id'];

                    $ins = $conn->prepare("
                        INSERT INTO project_leaders (project_id, leader_id)
                        VALUES (?, ?)
                    ");
                    $ins->bind_param("ii", $sub_id, $leader_id);
                    $ins->execute();
                    $ins->close();
                }

                echo json_encode(['status'=>'success','message'=>'Sub-project added successfully.']);

            } else {
                echo json_encode(['status'=>'error','message'=>'Failed to add sub-project.']);
            }

            $stmt->close();
            exit;
        }
    }
}
?>

<!-- ========== STYLES ========== -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
    --accent: #1d3557;
    --accent-light: #457b9d;
    --bg: #eef2f5;
    --card-bg: #ffffff;
}

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    color: #222;
    margin: 0;
}

#projectFormContainer {
    max-width: 650px;
    margin: 20px auto;
    background: #fff;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
}

form label {
    display:block;
    margin-top:15px;
    font-weight:600;
    color:var(--accent);
}

.input-icon {
    position: relative;
}

.input-icon i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--accent-light);
}

.input-icon input {
    padding-left: 40px;
}

form input, form select {
    width:100%;
    padding:12px;
    margin-top:6px;
    border-radius:10px;
    border:1px solid #d0d7de;
    background:#f7f9fc;
}

select[multiple] { height:130px; }

.btn-row {
    display:flex;
    gap:10px;
    margin-top:20px;
}

button[type="submit"],
.btn-link {
    flex:1;
    padding:14px;
    border:none;
    border-radius:10px;
    background:linear-gradient(135deg,var(--accent),var(--accent-light));
    color:#fff;
    font-weight:600;
    cursor:pointer;
    text-align:center;
    text-decoration:none;
}

button:hover,
.btn-link:hover {
    opacity: .9;
}

.msg-success {
    background:#e3f8e8;
    padding:12px;
    border-radius:8px;
    text-align:center;
    color:#1b5e20;
}

.msg-error {
    background:#fdecea;
    padding:12px;
    border-radius:8px;
    text-align:center;
    color:#b71c1c;
}

.btn-secondary {
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    color: #fff;
    border: none;
}
</style>

<!-- ========== FORM ========== -->
<div id="projectFormContainer">
    <h2>Add Project / Sub-Project</h2>
    <div id="msg"></div>

    <form id="projectFormUnified">

        <label>Type:</label>
        <select id="type" name="type">
            <option value="main">Main Project</option>
            <option value="sub">Sub-Project</option>
        </select>

        <div id="parentProjectDiv" style="display:none;">
            <label>Parent Project:</label>
            <select name="parent_project" id="parent_project">
                <option value="">-- Select Main Project --</option>
                <?php foreach($projects as $p): ?>
                    <option value="<?= $p['project_id'] ?>"><?= htmlspecialchars($p['project_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="teamLeaderDiv">
            <label style="display:flex; align-items:center; gap:6px;">
                Team Leaders:

                <span 
                    style="
                        display:inline-block;
                        width:18px;
                        height:18px;
                        background:var(--accent);
                        color:#fff;
                        border-radius:50%;
                        text-align:center;
                        line-height:18px;
                        font-size:12px;
                        cursor:pointer;
                        position:relative;
                    "
                    onmouseover="document.getElementById('tl_tip').style.display='block';"
                    onmouseout="document.getElementById('tl_tip').style.display='none';"
                >
                    ?
                </span>

                <span id="tl_tip"
                    style="
                        display:none;
                        position:absolute;
                        margin-top:35px;
                        background:#ffffff;
                        color:#222;
                        padding:12px;
                        border-radius:10px;
                        box-shadow:0 4px 12px rgba(0,0,0,0.15);
                        font-size:13px;
                        width:230px;
                        z-index:10;
                        border:1px solid #d0d7de;
                    "
                >
                    Hold <strong>Ctrl</strong> (Windows) or <strong>Command ⌘</strong> (Mac)
                    while clicking to select multiple leaders.
                </span>
            </label>

            <select name="team_leaders[]" id="team_leaders" multiple>
                <?php foreach($leaders as $l): ?>
                    <option value="<?= $l['user_id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <label>Project Name:</label>
        <div class="input-icon">
            <i class="fa fa-folder"></i>
            <input type="text" name="project_name">
        </div>

        <label>Deliverable:</label>
        <div class="input-icon">
            <i class="fa fa-box"></i>
            <input type="text" name="deliverable">
        </div>

        <label>Start Date:</label>
        <input type="date" name="start_date" id="start_date" min="<?= date('Y-m-d') ?>">

        <label>End Date:</label>
        <input type="date" name="end_date" id="end_date" min="<?= date('Y-m-d') ?>">

        <label>Created By:</label>
        <div class="input-icon">
            <i class="fa fa-user"></i>
            <input type="text" value="<?= htmlspecialchars($userName) ?>" readonly>
        </div>

        <div class="btn-row">
            <button type="submit">Add Project</button>
            <button type="button" class="btn-secondary" onclick="window.location.href='viewing.php'">
    <i class="fa fa-folder-open"></i> View Projects
</button>
        </div>

    </form>
</div>

<script>
const typeSelect = document.getElementById('type');
const parentDiv = document.getElementById('parentProjectDiv');
const leaderDiv = document.getElementById('teamLeaderDiv');
const form = document.getElementById('projectFormUnified');
const msgBox = document.getElementById('msg');

typeSelect.addEventListener('change', () => {
    if (typeSelect.value === 'sub') {
        parentDiv.style.display = 'block';
        leaderDiv.style.display = 'none';
    } else {
        parentDiv.style.display = 'none';
        leaderDiv.style.display = 'block';
    }
});

/* AJAX SUBMISSION */
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msgBox.innerHTML = "";

    let formData = new FormData(form);
    formData.append('is_unified', '1');

    const res = await fetch('<?= basename(__FILE__) ?>', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With':'XMLHttpRequest' }
    });

    const text = await res.text();

    try {
        const data = JSON.parse(text);

        if (data.status === 'success') {
            msgBox.innerHTML = `<p class="msg-success">${data.message}</p>`;
            form.reset();
            parentDiv.style.display = 'none';
            leaderDiv.style.display = 'block';

            if (data.project) {
                let select = document.getElementById('parent_project');
                let opt = document.createElement('option');
                opt.value = data.project.id;
                opt.textContent = data.project.name;
                select.appendChild(opt);
            }
        } else {
            msgBox.innerHTML = `<p class="msg-error">${data.message}</p>`;
        }

    } catch (err) {
        msgBox.innerHTML = text;
    }
});
</script>
