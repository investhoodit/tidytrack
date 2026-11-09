<?php
session_start();
require 'dp.php';  // Your database connection

// No role or user check here — open to all visitors

// Include TCPDF library
require_once('TCPDF/library/tcpdf.php');

// Custom PDF class
class MYPDF extends TCPDF {
    public function Header() {
        $logo = __DIR__ . '/INVESTHOOD.jpg'; 
        if (file_exists($logo)) {
            $this->Image($logo, 15, 10, 25);
        }
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 15, 'INVESTHOOD IT', 0, false, 'C');
        $this->Ln(8);
        $this->SetFont('helvetica', '', 12);
        $this->Cell(0, 15, 'Prepared By: Manager', 0, false, 'C');
        $this->Ln(10);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 9);
        $this->Cell(0, 10, 'Generated on ' . date('Y-m-d') . ' | Page ' .
            $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C');
    }
}

// Fetch projects with leader info
$sql = "SELECT 
            p.id, 
            p.name AS project_name, 
            p.deliverable, 
            p.start_date, 
            p.end_date, 
            u.name AS leader_name 
        FROM projects p 
        LEFT JOIN users u ON p.teamLeader_ID = u.userID 
        ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();

$today = date('Y-m-d');
$active_projects = [];
$completed_projects = [];

while ($row = $result->fetch_assoc()) {
    // Fetch tasks for this project
    $taskStmt = $conn->prepare("SELECT t.taskTitle FROM tasks t 
        INNER JOIN project_tasks pt ON pt.task_id = t.taskID 
        WHERE pt.project_id = ?");
    $taskStmt->bind_param("i", $row['id']);
    $taskStmt->execute();
    $taskResult = $taskStmt->get_result();

    $tasks = [];
    while ($taskRow = $taskResult->fetch_assoc()) {
        $tasks[] = $taskRow['taskTitle'];
    }
    $taskStmt->close();

    $project = [
        'name' => $row['project_name'],
        'deliverable' => $row['deliverable'],
        'tasks' => implode("\n", $tasks),
        'start_date' => $row['start_date'],
        'end_date' => $row['end_date'],
        'leader' => $row['leader_name'] ?? 'N/A'
    ];

    if ($row['end_date'] >= $today) {
        $active_projects[] = $project;
    } else {
        $completed_projects[] = $project;
    }
}

// Create PDF
$pdf = new MYPDF();
$pdf->SetCreator('Investhood TSM');
$pdf->SetAuthor('Investhood TSM');
$pdf->SetTitle('Project Report');
$pdf->SetMargins(15, 35, 15);
$pdf->AddPage();

// Title
$pdf->SetFont('helvetica', 'B', 18);
$pdf->Cell(0, 10, 'Project Report', 0, 1, 'C');
$pdf->Ln(4);

// Chart function
function drawBarChart($pdf, $activeCount, $completedCount) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Project Statistics', 0, 1);

    $x = 30;
    $y = $pdf->GetY() + 5;
    $barWidth = 50;
    $maxHeight = 40;
    $maxValue = max($activeCount, $completedCount, 1);

    $activeHeight = ($activeCount / $maxValue) * $maxHeight;
    $completedHeight = ($completedCount / $maxValue) * $maxHeight;

    // Active bar
    $pdf->SetFillColor(40, 167, 69); // green
    $pdf->Rect($x, $y + ($maxHeight - $activeHeight), $barWidth, $activeHeight, 'F');
    $pdf->SetXY($x, $y + $maxHeight + 2);
    $pdf->Cell($barWidth, 5, 'Active (' . $activeCount . ')', 0, 0, 'C');

    // Completed bar
    $x2 = $x + $barWidth + 40;
    $pdf->SetFillColor(108, 117, 125); // gray
    $pdf->Rect($x2, $y + ($maxHeight - $completedHeight), $barWidth, $completedHeight, 'F');
    $pdf->SetXY($x2, $y + $maxHeight + 2);
    $pdf->Cell($barWidth, 5, 'Completed (' . $completedCount . ')', 0, 1, 'C');

    $pdf->Ln(10);
}
drawBarChart($pdf, count($active_projects), count($completed_projects));

// Render projects section
function renderProjectSection($pdf, $projects, $title, $color) {
    if (empty($projects)) return;

    $pdf->SetFillColor(...$color);
    $pdf->SetTextColor(255);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, $title, 0, 1, 'L', true);
    $pdf->Ln(3);

    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', '', 11);

    foreach ($projects as $index => $proj) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Project ' . ($index + 1) . ': ' . $proj['name'], 0, 1);
        $pdf->SetFont('helvetica', '', 11);

        $pdf->Cell(40, 7, 'Deliverable:', 0, 0);
        $pdf->MultiCell(0, 7, $proj['deliverable'], 0, 'L');

        $pdf->Cell(40, 7, 'Start Date:', 0, 0);
        $pdf->Cell(0, 7, date("M d, Y", strtotime($proj['start_date'])), 0, 1);

        $pdf->Cell(40, 7, 'End Date:', 0, 0);
        $pdf->Cell(0, 7, date("M d, Y", strtotime($proj['end_date'])), 0, 1);

        $pdf->Cell(40, 7, 'Team Leader:', 0, 0);
        $pdf->Cell(0, 7, $proj['leader'], 0, 1);

        $pdf->Cell(40, 7, 'Tasks:', 0, 1);
        $tasks = explode("\n", $proj['tasks']);
        foreach ($tasks as $task) {
            $pdf->Cell(45);
            $pdf->Cell(0, 6, '- ' . trim($task), 0, 1);
        }

        $pdf->Ln(6);
    }

    $pdf->Ln(5);
}

// Render active & completed projects
renderProjectSection($pdf, $active_projects, 'Active Projects', [40, 167, 69]);
renderProjectSection($pdf, $completed_projects, 'Completed Projects', [108, 117, 125]);

// Signatures page
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Signatures', 0, 1, 'L');
$pdf->Ln(10);

$pdf->SetFont('helvetica', '', 12);
$lineWidth = 60;
$spaceBelow = 15;

$pdf->Cell($lineWidth, 0, '', 'B');
$pdf->Cell(40);
$pdf->Cell($lineWidth, 0, '', 'B');
$pdf->Ln($spaceBelow);

$pdf->Cell($lineWidth, 5, 'Project Leader Signature', 0, 0, 'L');
$pdf->Cell(40);
$pdf->Cell($lineWidth, 5, 'Manager Signature', 0, 1, 'L');

// Output PDF to browser
$pdf->Output('project_report.pdf', 'I');
exit;
