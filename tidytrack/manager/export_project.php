<?php
session_start();
require_once __DIR__ . '/../dp.php';
@include __DIR__ . '/../profile.php';

// Load TCPDF
require_once('TCPDF/library/tcpdf.php');

// Validate user & role
$userID      = $_SESSION['user_id'] ?? null;
$currentRole = $currentRole ?? ($_SESSION['role'] ?? 'manager');

if (!$userID || strtolower($currentRole) !== 'manager') {
    die("Unauthorized");
}

$itemType = $_GET['item_type'] ?? '';
$id       = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id || !in_array($itemType, ['project', 'sub_project'], true)) {
    die("Invalid request");
}

/* -----------------------------------------------------
   FETCH DATA (PROJECT / SUB-PROJECT)
------------------------------------------------------ */

if ($itemType === 'project') {

    // Main project
    $stmt = $conn->prepare("
        SELECT p.*, c.name AS creator_name
        FROM projects_x p
        LEFT JOIN users c ON p.created_by = c.user_id
        WHERE p.project_id = ?
    ");
  $stmt->bind_param("i", $id);

    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$project) die("Project not found or access denied");

    // Fetch all team leaders for this project
    $leaders_res = $conn->prepare("
        SELECT u.name
        FROM project_leader pl
        JOIN users u ON pl.teamLeadder_id = u.user_id
        WHERE pl.project_id = ?
    ");
    $leaders_res->bind_param("i", $id);
    $leaders_res->execute();
    $leaders_list = $leaders_res->get_result()->fetch_all(MYSQLI_ASSOC);
    $leaders_res->close();

    // Convert leaders array to comma-separated string
    $project['leaders'] = implode(", ", array_column($leaders_list, 'name'));

    // Project tasks
    $task_q = $conn->prepare("
        SELECT t.*, u.name AS assigned_user
        FROM tasks t
        LEFT JOIN users u ON t.assignTo = u.name
        WHERE t.project_id = ?
        ORDER BY t.dueDate ASC
    ");
    $task_q->bind_param("i", $id);
    $task_q->execute();
    $tasks = $task_q->get_result()->fetch_all(MYSQLI_ASSOC);
    $task_q->close();

    // Sub-projects
    $sp_q = $conn->prepare("
        SELECT s.*, u.name AS leader_name, c.name AS creator_name
        FROM sub_projects s
        LEFT JOIN users u ON s.teamLeadder_id = u.user_id
        LEFT JOIN users c ON s.created_by = c.user_id
        WHERE s.project_id = ? AND s.created_by = ?
        ORDER BY s.start_date ASC
    ");
    $sp_q->bind_param("ii", $id, $userID);
    $sp_q->execute();
    $sub_projects_res = $sp_q->get_result();

    $sub_projects = [];

    while ($sp = $sub_projects_res->fetch_assoc()) {

        $st = $conn->prepare("
            SELECT t.*, u.name AS assigned_user
            FROM tasks t
            LEFT JOIN users u ON t.assignTo = u.name
            WHERE t.sub_project_id = ?
            ORDER BY t.dueDate ASC
        ");
        $st->bind_param("i", $sp['sub_project_id']);
        $st->execute();
        $sp['tasks'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        $sub_projects[] = $sp;
    }

} else {

    // Single sub-project
    $stmt = $conn->prepare("
        SELECT s.*, p.project_name, u.name AS leader_name, c.name AS creator_name
        FROM sub_projects s
        JOIN projects_x p ON s.project_id = p.project_id
        LEFT JOIN users u ON s.teamLeadder_id = u.user_id
        LEFT JOIN users c ON s.created_by = c.user_id
        WHERE s.sub_project_id = ? AND p.created_by = ?
    ");
    $stmt->bind_param("ii", $id, $userID);
    $stmt->execute();
    $sub_project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sub_project) die("Sub-project not found or access denied");

    // Sub-project tasks
    $task_q = $conn->prepare("
        SELECT t.*, u.name AS assigned_user
        FROM tasks t
        LEFT JOIN users u ON t.assignTo = u.name
        WHERE t.sub_project_id = ?
        ORDER BY t.dueDate ASC
    ");
    $task_q->bind_param("i", $id);
    $task_q->execute();
    $tasks = $task_q->get_result()->fetch_all(MYSQLI_ASSOC);
    $task_q->close();
}

/* -----------------------------------------------------
   PDF BRAND COLORS
------------------------------------------------------ */

$PRIMARY = "#0A1F44";   // Dark Blue
$SECONDARY = "#C1121F"; // Red
$ACCENT = "#E5E5E5";    // Gray
$BACKGROUND = "#F7F7F7";

/* -----------------------------------------------------
   CREATE PDF (Brand Design)
------------------------------------------------------ */

class StyledPDF extends TCPDF {

    public function Header() {
        global $PRIMARY;

        // Logo
        $img = $_SERVER['DOCUMENT_ROOT'] . '/tidytracker/tidytrack/images/INVESTHOOD.jpg';
        if (file_exists($img)) {
            $this->Image($img, 15, 10, 30);
        }

        // Company name
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(10, 31, 68); // #0A1F44
        $this->SetXY(50, 15);
        $this->Cell(0, 10, 'Investhood IT', 0, 1, 'L');

        // Address
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
        global $PRIMARY;

        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(10, 31, 68); // Primary
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

$pdf = new StyledPDF();
$pdf->SetCreator('INVESTHOOD_TSM');
$pdf->SetMargins(15, 40, 15);
$pdf->AddPage();

/* -----------------------------------------------------
   TITLE
------------------------------------------------------ */

$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(10, 31, 68); // dark blue
$title = ($itemType === 'project') ? "Project Report" : "Sub-Project Report";
$pdf->Cell(0, 12, $title, 0, 1, 'C');
$pdf->Ln(5);

/* -----------------------------------------------------
   DETAILS TABLE (Brand Colors)
------------------------------------------------------ */

$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 10, 'Details', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 11);

$headerColor = $PRIMARY;
$borderColor = $SECONDARY;

if ($itemType === 'project') {

    $detailsHTML = '
    <table border="1" cellpadding="6" cellspacing="0" style="border-color:' . $borderColor . ';">
        <tr style="background-color:' . $headerColor . '; color:#fff;">
            <td width="30%"><b>Project Name</b></td>
            <td width="70%">' . htmlspecialchars($project['project_name']) . '</td>
        </tr>
        <tr><td><b>Deliverable</b></td><td>' . htmlspecialchars($project['deliverable']) . '</td></tr>
        <tr><td><b>Start Date</b></td><td>' . htmlspecialchars($project['start_date']) . '</td></tr>
        <tr><td><b>End Date</b></td><td>' . htmlspecialchars($project['end_date']) . '</td></tr>
        <tr><td><b>Team Leaders</b></td><td>' . htmlspecialchars($project['leaders']) . '</td></tr>
        <tr><td><b>Created By</b></td><td>' . htmlspecialchars($project['creator_name']) . '</td></tr>
    </table>';

} else {

    $detailsHTML = '
    <table border="1" cellpadding="6" cellspacing="0" style="border-color:' . $borderColor . ';">
        <tr style="background-color:' . $headerColor . '; color:#fff;">
            <td width="30%"><b>Sub-Project Name</b></td>
            <td width="70%">' . htmlspecialchars($sub_project['sub_project_name']) . '</td>
        </tr>
        <tr><td><b>Parent Project</b></td><td>' . htmlspecialchars($sub_project['project_name']) . '</td></tr>
        <tr><td><b>Deliverable</b></td><td>' . htmlspecialchars($sub_project['deliverable']) . '</td></tr>
        <tr><td><b>Start Date</b></td><td>' . htmlspecialchars($sub_project['start_date']) . '</td></tr>
        <tr><td><b>End Date</b></td><td>' . htmlspecialchars($sub_project['end_date']) . '</td></tr>
        <tr><td><b>Leader</b></td><td>' . htmlspecialchars($sub_project['leader_name']) . '</td></tr>
    </table>';
}

$pdf->writeHTML($detailsHTML, true, false, false, false, '');
$pdf->Ln(8);

/* -----------------------------------------------------
   TASK TABLE (Brand + Status Colors)
------------------------------------------------------ */

$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 10, 'Tasks', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 10);

if (empty($tasks)) {
    $pdf->MultiCell(0, 7, 'No tasks found.');
} else {

    $taskTable = '
    <table border="1" cellpadding="6" cellspacing="0" style="border-color:' . $SECONDARY . ';">
        <tr style="background-color:' . $PRIMARY . '; color:#fff; text-align:center;">
            <th width="20%">Task Title</th>
            <th width="18%">Assigned To</th>
            <th width="15%">Due Date</th>
            <th width="12%">Priority</th>
            <th width="15%">Status</th>
            <th width="20%">Description</th>
        </tr>';

    $today = new DateTime();

    foreach ($tasks as $t) {
        $due = new DateTime($t['dueDate']);
        $status = strtolower($t['status']);

        // Meaningful task status colors
        $statusColor = "#ffffff"; // default

        if ($status === 'completed') {
            $statusColor = "#d4edda"; // green
        } elseif ($status === 'in progress') {
            $statusColor = "#d1ecf1"; // blue
        } elseif ($status === 'pending') {
            $statusColor = "#e2e3e5"; // gray
        }

        if ($status !== 'completed') {
            if ($due < $today) {
                $status = 'overdue';
                $statusColor = "#f8d7da"; // red
            } elseif ($due->format('Y-m-d') == $today->format('Y-m-d')) {
                $status = 'due today';
                $statusColor = "#fff3cd"; // yellow
            }
        }

        $taskTable .= '
        <tr style="background-color:' . $statusColor . ';">
            <td>' . htmlspecialchars($t['taskTitle']) . '</td>
            <td>' . htmlspecialchars($t['assigned_user'] ?? 'Unassigned') . '</td>
            <td align="center">' . htmlspecialchars($t['dueDate']) . '</td>
            <td align="center">' . htmlspecialchars($t['priority']) . '</td>
            <td align="center">' . ucfirst($status) . '</td>
            <td>' . htmlspecialchars($t['description'] ?? '') . '</td>
        </tr>';
    }

    $taskTable .= '</table>';

    $pdf->writeHTML($taskTable, true, false, false, false, '');
}

/* -----------------------------------------------------
   SUB-PROJECTS (ONLY FOR PROJECTS)
------------------------------------------------------ */

if ($itemType === 'project') {

    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(10, 31, 68);
    $pdf->Cell(0, 10, 'Sub-Projects', 0, 1, 'L');
    $pdf->Ln(5);

    if (empty($sub_projects)) {
        $pdf->MultiCell(0, 7, 'No sub-projects.');
    } else {
        foreach ($sub_projects as $sp) {

            // Sub-project title
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetTextColor(10, 31, 68);
            $pdf->Cell(0, 8, $sp['sub_project_name'], 0, 1);

            // Sub-project details
            $pdf->SetFont('helvetica', '', 10);

            $subHTML = '
            <table border="1" cellpadding="6" cellspacing="0" style="border-color:' . $SECONDARY . ';">
                <tr><td width="30%"><b>Leader</b></td><td>' . $sp['leader_name'] . '</td></tr>
                <tr><td><b>Deliverable</b></td><td>' . $sp['deliverable'] . '</td></tr>
                <tr><td><b>Dates</b></td><td>' . $sp['start_date'] . ' to ' . $sp['end_date'] . '</td></tr>
            </table><br>';

            $pdf->writeHTML($subHTML, true, false, false, false, '');

            // Sub-project tasks
            if (!empty($sp['tasks'])) {

                $pdf->writeHTML("<b>Tasks:</b><br>", true, false, false, false, '');

                $taskList = "<ul>";
                foreach ($sp['tasks'] as $t) {
                    $taskList .= "<li><b>{$t['taskTitle']}</b> ({$t['assigned_user']} - {$t['status']})</li>";
                }
                $taskList .= "</ul>";

                $pdf->writeHTML($taskList, true, false, false, false, '');
            }

            $pdf->Ln(4);
        }
    }
}

/* -----------------------------------------------------
   SIGNATURE PAGE (Brand Style)
------------------------------------------------------ */

$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(10, 31, 68);
$pdf->Cell(0, 10, 'Signatures', 0, 1, 'L');
$pdf->Ln(20);

$lineWidth = 70;
$spacing = 20;
$startX = 30;
$startY = $pdf->GetY();

// Signature lines
$pdf->SetDrawColor(193, 18, 31); // red
$pdf->Line($startX, $startY, $startX + $lineWidth, $startY);
$pdf->Line($startX + $lineWidth + $spacing, $startY, $startX + 2 * $lineWidth + $spacing, $startY);

// Signature labels
$pdf->SetFont('helvetica', '', 12);
$pdf->SetTextColor(10, 31, 68);

$pdf->SetXY($startX, $startY + 5);
$pdf->Cell($lineWidth, 10, 'Manager Signature', 0, 0, 'C');

$pdf->SetXY($startX + $lineWidth + $spacing, $startY + 5);
$pdf->Cell($lineWidth, 10, 'Team Leader Signature', 0, 0, 'C');

/* -----------------------------------------------------
   OUTPUT
------------------------------------------------------ */

$filename = ($itemType === 'project')
    ? "project_" . $project['project_id'] . ".pdf"
    : "sub_project_" . $sub_project['sub_project_id'] . ".pdf";

$pdf->Output($filename, 'D');
exit;
?>
