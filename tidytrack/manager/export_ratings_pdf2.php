<?php
require_once __DIR__ . '/vendor/autoload.php'; // Composer autoload for TCPDF

// Database connection
$conn = new mysqli("localhost", "root", "", "tsm_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get employee_id from request
if (!isset($_GET['employee_id']) || $_GET['employee_id'] === "") {
    die("No employee selected!");
}

$employee_id = intval($_GET['employee_id']);

// ✅ Get employee name from users table
$empResult = $conn->query("SELECT name FROM users WHERE user_id = $employee_id LIMIT 1");
if ($empResult->num_rows === 0) {
    die("Employee not found!");
}
$empRow = $empResult->fetch_assoc();
$employee_name = $empRow['name'];

// ✅ Fetch ratings for the employee (latest session of ratings)
$sql = "SELECT criteria, rating, feedback, created_at 
        FROM employee_ratings 
        WHERE employee_id = $employee_id 
        ORDER BY created_at DESC";
$result = $conn->query($sql);

if ($result->num_rows === 0) {
    die("No ratings available for this employee");
}

// Get first row feedback (since only one feedback is needed)
$firstRow = $result->fetch_assoc();
$feedback = trim($firstRow['feedback']); // remove extra spaces
$result->data_seek(0); // rewind for table

// Create new PDF document
$pdf = new TCPDF();
$pdf->AddPage();

// ✅ Add logo top-left
$pdf->Image('logo.jpeg', 10, 10, 20, 20);

// Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 15, 'Performance Rating Report for: ' . $employee_name, 0, 1, 'C');

// Line break
$pdf->Ln(10);

// Ratings Table
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(120, 10, 'Criteria', 1);
$pdf->Cell(60, 10, 'Rating', 1);
$pdf->Ln();

$pdf->SetFont('helvetica', '', 12);
while ($row = $result->fetch_assoc()) {
    $pdf->Cell(120, 10, $row['criteria'], 1);
    $pdf->Cell(60, 10, $row['rating'], 1);
    $pdf->Ln();
}

// Feedback / Comments Section (single, clean sentence)
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'Manager Feedback / Comments:', 0, 1);

$pdf->SetFont('helvetica', '', 12);
if (!empty($feedback)) {
    $pdf->MultiCell(0, 0, "The manager provided the following feedback: \n\n" . $feedback, 1, 'L', false, 1);
} else {
    $pdf->MultiCell(0, 0, "No feedback was provided for this evaluation.", 1, 'L', false, 1);
}

// Timestamp
$pdf->Ln(10);
$pdf->Cell(0, 10, 'Generated on ' . date("Y-m-d H:i:s"), 0, 1, 'R');

// Output PDF
$pdf->Output('rating_report.pdf', 'I');
?>
