<?php
session_start();
require_once __DIR__ . '/../dp.php';

// --- AUTH GUARD ---
$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || $role !== 'admin') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// --- FETCH FILTERS ---
$filter_project = $_GET['project'] ?? '';
$filter_user    = $_GET['user'] ?? '';
$filter_role    = $_GET['role'] ?? '';

$where = [];
if ($filter_project !== '' && is_numeric($filter_project)) {
    $where[] = "p.project_id = " . $conn->real_escape_string($filter_project);
}
if ($filter_user !== '' && is_numeric($filter_user)) {
    $where[] = "u.user_id = " . $conn->real_escape_string($filter_user);
}
if ($filter_role !== '') {
    $where[] = "u.role = '" . $conn->real_escape_string($filter_role) . "'";
}

// --- OPTIONAL: Auto-assign random start and due dates for contributions without them ---
$conn->query("
    UPDATE contributions
    SET start_date = IFNULL(start_date, DATE_SUB(CURDATE(), INTERVAL FLOOR(RAND()*30) DAY)),
        due_date   = IFNULL(due_date, DATE_ADD(start_date, INTERVAL FLOOR(RAND()*24)+7 DAY))
    WHERE start_date IS NULL OR due_date IS NULL
");

// --- PDF EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once __DIR__ . '/../tcpdf/tcpdf.php';

    $sql = "SELECT c.id, p.project_name, u.name AS member_name, u.role,
                   c.contribution_details, c.start_date, c.due_date, c.status
            FROM contributions c
            JOIN users u ON c.member_id = u.user_id
            JOIN projects_x p ON c.project_id = p.project_id
            WHERE TRIM(u.name) <> '' AND TRIM(c.contribution_details) <> ''";

    if ($where) {
        $sql .= " AND " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY c.start_date DESC";

    $rows = $conn->query($sql);

    // --- TCPDF SETUP ---
    $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('Contributions Report');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage();

    // --- LOGO ---
    $logoPath = __DIR__ . '/../images/investhood.jpg';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 10, 10, 40);
    }

    // --- TITLE ---
    $pdf->SetFont('helvetica', 'B', 22);
    $pdf->SetXY(55, 15);
    $pdf->Cell(0, 10, 'Contributions Report', 0, 1, 'L');
    $pdf->Ln(20);

    // --- TABLE HEADER ---
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);

    $header = [
        'Project' => 30,
        'Member' => 35,
        'Role' => 20,
        'Task' => 60,
        'Start' => 25,
        'Status' => 25,
        'Due' => 25
    ];

    foreach ($header as $title => $width) {
        $pdf->Cell($width, 8, $title, 1, 0, 'C', 1);
    }
    $pdf->Ln();

    // --- TABLE ROWS ---
    $pdf->SetFont('helvetica', '', 9);
    if ($rows && $rows->num_rows > 0) {
        $fill = false;
        while ($r = $rows->fetch_assoc()) {
            $gray = $fill ? 245 : 255;
            $pdf->SetFillColor($gray, $gray, $gray);

            $taskHeight = $pdf->getStringHeight($header['Task'], $r['contribution_details']);
            $rowHeight = max(8, $taskHeight);

            $pdf->Cell($header['Project'], $rowHeight, $r['project_name'], 1, 0, 'L', 1);
            $pdf->Cell($header['Member'], $rowHeight, $r['member_name'], 1, 0, 'L', 1);
            $pdf->Cell($header['Role'], $rowHeight, $r['role'], 1, 0, 'C', 1);
            $pdf->MultiCell($header['Task'], $rowHeight, $r['contribution_details'], 1, 'L', 1, 0);
            $pdf->Cell($header['Start'], $rowHeight, date('Y-m-d', strtotime($r['start_date'])), 1, 0, 'C', 1);
            $pdf->Cell($header['Status'], $rowHeight, $r['status'], 1, 0, 'C', 1);
            $pdf->Cell($header['Due'], $rowHeight, $r['due_date'] ? date('Y-m-d', strtotime($r['due_date'])) : '-', 1, 1, 'C', 1);

            $fill = !$fill;
        }
    } else {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Cell(array_sum($header), 8, 'No contributions found.', 1, 1, 'C', 1);
    }

    $pdf->Output('contributions_report.pdf', 'D');
    exit;
}

