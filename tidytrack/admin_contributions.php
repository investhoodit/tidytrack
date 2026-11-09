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

// --- FILTERS (normalized) ---
$filter_project = isset($_GET['project']) && $_GET['project'] !== '' ? (int)$_GET['project'] : null;
$filter_user    = isset($_GET['user'])    && $_GET['user']    !== '' ? (int)$_GET['user']    : null;
$filter_role    = isset($_GET['role'])    ? strtolower(trim($_GET['role']))                 : '';

// --- Normalize missing dates (two passes) ---
$conn->query("
  UPDATE contributions
  SET start_date = DATE_SUB(CURDATE(), INTERVAL FLOOR(RAND()*30) DAY)
  WHERE start_date IS NULL
");
$conn->query("
  UPDATE contributions
  SET due_date = DATE_ADD(start_date, INTERVAL (FLOOR(RAND()*24)+7) DAY)
  WHERE due_date IS NULL
");

// --- Shared query builder ---
function build_query(mysqli $conn, ?int $project, ?int $user, string $roleLower): mysqli_stmt {
  $sql = "
    SELECT 
      c.id,
      p.project_name,
      COALESCE(NULLIF(TRIM(u.name),''), u.email) AS member_name,
      u.role,
      c.contribution_details,
      c.start_date,
      c.due_date,
      c.status
    FROM contributions c
    JOIN users u      ON u.user_id    = c.member_id
    JOIN projects_x p ON p.project_id = c.project_id
    WHERE TRIM(c.contribution_details) <> ''
  ";

  $conds = [];
  $types = '';
  $vals  = [];

  if ($project) { $conds[] = "p.project_id = ?"; $types .= 'i'; $vals[] = $project; }
  if ($user)    { $conds[] = "u.user_id    = ?"; $types .= 'i'; $vals[] = $user; }
  if ($roleLower !== '') { $conds[] = "LOWER(u.role) = ?"; $types .= 's'; $vals[] = $roleLower; }

  if ($conds) { $sql .= ' AND ' . implode(' AND ', $conds); }
  $sql .= " ORDER BY c.start_date DESC";

  $stmt = $conn->prepare($sql);
  if (!$stmt) { throw new RuntimeException('Prepare failed: '.$conn->error); }
  if ($types !== '') { $stmt->bind_param($types, ...$vals); }
  return $stmt;
}

// --- PDF EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
  require_once __DIR__ . '/../tcpdf/tcpdf.php';

  $stmt = build_query($conn, $filter_project, $filter_user, $filter_role);
  $stmt->execute();
  $rows = $stmt->get_result();

  $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
  $pdf->setPrintHeader(false);
  $pdf->setPrintFooter(false);
  $pdf->SetMargins(10,10,10);
  $pdf->SetAutoPageBreak(true,10);
  $pdf->AddPage();

  $logo = __DIR__ . '/../images/investhood.jpg';
  if (file_exists($logo)) { $pdf->Image($logo, 10, 10, 40); }

  $pdf->SetFont('helvetica','B',22);
  $pdf->SetXY(55,15);
  $pdf->Cell(0,10,'Contributions Report',0,1,'L');
  $pdf->Ln(20);

  $pdf->SetFont('helvetica','B',10);
  $pdf->SetFillColor(230,230,230);
  $header = ['Project'=>35,'Member'=>40,'Role'=>22,'Task'=>90,'Start'=>28,'Status'=>28,'Due'=>28];
  foreach ($header as $t=>$w) { $pdf->Cell($w,8,$t,1,0,'C',1); }
  $pdf->Ln();

  $pdf->SetFont('helvetica','',9);
  if ($rows && $rows->num_rows) {
    $fill = false;
    while ($r = $rows->fetch_assoc()) {
      $g = $fill ? 245 : 255; $pdf->SetFillColor($g,$g,$g);
      $taskText   = $r['contribution_details'] ?? '';
      $taskWidth  = $header['Task'];
      $taskHeight = $pdf->getStringHeight($taskWidth, $taskText);
      $rowH       = max(8, $taskHeight);

      $pdf->Cell($header['Project'],$rowH,$r['project_name'],1,0,'L',1);
      $pdf->Cell($header['Member'], $rowH,$r['member_name'], 1,0,'L',1);
      $pdf->Cell($header['Role'],   $rowH,$r['role'],        1,0,'C',1);
      $pdf->MultiCell($header['Task'],$rowH,$taskText,1,'L',1,0);
      $pdf->Cell($header['Start'],  $rowH, $r['start_date'] ? date('Y-m-d',strtotime($r['start_date'])) : '-', 1,0,'C',1);
      $pdf->Cell($header['Status'], $rowH, $r['status'] ?? '-', 1,0,'C',1);
      $pdf->Cell($header['Due'],    $rowH, $r['due_date']   ? date('Y-m-d',strtotime($r['due_date']))   : '-', 1,1,'C',1);

      $fill = !$fill;
    }
  } else {
    $pdf->SetFillColor(255,255,255);
    $pdf->Cell(array_sum($header),8,'No contributions found.',1,1,'C',1);
  }

  $pdf->Output('contributions_report.pdf','D');
  exit;
}

// --- HTML LIST (inner content only) ---
$stmt = build_query($conn, $filter_project, $filter_user, $filter_role);
$stmt->execute();
$result = $stmt->get_result();
?>

<h1 style="font-size:1.4rem;margin:0 0 1rem">Contributions</h1>

<form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
  <label>Project:
    <select name="project" style="padding:.45rem .9rem;min-width:140px;border-radius:5px;border:1px solid #bbb;background:#fff">
      <option value="">All</option>
      <?php
      $projects = $conn->query("SELECT project_id, project_name FROM projects_x WHERE TRIM(project_name)<>'' ORDER BY project_name");
      while ($p = $projects->fetch_assoc()):
        $sel = ($filter_project && (int)$p['project_id'] === (int)$filter_project) ? 'selected' : '';
      ?>
        <option value="<?= (int)$p['project_id'] ?>" <?= $sel ?>><?= htmlspecialchars(trim($p['project_name'])) ?></option>
      <?php endwhile; ?>
    </select>
  </label>

  <label>Member:
    <select name="user" style="padding:.45rem .9rem;min-width:140px;border-radius:5px;border:1px solid #bbb;background:#fff">
      <option value="">All</option>
      <?php
      $users = $conn->query("SELECT user_id, name, email FROM users ORDER BY name");
      while ($u = $users->fetch_assoc()):
        $display = trim($u['name']) !== '' ? trim($u['name']) : $u['email'];
        $sel = ($filter_user && (int)$u['user_id'] === (int)$filter_user) ? 'selected' : '';
      ?>
        <option value="<?= (int)$u['user_id'] ?>" <?= $sel ?>><?= htmlspecialchars($display) ?></option>
      <?php endwhile; ?>
    </select>
  </label>

  <label>Role:
    <select name="role" style="padding:.45rem .9rem;min-width:140px;border-radius:5px;border:1px solid #bbb;background:#fff">
      <option value="">All</option>
      <option value="leader"  <?= $filter_role==='leader'  ? 'selected' : '' ?>>Leader</option>
      <option value="member"  <?= $filter_role==='member'  ? 'selected' : '' ?>>Member</option>
      <option value="manager" <?= $filter_role==='manager' ? 'selected' : '' ?>>Manager</option>
      <option value="admin"   <?= $filter_role==='admin'   ? 'selected' : '' ?>>Admin</option>
    </select>
  </label>

  <button type="submit" style="padding:.45rem .9rem;border-radius:5px;border:1px solid #bbb;background:#fff;cursor:pointer">Filter</button>
  <a href="admin_contributions.php" style="padding:.45rem .9rem;border-radius:5px;border:1px solid #bbb;background:#fff;text-decoration:none;color:#333">Reset</a>
</form>

<div class="actions" style="margin:1rem 0 .5rem">
  <a
    href="admin_contributions.php?export=pdf<?= $filter_project ? '&project='.$filter_project : '' ?><?= $filter_user ? '&user='.$filter_user : '' ?><?= $filter_role ? '&role='.$filter_role : '' ?>"
    style="padding:.45rem .9rem;border-radius:5px;background:#2a9d8f;color:#fff;text-decoration:none;display:inline-block"
  >Export PDF</a>
</div>

<div style="overflow:auto">
  <table style="border-collapse:collapse;width:100%;background:#fff;margin-top:1rem">
    <thead>
      <tr>
        <th style="border:1px solid #ccc;padding:.5rem;background:#f0f0f0;text-align:left">Project</th>
        <th style="border:1px solid #ccc;padding:.5rem;background:#f0f0f0;text-align:left">Member</th>
        <th style="border:1px solid #ccc;padding:.5rem;background:#f0f0f0;text-align:left">Role</th>
        <th style="border:1px solid #ccc;padding:.5rem;background:#f0f0f0;text-align:left">Task</th>
        <th style="border:1px solid #ccc;padding:.5rem;background:#f0f0f0;text-align:left">Start Date</th>
        <th style="border:1px solid #ccc;padding:.5rem;background:#f0f0f0;text-align:left">Status</th>
        <th style="border:1px solid #ccc;padding:.5rem;background:#f0f0f0;text-align:left">Due Date</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($result && $result->num_rows): ?>
        <?php while ($r = $result->fetch_assoc()): ?>
          <tr>
            <td style="border:1px solid #eee;padding:.5rem"><?= htmlspecialchars($r['project_name']) ?></td>
            <td style="border:1px solid #eee;padding:.5rem"><?= htmlspecialchars($r['member_name']) ?></td>
            <td style="border:1px solid #eee;padding:.5rem"><?= htmlspecialchars($r['role']) ?></td>
            <td style="border:1px solid #eee;padding:.5rem"><?= htmlspecialchars($r['contribution_details']) ?></td>
            <td style="border:1px solid #eee;padding:.5rem"><?= $r['start_date'] ? htmlspecialchars(date('Y-m-d', strtotime($r['start_date']))) : '-' ?></td>
            <td style="border:1px solid #eee;padding:.5rem"><?= htmlspecialchars($r['status'] ?? '-') ?></td>
            <td style="border:1px solid #eee;padding:.5rem"><?= $r['due_date'] ? htmlspecialchars(date('Y-m-d', strtotime($r['due_date']))) : '-' ?></td>
          </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="7" style="text-align:center;color:#777;padding:.75rem">No contributions found</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
