<?php
session_start();
require_once('TCPDF/library/tcpdf.php');
require '../dp.php';

// Only managers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    die("Unauthorized. Access restricted to managers.");
}

// Get parameters
$targetUserId = $_GET['user_id'] ?? null;
if (!$targetUserId) {
    die("User ID missing.");
}
$currentYear  = date('Y');
$currentMonth = date('m');
$selectedYear  = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
if ($selectedMonth < 1 || $selectedMonth > 12) $selectedMonth = $currentMonth;
if ($selectedYear < 2000 || $selectedYear > 2100) $selectedYear = $currentYear;

// Fetch user details
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

// ----- Attendance calculation (same as CLI script) -----
const REQUIRED_DAILY_MINUTES = 240;

// Load attendance notes (for reasons only)
$attStmt = $conn->prepare("
    SELECT date, attendance, absence_reason
    FROM attendance_log
    WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?
    ORDER BY date ASC
");
$attStmt->bind_param("iii", $targetUserId, $selectedYear, $selectedMonth);
$attStmt->execute();
$attResult = $attStmt->get_result();
$attendanceNotes = [];
while ($row = $attResult->fetch_assoc()) {
    $attendanceNotes[$row['date']] = $row;
}
$attStmt->close();

// Load daily logged minutes
$sessStmt = $conn->prepare("
    SELECT session_date, SUM(minutes) AS total_minutes
    FROM user_sessions
    WHERE user_id = ? AND YEAR(session_date) = ? AND MONTH(session_date) = ?
    GROUP BY session_date
");
$sessStmt->bind_param("iii", $targetUserId, $selectedYear, $selectedMonth);
$sessStmt->execute();
$sessResult = $sessStmt->get_result();
$dailyMinutes = [];
while ($row = $sessResult->fetch_assoc()) {
    $dailyMinutes[$row['session_date']] = (int)$row['total_minutes'];
}
$sessStmt->close();

// Build all dates in the month
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $selectedMonth, $selectedYear);
$monthDates = [];
for ($day = 1; $day <= $daysInMonth; $day++) {
    $monthDates[] = sprintf("%04d-%02d-%02d", $selectedYear, $selectedMonth, $day);
}

// Compute statistics (same as CLI)
$totalDays = 0;
$present = 0;
$absent = 0;
foreach ($monthDates as $date) {
    $dayNumber = (int) date('N', strtotime($date));
    if ($dayNumber >= 6) continue; // skip weekends
    $totalDays++;
    $minutes = $dailyMinutes[$date] ?? 0;
    if ($minutes >= REQUIRED_DAILY_MINUTES) {
        $present++;
    } else {
        $absent++;
    }
}
$totalMinutes = array_sum($dailyMinutes);
$totalHours = round($totalMinutes / 60, 2);
$attendanceRate = $totalDays > 0 ? round(($present / $totalDays) * 100, 1) : 0;

// Helper functions (same as CLI)
function formatMinutes($minutes) {
    if ($minutes <= 0) return '—';
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    if ($hours > 0 && $mins > 0) return "{$hours}h {$mins}min";
    if ($hours > 0) return "{$hours}h";
    return "{$mins}min";
}
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ----- PDF class with branded header (same as CLI) -----
class TimesheetPDF extends TCPDF
{
    private const LOGO_PATH = __DIR__ . '/../images/INVESTHOOD.jpg';

    public function Header() {
        $this->SetFillColor(29, 53, 87);
        $this->Rect(0, 0, $this->getPageWidth(), 45, 'F');
        if (file_exists(self::LOGO_PATH)) {
            $this->Image(self::LOGO_PATH, 15, 10, 30);
        }
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(50, 10);
        $this->Cell(0, 10, 'Investhood IT', 0, 1, 'L');
        $this->SetFont('helvetica', '', 10);
        $this->SetX(50);
        $this->MultiCell(0, 5, "136 2nd St Randjespark\nJohannesburg, 1685\nSouth Africa\nPhone: 075 333 8142", 0, 'L');
        $this->SetY(50);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().' of '.$this->getAliasNbPages(), 0, false, 'C');
    }
}

// Create PDF
$pdf = new TimesheetPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('InvestHood');
$pdf->SetAuthor('InvestHood');
$periodLabel = date('F Y', strtotime("$selectedYear-$selectedMonth-01"));
$pdf->SetTitle("Timesheet - {$user['name']} - $periodLabel");
$pdf->SetSubject('Monthly Timesheet');
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(15, 55, 15);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

