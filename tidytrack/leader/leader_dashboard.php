<?php
// leader_dashboard.php
// ✅ Overdue tab shows ONLY In Progress / Pending / blank
// ✅ Overdue KPI count matches the same rule
// ✅ Overdue actions guarded server-side
// ✅ Reassign sends email to old assignee + new assignee
// ✅ Extend due date sends email to assignee
// ✅ Uses correct endpoint for AJAX/fetch to avoid "page not found"

session_start();
include '../dp.php';
include '../track2.php';

if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
$teamLeaderId = $_SESSION['user_id'] ?? null;

$today = date('Y-m-d');
$isWeekend = date('N') >= 6;

$totalMinutesToday = 0;
$canSubmitReport = false;

if (!$isWeekend) {
    // update session minutes first
    update_current_session($teamLeaderId);

    // get today's minutes
    $totalMinutesToday = get_total_minutes_today($teamLeaderId);

    $nowHour = (int)date('G');
    $nowMin  = (int)date('i');

    // submission window (16:00 - 17:00)
    $inWindow = ($nowHour === 16) || ($nowHour === 17 && $nowMin === 0);

    $canSubmitReport = $inWindow && ($totalMinutesToday >= 240);
}

// calculate remaining minutes
$minsNeeded = max(240 - $totalMinutesToday, 0);
$hrsNeeded  = floor($minsNeeded / 60);
$minRem     = $minsNeeded % 60;

