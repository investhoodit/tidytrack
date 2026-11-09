<?php
require_once 'vendor/autoload.php';
require_once 'dp.php'; // Database connection

class MYPDF extends TCPDF {
    public function Header() {
        $logo = __DIR__ . '/logo.jpeg'; // Adjust path if needed
        if (file_exists($logo)) {
            $this->Image($logo, 15, 10, 30); // x, y, width
        }

        $this->SetY(10);
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 5, 'Report generated on: ' . date('Y-m-d H:i'), 0, 1, 'R');
        $this->SetY(35);
    }

    public function Footer() {
        $this->SetY(-15); // 15mm from bottom
        $this->SetFont('helvetica', 'I', 8);

        // Left side text
        $this->Cell(0, 10, 'InvestHood_IT', 0, 0, 'L');

        // Right side page number
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'R');
    }
}

$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('InvestHood');
$pdf->SetAuthor('Team Leader');
$pdf->SetTitle('Team Leader Report');
$pdf->SetMargins(15, 40, 15); // top margin for header
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->AddPage();

$query = "SELECT task_id, taskTitle, description, assignTo, priority, dueDate, status FROM tasks ORDER BY dueDate";
$result = $conn->query($query);

// Title in #1d3557 (RGB 29,53,87)
$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetTextColor(29, 53, 87);
$pdf->Cell(0, 15, 'Team Leader Report', 0, 1, 'C');

// Number of records summary below title
$taskCount = ($result) ? $result->num_rows : 0;
$pdf->SetFont('helvetica', 'I', 11);
$pdf->Cell(0, 10, "Total number of records: $taskCount", 0, 1, 'C');

$pdf->Ln(5);

$headers = ['ID', 'Title', 'Description', 'Assigned To', 'Priority', 'Due Date', 'Status'];
$widths  = [10, 25, 45, 25, 20, 25, 25];

// Table headers in uppercase and blue
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->SetTextColor(29, 53, 87);

foreach ($headers as $i => $header) {
    $pdf->Cell($widths[$i], 8, strtoupper($header), 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0); // reset color for table rows

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $data = [
            $row['id'],
            $row['taskTitle'],
            $row['description'],
            $row['assignTo'],
            $row['priority'],
            $row['dueDate'],
            $row['status'],
        ];

        foreach ($data as $i => $text) {
            if ($headers[$i] === 'Status') {
                $status = strtolower($text);
                if ($status === 'completed') {
                    $pdf->SetFillColor(0, 100, 0); // dark green
                } elseif ($status === 'pending') {
                    $pdf->SetFillColor(139, 0, 0); // dark red
                } else {
                    $pdf->SetFillColor(230, 230, 230); // gray
                }
            } else {
                $pdf->SetFillColor(255, 255, 255); // white
            }

            $pdf->MultiCell($widths[$i], 8, $text, 1, 'L', true, 0, '', '', true, 0, false, true, 8, 'M');
        }
        $pdf->Ln();
    }
} else {
    $pdf->Cell(0, 8, 'No task data found.', 1, 1, 'C');
}

$pdf->Output('team_leader_report.pdf', 'I');
