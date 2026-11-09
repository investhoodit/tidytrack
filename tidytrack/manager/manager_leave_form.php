<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include '../dp.php';

// ---------- AUTH GUARD ----------
$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower($_SESSION['role'] ?? '');
$allowedRoles = ['leader','manager','admin','member'];

if (!$userId || !in_array($role, $allowedRoles, true)) {
  http_response_code(401);
  echo '<div style="padding:16px;border:1px solid #eee;border-radius:8px;background:#fff">
          <b>Unauthorized.</b> Please sign in as Leader/Manager/Admin.
        </div>';
  exit;
}

// ---------- HELPERS ----------
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function ensure_upload_dir($path){
  if (!is_dir($path)) @mkdir($path, 0755, true);
  return is_dir($path) && is_writable($path);
}

function calc_total_units(array $in): array {
  $unit    = $in['request_unit']   ?? 'days';
  $sd      = $in['start_date']     ?? null;
  $ed      = $in['end_date']       ?? null;
  $st      = $in['start_time']     ?? null;
  $et      = $in['end_time']       ?? null;
  $partial = $in['partial_day']    ?? 'none';

  if (!$sd || !$ed) return [false, null, $unit, "Start/End date required"];
  $d1 = strtotime($sd . ' 00:00:00');
  $d2 = strtotime($ed . ' 00:00:00');
  if ($d2 < $d1) return [false, null, $unit, "End date cannot be before start date"];

  if ($unit === 'days') {
    $days = floor(($d2 - $d1) / 86400) + 1;
    if (in_array($partial, ['am','pm'], true)) $days -= 0.5;

    if ($partial === 'custom_hours') {
      if (!$st || !$et) return [false, null, $unit, "Custom hours requires start & end time"];
      $t1 = strtotime("$sd $st");
      $t2 = strtotime("$sd $et");
      if ($t2 <= $t1) return [false, null, $unit, "End time must be after start time"];
      $h = ($t2 - $t1) / 3600;
      return [true, round($h, 2), 'hours', null];
    }

    return [true, round(max(0, $days), 2), 'days', null];
  } else {
    if (!$st || !$et) return [false, null, $unit, "Hours unit requires start & end time"];
    $t1 = strtotime("$sd $st");
    $t2 = strtotime("$ed $et");
    if ($t2 <= $t1) return [false, null, $unit, "End date/time must be after start date/time"];
    $h = ($t2 - $t1) / 3600;
    return [true, round($h, 2), 'hours', null];
  }
}

function leave_type_label(string $code): string {
  return match($code){
    'personal_day'          => 'Personal Day',
    'sick'                  => 'Sick Leave',
    'annual_leave'          => 'Annual Leave',
    'family_responsibility' => 'Family Responsibility Leave',
    'study'                 => 'Study Leave',
    'unpaid'                => 'Unpaid Leave',
    default                 => $code
  };
}

function attachment_rule(string $leaveType, string $resolvedUnit, float $total): array {
  $leaveType = strtolower(trim($leaveType));

  if (in_array($leaveType, ['family_responsibility', 'personal_day'], true)) {
    return [false, 'Attachment is optional for this leave type.'];
  }

  return [true, 'Attachment is required for this leave type. Please upload a supporting document (PDF/JPG/PNG).'];
}

/* =========================================================
   CURRENT USER SNAPSHOT
========================================================= */
$me = ['display'=>'User','department'=>null,'email'=>null];

$deptCol = null;
if ($res = $conn->query("SHOW COLUMNS FROM users LIKE 'department'")) {
  if ($res->num_rows > 0) $deptCol = 'department';
  $res->free();
}
if (!$deptCol) {
  if ($res = $conn->query("SHOW COLUMNS FROM users LIKE 'deparment'")) {
    if ($res->num_rows > 0) $deptCol = 'deparment';
    $res->free();
  }
}
$deptSelect = $deptCol ? $deptCol : "NULL";

$sqlMe = "SELECT
            COALESCE(NULLIF(name,''), email) AS display,
            $deptSelect AS department,
            email
          FROM users
          WHERE user_id = ?";

if ($stmt = $conn->prepare($sqlMe)) {
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if ($row) $me = array_merge($me, $row);
  $stmt->close();
}
$deptMissing = empty(trim((string)($me['department'] ?? '')));

/* =========================================================
   SUPERVISORS
========================================================= */
$supervisors = [];
$supEmailMap = [];

$sqlSup = "SELECT user_id, COALESCE(NULLIF(name,''), email) AS n, email
           FROM users
           WHERE LOWER(role) IN ('manager','admin')
           ORDER BY n";

if ($res = $conn->query($sqlSup)) {
  $supervisors = $res->fetch_all(MYSQLI_ASSOC);
  foreach ($supervisors as $s) {
    $sid = (int)($s['user_id'] ?? 0);
    if ($sid) $supEmailMap[$sid] = (string)($s['email'] ?? '');
  }
  $res->free();
}

// ---------- TABLE CHECK ----------
$shape = 'ok';
$tblCheck = $conn->query("SHOW TABLES LIKE 'leave_requests'");
if (!$tblCheck || $tblCheck->num_rows === 0) {
  $shape = 'none';
}

// ---------- STICKY ----------
$OLD = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : [];
function oldv(string $key, $default=''){
  global $OLD;
  return $OLD[$key] ?? $default;
}
function oldh(string $key, $default=''){
  return h(oldv($key, $default));
}
function selected_if($val, $current): string {
  return ((string)$val === (string)$current) ? 'selected' : '';
}
function checked_if($val, $current): string {
  return ((string)$val === (string)$current) ? 'checked' : '';
}

