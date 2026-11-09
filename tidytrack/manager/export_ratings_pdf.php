<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../dp.php';

// POST validation
if(!isset($_POST['employee_id']) || $_POST['employee_id']=="") die("No employee selected!");
$employee_id = intval($_POST['employee_id']);
$feedback = trim($_POST['feedback'] ?? '');
$ratings = $_POST['rating'] ?? [];

// Get employee info
$empResult = $conn->query("SELECT name FROM users WHERE user_id=$employee_id LIMIT 1");
if($empResult->num_rows==0) die("Employee not found!");
$employee_name = $empResult->fetch_assoc()['name'];

// Save ratings to database
$feedback_safe = $conn->real_escape_string($feedback);
foreach($ratings as $criteria => $rate){
    $criteria_safe = $conn->real_escape_string($criteria);
    $rate_int = intval($rate);
    $conn->query("INSERT INTO employee_ratings (employee_id, criteria, rating, feedback, created_at) 
                 VALUES ($employee_id, '$criteria_safe', $rate_int, '$feedback_safe', NOW())");
}

// --- Generate PDF ---
$pdf = new TCPDF();
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Investhood');
$pdf->SetTitle('Performance Rating Report');
$pdf->SetMargins(15, 25, 15);
$pdf->AddPage();

// --- Logo & Header ---
$logoPath = __DIR__.'/logo.jpeg';
if(file_exists($logoPath)) $pdf->Image($logoPath,15,10,30,30);

$pdf->SetFont('helvetica','B',18);
$pdf->SetY(15);
$pdf->Cell(0,10,'Investhood Task Management',0,1,'C');

$pdf->SetFont('helvetica','B',14);
$pdf->Ln(5);
$pdf->Cell(0,10,'Performance Rating Report',0,1,'C');
$pdf->Ln(5);

// Employee Name
$pdf->SetFont('helvetica','',12);
$pdf->Cell(0,8,'Employee: '.$employee_name,0,1,'C');
$pdf->Ln(8);

// --- Ratings Table ---
$pdf->SetFont('helvetica','B',12);
$pdf->SetFillColor(29,53,87);
$pdf->SetTextColor(255,255,255);
$pdf->Cell(130,10,'Criteria',1,0,'C',true);
$pdf->Cell(40,10,'Rating',1,1,'C',true);

$pdf->SetFont('helvetica','',12);
$pdf->SetTextColor(0,0,0);
$fill = false;

foreach($ratings as $c => $r){
    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
    $pdf->MultiCell(130,10,$c,1,'L',$fill,0);
    $pdf->Cell(40,10,intval($r),1,1,'C',$fill);
    $fill = !$fill;
}

// --- Feedback Section ---
$pdf->Ln(8);
$pdf->SetFont('helvetica','B',12);
$pdf->Cell(0,10,'Manager Feedback / Comments',0,1);
$pdf->SetFont('helvetica','',12);
$pdf->SetFillColor(245,245,245);
$pdf->MultiCell(0,25,(!empty($feedback)?"“".$feedback."”":"No feedback provided."),1,'L',true);

// --- Timestamp ---
$pdf->Ln(10);
$pdf->SetFont('helvetica','I',10);
$pdf->Cell(0,10,'Generated on: '.date("Y-m-d H:i:s"),0,1,'R');

// --- Output PDF ---
$pdf->Output('rating_report.pdf','I');
