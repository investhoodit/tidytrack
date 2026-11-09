<?php
// leader/leave_application.php — DARK MODE FIXED + EMAIL FIXED
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include '../dp.php';

$userId = $_SESSION['user_id'] ?? null;
$role   = strtolower($_SESSION['role'] ?? '');
$allowedRoles = ['leader','manager','admin'];
if (!$userId || !in_array($role, $allowedRoles, true)) {
  http_response_code(401);
  echo '<div style="padding:16px;border:1px solid #eee;border-radius:8px;background:#fff"><b>Unauthorized.</b> Please sign in as Leader/Manager/Admin.</div>';
  exit;
}

/* =========================================================
   MAIL CONFIG  ✅ same style as create task
========================================================= */
$MAIL_FROM  = "noreply@investhoodprojects.com";
$MAIL_REPLY = "noreply@investhoodprojects.com";
$APP_URL    = "https://investhoodprojects.com/tidytracker/tidytrack/";

/* =========================================================
   HELPERS
========================================================= */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ensure_upload_dir($path){ if (!is_dir($path)) @mkdir($path, 0755, true); return is_dir($path) && is_writable($path); }
function clean_text(string $s): string { $s = trim($s); $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s); return $s; }
function is_valid_email(string $email): bool { if ($email === '') return false; return (bool)filter_var($email, FILTER_VALIDATE_EMAIL); }
function digits_count(string $s): int { return strlen(preg_replace('/\D+/', '', $s)); }
function is_valid_phone(string $phone): bool { $phone = trim($phone); if ($phone === '') return true; if (!preg_match('/^\+?[0-9\s\-\(\)]{10,20}$/', $phone)) return false; $d = digits_count($phone); return ($d >= 10 && $d <= 12); }
function limit_len(string $s, int $min, int $max): bool { $len = mb_strlen($s); return ($len >= $min && $len <= $max); }

/* ✅ same email helper style as create task */
function sendLeaveMail($to, $subject, $body, $fromEmail, $replyToEmail, $ccEmail = ''){
  $headers = [];
  $headers[] = "From: Investhood Leave Portal <{$fromEmail}>";
  $headers[] = "Reply-To: {$replyToEmail}";
  if (trim($ccEmail) !== '') {
    $headers[] = "Cc: " . trim($ccEmail);
  }
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/plain; charset=UTF-8";
  $headers[] = "X-Mailer: PHP/" . phpversion();

  $headersStr = implode("\r\n", $headers);

  $ok = mail($to, $subject, $body, $headersStr, "-f {$fromEmail}");

  if (!$ok) {
    $err = error_get_last();
    error_log("LEAVE MAIL FAILED => to={$to} | subject={$subject} | err=" . ($err['message'] ?? 'unknown'));
  } else {
    error_log("LEAVE MAIL SENT => to={$to} | subject={$subject}");
  }

  return $ok;
}

define('YEARLY_ALLOWANCE_DAYS', 12);
define('MONTHLY_ACCRUAL_DAYS', 1);
define('WORKDAY_HOURS', 8);

function to_days_equivalent(float $total, string $unit): float { $unit = strtolower(trim($unit)); if ($unit === 'hours') return $total / WORKDAY_HOURS; return $total; }

function calc_total_units(array $in): array {
  $unit=$in['request_unit']??'days'; $sd=$in['start_date']??null; $ed=$in['end_date']??null; $st=$in['start_time']??null; $et=$in['end_time']??null; $partial=$in['partial_day']??'none';
  if (!$sd||!$ed) return [false,null,$unit,"Start/End date required"];
  $d1=strtotime($sd.' 00:00:00'); $d2=strtotime($ed.' 00:00:00');
  if (!$d1||!$d2) return [false,null,$unit,"Invalid date format"];
  if ($d2<$d1) return [false,null,$unit,"End date cannot be before start date"];
  if ($unit==='days') {
    $days=floor(($d2-$d1)/86400)+1;
    if (in_array($partial,['am','pm'],true)) $days-=0.5;
    if ($partial==='custom_hours') { if (!$st||!$et) return [false,null,$unit,"Custom hours requires start & end time"]; $t1=strtotime("$sd $st"); $t2=strtotime("$sd $et"); if (!$t1||!$t2) return [false,null,$unit,"Invalid time format"]; if ($t2<=$t1) return [false,null,$unit,"End time must be after start time"]; $h=($t2-$t1)/3600; return [true,round($h,2),'hours',null]; }
    return [true,round(max(0,$days),2),'days',null];
  } else {
    if (!$st||!$et) return [false,null,$unit,"Hours unit requires start & end time"];
    $t1=strtotime("$sd $st"); $t2=strtotime("$ed $et");
    if (!$t1||!$t2) return [false,null,$unit,"Invalid date/time format"];
    if ($t2<=$t1) return [false,null,$unit,"End date/time must be after start date/time"];
    $h=($t2-$t1)/3600; return [true,round($h,2),'hours',null];
  }
}

function leave_type_label(string $code): string {
  return match($code){ 'personal_day'=>'Personal Day','sick'=>'Sick Leave','annual_leave'=>'Annual Leave','family_responsibility'=>'Family Responsibility Leave','study'=>'Study Leave','unpaid'=>'Unpaid Leave',default=>$code };
}
function attachment_rule(string $leaveType, string $resolvedUnit, float $total): array {
  $leaveType=strtolower(trim($leaveType));
  if (in_array($leaveType,['family_responsibility','personal_day'],true)) return [false,'Attachment is optional for this leave type.'];
  return [true,'Attachment is required for this leave type. Please upload a supporting document (PDF/JPG/PNG).'];
}

$me=['display'=>'User','department'=>null,'email'=>null];
$deptCol=null;
if ($res=$conn->query("SHOW COLUMNS FROM users LIKE 'department'")) { if ($res->num_rows>0) $deptCol='department'; $res->free(); }
if (!$deptCol) { if ($res=$conn->query("SHOW COLUMNS FROM users LIKE 'deparment'")) { if ($res->num_rows>0) $deptCol='deparment'; $res->free(); } }
$deptSelect=$deptCol?$deptCol:"NULL";
$sqlMe="SELECT COALESCE(NULLIF(name,''),email) AS display, $deptSelect AS department, email FROM users WHERE user_id=?";
if ($stmt=$conn->prepare($sqlMe)) { $stmt->bind_param('i',$userId); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); if ($row) $me=array_merge($me,$row); $stmt->close(); }
$deptMissing=empty(trim((string)($me['department']??'')));

$supervisors=[]; $supEmailMap=[];
$sqlSup="SELECT user_id, COALESCE(NULLIF(name,''),email) AS n, email FROM users WHERE LOWER(role) IN ('manager') ORDER BY n";
if ($res=$conn->query($sqlSup)) { $supervisors=$res->fetch_all(MYSQLI_ASSOC); foreach($supervisors as $s){ $sid=(int)($s['user_id']??0); if($sid) $supEmailMap[$sid]=(string)($s['email']??''); } $res->free(); }

$shape='ok';
$tblCheck=$conn->query("SHOW TABLES LIKE 'leave_requests'");
if (!$tblCheck||$tblCheck->num_rows===0) $shape='none';

function allowed_days_by_date(string $dateStr): float { $ts=strtotime($dateStr); if (!$ts) return 0.0; $month=(int)date('n',$ts); $allowed=$month*(float)MONTHLY_ACCRUAL_DAYS; return (float)min((float)YEARLY_ALLOWANCE_DAYS,$allowed); }

function used_days_in_range(mysqli $conn, int $userId, string $rangeStart, string $rangeEnd): float {
  $sql="SELECT COALESCE(SUM(CASE WHEN LOWER(lr.request_unit)='hours' THEN (COALESCE(lr.total_units,0)/?) ELSE (CASE WHEN lr.end_date<? OR lr.start_date>? THEN 0 ELSE (DATEDIFF(LEAST(lr.end_date,?),GREATEST(lr.start_date,?))+1) END - CASE WHEN LOWER(COALESCE(lr.partial_day,'')) IN ('am','pm') AND lr.start_date BETWEEN ? AND ? THEN 0.5 ELSE 0 END) END),0) AS used_days FROM leave_requests lr WHERE lr.employee_id=? AND LOWER(lr.status) IN ('approved','pending') AND NOT (lr.end_date<? OR lr.start_date>?)";
  $used=0.0;
  if ($st=$conn->prepare($sql)) { $wh=(float)WORKDAY_HOURS; $st->bind_param('dsssssssis',$wh,$rangeStart,$rangeEnd,$rangeEnd,$rangeStart,$rangeStart,$rangeEnd,$userId,$rangeStart,$rangeEnd); $st->execute(); $row=$st->get_result()->fetch_assoc(); $used=(float)($row['used_days']??0); $st->close(); }
  return $used;
}

