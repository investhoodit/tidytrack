<?php
// leader_manage_team_standalone.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../dp.php';

if (!function_exists('esc')) {
  function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ---------------------------
   AUTH (Leader only)
--------------------------- */
$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = strtolower(trim($_SESSION['role'] ?? ''));
$leaderName = trim($_SESSION['name'] ?? 'Leader');

if (!$userId || $role !== 'leader') {
  http_response_code(403);
  exit('Forbidden');
}

/* ---------------------------
   CONFIG
--------------------------- */
$MAIL_FROM  = "noreply@investhoodprojects.com";
$MAIL_REPLY = "noreply@investhoodprojects.com";

$signupFile = 'accept_invite.php';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\','/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$signupBaseUrl = "{$scheme}://{$host}{$basePath}/{$signupFile}";

/* ---------------------------
   Helpers
--------------------------- */
function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $r = $conn->query("SHOW TABLES LIKE '{$t}'");
  $ok = ($r && $r->num_rows > 0);
  if ($r) $r->free();
  return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
  $ok = ($r && $r->num_rows > 0);
  if ($r) $r->free();
  return $ok;
}

function ensureInviteTable(mysqli $conn): bool {
  if (!tableExists($conn, 'user_invites')) {
    $sql = "
      CREATE TABLE IF NOT EXISTS user_invites (
        invite_id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        role VARCHAR(30) NOT NULL DEFAULT 'member',
        token VARCHAR(128) NOT NULL,
        invited_by INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_invite_token (token),
        KEY idx_email_status (email, status),
        KEY idx_invited_by (invited_by)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    if (!$conn->query($sql)) return false;
  }

  if (!columnExists($conn, 'user_invites', 'invited_by')) {
    if (!$conn->query("ALTER TABLE user_invites ADD COLUMN invited_by INT NOT NULL DEFAULT 0 AFTER token")) return false;
  }

  return true;
}

function ensureTeamMembersLeaderColumn(mysqli $conn): bool {
  if (!tableExists($conn, 'team_members')) return true;
  if (!columnExists($conn, 'team_members', 'leader_id')) {
    if (!$conn->query("ALTER TABLE team_members ADD COLUMN leader_id INT NULL AFTER tm_id")) return false;
  }
  return true;
}

function detectTaskLeaderColumn(mysqli $conn): ?string {
  foreach (['created_by','assigned_by','leader_id','user_id'] as $col) {
    if (columnExists($conn, 'tasks', $col)) return $col;
  }
  return null;
}

function detectTaskDueDateColumn(mysqli $conn): ?string {
  foreach (['dueDate','due_date'] as $col) {
    if (columnExists($conn, 'tasks', $col)) return $col;
  }
  return null;
}

function detectTaskStatusColumn(mysqli $conn): ?string {
  foreach (['status','task_status'] as $col) {
    if (columnExists($conn, 'tasks', $col)) return $col;
  }
  return null;
}

function detectTaskAssignToColumn(mysqli $conn): ?string {
  foreach (['assignTo','assigned_to','assignee'] as $col) {
    if (columnExists($conn, 'tasks', $col)) return $col;
  }
  return null;
}

function detectTaskTitleColumn(mysqli $conn): ?string {
  foreach (['taskTitle','title','task_title','task_name','name','subject','description'] as $col) {
    if (columnExists($conn, 'tasks', $col)) return $col;
  }
  return null;
}

function detectTaskIdColumn(mysqli $conn): ?string {
  foreach (['task_id','id'] as $col) {
    if (columnExists($conn, 'tasks', $col)) return $col;
  }
  return null;
}

function sendInviteMail($to, $subject, $body, $fromEmail, $replyToEmail){
  $headers = [];
  $headers[] = "From: Investhood TMS <{$fromEmail}>";
  $headers[] = "Reply-To: {$replyToEmail}";
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/plain; charset=UTF-8";
  $headers[] = "X-Mailer: PHP/" . phpversion();
  $headersStr = implode("\r\n", $headers);

  $ok = mail($to, $subject, $body, $headersStr, "-f {$fromEmail}");

  if (!$ok) {
    $err = error_get_last();
    error_log("INVITE MAIL FAILED => to={$to} | subject={$subject} | err=" . ($err['message'] ?? 'unknown'));
  }

  return $ok;
}

ensureTeamMembersLeaderColumn($conn);

$popupTitle   = '';
$popupMessage = '';

/* ---------------------------
   DATA: Team Members
   FIX:
   - do not rely only on leader_id
   - merge direct members + fallback from tasks
--------------------------- */
$teamMembers = [];
$teamMembersMap = [];

$taskLeaderColumn   = detectTaskLeaderColumn($conn);
$taskAssignToColumn = detectTaskAssignToColumn($conn);

/* 1. Direct members linked to leader_id */
if (tableExists($conn, 'team_members')) {
  if (columnExists($conn, 'team_members', 'leader_id')) {
    $stmt = $conn->prepare("
      SELECT tm_id, name, email, date_joined
      FROM team_members
      WHERE leader_id = ?
      ORDER BY date_joined DESC
    ");
    if ($stmt) {
      $stmt->bind_param("i", $userId);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $key = strtolower(trim($row['email'] ?? ''));
        if ($key === '') $key = 'tm_' . (int)$row['tm_id'];
        $teamMembersMap[$key] = $row;
      }
      $stmt->close();
    }
  }

  /* 2. Fallback members from tasks created by this leader */
  if (tableExists($conn, 'tasks') && $taskLeaderColumn !== null && $taskAssignToColumn !== null) {
    $sqlFallback = "
      SELECT DISTINCT tm.tm_id, tm.name, tm.email, tm.date_joined
      FROM team_members tm
      INNER JOIN tasks t
        ON (
          LOWER(TRIM(t.`{$taskAssignToColumn}`)) = LOWER(TRIM(tm.email))
          OR LOWER(TRIM(t.`{$taskAssignToColumn}`)) = LOWER(TRIM(tm.name))
          OR LOWER(TRIM(t.`{$taskAssignToColumn}`)) LIKE CONCAT('%', LOWER(TRIM(tm.email)), '%')
          OR LOWER(TRIM(t.`{$taskAssignToColumn}`)) LIKE CONCAT('%', LOWER(TRIM(tm.name)), '%')
        )
      WHERE t.`{$taskLeaderColumn}` = ?
      ORDER BY tm.date_joined DESC
    ";
    $stmtF = $conn->prepare($sqlFallback);
    if ($stmtF) {
      $stmtF->bind_param("i", $userId);
      $stmtF->execute();
      $resF = $stmtF->get_result();
      while ($row = $resF->fetch_assoc()) {
        $key = strtolower(trim($row['email'] ?? ''));
        if ($key === '') $key = 'tm_' . (int)$row['tm_id'];
        if (!isset($teamMembersMap[$key])) {
          $teamMembersMap[$key] = $row;
        }
      }
      $stmtF->close();
    }
  }
}

$teamMembers = array_values($teamMembersMap);

/* sort newest first */
usort($teamMembers, function($a, $b) {
  return strcmp((string)($b['date_joined'] ?? ''), (string)($a['date_joined'] ?? ''));
});

$teamCount = count($teamMembers);

/* ---------------------------
   Task stats
--------------------------- */
$memberStatsByEmail = [];
$assignedCount = 0;
$overdueCount  = 0;
$productivity  = 0;

$taskStatusColumn   = detectTaskStatusColumn($conn);
$taskDueDateColumn  = detectTaskDueDateColumn($conn);
$taskTitleColumn    = detectTaskTitleColumn($conn);
$taskIdColumn       = detectTaskIdColumn($conn);

if (tableExists($conn, 'tasks') && $teamCount > 0) {
  foreach ($teamMembers as $m) {
    $em = strtolower(trim($m['email'] ?? ''));
    if ($em !== '') {
      $memberStatsByEmail[$em] = ['total'=>0,'completed'=>0,'active'=>0,'overdue'=>0];
    }
  }

  if ($taskLeaderColumn !== null && $taskAssignToColumn !== null && $taskStatusColumn !== null) {
    $dueSql = $taskDueDateColumn !== null
      ? "SUM(
          (`{$taskStatusColumn}` IN ('In progress','In review'))
          AND `{$taskDueDateColumn}` IS NOT NULL
          AND `{$taskDueDateColumn}` < CURDATE()
        ) AS overdue_tasks"
      : "0 AS overdue_tasks";

    $sqlAgg = "
      SELECT
        `{$taskAssignToColumn}` AS assign_to_value,
        COUNT(*) AS total_tasks,
        SUM(`{$taskStatusColumn}` IN ('Completed','Approved')) AS completed_tasks,
        SUM(`{$taskStatusColumn}` IN ('In progress','In review')) AS active_tasks,
        {$dueSql}
      FROM tasks
      WHERE `{$taskAssignToColumn}` IS NOT NULL
        AND `{$taskAssignToColumn}` <> ''
        AND `{$taskStatusColumn}` <> 'Archived'
        AND `{$taskLeaderColumn}` = ?
      GROUP BY `{$taskAssignToColumn}`
    ";
    $stmtAgg = $conn->prepare($sqlAgg);
    if ($stmtAgg) {
      $stmtAgg->bind_param("i", $userId);
      $stmtAgg->execute();
      $rows = $stmtAgg->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmtAgg->close();

      foreach ($rows as $r) {
        $assign = strtolower(trim((string)($r['assign_to_value'] ?? '')));
        if ($assign === '') continue;

        $matchedEmail = '';
        foreach ($teamMembers as $m) {
          $em = strtolower(trim($m['email'] ?? ''));
          $nm = strtolower(trim($m['name'] ?? ''));
          if ($em !== '' && (strpos($assign, $em) !== false || $assign === $em)) { $matchedEmail = $em; break; }
          if ($nm !== '' && $assign === $nm && $em !== '') { $matchedEmail = $em; break; }
          if ($nm !== '' && strpos($assign, $nm) !== false && $em !== '') { $matchedEmail = $em; break; }
        }

        if ($matchedEmail !== '' && isset($memberStatsByEmail[$matchedEmail])) {
          $memberStatsByEmail[$matchedEmail]['total']     += (int)$r['total_tasks'];
          $memberStatsByEmail[$matchedEmail]['completed'] += (int)$r['completed_tasks'];
          $memberStatsByEmail[$matchedEmail]['active']    += (int)$r['active_tasks'];
          $memberStatsByEmail[$matchedEmail]['overdue']   += (int)$r['overdue_tasks'];
        }
      }
    }
  }

  $assignedCount  = array_sum(array_map(fn($x)=> (int)$x['active'], $memberStatsByEmail));
  $overdueCount   = array_sum(array_map(fn($x)=> (int)$x['overdue'], $memberStatsByEmail));
  $completedTotal = array_sum(array_map(fn($x)=> (int)$x['completed'], $memberStatsByEmail));
  $den = max(1, $assignedCount + $completedTotal);
  $productivity = (int)round(($completedTotal / $den) * 100);
}