// ---------- SUBMIT & CANCEL ----------
$flash     = null;
$activeTab = 'apply';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {
  if ($shape === 'none') {
    $flash = ['err','Leave table not found. Please create `leave_requests` first.'];
  } else {
    if ($deptMissing) {
      $flash = ['err','Department is missing. Please update your profile so the department can be pulled automatically.'];
    } else {
      $in = [
        'request_unit'    => $_POST['request_unit'] ?? 'days',
        'start_date'      => $_POST['start_date'] ?? '',
        'end_date'        => $_POST['end_date'] ?? '',
        'start_time'      => $_POST['start_time'] ?? null,
        'end_time'        => $_POST['end_time'] ?? null,
        'partial_day'     => $_POST['partial_day'] ?? 'none',
        'leave_type'      => $_POST['leave_type'] ?? '',
        'reason_category' => $_POST['reason_category'] ?? '',
        'reason_text'     => trim($_POST['reason_text'] ?? ''),
        'contact_number'  => trim($_POST['contact_number'] ?? ''),
        'contact_email'   => trim($_POST['contact_email'] ?? ''),
        'leave_address'   => trim($_POST['leave_address'] ?? ''),
        'handover_notes'  => trim($_POST['handover_notes'] ?? ''),
        'cc_email'        => trim($_POST['cc_email'] ?? ''),
        'supervisor_id'   => (int)($_POST['supervisor_id'] ?? 0),
      ];

      if (!$in['supervisor_id']) {
        $flash = ['err','Please select a supervisor.'];
      } elseif (!$in['leave_type'] || !$in['reason_category']) {
        $flash = ['err','Please select leave type and reason category.'];
      } elseif ($in['reason_category'] === 'other' && $in['reason_text'] === '') {
        $flash = ['err',"Please fill in 'Reason details' because you selected Other."];
      } elseif (!isset($_POST['ack_policy'])) {
        $flash = ['err','Please acknowledge the leave policy.'];
      } else {
        [$ok, $total, $resolvedUnit, $err] = calc_total_units(array_merge($in, $_POST));
        if (!$ok) {
          $flash = ['err', $err ?: 'Invalid dates/times'];
        } else {
          [$attachRequired, $attachHelper] = attachment_rule($in['leave_type'], $resolvedUnit, (float)$total);

          $hasFile = !empty($_FILES['attachment']['name']);
          if ($attachRequired && !$hasFile) {
            $flash = ['err', $attachHelper];
          }

          $savedPath = null;
          if (!$flash && $hasFile) {
            $u = $_FILES['attachment'];
            if ($u['error'] === UPLOAD_ERR_OK) {
              $allowed = ['application/pdf','image/jpeg','image/png'];
              $mime    = mime_content_type($u['tmp_name']);
              if (!in_array($mime, $allowed, true)) {
                $flash = ['err','Attachment must be PDF/JPG/PNG.'];
              } elseif ($u['size'] > 5 * 1024 * 1024) {
                $flash = ['err','Attachment too large (max 5MB).'];
              } else {
                $dir = __DIR__ . '/uploads/leave';
                if (!ensure_upload_dir($dir)) {
                  $flash = ['err','Upload folder is not writable.'];
                } else {
                  $ext   = strtolower(pathinfo($u['name'], PATHINFO_EXTENSION));
                  $fname = 'leave_' . $userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                  $dest  = $dir . '/' . $fname;
                  if (!move_uploaded_file($u['tmp_name'], $dest)) {
                    $flash = ['err','Failed to save attachment.'];
                  } else {
                    $savedPath = 'uploads/leave/' . $fname;
                  }
                }
              }
            } elseif ($u['error'] !== UPLOAD_ERR_NO_FILE) {
              $flash = ['err','Upload error code: ' . $u['error']];
            }
          }

          if (!$flash) {
            $sql = "INSERT INTO leave_requests
              (employee_id, supervisor_id, request_unit, start_date, end_date, start_time, end_time, partial_day,
               leave_type, reason_category, reason_text, contact_number, contact_email, leave_address,
               handover_notes, cc_email, attachment_path, total_units, status)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending')";

            $stmt = $conn->prepare($sql);

            $st  = $in['start_time'] ?: null;
            $et  = $in['end_time']   ?: null;
            $par = $in['partial_day'] ?: 'none';
            $ru  = $resolvedUnit === 'hours' ? 'hours' : $in['request_unit'];

            $stmt->bind_param(
              'iisssssssssssssssd',
              $userId,
              $in['supervisor_id'],
              $ru,
              $in['start_date'],
              $in['end_date'],
              $st,
              $et,
              $par,
              $in['leave_type'],
              $in['reason_category'],
              $in['reason_text'],
              $in['contact_number'],
              $in['contact_email'],
              $in['leave_address'],
              $in['handover_notes'],
              $in['cc_email'],
              $savedPath,
              $total
            );

            if ($stmt->execute()) {
              $supervisorEmail = trim((string)($supEmailMap[$in['supervisor_id']] ?? ''));
              if ($supervisorEmail === '') {
                $supervisorEmail = "jerome.dhlamini@gmail.com";
              }

              $subject = "New Leave Request Submitted";
              $message =
                "Good Day,\n\n" .
                "A new leave request has been submitted by: " . ($me['display'] ?? 'Unknown') . "\n" .
                "Department: " . ($me['department'] ?? '') . "\n" .
                "Leave Type: " . leave_type_label($in['leave_type']) . "\n" .
                "From: " . $in['start_date'] . " To: " . $in['end_date'] . "\n" .
                "Total: " . $total . " " . $resolvedUnit . "\n\n" .
                "Reason: " . ($in['reason_text'] ?: $in['reason_category']) . "\n\n" .
                "Please review and approve the request in the Leave Portal:\n" .
                "https://investhoodarcade.com/Investhood_TSM/tidytrack\n\n" .
                "Regards,\nLeave Management System";

              $headers  = "From: no-reply@investhoodarcade.com\r\n";
              if (!empty($me['email'])) {
                $headers .= "Reply-To: " . $me['email'] . "\r\n";
              }
              if (!empty($in['cc_email'])) {
                $headers .= "Cc: " . $in['cc_email'] . "\r\n";
              }
              $headers .= "MIME-Version: 1.0\r\n";
              $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

              @mail($supervisorEmail, $subject, $message, $headers);

              $flash = ['ok','Leave request submitted successfully. Email sent to supervisor at: '.$supervisorEmail];
              $activeTab = 'my';
              $OLD = [];
            } else {
              $flash = ['err','Database error: ' . $stmt->error];
            }
            $stmt->close();
          }
        }
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
  $cancelId = (int)$_POST['cancel_id'];
  if ($cancelId) {
    $sql = "UPDATE leave_requests SET status='cancelled'
            WHERE id=? AND employee_id=? AND status='pending'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $cancelId, $userId);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
      $flash = ['ok','Request cancelled.'];
    } else {
      $flash = ['err','Unable to cancel (already processed or not found).'];
    }
    $stmt->close();
    $activeTab = 'my';
  }
}

// ---------- LOAD MY REQUESTS ----------
$myRequests = [];
if ($shape !== 'none') {
  $q = "SELECT * FROM leave_requests WHERE employee_id=? ORDER BY created_at DESC";
  $stmt = $conn->prepare($q);
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $myRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

$oldUnit     = oldv('request_unit', 'days');
$oldPartial  = oldv('partial_day', 'none');
$oldLeave    = oldv('leave_type', '');
$oldReason   = oldv('reason_category', '');
$oldSup      = oldv('supervisor_id', '');
$showOther   = ($oldReason === 'other');
$isHoursUI   = ($oldUnit === 'hours');
$showTimeUI  = ($isHoursUI || $oldPartial === 'custom_hours');
?>
<!doctype html>
<html lang="en-ZA">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Leave Portal</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
      --bg:#f6f8fb;
      --surface:#ffffff;
      --surface-2:#f8fafc;
      --text:#1f2937;
      --text-soft:#475569;
      --muted:#6b7280;
      --accent:#1d3557;
      --accent-600:#162a44;
      --border:#dbe3ee;
      --line:#eef2f7;
      --shadow:0 8px 24px rgba(13,16,23,.08);
      --radius:16px;
      --radius-sm:8px;
      --thead:#f3f5f9;
    }

    body.dark{
      --bg:#0f172a;
      --surface:#0b1222;
      --surface-2:#111a2e;
      --text:#e5e7eb;
      --text-soft:#cbd5e1;
      --muted:#cbd5e1;
      --accent:#93c5fd;
      --accent-600:#60a5fa;
      --border:#334155;
      --line:#1e293b;
      --shadow:none;
      --thead:#111a2e;
    }

    html,body{
      margin:0;
      padding:0;
      min-height:100%;
      background:var(--bg);
      color:var(--text);
      font-family:"Poppins",system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
    }

    main{
      width:100%;
      max-width:1280px;
      margin:40px auto;
      padding:0 20px 40px;
    }

    .page-shell{
      display:flex;
      flex-direction:column;
      align-items:center;
      width:100%;
    }

    .page-header{
      width:100%;
      max-width:1120px;
      text-align:center;
      margin:0 auto 24px;
    }

    .page-title{
      text-align:center;
      font-size:clamp(1.8rem, 2.6vw, 2.2rem);
      font-weight:700;
      color:var(--accent);
      margin-bottom:10px;
    }

    .page-subtitle{
      text-align:center;
      color:var(--text-soft);
      margin-bottom:18px;
      font-size:.98rem;
    }

    .heading-accent{
      height:4px;
      width:120px;
      margin:0 auto 24px;
      background:linear-gradient(90deg,var(--accent) 0%, #2a9d8f 100%);
      border-radius:999px;
    }

    .top-bar{
      width:100%;
      max-width:1120px;
      margin:0 auto 16px;
      display:flex;
      flex-wrap:wrap;
      justify-content:center;
      align-items:center;
      gap:12px;
      text-align:center;
    }

    .top-bar .left-group,
    .top-bar .right-group{
      display:flex;
      flex-wrap:wrap;
      justify-content:center;
      align-items:center;
      gap:10px;
    }

    .content-wrap{
      width:100%;
      max-width:1120px;
      margin:0 auto;
    }

    .card{
      width:100%;
      border:1px solid var(--line) !important;
      border-radius:var(--radius) !important;
      box-shadow:var(--shadow);
      background:var(--surface) !important;
      color:var(--text) !important;
    }

    .card .card-body{
      padding:24px;
      color:var(--text);
    }

    .nav.nav-pills{
      width:100%;
      justify-content:center;
    }

    .nav-pills .nav-link{
      border-radius:12px;
      font-weight:600;
      color:var(--accent);
      border:1px solid var(--border);
      background:var(--surface);
    }

    .nav-pills .nav-link.active{
      background:var(--accent);
      color:#fff;
      border-color:var(--accent);
    }

    body.dark .nav-pills .nav-link.active{
      color:#0b1222;
    }

    label,.form-label{
      font-weight:700;
      margin-bottom:6px;
      color:var(--text) !important;
    }

    .hint,
    .muted,
    .text-muted,
    small,
    .small{
      color:var(--text-soft) !important;
    }

    .form-control,
    .form-select{
      background:var(--surface) !important;
      color:var(--text) !important;
      border:1px solid var(--border) !important;
      border-radius:var(--radius-sm) !important;
      padding:10px 12px !important;
      min-height:46px;
      box-shadow:none !important;
    }

    .form-control::placeholder{
      color:var(--text-soft) !important;
      opacity:1;
    }

    .form-control:focus,
    .form-select:focus{
      outline:2px solid var(--accent) !important;
      border-color:var(--accent) !important;
      background:var(--surface) !important;
      color:var(--text) !important;
    }

    .form-check-label{
      color:var(--text) !important;
    }

    .date-input-wrap,
    .time-input-wrap{
      position:relative;
      width:100%;
    }

    .date-input-wrap .form-control,
    .time-input-wrap .form-control{
      padding-right:42px !important;
    }

    .date-input-icon,
    .time-input-icon{
      position:absolute;
      right:14px;
      top:50%;
      transform:translateY(-50%);
      color:#475569;
      font-size:16px;
      pointer-events:none;
      z-index:2;
    }

    body.dark .date-input-icon,
    body.dark .time-input-icon{
      color:#e2e8f0 !important;
    }

    input[type="date"],
    input[type="time"]{
      position:relative;
      color-scheme:light;
    }

    body.dark input[type="date"],
    body.dark input[type="time"]{
      color-scheme:dark;
      background:var(--surface) !important;
      color:var(--text) !important;
    }

    input[type="date"]::-webkit-calendar-picker-indicator,
    input[type="time"]::-webkit-calendar-picker-indicator{
      position:absolute;
      right:10px;
      width:22px;
      height:22px;
      opacity:0;
      cursor:pointer;
      z-index:3;
    }

    input[type="date"]::-webkit-inner-spin-button,
    input[type="time"]::-webkit-inner-spin-button{
      opacity:1;
    }

    .btn{
      border-radius:var(--radius-sm) !important;
      font-weight:600;
    }

    .btn-primary{
      background:var(--accent) !important;
      border-color:var(--accent) !important;
    }

    .btn-primary:hover{
      background:var(--accent-600) !important;
      border-color:var(--accent-600) !important;
    }

    .btn-outline-primary{
      border-color:var(--accent) !important;
      color:var(--accent) !important;
      font-weight:600;
      background:transparent !important;
    }

    .btn-outline-primary:hover{
      background:var(--accent) !important;
      color:#fff !important;
    }

    .btn-outline-secondary{
      border-color:var(--border) !important;
      color:var(--text) !important;
      background:transparent !important;
    }

    .btn-outline-secondary:hover{
      background:var(--surface-2) !important;
      color:var(--text) !important;
    }

    .btn-outline-danger{
      color:#ef4444 !important;
      border-color:#ef4444 !important;
    }

    .btn-outline-danger:hover{
      background:#ef4444 !important;
      color:#fff !important;
    }

    .alert{
      width:100%;
      max-width:1120px;
      margin-left:auto;
      margin-right:auto;
      border-radius:var(--radius-sm);
      border:1px solid var(--border);
    }

    .chip{
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      padding:.35rem .7rem;
      border-radius:999px;
      border:1px solid var(--line);
      background:var(--surface);
      color:var(--text);
      font-size:.85rem;
    }

    .table-responsive{
      border:1px solid var(--line);
      border-radius:var(--radius);
      overflow:hidden;
      box-shadow:var(--shadow);
      background:var(--surface);
    }

    table.table{
      margin:0;
      color:var(--text) !important;
      background:var(--surface) !important;
    }

    .table > :not(caption) > * > *{
      background:transparent !important;
      color:var(--text) !important;
      border-color:var(--line) !important;
    }

    .table thead th{
      background:var(--thead) !important;
      color:var(--text) !important;
      font-weight:700;
      border-bottom:1px solid var(--line) !important;
    }

    .table tbody tr{
      background:var(--surface) !important;
    }

    .table-hover tbody tr:hover{
      background:var(--surface-2) !important;
    }

    .badge-pending{background:#ffc107;color:#111827 !important;}
    .badge-approved{background:#22c55e;color:#fff !important;}
    .badge-rejected{background:#ef4444;color:#fff !important;}

    .bg-secondary{
      background:#64748b !important;
      color:#fff !important;
    }

    #toggle-dark{
      position:fixed;
      top:15px;
      right:15px;
      z-index:999;
      background:#111827;
      border:0;
      color:#fff;
      padding:10px 12px;
      border-radius:var(--radius-sm);
      box-shadow:var(--shadow);
    }

    body.dark #toggle-dark{
      background:#e5e7eb;
      color:#111827;
    }

    .req-dot{ color:#ef4444; font-weight:800; }

    .attach-badge{
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      padding:.25rem .55rem;
      border-radius:999px;
      border:1px solid var(--line);
      background:var(--surface);
      font-size:.82rem;
    }

    .attach-badge.required{
      border-color:#fecaca;
      background:#fff1f2;
      color:#991b1b;
    }

    .attach-badge.optional{
      border-color:#dbeafe;
      background:#eff6ff;
      color:#1e40af;
    }

    body.dark .attach-badge.required{
      background:#3b0d17;
      color:#fecdd3;
      border-color:#7f1d1d;
    }

    body.dark .attach-badge.optional{
      background:#0b2545;
      color:#bfdbfe;
      border-color:#1d4ed8;
    }

    hr{
      border-color:var(--line);
      opacity:1;
    }

    code{
      color:var(--accent);
    }

    @media (max-width:768px){
      main{
        margin:24px auto;
        padding:0 14px 30px;
      }

      .card .card-body{
        padding:16px;
      }

      .top-bar{
        flex-direction:column;
      }

      .page-header,
      .top-bar,
      .content-wrap,
      .alert{
        max-width:100%;
      }
    }
  </style>
</head>

<body>
<main>
  <div class="page-shell">
    <div class="page-header">
      <h1 class="page-title"><i class="fa-regular fa-calendar-check me-2"></i>Leave Portal</h1>
      <div class="page-subtitle">Hi, <?php echo h($me['display']); ?> • Submit and track leave requests</div>
      <div class="heading-accent"></div>
    </div>

    <div class="top-bar">
      <div class="left-group muted">
        <span class="chip"><i class="fa-solid fa-building"></i> Department: <?php echo h($me['department'] ?? ''); ?></span>
        <span class="chip" data-bs-toggle="tooltip" title="Department is pulled from your profile/bio. Update your profile if it’s missing.">
          <i class="fa-solid fa-circle-info"></i> Profile-synced
        </span>
      </div>
      <div class="right-group">
        <a href="profile.php" class="btn btn-sm btn-outline-primary" title="Update your profile department">
          <i class="fa-solid fa-user-gear me-1"></i> Profile
        </a>
      </div>
    </div>

    <?php if ($deptMissing): ?>
      <div class="alert alert-warning">
        <b>Department is pulled from your profile.</b> Please update your profile if missing.
        <div class="hint mt-1">Submission is disabled until a department is available.</div>
      </div>
    <?php endif; ?>

    <?php if (!empty($flash)): ?>
      <div class="alert <?php echo $flash[0]==='ok'?'alert-success':'alert-danger'; ?>">
        <?php echo h($flash[1]); ?>
      </div>
    <?php endif; ?>

    <?php if ($shape==='none'): ?>
      <div class="alert alert-danger">No leave table found. Create <code>leave_requests</code>.</div>
    <?php endif; ?>

    <div class="content-wrap">
      <ul class="nav nav-pills gap-2 mb-3" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link <?php echo $activeTab==='apply'?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#apply-pane" type="button" role="tab">
            <i class="fa-regular fa-file-lines me-1"></i> Apply
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link <?php echo $activeTab==='my'?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#my-pane" type="button" role="tab">
            <i class="fa-solid fa-folder-open me-1"></i> My Requests
          </button>
        </li>
      </ul>

      <button id="toggle-dark" type="button" title="Toggle dark mode">
        <i class="fas fa-moon"></i>
      </button>

      <div class="tab-content">

        <div class="tab-pane fade <?php echo $activeTab==='apply'?'show active':''; ?>" id="apply-pane" role="tabpanel">
          <div class="card mb-4">
            <div class="card-body">

              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Employee</label>
                  <input class="form-control" value="<?php echo h($me['display']); ?>" readonly>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Department</label>
                  <input class="form-control" value="<?php echo h($me['department'] ?? ''); ?>" readonly>
                  <div class="hint"><i class="fa-solid fa-circle-info me-1"></i>Department is pulled from your profile/bio.</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Supervisor <span class="req-dot">*</span></label>

                  <select name="supervisor_id" id="supervisor_id" form="leaveForm" class="form-select" required onchange="updateSupervisorEmailUI()">
                    <option value="">-- Select --</option>
                    <?php foreach($supervisors as $s): ?>
                      <?php
                        $sid = (int)($s['user_id'] ?? 0);
                        $sn  = (string)($s['n'] ?? '');
                        $se  = (string)($s['email'] ?? '');
                        $label = $se ? ($sn . " (" . $se . ")") : $sn;
                      ?>
                      <option
                        value="<?php echo $sid; ?>"
                        data-email="<?php echo h($se); ?>"
                        <?php echo selected_if((string)$sid, (string)$oldSup); ?>
                      >
                        <?php echo h($label); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <div class="hint mt-1" id="supervisorEmailHint">
                    Select a supervisor to see the email address.
                  </div>

                  <div class="hint">Your request will be routed to this person for approval.</div>
                </div>
              </div>

              <hr class="my-4">

              <form id="leaveForm" method="post" enctype="multipart/form-data" class="row g-3" novalidate>
                <input type="hidden" name="submit_leave" value="1">

                <div class="col-12">
                  <label class="form-label">
                    Leave Units
                    <i class="fa-solid fa-circle-info ms-1 text-muted"
                       data-bs-toggle="tooltip"
                       title="Full day leave is auto-calculated based on selected dates. Partial day applies when hours/time is selected."></i>
                  </label>
                  <div class="d-flex flex-wrap gap-2">
                    <span class="chip">
                      <input class="form-check-input me-1" type="radio" name="request_unit" value="days" id="uDays"
                             <?php echo checked_if('days', $oldUnit); ?> onclick="toggleUnit()">
                      <label class="form-check-label" for="uDays">Days (Full Day)</label>
                    </span>
                    <span class="chip">
                      <input class="form-check-input me-1" type="radio" name="request_unit" value="hours" id="uHours"
                             <?php echo checked_if('hours', $oldUnit); ?> onclick="toggleUnit()">
                      <label class="form-check-label" for="uHours">Hours (Partial Day)</label>
                    </span>
                  </div>
                  <div class="hint mt-1">
                    Full Day is auto-calculated from dates. Hours triggers Partial Day.
                  </div>
                </div>

                <div class="col-md-3">
                  <label class="form-label">From <span class="req-dot">*</span></label>
                  <div class="date-input-wrap">
                    <input type="date" class="form-control" name="start_date" id="start_date" required
                           value="<?php echo oldh('start_date'); ?>"
                           oninput="recalc();updateSupportDocsUI();">
                    <i class="fa-regular fa-calendar date-input-icon"></i>
                  </div>
                </div>

                <div class="col-md-3">
                  <label class="form-label">To <span class="req-dot">*</span></label>
                  <div class="date-input-wrap">
                    <input type="date" class="form-control" name="end_date" id="end_date" required
                           value="<?php echo oldh('end_date'); ?>"
                           oninput="recalc();updateSupportDocsUI();">
                    <i class="fa-regular fa-calendar date-input-icon"></i>
                  </div>
                </div>

                <div class="col-md-3" id="partialWrap" <?php echo $isHoursUI ? 'style="display:none"' : ''; ?>>
                  <label class="form-label">
                    Partial Day
                    <i class="fa-solid fa-circle-info ms-1 text-muted"
                       data-bs-toggle="tooltip"
                       title="Use Half-day AM/PM or Custom Hours. Custom Hours will calculate hours instead of days."></i>
                  </label>
                  <select class="form-select" name="partial_day" id="partial_day" onchange="recalc();updateSupportDocsUI();">
                    <option value="none" <?php echo selected_if('none', $oldPartial); ?>>—</option>
                    <option value="am" <?php echo selected_if('am', $oldPartial); ?>>Half-day (AM)</option>
                    <option value="pm" <?php echo selected_if('pm', $oldPartial); ?>>Half-day (PM)</option>
                    <option value="custom_hours" <?php echo selected_if('custom_hours', $oldPartial); ?>>Custom Hours</option>
                  </select>
                </div>

                <div class="col-md-3 <?php echo $showTimeUI ? '' : 'd-none'; ?>" id="timeWrap">
                  <label class="form-label">Time (for hours/custom)</label>
                  <div class="d-flex gap-2">
                    <div class="time-input-wrap">
                      <input type="time" class="form-control" name="start_time" id="start_time"
                             value="<?php echo oldh('start_time'); ?>"
                             onchange="recalc();updateSupportDocsUI();">
                      <i class="fa-regular fa-clock time-input-icon"></i>
                    </div>

                    <div class="time-input-wrap">
                      <input type="time" class="form-control" name="end_time" id="end_time"
                             value="<?php echo oldh('end_time'); ?>"
                             onchange="recalc();updateSupportDocsUI();">
                      <i class="fa-regular fa-clock time-input-icon"></i>
                    </div>
                  </div>
                  <div class="hint">We’ll calculate total hours. End must be after start.</div>
                </div>

                <div class="col-md-3">
                  <label class="form-label">Type of Leave <span class="req-dot">*</span></label>
                  <select class="form-select" name="leave_type" id="leave_type" required onchange="updateSupportDocsUI()">
                    <option value="">-- select --</option>
                    <option value="personal_day" data-help="Attachment optional for Personal Day." <?php echo selected_if('personal_day', $oldLeave); ?>>Personal Day</option>
                    <option value="sick" data-help="Attachment required for Sick Leave." <?php echo selected_if('sick', $oldLeave); ?>>Sick Leave</option>
                    <option value="family_responsibility" data-help="Attachment optional for Family Responsibility Leave." <?php echo selected_if('family_responsibility', $oldLeave); ?>>Family Responsibility Leave</option>
                    <option value="annual_leave" data-help="Attachment required for Annual Leave." <?php echo selected_if('annual_leave', $oldLeave); ?>>Annual Leave</option>
                    <option value="study" data-help="Attachment required for Study Leave (supporting document/proof)." <?php echo selected_if('study', $oldLeave); ?>>Study Leave</option>
                    <option value="unpaid" data-help="Attachment required for Unpaid Leave." <?php echo selected_if('unpaid', $oldLeave); ?>>Unpaid Leave</option>
                  </select>
                  <div class="hint" id="leave_type_help"></div>
                </div>

                <div class="col-md-3">
                  <label class="form-label">Reason <span class="req-dot">*</span></label>
                  <select class="form-select" name="reason_category" id="reason_category" required onchange="toggleOther()">
                    <option value="">-- select --</option>
                    <option value="medical_appointment" <?php echo selected_if('medical_appointment', $oldReason); ?>>Medical Appointment</option>
                    <option value="personal_family_event" <?php echo selected_if('personal_family_event', $oldReason); ?>>Personal or Family Events</option>
                    <option value="exam_preparation" <?php echo selected_if('exam_preparation', $oldReason); ?>>Exam preparation</option>
                    <option value="bereavement" <?php echo selected_if('bereavement', $oldReason); ?>>Death in the Family</option>
                    <option value="other" <?php echo selected_if('other', $oldReason); ?>>Other</option>
                  </select>
                </div>

                <div class="col-md-6 <?php echo $showOther ? '' : 'd-none'; ?>" id="otherWrap">
                  <label class="form-label">Reason details <span class="req-dot" id="otherStar" style="<?php echo $showOther ? '' : 'display:none'; ?>">*</span></label>
                  <textarea class="form-control" id="reason_text" name="reason_text" rows="3" placeholder="Required if 'Other'"><?php echo h(oldv('reason_text','')); ?></textarea>
                  <div class="hint">If you selected <b>Other</b>, please give more details.</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Contact number</label>
                  <input type="tel" name="contact_number" class="form-control" placeholder="+27…" value="<?php echo oldh('contact_number'); ?>">
                </div>

                <div class="col-md-4">
                  <label class="form-label">Contact email</label>
                  <input type="email" name="contact_email" class="form-control" value="<?php echo h(oldv('contact_email', (string)$me['email'])); ?>">
                </div>

                <div class="col-md-4">
                  <label class="form-label">Address during leave</label>
                  <input type="text" name="leave_address" class="form-control" placeholder="Where you’ll be reachable" value="<?php echo oldh('leave_address'); ?>">
                </div>

                <div class="col-12">
                  <label class="form-label">Handover notes</label>
                  <textarea name="handover_notes" class="form-control" rows="3" placeholder="Work handover / delegated tasks"><?php echo h(oldv('handover_notes','')); ?></textarea>
                </div>

                <div class="col-md-6">
                  <label class="form-label">CC Email (optional)</label>
                  <input type="email" name="cc_email" class="form-control" placeholder="e.g. team@company.com" value="<?php echo oldh('cc_email'); ?>">
                </div>

                <div class="col-md-6">
                  <div class="d-flex align-items-center justify-content-between">
                    <label class="form-label mb-1">
                      Supporting Document
                      <span id="attachRequiredStar" class="req-dot" style="display:none">*</span>
                    </label>
                    <span id="attachBadge" class="attach-badge optional">
                      <i class="fa-regular fa-file"></i> Optional
                    </span>
                  </div>

                  <input type="file" name="attachment" id="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                  <div class="hint mt-1" id="attachHint">
                    Select a leave type to see attachment requirements.
                  </div>
                </div>

                <div class="col-md-3">
                  <label class="form-label">Total</label>
                  <input class="form-control" id="total_units" name="total_units_display" value="—" readonly>
                  <div class="hint" id="total_hint">Auto-calculated (days/hours)</div>
                </div>

                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="ack_policy" name="ack_policy" required <?php echo isset($OLD['ack_policy']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="ack_policy">
                      I confirm my details are true and I understand the leave policy.
                    </label>
                  </div>
                </div>

                <div class="col-12 d-flex gap-2 flex-wrap">
                  <button type="submit" class="btn btn-primary" <?php echo $deptMissing ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-paper-plane me-1"></i> Submit Request
                  </button>
                  <button class="btn btn-outline-secondary" type="reset" onclick="resetForm()">Reset</button>
                </div>
              </form>

            </div>
          </div>
        </div>

        <div class="tab-pane fade <?php echo $activeTab==='my'?'show active':''; ?>" id="my-pane" role="tabpanel">
          <div class="card">
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr>
                      <th>#</th><th>Unit</th><th>From</th><th>To</th>
                      <th>Total</th><th>Type</th><th>Reason</th>
                      <th>Status</th><th>Submitted</th><th></th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($myRequests)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No leave requests yet.</td></tr>
                  <?php else: foreach ($myRequests as $r): ?>
                    <?php
                      $reasonCat = isset($r['reason_category']) ? str_replace('_',' ', $r['reason_category']) : '';
                      $reasonTxt = trim($r['reason_text'] ?? '');
                      $status    = strtolower($r['status'] ?? 'pending');
                      $badgeCls  = match($status){
                        'approved'  => 'badge-approved',
                        'rejected'  => 'badge-rejected',
                        'cancelled' => 'bg-secondary',
                        default     => 'badge-pending'
                      };
                      $submitted = !empty($r['created_at']) ? date('Y-m-d H:i', strtotime($r['created_at'])) : '';
                      $from      = trim(($r['start_date'] ?? '') . (!empty($r['start_time']) ? ' '.$r['start_time'] : ''));
                      $to        = trim(($r['end_date'] ?? '')   . (!empty($r['end_time'])   ? ' '.$r['end_time']   : ''));
                    ?>
                    <tr>
                      <td><?php echo (int)$r['id']; ?></td>
                      <td class="text-uppercase"><?php echo h($r['request_unit'] ?? ''); ?></td>
                      <td><?php echo h($from); ?></td>
                      <td><?php echo h($to); ?></td>
                      <td><?php echo h($r['total_units'] ?? '—'); ?></td>
                      <td><?php echo h(leave_type_label($r['leave_type'] ?? '')); ?></td>
                      <td>
                        <div><?php echo h(ucfirst($reasonCat)); ?></div>
                        <?php if ($reasonTxt !== ''): ?>
                          <div class="small text-muted"><?php echo h($reasonTxt); ?></div>
                        <?php endif; ?>
                      </td>
                      <td><span class="badge <?php echo $badgeCls; ?>"><?php echo h(ucfirst($status)); ?></span></td>
                      <td class="small text-muted"><?php echo h($submitted); ?></td>
                      <td style="white-space:nowrap;">
                        <?php if (!empty($r['attachment_path'])): ?>
                          <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($r['attachment_path']); ?>" target="_blank" title="View attachment">
                            <i class="fa-regular fa-file"></i>
                          </a>
                        <?php endif; ?>
                        <?php if ($status === 'pending'): ?>
                          <form method="post" class="d-inline" onsubmit="return confirm('Cancel this request?')">
                            <input type="hidden" name="cancel_id" value="<?php echo (int)$r['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Cancel">
                              <i class="fa-regular fa-circle-xmark"></i>
                            </button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.recalc = window.recalc || function(){};

const unitDays   = document.getElementById('uDays');
const unitHours  = document.getElementById('uHours');
const partialSel = document.getElementById('partial_day');
const timeWrap   = document.getElementById('timeWrap');
const otherWrap  = document.getElementById('otherWrap');
const totalBox   = document.getElementById('total_units');

const leaveTypeSel    = document.getElementById('leave_type');
const leaveTypeHelp   = document.getElementById('leave_type_help');
const attachHint      = document.getElementById('attachHint');
const attachBadge     = document.getElementById('attachBadge');
const attachStar      = document.getElementById('attachRequiredStar');
const attachmentInput = document.getElementById('attachment');

const supervisorSel   = document.getElementById('supervisor_id');
const supervisorHint  = document.getElementById('supervisorEmailHint');

const reasonSel       = document.getElementById('reason_category');
const reasonText      = document.getElementById('reason_text');
const otherStar       = document.getElementById('otherStar');

function toggleUnit(){
  const isHours = unitHours?.checked;
  document.getElementById('partialWrap')?.classList.toggle('d-none', !!isHours);
  timeWrap?.classList.toggle('d-none', !isHours && (partialSel?.value !== 'custom_hours'));
  recalc();
  updateSupportDocsUI();
}

function toggleOther(){
  const v = reasonSel?.value || '';
  const isOther = (v === 'other');

  otherWrap?.classList.toggle('d-none', !isOther);

  if (reasonText) {
    reasonText.required = isOther;
    reasonText.disabled = !isOther;
    if (!isOther) reasonText.value = '';
  }
  if (otherStar) otherStar.style.display = isOther ? 'inline' : 'none';
}

function updateSupervisorEmailUI(){
  const opt = supervisorSel?.options[supervisorSel.selectedIndex];
  const email = opt ? (opt.getAttribute('data-email') || '') : '';
  if (!opt || !supervisorSel.value) {
    supervisorHint.textContent = 'Select a supervisor to see the email address.';
    return;
  }
  supervisorHint.textContent = email ? ('Supervisor Email: ' + email) : 'Supervisor email not available.';
}

function calcDays(sd, ed, partial){
  if(!sd || !ed) return null;
  const d1 = new Date(sd), d2 = new Date(ed);
  if (d2 < d1) return null;
  let days = Math.floor((d2 - d1)/(1000*60*60*24)) + 1;
  if (partial === 'am' || partial === 'pm') days -= 0.5;
  return days;
}

function calcHours(sd, ed, st, et){
  if(!sd || !ed || !st || !et) return null;
  const h = (new Date(ed+'T'+et) - new Date(sd+'T'+st)) / 3600000;
  return h > 0 ? h : null;
}

function recalc(){
  const sd = document.getElementById('start_date')?.value || '';
  const ed = document.getElementById('end_date')?.value || '';
  const st = document.getElementById('start_time')?.value || '';
  const et = document.getElementById('end_time')?.value || '';
  let txt = '—';

  if (unitDays?.checked && sd && ed){
    const days = calcDays(sd, ed, partialSel?.value || 'none');
    if (days !== null){
      if (partialSel?.value === 'custom_hours' && st && et){
        const h = (new Date(sd+'T'+et) - new Date(sd+'T'+st)) / 3600000;
        txt = (h > 0 ? h.toFixed(2)+' hours' : '—');
        if (totalBox) totalBox.value = txt;
        return;
      }
      txt = days.toFixed(1) + ' days';
    }
  }

  if (unitHours?.checked && sd && ed && st && et){
    const h = calcHours(sd, ed, st, et);
    if (h !== null) txt = h.toFixed(2)+' hours';
  }

  if (totalBox) totalBox.value = txt;
}

function updateSupportDocsUI(){
  const opt = leaveTypeSel?.options[leaveTypeSel.selectedIndex];

  if (!opt || !leaveTypeSel.value) {
    leaveTypeHelp.textContent = '';
    attachHint.textContent = 'Select a leave type to see attachment requirements.';
    attachBadge.className = 'attach-badge optional';
    attachBadge.innerHTML = '<i class="fa-regular fa-file"></i> Optional';
    attachStar.style.display = 'none';
    attachmentInput.required = false;
    return;
  }

  const type = leaveTypeSel.value;
  const help = opt.getAttribute('data-help') || '';
  leaveTypeHelp.textContent = help;

  const requiredNow = !(['personal_day','family_responsibility'].includes(type));

  attachmentInput.required = requiredNow;
  attachStar.style.display = requiredNow ? 'inline' : 'none';

  if (requiredNow) {
    attachHint.textContent = 'Attachment is required for this leave type. Please upload PDF/JPG/PNG.';
    attachBadge.className = 'attach-badge required';
    attachBadge.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Required';
  } else {
    attachHint.textContent = 'Attachment is optional for this leave type.';
    attachBadge.className = 'attach-badge optional';
    attachBadge.innerHTML = '<i class="fa-regular fa-file"></i> Optional';
  }
}

partialSel?.addEventListener('change', ()=>{
  timeWrap?.classList.toggle('d-none', !(partialSel.value==='custom_hours' || unitHours?.checked));
  recalc();
  updateSupportDocsUI();
});

function resetForm(){
  setTimeout(()=>{
    toggleUnit();
    toggleOther();
    updateSupportDocsUI();
    updateSupervisorEmailUI();
    if(totalBox) totalBox.value='—';
  }, 0);
}

<?php if (in_array($activeTab,['apply','my'],true)): ?>
if (!location.hash || location.hash !== '#<?php echo $activeTab; ?>') {
  history.replaceState(null, '', '#<?php echo $activeTab; ?>');
}
<?php endif; ?>

document.querySelectorAll('[data-bs-toggle="pill"]').forEach(b =>
  b.addEventListener('shown.bs.tab', e => {
    const id = e.target.getAttribute('data-bs-target');
    history.replaceState(null,'', id.includes('my') ? '#my' : '#apply');
  })
);

toggleUnit();
toggleOther();
updateSupportDocsUI();
updateSupervisorEmailUI();
recalc();

document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

function applyTheme(mode) {
  const isDark = mode === 'dark' || mode === true || mode === 'true';
  document.body.classList.toggle('dark', isDark);
}

function getSavedTheme() {
  return localStorage.getItem('theme') || localStorage.getItem('mode') || (localStorage.getItem('darkMode') === 'true' ? 'dark' : 'light');
}

document.getElementById('toggle-dark').addEventListener('click', () => {
  const willBeDark = !document.body.classList.contains('dark');
  applyTheme(willBeDark ? 'dark' : 'light');
  localStorage.setItem('darkMode', willBeDark ? 'true' : 'false');
  localStorage.setItem('theme', willBeDark ? 'dark' : 'light');
});

applyTheme(getSavedTheme());

window.addEventListener('storage', function(e){
  if (e.key === 'theme' || e.key === 'mode' || e.key === 'darkMode') {
    applyTheme(getSavedTheme());
  }
});
</script>
</body>
</html>