<?php
/**
 * send_timesheet_email.php
 *
 * Generates a PDF timesheet and emails it to the employee.
 * Displays a status page with success/error message.
 */

session_start();
require '../dp.php';

/* =========================================================
   CONFIG
========================================================= */
$MAIL_FROM  = 'noreply@investhoodprojects.com';
$MAIL_REPLY = 'noreply@investhoodprojects.com';
$LOG_FILE   = __DIR__ . '/send_timesheet_mail_log.txt';
$APP_URL    = 'https://investhoodprojects.com/tidytracker/tidytrack/manager';  // <-- FIX: added this line

// PHPMailer & TCPDF (adjust paths as needed)
require_once('TCPDF/library/tcpdf.php');
require 'PHPMailer-master/PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* =========================================================
   AUTHENTICATION
========================================================= */
$userID   = $_SESSION['user_id'] ?? null;
$role     = $_SESSION['role'] ?? '';

if (!$userID || $role !== 'manager') {
    die("Unauthorized. Access restricted to managers.");
}

/* =========================================================
   PARAMETERS
========================================================= */
$targetUserId = $_GET['user_id'] ?? null;
$year         = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month        = isset($_GET['month']) ? (int)$_GET['month'] : date('m');

if (!$targetUserId) {
    die("User ID missing.");
}
if ($month < 1 || $month > 12) $month = date('m');
if ($year < 2000 || $year > 2100) $year = date('Y');

