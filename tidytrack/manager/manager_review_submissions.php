<?php
// manager_review_submissions.php
// ✅ Status at top
// ✅ Manager can only see THEIR assigned submissions
// ✅ Documents available for all statuses (but click & place requires Approved)
// ✅ Supports image signature + text annotations
// ✅ Saves placements to DB + burns into PDF when FPDI supports; otherwise copy fallback

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../dp.php';

// Load FPDF first
require_once __DIR__ . '/FPDF/FPDF/fpdf.php';
// Then load FPDI
require_once __DIR__ . '/FPDI/FPDI-2.6.4/src/autoload.php';

if (!function_exists('esc')) {
  function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* AUTH: manager only */
$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = strtolower(trim($_SESSION['role'] ?? ''));
if (!$userId || $role !== 'manager') {
  http_response_code(403);
  exit('Forbidden');
}

/* Helpers */
function ensureDir($dir){
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}
function monthText($ymd){
  try { return (new DateTime($ymd))->format('F Y'); }
  catch (Throwable $e) { return (string)$ymd; }
}
function colExists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  $ok = ($r && $r->num_rows > 0);
  if ($r) $r->free();
  return $ok;
}
function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $r = $conn->query("SHOW TABLES LIKE '$t'");
  $ok = ($r && $r->num_rows > 0);
  if ($r) $r->free();
  return $ok;
}
function firstExistingUserCol(mysqli $conn, array $candidates): ?string {
  foreach ($candidates as $c) {
    $cEsc = $conn->real_escape_string($c);
    $r = $conn->query("SHOW COLUMNS FROM users LIKE '$cEsc'");
    $ok = ($r && $r->num_rows > 0);
    if ($r) $r->free();
    if ($ok) return $c;
  }
  return null;
}
function webPath(string $rel): string {
  $rel = ltrim(trim($rel), '/');
  if ($rel === '') return '';
  $parts = array_map('rawurlencode', explode('/', $rel));
  return '../' . implode('/', $parts);
}
function absolutePath(string $rel): string {
  $rel = ltrim(trim($rel), '/');
  return __DIR__ . '/../' . $rel;
}

/* Checks */
$toast = null;

$hasSubmissions = tableExists($conn, 'document_submissions');
$hasFiles       = tableExists($conn, 'submission_files');

if (!$hasSubmissions || !$hasFiles) {
  $toast = ['type'=>'danger','msg'=>'Missing DB tables: document_submissions / submission_files'];
}

/* Required columns */
$needCols = [
  'manager_id',
  'mentor_improve','mentor_general','mentor_action',
  'signed_timesheet_path','commented_progress_path',
  'status'
];

// ✅ Ensure manager_id exists (prevents SQL crash)
if ($hasSubmissions && !colExists($conn, 'document_submissions', 'manager_id')) {
  $conn->query("ALTER TABLE document_submissions ADD COLUMN manager_id INT DEFAULT 0");
  $conn->query("CREATE INDEX idx_manager_id ON document_submissions (manager_id)");
}

$missingCols = [];
if ($hasSubmissions) {
  foreach ($needCols as $c) {
    if (!colExists($conn,'document_submissions',$c)) $missingCols[] = $c;
  }
  if ($missingCols) {
    $toast = ['type'=>'danger','msg'=>"Missing columns in document_submissions: ".implode(', ', $missingCols)];
  }
}

/* Tabs */
$tab = strtolower(trim($_GET['tab'] ?? 'submitted'));
if (!in_array($tab, ['submitted','signed'], true)) $tab='submitted';

/* ✅ Only show submissions assigned to THIS manager */
$where = ($tab === 'submitted')
  ? " WHERE ds.manager_id = ? AND ds.status <> 'Approved' "
  : " WHERE ds.manager_id = ? AND ds.status = 'Approved' ";

/* ===========================
   REVIEW ITEM (✅ FIXED)
=========================== */
$reviewId = (int)($_GET['review'] ?? 0);
$reviewRow = null;
$reviewFiles = [];
$reviewUser = null;
$displayName = '';

