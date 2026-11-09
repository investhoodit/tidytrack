<?php
/**
 * Export Defects to Excel (.xlsx) — pure PHP, no PhpSpreadsheet.
 * Builds a minimal OOXML workbook using ZipArchive + raw XML strings.
 *
 * Usage: link to this file with the same GET filters as the main defects page.
 *   <a href="export_defects_excel.php?search=...&project=...&status=...&assigned=...">Export Excel</a>
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();

include '../dp.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

/* -----------------------------------------------------------------------
 * Helpers
 * --------------------------------------------------------------------- */

/** Escape a value for an XML text node. */
function xmlEsc(string $v): string {
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Convert a PHP value to a "shared string" safe string. */
function cellStr($v): string {
    return $v === null ? '' : (string)$v;
}

/* -----------------------------------------------------------------------
 * Filters (mirrors the main page)
 * --------------------------------------------------------------------- */
$search         = trim($_GET['search']   ?? '');
$filterProject  = trim($_GET['project']  ?? '');
$filterStatus   = trim($_GET['status']   ?? '');
$filterAssigned = trim($_GET['assigned'] ?? '');

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "(d.test_case LIKE ? OR d.title LIKE ? OR d.description LIKE ? OR d.project_name LIKE ?)";
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
    $types   .= 'ssss';
}
if ($filterProject !== '') {
    $where[]  = "d.project_name = ?";
    $params[] = $filterProject;
    $types   .= 's';
}
if ($filterStatus !== '') {
    $where[]  = "d.status = ?";
    $params[] = $filterStatus;
    $types   .= 's';
}
if ($filterAssigned !== '') {
    $where[]  = "d.assigned = ?";
    $params[] = $filterAssigned;
    $types   .= 's';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        d.defect_id,
        d.test_case,
        d.project_name,
        d.title,
        d.description,
        d.date_identified,
        d.status,
        d.priority,
        d.assigned,
        d.estimated_time,
        d.recommendation,
        d.risk,
        d.date_work_begins,
        d.further_steps,
        d.date_work_resolved,
        d.linked_task_id,
        t.taskTitle  AS linked_task_title,
        d.attachment
    FROM project_defects d
    LEFT JOIN tasks t ON t.task_id = d.linked_task_id
    $whereSql
    ORDER BY d.defect_id DESC
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

/* -----------------------------------------------------------------------
 * Collect rows + build shared-strings table
 * --------------------------------------------------------------------- */
$headers = [
    'Defect ID', 'Test Case', 'Project Name', 'Title', 'Description',
    'Date Identified', 'Status', 'Priority', 'Assigned', 'Estimated Time',
    'Recommendation', 'Risk', 'Date Work Begins', 'Further Steps',
    'Date Work Resolved', 'Linked Task ID', 'Linked Task Title', 'Attachment',
];

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        cellStr($row['defect_id']),
        cellStr($row['test_case']),
        cellStr($row['project_name']),
        cellStr($row['title']),
        cellStr($row['description']),
        cellStr($row['date_identified']),
        cellStr($row['status']),
        cellStr($row['priority']),
        cellStr($row['assigned']),
        cellStr($row['estimated_time']),
        cellStr($row['recommendation']),
        cellStr($row['risk']),
        cellStr($row['date_work_begins']),
        cellStr($row['further_steps']),
        cellStr($row['date_work_resolved']),
        cellStr($row['linked_task_id']),
        cellStr($row['linked_task_title']),
        cellStr($row['attachment']),
    ];
}

/* -----------------------------------------------------------------------
 * Build shared strings (deduplicated)
 * Every cell value goes into a shared-strings list; cells reference by index.
 * --------------------------------------------------------------------- */
$sharedStrings = [];   // value => index
$ssIndex = 0;

function addSS(string $val, array &$sharedStrings, int &$ssIndex): int {
    if (!array_key_exists($val, $sharedStrings)) {
        $sharedStrings[$val] = $ssIndex++;
    }
    return $sharedStrings[$val];
}

// Pre-populate headers
foreach ($headers as $h) {
    addSS($h, $sharedStrings, $ssIndex);
}
// Pre-populate data rows
foreach ($rows as $row) {
    foreach ($row as $cell) {
        addSS($cell, $sharedStrings, $ssIndex);
    }
}

/* -----------------------------------------------------------------------
 * Column letter helper
 * --------------------------------------------------------------------- */
function colLetter(int $col): string {
    $letter = '';
    while ($col >= 0) {
        $letter = chr(65 + ($col % 26)) . $letter;
        $col    = intdiv($col, 26) - 1;
    }
    return $letter;
}

/* -----------------------------------------------------------------------
 * Build sheet XML
 * --------------------------------------------------------------------- */