if (!$teamLeaderId) {
  header("Location: ../login.php");
  exit;
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function esc($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function uiStatus($status) {
  $s = strtolower(trim((string)$status));
  if ($s === 'archived' || $s === 'onhold') return 'Archived';
  if ($s === '' || $s === 'pending') return 'In progress';
  return (string)$status;
}

function isInProgressStatusLower(string $s): bool {
  $s = strtolower(trim($s));
  return in_array($s, ['in progress', 'pending', ''], true);
}

/* -------------------------------------------------------
   CHECK audit table exists
------------------------------------------------------- */
$actionsTableExists = false;
$tr = $conn->query("SHOW TABLES LIKE 'task_actions'");
if ($tr && $tr->num_rows > 0) $actionsTableExists = true;
if ($tr) $tr->free();

/* -------------------------------------------------------
   GET LEADER NAME
------------------------------------------------------- */
$stmtLeader = $conn->prepare("SELECT name FROM users WHERE user_id = ?");
$stmtLeader->bind_param("i", $teamLeaderId);
$stmtLeader->execute();
$leaderRow = $stmtLeader->get_result()->fetch_assoc();
$leaderName = $leaderRow['name'] ?? 'Leader';
$stmtLeader->close();

/* ✅ Correct endpoint for AJAX */
$ajaxEndpoint = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/leader_dashboard.php';

/* -------------------------------------------------------
   USERS + MANAGERS + MEMBERS
------------------------------------------------------- */

// For charts/assignee filters
$users = [];
$usersRes = $conn->query("SELECT name FROM users ORDER BY name ASC");
if ($usersRes) {
  while ($r = $usersRes->fetch_assoc()) $users[] = $r['name'];
  $usersRes->free();
}

// Managers
$managers = [];
$mgrRes = $conn->query("
  SELECT user_id, COALESCE(NULLIF(name,''), email) AS name, email
  FROM users
  WHERE LOWER(role)='manager' AND email IS NOT NULL AND email <> ''
  ORDER BY name ASC
");
if ($mgrRes) {
  while ($r = $mgrRes->fetch_assoc()) {
    $managers[] = [
      "user_id" => (int)$r["user_id"],
      "name"    => (string)$r["name"],
      "email"   => (string)$r["email"],
    ];
  }
  $mgrRes->free();
}

// Members
$members = [];
$memRes = $conn->query("
  SELECT user_id, COALESCE(NULLIF(name,''), email) AS name, email
  FROM users
  WHERE LOWER(role) NOT IN ('manager','admin')
  ORDER BY name ASC
");
if ($memRes) {
  while ($r = $memRes->fetch_assoc()) {
    $members[] = [
      "user_id" => (int)$r["user_id"],
      "name"    => (string)$r["name"],
      "email"   => (string)($r["email"] ?? ""),
    ];
  }
  $memRes->free();
}

/* -------------------------------------------------------
   PROJECTS
------------------------------------------------------- */
$projects = [];
$stmtProj = $conn->prepare("
  SELECT DISTINCT
    COALESCE(t.project_id, sp.project_id) AS pid,
    COALESCE(p.project_name, p2.project_name) AS pname
  FROM tasks t
  LEFT JOIN sub_projects sp ON t.sub_project_id = sp.sub_project_id
  LEFT JOIN projects_x p ON t.project_id = p.project_id
  LEFT JOIN projects_x p2 ON sp.project_id = p2.project_id
  WHERE t.created_by = ?
    AND COALESCE(p.project_name, p2.project_name) IS NOT NULL
  ORDER BY pname ASC
");
$stmtProj->bind_param("i", $teamLeaderId);
$stmtProj->execute();
$resP = $stmtProj->get_result();
while ($row = $resP->fetch_assoc()) {
  $pid = (int)($row['pid'] ?? 0);
  $pname = (string)($row['pname'] ?? '');
  if ($pid && $pname !== '') $projects[] = ['pid' => $pid, 'pname' => $pname];
}
$stmtProj->close();

/* =======================================================
   POST: TASK ACTIONS
======================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_task'])) {
  header("Content-Type: application/json; charset=utf-8");

  $taskId  = (int)($_POST['task_id'] ?? 0);
  $action  = trim($_POST['action'] ?? '');
  $comment = trim($_POST['comment'] ?? '');

  if (!$taskId || $action === '') {
    echo json_encode(["ok" => false, "message" => "Missing task_id or action"]);
    exit;
  }

  $chk = $conn->prepare("
    SELECT task_id, taskTitle, assignTo, dueDate, status
    FROM tasks
    WHERE task_id=? AND created_by=?
    LIMIT 1
  ");
  $chk->bind_param("ii", $taskId, $teamLeaderId);
  $chk->execute();
  $task = $chk->get_result()->fetch_assoc();
  $chk->close();

  if (!$task) {
    echo json_encode(["ok" => false, "message" => "Task not found or not allowed"]);
    exit;
  }

  $oldAssign = trim((string)($task['assignTo'] ?? ''));
  $oldDue    = (string)($task['dueDate'] ?? '');
  $oldStatus = strtolower(trim((string)($task['status'] ?? '')));
  $taskTitle = (string)($task['taskTitle'] ?? '');

  $isOverdue = false;
  if (!empty($oldDue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $oldDue)) {
    $isOverdue = (
      isInProgressStatusLower($oldStatus) &&
      $oldDue < date('Y-m-d')
    );
  }

  if (!$isOverdue) {
    echo json_encode(["ok" => false, "message" => "Actions are only allowed for overdue tasks with status In Progress."]);
    exit;
  }

  $logAction = function($type, $oldVal, $newVal, $cmt = '') use ($conn, $taskId, $teamLeaderId, $actionsTableExists) {
    if (!$actionsTableExists) return;
    $ins = $conn->prepare("
      INSERT INTO task_actions (task_id, actor_id, action_type, old_value, new_value, comment)
      VALUES (?,?,?,?,?,?)
    ");
    $ins->bind_param("iissss", $taskId, $teamLeaderId, $type, $oldVal, $newVal, $cmt);
    $ins->execute();
    $ins->close();
  };

  $sendMail = function($to, $subject, $body) {
    if (!$to) return false;

    $headers  = "From: noreply@investhoodprojects.com\r\n";
    $headers .= "Reply-To: support@investhoodprojects.com\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($to, $subject, $body, $headers);
  };

  $getUserByName = function($name) use ($conn) {
    $name = trim((string)$name);
    if ($name === '') return null;

    $stmt = $conn->prepare("
      SELECT user_id, name, email
      FROM users
      WHERE TRIM(name) = ?
      LIMIT 1
    ");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
  };

  $getUserById = function($userId) use ($conn) {
    $userId = (int)$userId;
    if ($userId <= 0) return null;

    $stmt = $conn->prepare("
      SELECT user_id, name, email
      FROM users
      WHERE user_id = ?
      LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
  };

  if ($action === 'reassign') {
    $newAssign       = trim($_POST['new_assignTo'] ?? '');
    $newAssignUserId = (int)($_POST['new_assign_user_id'] ?? 0);

    if ($newAssign === '') {
      echo json_encode(["ok" => false, "message" => "Missing new assignee"]);
      exit;
    }

    $up = $conn->prepare("UPDATE tasks SET assignTo=? WHERE task_id=? AND created_by=? LIMIT 1");
    $up->bind_param("sii", $newAssign, $taskId, $teamLeaderId);
    $ok = $up->execute();
    $up->close();

    if (!$ok) {
      echo json_encode(["ok" => false, "message" => "Failed to reassign"]);
      exit;
    }

    $logAction("reassign", $oldAssign, $newAssign, $comment);

    $oldUser = $getUserByName($oldAssign);
    $newUser = $newAssignUserId > 0 ? $getUserById($newAssignUserId) : $getUserByName($newAssign);

    $mailResults = [];

    if ($oldUser && !empty($oldUser['email']) && strcasecmp($oldAssign, $newAssign) !== 0) {
      $subjectOld = "Task Reassigned: {$taskTitle}";
      $bodyOld = "Hi {$oldUser['name']},\n\n"
               . "Please note that the following task has been reassigned to another team member.\n\n"
               . "Task: {$taskTitle}\n"
               . "Previous Assignee: {$oldAssign}\n"
               . "New Assignee: {$newAssign}\n"
               . "Previous Due Date: {$oldDue}\n"
               . "Status: " . uiStatus($oldStatus) . "\n\n"
               . ($comment !== '' ? "Leader Note: {$comment}\n\n" : "")
               . "Regards,\n{$leaderName}";
      $mailResults[] = $sendMail($oldUser['email'], $subjectOld, $bodyOld);
    }

    if ($newUser && !empty($newUser['email'])) {
      $subjectNew = "New Task Assigned: {$taskTitle}";
      $bodyNew = "Hi {$newUser['name']},\n\n"
               . "A task has been assigned to you.\n\n"
               . "Task: {$taskTitle}\n"
               . "Assigned By: {$leaderName}\n"
               . "Due Date: {$oldDue}\n"
               . "Status: " . uiStatus($oldStatus) . "\n\n"
               . ($comment !== '' ? "Leader Note: {$comment}\n\n" : "")
               . "Please log in and review the task.\n\n"
               . "Regards,\n{$leaderName}";
      $mailResults[] = $sendMail($newUser['email'], $subjectNew, $bodyNew);
    }

    $allMailOk = !in_array(false, $mailResults, true);

    echo json_encode([
      "ok" => true,
      "message" => $allMailOk
        ? "Task reassigned and email notifications sent."
        : "Task reassigned, but one or more email notifications failed."
    ]);
    exit;
  }

  if ($action === 'extend_due') {
    $newDue = trim($_POST['new_dueDate'] ?? '');
    if ($newDue === '') {
      echo json_encode(["ok" => false, "message" => "Missing new due date"]);
      exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDue)) {
      echo json_encode(["ok" => false, "message" => "Invalid date format (use YYYY-MM-DD)"]);
      exit;
    }

    $up = $conn->prepare("UPDATE tasks SET dueDate=? WHERE task_id=? AND created_by=? LIMIT 1");
    $up->bind_param("sii", $newDue, $taskId, $teamLeaderId);
    $ok = $up->execute();
    $up->close();

    if (!$ok) {
      echo json_encode(["ok" => false, "message" => "Failed to extend due date"]);
      exit;
    }

    $logAction("extend_due", $oldDue, $newDue, $comment);

    $assigneeUser = $getUserByName($oldAssign);
    $mailOk = true;

    if ($assigneeUser && !empty($assigneeUser['email'])) {
      $subject = "Due Date Extended: {$taskTitle}";
      $body = "Hi {$assigneeUser['name']},\n\n"
            . "The due date for your task has been extended.\n\n"
            . "Task: {$taskTitle}\n"
            . "Assigned To: {$oldAssign}\n"
            . "Old Due Date: {$oldDue}\n"
            . "New Due Date: {$newDue}\n"
            . "Status: " . uiStatus($oldStatus) . "\n\n"
            . ($comment !== '' ? "Leader Note: {$comment}\n\n" : "")
            . "Please log in and continue with the task.\n\n"
            . "Regards,\n{$leaderName}";
      $mailOk = $sendMail($assigneeUser['email'], $subject, $body);
    }

    echo json_encode([
      "ok" => true,
      "message" => $mailOk
        ? "Due date extended and email notification sent."
        : "Due date extended, but the email notification failed."
    ]);
    exit;
  }

  if ($action === 'reminder') {
    $email = '';
    if ($oldAssign !== '') {
      $u = $conn->prepare("SELECT email FROM users WHERE name=? LIMIT 1");
      $u->bind_param("s", $oldAssign);
      $u->execute();
      $email = (string)(($u->get_result()->fetch_assoc()['email'] ?? ''));
      $u->close();
    }

    if ($email === '') {
      echo json_encode(["ok" => false, "message" => "Assignee email not found"]);
      exit;
    }

    $subject = "Task Reminder: {$taskTitle}";
    $body = "Hi {$oldAssign},\n\n"
          . "Reminder: The task below is OVERDUE.\n\n"
          . "Task: {$taskTitle}\n"
          . "Due Date: {$oldDue}\n"
          . "Status: " . uiStatus($oldStatus) . "\n\n"
          . ($comment ? "Leader Note: {$comment}\n\n" : "")
          . "Please update progress as soon as possible.\n\n"
          . "Regards,\n{$leaderName}";

    $mailOk = $sendMail($email, $subject, $body);
    $logAction("reminder", "", $email, $comment);

    echo json_encode([
      "ok" => true,
      "message" => $mailOk ? "Reminder sent" : "Reminder action saved, but email failed to send"
    ]);
    exit;
  }

  if ($action === 'escalate') {
    $managerEmail = trim($_POST['manager_email'] ?? '');
    if ($managerEmail === '') {
      echo json_encode(["ok" => false, "message" => "Please select a manager to escalate to."]);
      exit;
    }

    $subject = "Escalation: Overdue Task - {$taskTitle}";
    $body = "Manager,\n\n"
          . "The following task has been escalated by a Team Leader.\n\n"
          . "Task: {$taskTitle}\n"
          . "Assigned To: {$oldAssign}\n"
          . "Due Date: {$oldDue}\n"
          . "Status: " . uiStatus($oldStatus) . "\n\n"
          . ($comment ? "Leader Reason/Note: {$comment}\n\n" : "")
          . "Please review.\n";

    $mailOk = $sendMail($managerEmail, $subject, $body);

    $logAction("escalate", "", $managerEmail, $comment);
    echo json_encode([
      "ok" => true,
      "message" => $mailOk ? "Escalated to selected Manager" : "Escalation saved, but email failed to send"
    ]);
    exit;
  }

  echo json_encode(["ok" => false, "message" => "Unknown action"]);
  exit;
}

/* =======================================================
   AJAX: FETCH TASKS
======================================================= */
if (
  isset($_GET['status']) || isset($_GET['filter']) || isset($_GET['assignee']) ||
  isset($_GET['project_id']) || isset($_GET['from']) || isset($_GET['to']) ||
  isset($_GET['q'])
) {
  $where = " WHERE t.created_by = ? ";
  $types = "i";
  $params = [$teamLeaderId];

  if (isset($_GET['status']) && $_GET['status'] !== '') {
    $status = strtolower(trim($_GET['status']));
    if ($status === 'archived') {
      $where .= " AND LOWER(TRIM(COALESCE(t.status,''))) IN ('archived','onhold') ";
    } else {
      $where .= " AND LOWER(TRIM(COALESCE(t.status,''))) = ? ";
      $types .= "s";
      $params[] = $status;
    }
  }

  if (isset($_GET['assignee']) && $_GET['assignee'] !== '') {
    $where .= " AND t.assignTo = ? ";
    $types .= "s";
    $params[] = $_GET['assignee'];
  }

  if (isset($_GET['project_id']) && $_GET['project_id'] !== '') {
    $pid = (int)$_GET['project_id'];
    if ($pid > 0) {
      $where .= " AND COALESCE(t.project_id, sp.project_id) = ? ";
      $types .= "i";
      $params[] = $pid;
    }
  }

  if (!empty($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) {
    $where .= " AND DATE(t.created_at) >= ? ";
    $types .= "s";
    $params[] = $_GET['from'];
  }

  if (!empty($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])) {
    $where .= " AND DATE(t.created_at) <= ? ";
    $types .= "s";
    $params[] = $_GET['to'];
  }

  if (isset($_GET['filter']) && $_GET['filter'] !== '') {
    $filter = $_GET['filter'];

    if ($filter === 'overdue') {
      $where .= " AND LOWER(TRIM(COALESCE(t.status,''))) IN ('in progress','pending','')
                  AND t.dueDate IS NOT NULL AND t.dueDate < CURDATE() ";
    } elseif ($filter === 'due_7') {
      $where .= " AND LOWER(TRIM(COALESCE(t.status,''))) NOT IN ('completed','archived','onhold')
                  AND t.dueDate IS NOT NULL
                  AND t.dueDate >= CURDATE() AND t.dueDate <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ";
    } elseif ($filter === 'recent_30') {
      $where .= " AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ";
    }
  }

  if (isset($_GET['q']) && trim($_GET['q']) !== '') {
    $q = trim($_GET['q']);
    $where .= " AND (
      t.taskTitle LIKE CONCAT('%',?,'%')
      OR COALESCE(t.assignTo,'') LIKE CONCAT('%',?,'%')
      OR LOWER(TRIM(COALESCE(t.status,''))) LIKE CONCAT('%',LOWER(?),'%')
      OR COALESCE(p.project_name, p2.project_name, '') LIKE CONCAT('%',?,'%')
      OR COALESCE(sp.sub_project_name,'') LIKE CONCAT('%',?,'%')
    ) ";
    $types .= "sssss";
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
  }

  $sql = "
    SELECT
      t.task_id,
      t.taskTitle,
      t.assignTo,
      t.created_at,
      t.dueDate,
      t.status,
      COALESCE(p.project_name, p2.project_name) AS main_project_name,
      sp.sub_project_name
    FROM tasks t
    LEFT JOIN sub_projects sp ON t.sub_project_id = sp.sub_project_id
    LEFT JOIN projects_x p ON t.project_id = p.project_id
    LEFT JOIN projects_x p2 ON sp.project_id = p2.project_id
    $where
    ORDER BY t.created_at DESC
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result();

  $tasks = [];
  while ($row = $result->fetch_assoc()) {
    $tasks[] = [
      "task_id"          => $row["task_id"],
      "project_name"     => $row["main_project_name"] ?? "No Project",
      "sub_project_name" => $row["sub_project_name"] ?? null,
      "taskTitle"        => $row["taskTitle"],
      "assignTo"         => $row["assignTo"],
      "startDate"        => $row["created_at"] ? date("M d, Y", strtotime($row["created_at"])) : "",
      "startDateISO"     => $row["created_at"] ? date("Y-m-d", strtotime($row["created_at"])) : "",
      "dueDate"          => $row["dueDate"] ? date("M d, Y", strtotime($row["dueDate"])) : "",
      "dueDateISO"       => $row["dueDate"] ? date("Y-m-d", strtotime($row["dueDate"])) : "",
      "status"           => uiStatus($row["status"] ?? "")
    ];
  }

  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($tasks);
  exit;
}

/* =======================================================
   COUNTERS / KPI
======================================================= */
function getCount($conn, $sql, $types, ...$params) {
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['total'] ?? 0);
}

$inprogressCount = getCount(
  $conn,
  "SELECT COUNT(*) total FROM tasks
   WHERE created_by=? AND LOWER(TRIM(COALESCE(status,''))) IN ('in progress','pending','')",
  "i", $teamLeaderId
);

$inreviewCount = getCount(
  $conn,
  "SELECT COUNT(*) total FROM tasks
   WHERE created_by=? AND LOWER(TRIM(COALESCE(status,'')))='in review'",
  "i", $teamLeaderId
);

$archivedCount = getCount(
  $conn,
  "SELECT COUNT(*) total FROM tasks
   WHERE created_by=? AND LOWER(TRIM(COALESCE(status,''))) IN ('archived','onhold')",
  "i", $teamLeaderId
);

$completedCount = getCount(
  $conn,
  "SELECT COUNT(*) total FROM tasks
   WHERE created_by=? AND LOWER(TRIM(COALESCE(status,'')))='completed'",
  "i", $teamLeaderId
);

$overdueCount = getCount(
  $conn,
  "SELECT COUNT(*) total FROM tasks
   WHERE created_by=?
     AND LOWER(TRIM(COALESCE(status,''))) IN ('in progress','pending','')
     AND dueDate IS NOT NULL AND dueDate < CURDATE()",
  "i",
  $teamLeaderId
);

$due7Count = getCount(
  $conn,
  "SELECT COUNT(*) total FROM tasks
   WHERE created_by=?
     AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('completed','archived','onhold')
     AND dueDate IS NOT NULL
     AND dueDate >= CURDATE() AND dueDate <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
  "i",
  $teamLeaderId
);

$last30Total = getCount(
  $conn,
  "SELECT COUNT(*) total FROM tasks
   WHERE created_by=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
  "i",
  $teamLeaderId
);

$last30Completed = getCount(
  $conn,
  "SELECT COUNT(*) total FROM tasks
   WHERE created_by=? AND LOWER(TRIM(COALESCE(status,'')))='completed'
     AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
  "i",
  $teamLeaderId
);

$completionRate = ($last30Total > 0) ? round(($last30Completed / $last30Total) * 100) : 0;

/* =======================================================
   CHART DATA
======================================================= */
$statusDist = [
  "In progress" => $inprogressCount,
  "In review"   => $inreviewCount,
  "Archived"    => $archivedCount,
  "Completed"   => $completedCount
];

$assigneeLabels = [];
$assigneeCounts = [];

$stmtAssignee = $conn->prepare("
  SELECT COALESCE(assignTo,'Unassigned') AS who, COUNT(*) AS total
  FROM tasks
  WHERE created_by = ?
  GROUP BY who
  ORDER BY total DESC
  LIMIT 8
");
$stmtAssignee->bind_param("i", $teamLeaderId);
$stmtAssignee->execute();
$resA = $stmtAssignee->get_result();
while ($r = $resA->fetch_assoc()) {
  $assigneeLabels[] = $r['who'];
  $assigneeCounts[] = (int)$r['total'];
}
$stmtAssignee->close();

$days = [];
$createdCounts = [];
for ($i = 13; $i >= 0; $i--) {
  $d = date("Y-m-d", strtotime("-$i days"));
  $days[$d] = 0;
}

$stmtTrend = $conn->prepare("
  SELECT DATE(created_at) AS d, COUNT(*) AS total
  FROM tasks
  WHERE created_by = ?
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
  GROUP BY DATE(created_at)
");
$stmtTrend->bind_param("i", $teamLeaderId);
$stmtTrend->execute();
$resT = $stmtTrend->get_result();
while ($r = $resT->fetch_assoc()) {
  $key = $r['d'];
  if (isset($days[$key])) $days[$key] = (int)$r['total'];
}
$stmtTrend->close();

foreach ($days as $k => $v) $createdCounts[] = $v;

function topTasksByStatus($conn, $leaderId, $statusLower) {
  if ($statusLower === 'archived') {
    $stmt = $conn->prepare("
      SELECT task_id, taskTitle, assignTo, dueDate, status
      FROM tasks
      WHERE created_by = ?
        AND LOWER(TRIM(COALESCE(status,''))) IN ('archived','onhold')
      ORDER BY created_at DESC
      LIMIT 3
    ");
    $stmt->bind_param("i", $leaderId);
  } else {
    $stmt = $conn->prepare("
      SELECT task_id, taskTitle, assignTo, dueDate, status
      FROM tasks
      WHERE created_by = ?
        AND LOWER(TRIM(COALESCE(status,''))) = ?
      ORDER BY created_at DESC
      LIMIT 3
    ");
    $stmt->bind_param("is", $leaderId, $statusLower);
  }

  $stmt->execute();
  $out = [];
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $r['status'] = uiStatus($r['status'] ?? '');
    $out[] = $r;
  }
  $stmt->close();
  return $out;
}

$kanban = [
  "In progress" => topTasksByStatus($conn, $teamLeaderId, "in progress"),
  "In review"   => topTasksByStatus($conn, $teamLeaderId, "in review"),
  "Archived"    => topTasksByStatus($conn, $teamLeaderId, "archived"),
  "Completed"   => topTasksByStatus($conn, $teamLeaderId, "completed"),
];

/* =======================================================
   RECENT TASK FEED
======================================================= */
$stmtRecent = $conn->prepare("
  SELECT
    t.task_id,
    t.taskTitle,
    t.assignTo,
    t.created_at,
    t.dueDate,
    t.status,
    COALESCE(p.project_name, p2.project_name) AS main_project_name,
    sp.sub_project_name
  FROM tasks t
  LEFT JOIN sub_projects sp ON t.sub_project_id = sp.sub_project_id
  LEFT JOIN projects_x p ON t.project_id = p.project_id
  LEFT JOIN projects_x p2 ON sp.project_id = p2.project_id
  WHERE t.created_by = ?
    AND LOWER(TRIM(COALESCE(t.status,''))) NOT IN ('archived','onhold')
  ORDER BY t.created_at DESC
  LIMIT 20
");
$stmtRecent->bind_param("i", $teamLeaderId);
$stmtRecent->execute();
$recentRes = $stmtRecent->get_result();
?>

<style>
  .dash-wrap { background: radial-gradient(1200px 500px at 10% 0%, rgba(59,130,246,.18), transparent 55%),
                         radial-gradient(900px 450px at 90% 10%, rgba(16,185,129,.16), transparent 60%),
                         radial-gradient(700px 400px at 60% 90%, rgba(245,158,11,.14), transparent 55%); }
  .dash-dark .dash-wrap { background: radial-gradient(1200px 500px at 10% 0%, rgba(59,130,246,.14), transparent 55%),
                                     radial-gradient(900px 450px at 90% 10%, rgba(16,185,129,.12), transparent 60%),
                                     radial-gradient(700px 400px at 60% 90%, rgba(245,158,11,.10), transparent 55%); }
  .glass { background: rgba(255,255,255,.75); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,.55); }
  .dash-dark .glass { background: rgba(17,24,39,.65); border-color: rgba(255,255,255,.10); }
  .dash-dark .txt { color: #e5e7eb; }
  .dash-dark .muted { color: #9ca3af; }
  .dash-dark .table-row { border-color: rgba(255,255,255,.10) !important; }

  .dash-dark .text-slate-600 { color:#cbd5e1 !important; }
  .dash-dark .text-slate-500 { color:#9ca3af !important; }
  .dash-dark .text-slate-900 { color:#e5e7eb !important; }
  .dash-dark .border-b { border-color: rgba(255,255,255,.10) !important; }

  .dash-dark .bg-blue-100  { background: rgba(37,99,235,.20) !important; }
  .dash-dark .text-blue-800{ color: #bfdbfe !important; }
  .dash-dark .bg-amber-100 { background: rgba(245,158,11,.20) !important; }
  .dash-dark .text-amber-800{ color:#fde68a !important; }
  .dash-dark .bg-green-100 { background: rgba(16,185,129,.20) !important; }
  .dash-dark .text-green-800{ color:#bbf7d0 !important; }
  .dash-dark .bg-slate-100 { background: rgba(148,163,184,.16) !important; }
  .dash-dark .text-slate-700{ color:#e2e8f0 !important; }

  .dash-dark input, .dash-dark textarea, .dash-dark select{
    background: rgba(15,23,42,.45) !important;
    border-color: rgba(255,255,255,.14) !important;
    color:#e5e7eb !important;
  }
  .dash-dark input::placeholder, .dash-dark textarea::placeholder{ color: rgba(226,232,240,.6) !important; }

  .tt-tip { cursor: pointer; opacity:.75; display:inline-flex; align-items:center; justify-content:center; }
  .tt-tip:hover { opacity:1; }

  .tt-bubble {
    position: fixed;
    z-index: 99999;
    max-width: 320px;
    padding: 10px 12px;
    border-radius: 12px;
    font-size: 12px;
    line-height: 1.35;
    background: rgba(15,23,42,.95);
    color: #fff;
    box-shadow: 0 10px 30px rgba(0,0,0,.25);
    display: none;
    pointer-events: none;
  }

  .filter-pill { border:1px solid rgba(148,163,184,.6); background:rgba(255,255,255,.75); }
  .dash-dark .filter-pill { background:rgba(17,24,39,.55); border-color: rgba(255,255,255,.14); color:#e5e7eb; }
</style>

<div id="leaderDashRoot" class="dash-wrap p-4 sm:p-6 rounded-2xl">

  <div class="glass rounded-2xl shadow-sm p-4 mb-6 flex flex-col gap-3">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="text-2xl font-bold txt text-slate-900">Welcome, <?= esc($leaderName) ?> 👋</div>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <div class="relative">
          <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400"></i>
          <input id="taskSearch" class="pl-9 pr-3 py-2 rounded-xl border border-slate-200 bg-white/80 outline-none"
                 placeholder="Search tasks…" />
        </div>

        <button id="btnRecent30" class="px-3 py-2 rounded-xl border border-slate-200 bg-white/80 hover:bg-white">
          <i class="fa-regular fa-calendar-days mr-1"></i> Last 30 days
        </button>

        <a href="#" data-page="leader_create-task.php"
           class="px-3 py-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700"
           onclick="document.querySelector('#leader-sidenav a[data-page=&quot;leader_create-task.php&quot;]')?.click(); return false;">
          <i class="fa-solid fa-plus mr-1"></i> New Task
        </a>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-4 mb-6">
    <?php
      $cards = [
        ["title"=>"In Progress", "value"=>$inprogressCount, "hint"=>"Work currently active", "icon"=>"fa-spinner", "action"=>["type"=>"status","val"=>"In progress"]],
        ["title"=>"In Review",   "value"=>$inreviewCount,   "hint"=>"Awaiting approval/review", "icon"=>"fa-circle-check", "action"=>["type"=>"status","val"=>"In review"]],
        ["title"=>"Archived",    "value"=>$archivedCount,   "hint"=>"Archived tasks", "icon"=>"fa-box-archive", "action"=>["type"=>"status","val"=>"Archived"]],
        ["title"=>"Completed",   "value"=>$completedCount,  "hint"=>"Done & logged", "icon"=>"fa-check-double", "action"=>["type"=>"status","val"=>"Completed"]],
        ["title"=>"Overdue",     "value"=>$overdueCount,    "hint"=>"Action : reassign / extend / remind / escalate", "icon"=>"fa-triangle-exclamation", "action"=>["type"=>"filter","val"=>"overdue"]],
        ["title"=>"Due (7 days)","value"=>$due7Count,       "hint"=>"Upcoming deadlines", "icon"=>"fa-clock", "action"=>["type"=>"filter","val"=>"due_7"]],
      ];
      foreach ($cards as $c):
    ?>
      <button
        class="glass rounded-2xl shadow-sm p-4 text-left hover:shadow-md transition"
        data-action="<?= esc($c['action']['type']) ?>"
        data-value="<?= esc($c['action']['val']) ?>"
        title="<?= esc($c['hint']) ?>"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-xs font-bold muted text-slate-500"><?= esc($c['title']) ?></div>
            <div class="text-3xl font-extrabold txt text-slate-900"><?= (int)$c['value'] ?></div>
          </div>
          <div class="w-11 h-11 rounded-xl flex items-center justify-center bg-white/60 border border-white/60">
            <i class="fa-solid <?= esc($c['icon']) ?> text-slate-700"></i>
          </div>
        </div>

        <?php
          $max = max(1, $inprogressCount, $inreviewCount, $archivedCount, $completedCount, $overdueCount, $due7Count);
          $pct = min(100, round(($c['value'] / $max) * 100));
        ?>
        <div class="mt-3 h-2 rounded-full bg-slate-200/70 overflow-hidden">
          <div class="h-2 rounded-full bg-blue-600" style="width: <?= $pct ?>%"></div>
        </div>
        <div class="mt-2 text-xs muted text-slate-500"><?= esc($c['hint']) ?></div>
      </button>
    <?php endforeach; ?>
  
  <div class="glass rounded-2xl shadow-sm p-4">
  <div class="flex items-start justify-between">
    <div>
      <div class="text-xs font-bold text-slate-500">Daily Report Progress</div>

      <div class="text-2xl font-extrabold text-slate-900">
        <?php echo floor($totalMinutesToday / 60); ?>h <?php echo $totalMinutesToday % 60; ?>m
      </div>
    </div>

    <div class="w-10 h-10 flex items-center justify-center bg-white/60 rounded-xl">
      <i class="fa-solid fa-clock text-slate-700"></i>
    </div>
  </div>

  <div class="mt-3 text-xs text-slate-500">
    <?php if ($isWeekend): ?>
      Weekend (No submission required)
    <?php elseif ($canSubmitReport): ?>
      ✅ Ready to submit report
    <?php else: ?>
      ⏳ Need <?php echo $hrsNeeded; ?>h <?php echo $minRem; ?>m more
    <?php endif; ?>
  </div>

  <div class="mt-3 h-2 bg-slate-200 rounded-full overflow-hidden">
    <div class="h-2 bg-blue-600"
         style="width: <?php echo min(100, ($totalMinutesToday / 240) * 100); ?>%">
    </div>
  </div>
</div>
  
  
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
    <div class="glass rounded-2xl shadow-sm p-4 xl:col-span-1">
      <div class="flex items-center justify-between">
        <h3 class="font-bold txt text-slate-900">
          Status Distribution
          <span class="tt-tip ml-1 text-slate-400" data-tip="Shows how many tasks are in each status (tasks you created). Click a slice to drill down.">ℹ️</span>
        </h3>
        <span class="text-xs muted text-slate-500">Click a slice to filter</span>
      </div>
      <div class="mt-3">
        <canvas id="statusPie" height="170"></canvas>
      </div>
    </div>

    <div class="glass rounded-2xl shadow-sm p-4 xl:col-span-1">
      <div class="flex items-center justify-between">
        <h3 class="font-bold txt text-slate-900">
          Workload by Assignee
          <span class="tt-tip ml-1 text-slate-400" data-tip="Top 8 assignees by number of tasks. Click a bar to drill down.">ℹ️</span>
        </h3>
        <span class="text-xs muted text-slate-500">Click a bar to filter</span>
      </div>
      <div class="mt-3">
        <canvas id="assigneeBar" height="170"></canvas>
      </div>
    </div>

    <div class="glass rounded-2xl shadow-sm p-4 xl:col-span-1">
      <div class="flex items-center justify-between">
        <h3 class="font-bold txt text-slate-900">
          Last 14 Days
          <span class="tt-tip ml-1 text-slate-400" data-tip="How many tasks started each day (based on created_at).">ℹ️</span>
        </h3>
        <span class="text-xs muted text-slate-500">Tasks starting</span>
      </div>

      <div class="mt-3">
        <canvas id="createdLine" height="170"></canvas>
      </div>

      <div class="mt-4 flex items-center justify-between">
        <div>
          <div class="text-xs muted text-slate-500 font-bold">Completion rate (30d)</div>
          <div class="text-2xl font-extrabold txt text-slate-900"><?= (int)$completionRate ?>%</div>
        </div>
        <div class="w-24">
          <div class="h-2 rounded-full bg-slate-200/70 overflow-hidden">
            <div class="h-2 rounded-full bg-green-600" style="width: <?= (int)$completionRate ?>%"></div>
          </div>
          <div class="text-xs muted text-slate-500 mt-2"><?= (int)$last30Completed ?>/<?= (int)$last30Total ?> completed</div>
        </div>
      </div>
    </div>
  </div>

  <div class="glass rounded-2xl shadow-sm p-4 mb-6">
    <div class="flex items-center justify-content-between">
      <h3 class="font-bold txt text-slate-900">
        Kanban Snapshot
        <span class="tt-tip ml-1 text-slate-400" data-tip="Top 3 newest tasks in each lane. Click View to drill down.">ℹ️</span>
      </h3>
      <span class="text-xs muted text-slate-500">Top 3 tasks per lane</span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mt-4">
      <?php
        $laneMeta = [
          "In progress" => ["badge"=>"bg-blue-100 text-blue-800",  "dot"=>"bg-blue-600"],
          "In review"   => ["badge"=>"bg-amber-100 text-amber-800","dot"=>"bg-amber-600"],
          "Archived"    => ["badge"=>"bg-slate-100 text-slate-700","dot"=>"bg-slate-600"],
          "Completed"   => ["badge"=>"bg-green-100 text-green-800","dot"=>"bg-green-600"],
        ];

        foreach ($kanban as $lane => $items):
          $meta = $laneMeta[$lane];
      ?>
      <div class="rounded-2xl border border-white/50 bg-white/45 p-3">
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full <?= $meta['dot'] ?>"></span>
            <span class="font-bold txt text-slate-900"><?= esc($lane) ?></span>
          </div>
          <button class="text-xs px-2 py-1 rounded-lg <?= $meta['badge'] ?>"
                  data-action="status" data-value="<?= esc($lane) ?>">
            View
          </button>
        </div>

        <?php if (!count($items)): ?>
          <div class="text-sm muted text-slate-500">No tasks</div>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($items as $t): ?>
              <div class="rounded-xl bg-white/70 border border-white/70 p-2">
                <div class="text-sm font-semibold txt text-slate-900 truncate"><?= esc($t['taskTitle']) ?></div>
                <div class="text-xs muted text-slate-500 mt-1 flex items-center justify-between">
                  <span><i class="fa-regular fa-user mr-1"></i><?= esc($t['assignTo'] ?: 'Unassigned') ?></span>
                  <span><i class="fa-regular fa-clock mr-1"></i><?= $t['dueDate'] ? date("M d", strtotime($t['dueDate'])) : 'No due' ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div id="taskFeedCard" class="glass rounded-2xl shadow-sm p-4">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h3 class="font-bold txt text-slate-900">Task Feed</h3>
        <div id="tableSubtitle" class="text-xs muted text-slate-500">Latest 20 tasks you created (Archived hidden)</div>
      </div>

      <div class="flex items-center gap-2">
        <a href="#" data-page="leader_approve-tasks.php"
           class="px-3 py-2 rounded-xl border border-slate-200 bg-white/80 hover:bg-white"
           onclick="document.querySelector('#leader-sidenav a[data-page=&quot;leader_approve-tasks.php&quot;]')?.click(); return false;">
          <i class="fa-solid fa-check-circle mr-1"></i> Quick Approvals
        </a>
      </div>
    </div>

    <div class="overflow-x-auto mt-4">
      <table class="min-w-full text-left text-sm">
        <thead class="text-slate-600 border-b">
          <tr>
            <th class="py-2 pr-4">Project</th>
            <th class="py-2 pr-4">Task</th>
            <th class="py-2 pr-4">Assignee</th>
            <th class="py-2 pr-4">Start Date</th>
            <th class="py-2 pr-4">Due</th>
            <th class="py-2 pr-4">Status</th>
          </tr>
        </thead>

        <tbody id="tasksBody">
          <?php if ($recentRes && $recentRes->num_rows > 0): ?>
            <?php while($row = $recentRes->fetch_assoc()): ?>
              <?php
                $displayProject = $row['sub_project_name']
                  ? ($row['main_project_name'] . " (" . $row['sub_project_name'] . ")")
                  : ($row['main_project_name'] . " (No Sub Project)");

                $displayStatus = uiStatus($row['status'] ?? "");

                $badge = "bg-slate-100 text-slate-700";
                if ($displayStatus === "In progress") $badge = "bg-blue-100 text-blue-800";
                if ($displayStatus === "In review")   $badge = "bg-amber-100 text-amber-800";
                if ($displayStatus === "Archived")    $badge = "bg-slate-100 text-slate-700";
                if ($displayStatus === "Completed")   $badge = "bg-green-100 text-green-800";
              ?>
              <tr class="border-t table-row">
                <td class="py-2 pr-4"><?= esc($displayProject) ?></td>
                <td class="py-2 pr-4 font-semibold txt text-slate-900"><?= esc($row['taskTitle']) ?></td>
                <td class="py-2 pr-4"><?= esc($row['assignTo']) ?></td>
                <td class="py-2 pr-4"><?= $row['created_at'] ? date("M d, Y", strtotime($row['created_at'])) : "" ?></td>
                <td class="py-2 pr-4"><?= $row['dueDate'] ? date("M d, Y", strtotime($row['dueDate'])) : "" ?></td>
                <td class="py-2 pr-4">
                  <span class="px-2 py-1 rounded-lg text-xs font-bold <?= $badge ?>"><?= esc($displayStatus) ?></span>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="6" class="py-3 text-slate-500">No tasks found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<div id="ttBubble" class="tt-bubble"></div>

<div id="tasksModal" class="fixed inset-0 z-[9999] hidden">
  <div class="absolute inset-0 bg-black/50" data-modal-close></div>

  <div class="relative mx-auto mt-6 w-[95%] max-w-6xl glass rounded-2xl shadow-lg p-4 sm:p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="text-xs muted text-slate-500 font-semibold">Task Drill-down</div>
        <div class="text-xl font-extrabold txt text-slate-900" id="modalTitle">Filtered Tasks</div>
        <div class="text-xs muted text-slate-500" id="modalSubtitle">Showing results</div>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <div class="relative">
          <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400"></i>
          <input id="modalSearch" class="pl-9 pr-3 py-2 rounded-xl border border-slate-200 bg-white/80 outline-none"
                 placeholder="Search in results..." />
        </div>

        <button id="btnCloseModal" class="px-3 py-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700">
          <i class="fa-solid fa-xmark mr-1"></i> Close
        </button>
      </div>
    </div>

    <div class="overflow-x-auto mt-4 max-h-[70vh]">
      <table class="min-w-full text-left text-sm">
        <thead class="text-slate-600 border-b">
          <tr>
            <th class="py-2 pr-4">Project</th>
            <th class="py-2 pr-4">Task</th>
            <th class="py-2 pr-4">Assignee</th>
            <th class="py-2 pr-4">Start Date</th>
            <th class="py-2 pr-4">Due</th>
            <th class="py-2 pr-4">Status</th>
            <th class="py-2 pr-4" id="actionsTh">Actions</th>
          </tr>
        </thead>
        <tbody id="modalTasksBody"></tbody>
      </table>
    </div>
  </div>
</div>

<div id="actionPopup" class="fixed inset-0 z-[10001] hidden">
  <div class="absolute inset-0 bg-black/50" data-ap-close></div>

  <div class="relative mx-auto mt-10 w-[95%] max-w-xl glass rounded-2xl shadow-lg p-4 sm:p-5">
    <div class="flex items-center justify-between gap-3">
      <div>
        <div class="text-xs muted text-slate-500 font-semibold">Task Action</div>
        <div id="apTitle" class="text-xl font-extrabold txt text-slate-900">Action</div>
      </div>
      <button id="apClose" class="px-3 py-2 rounded-xl border border-slate-200 bg-white/80 hover:bg-white">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div id="apBody" class="mt-4"></div>

    <div class="mt-4 flex items-center justify-end gap-2">
      <button id="apCancel" class="px-4 py-2 rounded-xl border border-slate-200 bg-white/80 hover:bg-white">
        Cancel
      </button>
      <button id="apConfirm" class="px-4 py-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700">
        Confirm
      </button>
    </div>
  </div>
</div>

<script>
(function () {
  const DASHBOARD_ENDPOINT = <?= json_encode($ajaxEndpoint) ?>;

  const feedTbody = document.getElementById("tasksBody");
  const feedSubtitle = document.getElementById("tableSubtitle");
  const taskFeedCard = document.getElementById("taskFeedCard");

  const modal = document.getElementById("tasksModal");
  const modalTbody = document.getElementById("modalTasksBody");
  const modalSubtitle = document.getElementById("modalSubtitle");
  const modalTitle = document.getElementById("modalTitle");
  const modalSearch = document.getElementById("modalSearch");
  const actionsTh = document.getElementById("actionsTh");

  const projectFilter = document.getElementById("projectFilter");
  const fromDate = document.getElementById("fromDate");
  const toDate = document.getElementById("toDate");
  const btnApplyFilters = document.getElementById("btnApplyFilters");
  const btnClearFilters = document.getElementById("btnClearFilters");

  const MANAGERS = <?php echo json_encode($managers); ?>;
  const MEMBERS  = <?php echo json_encode($members); ?>;

  const initialFeedHTML = feedTbody ? feedTbody.innerHTML : "";
  const initialSubtitle = feedSubtitle ? feedSubtitle.textContent : "";

  const currentFilters = { project_id: "", from: "", to: "" };

  function applyFiltersFromUI() {
    currentFilters.project_id = projectFilter?.value || "";
    currentFilters.from = fromDate?.value || "";
    currentFilters.to = toDate?.value || "";
  }

  function clearFiltersUI() {
    if (projectFilter) projectFilter.value = "";
    if (fromDate) fromDate.value = "";
    if (toDate) toDate.value = "";
    applyFiltersFromUI();
  }

  btnApplyFilters?.addEventListener("click", () => {
    applyFiltersFromUI();
    alert("Filters applied. Now click a card or graph to drill down with filters.");
  });

  btnClearFilters?.addEventListener("click", () => {
    clearFiltersUI();
    alert("Filters cleared.");
  });

  function openModal(titleText, subtitleText) {
    modalTitle.textContent = titleText || "Filtered Tasks";
    modalSubtitle.textContent = subtitleText || "Showing results";
    modal.classList.remove("hidden");
    modalSearch.value = "";
    setTimeout(() => modalSearch.focus(), 0);
  }

  function closeModal() {
    modal.classList.add("hidden");
  }

  document.getElementById("btnCloseModal")?.addEventListener("click", closeModal);
  modal.querySelectorAll("[data-modal-close]").forEach(el => el.addEventListener("click", closeModal));
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !modal.classList.contains("hidden")) closeModal();
  });

  function badgeClass(status) {
    if (status === "In progress") return "bg-blue-100 text-blue-800";
    if (status === "In review")   return "bg-amber-100 text-amber-800";
    if (status === "Archived")    return "bg-slate-100 text-slate-700";
    if (status === "Completed")   return "bg-green-100 text-green-800";
    return "bg-slate-100 text-slate-700";
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function postAction(payload) {
    const fd = new FormData();
    fd.append("action_task", "1");
    Object.keys(payload).forEach(k => fd.append(k, payload[k]));
    return fetch(DASHBOARD_ENDPOINT, { method: "POST", body: fd }).then(r => r.json());
  }

  function renderRows(rows, targetTbody, actionsEnabled) {
    targetTbody.innerHTML = "";
    if (actionsTh) actionsTh.style.display = actionsEnabled ? "" : "none";

    if (!rows.length) {
      const colCount = actionsEnabled ? 7 : 6;
      targetTbody.innerHTML = `<tr><td colspan="${colCount}" class="py-3 text-slate-500">No tasks found.</td></tr>`;
      return;
    }

    rows.forEach(r => {
      const projectName = r.sub_project_name
        ? `${r.project_name} (${r.sub_project_name})`
        : `${r.project_name} (No Sub Project)`;

      const tr = document.createElement("tr");
      tr.className = "border-t table-row";

      tr.innerHTML = `
        <td class="py-2 pr-4">${escapeHtml(projectName)}</td>
        <td class="py-2 pr-4 font-semibold txt text-slate-900">${escapeHtml(r.taskTitle)}</td>
        <td class="py-2 pr-4">${escapeHtml(r.assignTo || "")}</td>
        <td class="py-2 pr-4">${escapeHtml(r.startDate || "")}</td>
        <td class="py-2 pr-4">${escapeHtml(r.dueDate || "")}</td>
        <td class="py-2 pr-4">
          <span class="px-2 py-1 rounded-lg text-xs font-bold ${badgeClass(r.status)}">
            ${escapeHtml(r.status || "")}
          </span>
        </td>
        ${
          actionsEnabled
            ? `<td class="py-2 pr-4 whitespace-nowrap">
                <button class="px-2 py-1 rounded-lg border text-xs hover:bg-white"
                        data-act="reminder" data-id="${r.task_id}">Remind</button>

                <button class="px-2 py-1 rounded-lg border text-xs hover:bg-white ml-1"
                        data-act="escalate" data-id="${r.task_id}">Escalate</button>

                <button class="px-2 py-1 rounded-lg border text-xs hover:bg-white ml-1"
                        data-act="reassign" data-id="${r.task_id}">Reassign</button>

                <button class="px-2 py-1 rounded-lg border text-xs hover:bg-white ml-1"
                        data-act="extend_due" data-id="${r.task_id}">Extend</button>
              </td>`
            : ``
        }
      `;

      targetTbody.appendChild(tr);
    });
  }

  let lastQuery = null;
  let actionsEnabledForThisView = false;

  function loadTasks(params, label, openInModal = true) {
    applyFiltersFromUI();

    const merged = Object.assign({}, params);
    if (currentFilters.project_id) merged.project_id = currentFilters.project_id;
    if (currentFilters.from) merged.from = currentFilters.from;
    if (currentFilters.to) merged.to = currentFilters.to;

    lastQuery = merged;
    actionsEnabledForThisView = (merged.filter === "overdue");

    const qs = new URLSearchParams(merged);
    fetch(DASHBOARD_ENDPOINT + "?" + qs.toString())
      .then(res => res.json())
      .then(rows => {
        if (openInModal) {
          renderRows(rows, modalTbody, actionsEnabledForThisView);
          openModal("Filtered Tasks", label || "Filtered results");
        } else {
          renderRows(rows, feedTbody, false);
          if (feedSubtitle) feedSubtitle.textContent = label || "Filtered results";
        }
      });
  }

  const ap = document.getElementById("actionPopup");
  const apTitle = document.getElementById("apTitle");
  const apBody = document.getElementById("apBody");
  const apClose = document.getElementById("apClose");
  const apCancel = document.getElementById("apCancel");
  const apConfirm = document.getElementById("apConfirm");
  let apOnConfirm = null;

  function openActionPopup(title, bodyHtml, onConfirm) {
    apTitle.textContent = title;
    apBody.innerHTML = bodyHtml;
    apOnConfirm = onConfirm;
    ap.classList.remove("hidden");
  }

  function closeActionPopup() {
    ap.classList.add("hidden");
    apBody.innerHTML = "";
    apOnConfirm = null;
  }

  apClose?.addEventListener("click", closeActionPopup);
  apCancel?.addEventListener("click", closeActionPopup);
  ap.querySelectorAll("[data-ap-close]").forEach(el => el.addEventListener("click", closeActionPopup));
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !ap.classList.contains("hidden")) closeActionPopup();
  });
  apConfirm?.addEventListener("click", () => apOnConfirm && apOnConfirm());

  function renderSelectableList(items, listId, showSub = false) {
    const rows = items.map((x, idx) => `
      <label class="flex items-center gap-3 p-2 rounded-xl hover:bg-white/60 border border-white/50">
        <input type="checkbox" class="apPick" data-idx="${idx}" />
        <div class="min-w-0">
          <div class="text-sm font-semibold txt text-slate-900 truncate">${escapeHtml(x.name || "")}</div>
          ${showSub ? `<div class="text-xs muted text-slate-500 truncate">${escapeHtml(x.email || "")}</div>` : ``}
        </div>
      </label>
    `).join("");

    return `
      <div class="space-y-3">
        <div class="relative">
          <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400"></i>
          <input id="apSearch_${listId}" class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 bg-white/80 outline-none"
                 placeholder="Search..." />
        </div>

        <div id="apList_${listId}" class="max-h-64 overflow-auto space-y-2 pr-1">
          ${rows || `<div class="text-sm muted text-slate-500">No items found.</div>`}
        </div>
      </div>
    `;
  }

  function wireSingleSelect(listRoot) {
    listRoot.querySelectorAll(".apPick").forEach(chk => {
      chk.addEventListener("change", () => {
        if (!chk.checked) return;
        listRoot.querySelectorAll(".apPick").forEach(other => {
          if (other !== chk) other.checked = false;
        });
      });
    });
  }

  function wireSearch(listId) {
    const input = document.getElementById(`apSearch_${listId}`);
    const list = document.getElementById(`apList_${listId}`);
    if (!input || !list) return;

    input.addEventListener("input", () => {
      const q = input.value.toLowerCase().trim();
      list.querySelectorAll("label").forEach(lbl => {
        const txt = lbl.textContent.toLowerCase();
        lbl.style.display = txt.includes(q) ? "" : "none";
      });
    });
  }

  function getSelectedIndex(listRoot) {
    const picked = listRoot.querySelector(".apPick:checked");
    return picked ? parseInt(picked.getAttribute("data-idx"), 10) : -1;
  }

  modalTbody.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-act][data-id]");
    if (!btn) return;

    const action = btn.getAttribute("data-act");
    const taskId = btn.getAttribute("data-id");

    if (action === "reminder") {
      openActionPopup(
        "Send Reminder",
        `
          <div class="text-sm muted text-slate-600 mb-2">Send a reminder email to the assignee.</div>
          <label class="text-xs font-bold muted text-slate-500">Optional note</label>
          <textarea id="apNote" class="mt-1 w-full p-3 rounded-xl border border-slate-200 bg-white/80 outline-none"
                    rows="3" placeholder="Write a short note (optional)"></textarea>
        `,
        () => {
          const note = (document.getElementById("apNote")?.value || "").trim();
          postAction({ task_id: taskId, action: "reminder", comment: note })
            .then(resp => {
              alert(resp.message || (resp.ok ? "Done" : "Failed"));
              if (resp.ok && lastQuery) loadTasks(lastQuery, modalSubtitle.textContent, true);
              closeActionPopup();
            })
            .catch(() => alert("Action failed. Check server logs."));
        }
      );
      return;
    }

    if (action === "escalate") {
      openActionPopup(
        "Escalate to Manager",
        `
          <div class="text-sm muted text-slate-600 mb-3">Select the manager you want to escalate this task to.</div>
          ${renderSelectableList(MANAGERS, "mgr", true)}
          <div class="mt-3">
            <label class="text-xs font-bold muted text-slate-500">Optional note</label>
            <textarea id="apNote" class="mt-1 w-full p-3 rounded-xl border border-slate-200 bg-white/80 outline-none"
                      rows="3" placeholder="Reason / note (optional)"></textarea>
          </div>
        `,
        () => {
          const note = (document.getElementById("apNote")?.value || "").trim();
          const idx = getSelectedIndex(apBody);
          if (idx < 0) { alert("Please select a manager."); return; }
          const mgr = MANAGERS[idx];

          postAction({ task_id: taskId, action: "escalate", manager_email: mgr.email, comment: note })
            .then(resp => {
              alert(resp.message || (resp.ok ? "Done" : "Failed"));
              if (resp.ok && lastQuery) loadTasks(lastQuery, modalSubtitle.textContent, true);
              closeActionPopup();
            })
            .catch(() => alert("Action failed. Check server logs."));
        }
      );

      wireSingleSelect(apBody);
      wireSearch("mgr");
      return;
    }

    if (action === "reassign") {
      openActionPopup(
        "Reassign Task",
        `
          <div class="text-sm muted text-slate-600 mb-3">Select the member you want to assign this task to.</div>
          ${renderSelectableList(MEMBERS, "mem", false)}
          <div class="mt-3">
            <label class="text-xs font-bold muted text-slate-500">Optional note</label>
            <textarea id="apNote" class="mt-1 w-full p-3 rounded-xl border border-slate-200 bg-white/80 outline-none"
                      rows="3" placeholder="Reason / note (optional)"></textarea>
          </div>
        `,
        () => {
          const note = (document.getElementById("apNote")?.value || "").trim();
          const idx = getSelectedIndex(apBody);
          if (idx < 0) { alert("Please select a member."); return; }
          const mem = MEMBERS[idx];

          postAction({
            task_id: taskId,
            action: "reassign",
            new_assignTo: mem.name,
            new_assign_user_id: mem.user_id,
            comment: note
          })
            .then(resp => {
              alert(resp.message || (resp.ok ? "Done" : "Failed"));
              if (resp.ok && lastQuery) loadTasks(lastQuery, modalSubtitle.textContent, true);
              closeActionPopup();
            })
            .catch(() => alert("Action failed. Check server logs."));
        }
      );

      wireSingleSelect(apBody);
      wireSearch("mem");
      return;
    }

    if (action === "extend_due") {
      openActionPopup(
        "Extend Due Date",
        `
          <div class="text-sm muted text-slate-600 mb-3">Choose a new due date.</div>
          <label class="text-xs font-bold muted text-slate-500">New due date</label>
          <input id="apDue" type="date"
                 class="mt-1 w-full px-3 py-2 rounded-xl border border-slate-200 bg-white/80 outline-none" />
          <div class="mt-3">
            <label class="text-xs font-bold muted text-slate-500">Optional note</label>
            <textarea id="apNote" class="mt-1 w-full p-3 rounded-xl border border-slate-200 bg-white/80 outline-none"
                      rows="3" placeholder="Reason / note (optional)"></textarea>
          </div>
        `,
        () => {
          const newDue = (document.getElementById("apDue")?.value || "").trim();
          if (!newDue) { alert("Please choose a due date."); return; }
          const note = (document.getElementById("apNote")?.value || "").trim();

          postAction({ task_id: taskId, action: "extend_due", new_dueDate: newDue, comment: note })
            .then(resp => {
              alert(resp.message || (resp.ok ? "Done" : "Failed"));
              if (resp.ok && lastQuery) loadTasks(lastQuery, modalSubtitle.textContent, true);
              closeActionPopup();
            })
            .catch(() => alert("Action failed. Check server logs."));
        }
      );
      return;
    }
  });

  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-action][data-value]");
    if (!btn) return;

    const action = btn.getAttribute("data-action");
    const value = btn.getAttribute("data-value");

    if (action === "status") {
      loadTasks({ status: value }, `Showing: ${value}`, true);
    } else if (action === "filter") {
      const map = { overdue: "Overdue (In Progress only)", due_7: "Due in 7 days", recent_30: "Last 30 days" };
      loadTasks({ filter: value }, `Showing: ${map[value] || value}`, true);
    }
  });

  document.getElementById("btnRecent30")?.addEventListener("click", () => {
    loadTasks({ filter: "recent_30" }, "Showing: Last 30 days", true);
  });

  const taskSearch = document.getElementById("taskSearch");
  let searchTimer = null;

  function scrollToTaskFeed() {
    if (!taskFeedCard) return;
    taskFeedCard.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function restoreTaskFeed() {
    if (feedTbody) feedTbody.innerHTML = initialFeedHTML;
    if (feedSubtitle) feedSubtitle.textContent = initialSubtitle;
  }

  function runServerSearch(q) {
    const query = (q || "").trim();

    if (!query) {
      restoreTaskFeed();
      return;
    }

    if (modal && !modal.classList.contains("hidden")) closeModal();

    loadTasks({ q: query }, `Search results for: "${query}"`, false);
    scrollToTaskFeed();
  }

  taskSearch?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      runServerSearch(taskSearch.value);
    }
  });

  taskSearch?.addEventListener("input", () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => runServerSearch(taskSearch.value), 450);
  });

  modalSearch?.addEventListener("input", (e) => {
    const q = e.target.value.toLowerCase().trim();
    modalTbody.querySelectorAll("tr").forEach(tr => {
      const txt = tr.textContent.toLowerCase();
      tr.style.display = txt.includes(q) ? "" : "none";
    });
  });

  const bubble = document.getElementById("ttBubble");
  function showBubble(text, x, y) {
    if (!bubble) return;
    bubble.textContent = text;
    bubble.style.display = "block";
    bubble.style.left = (x + 12) + "px";
    bubble.style.top = (y + 12) + "px";
  }
  function hideBubble() {
    if (!bubble) return;
    bubble.style.display = "none";
  }

  document.querySelectorAll(".tt-tip[data-tip]").forEach(el => {
    el.addEventListener("mouseenter", (e) => showBubble(el.getAttribute("data-tip") || "", e.clientX, e.clientY));
    el.addEventListener("mousemove", (e) => showBubble(el.getAttribute("data-tip") || "", e.clientX, e.clientY));
    el.addEventListener("mouseleave", hideBubble);
  });

  const statusDist = <?php echo json_encode($statusDist); ?>;
  const assigneeLabels = <?php echo json_encode($assigneeLabels); ?>;
  const assigneeCounts = <?php echo json_encode($assigneeCounts); ?>;
  const dayKeys = <?php echo json_encode(array_keys($days)); ?>;
  const createdCounts = <?php echo json_encode($createdCounts); ?>;

  const pieCtx = document.getElementById("statusPie");
  if (pieCtx && window.Chart) {
    const pie = new Chart(pieCtx, {
      type: "pie",
      data: { labels: Object.keys(statusDist), datasets: [{ data: Object.values(statusDist) }] },
      options: {
        plugins: {
          legend: { position: "bottom" },
          tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.parsed}` } }
        },
        onClick: (evt, elements) => {
          if (!elements.length) return;
          const i = elements[0].index;
          const status = pie.data.labels[i];
          loadTasks({ status }, `Showing: ${status}`, true);
        }
      }
    });
  }

  const barCtx = document.getElementById("assigneeBar");
  if (barCtx && window.Chart) {
    const bar = new Chart(barCtx, {
      type: "bar",
      data: { labels: assigneeLabels, datasets: [{ label: "Tasks", data: assigneeCounts }] },
      options: {
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (ctx) => `Tasks: ${ctx.parsed.y}` } }
        },
        onClick: (evt, elements) => {
          if (!elements.length) return;
          const i = elements[0].index;
          const who = bar.data.labels[i];
          loadTasks({ assignee: who }, `Showing: Assigned to ${who}`, true);
        },
        scales: { y: { beginAtZero: true } }
      }
    });
  }

  const lineCtx = document.getElementById("createdLine");
  if (lineCtx && window.Chart) {
    new Chart(lineCtx, {
      type: "line",
      data: { labels: dayKeys.map(d => d.slice(5)), datasets: [{ label: "Started", data: createdCounts, tension: 0.35 }] },
      options: {
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (ctx) => `Started: ${ctx.parsed.y}` } }
        },
        scales: { y: { beginAtZero: true } }
      }
    });
  }
})();
</script>

<?php
if ($stmtRecent) $stmtRecent->close();
?>