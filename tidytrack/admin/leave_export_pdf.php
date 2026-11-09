<?php
session_start();
require_once __DIR__ . '/../dp.php';

// REQUIRE TCPDF
require_once('TCPDF/library/tcpdf.php');

// VALIDATE
if (!isset($_GET['id'])) { die("Missing ID"); }
$id = (int) $_GET['id'];

$userId = $_SESSION['user_id'] ?? null;
$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || !in_array($role, ['admin','manager'], true)) { die("Forbidden"); }

// FETCH REQUEST
$sql = "SELECT lr.*, 
               COALESCE(u.name, u.email) AS employee_ref,
               u.name AS employee_name
        FROM leave_requests lr
        LEFT JOIN users u ON u.user_id = lr.employee_id
        WHERE lr.id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$detail = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$detail) { die("Request not found."); }

// FETCH ATTACHMENTS
$files = [];
$check = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_files'");
if ($check && $check->num_rows) {
    $fstmt = $conn->prepare("SELECT file_name, file_path, created_at 
                             FROM leave_files 
                             WHERE leave_request_id = ?
                             ORDER BY id DESC");
    $fstmt->bind_param('i', $id);
    $fstmt->execute();
    $files = $fstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fstmt->close();
}

/* ================================================================
   BRAND-STYLE HEADER & FOOTER (Copied from your second script)
================================================================ */
class StyledPDF extends TCPDF {

    // ADD: Watermark text
    protected $watermarkText = "CONFIDENTIAL";

    public function Header() {

        // 🔹 WATERMARK (behind everything)
        $this->SetAlpha(0.08);  // Transparency level
        $this->SetFont('helvetica', 'B', 55);
        $this->SetTextColor(193, 18, 31); // Investhood Red

        // Rotate and position watermark
        $this->StartTransform();
        $this->Rotate(45, $this->getPageWidth() / 2, $this->getPageHeight() / 2);
        $this->Text(30, $this->getPageHeight() / 2, $this->watermarkText);
        $this->StopTransform();

        // Reset alpha for normal content
        $this->SetAlpha(1);

        // 🔹 LOGO
        $img = $_SERVER['DOCUMENT_ROOT'] . '/Investhood_TSM/investhood/images/INVESTHOOD.jpg';
        if (file_exists($img)) {
            $this->Image($img, 15, 10, 30);
        }

        // 🔹 COMPANY NAME
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(10, 31, 68); 
        $this->SetXY(50, 15);
        $this->Cell(0, 10, 'Investhood IT', 0, 1, 'L');

        // 🔹 ADDRESS
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(120, 120, 120);
        $this->SetX(50);
        $this->MultiCell(
            0,
            5,
            "136 2nd St Randjespark\nJohannesburg, 1685\nSouth Africa\nPhone: 075 333 8142",
            0,
            'L'
        );
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(10, 31, 68);
        $this->Cell(
            0,
            10,
            'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(),
            0,
            false,
            'C'
        );
    }
}

/* ================================================================
   INIT PDF WITH HEADER & FOOTER
================================================================ */
$pdf = new StyledPDF();
$pdf->SetCreator('Leave Management System');
$pdf->SetAuthor('Investhood IT HR');
$pdf->SetTitle('Leave Request Report');

// Margins: leave space at top for header
$pdf->SetMargins(15, 50, 15);
$pdf->SetAutoPageBreak(true, 15);

/* ================================================================
   PAGE 1
================================================================ */
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11.5);

$html = "
<h2 style='color:#1d3557; margin-bottom:4px;'>Leave Request Details</h2>
<hr style='margin-bottom:10px;'>

<p><strong>Employee Information</strong></p>
<table cellspacing='0' cellpadding='6'>
<tr><td><strong>Name:</strong></td><td>{$detail['employee_name']}</td></tr>
<tr><td><strong>Reference:</strong></td><td>{$detail['employee_ref']}</td></tr>
</table>

<p style='margin-top:15px;'><strong>Leave Duration</strong></p>
<table cellspacing='0' cellpadding='6'>
<tr><td><strong>From:</strong></td><td>{$detail['start_date']}</td></tr>
<tr><td><strong>To:</strong></td><td>{$detail['end_date']}</td></tr>
<tr><td><strong>Type:</strong></td><td>" . ucfirst(str_replace('_',' ', $detail['leave_type'])) . "</td></tr>
</table>

<p style='margin-top:15px;'><strong>Reason</strong></p>
<p><strong>Category:</strong> {$detail['reason_category']}</p>
<p style='font-style:italic; border-left:3px solid #ccc; padding-left:10px;'>
" . nl2br(htmlspecialchars($detail['reason_text'])) . "
</p>

<p style='margin-top:15px;'><strong>Status & Manager Decision</strong></p>
<table cellspacing='0' cellpadding='6'>
<tr><td><strong>Status:</strong></td><td>{$detail['status']}</td></tr>
<tr><td><strong>Manager Comment:</strong></td><td>" . nl2br(htmlspecialchars($detail['comment'] ?: 'No comment')) . "</td></tr>
<tr><td><strong>Decided At:</strong></td><td>" . ($detail['decided_at'] ?: '—') . "</td></tr>
</table>

<p style='margin-top:15px;'><strong>Supporting Documents</strong></p>
";

if (empty($files)) {
    $html .= "<p>No attachments found.</p>";
} else {
    $html .= "<ul>";
    foreach ($files as $f) {
        $name = htmlspecialchars($f['file_name']);
        $created = htmlspecialchars($f['created_at']);
        $html .= "<li><strong>$name</strong> ($created)</li>";
    }
    $html .= "</ul>";
}

$html .= "
<hr style='margin-top:15px; margin-bottom:10px;'>
<p><strong>Submitted At:</strong> {$detail['created_at']}</p>
";

$pdf->writeHTML($html, true, false, true, false, '');

/* ================================================================
   PAGE 2 — POLICY
================================================================ */
$pdf->AddPage();

$policy = "
<h2 style='margin-bottom:4px;'>Company Leave Policy Notes</h2>
<hr style='margin-bottom:10px;'>

<p><strong>1. Leave Application:</strong> Employees must submit leave requests at least 3 days before the planned leave unless it is an emergency.</p>
<p><strong>2. Medical Leave:</strong> Medical certificates are required for sick leave extending beyond 2 days.</p>
<p><strong>3. Manager Approval:</strong> All leave applications require approval from the direct manager or HR.</p>
<p><strong>4. Unpaid Leave:</strong> Unpaid leave may apply when leave days are exhausted.</p>
<p><strong>5. Documentation:</strong> HR reserves the right to request supporting documents for verification.</p>
<p><strong>6. Policy Updates:</strong> Policies may change anytime; employees will be notified via internal communication.</p>
";

$pdf->writeHTML($policy, true, false, true, false, '');

/* ================================================================
   OUTPUT PDF
================================================================ */

$pdf->Output("leave-request-{$id}.pdf", 'I');
?>