// ----- HTML content (identical to CLI script's style) -----
$html = <<<HTML
<html>
<head>
<style>
    body { font-family: helvetica, sans-serif; color: #333; }
    h2 { color: #1e3a5f; border-bottom: 2px solid #1e3a5f; padding-bottom: 6px; }
    .employee-details { background: #f8fafc; padding: 10px; border-left: 4px solid #1e3a5f; margin-bottom: 12px; }
    .summary-table { width: 100%; border-collapse: collapse; margin: 8px 0; }
    .summary-table td { padding: 5px 8px; border: none; }
    .summary-label { font-weight: bold; width: 40%; background-color: #f0f2f8; }
    .summary-value { width: 60%; background-color: #f8fafc; }
    .summary-row-alt td { background-color: #f8fafc; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 10px; }
    .data-table th { background-color: #1e3a5f; color: #ffffff; padding: 6px; text-align: left; }
    .data-table td { padding: 5px; border: 1px solid #ccc; }
    .present { background-color: #d4edda; color: #155724; }
    .absent { background-color: #f8d7da; color: #721c24; }
    .weekend { background-color: #e9ecef; color: #495057; }
    .footer-note { font-size: 8pt; color: #666; margin-top: 15px; }
</style>
</head>
<body>
HTML;

// Employee details
$html .= '<div class="employee-details">';
$html .= '<h2>' . e($user['name']) . '</h2>';
$html .= '<p><strong>Email:</strong> ' . e($user['email']) . ' | ';
$html .= '<strong>Department:</strong> ' . e($user['department']) . ' | ';
$html .= '<strong>Role:</strong> ' . e(ucfirst($user['role'])) . '</p>';
$html .= '<p><strong>Period:</strong> ' . e($periodLabel) . '</p>';
$html .= '</div>';

// Summary table (two‑column)
$html .= '
    <table class="summary-table">
        <tr>
            <td class="summary-label">Working Days</td>
            <td class="summary-value">' . $totalDays . '</td>
        </tr>
        <tr class="summary-row-alt">
            <td class="summary-label">Present (4h+)</td>
            <td class="summary-value" style="color:#155724;font-weight:bold;">' . $present . '</td>
        </tr>
        <tr>
            <td class="summary-label">Absent (&lt;4h)</td>
            <td class="summary-value" style="color:#721c24;font-weight:bold;">' . $absent . '</td>
        </tr>
        <tr class="summary-row-alt">
            <td class="summary-label">Total Logged</td>
            <td class="summary-value">' . e(formatMinutes($totalMinutes)) . '</td>
        </tr>
        <tr>
            <td class="summary-label">Total Hours</td>
            <td class="summary-value">' . number_format($totalHours, 1) . 'h</td>
        </tr>
        <tr class="summary-row-alt">
            <td class="summary-label">Attendance Rate</td>
            <td class="summary-value">' . number_format($attendanceRate, 1) . '%</td>
        </tr>
    </table>
';

// Daily table
$html .= '
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Day</th>
                <th>Attendance</th>
                <th>Logged</th>
                <th>Completed 4h</th>
                <th>Reason / Notes</th>
            </tr>
        </thead>
        <tbody>
';

foreach ($monthDates as $date) {
    $minutes = $dailyMinutes[$date] ?? 0;
    $dayNumber = (int) date('N', strtotime($date));
    $isWeekend = $dayNumber >= 6;
    $record = $attendanceNotes[$date] ?? null;

    if ($isWeekend) {
        $attendance = 'Weekend';
        $class = 'weekend';
        $completed = '—';
        $reason = 'Scheduled day off';
    } elseif ($minutes >= REQUIRED_DAILY_MINUTES) {
        $attendance = 'Present';
        $class = 'present';
        $completed = 'Yes';
        $reason = 'Completed at least 4 logged hours';
    } else {
        $attendance = 'Absent';
        $class = 'absent';
        $completed = 'No';
        if ($minutes > 0) {
            $reason = 'Logged less than 4 hours';
        } else {
            $reason = 'No logged work time';
        }
        if ($record && !empty($record['absence_reason'])) {
            $reason .= ' — ' . $record['absence_reason'];
        }
    }

    $html .= '<tr>';
    $html .= '<td>' . date('d M Y', strtotime($date)) . '</td>';
    $html .= '<td>' . date('D', strtotime($date)) . '</td>';
    $html .= '<td class="' . $class . '">' . e($attendance) . '</td>';
    $html .= '<td>' . e(formatMinutes($minutes)) . '</td>';
    $html .= '<td>' . e($completed) . '</td>';
    $html .= '<td>' . e($reason ?: '—') . '</td>';
    $html .= '</tr>';
}

$html .= '
        </tbody>
    </table>
';

// Footer notes
$html .= '
    <p class="footer-note">
        <em>Attendance is calculated from logged session time.</em>
    </p>
    <p class="footer-note">
        <em>Timesheet generated automatically by InvestHood.</em>
    </p>
';

$html .= '</body></html>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->lastPage();

// Output PDF
$filename = "Timesheet_{$user['name']}_" . date('Y_m', strtotime("$selectedYear-$selectedMonth-01")) . ".pdf";
$pdf->Output($filename, 'I');