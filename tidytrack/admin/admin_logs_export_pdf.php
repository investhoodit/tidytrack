<?php
session_start();
require_once __DIR__ . '/../dp.php';
require_once('TCPDF/library/tcpdf.php');

// ---- AUTH GUARD ----
if (!($_SESSION['user_id'] ?? null) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403); echo "Forbidden"; exit;
}

// ---- Column checks ----
$hasUserName = (bool)$conn->query("SHOW COLUMNS FROM users LIKE 'name'")->num_rows;
$hasDescription = (bool)$conn->query("SHOW COLUMNS FROM user_activity LIKE 'description'")->num_rows;

// ---- Filters ----
$roleF = $_GET['role'] ?? '';
$userQ = trim($_GET['user'] ?? '');
$from  = $_GET['from'] ?? '';
$to    = $_GET['to'] ?? '';

// ---- WHERE builder ----
$where=[]; $params=[]; $types='';

if ($roleF!==''){ $where[]="u.role=?"; $params[]=$roleF; $types.='s'; }
if ($userQ!==''){ $where[]="(u.name LIKE ? OR u.email LIKE ?)"; $params[]="%$userQ%"; $params[]="%$userQ%"; $types.='ss'; }
if ($from!==''){ $where[]="DATE(a.timestamp)>=?"; $params[]=$from; $types.='s'; }
if ($to  !==''){ $where[]="DATE(a.timestamp)<=?"; $params[]=$to;   $types.='s'; }

$whereSql = $where ? "WHERE " . implode(" AND ",$where) : "";

$nameField = $hasUserName ? "u.name" : "u.email";
$fields = "a.timestamp, $nameField AS display_name, u.email, u.role,
           a.activity_type, a.ip_address, a.user_agent";

if ($hasDescription) $fields .= ", a.description";

$sql = "SELECT $fields FROM user_activity a 
        JOIN users u ON u.user_id=a.user_id 
        $whereSql 
        ORDER BY a.timestamp DESC";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


/* ===========================================================
   CUSTOM HEADER & FOOTER CLASS
   =========================================================== */
class CustomPDF extends TCPDF {

    // ---- PAGE HEADER ----
    public function Header() {

        // Header Background
        $this->SetFillColor(29, 53, 87);
        $this->Rect(0, 0, $this->getPageWidth(), 45, 'F');

        // Company Logo
        $img = $_SERVER['DOCUMENT_ROOT'] . '/Investhood_TSM/investhood/images/INVESTHOOD.jpg';
        if (file_exists($img)) {
            $this->Image($img, 15, 10, 28); // Adjusted ratio
        }

        // Title
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(50, 10);
        $this->Cell(0, 10, 'Investhood IT', 0, 1, 'L');

        // Address
        $this->SetFont('helvetica', '', 10);
        $this->SetX(50);
        $this->MultiCell(0, 5,
            "136 2nd St Randjespark\nJohannesburg, 1685\nSouth Africa\nPhone: 075 333 8142",
            0, 'L'
        );

        // ---- NEW: Separator Line Below Header ----
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.4);
        $this->Line(10, 46, $this->getPageWidth() - 10, 46);

        // ---- NEW: small padding below header ----
        $this->Ln(8);
    }

    // ---- PAGE FOOTER ----
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().' of '.$this->getAliasNbPages(), 0, 0, 'C');
    }
}


/* ===========================================================
   TCPDF INIT
   =========================================================== */
$pdf = new CustomPDF('L', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Investhood System');
$pdf->SetAuthor('Admin');
$pdf->SetTitle('Activity Logs Export');

$pdf->SetMargins(10, 55, 10); // top = 55mm to match header
$pdf->SetAutoPageBreak(true, 20); // leave space for footer
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 9);


/* ===========================================================
   BUILD HTML TABLE
   =========================================================== */
$html = '<h4 style="text-align:center; margin-top:5px;">Filtered Activity Logs</h4>';

$html .= '<table border="1" cellpadding="4" cellspacing="0">
<thead>
<tr style="background-color:#f0f0f0; font-weight:bold;">
    <th>Timestamp</th>
    <th>User</th>
    <th>Email</th>
    <th>Role</th>
    <th>Activity</th>';

if ($hasDescription) 
    $html .= '<th>Description</th>';

$html .= '
    <th>IP Address</th>
    <th>User Agent</th>
</tr>
</thead>
<tbody>';

foreach ($rows as $r) {
    $html .= '<tr>
        <td>'.htmlspecialchars($r['timestamp']).'</td>
        <td>'.htmlspecialchars($r['display_name']).'</td>
        <td>'.htmlspecialchars($r['email']).'</td>
        <td>'.htmlspecialchars($r['role']).'</td>
        <td>'.htmlspecialchars($r['activity_type']).'</td>';

    if ($hasDescription)
        $html .= '<td>'.htmlspecialchars($r['description'] ?? '').'</td>';

    $html .= '
        <td>'.htmlspecialchars($r['ip_address']).'</td>
        <td>'.htmlspecialchars($r['user_agent']).'</td>
    </tr>';
}

$html .= '</tbody></table>';


/* ===========================================================
   OUTPUT PDF SAFELY
   =========================================================== */
ob_clean();            // remove any accidental output
$pdf->writeHTML($html);
$pdf->Output('activity_logs_' . date('Y-m-d') . '.pdf', 'D');
exit;
