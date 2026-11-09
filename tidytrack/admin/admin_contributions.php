<?php
session_start();
include '../dp.php';

// --- AUTH CHECK ---
$userId = $_SESSION['user_id'] ?? null;
$role = strtolower($_SESSION['role'] ?? '');
if (!$userId || $role !== 'admin') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// Handle filters
$filterProject = $_GET['project'] ?? '';
$filterMember  = $_GET['member'] ?? '';
$filterStatus  = $_GET['status'] ?? '';

// Build query with filters
$sql = "
SELECT 
    t.task_id,
    t.taskTitle,
    t.assignTo AS member_name,
    t.status,
    t.dueDate,
    p.project_name
FROM tasks t
JOIN projects_x p ON t.project_id = p.project_id
WHERE 1=1
";

$params = [];
$types = '';

if ($filterProject) {
    $sql .= " AND p.project_name = ?";
    $params[] = $filterProject;
    $types .= 's';
}

if ($filterMember) {
    $sql .= " AND t.assignTo = ?";
    $params[] = $filterMember;
    $types .= 's';
}

if ($filterStatus) {
    $sql .= " AND t.status = ?";
    $params[] = $filterStatus;
    $types .= 's';
}

$sql .= " ORDER BY p.project_name ASC, t.status ASC, t.dueDate ASC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("SQL Prepare Error: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Fetch all projects and members for the filter dropdowns
$projects = $conn->query("SELECT DISTINCT project_name FROM projects_x ORDER BY project_name ASC")->fetch_all(MYSQLI_ASSOC);
$members  = $conn->query("SELECT DISTINCT assignTo FROM tasks ORDER BY assignTo ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin - Member Contributions</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
      
 /* ==========================
   DARK MODE COMPATIBLE STYLES
   ========================== */

/* Default (light mode) variables */
:root {
    --bg: #f9f9f9;
    --ink: #333;

    --card-bg: #ffffff;
    --border: #ddd;

    --primary: #1d3557;
    --primary-light: #457b9d;

    --table-even: #f2f2f2;
    --table-odd: #ffffff;
}

/* Dark-mode variables (applied when iframe adds class="dark") */
.dark {
    --bg: #0f172a;
    --ink: #f1f5f9;

    --card-bg: #1e293b;
    --border: #334155;

    --primary: #a5d8ff;
    --primary-light: #74c0fc;

    --table-even: #16213a;
    --table-odd: #1a2333;
}

/* ==========================
   BASE PAGE
   ========================== */
body {
    font-family: Arial, sans-serif;
    margin: 20px;
    background: var(--bg) !important;
    color: var(--ink) !important;
    transition: background .25s ease, color .25s ease;
}

h1 {
    color: var(--primary) !important;
    font-size: 24px;
    text-align: center;
}

p {
    text-align: center;
    margin-bottom: 15px;
    font-size: 15px;
}

/* ==========================
   FILTER FORM
   ========================== */
.filter-form {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;

    background: var(--card-bg);
    padding: 10px;
    border-radius: 8px;
    box-shadow: 0 0 5px rgba(0,0,0,0.1);

    color: var(--ink);
}

.filter-form select,
.filter-form button {
    padding: 8px;
    font-size: 14px;
    background: var(--card-bg);
    color: var(--ink);
    border: 1px solid var(--border);
    border-radius: 4px;
}

.filter-form button {
    cursor: pointer;
    background-color: var(--primary);
    color: #fff !important;
    border: none;
}

.filter-form button:hover {
    background-color: var(--primary-light);
}

/* ==========================
   TABLE
   ========================== */
.table-container {
    overflow-x: auto;
    margin-top: 15px;

    background: var(--card-bg);
    border-radius: 8px;
    box-shadow: 0 0 5px rgba(0,0,0,0.1);
}

table {
    border-collapse: collapse;
    width: 100%;
    min-width: 600px;
    background: var(--card-bg);
    color: var(--ink);
}

th, td {
    border: 1px solid var(--border);
    padding: 10px;
    text-align: left;
    font-size: 14px;
}

th {
    background: var(--primary);
    color: #fff !important;
    text-transform: uppercase;
}

/* EVEN / ODD striping (dynamic for light & dark mode) */
tr:nth-child(even) {
    background: var(--table-even) !important;
}
tr:nth-child(odd) {
    background: var(--table-odd) !important;
}

/* ==========================
   STATUS COLORS (dark-mode compatible)
   ========================== */
.status-inreview {
    color: #ffb74d !important;
    font-weight: bold;
}

.status-completed {
    color: #4ade80 !important;
    font-weight: bold;
}

.status-pending {
    color: #f87171 !important;
    font-weight: bold;
}

/* ==========================
   RESPONSIVE
   ========================== */
@media (max-width: 768px) {
    body { margin: 10px; }
    h1 { font-size: 20px; }
    .filter-form { flex-direction: column; align-items: stretch; }
    .filter-form select, .filter-form button { width: 100%; font-size: 15px; }
    table th, table td { padding: 8px; font-size: 13px; }
}

@media (max-width: 480px) {
    table { min-width: 400px; }
    th, td { font-size: 12px; padding: 6px; }
}

    </style>
</head>
<body>

<h1>Member Contributions</h1>
<p>Admin can view all tasks assigned to members and track their progress.</p>

<form method="GET" class="filter-form">
    <select name="project">
        <option value="">All Projects</option>
        <?php foreach ($projects as $p): ?>
            <option value="<?php echo htmlspecialchars($p['project_name']); ?>" <?php if($filterProject==$p['project_name']) echo 'selected'; ?>>
                <?php echo htmlspecialchars($p['project_name']); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="member">
        <option value="">All Members</option>
        <?php foreach ($members as $m): ?>
            <option value="<?php echo htmlspecialchars($m['assignTo']); ?>" <?php if($filterMember==$m['assignTo']) echo 'selected'; ?>>
                <?php echo htmlspecialchars($m['assignTo']); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="status">
        <option value="">All Status</option>
        <option value="pending" <?php if($filterStatus=='pending') echo 'selected'; ?>>Pending</option>
        <option value="inreview" <?php if($filterStatus=='inreview') echo 'selected'; ?>>In Review</option>
        <option value="completed" <?php if($filterStatus=='completed') echo 'selected'; ?>>Completed</option>
    </select>

    <button type="submit">Filter</button>
    <a href="admin_contributions.php"><button type="button">Reset</button></a>
</form>

<div class="table-container">
<table>
    <thead>
        <tr>
            <th>Project</th>
            <th>Task Title</th>
            <th>Assigned To</th>
            <th>Due Date</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $statusClass = match($row['status']) {
                    'inreview' => 'status-inreview',
                    'completed' => 'status-completed',
                    default => 'status-pending'
                };
                echo "<tr>";
                echo "<td>".htmlspecialchars($row['project_name'])."</td>";
                echo "<td>".htmlspecialchars($row['taskTitle'])."</td>";
                echo "<td>".htmlspecialchars($row['member_name'])."</td>";
                echo "<td>".htmlspecialchars($row['dueDate'])."</td>";
                echo "<td class='$statusClass'>".htmlspecialchars($row['status'])."</td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='5'>No tasks found.</td></tr>";
        }
        ?>
    </tbody>
</table>
</div>

</body>
</html>
