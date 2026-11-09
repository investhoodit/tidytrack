<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include '../dp.php';
include '../track2.php';

// ------------------------
// MANUAL PHPSPREADSHEET INCLUDES
// ------------------------
require_once 'PhpSpreadsheet/Cell/IValueBinder.php';
require_once 'PhpSpreadsheet/Cell/DefaultValueBinder.php';
require_once 'PhpSpreadsheet/Cell/Cell.php';
require_once 'PhpSpreadsheet/Cell/DataType.php';
require_once 'PhpSpreadsheet/Shared/StringHelper.php';
require_once 'PhpSpreadsheet/Shared/Date.php';
require_once 'PhpSpreadsheet/Shared/XMLWriter.php';
require_once 'PhpSpreadsheet/Worksheet/Worksheet.php';
require_once 'PhpSpreadsheet/Worksheet/Protection.php';
require_once 'PhpSpreadsheet/Style/Style.php';
require_once 'PhpSpreadsheet/Style/Fill.php';
require_once 'PhpSpreadsheet/Style/Color.php';
require_once 'PhpSpreadsheet/Spreadsheet.php';
require_once 'PhpSpreadsheet/Writer/BaseWriter.php';
require_once 'PhpSpreadsheet/Writer/Xlsx.php';


// ------------------------
// USE NAMESPACES
// ------------------------
use PhpSpreadsheet\Spreadsheet;
use PhpSpreadsheet\Writer\Xlsx;
use PhpSpreadsheet\Style\Fill;
use PhpSpreadsheet\Style\Color;
use PhpSpreadsheet\Worksheet\Protection;

// ------------------------
// Get logged-in user
// ------------------------
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header("Location: login.php");
    exit();
}

// ------------------------
// Create Spreadsheet
// ------------------------
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Attendance ' . date('F Y'));

// ------------------------
// Headers
// ------------------------
$sheet->setCellValue('A1', 'Date')
      ->setCellValue('B1', 'Attendance')
      ->setCellValue('C1', 'Absence Reason');

$sheet->getStyle('A1:C1')->getFont()->setBold(true);
$sheet->getStyle('A1:C1')->getFill()
      ->setFillType(Fill::FILL_SOLID)
      ->getStartColor()->setRGB('D9E1F2');

// ------------------------
// Fill attendance data
// ------------------------
$currentMonth = date('m');
$currentYear = date('Y');
$daysInMonth = date('t');
$row = 2;

for ($day = 1; $day <= $daysInMonth; $day++) {
    $date = sprintf("%s-%s-%02d", $currentYear, $currentMonth, $day);
    $isWeekend = (date('N', strtotime($date)) >= 6);

    if ($isWeekend) {
        $attendance = 'Off Day';
        $reason = '-';
    } else {
        // Get total minutes for the day
        $stmt = $conn->prepare("SELECT SUM(minutes) as totalMinutes FROM user_sessions WHERE user_id = ? AND session_date = ?");
        $stmt->bind_param("is", $userId, $date);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $totalMinutes = $result['totalMinutes'] ?? 0;
        $stmt->close();

        $attendance = ($totalMinutes >= 240) ? 'Yes' : 'No';

        // Absence reason
        $stmt = $conn->prepare("SELECT absence_reason FROM attendance_log WHERE user_id = ? AND date = ?");
        $stmt->bind_param("is", $userId, $date);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $reason = $res['absence_reason'] ?? '';
        $stmt->close();
    }

    $sheet->setCellValue("A$row", $date)
          ->setCellValue("B$row", $attendance)
          ->setCellValue("C$row", $reason);

    // Color-code
    if ($attendance === 'Yes') {
        $sheet->getStyle("B$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('C6EFCE'); // green
    } elseif ($attendance === 'No') {
        $sheet->getStyle("B$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC7CE'); // red
    } else {
        $sheet->getStyle("B$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFEB9C'); // yellow
    }

    $row++;
}

// ------------------------
// Protect sheet
// ------------------------
$protection = $sheet->getProtection();
$protection->setSheet(true);
$protection->setPassword('tidytracker20'); 
$protection->setSort(true);
$protection->setInsertRows(true);

// ------------------------
// Auto-size columns
// ------------------------
foreach (range('A','C') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ------------------------
// Send file to browser
// ------------------------
$filename = 'attendance_' . date('F_Y') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();