<?php
require_once 'vendor/autoload.php';
require '../../dp.php'; // your database connection

class MYPDF extends TCPDF {
    // Page header
    public function Header() {
        // Logo
        $logo = __DIR__ . '/logo.jpeg';
        if(file_exists($logo)){
            $this->Image($logo, 15, 10, 30);
        }

        // Generated date/time top-right
        $this->SetFont('helvetica', '', 10);
        $this->SetXY(-60, 15);
        $this->Cell(50, 5, 'Generated: ' . date('Y-m-d H:i:s'), 0, 0, 'R');
    }

    // Page footer
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().' of '.$this->getAliasNbPages(), 0, 0, 'C');
    }
}

// Create PDF
$pdf = new MYPDF();
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Team Leader');
$pdf->SetTitle('Attendance Register');
$pdf->SetMargins(15, 40, 15); // left, top, right
$pdf->SetAutoPageBreak(TRUE, 25);
$pdf->AddPage();

// Title
$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetTextColor(29, 53, 87); // #1d3557
$pdf->Cell(0, 15, 'Attendance Register', 0, 1, 'C');
$pdf->Ln(5);

// Table header
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(200, 200, 200); // light grey
$colWidths = [50, 60, 30, 30]; // Name, Email, Role, Attendance
$startX = ($pdf->GetPageWidth() - array_sum($colWidths)) / 2; // center table
$pdf->SetX($startX);
$pdf->Cell($colWidths[0], 10, 'Name', 1, 0, 'C', 1);
$pdf->Cell($colWidths[1], 10, 'Email', 1, 0, 'C', 1);
$pdf->Cell($colWidths[2], 10, 'Role', 1, 0, 'C', 1);
$pdf->Cell($colWidths[3], 10, 'Attendance', 1, 1, 'C', 1);

// Fetch data
$query = "SELECT name, email, role, status FROM users";
$result = $conn->query($query);

// Table content
$pdf->SetFont('helvetica', '', 10);
$fill = 0; // alternating row fill
while($row = $result->fetch_assoc()){
    $pdf->SetX($startX);
    $pdf->Cell($colWidths[0], 8, $row['name'], 1, 0, 'L', $fill);
    $pdf->Cell($colWidths[1], 8, $row['email'], 1, 0, 'L', $fill);

    // Restrict role to Member or Leader
    $role = strtolower($row['role']);
    $roleText = ($role === 'member') ? 'Member' : 'Leader';
    $pdf->Cell($colWidths[2], 8, $roleText, 1, 0, 'C', $fill);

    // Attendance: Present if status = 'ready', else Absent
    $attendance = ($row['status'] === 'ready') ? 'Present' : 'Absent';
    $pdf->Cell($colWidths[3], 8, $attendance, 1, 1, 'C', $fill);

    $fill = !$fill; // alternate row color
}

// Output PDF
$pdf->Output('attendance_register.pdf', 'I');
?>