/* ---------------------------
   POST: Remove members
--------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_members'])) {
  $rawIds = trim($_POST['remove_members'] ?? '');
  $ids = array_filter(array_map('intval', explode(',', $rawIds)), fn($id) => $id > 0);

  if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids)) . 'i';

    $sql = "DELETE FROM team_members WHERE tm_id IN ({$placeholders}) AND leader_id = ?";
    $stmtDel = $conn->prepare($sql);

    if ($stmtDel) {
      $params = [...$ids, $userId];
      $stmtDel->bind_param($types, ...$params);
      $stmtDel->execute();
      $affected = $stmtDel->affected_rows;
      $stmtDel->close();

      $_SESSION['invite_popup'] = ['Success', "✅ Removed {$affected} member(s) from your team."];
    } else {
      $_SESSION['invite_popup'] = ['Error', '❌ Failed to remove selected member(s).'];
    }
  }

  header("Location: " . basename(__FILE__) . "?tab=members");
  exit();
}

/* ---------------------------
   POST: Invite user
--------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invite_user'])) {
  $inviteEmail = strtolower(trim($_POST['invite_email'] ?? ''));
  $inviteRole  = strtolower(trim($_POST['invite_role'] ?? 'member'));

  if ($inviteEmail === '' || !filter_var($inviteEmail, FILTER_VALIDATE_EMAIL)) {
    $popupTitle = "Error";
    $popupMessage = "❌ Please enter a valid email address.";
  } else {
    if (!ensureInviteTable($conn)) {
      $popupTitle = "Error";
      $popupMessage = "❌ user_invites table is missing and could not be created.";
    } else {
      $dup = false;
      $stmtD = $conn->prepare("SELECT 1 FROM user_invites WHERE email = ? AND status = 'pending' LIMIT 1");
      if ($stmtD) {
        $stmtD->bind_param("s", $inviteEmail);
        $stmtD->execute();
        $dup = $stmtD->get_result()->num_rows > 0;
        $stmtD->close();
      }

      if ($dup) {
        $popupTitle = "Info";
        $popupMessage = "ℹ️ There is already a pending invite for this email address.";
      } else {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');
        $inviteUrl = $signupBaseUrl . '?token=' . urlencode($token);

        $stmtI = $conn->prepare("
          INSERT INTO user_invites (email, role, token, invited_by, status, expires_at)
          VALUES (?, ?, ?, ?, 'pending', ?)
        ");

        if (!$stmtI) {
          $popupTitle = "Error";
          $popupMessage = "❌ DB prepare failed: " . $conn->error;
        } else {
          $stmtI->bind_param("sssis", $inviteEmail, $inviteRole, $token, $userId, $expiresAt);

          if (!$stmtI->execute()) {
            $popupTitle = "Error";
            $popupMessage = "❌ Failed to create invite: " . $stmtI->error;
          } else {
            $subject = "Invitation to join Investhood Projects";
            $body =
              "Hello,\n\n" .
              "You have been invited by {$leaderName} to join Investhood Projects as a {$inviteRole}.\n\n" .
              "Please create your account using the link below:\n" .
              "{$inviteUrl}\n\n" .
              "This invitation link will expire in 7 days.\n\n" .
              "If you did not expect this invitation, please ignore this email.\n\n" .
              "Best regards,\n" .
              "Investhood Task Management System\n";

            $sent = sendInviteMail($inviteEmail, $subject, $body, $MAIL_FROM, $MAIL_REPLY);

            if ($sent) {
              $popupTitle = "Success";
              $popupMessage = "✅ Your invitation was sent to {$inviteEmail}.";
            } else {
              $popupTitle = "Invite created (email not sent)";
              $popupMessage = "✅ Invite created in the database, but email was NOT sent.\n\nManual invite link:\n{$inviteUrl}";
            }
          }
          $stmtI->close();
        }
      }
    }
  }

  $_SESSION['invite_popup'] = [$popupTitle, $popupMessage];
  header("Location: " . basename(__FILE__) . "?tab=invite");
  exit();
}

/* popup after redirect */
if (!empty($_SESSION['invite_popup'])) {
  [$popupTitle, $popupMessage] = $_SESSION['invite_popup'];
  unset($_SESSION['invite_popup']);
}

