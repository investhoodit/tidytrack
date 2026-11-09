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
    SELECT 
        u.user_id, u.name, u.email,
        p.plan_text,
        COALESCE(SUM(s.minutes), 0) AS total_minutes
    FROM users u
    JOIN member_daily_plans p 
        ON p.user_id = u.user_id AND p.plan_date = ?
    LEFT JOIN user_sessions s 
        ON s.user_id = u.user_id AND s.session_date = ?
    LEFT JOIN daily_tasks dt 
        ON dt.user_id = u.user_id AND dt.sent_date = ?
    WHERE u.role = 'member'
      AND dt.id IS NULL
    GROUP BY u.user_id, p.plan_text
    HAVING total_minutes >= 240
");
$stmt->bind_param("sss", $today, $today, $today);
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($members)) {
    echo "No eligible members at " . date('H:i');
    exit;
}

$submitted = 0;
$failed    = 0;

foreach ($members as $member) {
    $userId   = $member['user_id'];
    $planText = trim($member['plan_text']);
    $mins     = (int)$member['total_minutes'];
    $hours    = floor($mins / 60);
    $minutes  = $mins % 60;

    $insert = $conn->prepare("
        INSERT INTO daily_tasks (user_id, task, sent_at, sent_date)
        VALUES (?, ?, NOW(), CURDATE())
        ON DUPLICATE KEY UPDATE task = VALUES(task), sent_at = NOW()
    ");
    $insert->bind_param("is", $userId, $planText);
    $ok = $insert->execute();
    $insert->close();

    if (!$ok) { $failed++; continue; }
    $submitted++;

    if (empty($member['email'])) continue;

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
        $mail->addAddress($member['email'], $member['name']);
        $mail->isHTML(true);
        $mail->Subject = "Your daily report was automatically submitted!";

        $mail->Body = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef2f5;font-family:\'Helvetica Neue\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f5;padding:32px 16px;">
  <tr><td align="center">
  <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">

    <tr><td style="background:#10b981;border-radius:14px 14px 0 0;padding:28px 32px;text-align:center;">
      <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,0.7);text-transform:uppercase;">TidyTrack &middot; Auto Submission</p>
      <p style="margin:0;font-size:22px;font-weight:700;color:#fff;">Report Submitted For You!</p>
    </td></tr>

    <tr><td style="background:#ffffff;padding:32px;">
      <p style="margin:0 0 20px;font-size:15px;color:#334155;line-height:1.6;">
        Hi <strong style="color:#1d3557;">' . htmlspecialchars($member['name']) . '</strong>,<br>
        Your daily report was <strong>automatically submitted</strong> using the plan you set this morning. Nothing to do!
      </p>

      <div style="background:#f0fdf4;border-left:3px solid #10b981;border-radius:0 8px 8px 0;padding:16px 18px;margin-bottom:22px;">
        <p style="margin:0 0 5px;font-size:11px;font-weight:700;color:#059669;letter-spacing:.6px;text-transform:uppercase;">Submitted report</p>
        <p style="margin:0;font-size:14px;color:#1e293b;line-height:1.5;">' . htmlspecialchars($planText) . '</p>
      </div>

      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;text-align:center;">
        <p style="margin:0 0 4px;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Time logged today</p>
        <p style="margin:0;font-size:26px;font-weight:700;color:#1d3557;">' . $hours . 'h ' . $minutes . 'm</p>
      </div>

      <p style="margin:0;font-size:13px;color:#94a3b8;text-align:center;">Great work today &mdash; see you tomorrow!</p>
    </td></tr>

    <tr><td style="background:#f1f5f9;border-radius:0 0 14px 14px;padding:16px 32px;text-align:center;">
      <p style="margin:0;font-size:12px;color:#94a3b8;">&copy; ' . date('Y') . ' TidyTrack &middot; Investhood Projects &middot; Automated submission.</p>
    </td></tr>

  </table>
  </td></tr>
</table>
</body></html>';

        $mail->send();

    } catch (Exception $e) {
        error_log("Auto-submit mail error for {$member['email']}: " . $mail->ErrorInfo);
    }
}

echo "Auto-submitted: {$submitted} | Failed: {$failed} | " . date('H:i');
?>