$usedToDate=0.0; $availableToDate=0.0; $allowedToDate=0.0; $usedYearTotal=0.0;
if ($shape!=='none') {
  $year=(int)date('Y'); $yearStart=$year.'-01-01'; $yearEnd=$year.'-12-31'; $today=date('Y-m-d');
  $usedToDate=used_days_in_range($conn,$userId,$yearStart,$today);
  $allowedToDate=allowed_days_by_date($today);
  $availableToDate=max(0.0,$allowedToDate-$usedToDate);
  $usedYearTotal=used_days_in_range($conn,$userId,$yearStart,$yearEnd);
}

$OLD=($_SERVER['REQUEST_METHOD']==='POST')?$_POST:[];
function oldv(string $key,$default=''){ global $OLD; return $OLD[$key]??$default; }
function oldh(string $key,$default=''){ return h(oldv($key,$default)); }
function selected_if($val,$current): string { return ((string)$val===(string)$current)?'selected':''; }
function checked_if($val,$current): string { return ((string)$val===(string)$current)?'checked':''; }

$flash=null; $activeTab='apply';

if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['submit_leave'])) {
  if ($shape==='none') { $flash=['err','Leave table not found. Please create `leave_requests` first.']; }
  else {
    if ($deptMissing) { $flash=['err','Department is missing. Please update your profile.']; }
    else {
      $in=['request_unit'=>$_POST['request_unit']??'days','start_date'=>$_POST['start_date']??'','end_date'=>$_POST['end_date']??'','start_time'=>$_POST['start_time']??null,'end_time'=>$_POST['end_time']??null,'partial_day'=>$_POST['partial_day']??'none','leave_type'=>$_POST['leave_type']??'','reason_category'=>$_POST['reason_category']??'','reason_text'=>clean_text($_POST['reason_text']??''),'contact_number'=>clean_text($_POST['contact_number']??''),'contact_email'=>clean_text($_POST['contact_email']??''),'leave_address'=>clean_text($_POST['leave_address']??''),'handover_notes'=>clean_text($_POST['handover_notes']??''),'cc_email'=>clean_text($_POST['cc_email']??''),'supervisor_id'=>(int)($_POST['supervisor_id']??0)];
      if ($in['contact_email']===''&&!empty($me['email'])) $in['contact_email']=(string)$me['email'];
      if (!$in['supervisor_id']) { $flash=['err','Please select a supervisor.']; }
      elseif (!$in['leave_type']||!$in['reason_category']) { $flash=['err','Please select leave type and reason category.']; }
      elseif ($in['reason_category']==='other'&&$in['reason_text']==='') { $flash=['err',"Please fill in 'Reason details' because you selected Other."]; }
      elseif ($in['reason_text']!==''&&!limit_len($in['reason_text'],3,500)) { $flash=['err',"Reason details must be between 3 and 500 characters."]; }
      elseif (!is_valid_phone($in['contact_number'])) { $flash=['err',"Contact number is invalid."]; }
      elseif ($in['contact_email']!==''&&!is_valid_email($in['contact_email'])) { $flash=['err',"Contact email is invalid."]; }
      elseif ($in['cc_email']!==''&&!is_valid_email($in['cc_email'])) { $flash=['err',"CC email is invalid."]; }
      elseif ($in['leave_address']!==''&&!limit_len($in['leave_address'],3,120)) { $flash=['err',"Address during leave must be between 3 and 120 characters."]; }
      elseif ($in['handover_notes']!==''&&!limit_len($in['handover_notes'],3,500)) { $flash=['err',"Handover notes must be between 3 and 500 characters."]; }
      elseif (($_POST['ack_policy']??'')!=='1') { $flash=['err','Please open the policy and click "Accept Policy" before submitting.']; }
      else {
        [$ok,$total,$resolvedUnit,$err]=calc_total_units(array_merge($in,$_POST));
        if (!$ok) { $flash=['err',$err?:'Invalid dates/times']; }
        else {
          $requestedDaysEq=to_days_equivalent((float)$total,(string)$resolvedUnit);
          $reqEnd=$in['end_date']?:date('Y-m-d'); $reqYear=(int)date('Y',strtotime($reqEnd)); $curYear=(int)date('Y');
          if ($shape!=='none'&&$reqYear===$curYear) {
            $yearStart=$curYear.'-01-01'; $allowedAtEnd=allowed_days_by_date($reqEnd); $usedUntilEnd=used_days_in_range($conn,$userId,$yearStart,$reqEnd); $remainingAtEnd=max(0.0,$allowedAtEnd-$usedUntilEnd);
            if ($requestedDaysEq>$remainingAtEnd) { $flash=['err','Leave request exceeds your accrued balance. Accrued by '.h($reqEnd).': '.round($allowedAtEnd,2).' • Used: '.round($usedUntilEnd,2).' • Available: '.round($remainingAtEnd,2).' • Requested: '.round($requestedDaysEq,2)]; }
          }
          if (!$flash) { [$attachRequired,$attachHelper]=attachment_rule($in['leave_type'],$resolvedUnit,(float)$total); $hasFile=!empty($_FILES['attachment']['name']); if ($attachRequired&&!$hasFile) { $flash=['err',$attachHelper]; } }
          $savedPath=null;
          if (!$flash&&!empty($_FILES['attachment']['name'])) {
            $u=$_FILES['attachment'];
            if ($u['error']===UPLOAD_ERR_OK) {
              $allowed=['application/pdf','image/jpeg','image/png']; $mime=mime_content_type($u['tmp_name']);
              if (!in_array($mime,$allowed,true)) { $flash=['err','Attachment must be PDF/JPG/PNG.']; }
              elseif ($u['size']>5*1024*1024) { $flash=['err','Attachment too large (max 5MB).']; }
              else { $dir=__DIR__.'/uploads/leave'; if (!ensure_upload_dir($dir)) { $flash=['err','Upload folder is not writable.']; } else { $ext=strtolower(pathinfo($u['name'],PATHINFO_EXTENSION)); $fname='leave_'.$userId.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.'.$ext; $dest=$dir.'/'.$fname; if (!move_uploaded_file($u['tmp_name'],$dest)) { $flash=['err','Failed to save attachment.']; } else { $savedPath='uploads/leave/'.$fname; } } }
            } elseif ($u['error']!==UPLOAD_ERR_NO_FILE) { $flash=['err','Upload error code: '.$u['error']]; }
          }
          if (!$flash) {
            $sql="INSERT INTO leave_requests (employee_id,supervisor_id,request_unit,start_date,end_date,start_time,end_time,partial_day,leave_type,reason_category,reason_text,contact_number,contact_email,leave_address,handover_notes,cc_email,attachment_path,total_units,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending')";
            $stmt=$conn->prepare($sql); $st=$in['start_time']?:null; $et=$in['end_time']?:null; $par=$in['partial_day']?:'none'; $ru=$resolvedUnit==='hours'?'hours':$in['request_unit'];
            $stmt->bind_param('iisssssssssssssssd',$userId,$in['supervisor_id'],$ru,$in['start_date'],$in['end_date'],$st,$et,$par,$in['leave_type'],$in['reason_category'],$in['reason_text'],$in['contact_number'],$in['contact_email'],$in['leave_address'],$in['handover_notes'],$in['cc_email'],$savedPath,$total);

            if ($stmt->execute()) {
              $supervisorEmail = trim((string)($supEmailMap[$in['supervisor_id']] ?? ''));

              if ($supervisorEmail === '') {
                error_log("LEAVE MAIL FAILED => empty supervisor email for supervisor_id=" . $in['supervisor_id']);
                $flash=['ok','Leave request submitted successfully, but the supervisor email is missing.'];
              } else {
                $subject = "New Leave Request Submitted";

                $messageBody =
                  "Good Day,\n\n" .
                  "A new leave request has been submitted.\n\n" .
                  "Employee: " . ($me['display'] ?? 'Unknown') . "\n" .
                  "Department: " . ($me['department'] ?? 'N/A') . "\n" .
                  "Leave Type: " . leave_type_label($in['leave_type']) . "\n" .
                  "Reason: " . ($in['reason_text'] !== '' ? $in['reason_text'] : $in['reason_category']) . "\n" .
                  "From: " . $in['start_date'] . "\n" .
                  "To: " . $in['end_date'] . "\n" .
                  "Total: " . $total . " " . $resolvedUnit . "\n";

                if (!empty($in['contact_number'])) {
                  $messageBody .= "Contact Number: " . $in['contact_number'] . "\n";
                }
                if (!empty($in['contact_email'])) {
                  $messageBody .= "Contact Email: " . $in['contact_email'] . "\n";
                }
                if (!empty($in['leave_address'])) {
                  $messageBody .= "Address During Leave: " . $in['leave_address'] . "\n";
                }
                if (!empty($in['handover_notes'])) {
                  $messageBody .= "Handover Notes: " . $in['handover_notes'] . "\n";
                }

                $messageBody .=
                  "\nPlease log in to the system to review the request:\n" .
                  $APP_URL . "\n\n" .
                  "Best regards,\n" .
                  "Investhood Leave Management System\n";

                $replyTo = !empty($me['email']) ? (string)$me['email'] : $MAIL_REPLY;

                $sent = sendLeaveMail(
                  $supervisorEmail,
                  $subject,
                  $messageBody,
                  $MAIL_FROM,
                  $replyTo,
                  $in['cc_email'] ?? ''
                );

                if (!$sent) {
                  $flash=['ok','Leave request submitted successfully, BUT email could not be sent. Supervisor: '.$supervisorEmail];
                } else {
                  $flash=['ok','Leave request submitted successfully. Email sent to supervisor at: '.$supervisorEmail];
                }
              }

              $activeTab='my'; $OLD=[];
            } else {
              $flash=['err','Database error: '.$stmt->error];
            }
            $stmt->close();
          }
        }
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['cancel_id'])) {
  $cancelId=(int)$_POST['cancel_id'];
  if ($cancelId) {
    $sql="UPDATE leave_requests SET status='cancelled' WHERE id=? AND employee_id=? AND status='pending'";
    $stmt=$conn->prepare($sql); $stmt->bind_param('ii',$cancelId,$userId);
    if ($stmt->execute()&&$stmt->affected_rows>0) { $flash=['ok','Request cancelled.']; } else { $flash=['err','Unable to cancel (already processed or not found).']; }
    $stmt->close(); $activeTab='my';
  }
}

$myRequests=[];
if ($shape!=='none') { $q="SELECT * FROM leave_requests WHERE employee_id=? ORDER BY created_at DESC"; $stmt=$conn->prepare($q); $stmt->bind_param('i',$userId); $stmt->execute(); $myRequests=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); }

$oldUnit=oldv('request_unit','days'); $oldPartial=oldv('partial_day','none'); $oldLeave=oldv('leave_type',''); $oldReason=oldv('reason_category',''); $oldSup=oldv('supervisor_id','');
$showOther=($oldReason==='other'); $isHoursUI=($oldUnit==='hours'); $showTimeUI=($isHoursUI||$oldPartial==='custom_hours');
$oldPolicyAccepted=(oldv('ack_policy','0')==='1');
?>
<!doctype html>
<html lang="en-ZA">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Leave Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #f6f8fb;
      --surface: #ffffff;
      --surface-alt: #f3f5f9;
      --surface-sunken: #f8fafc;
      --text: #1f2937;
      --text-soft: #374151;
      --muted: #6b7280;
      --accent: #1d3557;
      --accent-hover: #162a44;
      --border: #e5e7eb;
      --line: #eef2f7;
      --shadow: 0 4px 14px rgba(13,16,23,.06);
      --radius: 16px;
      --radius-sm: 8px;
      --input-bg: #ffffff;
      --input-text: #1f2937;
      --input-placeholder: #9ca3af;
      --input-border: #d1d5db;
      --pill-bg: #ffffff;
      --pill-text: #1d3557;
      --pill-border: #d1d5db;
      --pill-active-bg: #1d3557;
      --pill-active-text: #ffffff;
      --table-head-bg: #f3f5f9;
      --table-head-text: #334155;
      --table-hover: #f8fafc;
      --table-border: #e5e7eb;
      --badge-pending-bg: #fef3c7;  --badge-pending-text: #92400e;
      --badge-approved-bg: #d1fae5; --badge-approved-text: #065f46;
      --badge-rejected-bg: #fee2e2; --badge-rejected-text: #991b1b;
      --badge-cancel-bg: #f3f4f6;   --badge-cancel-text: #4b5563;
      --attach-req-bg: #fff1f2;   --attach-req-border: #fecaca;   --attach-req-text: #991b1b;
      --attach-opt-bg: #eff6ff;   --attach-opt-border: #dbeafe;   --attach-opt-text: #1e40af;
      --attach-ok-bg: #f0fdf4;    --attach-ok-border: #bbf7d0;    --attach-ok-text: #166534;
      --alert-info-bg: #eff6ff;   --alert-info-border: #bfdbfe;   --alert-info-text: #1e3a5f;
      --alert-ok-bg: #f0fdf4;     --alert-ok-border: #bbf7d0;     --alert-ok-text: #166534;
      --alert-err-bg: #fff1f2;    --alert-err-border: #fecaca;    --alert-err-text: #991b1b;
      --alert-warn-bg: #fef3c7;   --alert-warn-border: #fcd34d;   --alert-warn-text: #92400e;
    }

    body.dark {
      --bg: #0d1117;
      --surface: #161b22;
      --surface-alt: #1c2128;
      --surface-sunken: #0d1117;
      --text: #e6edf3;
      --text-soft: #c9d1d9;
      --muted: #8b949e;
      --accent: #58a6ff;
      --accent-hover: #388bfd;
      --border: #30363d;
      --line: #21262d;
      --shadow: 0 4px 20px rgba(0,0,0,.5);
      --input-bg: #0d1117;
      --input-text: #e6edf3;
      --input-placeholder: #6e7681;
      --input-border: #30363d;
      --pill-bg: #161b22;
      --pill-text: #58a6ff;
      --pill-border: #30363d;
      --pill-active-bg: #58a6ff;
      --pill-active-text: #0d1117;
      --table-head-bg: #1c2128;
      --table-head-text: #c9d1d9;
      --table-hover: #1c2128;
      --table-border: #21262d;
      --badge-pending-bg: #3d2e00;  --badge-pending-text: #fbbf24;
      --badge-approved-bg: #003820; --badge-approved-text: #34d399;
      --badge-rejected-bg: #3d0000; --badge-rejected-text: #f87171;
      --badge-cancel-bg: #1c2128;   --badge-cancel-text: #8b949e;
      --attach-req-bg: #2d0000;   --attach-req-border: #7f1d1d;   --attach-req-text: #fca5a5;
      --attach-opt-bg: #0c1e3d;   --attach-opt-border: #1e3a5f;   --attach-opt-text: #93c5fd;
      --attach-ok-bg: #022c15;    --attach-ok-border: #14532d;    --attach-ok-text: #86efac;
      --alert-info-bg: #0c1e3d;   --alert-info-border: #1e3a5f;   --alert-info-text: #93c5fd;
      --alert-ok-bg: #022c15;     --alert-ok-border: #14532d;     --alert-ok-text: #86efac;
      --alert-err-bg: #2d0000;    --alert-err-border: #7f1d1d;    --alert-err-text: #fca5a5;
      --alert-warn-bg: #3d2e00;   --alert-warn-border: #92400e;   --alert-warn-text: #fbbf24;
    }

    html { min-height:100%; }
    body { margin:0; padding:0; min-height:100vh; background:var(--bg); color:var(--text); font-family:"Poppins",system-ui,sans-serif; -webkit-font-smoothing:antialiased; transition:background .25s,color .25s; }
    main { max-width:1100px; margin:40px auto; padding:0 16px; }
    .page-title { text-align:center; font-size:clamp(1.8rem,2.6vw,2.2rem); font-weight:600; color:var(--accent); margin-bottom:10px; }
    .page-subtitle { text-align:center; color:var(--muted); margin-bottom:22px; font-size:.98rem; }
    .heading-accent { height:4px; width:120px; margin:0 auto 26px; background:linear-gradient(90deg,var(--accent) 0%,#2a9d8f 100%); border-radius:999px; }
    label, .form-label { font-weight:700; margin-bottom:6px; color:var(--text); }
    .hint { font-size:.85rem; color:var(--muted); }
    .muted { color:var(--muted); }
    small { color:var(--muted); }
    b, strong { color:inherit; }
    hr { border-color:var(--line) !important; opacity:1; }
    code { color:var(--accent) !important; background:transparent; padding:1px 4px; border-radius:4px; border:1px solid var(--border); }
    .card { border:1px solid var(--line) !important; border-radius:var(--radius) !important; box-shadow:var(--shadow); background:var(--surface) !important; color:var(--text) !important; }
    .card .card-body { padding:22px; background:var(--surface) !important; color:var(--text) !important; }
    .tab-pane { background:transparent; }
    .tab-content { background:transparent; }
    .nav-pills .nav-link { border-radius:12px; font-weight:600; color:var(--pill-text) !important; border:1px solid var(--pill-border); background:var(--pill-bg) !important; transition:all .2s; }
    .nav-pills .nav-link.active { background:var(--pill-active-bg) !important; color:var(--pill-active-text) !important; border-color:var(--pill-active-bg); }
    .form-control, .form-select {
      border:1px solid var(--input-border) !important;
      border-radius:var(--radius-sm) !important;
      padding:10px 12px !important;
      box-shadow:none !important;
      background:var(--input-bg) !important;
      color:var(--input-text) !important;
      transition:border-color .2s, background .2s, color .2s;
    }
    .form-control::placeholder { color:var(--input-placeholder) !important; }
    .form-control:focus, .form-select:focus { outline:2px solid var(--accent) !important; border-color:var(--accent) !important; }
    body.dark input[type="date"].form-control,
    body.dark input[type="time"].form-control {
      color-scheme: dark;
      background:var(--input-bg) !important;
      color:var(--input-text) !important;
    }
    body.dark select.form-select {
      filter: none;
    }
    body.dark .form-select,
    body.dark select.form-select {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%2358a6ff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3E%3C/svg%3E") !important;
      background-repeat: no-repeat !important;
      background-position: right 0.75rem center !important;
      background-size: 16px 12px !important;
    }
    body.dark input[type="file"].form-control { color:var(--text) !important; }
    body.dark input[type="file"].form-control::file-selector-button { background:var(--surface-alt) !important; color:var(--text) !important; border-color:var(--border) !important; }
    .form-control[readonly], .form-control:disabled { background:var(--surface-alt) !important; color:var(--muted) !important; opacity:1; }
    .btn { border-radius:var(--radius-sm) !important; font-weight:600; transition:all .2s; }
    .btn-primary { background:var(--accent) !important; border-color:var(--accent) !important; color:#fff !important; }
    body.dark .btn-primary { color:#0d1117 !important; }
    .btn-primary:hover { background:var(--accent-hover) !important; border-color:var(--accent-hover) !important; }
    .btn-outline-primary { border-color:var(--accent) !important; color:var(--accent) !important; background:transparent !important; font-weight:600; }
    .btn-outline-primary:hover { background:var(--accent) !important; color:var(--surface) !important; }
    .btn-outline-secondary { border-color:var(--border) !important; color:var(--text-soft) !important; background:transparent !important; }
    .btn-outline-secondary:hover { background:var(--surface-alt) !important; color:var(--text) !important; }
    .btn-outline-danger { border-color:var(--badge-rejected-text) !important; color:var(--badge-rejected-text) !important; background:transparent !important; }
    .btn-outline-danger:hover { background:var(--badge-rejected-bg) !important; }
    body.dark .btn-close { filter:invert(1) grayscale(1); }
    .alert { border-radius:var(--radius-sm); border:1px solid; }
    .alert-success { background:var(--alert-ok-bg) !important; border-color:var(--alert-ok-border) !important; color:var(--alert-ok-text) !important; }
    .alert-danger  { background:var(--alert-err-bg) !important; border-color:var(--alert-err-border) !important; color:var(--alert-err-text) !important; }
    .alert-warning { background:var(--alert-warn-bg) !important; border-color:var(--alert-warn-border) !important; color:var(--alert-warn-text) !important; }
    .alert-info    { background:var(--alert-info-bg) !important; border-color:var(--alert-info-border) !important; color:var(--alert-info-text) !important; }
    .alert b, .alert strong { color:inherit !important; }
    .chip { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .6rem; border-radius:999px; border:1px solid var(--line); background:var(--surface); color:var(--text); font-size:.85rem; }
    .chip label { color:var(--text) !important; font-weight:500; margin:0; }
    .table-responsive { border:1px solid var(--table-border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow); background:var(--surface); }
    table.table { margin:0; }
    .table {
      color:var(--text) !important;
      --bs-table-bg: transparent;
      --bs-table-color: var(--text);
      --bs-table-border-color: var(--table-border);
      --bs-table-hover-bg: var(--table-hover);
      --bs-table-hover-color: var(--text);
    }
    .table thead th { background:var(--table-head-bg) !important; color:var(--table-head-text) !important; font-weight:700; border-bottom:1px solid var(--table-border) !important; }
    .table tbody td, .table tbody th { background:var(--surface) !important; color:var(--text) !important; border-color:var(--table-border) !important; }
    .table-hover tbody tr:hover td, .table-hover tbody tr:hover th { background:var(--table-hover) !important; color:var(--text) !important; }
    .table .small, .table .text-muted { color:var(--muted) !important; }
    .badge { font-size:.8rem; font-weight:600; padding:.3em .65em; border-radius:999px; }
    .badge-pending  { background:var(--badge-pending-bg) !important;  color:var(--badge-pending-text) !important; }
    .badge-approved { background:var(--badge-approved-bg) !important; color:var(--badge-approved-text) !important; }
    .badge-rejected { background:var(--badge-rejected-bg) !important; color:var(--badge-rejected-text) !important; }
    .bg-secondary   { background:var(--badge-cancel-bg) !important;   color:var(--badge-cancel-text) !important; }
    .attach-badge { display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .55rem; border-radius:999px; font-size:.82rem; white-space:nowrap; border:1px solid; transition:all .2s; }
    .attach-badge.required { border-color:var(--attach-req-border); background:var(--attach-req-bg); color:var(--attach-req-text); }
    .attach-badge.optional { border-color:var(--attach-opt-border); background:var(--attach-opt-bg); color:var(--attach-opt-text); }
    .attach-badge.ok       { border-color:var(--attach-ok-border);  background:var(--attach-ok-bg);  color:var(--attach-ok-text);  }
    .policy-box { border:1px dashed var(--border); border-radius:12px; padding:14px; background:var(--surface); color:var(--text); }
    .policy-box .hint { color:var(--muted); }
    .policy-summary { border:1px solid var(--line); border-radius:12px; padding:14px; background:var(--surface-sunken); color:var(--text); }
    .policy-summary li, .policy-summary b { color:var(--text) !important; }
    .modal-content { border-radius:16px !important; background:var(--surface) !important; border:1px solid var(--border) !important; color:var(--text) !important; }
    .modal-header  { background:var(--surface) !important; border-bottom:1px solid var(--border) !important; }
    .modal-body    { background:var(--surface) !important; color:var(--text) !important; }
    .modal-footer  { background:var(--surface) !important; border-top:1px solid var(--border) !important; }
    .modal-title   { color:var(--accent) !important; font-weight:800; }
    .invalid-feedback { color:var(--attach-req-text) !important; }
    body.dark .was-validated .form-control:invalid,
    body.dark .form-control.is-invalid {
      border-color: var(--badge-rejected-text) !important;
      background: var(--input-bg) !important;
      color: var(--input-text) !important;
    }
    body.dark .was-validated .form-control:valid,
    body.dark .form-control.is-valid {
      border-color: var(--badge-approved-text) !important;
      background: var(--input-bg) !important;
      color: var(--input-text) !important;
    }
    body.dark .was-validated .form-select:valid,
    body.dark .form-select.is-valid {
      border-color: var(--badge-approved-text) !important;
      background-color: var(--input-bg) !important;
      color: var(--input-text) !important;
    }
    body.dark .was-validated .form-select:invalid,
    body.dark .form-select.is-invalid {
      border-color: var(--badge-rejected-text) !important;
      background-color: var(--input-bg) !important;
      color: var(--input-text) !important;
    }
    body.dark input.form-control:-webkit-autofill,
    body.dark input.form-control:-webkit-autofill:hover,
    body.dark input.form-control:-webkit-autofill:focus,
    body.dark input.form-control:-webkit-autofill:active {
      -webkit-box-shadow: 0 0 0 1000px var(--input-bg) inset !important;
      -webkit-text-fill-color: var(--input-text) !important;
      caret-color: var(--input-text) !important;
      border-color: var(--input-border) !important;
    }
    body.dark input[type="tel"].form-control,
    body.dark input[type="text"].form-control,
    body.dark input[type="email"].form-control,
    body.dark input[type="number"].form-control,
    body.dark input[type="password"].form-control,
    body.dark textarea.form-control {
      background: var(--input-bg) !important;
      color: var(--input-text) !important;
      border-color: var(--input-border) !important;
    }
    body.dark input.form-control.is-valid:-webkit-autofill,
    body.dark input.form-control.is-valid:-webkit-autofill:focus {
      -webkit-box-shadow: 0 0 0 1000px var(--input-bg) inset !important;
      -webkit-text-fill-color: var(--input-text) !important;
      border-color: var(--badge-approved-text) !important;
    }
    #toggle-dark { position:fixed; top:15px; right:15px; z-index:999; background:#1f2937; border:1px solid #374151; color:#e5e7eb; padding:10px 12px; border-radius:var(--radius-sm); cursor:pointer; transition:all .2s; }
    body.dark #toggle-dark { background:#e5e7eb; color:#111827; border-color:#d1d5db; }
    .req-dot { color:#ef4444; font-weight:800; }
  </style>
</head>
<body>
<main>
  <h1 class="page-title"><i class="fa-regular fa-calendar-check me-2"></i>Leave Portal</h1>
  <div class="page-subtitle">Hi, <?php echo h($me['display']); ?> &bull; Submit and track leave requests</div>
  <div class="heading-accent"></div>

  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <div class="muted">
      <span class="chip"><i class="fa-solid fa-building"></i> Department: <?php echo h($me['department']??''); ?></span>
      <span class="chip ms-2" data-bs-toggle="tooltip" title="Department is pulled from your profile/bio.">
        <i class="fa-solid fa-circle-info"></i> Profile-synced
      </span>
    </div>
    <div class="d-flex gap-2">
      <a href="profile.php" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-user-gear me-1"></i> Profile</a>
      <a href="leader_index.php" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-arrow-left me-1"></i> Dashboard</a>
    </div>
  </div>

  <?php if ($deptMissing): ?>
    <div class="alert alert-warning"><b>Department is pulled from your profile.</b> Please update your profile if missing.<div class="hint mt-1">Submission is disabled until a department is available.</div></div>
  <?php endif; ?>
  <?php if (!empty($flash)): ?>
    <div class="alert <?php echo $flash[0]==='ok'?'alert-success':'alert-danger'; ?>"><?php echo h($flash[1]); ?></div>
  <?php endif; ?>
  <?php if ($shape==='none'): ?>
    <div class="alert alert-danger">No leave table found. Create <code>leave_requests</code>.</div>
  <?php endif; ?>

  <ul class="nav nav-pills gap-2 mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link <?php echo $activeTab==='apply'?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#apply-pane" type="button"><i class="fa-regular fa-file-lines me-1"></i> Apply</button></li>
    <li class="nav-item"><button class="nav-link <?php echo $activeTab==='my'?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#my-pane" type="button"><i class="fa-solid fa-folder-open me-1"></i> My Requests</button></li>
  </ul>

  <button id="toggle-dark" type="button" title="Toggle dark mode"><i class="fas fa-moon" id="darkIcon"></i></button>

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
              <input class="form-control" value="<?php echo h($me['department']??''); ?>" readonly>
              <div class="hint"><i class="fa-solid fa-circle-info me-1"></i>Pulled from your profile/bio.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Supervisor <span class="req-dot">*</span></label>
              <select name="supervisor_id" id="supervisor_id" form="leaveForm" class="form-select" required onchange="updateSupervisorEmailUI()">
                <option value="">-- Select --</option>
                <?php foreach($supervisors as $s): $sid=(int)($s['user_id']??0); $sn=(string)($s['n']??''); $se=(string)($s['email']??''); $label=$se?($sn." (".$se.")"):$sn; ?>
                <option value="<?php echo $sid; ?>" data-email="<?php echo h($se); ?>" <?php echo selected_if((string)$sid,(string)$oldSup); ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback">Please select a supervisor.</div>
              <div class="hint mt-1" id="supervisorEmailHint">Select a supervisor to see the email address.</div>
              <div class="hint">Your request will be routed to this person for approval.</div>
            </div>
          </div>

          <hr class="my-4">

          <form id="leaveForm" method="post" enctype="multipart/form-data" class="row g-3 needs-validation" novalidate>
            <input type="hidden" name="submit_leave" value="1">

            <div class="col-12">
              <div class="alert alert-info mb-0">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div style="font-weight:800;"><i class="fa-solid fa-calculator me-1"></i>Leave Balance (Accrual &bull; <?php echo date('Y'); ?>)</div>
                  <div class="small">Accrues: <b><?php echo MONTHLY_ACCRUAL_DAYS; ?></b> day/month &bull; Max: <b><?php echo YEARLY_ALLOWANCE_DAYS; ?></b>/year &bull; Used (pending+approved): <b><?php echo round($usedToDate,2); ?></b> &bull; Available now: <b><?php echo round($availableToDate,2); ?></b></div>
                </div>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">Leave Units <i class="fa-solid fa-circle-info ms-1" style="color:var(--muted)" data-bs-toggle="tooltip" title="Full day is auto-calculated from dates. Hours triggers partial day."></i></label>
              <div class="d-flex flex-wrap gap-2">
                <span class="chip"><input class="form-check-input me-1" type="radio" name="request_unit" value="days" id="uDays" <?php echo checked_if('days',$oldUnit); ?> onclick="toggleUnit()"><label class="form-check-label" for="uDays">Days (Full Day)</label></span>
                <span class="chip"><input class="form-check-input me-1" type="radio" name="request_unit" value="hours" id="uHours" <?php echo checked_if('hours',$oldUnit); ?> onclick="toggleUnit()"><label class="form-check-label" for="uHours">Hours (Partial Day)</label></span>
              </div>
              <div class="hint mt-1">Full Day is auto-calculated from dates. Hours triggers Partial Day.</div>
            </div>

            <div class="col-md-3">
              <label class="form-label">From <span class="req-dot">*</span></label>
              <input type="date" class="form-control" name="start_date" id="start_date" required value="<?php echo oldh('start_date'); ?>" oninput="recalc();updateSupportDocsUI();">
              <div class="invalid-feedback">Please select a valid start date.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label">To <span class="req-dot">*</span></label>
              <input type="date" class="form-control" name="end_date" id="end_date" required value="<?php echo oldh('end_date'); ?>" oninput="recalc();updateSupportDocsUI();">
              <div class="invalid-feedback">Please select a valid end date.</div>
            </div>

            <div class="col-md-3" id="partialWrap" <?php echo $isHoursUI?'style="display:none"':''; ?>>
              <label class="form-label">Partial Day <i class="fa-solid fa-circle-info ms-1" style="color:var(--muted)" data-bs-toggle="tooltip" title="AM/PM = 0.5 day. Custom Hours calculates hours."></i></label>
              <select class="form-select" name="partial_day" id="partial_day" onchange="recalc();updateSupportDocsUI();">
                <option value="none" <?php echo selected_if('none',$oldPartial); ?>>—</option>
                <option value="am" <?php echo selected_if('am',$oldPartial); ?>>Half-day (AM)</option>
                <option value="pm" <?php echo selected_if('pm',$oldPartial); ?>>Half-day (PM)</option>
                <option value="custom_hours" <?php echo selected_if('custom_hours',$oldPartial); ?>>Custom Hours</option>
              </select>
            </div>

            <div class="col-md-3 <?php echo $showTimeUI?'':'d-none'; ?>" id="timeWrap">
              <label class="form-label">Time (for hours/custom)</label>
              <div class="d-flex gap-2">
                <input type="time" class="form-control" name="start_time" id="start_time" value="<?php echo oldh('start_time'); ?>" onchange="recalc();updateSupportDocsUI();">
                <input type="time" class="form-control" name="end_time" id="end_time" value="<?php echo oldh('end_time'); ?>" onchange="recalc();updateSupportDocsUI();">
              </div>
              <div class="hint">End must be after start.</div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Type of Leave <span class="req-dot">*</span></label>
              <select class="form-select" name="leave_type" id="leave_type" required onchange="updateSupportDocsUI()">
                <option value="">-- select --</option>
                <option value="personal_day" data-help="Attachment optional." <?php echo selected_if('personal_day',$oldLeave); ?>>Personal Day</option>
                <option value="sick" data-help="Attachment required." <?php echo selected_if('sick',$oldLeave); ?>>Sick Leave</option>
                <option value="family_responsibility" data-help="Attachment optional." <?php echo selected_if('family_responsibility',$oldLeave); ?>>Family Responsibility Leave</option>
                <option value="annual_leave" data-help="Attachment required." <?php echo selected_if('annual_leave',$oldLeave); ?>>Annual Leave</option>
                <option value="study" data-help="Attachment required (proof of study)." <?php echo selected_if('study',$oldLeave); ?>>Study Leave</option>
                <option value="unpaid" data-help="Attachment required." <?php echo selected_if('unpaid',$oldLeave); ?>>Unpaid Leave</option>
              </select>
              <div class="invalid-feedback">Please select a leave type.</div>
              <div class="hint" id="leave_type_help"></div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Reason <span class="req-dot">*</span></label>
              <select class="form-select" name="reason_category" id="reason_category" required onchange="toggleOther()">
                <option value="">-- select --</option>
                <option value="medical_appointment" <?php echo selected_if('medical_appointment',$oldReason); ?>>Medical Appointment</option>
                <option value="personal_family_event" <?php echo selected_if('personal_family_event',$oldReason); ?>>Personal or Family Events</option>
                <option value="exam_preparation" <?php echo selected_if('exam_preparation',$oldReason); ?>>Exam preparation</option>
                <option value="bereavement" <?php echo selected_if('bereavement',$oldReason); ?>>Death in the Family</option>
                <option value="other" <?php echo selected_if('other',$oldReason); ?>>Other</option>
              </select>
              <div class="invalid-feedback">Please select a reason.</div>
            </div>

            <div class="col-md-6 <?php echo $showOther?'':'d-none'; ?>" id="otherWrap">
              <label class="form-label">Reason details <span class="req-dot" id="otherStar" style="<?php echo $showOther?'':'display:none'; ?>">*</span></label>
              <textarea class="form-control" id="reason_text" name="reason_text" rows="3" minlength="3" maxlength="500" placeholder="Required if 'Other'"><?php echo h(oldv('reason_text','')); ?></textarea>
              <div class="invalid-feedback">Reason details must be 3–500 characters.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Contact number</label>
              <input type="tel" name="contact_number" class="form-control" placeholder="+27…" inputmode="tel" maxlength="20" pattern="^\+?[0-9\s\-\(\)]{10,20}$" value="<?php echo oldh('contact_number'); ?>">
              <div class="invalid-feedback">Invalid contact number (10–12 digits, may include +27).</div>
              <div class="hint">Example: 0812345678 or +27 81 234 5678</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Contact email</label>
              <input type="email" name="contact_email" class="form-control" maxlength="120" value="<?php echo h(oldv('contact_email',(string)$me['email'])); ?>">
              <div class="invalid-feedback">Please enter a valid email address.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Address during leave</label>
              <input type="text" name="leave_address" class="form-control" placeholder="Where you'll be reachable" minlength="3" maxlength="120" value="<?php echo oldh('leave_address'); ?>">
              <div class="invalid-feedback">Address must be 3–120 characters.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Handover notes</label>
              <textarea name="handover_notes" class="form-control" rows="3" minlength="3" maxlength="500" placeholder="Work handover / delegated tasks"><?php echo h(oldv('handover_notes','')); ?></textarea>
              <div class="invalid-feedback">Handover notes must be 3–500 characters.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">CC Email (optional)</label>
              <input type="email" name="cc_email" class="form-control" placeholder="e.g. team@company.com" maxlength="120" value="<?php echo oldh('cc_email'); ?>">
              <div class="invalid-feedback">Please enter a valid CC email address.</div>
            </div>

            <div class="col-md-6">
              <div class="d-flex align-items-center justify-content-between">
                <label class="form-label mb-1">Supporting Document <span id="attachRequiredStar" class="req-dot" style="display:none">*</span></label>
                <span id="attachBadge" class="attach-badge optional"><i class="fa-regular fa-file"></i> Optional</span>
              </div>
              <input type="file" name="attachment" id="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
              <div class="invalid-feedback">Please upload the required supporting document.</div>
              <div class="hint mt-1" id="attachHint">Select a leave type to see attachment requirements.</div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Total</label>
              <input class="form-control" id="total_units" name="total_units_display" value="—" readonly>
              <div class="hint">Auto-calculated (days/hours)</div>
            </div>

            <div class="col-12">
              <div class="policy-box">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <div style="font-weight:800;color:var(--accent);"><i class="fa-solid fa-shield-halved me-1"></i> InvesthoodIT Leave Policy</div>
                    <div class="hint mb-0">You must accept the policy inside the modal before submitting.</div>
                    <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
                      <span id="policyStatusBadge" class="attach-badge <?php echo $oldPolicyAccepted?'ok':'required'; ?>">
                        <?php if($oldPolicyAccepted): ?><i class="fa-solid fa-check"></i> Accepted<?php else: ?><i class="fa-solid fa-lock"></i> Not accepted<?php endif; ?>
                      </span>
                      <span class="hint" id="policyStatusText"><?php echo $oldPolicyAccepted?'Policy accepted. You can submit.':'Open the policy and click "Accept Policy".'; ?></span>
                    </div>
                  </div>
                  <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#policyModal"><i class="fa-regular fa-rectangle-list me-1"></i> View Policy</button>
                </div>
                <input type="hidden" id="ack_policy" name="ack_policy" value="<?php echo $oldPolicyAccepted?'1':'0'; ?>">
              </div>
            </div>

            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary" <?php echo $deptMissing?'disabled data-dept-disabled="1"':''; ?> id="submitBtn"><i class="fa-solid fa-paper-plane me-1"></i> Submit Request</button>
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
                <tr><th>#</th><th>Unit</th><th>From</th><th>To</th><th>Total</th><th>Type</th><th>Reason</th><th>Status</th><th>Submitted</th><th></th></tr>
              </thead>
              <tbody>
              <?php if (empty($myRequests)): ?>
                <tr><td colspan="10" class="text-center py-4" style="color:var(--muted)">No leave requests yet.</td></tr>
              <?php else: foreach($myRequests as $r):
                $reasonCat=isset($r['reason_category'])?str_replace('_',' ',$r['reason_category']):'';
                $reasonTxt=trim($r['reason_text']??'');
                $status=strtolower($r['status']??'pending');
                $badgeCls=match($status){'approved'=>'badge-approved','rejected'=>'badge-rejected','cancelled'=>'bg-secondary',default=>'badge-pending'};
                $submitted=!empty($r['created_at'])?date('Y-m-d H:i',strtotime($r['created_at'])):'';
                $from=trim(($r['start_date']??'').(!empty($r['start_time'])?' '.$r['start_time']:''));
                $to=trim(($r['end_date']??'').(!empty($r['end_time'])?' '.$r['end_time']:''));
              ?>
                <tr>
                  <td><?php echo (int)$r['id']; ?></td>
                  <td class="text-uppercase"><?php echo h($r['request_unit']??''); ?></td>
                  <td><?php echo h($from); ?></td>
                  <td><?php echo h($to); ?></td>
                  <td><?php echo h($r['total_units']??'—'); ?></td>
                  <td><?php echo h(leave_type_label($r['leave_type']??'')); ?></td>
                  <td>
                    <div><?php echo h(ucfirst($reasonCat)); ?></div>
                    <?php if($reasonTxt!==''): ?><div class="small" style="color:var(--muted)"><?php echo h($reasonTxt); ?></div><?php endif; ?>
                  </td>
                  <td><span class="badge <?php echo $badgeCls; ?>"><?php echo h(ucfirst($status)); ?></span></td>
                  <td class="small" style="color:var(--muted)"><?php echo h($submitted); ?></td>
                  <td style="white-space:nowrap;">
                    <?php if(!empty($r['attachment_path'])): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo h($r['attachment_path']); ?>" target="_blank"><i class="fa-regular fa-file"></i></a><?php endif; ?>
                    <?php if($status==='pending'): ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('Cancel this request?')">
                        <input type="hidden" name="cancel_id" value="<?php echo (int)$r['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Cancel"><i class="fa-regular fa-circle-xmark"></i></button>
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
</main>

<div class="modal fade" id="policyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-shield-halved me-2"></i>InvesthoodIT Leave Policy</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="policy-summary">
          <div style="font-weight:800;margin-bottom:6px;">Key Policy Summary</div>
          <ul class="mb-0" style="padding-left:18px;">
            <li><b>Accrual:</b> <?php echo MONTHLY_ACCRUAL_DAYS; ?> day per month (unused days carry into the next month).</li>
            <li><b>Year limit:</b> Maximum <?php echo YEARLY_ALLOWANCE_DAYS; ?> days per year.</li>
            <li><b>Shared pool:</b> Accrued balance is shared across <b>all leave types</b>.</li>
            <li><b>Days calculation:</b> Full-day leave is calculated <b>inclusive</b> (From &amp; To included).</li>
            <li><b>Half-day:</b> AM/PM counts as <b>0.5 day</b>.</li>
            <li><b>Hours:</b> Converted using <b><?php echo WORKDAY_HOURS; ?> hours = 1 day</b>.</li>
            <li><b>Documents:</b> Supporting documents required for most leave types.</li>
            <li><b>Approval:</b> Request stays <b>Pending</b> until supervisor approves.</li>
          </ul>
        </div>
        <div class="mt-3">
          <div style="font-weight:800;">Acknowledgement</div>
          <div class="hint">By accepting, you confirm you understand and will comply with the leave policy.</div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="acceptPolicyBtn"><i class="fa-solid fa-check me-1"></i> Accept Policy</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const unitDays=document.getElementById('uDays'),unitHours=document.getElementById('uHours'),partialSel=document.getElementById('partial_day'),timeWrap=document.getElementById('timeWrap'),otherWrap=document.getElementById('otherWrap'),totalBox=document.getElementById('total_units');
const leaveTypeSel=document.getElementById('leave_type'),leaveTypeHelp=document.getElementById('leave_type_help'),attachHint=document.getElementById('attachHint'),attachBadge=document.getElementById('attachBadge'),attachStar=document.getElementById('attachRequiredStar'),attachmentInput=document.getElementById('attachment');
const supervisorSel=document.getElementById('supervisor_id'),supervisorHint=document.getElementById('supervisorEmailHint');
const reasonSel=document.getElementById('reason_category'),reasonText=document.getElementById('reason_text'),otherStar=document.getElementById('otherStar');
const startDateEl=document.getElementById('start_date'),endDateEl=document.getElementById('end_date'),startTimeEl=document.getElementById('start_time'),endTimeEl=document.getElementById('end_time');
const ackHidden=document.getElementById('ack_policy'),submitBtn=document.getElementById('submitBtn'),acceptPolicyBtn=document.getElementById('acceptPolicyBtn');
const badge=document.getElementById('policyStatusBadge'),statusText=document.getElementById('policyStatusText');
const form=document.getElementById('leaveForm');

function setFieldState(el,valid){if(!el)return;el.classList.remove('is-valid','is-invalid');el.classList.add(valid?'is-valid':'is-invalid');}
function clearFieldState(el){if(!el)return;el.classList.remove('is-valid','is-invalid');}
function digitsCount(str){return(str||'').replace(/\D+/g,'').length;}

function validateDateRange(){if(!startDateEl||!endDateEl)return true;const sd=startDateEl.value||'',ed=endDateEl.value||'';if(!sd||!ed){endDateEl.setCustomValidity('');return true;}if(new Date(ed)<new Date(sd)){endDateEl.setCustomValidity('invalid');return false;}endDateEl.setCustomValidity('');return true;}
function needsTimeFields(){return!!unitHours?.checked||(partialSel?.value||'none')==='custom_hours';}
function validateTimeRange(){if(!startTimeEl||!endTimeEl)return true;const need=needsTimeFields();startTimeEl.required=need;endTimeEl.required=need;if(!need){startTimeEl.setCustomValidity('');endTimeEl.setCustomValidity('');clearFieldState(startTimeEl);clearFieldState(endTimeEl);return true;}const sd=startDateEl?.value||'',ed=endDateEl?.value||'',st=startTimeEl.value||'',et=endTimeEl.value||'';if(!st){startTimeEl.setCustomValidity('invalid');return false;}else startTimeEl.setCustomValidity('');if(!et){endTimeEl.setCustomValidity('invalid');return false;}const baseSd=sd||new Date().toISOString().slice(0,10),baseEd=ed||baseSd;const diff=(new Date(baseEd+'T'+et)-new Date(baseSd+'T'+st));if(diff<=0){endTimeEl.setCustomValidity('invalid');return false;}endTimeEl.setCustomValidity('');return true;}
function validatePhoneRealtime(){const phone=form?.querySelector('input[name="contact_number"]');if(!phone)return true;const v=phone.value.trim();if(!v){phone.setCustomValidity('');clearFieldState(phone);return true;}const digits=digitsCount(v),patternOk=/^\+?[0-9\s\-\(\)]{10,20}$/.test(v);if(!patternOk||digits<10||digits>12){phone.setCustomValidity('invalid');setFieldState(phone,false);return false;}phone.setCustomValidity('');setFieldState(phone,true);return true;}
function validateReasonOtherRealtime(){if(!reasonSel)return true;const isOther=reasonSel.value==='other';if(!reasonText)return true;reasonText.required=isOther;reasonText.disabled=!isOther;if(!isOther){reasonText.setCustomValidity('');clearFieldState(reasonText);return true;}const txt=(reasonText.value||'').trim();if(txt.length<3||txt.length>500){reasonText.setCustomValidity('invalid');setFieldState(reasonText,false);return false;}reasonText.setCustomValidity('');setFieldState(reasonText,true);return true;}
function validateAttachmentRealtime(){if(!attachmentInput||!leaveTypeSel)return true;if(!attachmentInput.required){attachmentInput.setCustomValidity('');clearFieldState(attachmentInput);return true;}const hasFile=!!attachmentInput.files&&attachmentInput.files.length>0;if(!hasFile){attachmentInput.setCustomValidity('invalid');setFieldState(attachmentInput,false);return false;}attachmentInput.setCustomValidity('');setFieldState(attachmentInput,true);return true;}
function validateGenericField(el){if(!el)return true;if(el.disabled){el.setCustomValidity('');clearFieldState(el);return true;}const ok=el.checkValidity(),isRequired=el.required,isEmpty=!String(el.value??'').trim()&&!(el.type==='file'&&el.files?.length);if(!isRequired&&isEmpty){el.setCustomValidity('');clearFieldState(el);return true;}setFieldState(el,ok);return ok;}
function validateAllRealtime(){let ok=true;if(!validateDateRange())ok=false;if(!validateTimeRange())ok=false;if(!validatePhoneRealtime())ok=false;if(!validateReasonOtherRealtime())ok=false;if(!validateAttachmentRealtime())ok=false;const fields=form?.querySelectorAll('input,select,textarea')||[];fields.forEach(el=>{if(el.id==='ack_policy')return;if(!validateGenericField(el))ok=false;});return ok;}

function toggleUnit(){const isHours=unitHours?.checked;document.getElementById('partialWrap')?.classList.toggle('d-none',!!isHours);timeWrap?.classList.toggle('d-none',!isHours&&(partialSel?.value!=='custom_hours'));recalc();updateSupportDocsUI();validateTimeRange();}
function toggleOther(){const isOther=reasonSel?.value==='other';otherWrap?.classList.toggle('d-none',!isOther);if(reasonText){reasonText.required=isOther;reasonText.disabled=!isOther;if(!isOther)reasonText.value='';}if(otherStar)otherStar.style.display=isOther?'inline':'none';validateReasonOtherRealtime();}
function updateSupervisorEmailUI(){const opt=supervisorSel?.options[supervisorSel.selectedIndex];if(!opt||!supervisorSel.value){supervisorHint.textContent='Select a supervisor to see the email address.';return;}const email=opt.getAttribute('data-email')||'';supervisorHint.textContent=email?'Supervisor Email: '+email:'Supervisor email not available.';}
function calcDays(sd,ed,partial){if(!sd||!ed)return null;const d1=new Date(sd),d2=new Date(ed);if(d2<d1)return null;let days=Math.floor((d2-d1)/(1000*60*60*24))+1;if(partial==='am'||partial==='pm')days-=0.5;return days;}
function calcHours(sd,ed,st,et){if(!sd||!ed||!st||!et)return null;const h=(new Date(ed+'T'+et)-new Date(sd+'T'+st))/3600000;return h>0?h:null;}
function recalc(){const sd=startDateEl?.value||'',ed=endDateEl?.value||'',st=startTimeEl?.value||'',et=endTimeEl?.value||'';let txt='—';if(unitDays?.checked&&sd&&ed){const days=calcDays(sd,ed,partialSel?.value||'none');if(days!==null){if(partialSel?.value==='custom_hours'&&st&&et){const h=(new Date(sd+'T'+et)-new Date(sd+'T'+st))/3600000;txt=h>0?h.toFixed(2)+' hours':'—';totalBox&&(totalBox.value=txt);return;}txt=days.toFixed(1)+' days';}}if(unitHours?.checked&&sd&&ed&&st&&et){const h=calcHours(sd,ed,st,et);if(h!==null)txt=h.toFixed(2)+' hours';}totalBox&&(totalBox.value=txt);}
function updateSupportDocsUI(){const opt=leaveTypeSel?.options[leaveTypeSel.selectedIndex];if(!opt||!leaveTypeSel.value){leaveTypeHelp.textContent='';attachHint.textContent='Select a leave type to see attachment requirements.';attachBadge.className='attach-badge optional';attachBadge.innerHTML='<i class="fa-regular fa-file"></i> Optional';attachStar.style.display='none';attachmentInput.required=false;validateAttachmentRealtime();return;}const type=leaveTypeSel.value,help=opt.getAttribute('data-help')||'';leaveTypeHelp.textContent=help;const requiredNow=!(['personal_day','family_responsibility'].includes(type));attachmentInput.required=requiredNow;attachStar.style.display=requiredNow?'inline':'none';if(requiredNow){attachHint.textContent='Attachment is required. Please upload PDF/JPG/PNG.';attachBadge.className='attach-badge required';attachBadge.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> Required';}else{attachHint.textContent='Attachment is optional for this leave type.';attachBadge.className='attach-badge optional';attachBadge.innerHTML='<i class="fa-regular fa-file"></i> Optional';}validateAttachmentRealtime();}
function setPolicyUI(accepted){if(!badge||!statusText)return;if(accepted){badge.className='attach-badge ok';badge.innerHTML='<i class="fa-solid fa-check"></i> Accepted';statusText.textContent='Policy accepted. You can submit.';}else{badge.className='attach-badge required';badge.innerHTML='<i class="fa-solid fa-lock"></i> Not accepted';statusText.textContent='Open the policy and click "Accept Policy".';}}
function syncPolicyGate(){if(!submitBtn)return;if(submitBtn.hasAttribute('data-dept-disabled')){submitBtn.disabled=true;return;}const accepted=ackHidden?.value==='1';submitBtn.disabled=!accepted;setPolicyUI(accepted);}
acceptPolicyBtn?.addEventListener('click',()=>{if(ackHidden)ackHidden.value='1';syncPolicyGate();const modalEl=document.getElementById('policyModal');(bootstrap.Modal.getInstance(modalEl)||new bootstrap.Modal(modalEl)).hide();});
(function(){if(!form)return;form.addEventListener('submit',function(event){validateDateRange();validateTimeRange();validatePhoneRealtime();validateReasonOtherRealtime();validateAttachmentRealtime();if(!form.checkValidity()){event.preventDefault();event.stopPropagation();}form.classList.add('was-validated');},false);})();
function wireRealtimeValidation(){if(!form)return;form.querySelectorAll('input,textarea').forEach(el=>{if((el.getAttribute('type')||'').toLowerCase()==='file')return;el.addEventListener('input',()=>{if(el.name==='contact_number'){validatePhoneRealtime();return;}if(el.id==='reason_text'){validateReasonOtherRealtime();return;}if(el.id==='start_date'||el.id==='end_date'){validateDateRange();validateGenericField(startDateEl);validateGenericField(endDateEl);return;}if(el.id==='start_time'||el.id==='end_time'){validateTimeRange();validateGenericField(startTimeEl);validateGenericField(endTimeEl);return;}validateGenericField(el);});});form.querySelectorAll('select').forEach(el=>{el.addEventListener('change',()=>{if(el.id==='leave_type'){updateSupportDocsUI();validateGenericField(el);return;}if(el.id==='reason_category'){toggleOther();validateGenericField(el);return;}if(el.id==='partial_day'){validateTimeRange();validateGenericField(el);return;}validateGenericField(el);});});attachmentInput?.addEventListener('change',()=>validateAttachmentRealtime());supervisorSel?.addEventListener('change',()=>validateGenericField(supervisorSel));unitDays?.addEventListener('change',()=>{toggleUnit();validateAllRealtime();});unitHours?.addEventListener('change',()=>{toggleUnit();validateAllRealtime();});startDateEl?.addEventListener('change',()=>{recalc();validateAllRealtime();});endDateEl?.addEventListener('change',()=>{recalc();validateAllRealtime();});startTimeEl?.addEventListener('change',()=>{recalc();validateAllRealtime();});endTimeEl?.addEventListener('change',()=>{recalc();validateAllRealtime();});}
function resetForm(){setTimeout(()=>{form?.classList.remove('was-validated');form?.querySelectorAll('.is-valid,.is-invalid').forEach(el=>{el.classList.remove('is-valid','is-invalid');el.setCustomValidity?.('');});toggleUnit();toggleOther();updateSupportDocsUI();updateSupervisorEmailUI();if(totalBox)totalBox.value='—';if(ackHidden)ackHidden.value='0';syncPolicyGate();},0);}

toggleUnit();toggleOther();updateSupportDocsUI();updateSupervisorEmailUI();recalc();
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el=>new bootstrap.Tooltip(el));

const toggleBtn=document.getElementById('toggle-dark'),darkIcon=document.getElementById('darkIcon');
function applyDark(on){document.body.classList.toggle('dark',on);darkIcon.className=on?'fas fa-sun':'fas fa-moon';}
toggleBtn.addEventListener('click',()=>{const on=!document.body.classList.contains('dark');applyDark(on);localStorage.setItem('darkMode',String(on));});
applyDark(localStorage.getItem('darkMode')==='true');

syncPolicyGate();
wireRealtimeValidation();
</script>
</body>
</html>