/* =========================================================
   FETCH USER DATA
========================================================= */
$userStmt = $conn->prepare("
    SELECT user_id, name, email, role,
           COALESCE(department, 'Not assigned') AS department,
           COALESCE(phone, '—') AS phone,
           COALESCE(bio, '') AS bio
    FROM users WHERE user_id = ?
");
$userStmt->bind_param("i", $targetUserId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    die("User not found.");
}

/* =========================================================
   FETCH TIMESHEET DATA
========================================================= */
// Monthly summary
$timesheetStmt = $conn->prepare("
    SELECT total_days, present_days, absent_days, total_hours, updated_at
    FROM timesheet
    WHERE user_id = ? AND month = ? AND year = ?
");
$timesheetStmt->bind_param("iii", $targetUserId, $month, $year);
$timesheetStmt->execute();
$monthlySummary = $timesheetStmt->get_result()->fetch_assoc();
$timesheetStmt->close();

// Daily attendance
$attQuery = "
    SELECT date, attendance, absence_reason
    FROM attendance_log
    WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?
    ORDER BY date ASC
";
$attStmt = $conn->prepare($attQuery);
$attStmt->bind_param("iii", $targetUserId, $year, $month);
$attStmt->execute();
$attResult = $attStmt->get_result();
$dailyRecords = [];
while ($row = $attResult->fetch_assoc()) {
    $dailyRecords[$row['date']] = $row;
}
$attStmt->close();

// Session minutes
$sessQuery = "
    SELECT session_date, SUM(minutes) AS total_minutes
    FROM user_sessions
    WHERE user_id = ? AND YEAR(session_date) = ? AND MONTH(session_date) = ?
    GROUP BY session_date
";
$sessStmt = $conn->prepare($sessQuery);
$sessStmt->bind_param("iii", $targetUserId, $year, $month);
$sessStmt->execute();
$sessResult = $sessStmt->get_result();
$dailyMinutes = [];
while ($row = $sessResult->fetch_assoc()) {
    $dailyMinutes[$row['session_date']] = (int)$row['total_minutes'];
}
$sessStmt->close();

// Build days
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$monthDates = [];
for ($day = 1; $day <= $daysInMonth; $day++) {
    $monthDates[] = date('Y-m-d', strtotime("$year-$month-$day"));
}

// Calculate totals
if ($monthlySummary) {
    $totalPresent = $monthlySummary['present_days'];
    $totalAbsent   = $monthlySummary['absent_days'];
    $totalHours    = $monthlySummary['total_hours'];
    $totalDays     = $monthlySummary['total_days'];
    $lastUpdated   = $monthlySummary['updated_at'];
} else {
    $totalPresent = 0;
    $totalAbsent = 0;
    foreach ($monthDates as $date) {
        if (isset($dailyRecords[$date])) {
            $att = strtolower($dailyRecords[$date]['attendance']);
            if (in_array($att, ['present','late','half-day'])) {
                $totalPresent++;
            } else {
                $totalAbsent++;
            }
        } else {
            if (date('N', strtotime($date)) < 6) $totalAbsent++;
        }
    }
    $totalDays = $daysInMonth;
    $totalHours = 0;
    $lastUpdated = null;
}

$totalMinutesMonth = array_sum($dailyMinutes);
$attendanceRate = $totalDays > 0 ? round(($totalPresent / $totalDays) * 100, 1) : 0;
$fullDaysCount = 0;
foreach ($monthDates as $date) {
    if (($dailyMinutes[$date] ?? 0) >= 240) $fullDaysCount++;
}

/* =========================================================
   HELPER FUNCTIONS FOR PDF
========================================================= */
function formatMinutesPDF($minutes) {
    if ($minutes <= 0) return '—';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($hours > 0 && $mins > 0) return "{$hours}h {$mins}min";
    if ($hours > 0) return "{$hours}h";
    return "{$mins}min";
}

function attendanceLabelPDF($attendance) {
    $att = strtolower($attendance);
    $map = [
        'present'  => 'Present',
        'late'     => 'Late',
        'half-day' => 'Half Day',
        'absent'   => 'Absent',
        'sick'     => 'Sick'
    ];
    return $map[$att] ?? '—';
}

function getDayNamePDF($dateStr) {
    return date('D', strtotime($dateStr));
}

/* =========================================================
   GENERATE PDF USING TCPDF
========================================================= */
class MYPDF extends TCPDF {
    public function Header() {
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 15, 'Timesheet Report', 0, false, 'C', 0, '', 0, false, 'M', 'M');
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('InvestHood');
$pdf->SetAuthor('InvestHood');
$pdf->SetTitle('Timesheet - '.$user['name']);
$pdf->SetSubject('Monthly Timesheet');
$pdf->SetKeywords('Timesheet, Attendance');
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(15, 20, 15);
$pdf->AddPage();

// Build HTML content for PDF
$html = '<h2>Employee: ' . htmlspecialchars($user['name']) . '</h2>';
$html .= '<p><strong>Email:</strong> ' . htmlspecialchars($user['email']) . ' | ';
$html .= '<strong>Department:</strong> ' . htmlspecialchars($user['department']) . ' | ';
$html .= '<strong>Role:</strong> ' . ucfirst(htmlspecialchars($user['role'])) . '</p>';
$html .= '<p><strong>Period:</strong> ' . date('F Y', strtotime("$year-$month-01")) . '</p>';

// Summary stats
$html .= '<table border="0" cellpadding="4" style="margin-bottom:10px; width:100%;">';
$html .= '<tr>
            <td><strong>Working Days:</strong> ' . $totalDays . '</td>
            <td><strong>Present (att.):</strong> ' . $totalPresent . '</td>
            <td><strong>Full Days (≥4h):</strong> ' . $fullDaysCount . '</td>
          </tr>';
$html .= '<tr>
            <td><strong>Absent:</strong> ' . ($totalAbsent ?? ($totalDays - $totalPresent)) . '</td>
            <td><strong>Total Hours (att.):</strong> ' . number_format($totalHours, 1) . 'h</td>
            <td><strong>Total Logged:</strong> ' . formatMinutesPDF($totalMinutesMonth) . '</td>
          </tr>';
$html .= '<tr><td><strong>Attendance %:</strong> ' . $attendanceRate . '%</td><td colspan="2"></td></tr>';
$html .= '</table>';

// Daily table
$html .= '<table border="1" cellpadding="5" style="font-size:10pt; width:100%; border-collapse:collapse;">';
$html .= '<thead>
            <tr style="background-color:#f0f2f8;">
                <th>Date</th>
                <th>Day</th>
                <th>Attendance</th>
                <th>Minutes Logged</th>
                <th>≥4h Workday</th>
                <th>Reason / Notes</th>
            </tr>
          </thead><tbody>';

foreach ($monthDates as $date) {
    $record = $dailyRecords[$date] ?? null;
    $isWeekend = (date('N', strtotime($date)) >= 6);
    $minutesLogged = $dailyMinutes[$date] ?? 0;
    $attLabel = '';
    $reason = '';
    $fullDay = ($minutesLogged >= 240) ? 'Yes' : 'No';

    if ($record) {
        $attLabel = attendanceLabelPDF($record['attendance']);
        $reason = htmlspecialchars($record['absence_reason'] ?? '');
    } else {
        if ($isWeekend) {
            $attLabel = 'Weekend (No duty)';
            $reason = 'Scheduled day off';
        } else {
            $attLabel = 'No record';
            $reason = 'Missing check-in';
        }
    }

    $html .= '<tr>
                <td>' . date('d M Y', strtotime($date)) . '</td>
                <td>' . getDayNamePDF($date) . '</td>
                <td>' . $attLabel . '</td>
                <td>' . formatMinutesPDF($minutesLogged) . '</td>
                <td>' . $fullDay . '</td>
                <td>' . ($reason ?: '—') . '</td>
              </tr>';
}

$html .= '</tbody></table>';

if ($lastUpdated) {
    $html .= '<p style="font-size:8pt; color:#666;"><em>Last summary update: ' . date('d M Y H:i', strtotime($lastUpdated)) . '</em></p>';
}
$html .= '<p style="font-size:8pt; color:#666;"><em>Timesheet generated automatically by InvestHood system.</em></p>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->lastPage();

// Output PDF as string
$pdfContent = $pdf->Output('timesheet_' . $user['name'] . '_' . date('Y-m') . '.pdf', 'S');

/* =========================================================
   PREPARE STYLED HTML EMAIL BODY
========================================================= */
$monthYear = date('F Y', strtotime("$year-$month-01"));
$emailSubject = "Your Timesheet for $monthYear";

// Build a clean, styled HTML email
$emailBodyHtml = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Timesheet</title>
<style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        background-color: #f4f6fa;
        margin: 0;
        padding: 0;
    }
    .container {
        max-width: 600px;
        margin: 30px auto;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        overflow: hidden;
    }
    .header {
        background: #1e3a5f;
        color: white;
        padding: 25px 30px;
        text-align: center;
    }
    .header h1 {
        margin: 0;
        font-size: 24px;
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    .header p {
        margin: 5px 0 0;
        opacity: 0.8;
        font-size: 14px;
    }
    .content {
        padding: 30px;
    }
    .greeting {
        font-size: 16px;
        margin-bottom: 20px;
    }
    .summary-box {
        background: #f8fafc;
        border-radius: 12px;
        padding: 18px 20px;
        margin: 20px 0;
        border-left: 4px solid #1e3a5f;
    }
    .summary-box table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    .summary-box td {
        padding: 6px 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .summary-box tr:last-child td {
        border-bottom: none;
    }
    .label {
        font-weight: 600;
        color: #334155;
    }
    .value {
        text-align: right;
        font-weight: 500;
        color: #0f172a;
    }
    .footer-note {
        font-size: 13px;
        color: #64748b;
        margin-top: 30px;
        text-align: center;
        border-top: 1px solid #e2e8f0;
        padding-top: 20px;
    }
    .footer-note a {
        color: #1e3a5f;
        text-decoration: none;
    }
    .btn {
        display: inline-block;
        background: #1e3a5f;
        color: white;
        padding: 10px 24px;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 500;
        font-size: 14px;
        margin-top: 10px;
    }
    .btn:hover {
        background: #0f2b44;
    }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📋 Timesheet Report</h1>
        <p>' . $monthYear . '</p>
    </div>
    <div class="content">
        <p class="greeting">Dear <strong>' . htmlspecialchars($user['name']) . '</strong>,</p>
        <p>Please find attached your detailed timesheet for <strong>' . $monthYear . '</strong>.</p>

        <div class="summary-box">
            <table>
                <tr><td class="label">Working Days</td><td class="value">' . $totalDays . '</td></tr>
                <tr><td class="label">Present (attendance)</td><td class="value">' . $totalPresent . '</td></tr>
                <tr><td class="label">Full Days (≥4h logged)</td><td class="value">' . $fullDaysCount . '</td></tr>
                <tr><td class="label">Absent</td><td class="value">' . ($totalAbsent ?? ($totalDays - $totalPresent)) . '</td></tr>
                <tr><td class="label">Total Hours (attendance)</td><td class="value">' . number_format($totalHours, 1) . 'h</td></tr>
                <tr><td class="label">Total Logged Minutes</td><td class="value">' . formatMinutesPDF($totalMinutesMonth) . '</td></tr>
                <tr><td class="label">Attendance Rate</td><td class="value">' . $attendanceRate . '%</td></tr>
            </table>
        </div>

        <p style="font-size: 14px; color: #334155;">The PDF attachment contains the full daily breakdown, including attendance status, logged minutes, and notes.</p>

        <p style="margin-top: 25px;">This is an automated message from InvestHood. If you have any questions, please contact your manager.</p>

        <div class="footer-note">
            &copy; ' . date('Y') . ' InvestHood Projects &bull; All rights reserved.
        </div>
    </div>
</div>
</body>
</html>';

// Plain text alternative
$emailBodyPlain = "Dear " . $user['name'] . ",\n\nPlease find attached your timesheet for " . $monthYear . ".\n\n";
$emailBodyPlain .= "Summary:\n";
$emailBodyPlain .= "Working Days: $totalDays\nPresent: $totalPresent\nFull Days (≥4h): $fullDaysCount\n";
$emailBodyPlain .= "Absent: " . ($totalAbsent ?? ($totalDays - $totalPresent)) . "\n";
$emailBodyPlain .= "Total Hours: " . number_format($totalHours, 1) . "h\n";
$emailBodyPlain .= "Total Logged: " . formatMinutesPDF($totalMinutesMonth) . "\n";
$emailBodyPlain .= "Attendance Rate: $attendanceRate%\n\n";
$emailBodyPlain .= "The full detailed timesheet is attached as a PDF.\n\n";
$emailBodyPlain .= "This is an automated message from InvestHood.\nThank you.";

/* =========================================================
   SEND EMAIL
========================================================= */
$mail = new PHPMailer(true);
$statusMessage = '';
$statusType = 'success';

try {
    $mail->isMail(); // or isSMTP() if configured
    $mail->setFrom($MAIL_FROM, 'InvestHood Timesheet');
    $mail->addAddress($user['email'], $user['name']);
    $mail->addReplyTo($MAIL_REPLY, 'InvestHood');
    $mail->Subject = $emailSubject;
    $mail->isHTML(true);
    $mail->Body    = $emailBodyHtml;
    $mail->AltBody = $emailBodyPlain;

    $mail->addStringAttachment($pdfContent, 'timesheet_' . $user['name'] . '_' . date('Y-m') . '.pdf', 'base64', 'application/pdf');

    $mail->send();
    $statusMessage = "Timesheet sent successfully to " . htmlspecialchars($user['email']) . ".";
    $logMessage = date('Y-m-d H:i:s') . " - SUCCESS: Timesheet sent to {$user['email']} for {$year}-{$month}\n";
} catch (Exception $e) {
    $statusMessage = "Message could not be sent. Mailer Error: " . htmlspecialchars($mail->ErrorInfo);
    $statusType = 'error';
    $logMessage = date('Y-m-d H:i:s') . " - ERROR: {$mail->ErrorInfo} for {$user['email']} ({$year}-{$month})\n";
}

// Write log
file_put_contents($LOG_FILE, $logMessage, FILE_APPEND);

/* =========================================================
   DISPLAY STATUS PAGE
========================================================= */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Email Status</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: 'Inter', sans-serif;
    background: #f0f2f8;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 20px;
}
.card {
    background: white;
    border-radius: 24px;
    padding: 40px 50px;
    max-width: 600px;
    width: 100%;
    box-shadow: 0 8px 30px rgba(0,0,0,0.08);
    text-align: center;
}
.icon {
    font-size: 4rem;
    margin-bottom: 20px;
}
.success .icon { color: #2b7e3a; }
.error .icon { color: #b45353; }
h1 {
    font-size: 1.8rem;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 12px;
}
p {
    color: #475569;
    font-size: 1rem;
    line-height: 1.6;
    margin-bottom: 24px;
}
.btn {
    display: inline-block;
    background: #1e3a5f;
    color: white;
    padding: 12px 30px;
    border-radius: 40px;
    text-decoration: none;
    font-weight: 500;
    transition: 0.2s;
}
.btn:hover {
    background: #0f2b44;
    transform: translateY(-2px);
}
</style>
</head>
<body>
<div class="card <?= $statusType ?>">
    <div class="icon">
        <?= $statusType === 'success' ? '✅' : '❌' ?>
    </div>
    <h1><?= $statusType === 'success' ? 'Email Sent!' : 'Email Failed' ?></h1>
    <p><?= $statusMessage ?></p>
    <a href="timesheet_list.php?user_id=<?= urlencode($targetUserId) ?>&year=<?= $year ?>&month=<?= $month ?>" class="btn">
        ← Back to Timesheet
    </a>
</div>
</body>
</html>