function buildSheetXml(array $headers, array $rows, array $sharedStrings): string {
    $colCount = count($headers);
    $rowCount = count($rows) + 1; // +1 for header row

    // Column widths (auto-size approximation)
    $colWidths = array_fill(0, $colCount, 15);
    foreach ($headers as $i => $h) {
        $colWidths[$i] = max($colWidths[$i], mb_strlen($h) + 2);
    }
    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $len = min(mb_strlen($cell), 50);
            if ($len > $colWidths[$i]) $colWidths[$i] = $len;
        }
    }

    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $xml .= ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    // Column dimensions
    $xml .= '<cols>';
    for ($c = 0; $c < $colCount; $c++) {
        $w    = min($colWidths[$c], 50);
        $cNum = $c + 1;
        $xml .= "<col min=\"{$cNum}\" max=\"{$cNum}\" width=\"{$w}\" customWidth=\"1\"/>";
    }
    $xml .= '</cols>';

    $xml .= '<sheetData>';

    // Header row (row 1) — style index 1 (bold)
    $xml .= '<row r="1">';
    foreach ($headers as $ci => $h) {
        $colL = colLetter($ci);
        $ssIdx = $sharedStrings[$h];
        $xml .= "<c r=\"{$colL}1\" t=\"s\" s=\"1\"><v>{$ssIdx}</v></c>";
    }
    $xml .= '</row>';

    // Data rows
    foreach ($rows as $ri => $row) {
        $rowNum = $ri + 2;
        $xml   .= "<row r=\"{$rowNum}\">";
        foreach ($row as $ci => $cell) {
            $colL  = colLetter($ci);
            $ssIdx = $sharedStrings[$cell];
            $xml  .= "<c r=\"{$colL}{$rowNum}\" t=\"s\"><v>{$ssIdx}</v></c>";
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData>';
    $xml .= '<pageSetup orientation="landscape"/>';
    $xml .= '</worksheet>';

    return $xml;
}

/* -----------------------------------------------------------------------
 * Build shared strings XML
 * --------------------------------------------------------------------- */
function buildSharedStringsXml(array $sharedStrings): string {
    $count = count($sharedStrings);
    $xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml  .= "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"{$count}\" uniqueCount=\"{$count}\">";

    // Sort by index so order is correct
    $sorted = array_flip($sharedStrings);  // index => value
    ksort($sorted);

    foreach ($sorted as $val) {
        $xml .= '<si><t xml:space="preserve">' . xmlEsc($val) . '</t></si>';
    }
    $xml .= '</sst>';
    return $xml;
}

/* -----------------------------------------------------------------------
 * Styles XML — two styles: normal (0) and bold header (1)
 * --------------------------------------------------------------------- */
function buildStylesXml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/></patternFill></fill>
  </fills>
  <borders count="1">
    <border><left/><right/><top/><bottom/><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
  </cellXfs>
</styleSheet>';
}

/* -----------------------------------------------------------------------
 * Workbook XML
 * --------------------------------------------------------------------- */
function buildWorkbookXml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Defects" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';
}

/* -----------------------------------------------------------------------
 * Relationships XMLs
 * --------------------------------------------------------------------- */
function buildWorkbookRels(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
    Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"
    Target="styles.xml"/>
  <Relationship Id="rId3"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"
    Target="sharedStrings.xml"/>
</Relationships>';
}

function buildRootRels(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="xl/workbook.xml"/>
</Relationships>';
}

function buildContentTypes(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
}

/* -----------------------------------------------------------------------
 * Assemble .xlsx using ZipArchive
 * --------------------------------------------------------------------- */
if (!class_exists('ZipArchive')) {
    die('ZipArchive extension is required.');
}

$tmpFile = tempnam(sys_get_temp_dir(), 'defects_') . '.xlsx';

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('Cannot create ZIP file.');
}

$sheetXml  = buildSheetXml($headers, $rows, $sharedStrings);
$ssXml     = buildSharedStringsXml($sharedStrings);
$stylesXml = buildStylesXml();

$zip->addFromString('[Content_Types].xml',              buildContentTypes());
$zip->addFromString('_rels/.rels',                      buildRootRels());
$zip->addFromString('xl/workbook.xml',                  buildWorkbookXml());
$zip->addFromString('xl/_rels/workbook.xml.rels',       buildWorkbookRels());
$zip->addFromString('xl/worksheets/sheet1.xml',         $sheetXml);
$zip->addFromString('xl/sharedStrings.xml',             $ssXml);
$zip->addFromString('xl/styles.xml',                    $stylesXml);
$zip->close();

/* -----------------------------------------------------------------------
 * Send file to browser
 * --------------------------------------------------------------------- */
$filename = 'defects_export_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: max-age=0');

readfile($tmpFile);
unlink($tmpFile);
exit;