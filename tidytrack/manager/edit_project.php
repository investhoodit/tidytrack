<?php
session_start();
require_once __DIR__ . '/../dp.php';
@include __DIR__ . '/../profile.php';

$userID      = $_SESSION['user_id'] ?? null;
$currentRole = strtolower($currentRole ?? ($_SESSION['role'] ?? 'manager'));

if (!$userID || $currentRole !== 'manager') {
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
                WHERE project_id = ?
            ");
            $stmt->bind_param(
                "issssi",
                $primary_leader,
                $name,
                $deliverable,
                $start_date,
                $end_date,
                $id
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

    } else {

        $stmt = $conn->prepare("
            UPDATE sub_projects s
            JOIN projects_x p ON s.project_id = p.project_id
            SET s.sub_project_name = ?, s.deliverable = ?, s.start_date = ?, s.end_date = ?
            WHERE s.sub_project_id = ?
        ");
        $stmt->bind_param(
            "ssssi",
            $name,
            $deliverable,
            $start_date,
            $end_date,
            $id
        );
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
        WHERE project_id = ?
    ");
} else {
    $stmt = $conn->prepare("
        SELECT s.sub_project_name AS name, s.deliverable, s.start_date, s.end_date
        FROM sub_projects s
        JOIN projects_x p ON s.project_id = p.project_id
        WHERE s.sub_project_id = ?
    ");
}

$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) die("Not found");

$leaders = [];
$res = $conn->query("SELECT user_id, name FROM users WHERE LOWER(role)='leader' ORDER BY name");
while ($r = $res->fetch_assoc()) $leaders[] = $r;

$currentLeaders = [];
if ($itemType === 'project') {
    $res = $conn->query("SELECT teamLeadder_id FROM project_leader WHERE project_id=$id");
    while ($r = $res->fetch_assoc()) $currentLeaders[] = (int)$r['teamLeadder_id'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit <?= esc($itemType) ?> · Manager Portal</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --accent: #1d3557;
    --accent-light: #457b9d;
    --bg: #f0f4f9;
    --card: #ffffff;
    --border: #e2e8f0;
    --text: #0f172a;
    --sub: #64748b;
    --blue: #2563eb;
    --green: #059669;
    --rose: #dc2626;
    --r: 16px;
}
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    padding: 2rem;
    min-height: 100vh;
    transition: background 0.2s ease, color 0.2s ease;
}
body.dark {
    --bg: #121212;
    --card: #1e1e1e;
    --border: #2d2d2d;
    --text: #e2e8f0;
    --sub: #94a3b8;
}
.container {
    max-width: 800px;
    margin: 0 auto;
}
.form-card {
    background: var(--card);
    border-radius: var(--r);
    border: 1px solid var(--border);
    padding: 2rem;
    box-shadow: 0 8px 24px rgba(0,0,0,0.05);
    transition: background 0.2s;
}
.form-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
    justify-content: space-between;
}
.form-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--accent);
}
.back-btn {
    background: transparent;
    border: 1px solid var(--border);
    padding: 0.5rem 1rem;
    border-radius: 40px;
    color: var(--text);
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
}
.back-btn:hover {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
label {
    display: block;
    font-weight: 600;
    font-size: 0.85rem;
    margin-top: 1.25rem;
    margin-bottom: 0.25rem;
    color: var(--sub);
}
input, textarea, select {
    width: 100%;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: var(--card);
    color: var(--text);
    font-family: inherit;
    font-size: 0.9rem;
    transition: 0.2s;
}
input:focus, textarea:focus, select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(29,53,87,0.2);
}
textarea {
    resize: vertical;
}
.leader-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.75rem;
    margin-top: 0.5rem;
}
.leader-card {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 1rem;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
}
.leader-card:hover {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
.leader-card input {
    width: auto;
    margin: 0;
    transform: scale(1.1);
    accent-color: var(--accent);
}
.leader-card span {
    font-size: 0.85rem;
    font-weight: 500;
}
.leader-card input:checked ~ span {
    font-weight: 700;
    color: var(--accent);
}
body.dark .leader-card input:checked ~ span {
    color: #fff;
}
.hint {
    font-size: 0.7rem;
    color: var(--sub);
    margin-top: 0.5rem;
}
.btn-save {
    margin-top: 2rem;
    background: var(--accent);
    color: #fff;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 40px;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.btn-save:hover {
    background: var(--accent-light);
    transform: translateY(-1px);
}
.theme-toggle {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 48px;
    height: 48px;
    background: var(--accent);
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transition: 0.2s;
    z-index: 100;
}
.theme-toggle:hover {
    transform: scale(1.05);
}
@media (max-width: 640px) {
    body { padding: 1rem; }
    .form-card { padding: 1.5rem; }
    .leader-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="theme-toggle" id="themeToggle">
    <i class="fas fa-moon"></i>
</div>

<div class="container">
    <div class="form-card">
        <div class="form-header">
            <h2>
                <i class="fas fa-edit" style="margin-right: 0.5rem;"></i>
                Edit <?= ucfirst(str_replace('_',' ',$itemType)) ?>
            </h2>
            <a href="Project_viewing.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Projects
            </a>
        </div>

        <form method="post">
            <input type="hidden" name="item_type" value="<?= esc($itemType) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">

            <?php if ($itemType === 'project'): ?>
            <label><i class="fas fa-users"></i> Team Leaders</label>
            <div class="leader-grid">
                <?php foreach ($leaders as $l): ?>
                <label class="leader-card">
                    <input type="checkbox" name="leader_ids[]" value="<?= $l['user_id'] ?>"
                        <?= in_array($l['user_id'], $currentLeaders) ? 'checked' : '' ?>>
                    <span><?= esc($l['name']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="hint">
                <i class="fas fa-info-circle"></i> The first selected leader will be the primary leader.
            </div>
            <?php endif; ?>

            <label><i class="fas fa-heading"></i> Name</label>
            <input type="text" name="name" value="<?= esc($data['name']) ?>" required>

            <label><i class="fas fa-bullseye"></i> Deliverable</label>
            <textarea name="deliverable" rows="3" required><?= esc($data['deliverable']) ?></textarea>

            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <div style="flex: 1;">
                    <label><i class="fas fa-calendar-alt"></i> Start Date</label>
                    <input type="date" name="start_date" value="<?= esc($data['start_date']) ?>" required>
                </div>
                <div style="flex: 1;">
                    <label><i class="fas fa-calendar-check"></i> End Date</label>
                    <input type="date" name="end_date" value="<?= esc($data['end_date']) ?>" required>
                </div>
            </div>

            <button type="submit" class="btn-save">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </form>
    </div>
</div>

<script>
// Dark mode toggle with localStorage
const toggle = document.getElementById('themeToggle');
const body = document.body;
const icon = toggle.querySelector('i');

function setTheme(theme) {
    if (theme === 'dark') {
        body.classList.add('dark');
        icon.classList.remove('fa-moon');
        icon.classList.add('fa-sun');
    } else {
        body.classList.remove('dark');
        icon.classList.remove('fa-sun');
        icon.classList.add('fa-moon');
    }
    localStorage.setItem('theme', theme);
}

const savedTheme = localStorage.getItem('theme');
if (savedTheme === 'dark') setTheme('dark');
else setTheme('light');

toggle.addEventListener('click', () => {
    const isDark = body.classList.contains('dark');
    setTheme(isDark ? 'light' : 'dark');
});
</script>

</body>
</html>