if ($reviewId > 0 && $hasSubmissions) {

  // ✅ manager can only open assigned submissions
  $stmt = $conn->prepare("
    SELECT ds.*
    FROM document_submissions ds
    WHERE ds.id = ? AND ds.manager_id = ?
    LIMIT 1
  ");
  if (!$stmt) die("Review SQL prepare failed: " . $conn->error);

  $stmt->bind_param("ii", $reviewId, $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && $res->num_rows>0) $reviewRow = $res->fetch_assoc();
  $stmt->close();

  if ($reviewRow) {

    // Load submission files
    $stmt = $conn->prepare("
      SELECT doc_type, original_name, stored_path
      FROM submission_files
      WHERE submission_id=?
      ORDER BY id ASC
    ");
    if (!$stmt) die("Files SQL prepare failed: " . $conn->error);

    $sid = (int)$reviewRow['id'];
    $stmt->bind_param("i",$sid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) while ($r = $res->fetch_assoc()) $reviewFiles[] = $r;
    $stmt->close();

    // Load user info
    $uid = (int)$reviewRow['user_id'];
    if (tableExists($conn,'users')) {
      $nameCol  = firstExistingUserCol($conn, ['full_name','name']);
      $emailCol = firstExistingUserCol($conn, ['email']);

      $cols = ['user_id'];
      if ($nameCol)  $cols[] = $nameCol;
      if ($emailCol) $cols[] = $emailCol;

      $colSql = implode(',', array_map(fn($c)=>"`$c`", $cols));
      $sql = "SELECT $colSql FROM users WHERE user_id=? LIMIT 1";

      $stmt = $conn->prepare($sql);
      if ($stmt) {
        $stmt->bind_param("i",$uid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows>0) {
          $reviewUser = $res->fetch_assoc();
          $displayName = $reviewUser['full_name'] ?? $reviewUser['name'] ?? '';
        }
        $stmt->close();
      }
    }

  } else {
    $toast = ['type'=>'danger','msg'=>'You are not allowed to review this submission (not assigned to you).'];
  }
}

/* ===========================
   MASTER SIGNATURES (saved once per manager)
=========================== */
$conn->query("
  CREATE TABLE IF NOT EXISTS manager_saved_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manager_id INT NOT NULL,
    label VARCHAR(120) DEFAULT 'My Signature',
    signature_path VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mgr (manager_id)
  )
");


function getManagerSignatureRow(mysqli $conn, int $managerId): ?array {
  $stmt = $conn->prepare("SELECT * FROM manager_saved_signatures WHERE manager_id=? ORDER BY id DESC LIMIT 1");
  $stmt->bind_param("i", $managerId);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ?: null;
}

function getManagerSignaturePath(mysqli $conn, int $managerId): ?string {
  $row = getManagerSignatureRow($conn, $managerId);
  return $row['signature_path'] ?? null;
}

/* ===========================
   SIGNATURES TABLE (per document placement)
=========================== */
$conn->query("
  CREATE TABLE IF NOT EXISTS document_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    doc_type ENUM('timesheet','progress') NOT NULL,

    sig_kind ENUM('image','text') NOT NULL DEFAULT 'image',

    signature_path VARCHAR(500) DEFAULT NULL,

    text_value VARCHAR(255) DEFAULT NULL,
    font_size INT DEFAULT 18,
    text_color VARCHAR(20) DEFAULT '#111111',

    page_number INT DEFAULT 1,
    position_x INT DEFAULT 100,
    position_y INT DEFAULT 100,
    width INT DEFAULT 160,
    height INT DEFAULT 55,
    canvas_w INT DEFAULT 0,
    canvas_h INT DEFAULT 0,

    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_submission (submission_id, doc_type)
  )
");

if (!colExists($conn, 'document_signatures', 'canvas_w')) {
  $conn->query("ALTER TABLE document_signatures ADD COLUMN canvas_w INT DEFAULT 0");
}
if (!colExists($conn, 'document_signatures', 'canvas_h')) {
  $conn->query("ALTER TABLE document_signatures ADD COLUMN canvas_h INT DEFAULT 0");
}
if (!colExists($conn, 'document_signatures', 'sig_kind')) {
  $conn->query("ALTER TABLE document_signatures ADD COLUMN sig_kind ENUM('image','text') NOT NULL DEFAULT 'image'");
}
if (!colExists($conn, 'document_signatures', 'text_value')) {
  $conn->query("ALTER TABLE document_signatures ADD COLUMN text_value VARCHAR(255) DEFAULT NULL");
}
if (!colExists($conn, 'document_signatures', 'font_size')) {
  $conn->query("ALTER TABLE document_signatures ADD COLUMN font_size INT DEFAULT 18");
}
if (!colExists($conn, 'document_signatures', 'text_color')) {
  $conn->query("ALTER TABLE document_signatures ADD COLUMN text_color VARCHAR(20) DEFAULT '#111111'");
}

/* ===========================
   AJAX
=========================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_action'])) {
  header('Content-Type: application/json');

  if ($_POST['ajax_action'] === 'save_master_signature') {
    $label = trim($_POST['label'] ?? 'My Signature');
    $signatureData = $_POST['signature_data'] ?? '';

    if (!$signatureData) {
      echo json_encode(['success'=>false,'error'=>'No signature data']);
      exit;
    }

    $existing = getManagerSignatureRow($conn, $userId);

    $absDir = __DIR__ . '/../Uploads/manager_signatures/' . $userId;
    $relDir = 'Uploads/manager_signatures/' . $userId;
    ensureDir($absDir);

    $fileName = 'manager_signature_' . $userId . '_' . time() . '.png';
    $absPath = $absDir . '/' . $fileName;

    $signatureData = preg_replace('/^data:image\/\w+;base64,/', '', $signatureData);
    $bin = base64_decode($signatureData);

    if ($bin === false || $bin === '') {
      echo json_encode(['success'=>false,'error'=>'Invalid signature data']);
      exit;
    }

    if (file_put_contents($absPath, $bin) === false) {
      echo json_encode(['success'=>false,'error'=>'Failed to save file']);
      exit;
    }

    $relPath = $relDir . '/' . $fileName;

    if ($existing && !empty($existing['signature_path'])) {
      $oldAbs = absolutePath($existing['signature_path']);
      if (file_exists($oldAbs)) @unlink($oldAbs);

      $stmt = $conn->prepare("UPDATE manager_saved_signatures SET label=?, signature_path=? WHERE manager_id=?");
      $stmt->bind_param("ssi", $label, $relPath, $userId);
      $ok = $stmt->execute();
      $stmt->close();

      echo json_encode($ok ? ['success'=>true,'label'=>$label,'signature_path'=>webPath($relPath),'replaced'=>true]
                            : ['success'=>false,'error'=>'Failed to update signature']);
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO manager_saved_signatures (manager_id, label, signature_path) VALUES (?,?,?)");
    $stmt->bind_param("iss", $userId, $label, $relPath);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok ? ['success'=>true,'label'=>$label,'signature_path'=>webPath($relPath)]
                          : ['success'=>false,'error'=>'Failed to save signature']);
    exit;
  }

  if ($_POST['ajax_action'] === 'upload_master_signature') {
    $label = trim($_POST['label'] ?? 'My Signature');

    if (!isset($_FILES['signature_file']) || $_FILES['signature_file']['error'] !== UPLOAD_ERR_OK) {
      echo json_encode(['success'=>false,'error'=>'Upload failed']);
      exit;
    }

    $name = $_FILES['signature_file']['name'] ?? 'signature.png';
    $tmp  = $_FILES['signature_file']['tmp_name'];

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png','jpg','jpeg'], true)) {
      echo json_encode(['success'=>false,'error'=>'Only PNG/JPG allowed']);
      exit;
    }

    $existing = getManagerSignatureRow($conn, $userId);

    $absDir = __DIR__ . '/../Uploads/manager_signatures/' . $userId;
    $relDir = 'Uploads/manager_signatures/' . $userId;
    ensureDir($absDir);

    $fileName = 'manager_signature_' . $userId . '_' . time() . '.' . $ext;
    $absPath = $absDir . '/' . $fileName;

    if (!move_uploaded_file($tmp, $absPath)) {
      echo json_encode(['success'=>false,'error'=>'Failed to store upload']);
      exit;
    }

    $relPath = $relDir . '/' . $fileName;

    if ($existing && !empty($existing['signature_path'])) {
      $oldAbs = absolutePath($existing['signature_path']);
      if (file_exists($oldAbs)) @unlink($oldAbs);

      $stmt = $conn->prepare("UPDATE manager_saved_signatures SET label=?, signature_path=? WHERE manager_id=?");
      $stmt->bind_param("ssi", $label, $relPath, $userId);
      $ok = $stmt->execute();
      $stmt->close();

      echo json_encode($ok ? ['success'=>true,'label'=>$label,'signature_path'=>webPath($relPath),'replaced'=>true]
                            : ['success'=>false,'error'=>'Failed to update signature']);
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO manager_saved_signatures (manager_id, label, signature_path) VALUES (?,?,?)");
    $stmt->bind_param("iss", $userId, $label, $relPath);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok ? ['success'=>true,'label'=>$label,'signature_path'=>webPath($relPath)]
                          : ['success'=>false,'error'=>'Failed to save signature']);
    exit;
  }

  if ($_POST['ajax_action'] === 'list_master_signatures') {
    $row = getManagerSignatureRow($conn, $userId);
    $items = [];
    if ($row) {
      $row['signature_path'] = webPath($row['signature_path']);
      $items[] = $row;
    }
    echo json_encode(['success'=>true,'items'=>$items]);
    exit;
  }

  if ($_POST['ajax_action'] === 'delete_master_signature') {
    $row = getManagerSignatureRow($conn, $userId);
    if (!$row) {
      echo json_encode(['success'=>false,'error'=>'Saved signature not found']);
      exit;
    }

    $abs = absolutePath($row['signature_path']);
    if (file_exists($abs)) @unlink($abs);

    $stmt = $conn->prepare("DELETE FROM manager_saved_signatures WHERE manager_id=?");
    $stmt->bind_param("i", $userId);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    echo json_encode($ok ? ['success'=>true] : ['success'=>false,'error'=>$err]);
    exit;
  }

  echo json_encode(['success'=>false,'error'=>'Unknown ajax_action']);
  exit;
}

/* ===========================
   PDF OUTPUT GENERATION (Approved)
=========================== */
function fpdiAvailable(): bool {
  return class_exists('\setasign\Fpdi\Fpdi') || class_exists('FPDI') || class_exists('\setasign\Fpdi\Tcpdf\Fpdi');
}
function gsAvailable(): bool {
  $out = @shell_exec("gs -version 2>&1");
  return is_string($out) && preg_match('/\d+\.\d+/', $out);
}
function normalizePdfForFpdi(string $inputAbs, string $outputAbs, ?string &$err=null): bool {
  if (!file_exists($inputAbs)) { $err = "normalize: input missing"; return false; }
  if (!gsAvailable()) { $err = "normalize: Ghostscript (gs) not installed"; return false; }
  if (!is_dir(dirname($outputAbs))) { @mkdir(dirname($outputAbs), 0755, true); }
  $cmd = 'gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/prepress -dNOPAUSE -dQUIET -dBATCH -sOutputFile=' . escapeshellarg($outputAbs) . ' ' . escapeshellarg($inputAbs) . ' 2>&1';
  $out = @shell_exec($cmd);
  if (!file_exists($outputAbs) || filesize($outputAbs) < 1000) { $err = "normalize failed: " . trim((string)$out); @unlink($outputAbs); return false; }
  return true;
}

function buildMentorNote(array $reviewData): string {
  $parts = [];

  $general = trim((string)($reviewData['mentor_general'] ?? ''));
  $improve = trim((string)($reviewData['mentor_improve'] ?? ''));
  $action  = trim((string)($reviewData['mentor_action'] ?? ''));

  if ($general !== '') $parts[] = $general;
  if ($improve !== '') $parts[] = 'Improve: ' . $improve;
  if ($action !== '')  $parts[] = 'Action: ' . $action;

  $text = trim(implode("
", $parts));
  return $text;
}

function createSignedOutputPDF(mysqli $conn, int $submissionId, string $docType, string $inputRel, string $outputAbs, string $outputRel, int $managerId, array $reviewData = [], &$warnMsg=null): bool {
  $inputAbs = absolutePath($inputRel);
  if (!file_exists($inputAbs)) {
    $warnMsg = "Input PDF missing for $docType.";
    return false;
  }

  $sigRel = getManagerSignaturePath($conn, $managerId);
  if (!$sigRel) {
    $warnMsg = "No saved signature found. Please save your signature first.";
    return false;
  }

  $sigAbs = absolutePath($sigRel);
  if (!file_exists($sigAbs)) {
    $warnMsg = "Saved signature file not found.";
    return false;
  }

  if (!fpdiAvailable()) {
    ensureDir(dirname($outputAbs));
    if (@copy($inputAbs, $outputAbs)) {
      $warnMsg = "FPDI not installed: output copied only.";
      return true;
    }
    return false;
  }

  $mentorNote = buildMentorNote($reviewData);

  $map = [
    'progress_report' => [
      'mentor_signature' => [
        'page' => 2,
        'x'    => 118,
        'y'    => 109,
        'w'    => 32,
        'h'    => 10,
      ],
    ],
    'timesheet' => [
      'mentor_cells' => [
        1 => [
          ['x'=>153, 'y'=>112, 'w'=>52, 'h'=>19],
          ['x'=>153, 'y'=>134, 'w'=>52, 'h'=>19],
          ['x'=>153, 'y'=>156, 'w'=>52, 'h'=>19],
          ['x'=>153, 'y'=>179, 'w'=>52, 'h'=>19],
          ['x'=>153, 'y'=>201, 'w'=>52, 'h'=>19],
        ],
        2 => [
          ['x'=>146, 'y'=> 27, 'w'=>58, 'h'=>20],
          ['x'=>146, 'y'=> 50, 'w'=>58, 'h'=>20],
          ['x'=>146, 'y'=> 73, 'w'=>58, 'h'=>20],
          ['x'=>146, 'y'=> 96, 'w'=>58, 'h'=>20],
          ['x'=>146, 'y'=>119, 'w'=>58, 'h'=>20],
          ['x'=>146, 'y'=>160, 'w'=>58, 'h'=>20],
          ['x'=>146, 'y'=>183, 'w'=>58, 'h'=>20],
          ['x'=>146, 'y'=>206, 'w'=>58, 'h'=>20],
          ['x'=>146, 'y'=>229, 'w'=>58, 'h'=>20],
          ['x'=>146, 'y'=>252, 'w'=>58, 'h'=>20],
        ],
      ],
    ],
  ];

  try {
    if (class_exists('\setasign\Fpdi\Fpdi')) {
      $pdf = new \setasign\Fpdi\Fpdi();
    } elseif (class_exists('FPDI')) {
      $pdf = new FPDI();
    } else {
      $warnMsg = "FPDI class not detected.";
      return false;
    }

    $sourceAbs = $inputAbs;

    try {
      $pageCount = $pdf->setSourceFile($sourceAbs);
    } catch (Throwable $e) {
      $normErr = null;
      $normAbs = dirname($inputAbs) . '/__normalized_for_fpdi.pdf';
      if (normalizePdfForFpdi($inputAbs, $normAbs, $normErr)) {
        $sourceAbs = $normAbs;
        $pageCount = $pdf->setSourceFile($sourceAbs);
      } else {
        $warnMsg = "Could not open PDF for signing. " . ($normErr ?: $e->getMessage());
        return false;
      }
    }

    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
      $tpl = $pdf->importPage($pageNo);
      $size = $pdf->getTemplateSize($tpl);
      $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';

      $pdf->AddPage($orientation, [$size['width'], $size['height']]);
      $pdf->useTemplate($tpl);

      if ($docType === 'progress_report') {
        $sig = $map['progress_report']['mentor_signature'];
        if ($pageNo === (int)$sig['page']) {
          $pdf->Image($sigAbs, $sig['x'], $sig['y'], $sig['w'], $sig['h']);
        }
      }

      if ($docType === 'timesheet' && !empty($map['timesheet']['mentor_cells'][$pageNo])) {
        foreach ($map['timesheet']['mentor_cells'][$pageNo] as $idx => $cell) {
          // Signature only in mentor block
          $sigW = 16.5;
          $sigH = 5.8;
          $sigX = $cell['x'] + (($cell['w'] - $sigW) / 2) - 0.4;
          $sigY = $cell['y'] + (($cell['h'] - $sigH) / 2) + 0.8;

          // First row on page 1 and 2 was slightly too high
          if (in_array($pageNo, [1,2,3], true) && $idx === 0) {
            $sigY += 1.0;
          }

          // Last row on the last page was drifting outside
          if ($pageNo === 2 && $idx === 9) {
            $sigY -= 1.0;
          }

          $pdf->Image($sigAbs, $sigX, $sigY, $sigW, $sigH);
        }
      }
    }

    ensureDir(dirname($outputAbs));
    $pdf->Output('F', $outputAbs);
    return file_exists($outputAbs);

  } catch (Throwable $e) {
    $warnMsg = "Signing failed: " . $e->getMessage();
    return false;
  }
}

/* Handle review actions */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_submission') {
  $sid = (int)($_POST['submission_id'] ?? 0);
  $newStatus = trim($_POST['status'] ?? '');
  $allowedStatus = ['In Review','Needs Changes','Approved','Rejected','Pending Manager Review'];

  if (!$sid || !in_array($newStatus,$allowedStatus,true)) {
    $toast = ['type'=>'danger','msg'=>'Invalid submission or status'];
  } else {
    $mentor_improve  = trim($_POST['mentor_improve'] ?? '');
    $mentor_general  = trim($_POST['mentor_general'] ?? '');
    $mentor_action   = trim($_POST['mentor_action'] ?? '');
    $signedTsRel = ''; $commentedPrRel = ''; $warns = [];

    if ($newStatus === 'Approved') {
      $managerSignature = getManagerSignaturePath($conn, $userId);
      if (!$managerSignature) {
        $toast = ['type'=>'danger','msg'=>'Please save your signature first before approving.'];
      } else {
      $tsRel = ''; $prRel = '';
      $stmt = $conn->prepare("SELECT doc_type, stored_path FROM submission_files WHERE submission_id=?");
      $stmt->bind_param("i", $sid); $stmt->execute();
      $res = $stmt->get_result();
      if ($res) { while ($r = $res->fetch_assoc()) { if ($r['doc_type']==='timesheet') $tsRel=$r['stored_path']; if ($r['doc_type']==='progress_report') $prRel=$r['stored_path']; } }
      $stmt->close();

      $outAbsDir = __DIR__ . '/../Uploads/signed_outputs/' . $sid;
      $outRelDir = 'Uploads/signed_outputs/' . $sid;
      ensureDir($outAbsDir);

      if ($tsRel && preg_match('/\.pdf$/i', $tsRel)) {
        $outAbs=$outAbsDir.'/SIGNED_TIMESHEET.pdf'; $outRel=$outRelDir.'/SIGNED_TIMESHEET.pdf'; $warn=null;
        if (createSignedOutputPDF($conn,$sid,'timesheet',$tsRel,$outAbs,$outRel,$userId,['mentor_improve'=>$mentor_improve,'mentor_general'=>$mentor_general,'mentor_action'=>$mentor_action],$warn)) { $signedTsRel=$outRel; if($warn) $warns[]=$warn; }
        else $warns[]=$warn?:"Failed to generate signed timesheet output.";
      } else $warns[]="Timesheet PDF not found for output generation.";

      if ($prRel && preg_match('/\.pdf$/i', $prRel)) {
        $outAbs=$outAbsDir.'/COMMENTED_PROGRESS.pdf'; $outRel=$outRelDir.'/COMMENTED_PROGRESS.pdf'; $warn=null;
        if (createSignedOutputPDF($conn,$sid,'progress_report',$prRel,$outAbs,$outRel,$userId,['mentor_improve'=>$mentor_improve,'mentor_general'=>$mentor_general,'mentor_action'=>$mentor_action],$warn)) { $commentedPrRel=$outRel; if($warn) $warns[]=$warn; }
        else $warns[]=$warn?:"Failed to generate progress output.";
      } else $warns[]="Progress Report PDF not found for output generation.";
      }
    }

    $conn->begin_transaction();
    try {
      $stmt = $conn->prepare("UPDATE document_submissions SET manager_id=CASE WHEN manager_id IS NULL OR manager_id=0 THEN ? ELSE manager_id END, status=?, mentor_improve=?, mentor_general=?, mentor_action=?, signed_timesheet_path=COALESCE(NULLIF(?,''),signed_timesheet_path), commented_progress_path=COALESCE(NULLIF(?,''),commented_progress_path) WHERE id=? AND manager_id=?");
      if (!$stmt) throw new Exception("Prepare failed: ".$conn->error);
      $stmt->bind_param("issssssii", $userId, $newStatus, $mentor_improve, $mentor_general, $mentor_action, $signedTsRel, $commentedPrRel, $sid, $userId);
      if (!$stmt->execute()) throw new Exception($stmt->error);
      $stmt->close();
      $conn->commit();
      $_SESSION['toast'] = !empty($warns) ? ['type'=>'danger','msg'=>implode(" | ", $warns)] : ['type'=>'success','msg'=>'Saved successfully. Outputs generated.'];
      header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=signed');
      exit;
    } catch (Throwable $e) {
      $conn->rollback();
      $toast = ['type'=>'danger','msg'=>"Update failed: ".$e->getMessage()];
    }
  }
}

if (!$toast && !empty($_SESSION['toast'])) { $toast = $_SESSION['toast']; unset($_SESSION['toast']); }

/* List submissions */
$list = [];
if ($hasSubmissions) {
  $sql = "SELECT ds.id, ds.user_id, ds.submission_month, ds.status, ds.created_at, ds.signed_timesheet_path, ds.commented_progress_path, COALESCE(u.name, CONCAT('User #', ds.user_id)) AS user_name FROM document_submissions ds LEFT JOIN users u ON u.user_id = ds.user_id $where ORDER BY ds.submission_month DESC, ds.id DESC LIMIT 100";
  $stmt = $conn->prepare($sql);
  if (!$stmt) die("List SQL prepare failed: " . $conn->error);
  $stmt->bind_param("i", $userId); $stmt->execute();
  $res = $stmt->get_result();
  if ($res) while ($r = $res->fetch_assoc()) $list[] = $r;
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Timesheet Submissions</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

  <style>
    body { margin:0; font-family:'Poppins', sans-serif; background:#f6f7fb; }
    .wrap { max-width: 1400px; margin: 0 auto; padding: 28px; }
    .card{ background:#fff; border:1px solid #eee; border-radius:14px; box-shadow:0 6px 18px rgba(0,0,0,.05); padding:18px; }
    .title{ font-size:22px; font-weight:600; color:#1d3557; }
    .muted{ color:#6b7280; font-size:14px; }
    .btn{ background:#1d3557; color:#fff; padding:10px 14px; border-radius:10px; border:none; cursor:pointer; font-weight:600; }
    .btn2{ background:#0f766e; color:#fff; padding:10px 14px; border-radius:10px; border:none; cursor:pointer; font-weight:600; }
    .btn-signature{ background:#4f46e5; color:#fff; padding:8px 12px; border-radius:8px; border:none; cursor:pointer; font-weight:600; font-size:12px; }
    .btn-muted{ background:#111827; color:#fff; padding:8px 12px; border-radius:8px; border:none; cursor:pointer; font-weight:600; font-size:12px; }
    .btn-danger{ background:#dc2626; color:#fff; padding:8px 12px; border-radius:8px; border:none; cursor:pointer; font-weight:600; font-size:12px; }
    table{ width:100%; border-collapse:collapse; }
    th, td{ padding:12px; border-bottom:1px solid #eee; text-align:left; font-size:14px; vertical-align: top; }
    th{ color:#1d3557; font-weight:600; background:#fafafa; }
    .pill{ display:inline-block; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#fff7ed; color:#9a3412; }
    .tabs{ display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
    .tab{ padding:10px 12px; border-radius:999px; border:1px solid #e5e7eb; background:#fff; color:#1d3557; font-weight:700; text-decoration:none; font-size:13px; }
    .tab.active{ background:#1d3557; color:#fff; border-color:#1d3557; }
    .link{ color:#1d3557; text-decoration:underline; font-weight:700; cursor:pointer; }
    .box{ background:#fafafa; border:1px solid #eee; border-radius:12px; padding:12px; }
    textarea, select { width:100%; border:1px solid #ddd; border-radius:10px; padding:10px; }

    .gridDocs { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    @media (max-width: 1024px){ .gridDocs { grid-template-columns: 1fr; } }

    .pdf-container {
      position: relative; width: 100%; height: 320px;
      overflow-y: auto; overflow-x: hidden;
      border: 1px solid #eee; border-radius: 12px; background: #f5f5f5;
    }
    .pdf-canvas-wrapper { position: relative; width: 100%; }
    .pdf-canvas { display: block; width: 100%; height: auto; }

    .signature-overlay {
      position: absolute; cursor: move; border: 2px dashed #4f46e5;
      background: transparent; pointer-events: all; user-select: none; z-index: 100; border-radius: 10px;
    }
    .signature-overlay:hover { background: rgba(79, 70, 229, 0.05); }
    .signature-overlay img { width: 100%; height: 100%; object-fit: contain; pointer-events: none; }
    .text-overlay {
      width:100%; height:100%; display:flex; align-items:center; justify-content:center;
      font-weight:700; background:transparent; border-radius:10px; pointer-events:none;
      padding:6px; text-align:center; line-height:1.1; white-space:pre-wrap;
    }
    .signature-controls {
      position: absolute; top: -30px; right: 0; display: flex; gap: 5px;
      opacity: 0; transition: opacity 0.2s; z-index: 101;
    }
    .signature-overlay:hover .signature-controls { opacity: 1; }
    .signature-controls button {
      padding: 4px 8px; font-size: 12px; border-radius: 6px; border: none;
      cursor: pointer; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .modal-backdrop{
      position:fixed; inset:0; background:rgba(0,0,0,.55);
      display:none; align-items:center; justify-content:center; z-index:9999; padding:12px;
    }
    .modal-backdrop.show{ display:flex; }
    .modal{
      width:min(1400px, 98vw); height:92vh; background:#fff;
      border-radius:16px; border:1px solid #eee;
      box-shadow:0 18px 55px rgba(0,0,0,.25); overflow:hidden; display:flex; flex-direction:column;
    }
    .modal-head{
      display:flex; align-items:center; justify-content:space-between;
      padding:12px 14px; border-bottom:1px solid #eee; background:#fff; flex:0 0 auto;
    }
    .modal-body{ padding:14px; overflow:auto; flex:1 1 auto; }
    .xbtn{ border:none; background:#f3f4f6; border-radius:10px; padding:10px 12px; cursor:pointer; font-weight:800; text-decoration:none; color:#111; }

    .signature-pad-modal {
      position: fixed; inset: 0; background: rgba(0,0,0,0.5);
      display: none; justify-content: center; align-items: center; z-index: 10000;
    }
    .signature-pad-container {
      background: white; padding: 20px; border-radius: 12px;
      width: 680px; max-width: 94%; box-shadow: 0 18px 55px rgba(0,0,0,.25);
    }
    .signature-pad {
      border: 2px solid #eee; border-radius: 8px; width: 100%; height: 200px;
      touch-action: none; background-color: #ffffff;
      background-image: linear-gradient(45deg,#e5e7eb 25%,transparent 25%), linear-gradient(-45deg,#e5e7eb 25%,transparent 25%), linear-gradient(45deg,transparent 75%,#e5e7eb 75%), linear-gradient(-45deg,transparent 75%,#e5e7eb 75%);
      background-size: 16px 16px; background-position: 0 0,0 8px,8px -8px,-8px 0px;
    }
    .resize-handle { position:absolute; width:10px; height:10px; background:#4f46e5; border:2px solid white; border-radius:50%; z-index:102; }
    .resize-handle.se { bottom:-5px; right:-5px; cursor:se-resize; }

    .hintbar{ margin-top:10px; padding:10px 12px; border-radius:12px; background:#eef2ff; color:#1e3a8a; font-size:13px; display:none; }
    .hintbar.show{ display:block; }

    /* =====================================================
       DARK MODE — applied to EVERY element including modal
       ===================================================== */
    [data-theme="dark"] body { background:#0f172a; color:#e2e8f0; }
    [data-theme="dark"] .card { background:#1e293b; border-color:#334155; }
    [data-theme="dark"] .box  { background:#0f172a; border-color:#334155; color:#e2e8f0; }
    [data-theme="dark"] .title { color:#60a5fa !important; }
    [data-theme="dark"] .muted { color:#94a3b8 !important; }
    [data-theme="dark"] label  { color:#94a3b8; }
    [data-theme="dark"] b, [data-theme="dark"] strong { color:#f1f5f9; }
    [data-theme="dark"] p { color:#cbd5e1; }

    /* Table */
    [data-theme="dark"] th { background:#1e293b !important; color:#60a5fa !important; border-bottom-color:#334155 !important; }
    [data-theme="dark"] td { color:#e2e8f0 !important; border-bottom-color:#334155 !important; }

    /* Pill */
    [data-theme="dark"] .pill { background:#292524; color:#fdba74; }

    /* Tabs */
    [data-theme="dark"] .tab        { background:#1e293b; color:#93c5fd; border-color:#334155; }
    [data-theme="dark"] .tab.active { background:#1d3557; color:#fff; border-color:#1d3557; }

    /* Links */
    [data-theme="dark"] .link    { color:#7dd3fc !important; }
    [data-theme="dark"] td a     { color:#7dd3fc !important; }

    /* Buttons */
    [data-theme="dark"] .btn      { background:#1d3557; color:#fff; }
    [data-theme="dark"] .btn-muted { background:#334155; color:#e2e8f0; }

    /* Form inputs */
    [data-theme="dark"] textarea,
    [data-theme="dark"] select,
    [data-theme="dark"] input[type="text"],
    [data-theme="dark"] input[type="number"] {
      background:#0f172a; color:#f1f5f9; border-color:#475569;
    }
    [data-theme="dark"] input[type="file"]  { color:#e2e8f0; }

    /* Hint bar */
    [data-theme="dark"] .hintbar { background:#1e3a5f; color:#93c5fd; }

    /* Main modal shell */
    [data-theme="dark"] .modal       { background:#1e293b; border-color:#334155; color:#e2e8f0; }
    [data-theme="dark"] .modal-head  { background:#1e293b; border-color:#334155; }
    [data-theme="dark"] .modal-body  { background:#1e293b; color:#e2e8f0; }
    [data-theme="dark"] .xbtn        { background:#334155; color:#f1f5f9; }

    /* Floating modals (signature pad, saved picker, text note) */
    [data-theme="dark"] .signature-pad-container {
      background:#1e293b; color:#e2e8f0; border:1px solid #334155;
    }
    [data-theme="dark"] .signature-pad-container h3 { color:#60a5fa; }
    [data-theme="dark"] .signature-pad-container .muted { color:#94a3b8; }
    [data-theme="dark"] .signature-pad-container input[type="text"],
    [data-theme="dark"] .signature-pad-container input[type="number"] {
      background:#0f172a; color:#f1f5f9; border-color:#475569;
    }
    [data-theme="dark"] .signature-pad-container textarea {
      background:#0f172a; color:#f1f5f9; border-color:#475569;
    }
    [data-theme="dark"] .signature-pad-container label { color:#94a3b8; }
    [data-theme="dark"] .signature-pad-container input[type="checkbox"] { accent-color:#60a5fa; }

    /* PDF viewer area */
    [data-theme="dark"] .pdf-container { background:#0f172a; border-color:#334155; }

    /* Overlay control delete button */
    [data-theme="dark"] .signature-controls button { background:#334155; color:#f1f5f9; }

    /* Saved signature list items */
    [data-theme="dark"] #savedList > div > div {
      background:#0f172a; border-color:#334155; color:#e2e8f0;
    }

    /* Toast messages inside modal */
    [data-theme="dark"] .toast-ok { background:#064e3b; color:#6ee7b7; }
    [data-theme="dark"] .toast-er { background:#450a0a; color:#fca5a5; }

    /* Theme toggle */
    #themeToggle { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:8px 14px; cursor:pointer; font-family:inherit; color:#1d3557; }
    [data-theme="dark"] #themeToggle { background:#1e293b; border-color:#334155; color:#e2e8f0; }
  </style>
</head>

<body>
<div class="wrap">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <a class="btn" href="manager_index.php" style="text-decoration:none;">
      <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>
    <button id="themeToggle"><i class="fa fa-moon"></i></button>
  </div>

  <div class="card" style="margin-top:14px;">
    <div class="title">Timesheets Submissions</div>
    <p class="muted" style="margin-top:6px;">Review submissions and approve by adding signatures / notes from the system.</p>

    <?php if ($toast && !$reviewRow): ?>
      <div class="<?php echo $toast['type']==='success' ? 'toast-ok' : 'toast-er'; ?>"
           style="margin-top:14px; padding:12px; border-radius:10px;
             background: <?php echo $toast['type']==='success' ? '#ecfdf5' : '#fef2f2'; ?>;
             color: <?php echo $toast['type']==='success' ? '#065f46' : '#991b1b'; ?>;">
        <?php echo esc($toast['msg']); ?>
      </div>
    <?php endif; ?>

    <div class="tabs" style="margin-top:14px;">
      <a class="tab <?php echo $tab==='submitted'?'active':''; ?>" href="?tab=submitted">Submitted</a>
      <a class="tab <?php echo $tab==='signed'?'active':''; ?>" href="?tab=signed">Signed</a>
    </div>
  </div>

  <div class="card" style="margin-top:18px;">
    <div class="title" style="font-size:18px;">Submissions List</div>

    <?php if (!$list): ?>
      <div style="margin-top:12px;" class="muted">No submissions found in this tab.</div>
    <?php else: ?>
      <div style="overflow:auto; margin-top:12px;">
        <table>
          <thead>
            <tr>
              <th>Month</th><th>Name</th><th>Status</th><th>Outputs</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($list as $r): ?>
            <tr>
              <td><b><?php echo esc(monthText($r['submission_month'])); ?></b></td>
              <td><?php echo esc($r['user_name'] ?? ('User #' . (int)$r['user_id'])); ?></td>
              <td><span class="pill"><?php echo esc($r['status']); ?></span></td>
              <td>
                <?php if (!empty($r['signed_timesheet_path'])): ?>
                  <div><a class="link" target="_blank" href="<?php echo esc(webPath($r['signed_timesheet_path'])); ?>"><i class="fa-solid fa-file-pdf"></i> Signed Timesheet</a></div>
                <?php else: ?>
                  <div class="muted">Timesheet Output: Pending</div>
                <?php endif; ?>
                <?php if (!empty($r['commented_progress_path'])): ?>
                  <div style="margin-top:6px;"><a class="link" target="_blank" href="<?php echo esc(webPath($r['commented_progress_path'])); ?>"><i class="fa-solid fa-file-pdf"></i> Commented Progress</a></div>
                <?php else: ?>
                  <div class="muted" style="margin-top:6px;">Progress Output: Pending</div>
                <?php endif; ?>
              </td>
              <td>
                <a class="link" href="?tab=<?php echo esc($tab); ?>&review=<?php echo (int)$r['id']; ?>">
                  <i class="fa-solid fa-eye"></i> View
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- REVIEW MODAL -->
<div id="reviewModal" class="modal-backdrop <?php echo $reviewRow ? 'show' : ''; ?>">
  <div class="modal">
    <div class="modal-head">
      <div class="title" style="font-size:18px; margin:0;">
        Review Submission<?php echo $reviewRow ? ': '.esc(monthText($reviewRow['submission_month'])) : ''; ?>
      </div>
      <a class="xbtn" href="?tab=<?php echo esc($tab); ?>" title="Close">
        <i class="fa-solid fa-xmark"></i>
      </a>
    </div>

    <div class="modal-body">
      <?php if ($reviewRow): ?>

        <?php if ($toast): ?>
          <div class="<?php echo $toast['type']==='success' ? 'toast-ok' : 'toast-er'; ?>"
               style="margin:10px 0; padding:12px; border-radius:10px;
                 background: <?php echo $toast['type']==='success' ? '#ecfdf5' : '#fef2f2'; ?>;
                 color: <?php echo $toast['type']==='success' ? '#065f46' : '#991b1b'; ?>;">
            <?php echo esc($toast['msg']); ?>
          </div>
        <?php endif; ?>

        <p class="muted" style="margin-top:2px;">
          Submitted by User ID: <b><?php echo (int)$reviewRow['user_id']; ?></b>
          <?php if ($reviewUser): ?>
            — <b><?php echo esc($displayName ?: ('User #' . (int)$reviewRow['user_id'])); ?></b>
            (<?php echo esc($reviewUser['email'] ?? ''); ?>)
          <?php endif; ?>
        </p>

        <form method="POST" action="<?php echo esc($_SERVER['REQUEST_URI']); ?>" style="margin-top:12px;">
          <input type="hidden" name="action" value="update_submission"/>
          <input type="hidden" name="submission_id" value="<?php echo (int)$reviewRow['id']; ?>"/>

          <!-- STATUS AT TOP -->
          <div class="box">
            <label class="muted">Status</label>
            <select id="statusSelect" name="status" required>
              <?php
                $opts = ['In Review','Needs Changes','Approved','Rejected'];
                $cur = $reviewRow['status'] ?? '';
                foreach ($opts as $o):
              ?>
                <option value="<?php echo esc($o); ?>" <?php echo $cur===$o?'selected':''; ?>><?php echo esc($o); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="muted" style="margin-top:8px;">
              When status is <b>Approved</b>, the system will automatically use your saved signature and generate the signed timesheet PDF on <b>Save & Update</b>.
            </div>
          </div>

          <!-- DOCS -->
          <div id="documentsSection" style="margin-top:12px; display:block;">
            <?php
              $managerSig = getManagerSignatureRow($conn, $userId);
            ?>

            <div class="box" style="margin-bottom:12px;">
              <div class="flex justify-between items-center mb-2 gap-2 flex-wrap">
                <b>My Approval Signature</b>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                  <button type="button" class="btn-signature" onclick="openAddSignature()">
                    <i class="fa-solid fa-pen"></i>
                    <?php echo $managerSig ? 'Replace Signature' : 'Save Signature'; ?>
                  </button>
                  <?php if ($managerSig): ?>
                    <button type="button" class="btn-danger" onclick="deleteSavedSignature()"><i class="fa-solid fa-trash"></i> Delete</button>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($managerSig && !empty($managerSig['signature_path'])): ?>
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                  <img src="<?php echo esc(webPath($managerSig['signature_path'])); ?>"
                       alt="Saved signature"
                       style="max-height:70px; max-width:240px; object-fit:contain; border:1px solid #ddd; border-radius:8px; padding:6px; background:#fff;">
                  <div class="muted">Saved and ready for one-click approval signing.</div>
                </div>
              <?php else: ?>
                <div class="muted">No signature saved yet. Save it once, then approving will auto-sign the PDF.</div>
              <?php endif; ?>
            </div>

            <div class="gridDocs">
              <?php
                $ts = ''; $pr = '';
                foreach ($reviewFiles as $f) {
                  if ($f['doc_type']==='timesheet') $ts = $f['stored_path'];
                  if ($f['doc_type']==='progress_report') $pr = $f['stored_path'];
                }
              ?>

              <div class="box">
                <div class="flex justify-between items-center mb-2 gap-2 flex-wrap">
                  <b>Timesheet</b>
                </div>
                <?php if ($ts && preg_match('/\.pdf$/i', $ts)): ?>
                  <div id="timesheet-viewer" class="pdf-container" data-doc-type="timesheet" data-path="<?php echo esc(webPath($ts)); ?>">
                    <div class="text-center p-4">Loading PDF…</div>
                  </div>
                <?php elseif ($ts): ?>
                  <div class="muted">Timesheet is not a PDF.</div>
                <?php else: ?>
                  <div class="muted">No timesheet found.</div>
                <?php endif; ?>
              </div>

              <div class="box">
                <div class="flex justify-between items-center mb-2 gap-2 flex-wrap">
                  <b>Progress Report</b>
                </div>
                <?php if ($pr && preg_match('/\.pdf$/i', $pr)): ?>
                  <div id="progress-viewer" class="pdf-container" data-doc-type="progress" data-path="<?php echo esc(webPath($pr)); ?>">
                   <div class="text-center p-4">Loading PDF…</div>
                  </div>
                <?php elseif ($pr): ?>
                  <div class="muted">Progress report is not a PDF.</div>
                <?php else: ?>
                  <div class="muted">No progress report found.</div>
                <?php endif; ?>
              </div>

            </div>
            <div class="muted" style="margin-top:10px;">When you approve, the system will automatically place your saved signature on the timesheet PDF.</div>
          </div>

          <!-- Comments -->
          <div style="margin-top:12px;" class="gridDocs">
            <div>
              <label class="muted">Areas to improve</label>
              <textarea name="mentor_improve" rows="2"><?php echo esc($reviewRow['mentor_improve'] ?? ''); ?></textarea>
            </div>
            <div>
              <label class="muted">General comments</label>
              <textarea name="mentor_general" rows="2"><?php echo esc($reviewRow['mentor_general'] ?? ''); ?></textarea>
            </div>
          </div>

          <div style="margin-top:10px;">
            <label class="muted">Action plan</label>
            <textarea name="mentor_action" rows="2"><?php echo esc($reviewRow['mentor_action'] ?? ''); ?></textarea>
          </div>

          <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn2" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save & Update</button>
            <a class="btn" style="text-decoration:none;" href="?tab=<?php echo esc($tab); ?>"><i class="fa-solid fa-arrow-left"></i> Close</a>
          </div>
        </form>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Add/Upload Signature Modal -->
<div id="addSigModal" class="signature-pad-modal">
  <div class="signature-pad-container">
    <h3 class="text-lg font-semibold mb-3">Save Approval Signature</h3>
    <div style="display:flex; gap:8px; margin-bottom:10px;">
      <button type="button" class="btn-muted" onclick="setAddSigTab('draw')">Draw</button>
      <button type="button" class="btn-muted" onclick="setAddSigTab('upload')">Upload</button>
    </div>

    <div id="addSigDrawTab">
      <input id="sigLabel2" class="w-full mb-2"
        style="border:1px solid #ddd;border-radius:10px;padding:10px;width:100%;"
        placeholder="Label (optional) e.g. Manager Signature" />
      <canvas id="signaturePad2" class="signature-pad" width="500" height="200"></canvas>
      <div class="flex justify-between mt-3">
        <div>
          <button class="btn-signature" type="button" onclick="clearSignature2()">Clear</button>
          <button class="btn-danger" type="button" onclick="closeAddSig()">Cancel</button>
        </div>
        <button class="btn2" type="button" onclick="saveDrawnFromAddSig()">Save Signature</button>
      </div>
    </div>

    <div id="addSigUploadTab" style="display:none;">
      <input id="uploadLabel2" class="w-full mb-2"
        style="border:1px solid #ddd;border-radius:10px;padding:10px;width:100%;"
        placeholder="Label (optional)" />
      <input id="uploadFile2" type="file" accept=".png,.jpg,.jpeg" class="w-full mb-3" />
      <div class="flex justify-between mt-2">
        <button class="btn-danger" type="button" onclick="closeAddSig()">Cancel</button>
        <button class="btn2" type="button" onclick="uploadFromAddSig()">Upload & Save</button>
      </div>
    </div>
  </div>
</div>

<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

let signaturePad2 = null;
let viewerInited = { timesheet:false, progress:false };

<?php if ($reviewRow): ?>
document.addEventListener('DOMContentLoaded', function() {
  if (!viewerInited.timesheet) { initPDFViewer('timesheet'); viewerInited.timesheet = true; }
  if (!viewerInited.progress)  { initPDFViewer('progress');  viewerInited.progress  = true; }
});
<?php endif; ?>

async function initPDFViewer(docType){
  const container = document.getElementById(docType + '-viewer');
  if (!container) return;

  const pdfPath = container.dataset.path;
  if (!pdfPath) {
    container.innerHTML = '<div class="text-center p-4 text-red-500">PDF path not found</div>';
    return;
  }

  try {
    container.innerHTML = '<div class="text-center p-4">Loading PDF...</div>';
    const pdf = await pdfjsLib.getDocument({ url: pdfPath, withCredentials: false }).promise;
    await renderAllPages(pdf, container);
  } catch(e) {
    console.error(e);
    container.innerHTML = '<div class="text-center p-4 text-red-500">Error loading PDF</div>';
  }
}

async function renderAllPages(pdf, container){
  container.innerHTML = '';
  const stack = document.createElement('div');
  stack.style.display = 'flex';
  stack.style.flexDirection = 'column';
  stack.style.gap = '12px';
  stack.style.padding = '10px';

  const containerWidth = Math.max(320, Math.floor(container.clientWidth - 30));

  for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
    const page = await pdf.getPage(pageNum);
    const base = page.getViewport({ scale: 1 });
    let scale = Math.max(0.6, Math.min(containerWidth / base.width, 2.5));
    const viewport = page.getViewport({ scale });

    const wrapper = document.createElement('div');
    wrapper.className = 'pdf-canvas-wrapper';
    wrapper.style.width = Math.floor(viewport.width) + 'px';
    wrapper.style.height = Math.floor(viewport.height) + 'px';

    const canvas = document.createElement('canvas');
    canvas.className = 'pdf-canvas';
    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);

    wrapper.appendChild(canvas);
    stack.appendChild(wrapper);

    await page.render({
      canvasContext: canvas.getContext('2d'),
      viewport
    }).promise;
  }

  container.appendChild(stack);
}

function openAddSignature(){
  document.getElementById('addSigModal').style.display = 'flex';
  setAddSigTab('draw');

  const c = document.getElementById('signaturePad2');
  c.getContext('2d').clearRect(0,0,c.width,c.height);
  signaturePad2 = new SignaturePad(c, {
    backgroundColor: 'rgba(0,0,0,0)',
    penColor: '#000'
  });
}

function closeAddSig(){
  document.getElementById('addSigModal').style.display = 'none';
  signaturePad2 = null;
}

function setAddSigTab(tab){
  document.getElementById('addSigDrawTab').style.display   = (tab === 'draw') ? 'block' : 'none';
  document.getElementById('addSigUploadTab').style.display = (tab === 'upload') ? 'block' : 'none';
}

function clearSignature2(){
  if (signaturePad2) signaturePad2.clear();
}

async function saveDrawnFromAddSig(){
  if (!signaturePad2 || signaturePad2.isEmpty()) {
    alert('Draw signature first');
    return;
  }

  const data = signaturePad2.toDataURL('image/png');
  const label = (document.getElementById('sigLabel2').value || 'My Signature').trim();

  const fd = new FormData();
  fd.append('ajax_action', 'save_master_signature');
  fd.append('label', label);
  fd.append('signature_data', data);

  const res = await fetch(window.location.href, { method:'POST', body:fd });
  const out = await res.json();

  if (!out.success) {
    alert(out.error || 'Failed to save signature');
    return;
  }

  alert('Signature saved successfully.');
  window.location.reload();
}

async function uploadFromAddSig(){
  const file = document.getElementById('uploadFile2').files[0];
  if (!file) {
    alert('Choose a file first');
    return;
  }

  const label = (document.getElementById('uploadLabel2').value || 'My Signature').trim();

  const fd = new FormData();
  fd.append('ajax_action', 'upload_master_signature');
  fd.append('label', label);
  fd.append('signature_file', file);

  const res = await fetch(window.location.href, { method:'POST', body:fd });
  const out = await res.json();

  if (!out.success) {
    alert(out.error || 'Upload failed');
    return;
  }

  alert('Signature saved successfully.');
  window.location.reload();
}

async function deleteSavedSignature(){
  if (!confirm('Delete your saved signature?')) return;
  const fd = new FormData();
  fd.append('ajax_action','delete_master_signature');
  const res = await fetch(window.location.href, { method:'POST', body: fd });
  const out = await res.json();
  if (!out.success) return alert(out.error || 'Delete failed');
  window.location.reload();
}

/* THEME */
document.addEventListener("DOMContentLoaded", function(){
  const root = document.documentElement;
  const toggle = document.getElementById("themeToggle");
  const saved = localStorage.getItem("theme") || "light";
  root.setAttribute("data-theme", saved);
  if (toggle) {
    toggle.onclick = function(){
      const next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
      root.setAttribute("data-theme", next);
      localStorage.setItem("theme", next);
    };
  }
});</script>

</body>
</html>