// ---- Fetch contributions for HTML ----
$sql = "SELECT c.id, p.project_name, u.name AS member_name, u.role,
               c.contribution_details, c.start_date, c.due_date, c.status
        FROM contributions c
        JOIN users u ON c.member_id = u.user_id
        JOIN projects_x p ON c.project_id = p.project_id
        WHERE TRIM(u.name) <> '' AND TRIM(c.contribution_details) <> ''";

if ($where) {
    $sql .= " AND " . implode(" AND ", $where);
}
$sql .= " ORDER BY c.start_date DESC";

$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin • Contributions</title>
<style>
body { font-family: Arial, sans-serif; margin: 1rem; background:#f9fafb; }
h1 { font-size: 1.4rem; margin-bottom: 1rem; }
form { margin-bottom: 1rem; display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
select, button, a.btn { padding:.45rem .9rem; min-width:110px; border-radius:5px; border:1px solid #bbb; font-size:.9rem; background:#fff; text-decoration:none; text-align:center; color:#333; cursor:pointer; }
button:hover, a.btn:hover { background:#f0f0f0; }
.btn-primary { background:#2a9d8f; color:#fff; border:none; }
.btn-primary:hover { background:#23867c; }
.actions { margin:1rem 0 .5rem 0; }
table { border-collapse: collapse; width:100%; background:#fff; margin-top:1.5rem; }
th, td { border:1px solid #ccc; padding:.5rem; font-size:.9rem; }
th { background:#f0f0f0; text-align:left; }
tr:nth-child(even){ background:#fafafa; }
</style>
</head>
<body>
<h1>Contributions</h1>

<form method="get">
<label>Project:
<select name="project">
<option value="">All</option>
<?php
$projects = $conn->query("SELECT project_id, project_name FROM projects_x WHERE TRIM(project_name) <> '' ORDER BY project_name");
while ($p = $projects->fetch_assoc()) {
    $name = htmlspecialchars(trim($p['project_name']));
    $sel = ((int)$p['project_id'] === (int)$filter_project) ? 'selected' : '';
    echo "<option value='{$p['project_id']}' $sel>{$name}</option>";
}
?>
</select>
</label>
<label>Member:
<select name="user">
<option value="">All</option>
<?php
$users = $conn->query("SELECT user_id, name FROM users WHERE TRIM(name) <> '' ORDER BY name");
while ($u = $users->fetch_assoc()) {
    $name = htmlspecialchars(trim($u['name']));
    $sel = ((int)$u['user_id'] === (int)$filter_user) ? 'selected' : '';
    echo "<option value='{$u['user_id']}' $sel>{$name}</option>";
}
?>
</select>
</label>
<label>Role:
<select name="role">
<option value="">All</option>
<option value="leader" <?= $filter_role=='leader'?'selected':'' ?>>Leader</option>
<option value="member" <?= $filter_role=='member'?'selected':'' ?>>Member</option>
</select>
</label>
<button type="submit" class="btn">Filter</button>
<a href="admin_contributions.php" class="btn">Reset</a>
</form>

<div class="actions">
<a href="admin_contributions.php?export=pdf<?= $filter_project ? "&project={$filter_project}" : "" ?><?= $filter_user ? "&user={$filter_user}" : "" ?><?= $filter_role ? "&role={$filter_role}" : "" ?>" class="btn btn-primary">Export PDF</a>
</div>

<table>
<tr>
<th>Project</th>
<th>Member</th>
<th>Role</th>
<th>Task</th>
<th>Start Date</th>
<th>Status</th>
<th>Due Date</th>
</tr>

<?php if ($result && $result->num_rows > 0): ?>
<?php while ($r = $result->fetch_assoc()): ?>
<tr>
<td><?= htmlspecialchars($r['project_name']) ?></td>
<td><?= htmlspecialchars($r['member_name']) ?></td>
<td><?= htmlspecialchars($r['role']) ?></td>
<td><?= htmlspecialchars($r['contribution_details']) ?></td>
<td><?= htmlspecialchars(date('Y-m-d', strtotime($r['start_date']))) ?></td>
<td><?= htmlspecialchars($r['status']) ?></td>
<td><?= htmlspecialchars($r['due_date'] ? date('Y-m-d', strtotime($r['due_date'])) : '-') ?></td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="7" style="text-align:center;color:#777;">No contributions found</td></tr>
<?php endif; ?>
</table>
</body>
</html>
