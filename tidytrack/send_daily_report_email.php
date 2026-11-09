<?php
require 'PHPMailer-master/PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'dp.php';
date_default_timezone_set('Africa/Johannesburg');

$today = date('Y-m-d');
$day   = (int)date('N');

if ($day > 5) exit;

$stmt = $conn->prepare("
    SELECT user_id, name, email 
    FROM users 
    WHERE role = 'member' 
      AND email IS NOT NULL 
      AND email != ''
");
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sentCount = 0;

foreach ($members as $user) {
    $userId = $user['user_id'];

    $minStmt = $conn->prepare("
        SELECT COALESCE(SUM(minutes), 0) as total 
        FROM user_sessions 
        WHERE user_id = ? AND session_date = ?
    ");
    $minStmt->bind_param("is", $userId, $today);
    $minStmt->execute();
    $minutes = (int)$minStmt->get_result()->fetch_assoc()['total'];
    $minStmt->close();

    $planStmt = $conn->prepare("
        SELECT plan_text FROM member_daily_plans 
        WHERE user_id = ? AND plan_date = ?
    ");
    $planStmt->bind_param("is", $userId, $today);
    $planStmt->execute();
    $planRow  = $planStmt->get_result()->fetch_assoc();
    $planStmt->close();
    $planText = $planRow['plan_text'] ?? null;

    $hours       = floor($minutes / 60);
    $mins        = $minutes % 60;
    $pct         = min(100, round(($minutes / 240) * 100));
    $barColor    = $pct >= 100 ? '#10b981' : ($pct >= 60 ? '#f59e0b' : '#ef4444');
    $timeDisplay = $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m";
    $goalNote    = $pct >= 100
        ? '&#9989; 4hr goal met &mdash; you\'re clear to submit!'
        : 'Need ' . max(0, 240 - $minutes) . 'm more to hit the 4hr goal';

    $planBlock = $planText
        ? '<div style="background:#f0fdf4;border-left:3px solid #10b981;border-radius:0 8px 8px 0;padding:14px 16px;margin-bottom:22px;">
            <p style="margin:0 0 4px;font-size:11px;font-weight:700;color:#059669;letter-spacing:.6px;text-transform:uppercase;">Your plan for today</p>
            <p style="margin:0;font-size:14px;color:#1e293b;line-height:1.5;">' . htmlspecialchars($planText) . '</p>
           </div>'
        : '<div style="background:#fff7ed;border-left:3px solid #f59e0b;border-radius:0 8px 8px 0;padding:14px 16px;margin-bottom:22px;">
            <p style="margin:0;font-size:13px;color:#92400e;">No morning plan set &mdash; type what you accomplished today when submitting.</p>
           </div>';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('TIDYTRACK_SMTP_USERNAME') ?: '';
        $mail->Password   = getenv('TIDYTRACK_SMTP_PASSWORD') ?: '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('no-reply@investhoodprojects.com', 'TidyTrack');
        $mail->addAddress($user['email'], $user['name']);
        $mail->isHTML(true);
        $mail->Subject = "URGENT: 5 minutes left - submit your daily report now!";

        $mail->Body = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef2f5;font-family:\'Helvetica Neue\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f5;padding:32px 16px;">
  <tr><td align="center">
  <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">

    <tr><td style="background:#dc2626;border-radius:14px 14px 0 0;padding:28px 32px;text-align:center;">
      <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,0.65);text-transform:uppercase;">TidyTrack &middot; Daily Report</p>
      <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;">Last Chance &mdash; Submit Now!</p>
      <div style="display:inline-block;margin-top:10px;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:20px;padding:4px 14px;">
        <span style="font-size:11px;font-weight:700;color:white;letter-spacing:.8px;">5 MIN &mdash; URGENT</span>
      </div>
    </td></tr>

    <tr><td style="background:#ffffff;padding:32px;">

      <p style="margin:0 0 22px;font-size:15px;color:#334155;line-height:1.6;">
        Hi <strong style="color:#1d3557;">' . htmlspecialchars($user['name']) . '</strong>,<br>
        You have <strong style="color:#dc2626;">only 5 minutes left</strong> before the report window closes at <strong>17:00</strong>. Do not miss it!
      </p>

      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px 20px;margin-bottom:22px;">
        <p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#94a3b8;letter-spacing:.6px;text-transform:uppercase;">Today\'s attendance</p>
        <p style="margin:0 0 10px;font-size:28px;font-weight:700;color:#1d3557;">' . $timeDisplay . ' <span style="font-size:14px;color:#94a3b8;font-weight:400;">logged</span></p>
        <div style="background:#e2e8f0;border-radius:20px;height:8px;overflow:hidden;">
          <div style="background:' . $barColor . ';width:' . $pct . '%;height:8px;border-radius:20px;"></div>
        </div>
        <p style="margin:6px 0 0;font-size:12px;color:#94a3b8;">' . $pct . '% of 4hr goal &middot; ' . $goalNote . '</p>
      </div>

      ' . $planBlock . '

      <div style="text-align:center;margin-bottom:8px;">
        <a href="https://investhoodprojects.com/tidytracker/tidytrack/member/send_daily_report.php"
           style="display:inline-block;background:#dc2626;color:white;text-decoration:none;font-size:14px;font-weight:700;padding:13px 32px;border-radius:10px;letter-spacing:.3px;">
          Submit My Daily Report &rarr;
        </a>
      </div>

    </td></tr>

    <tr><td style="background:#f1f5f9;border-radius:0 0 14px 14px;padding:16px 32px;text-align:center;">
      <p style="margin:0;font-size:12px;color:#94a3b8;">&copy; ' . date('Y') . ' TidyTrack &middot; Investhood Projects &middot; Automated reminder.</p>
    </td></tr>

  </table>
  </td></tr>
</table>
</body></html>';

        $mail->send();
        $sentCount++;

    } catch (Exception $e) {
        error_log("Mail error for " . $user['email'] . ": " . $mail->ErrorInfo);
    }
}

echo "Sent: {$sentCount} | " . date('H:i');
?>