<?php
session_start();
require_once __DIR__ . '/../dp.php';
@include __DIR__ . '/../profile.php';

$userID      = $_SESSION['user_id'] ?? null;
$currentRole = strtolower($currentRole ?? ($_SESSION['role'] ?? 'admin'));

if (!$userID || $currentRole !== 'admin') {
    die("Unauthorized");
}

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$itemType = $_GET['item_type'] ?? ($_POST['item_type'] ?? '');
$id       = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);

if (!$id || !in_array($itemType, ['project', 'sub_project'], true)) {
    die("Invalid request");
}

/* ======================
   UPDATE ON POST
====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name        = trim($_POST['name'] ?? '');
    $deliverable = trim($_POST['deliverable'] ?? '');
    $start_date  = $_POST['start_date'] ?? '';
    $end_date    = $_POST['end_date'] ?? '';

    if (!$name || !$deliverable || !$start_date || !$end_date) {
        die("All fields are required.");
    }

    if (strtotime($start_date) > strtotime($end_date)) {
        die("Start date cannot be after end date.");
    }

    /* ===== PROJECT UPDATE ===== */
    if ($itemType === 'project') {

        $leader_ids = $_POST['leader_ids'] ?? [];
        if (!is_array($leader_ids)) $leader_ids = [$leader_ids];

        $leader_ids = array_values(array_unique(array_map('intval', $leader_ids)));
        $leader_ids = array_filter($leader_ids, fn($v) => $v > 0);

        if (count($leader_ids) < 1) {
            die("Please select at least one team leader.");
        }

        $primary_leader = (int)$leader_ids[0];

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                UPDATE projects_x
                SET teamLeadder_id = ?, project_name = ?, deliverable = ?, start_date = ?, end_date = ?
                WHERE project_id = ? AND created_by = ?
            ");
            $stmt->bind_param(
                "issssii",
                $primary_leader,
                $name,
                $deliverable,
                $start_date,
                $end_date,
                $id,
                $userID
            );
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM project_leader WHERE project_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO project_leader (project_id, teamLeadder_id)
                VALUES (?, ?)
            ");
            foreach ($leader_ids as $lid) {
                $stmt->bind_param("ii", $id, $lid);
                $stmt->execute();
            }
            $stmt->close();

            $conn->commit();

        } catch (Throwable $e) {
            $conn->rollback();
            die($e->getMessage());
        }

    /* ===== SUB-PROJECT UPDATE ===== */
    } else {

        $stmt = $conn->prepare("
            UPDATE sub_projects s
            JOIN projects_x p ON s.project_id = p.project_id
            SET s.sub_project_name = ?, s.deliverable = ?, s.start_date = ?, s.end_date = ?
            WHERE s.sub_project_id = ? AND p.created_by = ?
        ");
        $stmt->bind_param("ssssii", $name, $deliverable, $start_date, $end_date, $id, $userID);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: Project_viewing.php");
    exit;
}

/* ======================
   FETCH DATA
====================== */
if ($itemType === 'project') {
    $stmt = $conn->prepare("
        SELECT project_name AS name, deliverable, start_date, end_date
        FROM projects_x
        WHERE project_id = ? AND created_by = ?
    ");
} else {
    $stmt = $conn->prepare("
        SELECT s.sub_project_name AS name, s.deliverable, s.start_date, s.end_date
        FROM sub_projects s
        JOIN projects_x p ON s.project_id = p.project_id
        WHERE s.sub_project_id = ? AND p.created_by = ?
    ");
}
$stmt->bind_param("ii", $id, $userID);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) die("Not found");

/* ===== LEADERS ===== */
$leaders = [];
$res = $conn->query("SELECT user_id, name FROM users WHERE LOWER(role)='leader' ORDER BY name");
while ($r = $res->fetch_assoc()) $leaders[] = $r;

/* ===== CURRENT LEADERS ===== */
$currentLeaders = [];
if ($itemType === 'project') {
    $res = $conn->query("SELECT teamLeadder_id FROM project_leader WHERE project_id=$id");
    while ($r = $res->fetch_assoc()) $currentLeaders[] = (int)$r['teamLeadder_id'];
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Edit <?= $itemType ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

<style>
body{font-family:Poppins,sans-serif;background:#eef2f5;padding:2rem}
.form-card{max-width:650px;margin:auto;background:#fff;padding:2rem;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.1)}
label{font-weight:600;margin-top:1rem;display:block}
input,textarea{width:100%;padding:.5rem;border-radius:8px;border:1px solid #ccc;margin-top:.3rem}
button{margin-top:1.5rem;padding:.6rem 1.6rem;border:none;border-radius:999px;background:#1d3557;color:#fff;font-weight:600}
.btn-secondary{
    display:inline-block;
    margin-top:1.5rem;
    padding:.6rem 1.6rem;
    border-radius:999px;
    background:#1d3557;
    color:#fff;
    font-weight:600;
    text-decoration:none;
    text-align:center;
    transition:.2s;
}

.btn-secondary:hover{
    background:#16304d;
}


/* ===== TEAM LEADER UI ===== */
.leader-grid{margin-top:.6rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.6rem}
.leader-card{position:relative;display:flex;align-items:center;gap:.6rem;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:10px;background:#f9fafb;cursor:pointer;transition:.2s}
.leader-card:hover{background:#eef4fa;border-color:#457b9d}
.leader-card input{position:absolute;opacity:0}
.checkmark{width:18px;height:18px;border-radius:5px;border:2px solid #9ca3af;display:flex;align-items:center;justify-content:center}
.checkmark::after{content:"";width:6px;height:10px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg) scale(0);transition:.15s}
.leader-card input:checked ~ .checkmark{background:#1d3557;border-color:#1d3557}
.leader-card input:checked ~ .checkmark::after{transform:rotate(45deg) scale(1)}
.leader-card input:checked ~ .leader-name{font-weight:600;color:#1d3557}
.hint{font-size:.85rem;opacity:.7;margin-top:.4rem}

 /* DARK MODE */
        body.dark{
            background:#1a1a1a;
            color:#f1f1f1;
        }

        body.dark .form-card{
            background:#2b2b2b;
            color:#fff;
        }

        body.dark input,
        body.dark textarea,
        body.dark select{
            background:#3a3a3a;
            border:1px solid #777;
            color:#fff;
        }

        body.dark button{
            background:#457b9d;
        }

        /* DARK MODE TOGGLE */
        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            width:45px;
            height:45px;
            background:#1d3557;
            color:#fff;
            border-radius:50%;
            display:flex;
            justify-content:center;
            align-items:center;
            font-size:22px;
            cursor:pointer;
            box-shadow:0 4px 8px rgba(0,0,0,0.2);
            transition:0.3s;
        }
        body.dark .theme-toggle {
            background:#457b9d;
        }
        
/* =========================
   DARK MODE — LEADER CHECKBOX
========================= */

body.dark .leader-card{
    background:#2f2f2f;
    border-color:#555;
}

body.dark .leader-card:hover{
    background:#383838;
    border-color:#6fa8dc;
}

/* checkbox border */
body.dark .checkmark{
    border-color:#999;
    background:transparent;
}

/* checked state */
body.dark .leader-card input:checked ~ .checkmark{
    background:#457b9d;
    border-color:#457b9d;
}

/* check icon */
body.dark .leader-card input:checked ~ .checkmark::after{
    border-color:#fff;
}

/* leader name text */
body.dark .leader-name{
    color:#e5e5e5;
}

/* leader name when checked */
body.dark .leader-card input:checked ~ .leader-name{
    color:#8ecae6;
    font-weight:600;
}

/* hint text */
body.dark .hint{
    color:#aaa;
}
        
</style>
</head>

<body>
<!-- 🌗 DARK MODE BUTTON -->
<div class="theme-toggle" id="themeToggle">🌙</div>

<script>
    const body = document.body;
    const toggleBtn = document.getElementById("themeToggle");

    if (localStorage.getItem("theme") === "dark") {
        body.classList.add("dark");
        toggleBtn.textContent = "☀️";
    }

    toggleBtn.addEventListener("click", () => {
        body.classList.toggle("dark");

        if (body.classList.contains("dark")) {
            toggleBtn.textContent = "☀️";
            localStorage.setItem("theme", "dark");
        } else {
            toggleBtn.textContent = "🌙";
            localStorage.setItem("theme", "light");
        }
    });
</script>
<div class="form-card">
<h2>Edit <?= ucfirst(str_replace('_',' ',$itemType)) ?></h2>

<form method="post">
<input type="hidden" name="item_type" value="<?= esc($itemType) ?>">
<input type="hidden" name="id" value="<?= $id ?>">

<?php if ($itemType === 'project'): ?>
<label>Team Leaders</label>
<div class="leader-grid">
<?php foreach ($leaders as $l): ?>
<label class="leader-card">
<input type="checkbox" name="leader_ids[]" value="<?= $l['user_id'] ?>"
<?= in_array($l['user_id'], $currentLeaders) ? 'checked' : '' ?>>
<span class="checkmark"></span>
<span class="leader-name"><?= esc($l['name']) ?></span>
</label>
<?php endforeach; ?>
</div>
<small class="hint">Primary leader = first checked</small>
<?php endif; ?>

<label>Name</label>
<input type="text" name="name" value="<?= esc($data['name']) ?>" required>

<label>Deliverable</label>
<textarea name="deliverable" rows="3" required><?= esc($data['deliverable']) ?></textarea>

<label>Start Date</label>
<input type="date" name="start_date" value="<?= esc($data['start_date']) ?>" required>

<label>End Date</label>
<input type="date" name="end_date" value="<?= esc($data['end_date']) ?>" required>

<button type="submit">Save Changes</button>
</form>

<a href="Project_viewing.php" class="btn-secondary">← Back</a>

</div>

</body>
</html>
