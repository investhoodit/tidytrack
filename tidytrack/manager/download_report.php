<?php
require_once('TCPDF/library/tcpdf.php'); // Adjust the path if needed

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
        $this->SetY(-20);
        $this->SetFont('helvetica', 'I', 9);
        $this->Cell(0, 10, 'Generated on ' . date('Y-m-d') . ' | Page ' .
            $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C');
    }
}

// Load data
$project_names = $_POST['project_name'] ?? [];
$deliverables = $_POST['deliverable'] ?? [];
$tasks_lists = $_POST['tasks'] ?? [];
$start_dates = $_POST['start_date'] ?? [];
$end_dates = $_POST['end_date'] ?? [];
$leader_names = $_POST['leader_name'] ?? [];

if (empty($project_names)) {
    die("No project data to generate report.");
}

// Group projects by status
$today = date('Y-m-d');
$active_projects = [];
$completed_projects = [];

for ($i = 0; $i < count($project_names); $i++) {
    $project = [
        'name' => $project_names[$i],
        'deliverable' => $deliverables[$i],
        'tasks' => $tasks_lists[$i],
        'start_date' => $start_dates[$i],
        'end_date' => $end_dates[$i],
        'leader' => $leader_names[$i]
    ];

    if ($end_dates[$i] >= $today) {
        $active_projects[] = $project;
    } else {
        $completed_projects[] = $project;
    }
}

// Start PDF
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

// ===== 📊 Draw Chart =====
function drawBarChart($pdf, $activeCount, $completedCount) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Project Statistics', 0, 1);

    $x = 30;
    $y = $pdf->GetY() + 5;

    $barWidth = 50;
    $maxHeight = 40;
    $maxValue = max($activeCount, $completedCount, 1);

    // Active
    $activeHeight = ($activeCount / $maxValue) * $maxHeight;
    $pdf->SetFillColor(40, 167, 69); // green
    $pdf->Rect($x, $y + ($maxHeight - $activeHeight), $barWidth, $activeHeight, 'F');
    $pdf->SetXY($x, $y + $maxHeight + 2);
    $pdf->Cell($barWidth, 5, 'Active (' . $activeCount . ')', 0, 0, 'C');

    // Completed
    $completedHeight = ($completedCount / $maxValue) * $maxHeight;
    $x2 = $x + $barWidth + 40;
    $pdf->SetFillColor(108, 117, 125); // gray
    $pdf->Rect($x2, $y + ($maxHeight - $completedHeight), $barWidth, $completedHeight, 'F');
    $pdf->SetXY($x2, $y + $maxHeight + 2);
    $pdf->Cell($barWidth, 5, 'Completed (' . $completedCount . ')', 0, 1, 'C');

    $pdf->Ln(10);
}

// Call chart renderer
drawBarChart($pdf, count($active_projects), count($completed_projects));

// ===== 🔍 Project Sections =====
function renderSection($pdf, $projects, $title, $bgColor) {
    if (empty($projects)) return;

    $pdf->SetFillColor(...$bgColor);
    $pdf->SetTextColor(255);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, $title, 0, 1, 'L', true);
    $pdf->Ln(3);

    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', '', 11);

    foreach ($projects as $proj) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Project: ' . $proj['name'], 0, 1);
        $pdf->SetFont('helvetica', '', 11);

        $pdf->Cell(40, 7, 'Deliverable:', 0, 0);
        $pdf->MultiCell(0, 7, $proj['deliverable'], 0, 'L');

        $pdf->Cell(40, 7, 'Tasks:', 0, 1);
        $pdf->MultiCell(0, 7, $proj['tasks'], 0, 'L');

        $pdf->Cell(40, 7, 'Start Date:', 0, 0);
        $pdf->Cell(0, 7, date("M d, Y", strtotime($proj['start_date'])), 0, 1);

        $pdf->Cell(40, 7, 'End Date:', 0, 0);
        $pdf->Cell(0, 7, date("M d, Y", strtotime($proj['end_date'])), 0, 1);

        $pdf->Cell(40, 7, 'Leader:', 0, 0);
        $pdf->Cell(0, 7, $proj['leader'], 0, 1);

        $pdf->Ln(6);
    }

    $pdf->Ln(4);
}

renderSection($pdf, $active_projects, 'Active Projects', [40, 167, 69]);
renderSection($pdf, $completed_projects, 'Completed Projects', [108, 117, 125]);

// ===== ✍️ Signature Section =====
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Signatures', 0, 1, 'L');
$pdf->Ln(10);

// Line and label
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

// Output PDF
$pdf->Output('project_report.pdf', 'D');
exit;