/* ---------------------------
   Invites list
--------------------------- */
$invites = [];
if (ensureInviteTable($conn)) {
  $conn->query("
    UPDATE user_invites
    SET status = 'expired'
    WHERE invited_by = {$userId}
      AND status = 'pending'
      AND expires_at IS NOT NULL
      AND expires_at < NOW()
  ");

  $stmtInv = $conn->prepare("
    SELECT invite_id, email, role, status, token, expires_at, created_at
    FROM user_invites
    WHERE invited_by = ?
    ORDER BY created_at DESC
  ");
  if ($stmtInv) {
    $stmtInv->bind_param("i", $userId);
    $stmtInv->execute();
    $invites = $stmtInv->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtInv->close();
  }
}

$tab = strtolower($_GET['tab'] ?? 'members');
if (!in_array($tab, ['members','invite','invites'], true)) {
  $tab = 'members';
}

/* ---------------------------
   Recent tasks by member
--------------------------- */
$recentTasksByEmail = [];
if (
  tableExists($conn, 'tasks') &&
  $taskLeaderColumn !== null &&
  $taskAssignToColumn !== null &&
  $taskStatusColumn !== null
) {
  foreach ($teamMembers as $m) {
    $email = strtolower(trim($m['email'] ?? ''));
    $name  = strtolower(trim($m['name'] ?? ''));
    if ($email === '' && $name === '') continue;

    $titleSql = $taskTitleColumn !== null ? "`{$taskTitleColumn}`" : "''";
    $dueSql   = $taskDueDateColumn !== null ? "`{$taskDueDateColumn}`" : "NULL";
    $idSql    = $taskIdColumn !== null ? "`{$taskIdColumn}`" : "0";

    $sqlRecent = "
      SELECT
        {$idSql} AS task_id,
        {$titleSql} AS task_title,
        `{$taskStatusColumn}` AS task_status,
        {$dueSql} AS due_date
      FROM tasks
      WHERE `{$taskLeaderColumn}` = ?
        AND (
          LOWER(TRIM(`{$taskAssignToColumn}`)) = ?
          OR LOWER(TRIM(`{$taskAssignToColumn}`)) = ?
          OR LOWER(TRIM(`{$taskAssignToColumn}`)) LIKE CONCAT('%', ?, '%')
          OR LOWER(TRIM(`{$taskAssignToColumn}`)) LIKE CONCAT('%', ?, '%')
        )
      ORDER BY " . ($taskDueDateColumn !== null ? "`{$taskDueDateColumn}` ASC" : ($taskIdColumn !== null ? "`{$taskIdColumn}` DESC" : "`{$taskStatusColumn}` ASC")) . "
      LIMIT 5
    ";

    $stmtRecent = $conn->prepare($sqlRecent);
    if ($stmtRecent) {
      $stmtRecent->bind_param("issss", $userId, $email, $name, $email, $name);
      $stmtRecent->execute();
      $recentTasksByEmail[$email] = $stmtRecent->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmtRecent->close();
    } else {
      $recentTasksByEmail[$email] = [];
    }
  }
}

/* ---------------------------
   Member JSON
--------------------------- */
$memberJson = [];
foreach ($teamMembers as $m) {
  $email = strtolower(trim($m['email'] ?? ''));
  $st = $memberStatsByEmail[$email] ?? ['total'=>0,'completed'=>0,'active'=>0,'overdue'=>0];
  $total = (int)$st['total'];
  $completed = (int)$st['completed'];
  $active = (int)$st['active'];
  $overdue = (int)$st['overdue'];
  $pct = $total > 0 ? (int)round(($completed / max(1,$total)) * 100) : 0;

  $statusLabel = 'No tasks';
  if ($total > 0) {
    if ($pct >= 100)      $statusLabel = 'Completed';
    elseif ($overdue > 0) $statusLabel = 'Overdue risk';
    elseif ($pct >= 70)   $statusLabel = 'On track';
    elseif ($pct < 30)    $statusLabel = 'Behind';
    else                  $statusLabel = 'In progress';
  }

  $memberJson[] = [
    'tm_id'        => (int)$m['tm_id'],
    'name'         => $m['name'] ?? '',
    'email'        => $email,
    'date_joined'  => $m['date_joined'] ?? '',
    'total'        => $total,
    'completed'    => $completed,
    'active'       => $active,
    'overdue'      => $overdue,
    'pct'          => $pct,
    'status'       => $statusLabel,
    'recent_tasks' => $recentTasksByEmail[$email] ?? [],
  ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Manage Team</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root{
      --bg:#f6f8fb;
      --card:#fff;
      --ink:#0f172a;
      --muted:#64748b;
      --line:#e5eaf2;
      --brand:#1d3557;
      --accent:#5271ff;
      --soft:#eef2ff;
      --danger:#dc2626;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background:var(--bg);
      color:var(--ink);
      font-family:Poppins,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif
    }
    .wrap{max-width:1180px;margin:28px auto;padding:0 16px 24px}
    .hero{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}
    .title h2{margin:0;font-size:1.6rem;color:var(--brand)}
    .title p{margin:.35rem 0 0;color:var(--muted)}
    .btn{
      border:1px solid var(--line);
      background:#fff;
      color:var(--ink);
      padding:10px 14px;
      border-radius:12px;
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      gap:8px;
      font-weight:800;
      font-size:.9rem;
      transition:all .15s
    }
    .btn:hover{border-color:#c7d2fe;background:#f5f7ff}
    .btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
    .btn-primary:hover{background:#3d5af1;border-color:#3d5af1}
    .btn-danger{background:var(--danger);border-color:var(--danger);color:#fff}
    .btn-danger:hover{background:#b91c1c}
    .btn-sm{padding:7px 11px;font-size:.82rem}
    .cards{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:14px}
    .card{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:16px;
      box-shadow:0 10px 28px rgba(2,6,23,.06);
      padding:14px
    }
    .kpi{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
    .label{color:var(--muted);font-size:.9rem}
    .value{font-size:1.35rem;font-weight:900;margin-top:4px}
    .chip{
      font-size:.78rem;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid var(--line);
      color:var(--muted);
      background:#f8fafc
    }
    .tabs{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 10px}
    .tab{
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--line);
      background:#fff;
      cursor:pointer;
      font-weight:900;
      color:#1f2a44;
      transition:all .15s
    }
    .tab:hover{border-color:#c7d2fe;background:#f5f7ff}
    .tab.active{background:#eef2ff;border-color:#c7d2fe;color:#1e3a8a}
    .bulk-bar{
      display:none;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
      background:#fff;
      border:1px solid var(--line);
      border-radius:14px;
      padding:10px 14px;
      margin-bottom:10px;
      box-shadow:0 4px 14px rgba(82,113,255,.1)
    }
    .bulk-bar.visible{display:flex}
    .bulk-count{font-weight:900;color:var(--accent);white-space:nowrap}
    .table-wrap{overflow:auto;border-radius:14px;border:1px solid var(--line)}
    table{width:100%;border-collapse:collapse;background:#fff;font-size:.95rem}
    th{
      padding:10px 12px;
      background:#f1f5f9;
      color:#475569;
      border-bottom:1px solid var(--line);
      text-align:left;
      position:sticky;
      top:0
    }
    td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:middle}
    tbody tr{transition:background .12s}
    tbody tr.row-clickable{cursor:pointer}
    tbody tr.row-clickable:hover{background:#f5f7ff}
    tbody tr.selected{background:#eef2ff}
    tbody tr.selected td{border-bottom-color:#c7d2fe}
    th.col-check, td.col-check{
      width:40px;
      text-align:center;
      cursor:default
    }
    input[type="checkbox"]{
      width:17px;height:17px;
      accent-color:var(--accent);
      cursor:pointer;
      border-radius:4px
    }
    .progress{
      height:10px;
      background:#eef2ff;
      border-radius:999px;
      overflow:hidden;
      border:1px solid #dde3ff;
      min-width:140px
    }
    .bar{height:100%;background:var(--accent)}
    .badge{
      display:inline-flex;
      align-items:center;
      gap:6px;
      font-size:.82rem;
      padding:5px 10px;
      border-radius:999px;
      border:1px solid var(--line);
      background:#f8fafc;
      color:#334155
    }
    .b-ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
    .b-warn{border-color:#fde68a;background:#fffbeb;color:#92400e}
    .b-bad{border-color:#fecaca;background:#fff1f2;color:#9f1239}
    .action-link{
      padding:7px 10px;
      border-radius:10px;
      border:1px solid #c7d2fe;
      background:#eef2ff;
      color:#1e3a8a;
      font-weight:800;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space:nowrap;
    }
    .action-link:hover{background:#dfe8ff}
    label{display:block;font-weight:900;margin-bottom:6px}
    input[type="email"],input[type="text"],select{
      width:100%;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--line)
    }

    .popup-overlay{
      position:fixed;
      inset:0;
      background:rgba(15,23,42,.55);
      display:none;
      align-items:center;
      justify-content:center;
      z-index:9999;
      padding:16px;
    }
    .popup{
      width:min(560px,92vw);
      background:#fff;
      border-radius:18px;
      border:1px solid rgba(0,0,0,.08);
      box-shadow:0 18px 60px rgba(0,0,0,.25);
      padding:18px;
      animation:popupIn .2s ease;
    }
    .popup h3{
      margin:0 0 10px;
      color:var(--brand);
      font-size:1.2rem;
    }
    .popup p{
      white-space:pre-line;
      color:var(--ink);
      margin:0 0 16px;
      line-height:1.5;
    }
    .ok{
      background:var(--accent);
      border:1px solid var(--accent);
      color:#fff;
      padding:10px 16px;
      border-radius:12px;
      cursor:pointer;
      font-weight:900
    }
    .ok:hover{
      background:#3d5af1;
      border-color:#3d5af1;
    }

    .detail-panel{
      position:fixed;
      top:0;
      right:-520px;
      width:min(500px,100vw);
      height:100vh;
      background:#fff;
      border-left:1px solid var(--line);
      box-shadow:-8px 0 40px rgba(0,0,0,.12);
      overflow-y:auto;
      z-index:8889;
      transition:right .28s cubic-bezier(.4,0,.2,1);
      display:flex;
      flex-direction:column
    }
    .detail-panel.open{right:0}
    .detail-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:18px 20px 14px;
      border-bottom:1px solid var(--line);
      position:sticky;
      top:0;
      background:#fff;
      z-index:1
    }
    .detail-avatar{
      width:52px;
      height:52px;
      border-radius:50%;
      background:var(--soft);
      color:var(--accent);
      font-size:1.3rem;
      font-weight:900;
      display:flex;
      align-items:center;
      justify-content:center;
      flex-shrink:0
    }
    .detail-close{
      width:40px;
      height:40px;
      border:none;
      background:#f8fafc;
      color:var(--muted);
      border-radius:12px;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:1.1rem;
      transition:all .15s ease;
    }
    .detail-close:hover{
      background:#eef2ff;
      color:var(--ink);
    }
    .detail-body{padding:18px 20px;flex:1}
    .detail-name{font-size:1.2rem;font-weight:900;color:var(--brand)}
    .detail-email{color:var(--muted);font-size:.9rem;margin-top:2px}
    .detail-section{margin-top:18px}
    .detail-section h4{
      margin:0 0 10px;
      font-size:.85rem;
      text-transform:uppercase;
      letter-spacing:.06em;
      color:var(--muted)
    }
    .stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .stat-box{
      background:var(--bg);
      border:1px solid var(--line);
      border-radius:12px;
      padding:12px 14px
    }
    .stat-box .s-val{font-size:1.4rem;font-weight:900;color:var(--ink)}
    .stat-box .s-lbl{font-size:.8rem;color:var(--muted);margin-top:2px}
    .detail-progress-wrap{margin-top:10px}
    .detail-bar-bg{height:12px;background:#eef2ff;border-radius:999px;overflow:hidden;border:1px solid #dde3ff}
    .detail-bar-fill{height:100%;background:var(--accent);border-radius:999px;transition:width .5s}
    .detail-footer{
      padding:14px 20px;
      border-top:1px solid var(--line);
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      background:#fff
    }
    .task-list{display:grid;gap:10px}
    .task-item{
      border:1px solid var(--line);
      border-radius:12px;
      padding:12px;
      background:#fafcff;
    }
    .task-item-title{
      font-weight:900;
      color:var(--ink);
      margin-bottom:6px;
    }
    .task-meta{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      color:var(--muted);
      font-size:.88rem;
    }
    .small-note{color:var(--muted);font-size:.9rem}
    .hidden-row{display:none}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.82rem}

    @keyframes popupIn{
      from{opacity:0;transform:translateY(8px) scale(.98)}
      to{opacity:1;transform:translateY(0) scale(1)}
    }
  </style>
</head>
<body>

<div class="popup-overlay" id="popupOverlay">
  <div class="popup">
    <h3 id="popupTitle"></h3>
    <p id="popupMessage"></p>
    <div style="display:flex;justify-content:flex-end">
      <button class="ok" type="button" id="popupOkBtn">OK</button>
    </div>
  </div>
</div>

<div class="detail-panel" id="detailPanel">
  <div class="detail-header">
    <div style="display:flex;align-items:center;gap:12px">
      <div class="detail-avatar" id="dpAvatar">?</div>
      <div>
        <div class="detail-name" id="dpName">—</div>
        <div class="detail-email" id="dpEmail">—</div>
      </div>
    </div>
    <button class="detail-close" id="detailCloseBtn" title="Close panel" type="button" onclick="closeDetailPanel(); return false;">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>

  <div class="detail-body">
    <div class="detail-section">
      <h4>Member info</h4>
      <div style="color:var(--muted);font-size:.9rem">
        Joined: <strong id="dpJoined">—</strong>
      </div>
      <div style="margin-top:6px">Status: <span class="badge" id="dpStatusBadge">—</span></div>
    </div>

    <div class="detail-section">
      <h4>Task breakdown</h4>
      <div class="stat-grid">
        <div class="stat-box">
          <div class="s-val" id="dpTotal">0</div>
          <div class="s-lbl">Total tasks</div>
        </div>
        <div class="stat-box">
          <div class="s-val" id="dpCompleted">0</div>
          <div class="s-lbl">Completed</div>
        </div>
        <div class="stat-box">
          <div class="s-val" id="dpActive">0</div>
          <div class="s-lbl">Active</div>
        </div>
        <div class="stat-box" id="dpOverdueBox">
          <div class="s-val" id="dpOverdue">0</div>
          <div class="s-lbl">Overdue</div>
        </div>
      </div>
    </div>

    <div class="detail-section">
      <h4>Progress</h4>
      <div class="detail-progress-wrap">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
          <span style="font-size:.9rem;color:var(--muted)">Completion rate</span>
          <strong id="dpPct">0%</strong>
        </div>
        <div class="detail-bar-bg">
          <div class="detail-bar-fill" id="dpBar" style="width:0%"></div>
        </div>
      </div>
    </div>

    <div class="detail-section">
      <h4>Recent task details</h4>
      <div class="task-list" id="dpTaskList">
        <div class="small-note">No task details available.</div>
      </div>
    </div>
  </div>
</div>

<div class="wrap">
  <div class="hero">
    <div class="title">
      <h2>Manage Team</h2>
    </div>
  </div>

  <div class="cards">
    <div class="card">
      <div class="kpi">
        <div>
          <div class="label">Team members assigned</div>
          <div class="value"><?= (int)$teamCount ?></div>
        </div>
        <div class="chip"><i class="fa-solid fa-users"></i> Team</div>
      </div>
    </div>

    <div class="card">
      <div class="kpi">
        <div>
          <div class="label">Invites sent</div>
          <div class="value"><?= count($invites) ?></div>
        </div>
        <div class="chip"><i class="fa-solid fa-paper-plane"></i> Invites</div>
      </div>
    </div>
  </div>

  <div class="tabs">
    <button class="tab" id="tab-members" type="button" onclick="openTab('members')">Team progress</button>
    <button class="tab" id="tab-invite" type="button" onclick="openTab('invite')">Invite</button>
    <button class="tab" id="tab-invites" type="button" onclick="openTab('invites')">Invite status</button>
  </div>

  <div id="pane-members">
    <div class="card">
      <h3 style="margin:0 0 4px;color:var(--brand)">Team progress</h3>

      <div class="bulk-bar" id="bulkBar">
        <span class="bulk-count" id="bulkCount">0 selected</span>
        <button class="btn btn-sm" id="bulkEmailBtn" type="button">
          <i class="fa-solid fa-envelope"></i> Email
        </button>
        <button class="btn btn-sm" id="bulkExportBtn" type="button">
          <i class="fa-solid fa-file-export"></i> Export CSV
        </button>
        <button class="btn btn-danger btn-sm" id="bulkRemoveBtn" type="button">
          <i class="fa-solid fa-user-minus"></i> Remove
        </button>
        <button class="btn btn-sm" id="bulkClearBtn" type="button" style="margin-left:auto">
          <i class="fa-solid fa-xmark"></i> Clear
        </button>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" id="masterCheck" title="Select all"></th>
              <th>Member</th>
              <th>Email</th>
              <th>Joined</th>
              <th>Completed</th>
              <th>Total</th>
              <th>Progress</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($teamMembers)): ?>
            <tr>
              <td colspan="9" style="color:var(--muted)">No team members found for this leader.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($teamMembers as $m):
              $email   = strtolower(trim($m['email'] ?? ''));
              $name    = trim($m['name'] ?? $email);
              $joined  = trim($m['date_joined'] ?? '');
              $tm_id   = (int)$m['tm_id'];
              $st = $memberStatsByEmail[$email] ?? ['total'=>0,'completed'=>0,'active'=>0,'overdue'=>0];

              $total     = (int)$st['total'];
              $completed = (int)$st['completed'];
              $active    = (int)$st['active'];
              $overdue   = (int)$st['overdue'];
              $pct       = $total > 0 ? (int)round(($completed / max(1,$total)) * 100) : 0;

              $badge = 'b-warn';
              $label = 'No tasks';
              if ($total > 0) {
                if ($pct >= 100)     { $badge='b-ok';  $label='Completed'; }
                elseif ($overdue>0)  { $badge='b-bad'; $label='Overdue risk'; }
                elseif ($pct >= 70)  { $badge='b-ok';  $label='On track'; }
                elseif ($pct < 30)   { $badge='b-bad'; $label='Behind'; }
                else                 { $badge='b-warn'; $label='In progress'; }
              }
            ?>
              <tr
                class="row-clickable"
                data-member-id="<?= $tm_id ?>"
                data-total="<?= $total ?>"
                data-completed="<?= $completed ?>"
                data-active="<?= $active ?>"
                data-overdue="<?= $overdue ?>"
                title="Click to view <?= esc($name) ?>'s details"
              >
                <td class="col-check" onclick="event.stopPropagation()">
                  <input type="checkbox" class="row-check" value="<?= $tm_id ?>">
                </td>
                <td><strong><?= esc($name) ?></strong></td>
                <td><?= esc($email) ?></td>
                <td><?= esc($joined) ?></td>
                <td><strong><?= $completed ?></strong></td>
                <td><?= $total ?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <div class="progress">
                      <div class="bar" style="width:<?= $pct ?>%"></div>
                    </div>
                    <strong><?= $pct ?>%</strong>
                  </div>
                  <div style="color:var(--muted);font-size:.9rem;margin-top:6px">
                    Active: <?= $active ?><?= $overdue > 0 ? " · Overdue: {$overdue}" : "" ?>
                  </div>
                </td>
                <td><span class="badge <?= esc($badge) ?>"><?= esc($label) ?></span></td>
                <td onclick="event.stopPropagation()">
                  <button class="action-link" type="button" onclick="openMemberById(<?= $tm_id ?>)">
                    <i class="fa-solid fa-eye"></i> View details
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div id="pane-invite" style="display:none;">
    <div class="card" style="margin-top:12px">
      <form method="POST" action="<?= esc(basename(__FILE__)) ?>?tab=invite">
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
          <div>
            <label>Email</label>
            <input type="email" name="invite_email" required placeholder="name@company.com">
          </div>
          <div>
            <label>Role</label>
            <select name="invite_role" required>
              <option value="member">member</option>
              <option value="manager">manager</option>
              <option value="leader">leader</option>
            </select>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
          <button class="btn btn-primary" type="submit" name="invite_user" value="1">
            <i class="fa-solid fa-paper-plane"></i> Send invite
          </button>
        </div>
      </form>
    </div>
  </div>

  <div id="pane-invites" style="display:none;">
    <div class="card">
      <h3 style="margin:0 0 10px;color:var(--brand)">Invite status</h3>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Email</th>
              <th>Role</th>
              <th>Status</th>
              <th>Expires</th>
              <th>Created</th>
              <th>Invite link</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($invites)): ?>
              <tr>
                <td colspan="6" style="color:var(--muted)">No invites found yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($invites as $inv):
                $status = strtolower(trim((string)($inv['status'] ?? 'pending')));
                $badgeClass = 'b-warn';
                if ($status === 'accepted') $badgeClass = 'b-ok';
                elseif ($status === 'expired' || $status === 'cancelled') $badgeClass = 'b-bad';
                $inviteLink = $signupBaseUrl . '?token=' . urlencode($inv['token']);
              ?>
                <tr>
                  <td><?= esc($inv['email']) ?></td>
                  <td><?= esc($inv['role']) ?></td>
                  <td><span class="badge <?= esc($badgeClass) ?>"><?= esc(ucfirst($status)) ?></span></td>
                  <td><?= esc($inv['expires_at'] ?? '') ?></td>
                  <td><?= esc($inv['created_at'] ?? '') ?></td>
                  <td class="mono"><?= esc($inviteLink) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
const MEMBERS = <?= json_encode($memberJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let currentMember = null;

function badgeClass(status) {
  if (status === 'Completed' || status === 'On track') return 'b-ok';
  if (status === 'Overdue risk' || status === 'Behind') return 'b-bad';
  return 'b-warn';
}

function showPopup(title, message) {
  const overlay = document.getElementById('popupOverlay');
  const okBtn = document.getElementById('popupOkBtn');

  document.getElementById('popupTitle').textContent = title || 'Notice';
  document.getElementById('popupMessage').textContent = message || '';
  overlay.style.display = 'flex';

  okBtn.onclick = function () {
    overlay.style.display = 'none';
  };

  overlay.onclick = function (e) {
    if (e.target === overlay) {
      overlay.style.display = 'none';
    }
  };
}

function openTab(key) {
  ['members','invite','invites'].forEach(k => {
    const pane = document.getElementById('pane-' + k);
    const tab  = document.getElementById('tab-' + k);
    if (pane) pane.style.display = (k === key ? '' : 'none');
    if (tab)  tab.classList.toggle('active', k === key);
  });
}

function openMemberById(id) {
  const member = MEMBERS.find(m => parseInt(m.tm_id, 10) === parseInt(id, 10));
  if (member) openDetailPanel(member);
}

function renderRecentTasks(tasks) {
  const box = document.getElementById('dpTaskList');
  if (!tasks || !tasks.length) {
    box.innerHTML = '<div class="small-note">No task details available.</div>';
    return;
  }

  box.innerHTML = tasks.map(t => {
    const title = (t.task_title && String(t.task_title).trim() !== '') ? t.task_title : 'Untitled task';
    const status = t.task_status || 'Unknown';
    const due = t.due_date ? t.due_date : 'No due date';

    return `
      <div class="task-item">
        <div class="task-item-title">${escapeHtml(title)}</div>
        <div class="task-meta">
          <span><strong>Status:</strong> ${escapeHtml(status)}</span>
          <span><strong>Due:</strong> ${escapeHtml(due)}</span>
        </div>
      </div>
    `;
  }).join('');
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function openDetailPanel(member) {
  currentMember = member;

  const initials = (member.name || member.email || '?')
    .split(' ')
    .map(w => w[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

  document.getElementById('dpAvatar').textContent = initials || '?';
  document.getElementById('dpName').textContent = member.name || '(no name)';
  document.getElementById('dpEmail').textContent = member.email || '—';
  document.getElementById('dpJoined').textContent = member.date_joined || '—';
  document.getElementById('dpTotal').textContent = member.total;
  document.getElementById('dpCompleted').textContent = member.completed;
  document.getElementById('dpActive').textContent = member.active;
  document.getElementById('dpOverdue').textContent = member.overdue;
  document.getElementById('dpPct').textContent = member.pct + '%';
  document.getElementById('dpBar').style.width = member.pct + '%';

  const badge = document.getElementById('dpStatusBadge');
  badge.textContent = member.status;
  badge.className = 'badge ' + badgeClass(member.status);

  const overdueBox = document.getElementById('dpOverdueBox');
  overdueBox.style.borderColor = member.overdue > 0 ? '#fecaca' : '';
  overdueBox.style.background = member.overdue > 0 ? '#fff1f2' : '';

  renderRecentTasks(member.recent_tasks || []);

  const panel = document.getElementById('detailPanel');
  panel.style.right = '0';
  panel.classList.add('open');
}

function closeDetailPanel() {
  const panel = document.getElementById('detailPanel');

  if (panel) {
    panel.classList.remove('open');
    panel.style.right = '-520px';
  }

  currentMember = null;
}

function getCheckedIds() {
  return Array.from(document.querySelectorAll('.row-check:checked'))
    .map(cb => parseInt(cb.value, 10));
}

function getCheckedMembers() {
  const ids = getCheckedIds();
  return MEMBERS.filter(m => ids.includes(m.tm_id));
}

function updateBulkBar() {
  const ids = getCheckedIds();
  const bar = document.getElementById('bulkBar');
  const count = document.getElementById('bulkCount');

  if (bar && count) {
    if (ids.length > 0) {
      bar.classList.add('visible');
      count.textContent = ids.length + ' member' + (ids.length > 1 ? 's' : '') + ' selected';
    } else {
      bar.classList.remove('visible');
    }
  }

  const all = Array.from(document.querySelectorAll('.row-check')).filter(cb => {
    const row = cb.closest('tr');
    return row && !row.classList.contains('hidden-row');
  });

  const checked = all.filter(cb => cb.checked);
  const master = document.getElementById('masterCheck');

  if (master) {
    master.indeterminate = checked.length > 0 && checked.length < all.length;
    master.checked = all.length > 0 && checked.length === all.length;
  }
}

function exportMembersCSV(members) {
  const header = ['Name','Email','Joined','Total','Completed','Active','Overdue','Progress%','Status'];
  const rows = members.map(m => [
    '"' + (m.name || '').replace(/"/g,'""') + '"',
    '"' + (m.email || '').replace(/"/g,'""') + '"',
    '"' + (m.date_joined || '').replace(/"/g,'""') + '"',
    m.total, m.completed, m.active, m.overdue, m.pct,
    '"' + (m.status || '') + '"'
  ].join(','));

  const csv = [header.join(','), ...rows].join('\r\n');
  const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = 'team_progress_' + new Date().toISOString().slice(0,10) + '.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

document.addEventListener('DOMContentLoaded', () => {
  openTab(<?= json_encode($tab) ?>);

  <?php if (!empty($popupMessage)): ?>
    showPopup(<?= json_encode($popupTitle) ?>, <?= json_encode($popupMessage) ?>);
  <?php endif; ?>

  const detailCloseBtn = document.getElementById('detailCloseBtn');
  if (detailCloseBtn) {
    detailCloseBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      closeDetailPanel();
    });
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeDetailPanel();
      const popup = document.getElementById('popupOverlay');
      if (popup) popup.style.display = 'none';
    }
  });

  const master = document.getElementById('masterCheck');
  if (master) {
    master.addEventListener('change', () => {
      document.querySelectorAll('.row-check').forEach(cb => {
        const row = cb.closest('tr');
        if (row && !row.classList.contains('hidden-row')) {
          cb.checked = master.checked;
          row.classList.toggle('selected', master.checked);
        }
      });
      updateBulkBar();
    });
  }

  document.querySelectorAll('.row-check').forEach(cb => {
    cb.addEventListener('change', () => {
      const row = cb.closest('tr');
      if (row) row.classList.toggle('selected', cb.checked);
      updateBulkBar();
    });
  });

  document.querySelectorAll('tr[data-member-id]').forEach(tr => {
    tr.addEventListener('click', (e) => {
      if (e.target.closest('.col-check')) return;
      if (e.target.closest('.action-link')) return;
      const id = parseInt(tr.dataset.memberId, 10);
      openMemberById(id);
    });
  });

  const bulkEmailBtn = document.getElementById('bulkEmailBtn');
  if (bulkEmailBtn) {
    bulkEmailBtn.addEventListener('click', () => {
      const members = getCheckedMembers();
      if (!members.length) return;
      const emails = members.map(m => m.email).join(';');
      window.location.href = 'mailto:' + encodeURIComponent(emails);
    });
  }

  const bulkExportBtn = document.getElementById('bulkExportBtn');
  if (bulkExportBtn) {
    bulkExportBtn.addEventListener('click', () => {
      const members = getCheckedMembers();
      if (!members.length) return;
      exportMembersCSV(members);
    });
  }

  const bulkClearBtn = document.getElementById('bulkClearBtn');
  if (bulkClearBtn) {
    bulkClearBtn.addEventListener('click', () => {
      document.querySelectorAll('.row-check').forEach(cb => {
        cb.checked = false;
        const row = cb.closest('tr');
        if (row) row.classList.remove('selected');
      });
      if (master) {
        master.checked = false;
        master.indeterminate = false;
      }
      updateBulkBar();
    });
  }

  const bulkRemoveBtn = document.getElementById('bulkRemoveBtn');
  if (bulkRemoveBtn) {
    bulkRemoveBtn.addEventListener('click', () => {
      const members = getCheckedMembers();
      if (!members.length) return;

      const names = members.map(m => m.name || m.email).join(', ');
      if (!confirm('Remove ' + members.length + ' member(s) from your team?\n\n' + names + '\n\nThis cannot be undone.')) return;

      const ids = members.map(m => m.tm_id);
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '';

      const field = document.createElement('input');
      field.type = 'hidden';
      field.name = 'remove_members';
      field.value = ids.join(',');

      form.appendChild(field);
      document.body.appendChild(form);
      form.submit();
    });
  }

  updateBulkBar();
});
</script>
</body>
</html>