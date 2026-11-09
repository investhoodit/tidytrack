<?php
session_start();
require_once __DIR__ . '/../dp.php';
@include __DIR__ . '/../profile.php';

$userName    = $userName ?? ($_SESSION['name'] ?? 'Manager');
$userID      = $_SESSION['user_id'] ?? null;
$currentRole = $currentRole ?? ($_SESSION['role'] ?? 'manager');

if (!$userID || $currentRole !== 'manager') {
    die("Unauthorized. Access restricted to managers.");
}

/* ---------------------------------------------
   FETCH MAIN PROJECTS CREATED BY THIS MANAGER
---------------------------------------------- */
$stmt = $conn->prepare("
    SELECT 
        p.*,
        c.name AS creator_name
    FROM projects_x p
    LEFT JOIN users c ON p.created_by = c.user_id
    WHERE p.created_by = ?
    ORDER BY p.start_date DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$projects_res = $stmt->get_result();
$projects = [];

while ($project = $projects_res->fetch_assoc()) {

    /* ---- Fetch MULTIPLE leaders for this project ---- */
    $lead = $conn->prepare("
        SELECT u.name
        FROM project_leaders pl
        INNER JOIN users u ON pl.leader_id = u.user_id
        WHERE pl.project_id = ?
        ORDER BY u.name ASC
    ");
    $lead->bind_param("i", $project['project_id']);
    $lead->execute();
    $lead_res = $lead->get_result();

    $leader_list = [];
    while ($row = $lead_res->fetch_assoc()) {
        $leader_list[] = $row['name'];
    }
    $project['leaders'] = $leader_list;
    $lead->close();

    /* ---- Fetch tasks for main project ---- */
    $t = $conn->prepare("
        SELECT t.*, u.name AS assigned_user
        FROM tasks t
        LEFT JOIN users u 
        ON t.assignTo = u.name OR t.assignTo = u.user_id
        WHERE t.project_id = ?
        ORDER BY t.dueDate ASC
    ");
    $t->bind_param("i", $project['project_id']);
    $t->execute();
    $task_res = $t->get_result();
    $project['tasks'] = $task_res->fetch_all(MYSQLI_ASSOC);
    $t->close();

    /* ---- Fetch SUB-PROJECTS ---- */
    $sp = $conn->prepare("
        SELECT s.*, u.name AS creator_name
        FROM sub_projects s
        LEFT JOIN users u ON s.created_by = u.user_id
        WHERE s.project_id = ?
        ORDER BY s.start_date ASC
    ");
    $sp->bind_param("i", $project['project_id']);
    $sp->execute();
    $sub_res = $sp->get_result();

    $sub_projects = [];

    while ($s = $sub_res->fetch_assoc()) {

        /* ---- Fetch MULTIPLE leaders for sub-project ---- */
        $l2 = $conn->prepare("
            SELECT u.name
            FROM project_leaders pl
            INNER JOIN users u ON pl.leader_id = u.user_id
            WHERE pl.project_id = ?
            ORDER BY u.name ASC
        ");
        $l2->bind_param("i", $s['sub_project_id']);
        $l2->execute();
        $l2_res = $l2->get_result();

        $sub_leaders = [];
        while ($lr = $l2_res->fetch_assoc()) {
            $sub_leaders[] = $lr['name'];
        }
        $s['leaders'] = $sub_leaders;
        $l2->close();

        /* ---- Fetch tasks for this sub-project ---- */
        $st = $conn->prepare("
            SELECT t.*, u.name AS assigned_user
            FROM tasks t
            LEFT JOIN users u 
                ON t.assignTo = u.name OR t.assignTo = u.user_id
            WHERE t.sub_project_id = ?
            ORDER BY t.dueDate ASC
        ");
        $st->bind_param("i", $s['sub_project_id']);
        $st->execute();
        $s['tasks'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        $sub_projects[] = $s;
    }

    $project['sub_projects'] = $sub_projects;
    $projects[] = $project;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Projects</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

<style>
:root {
    --accent:#1d3557;
    --accent-light:#457b9d;
    --bg:#eef2f5;
    --card-bg:#fff;
    --text:#222;
    --muted:#666;
    --border:#e0e0e0;
}

body {
    margin:0;
    padding:0;
    background:var(--bg);
    font-family:'Poppins',sans-serif;
}

body.dark-mode {
    --bg: #1e1e1e;
    --card-bg: #2b2b2b;
    --text: #e0e0e0;
    --muted: #aaa;
    --border: #444;
    --accent: #fca311;
    --accent-light: #ffba49;
}

.back-btn {
    display:inline-block;
    padding:0.5rem 1rem;
    background:var(--accent);
    color:#fff;
    text-decoration:none;
    border-radius:8px;
    font-weight:600;
    transition:.3s ease;
    margin-bottom:1.5rem;
}
.back-btn:hover { background:var(--accent-light); }

.page-wrapper {
    width:100%;
    max-width:1100px;
    margin:0 auto;
    padding:2rem;
}

h1 {
    text-align:center;
    font-size:2rem;
    font-weight:700;
    color:var(--accent);
    margin-bottom:2rem;
}

/* Dark Mode Toggle Button */
.dark-toggle {
    position: fixed;
    top: 20px;
    right: 20px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    transition: background 0.3s ease;
}
.dark-toggle:hover { background: var(--accent-light); }

.project-card {
    background: var(--card-bg);
    padding: 2rem;
    border-radius: 18px;
    margin-bottom: 2rem;
    border: 1px solid rgba(0,0,0,0.06);
    box-shadow: 0 4px 10px rgba(0,0,0,0.06);
    transition: .25s ease;
}
.project-card:hover { transform:translateY(-4px); }

.project-meta {
    background:#f7f9fc;
    padding:1rem;
    border-radius:12px;
    border:1px solid #e6e9ef;
    margin-bottom:1.4rem;
}
body.dark-mode .project-meta {
    background:#3a3a3a;
    border-color:#555;
}

/* PROGRESS BAR */
.progress-wrap {
    margin: 10px 0 15px 0;
}
.progress-label {
    font-weight: 600;
    color: var(--accent);
    font-size: .9rem;
}
.progress-bar-bg {
    width: 100%;
    height: 12px;
    background: #d0d7de;
    border-radius: 8px;
    margin-top: 6px;
}
.progress-bar-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    border-radius: 8px;
    transition: width .35s ease-in-out;
}

/* Action buttons */
.project-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.35rem 0.8rem;
    border-radius: 999px;
    border: none;
    text-decoration: none;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
}

.btn-edit   { background:#1d3557; color:#fff; }
.btn-delete { background:#e63946; color:#fff; }
.btn-export { background:#2a9d8f; color:#fff; }

/* ACCORDION */
.accordion {
    cursor:pointer;
    margin-bottom:1rem;
    border-radius:12px;
    border:1px solid var(--border);
    background:#fafafa;
    transition:.3s ease;
}
body.dark-mode .accordion { background:#3a3a3a; }

.accordion-header{
    display:flex;
    justify-content:space-between;
    padding:.8rem 1rem;
}
.accordion-icon{ 
    font-size:1.2rem; 
    transition:.3s ease; 
    font-weight:bold; 
    color:var(--accent);
}
.accordion.open .accordion-icon { transform:rotate(90deg); }

.accordion-body{
    max-height:0;
    overflow:hidden;
    padding:0 1rem;
    transition:max-height .35s ease, padding .3s ease;
}
.accordion.open .accordion-body{
    padding:.7rem 1rem;
    max-height:500px;
}
.accordion-content{
    background:#fff;
    border-radius:10px;
    padding:1rem;
    border:1px solid #eaeaea;
}
</style>
</head>

<body>

<!-- Dark Mode Button -->
<button class="dark-toggle" id="darkToggle">
    <i class="fa-solid fa-moon"></i>
</button>

<div class="page-wrapper">

<a href="manager_dashboard.php" class="back-btn">← Back to Dashboard</a>

<h1>📁 My Projects & Sub-Projects</h1>

<?php if (empty($projects)): ?>
<p style="text-align:center; color:#555;">No projects found.</p>

<?php else: foreach ($projects as $p): ?>
    <div class="project-card">

        <h2 style="color:var(--accent); font-weight:700;">
            <?= htmlspecialchars($p['project_name']) ?>
        </h2>

        <div class="project-meta">
            <strong>Team Leaders:</strong> 
            <?= !empty($p['leaders']) ? htmlspecialchars(implode(", ", $p['leaders'])) : "None" ?><br>

            <strong>Deliverable:</strong> <?= htmlspecialchars($p['deliverable']) ?><br>
            <strong>Created By:</strong> <?= htmlspecialchars($p['creator_name']) ?><br>
            <strong>Start:</strong> <?= $p['start_date'] ?> |
            <strong>End:</strong> <?= $p['end_date'] ?>
        </div>

        <!-- MAIN PROJECT PROGRESS BAR -->
        <div class="progress-wrap">
            <div class="progress-label">Timeline Progress</div>
            <div class="progress-bar-bg">
                <div class="progress-bar-fill"
                    data-start="<?= $p['start_date'] ?>"
                    data-end="<?= $p['end_date'] ?>"></div>
            </div>
        </div>

        <div class="project-actions">

            <a href="edit_project.php?item_type=project&id=<?= (int)$p['project_id'] ?>" 
                class="btn-action btn-edit">
                <i class="fa-solid fa-pen"></i> Edit
            </a>

            <form method="post" action="delete_project.php"
                onsubmit="return confirm('Delete this project and all linked data?');">
                <input type="hidden" name="item_type" value="project">
                <input type="hidden" name="id" value="<?= (int)$p['project_id'] ?>">
                <button type="submit" class="btn-action btn-delete">
                    <i class="fa-solid fa-trash"></i> Delete
                </button>
            </form>

            <form method="get" action="export_project.php">
                <input type="hidden" name="item_type" value="project">
                <input type="hidden" name="id" value="<?= (int)$p['project_id'] ?>">
                <button type="submit" class="btn-action btn-export">
                    <i class="fa-regular fa-file-pdf"></i> PDF
                </button>
            </form>

        </div>

        <!-- MAIN PROJECT TASKS -->
        <div class="tasks">
            <h4>🗒️ Tasks</h4>

            <?php if (empty($p['tasks'])): ?>
                <p>No tasks found.</p>
            <?php else: foreach($p['tasks'] as $t): ?>
                <div class="accordion">
                    <div class="accordion-header">
                        <div>
                            <strong><?= htmlspecialchars($t['taskTitle']) ?></strong><br>
                            <span class="task-meta">
                                Assigned: <?= htmlspecialchars($t['assigned_user'] ?? $t['assignTo']) ?> |
                                Due: <?= htmlspecialchars($t['dueDate']) ?> |
                                Priority: <?= htmlspecialchars($t['priority']) ?> |
                                Status: <?= htmlspecialchars($t['status']) ?>
                            </span>
                        </div>
                        <div class="accordion-icon">▶</div>
                    </div>
                    <div class="accordion-body">
                        <div class="accordion-content">
                            <?= nl2br(htmlspecialchars($t['description'])) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- SUB-PROJECTS -->
        <?php if (!empty($p['sub_projects'])): ?>
        <div class="sub-projects" style="margin-top:1.5rem; padding-left:1rem; border-left:3px solid #ccc;">
            <h4 style="color:var(--accent);">📂 Sub-Projects</h4>

            <?php foreach ($p['sub_projects'] as $sp): ?>
                <div class="project-card" style="padding:1rem;">

                    <h3><?= htmlspecialchars($sp['sub_project_name']) ?></h3>

                    <div class="project-meta">
                        <strong>Team Leaders:</strong> 
                        <?= !empty($sp['leaders']) ? htmlspecialchars(implode(", ", $sp['leaders'])) : "None" ?><br>

                        <strong>Deliverable:</strong> <?= htmlspecialchars($sp['deliverable']) ?><br>
                        <strong>Start:</strong> <?= $sp['start_date'] ?> |
                        <strong>End:</strong> <?= $sp['end_date'] ?>
                    </div>

                    <!-- SUB-PROJECT PROGRESS BAR -->
                    <div class="progress-wrap">
                        <div class="progress-label">Timeline Progress</div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill"
                                data-start="<?= $sp['start_date'] ?>"
                                data-end="<?= $sp['end_date'] ?>"></div>
                        </div>
                    </div>

        <div class="project-actions">

            <a href="edit_project.php?item_type=project&id=<?= (int)$p['project_id'] ?>" 
                class="btn-action btn-edit">
                <i class="fa-solid fa-pen"></i> Edit
            </a>

            <form method="post" action="delete_project.php"
                onsubmit="return confirm('Delete this project and all linked data?');">
                <input type="hidden" name="item_type" value="project">
                <input type="hidden" name="id" value="<?= (int)$p['project_id'] ?>">
                <button type="submit" class="btn-action btn-delete">
                    <i class="fa-solid fa-trash"></i> Delete
                </button>
            </form>

            <form method="get" action="export_project.php">
                <input type="hidden" name="item_type" value="project">
                <input type="hidden" name="id" value="<?= (int)$p['project_id'] ?>">
                <button type="submit" class="btn-action btn-export">
                    <i class="fa-regular fa-file-pdf"></i> PDF
                </button>
            </form>

        </div>

                    <!-- SUB-PROJECT TASKS -->
                    <div class="tasks">
                        <h5>🗒️ Tasks</h5>

                        <?php if (empty($sp['tasks'])): ?>
                            <p>No tasks found.</p>
                        <?php else: foreach($sp['tasks'] as $tsk): ?>
                            <div class="accordion">
                                <div class="accordion-header">
                                    <div>
                                        <strong><?= htmlspecialchars($tsk['taskTitle']) ?></strong><br>
                                        <span class="task-meta">
                                            Assigned: <?= htmlspecialchars($tsk['assigned_user'] ?? $tsk['assignTo']) ?> |
                                            Due: <?= htmlspecialchars($tsk['dueDate']) ?> |
                                            Priority: <?= htmlspecialchars($tsk['priority']) ?> |
                                            Status: <?= htmlspecialchars($tsk['status']) ?>
                                        </span>
                                    </div>
                                    <div class="accordion-icon">▶</div>
                                </div>
                                <div class="accordion-body">
                                    <div class="accordion-content">
                                        <?= nl2br(htmlspecialchars($tsk['description'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>

                    </div>

                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
<?php endforeach; endif; ?>

</div>

<!-- JAVASCRIPT -->
<script>
// ACCORDION OPEN/CLOSE
document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".accordion-header").forEach(header => {
        header.addEventListener("click", () => {
            const parent = header.parentElement;
            parent.classList.toggle("open");
        });
    });

    updateProgressBars();
});

const darkToggle = document.getElementById('darkToggle');
    darkToggle.addEventListener('click', () => {
        document.body.classList.toggle('dark-mode');
        const icon = darkToggle.querySelector('i');
        if(document.body.classList.contains('dark-mode')){
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        } else {
            icon.classList.remove('fa-sun');
            icon.classList.add('fa-moon');
        }
    });


// TIMELINE PROGRESS BAR LOGIC
function updateProgressBars() {
    document.querySelectorAll(".progress-bar-fill").forEach(bar => {

        let start = new Date(bar.dataset.start);
        let end = new Date(bar.dataset.end);
        let now = new Date();

        if (!bar.dataset.start || !bar.dataset.end) {
            bar.style.width = "0%";
            return;
        }

        if (now <= start) {
            bar.style.width = "0%";
        } else if (now >= end) {
            bar.style.width = "100%";
        } else {
            let total = end - start;
            let done = now - start;
            let percent = (done / total) * 100;
            bar.style.width = percent + "%";
        }
    });
}
</script>

</body>
</html>
