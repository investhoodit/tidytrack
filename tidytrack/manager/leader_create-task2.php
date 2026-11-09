<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include '../dp.php';

/* ---------------------------------------------------------
   Detect department column (department OR deparment typo)
--------------------------------------------------------- */
$deptCol = null;
if ($res = $conn->query("SHOW COLUMNS FROM users LIKE 'department'")) {
    if ($res->num_rows > 0) $deptCol = 'department';
    $res->free();
}
if (!$deptCol) {
    if ($res = $conn->query("SHOW COLUMNS FROM users LIKE 'deparment'")) {
        if ($res->num_rows > 0) $deptCol = 'deparment';
        $res->free();
    }
}
$deptSelect = $deptCol ? $deptCol : "NULL";

// Fetch MAIN projects
$sqlProjects = "SELECT project_id, project_name FROM projects_x";
$stmtProj = $conn->prepare($sqlProjects);
$stmtProj->execute();
$projectsResult = $stmtProj->get_result();

$message = "";

// POST HANDLER
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $project_id     = intval($_POST['project_id'] ?? 0);
    $sub_project_id = intval($_POST['sub_project_id'] ?? 0);
    $final_project_id = $sub_project_id ? $sub_project_id : $project_id;

    if (!$final_project_id) $message = "⚠️ Please select a project or sub-project.";

    $role = $_POST['project_role'] ?? '';
    if (!$role) $message = "⚠️ Please select a role.";

    $titles       = $_POST['taskTitle'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $assignTos    = $_POST['assignTo'] ?? [];
    $priorities   = $_POST['priority'] ?? [];
    $startDates   = $_POST['startDate'] ?? [];
    $dueDates     = $_POST['dueDate'] ?? [];

    if (!$message && (empty($titles) || empty($titles[0]))) {
        $message = "⚠️ At least one task title is required.";
    }

    if (!$message) {
        $insertSql = "INSERT INTO tasks
                        (taskTitle, description, assignTo, priority, created_at, dueDate, project_id, role, sub_project_id, created_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertSql);
        if (!$stmt) die("❌ SQL Error: " . $conn->error);

        $success = 0;
        $emailsSent = 0;
        $emailQuery = $conn->prepare("SELECT email FROM users WHERE name = ? LIMIT 1");
        if (!$emailQuery) die("❌ SQL Error: " . $conn->error);

        $count = count($titles);
        for ($i = 0; $i < $count; $i++) {
            if (!trim($titles[$i] ?? '')) continue;
            $title = trim($titles[$i]);
            $desc  = trim($descriptions[$i] ?? '');
            $assn  = trim($assignTos[$i] ?? '');
            $prio  = trim($priorities[$i] ?? '');
            $start = trim($startDates[$i] ?? '');
            $due   = trim($dueDates[$i] ?? '');
            if (!$assn || !$prio || !$start || !$due) continue;
            if (strtotime($due) < strtotime($start)) continue;

            $stmt->bind_param("ssssssisii", $title, $desc, $assn, $prio, $start, $due,
                              $final_project_id, $role, $sub_project_id, $teamLeaderId);

            if ($stmt->execute()) {
                $success++;
                $emailQuery->bind_param("s", $assn);
                $emailQuery->execute();
                $emailRes = $emailQuery->get_result();
                if ($emailRow = $emailRes->fetch_assoc()) {
                    $memberEmail = $emailRow['email'];
                    $subject = "New Task Assigned: " . $title;
                    $messageBody = "Hi $assn,\n\nYou have been assigned a new task.\n\n"
                        . "📝 Task: {$title}\n📄 Description: {$desc}\n⚙️ Priority: {$prio}\n"
                        . "📅 Start Date: {$start}\n📅 Due Date: {$due}\n\n"
                        . "Please log into your dashboard to begin:\n"
                        . "https://investhoodprojects.com/Investhood_TSM/tidytrack/\n\n"
                        . "Best regards,\nInvesthood Task Management System\n";
                    $headers  = "From: noreply@investhoodprojects.com\r\n";
                    $headers .= "Reply-To: support@investhoodprojects.com\r\n";
                    $headers .= "X-Mailer: PHP/" . phpversion();
                    if (@mail($memberEmail, $subject, $messageBody, $headers)) $emailsSent++;
                }
            }
        }
        $stmt->close();
        $emailQuery->close();

        echo "<script>
          document.addEventListener('DOMContentLoaded', function () {
            showPopup('Success', '✅ {$success} task(s) created. Emails sent: {$emailsSent}.');
          });
        </script>";
    }
}

// Members list
$members = [];
$mQ = $conn->query("SELECT user_id, name, $deptSelect AS department
                    FROM users WHERE role = 'member' ORDER BY name");
while ($row = $mQ->fetch_assoc()) $members[] = $row;
?>
<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create / Assign Tasks</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* =============================================
   SKIP LINK
============================================= */
.skip-link {
  position: absolute;
  top: -100px;
  left: 0;
  background: #000;
  color: #fff;
  padding: 12px 20px;
  z-index: 100000;
  font-weight: 700;
  font-size: 16px;
  border-radius: 0 0 8px 0;
  text-decoration: none;
  transition: top 0.2s;
}
.skip-link:focus { top: 0; outline: 3px solid #fbbf24; outline-offset: 2px; }

/* =============================================
   SCREEN READER ONLY
============================================= */
.sr-only {
  position: absolute; width: 1px; height: 1px;
  padding: 0; margin: -1px; overflow: hidden;
  clip: rect(0,0,0,0); white-space: nowrap; border-width: 0;
}

/* =============================================
   FOCUS VISIBLE — strong keyboard indicator
============================================= */
*:focus-visible {
  outline: 3px solid #f59e0b !important;
  outline-offset: 3px !important;
  border-radius: 4px;
}

/* =============================================
   CSS VARIABLES + THEMES
============================================= */
:root {
  --bg:#f6f8fb;
  --surface:#ffffff;
  --surface-2:#f9fafb;
  --text:#0f172a;
  --muted:#6b7280;
  --accent:#1d3557;
  --accent-600:#162a44;
  --border:#e5e7eb;
  --red:#dc2626;
  --success:#16a34a;
  --input-height:42px;
  --shadow:0 2px 10px rgba(0,0,0,0.06);
  --shadow-soft:0 1px 6px rgba(0,0,0,0.05);
  --radius:10px;
  --transition:all .2s ease;
}
[data-theme="dark"] {
  --bg:#0b1020;
  --surface:#0f172a;
  --surface-2:#111c33;
  --text:#e5e7eb;
  --muted:#9ca3af;
  --accent:#60a5fa;
  --accent-600:#3b82f6;
  --border:#22304a;
  --shadow:0 2px 12px rgba(0,0,0,0.45);
  --shadow-soft:0 1px 6px rgba(0,0,0,0.35);
}
/* High Contrast */
body.high-contrast {
  --bg:#000000 !important;
  --surface:#111111 !important;
  --surface-2:#1a1a1a !important;
  --text:#ffffff !important;
  --muted:#dddddd !important;
  --accent:#ffff00 !important;
  --accent-600:#cccc00 !important;
  --border:#ffffff !important;
  --red:#ff6666 !important;
  --success:#00ff88 !important;
}
body.high-contrast input,
body.high-contrast select,
body.high-contrast textarea,
body.high-contrast button { border: 2px solid var(--border) !important; }

/* Large Text */
body.large-text { font-size: 118%; }
body.large-text input,
body.large-text select,
body.large-text textarea { font-size: 1rem !important; }
body.large-text label { font-size: 1.05rem !important; }

/* Reduce Motion */
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}
body.reduce-motion *,
body.reduce-motion *::before,
body.reduce-motion *::after {
  animation-duration: 0.01ms !important;
  transition-duration: 0.01ms !important;
}

/* =============================================
   BASE
============================================= */
html, body { margin: 0; padding: 0; width: 100%; }
body {
  font-family: "Poppins", sans-serif;
  background: radial-gradient(1200px circle at 10% -10%, rgba(96,165,250,0.08), transparent 40%),
              radial-gradient(1000px circle at 90% 0%, rgba(34,197,94,0.08), transparent 40%),
              var(--bg);
  color: var(--text);
}
main.page-wrap { max-width: 900px; margin: 40px auto; padding: 0 16px; }

/* =============================================
   TOPBAR
============================================= */
.topbar {
  display: flex; justify-content: space-between;
  align-items: center; gap: 12px; margin-bottom: 18px; flex-wrap: wrap;
}
.back-btn {
  display: inline-flex; align-items: center; gap: 8px;
  text-decoration: none; background: var(--accent); color: #fff;
  padding: 10px 14px; border-radius: var(--radius); font-weight: 600;
  box-shadow: var(--shadow-soft); transition: var(--transition);
}
.back-btn:hover, .back-btn:focus-visible { background: var(--accent-600); }

.topbar-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

.theme-toggle {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--surface); color: var(--text);
  padding: 8px 12px; border: 1px solid var(--border);
  border-radius: 999px; font-weight: 600; cursor: pointer;
  box-shadow: var(--shadow-soft); transition: var(--transition);
  font-family: inherit; font-size: .9rem;
}
.theme-toggle:hover { background: var(--surface-2); }
.theme-toggle .dot { width: 18px; height: 18px; border-radius: 999px; background: linear-gradient(135deg,#fbbf24,#f97316); }
[data-theme="dark"] .theme-toggle .dot { background: linear-gradient(135deg,#60a5fa,#a78bfa); }

/* =============================================
   ACCESSIBILITY TOOLBAR (right side fixed)
============================================= */
#a11y-toolbar {
  position: fixed;
  top: 50%;
  right: 0;
  transform: translateY(-50%);
  z-index: 99998;
  display: flex;
  flex-direction: column;
  gap: 4px;
  background: var(--surface);
  border: 2px solid var(--border);
  border-right: none;
  border-radius: 12px 0 0 12px;
  padding: 10px 6px;
  box-shadow: var(--shadow);
}
.a11y-btn {
  width: 44px; height: 44px;
  border-radius: 8px;
  border: 2px solid var(--border);
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  transition: var(--transition);
  position: relative;
  font-family: inherit;
}
.a11y-btn:hover, .a11y-btn.active {
  background: var(--accent);
  color: #000;
  border-color: var(--accent);
  transform: scale(1.1);
}
.a11y-btn .a11y-tooltip {
  position: absolute;
  right: calc(100% + 10px);
  top: 50%; transform: translateY(-50%);
  background: #1e293b; color: #fff;
  padding: 6px 12px; border-radius: 6px;
  font-size: 13px; font-weight: 600;
  white-space: nowrap; pointer-events: none;
  opacity: 0; transition: opacity 0.2s; z-index: 99999;
}
.a11y-btn:hover .a11y-tooltip,
.a11y-btn:focus-visible .a11y-tooltip { opacity: 1; }

/* =============================================
   VOICE ASSISTANT PANEL
============================================= */
#voice-panel {
  position: fixed; bottom: 24px; right: 24px;
  z-index: 99998; width: 360px;
  background: var(--surface);
  border: 2px solid var(--accent);
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  overflow: hidden; display: none; flex-direction: column;
  font-size: 14px;
}
#voice-panel.open { display: flex; animation: slideUp .3s ease; }
@keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
#voice-panel-header {
  padding: 14px 18px; background: var(--accent);
  color: #000; display: flex; align-items: center;
  justify-content: space-between; font-weight: 800; font-size: 15px;
}
#voice-transcript {
  padding: 16px; min-height: 60px; max-height: 100px;
  overflow-y: auto; color: var(--text); background: var(--bg);
  font-size: 14px; border-bottom: 1px solid var(--border);
}
#voice-response {
  padding: 14px 16px; min-height: 50px; max-height: 100px;
  overflow-y: auto; color: var(--text); font-size: 13px; line-height: 1.5;
}
#voice-controls {
  padding: 14px 16px; display: flex; gap: 10px;
  align-items: center; border-top: 1px solid var(--border); background: var(--bg);
}
#voice-mic-btn {
  width: 52px; height: 52px; border-radius: 50%; border: none;
  background: var(--accent); color: #000;
  font-size: 22px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: var(--transition); flex-shrink: 0;
}
#voice-mic-btn.listening {
  background: var(--red); color: #fff;
  animation: pulse-mic 1s infinite;
}
@keyframes pulse-mic {
  0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.4); }
  50%       { box-shadow: 0 0 0 12px rgba(220,38,38,0); }
}
#voice-status { font-size: 12px; color: var(--muted); font-weight: 600; flex: 1; }
#voice-commands-hint {
  padding: 10px 16px; background: var(--surface-2);
  font-size: 12px; color: var(--muted); line-height: 1.6;
  border-top: 1px solid var(--border);
}

/* =============================================
   KEYBOARD SHORTCUTS MODAL
============================================= */
#kbd-help-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.6); justify-content: center;
  align-items: center; z-index: 99999;
}
#kbd-help-overlay.open { display: flex; }
#kbd-help-box {
  background: var(--surface); padding: 28px;
  width: 480px; max-width: 94%; border-radius: 14px;
  box-shadow: 0 20px 50px rgba(0,0,0,.4); max-height: 80vh; overflow-y: auto;
}
#kbd-help-box h2 { color: var(--accent); margin: 0 0 18px; font-size: 1.2rem; }
#kbd-help-box table { width: 100%; border-collapse: collapse; font-size: .9rem; }
#kbd-help-box th { text-align: left; padding: 8px; background: var(--surface-2); border: 1px solid var(--border); }
#kbd-help-box td { padding: 8px; border: 1px solid var(--border); }
kbd {
  background: var(--surface-2); border: 1px solid var(--border);
  border-radius: 4px; padding: 2px 6px; font-size: .85rem; font-family: monospace;
}

/* =============================================
   PAGE HEADING
============================================= */
.page-title {
  text-align: center; font-size: clamp(1.6rem, 3vw, 2rem);
  font-weight: 600; color: var(--accent); margin-bottom: 10px;
}
.heading-accent {
  height: 4px; width: 120px; margin: 0 auto 32px;
  background: linear-gradient(90deg, var(--accent) 0%, #2a9d8f 100%);
  border-radius: 999px;
}

/* =============================================
   ALERTS
============================================= */
.alert {
  padding: 14px 16px; border-radius: 8px; margin-bottom: 16px;
  color: #fff; font-weight: 600; display: flex; align-items: center; gap: 10px;
}
.alert-error { background: var(--red); }
.alert-success { background: var(--success); }

/* =============================================
   FORM
============================================= */
form .form-group { display: flex; flex-direction: column; margin-bottom: 18px; }
label { font-weight: 600; margin-bottom: 6px; color: var(--text); }
.field-hint { font-size: .82rem; color: var(--muted); margin-top: 4px; }
.required-mark { color: var(--red); margin-left: 2px; aria-hidden: true; }

input, select, textarea {
  border: 1px solid var(--border); border-radius: var(--radius);
  padding: 10px 12px; font-size: .95rem; height: var(--input-height);
  background: var(--surface); color: var(--text);
  box-shadow: var(--shadow-soft); font-family: inherit;
  transition: var(--transition);
}
textarea { resize: vertical; min-height: 90px; height: auto; }
input:focus, select:focus, textarea:focus {
  outline: none; border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(96,165,250,0.25);
}
input[aria-invalid="true"], select[aria-invalid="true"], textarea[aria-invalid="true"] {
  border-color: var(--red) !important;
  box-shadow: 0 0 0 3px rgba(220,38,38,0.2) !important;
}
.field-error {
  color: var(--red); font-size: .83rem; font-weight: 600; margin-top: 4px;
  display: none;
}
.field-error.visible { display: block; }

/* =============================================
   BUTTONS
============================================= */
button {
  background: var(--accent); color: #fff; border: none;
  border-radius: var(--radius); padding: 10px 14px; font-weight: 600;
  cursor: pointer; transition: var(--transition); margin-top: 8px;
  box-shadow: var(--shadow-soft); font-family: inherit;
}
button:hover { background: var(--accent-600); transform: translateY(-1px); }
button:focus-visible { outline: 3px solid #f59e0b !important; outline-offset: 3px !important; }
button.removeRow {
  background: var(--red); padding: 6px 10px; font-size: .85rem; margin: 0;
}
button.removeRow:hover { background: #b91c1c; }
.btn-submit { width: 100%; font-size: 1rem; padding: 14px; margin-top: 12px; }

/* =============================================
   TASK TABLE
============================================= */
.table-wrapper {
  overflow-x: auto; border-radius: 12px;
  box-shadow: var(--shadow); margin-top: 20px;
}
#taskTable {
  width: 100%; border-collapse: collapse;
  background: var(--surface); min-width: 600px;
}
#taskTable caption {
  font-weight: 700; padding: 10px; text-align: left;
  color: var(--text); background: var(--surface-2);
  border-bottom: 1px solid var(--border); font-size: .9rem;
}
#taskTable thead { background: var(--accent); color: #fff; }
#taskTable thead th { padding: 12px 10px; font-weight: 600; text-align: left; }
#taskTable tbody td { padding: 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
#taskTable tbody tr:nth-child(even) { background: var(--surface-2); }
#taskTable tbody tr:hover { background: rgba(96,165,250,0.12); }
#taskTable tfoot td { padding: 12px 10px; color: var(--muted); font-size: .85rem; font-style: italic; }
.table-empty-msg { text-align: center; color: var(--muted); padding: 20px; }

/* =============================================
   MULTI-SELECT DROPDOWN
============================================= */
.ms-wrap { position: relative; width: 100%; }
.ms-btn {
  width: 100%; border: 1px solid var(--border); border-radius: var(--radius);
  padding: 10px 12px; background: var(--surface); cursor: pointer;
  display: flex; justify-content: space-between; align-items: center;
  font-family: inherit; box-shadow: var(--shadow-soft); color: var(--text);
  font-size: .95rem;
}
.ms-btn[aria-expanded="true"] { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(96,165,250,.25); }
.ms-menu {
  display: none; position: absolute; top: calc(100% + 6px); left: 0; width: 100%;
  background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
  padding: 8px; max-height: 260px; overflow: auto;
  z-index: 99999; box-shadow: var(--shadow);
}
.ms-menu.open { display: block; }
.ms-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px; border-radius: var(--radius); cursor: pointer;
}
.ms-item:hover { background: rgba(96,165,250,0.12); }
.ms-item input { width: auto; height: auto; box-shadow: none; }
.ms-item span { color: var(--text) !important; }
.ms-item.selected { background: #eef2ff; border: 1px solid #c7d2fe; }
[data-theme="dark"] .ms-item.selected { background: rgba(96,165,250,0.14); border: 1px solid rgba(96,165,250,0.35); }
body.high-contrast .ms-item.selected { background: #333300; border: 2px solid #ffff00; }
.ms-search {
  width: 100%; padding: 10px 12px; border: 1px solid var(--border);
  border-radius: var(--radius); background: var(--surface); color: var(--text);
  outline: none; box-sizing: border-box; font-family: inherit;
}
.ms-empty { padding: 10px; color: var(--muted); }

/* =============================================
   POPUP / SUCCESS DIALOG
============================================= */
.popup-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.6); justify-content: center;
  align-items: center; z-index: 99999;
}
.popup-overlay.open { display: flex; }
.popup-box {
  background: var(--surface); padding: 28px; width: 380px;
  max-width: 92%; border-radius: 14px; text-align: center;
  box-shadow: 0 20px 50px rgba(0,0,0,.4);
}
.popup-box h3 { margin-bottom: 10px; color: var(--accent); }
.popup-box p { color: var(--text); font-size: .95rem; }
.popup-box button { margin-top: 18px; padding: 10px 26px; }

/* =============================================
   TOAST NOTIFICATIONS
============================================= */
.toast {
  position: fixed; top: 20px; right: 70px;
  background: var(--surface); color: var(--text);
  padding: 12px 20px; border-radius: var(--radius);
  box-shadow: var(--shadow); z-index: 99997;
  font-weight: 600; font-size: .9rem;
  animation: toastIn .3s ease;
  border-left: 4px solid var(--accent);
  max-width: 320px;
}
@keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes toastOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
</style>
</head>

<body>
<!-- =============================================
     SKIP LINKS
============================================= -->
<a href="#main-content" class="skip-link">Skip to main content</a>
<a href="#taskTitle" class="skip-link" style="left:220px">Skip to task title field</a>

<!-- =============================================
     ARIA LIVE REGIONS
============================================= -->
<div id="live-region" aria-live="polite" aria-atomic="true" role="status"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);"></div>
<div id="live-region-urgent" aria-live="assertive" aria-atomic="true" role="alert"
     style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);"></div>

<!-- =============================================
     ACCESSIBILITY TOOLBAR
============================================= -->
<nav id="a11y-toolbar" aria-label="Accessibility options">

  <button class="a11y-btn" id="btn-high-contrast" onclick="toggleHighContrast()"
          aria-pressed="false" aria-label="Toggle high contrast mode">
    <i class="fas fa-adjust" aria-hidden="true"></i>
    <span class="a11y-tooltip">High Contrast</span>
  </button>

  <button class="a11y-btn" id="btn-large-text" onclick="toggleLargeText()"
          aria-pressed="false" aria-label="Toggle large text mode">
    <i class="fas fa-text-height" aria-hidden="true"></i>
    <span class="a11y-tooltip">Large Text</span>
  </button>

 <!-- <button class="a11y-btn" id="btn-reduce-motion" onclick="toggleReduceMotion()"
          aria-pressed="false" aria-label="Reduce animations">
    <i class="fas fa-wind" aria-hidden="true"></i>
    <span class="a11y-tooltip">Reduce Motion</span>
  </button>

  <button class="a11y-btn" id="btn-dark-mode" onclick="toggleDarkMode()"
          aria-pressed="false" aria-label="Toggle dark mode">
    <i class="fas fa-moon" aria-hidden="true"></i>
    <span class="a11y-tooltip">Dark Mode</span>
  </button>-->

  <button class="a11y-btn" id="btn-voice" onclick="toggleVoicePanel()"
          aria-expanded="false" aria-controls="voice-panel"
          aria-label="Open voice assistant">
    <i class="fas fa-microphone" aria-hidden="true"></i>
    <span class="a11y-tooltip">Voice Assistant</span>
  </button>

  <button class="a11y-btn" id="btn-read-form" onclick="readFormSummary()"
          aria-label="Read form instructions aloud">
    <i class="fas fa-volume-up" aria-hidden="true"></i>
    <span class="a11y-tooltip">Read Form</span>
  </button>

  <button class="a11y-btn" id="btn-stop-speech" onclick="stopSpeech()"
          aria-label="Stop speaking" style="display:none;">
    <i class="fas fa-stop" aria-hidden="true"></i>
    <span class="a11y-tooltip">Stop Reading</span>
  </button>

  <button class="a11y-btn" id="btn-kbd-help" onclick="toggleKbdHelp()"
          aria-expanded="false" aria-controls="kbd-help-overlay"
          aria-label="Keyboard shortcuts help">
    <i class="fas fa-keyboard" aria-hidden="true"></i>
    <span class="a11y-tooltip">Keyboard Help</span>
  </button>

</nav>

<!-- =============================================
     VOICE ASSISTANT PANEL
============================================= -->
<div id="voice-panel" role="dialog" aria-label="Voice assistant" aria-modal="false">
  <div id="voice-panel-header">
    <span><i class="fas fa-microphone" aria-hidden="true"></i> &nbsp;Voice Assistant</span>
    <button onclick="toggleVoicePanel()" aria-label="Close voice assistant"
            style="background:none;border:none;color:#000;cursor:pointer;font-size:20px;line-height:1;">
      &times;
    </button>
  </div>
  <div id="voice-transcript" role="log" aria-live="polite" aria-label="Your speech">
    <em style="color:var(--muted);">Your speech will appear here…</em>
  </div>
  <div id="voice-response" aria-live="polite" aria-label="Assistant response">
    <em style="color:var(--muted);">Assistant response will appear here…</em>
  </div>
  <div id="voice-controls">
    <button id="voice-mic-btn" onclick="toggleListening()"
            aria-label="Start listening" aria-pressed="false">
      <i class="fas fa-microphone" aria-hidden="true"></i>
    </button>
    <div id="voice-status">Click the mic to speak</div>
  </div>
  <div id="voice-commands-hint">
    <strong>Try saying:</strong><br>
    "Read form instructions" • "What fields are required" •
    "How many tasks added" • "Clear form" • "Submit form" •
    "Stop reading" • "Toggle dark mode"
  </div>
</div>

<!-- =============================================
     KEYBOARD SHORTCUTS HELP
============================================= -->
<div id="kbd-help-overlay" role="dialog" aria-modal="true" aria-labelledby="kbdHelpTitle">
  <div id="kbd-help-box">
    <h2 id="kbdHelpTitle"><i class="fas fa-keyboard" aria-hidden="true"></i> Keyboard Shortcuts</h2>
    <table aria-label="Keyboard shortcuts list">
      <thead>
        <tr><th>Key</th><th>Action</th></tr>
      </thead>
      <tbody>
        <tr><td><kbd>?</kbd></td><td>Show / hide this help</td></tr>
        <tr><td><kbd>Escape</kbd></td><td>Close dialogs / stop speech</td></tr>
        <tr><td><kbd>Alt + R</kbd></td><td>Read form instructions aloud</td></tr>
        <tr><td><kbd>Alt + V</kbd></td><td>Toggle voice assistant</td></tr>
        <tr><td><kbd>Alt + C</kbd></td><td>Toggle high contrast</td></tr>
        <tr><td><kbd>Alt + T</kbd></td><td>Toggle large text</td></tr>
        <tr><td><kbd>Alt + D</kbd></td><td>Toggle dark mode</td></tr>
        <tr><td><kbd>Alt + S</kbd></td><td>Read task table summary</td></tr>
        <tr><td><kbd>Tab / Shift+Tab</kbd></td><td>Navigate fields</td></tr>
        <tr><td><kbd>Enter / Space</kbd></td><td>Activate buttons</td></tr>
        <tr><td><kbd>Arrow keys</kbd></td><td>Navigate select dropdowns</td></tr>
      </tbody>
    </table>
    <button onclick="toggleKbdHelp()" style="margin-top:16px;" aria-label="Close keyboard shortcuts help">
      Close
    </button>
  </div>
</div>

<!-- =============================================
     MAIN CONTENT
============================================= -->
<main class="page-wrap" id="main-content" role="main">

  <!-- TOPBAR -->
  <header class="topbar" role="banner">
    <a href="../manager/manager_index.php" class="back-btn"
       aria-label="Back to Manager Dashboard">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
           style="width:18px;height:18px">
        <path d="M15 18l-6-6 6-6" />
      </svg>
      Back to Dashboard
    </a>

    <div class="topbar-controls">
      <button type="button" class="theme-toggle" id="themeToggle"
              aria-label="Toggle colour theme">
        <span class="dot" aria-hidden="true"></span>
        <span id="themeLabel">Dark mode</span>
      </button>
    </div>
  </header>

  <h1 class="page-title" id="page-heading">Create / Assign Task(s)</h1>
  <div class="heading-accent" aria-hidden="true"></div>

  <!-- ERROR MESSAGE -->
  <?php if ($message): ?>
    <div class="alert alert-error" role="alert" aria-live="assertive">
      <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <!-- FORM INSTRUCTIONS (for screen readers + sighted users) -->
  <div class="sr-only" id="form-instructions" aria-live="polite">
    Create and assign tasks to team members. Fill in the task details below,
    then click "Add Task" to add it to the task list. You may add multiple tasks
    before clicking "Create Tasks" to submit. All fields marked with an asterisk are required.
  </div>

  <!-- =============================================
       MAIN FORM
  ============================================= -->
  <form method="POST" action="leader_create-task2.php" id="taskForm"
        aria-labelledby="page-heading"
        aria-describedby="form-instructions"
        novalidate>

    <!-- MAIN PROJECT -->
    <div class="form-group">
      <label for="project">
        Main Project <span class="required-mark" aria-label="required">*</span>
      </label>
      <select id="project" name="project_id" required
              aria-required="true"
              aria-describedby="project-hint">
        <option value="">-- Select Project --</option>
        <?php while ($p = $projectsResult->fetch_assoc()): ?>
          <option value="<?= (int)$p['project_id'] ?>">
            <?= htmlspecialchars($p['project_name']) ?>
          </option>
        <?php endwhile; ?>
      </select>
      <span class="field-hint" id="project-hint">Select the main project this task belongs to.</span>
      <span class="field-error" id="project-error" aria-live="polite"></span>
    </div>

    <!-- SUB-PROJECT -->
    <div class="form-group" id="subProjectGroup" style="display:none;">
      <label for="subProject">Sub-Project</label>
      <select id="subProject" name="sub_project_id"
              aria-describedby="subproject-hint">
        <option value="">-- Select Sub-Project (optional) --</option>
      </select>
      <span class="field-hint" id="subproject-hint">If this task belongs to a sub-project, select it here.</span>
    </div>

    <!-- ROLE -->
    <div class="form-group">
      <label for="project_role">
        Role <span class="required-mark" aria-label="required">*</span>
      </label>
      <select name="project_role" id="project_role" required
              aria-required="true"
              aria-describedby="role-hint">
        <option value="">-- Select Role --</option>
        <option value="frontend">Frontend Developer</option>
        <option value="backend">Backend Developer</option>
        <option value="fullstack">Full-Stack Developer</option>
        <option value="dba">DBA</option>
        <option value="uiux">UI/UX Designer</option>
        <option value="qa">Tester</option>
        <option value="devops">DevOps</option>
        <option value="business_analyst">Business Analyst</option>
        <option value="marketing">Marketing</option>
      </select>
      <span class="field-hint" id="role-hint">Select the role responsible for this task.</span>
      <span class="field-error" id="role-error" aria-live="polite"></span>
    </div>

    <!-- TASK TITLE -->
    <div class="form-group">
      <label for="taskTitle">
        Task Title <span class="required-mark" aria-label="required">*</span>
      </label>
      <input type="text" id="taskTitle" name="_taskTitle_ui"
             placeholder="e.g. Build login page"
             aria-required="true"
             aria-describedby="title-hint"
             autocomplete="off">
      <span class="field-hint" id="title-hint">Enter a short, descriptive title for the task.</span>
      <span class="field-error" id="title-error" aria-live="polite"></span>
    </div>

    <!-- DESCRIPTION -->
    <div class="form-group">
      <label for="taskDescription">
        Description <span class="required-mark" aria-label="required">*</span>
      </label>
      <textarea id="taskDescription" name="_desc_ui"
                placeholder="Describe what needs to be done…"
                aria-required="true"
                aria-describedby="desc-hint"></textarea>
      <span class="field-hint" id="desc-hint">Provide enough detail for the assignee to understand the task.</span>
      <span class="field-error" id="desc-error" aria-live="polite"></span>
    </div>

    <!-- RESPONSIBLE PERSON(S) -->
    <div class="form-group">
      <label id="assign-label">
        Responsible Person(s) <span class="required-mark" aria-label="required">*</span>
      </label>
      <div class="ms-wrap" role="group" aria-labelledby="assign-label">
        <button type="button" class="ms-btn" data-ms-btn
                aria-haspopup="listbox" aria-expanded="false"
                aria-controls="assignMenu"
                aria-describedby="assign-hint">
          <span data-ms-text>-- Select Member(s) --</span>
          <span aria-hidden="true">▾</span>
        </button>

        <div class="ms-menu" data-ms-menu id="assignMenu"
             role="listbox" aria-multiselectable="true"
             aria-label="Team members">

          <div style="padding:8px;">
            <label for="ms-search-input" class="sr-only">Search team members</label>
            <input type="text" class="ms-search" id="ms-search-input"
                   data-ms-search placeholder="Search member…"
                   aria-label="Search team members"
                   autocomplete="off">
          </div>

          <?php if (count($members) > 0): ?>
            <?php foreach ($members as $m):
              $rawName = (string)($m['name'] ?? '');
              $rawDept = trim((string)($m['department'] ?? ''));
              $display = $rawDept !== '' ? ($rawName . " - " . $rawDept) : $rawName;
              $nameEsc = htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8');
              $dispEsc = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
              $safeId  = 'assign_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $rawName);
            ?>
              <label class="ms-item" for="<?= htmlspecialchars($safeId, ENT_QUOTES) ?>"
                     role="option">
                <input
                  id="<?= htmlspecialchars($safeId, ENT_QUOTES) ?>"
                  type="checkbox"
                  value="<?= $nameEsc ?>"
                  data-label="<?= $dispEsc ?>"
                  class="assignCheckbox"
                  aria-label="<?= $dispEsc ?>"
                >
                <span><?= $dispEsc ?></span>
              </label>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="ms-empty" role="status">No members available</div>
          <?php endif; ?>
        </div>
      </div>
      <span class="field-hint" id="assign-hint">You may select multiple team members. Each will get a separate task row.</span>
      <span class="field-error" id="assign-error" aria-live="polite"></span>
    </div>

    <!-- PRIORITY -->
    <div class="form-group">
      <label for="priority">
        Priority <span class="required-mark" aria-label="required">*</span>
      </label>
      <select id="priority" aria-required="true" aria-describedby="priority-hint">
        <option value="">-- Select Priority --</option>
        <option value="low">Low</option>
        <option value="normal">Normal</option>
        <option value="high">High</option>
        <option value="urgent">Critical</option>
      </select>
      <span class="field-hint" id="priority-hint">How urgent is this task?</span>
      <span class="field-error" id="priority-error" aria-live="polite"></span>
    </div>

    <!-- START DATE -->
    <div class="form-group">
      <label for="startDate">
        Start Date <span class="required-mark" aria-label="required">*</span>
      </label>
      <input type="date" id="startDate"
             aria-required="true"
             aria-describedby="start-hint">
      <span class="field-hint" id="start-hint">The date work on this task begins.</span>
      <span class="field-error" id="start-error" aria-live="polite"></span>
    </div>

    <!-- DEADLINE -->
    <div class="form-group">
      <label for="dueDate">
        Deadline <span class="required-mark" aria-label="required">*</span>
      </label>
      <input type="date" id="dueDate"
             aria-required="true"
             aria-describedby="due-hint">
      <span class="field-hint" id="due-hint">Must be on or after the start date.</span>
      <span class="field-error" id="due-error" aria-live="polite"></span>
    </div>

    <!-- ADD TASK BUTTON -->
    <div class="form-group">
      <button type="button" id="addTaskBtn"
              aria-describedby="add-task-hint">
        <i class="fas fa-plus" aria-hidden="true"></i> Add Task to List
      </button>
      <span class="field-hint" id="add-task-hint">
        Adds the above details as a row in the task list below. You can add multiple tasks before submitting.
      </span>
    </div>

    <!-- TASK TABLE -->
    <div class="table-wrapper" role="region" aria-label="Task list" aria-live="polite">
      <table id="taskTable" aria-label="Tasks staged for creation" aria-describedby="table-caption-desc">
        <caption id="table-caption-desc">
          Tasks ready to be created — <span id="task-count-label">0 tasks added</span>.
          Review before submitting.
        </caption>
        <thead>
          <tr>
            <th scope="col">Title</th>
            <th scope="col">Description</th>
            <th scope="col">Assign To</th>
            <th scope="col">Priority</th>
            <th scope="col">Start Date</th>
            <th scope="col">Deadline</th>
            <th scope="col">Action</th>
          </tr>
        </thead>
        <tbody id="taskTableBody">
          <tr id="empty-table-row">
            <td colspan="7" class="table-empty-msg" role="status">
              No tasks added yet. Fill in the form above and click "Add Task to List".
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- SUBMIT -->
    <div class="form-group" style="margin-top:24px;">
      <button type="submit" class="btn-submit"
              aria-describedby="submit-hint">
        <i class="fas fa-paper-plane" aria-hidden="true"></i> Create Task(s) &amp; Send Emails
      </button>
      <span class="field-hint" id="submit-hint">
        Saves all tasks in the list above and sends an email notification to each assigned member.
      </span>
    </div>

  </form>

  <!-- =============================================
       SUCCESS POPUP
  ============================================= -->
  <div id="popupOverlay" class="popup-overlay" role="dialog"
       aria-modal="true" aria-labelledby="popupTitle">
    <div class="popup-box">
      <h3 id="popupTitle"></h3>
      <p id="popupMessage"></p>
      <button type="button" onclick="closePopup()"
              aria-label="OK — return to dashboard">OK</button>
    </div>
  </div>

</main>

<!-- =============================================
     JAVASCRIPT
============================================= -->
<script>
// =============================================
// LIVE REGION ANNOUNCER
// =============================================
function announce(msg, urgent = false) {
  const el = document.getElementById(urgent ? 'live-region-urgent' : 'live-region');
  el.textContent = '';
  requestAnimationFrame(() => { el.textContent = msg; });
}

// =============================================
// TEXT-TO-SPEECH
// =============================================
let currentUtterance = null;

function speak(text, onEnd) {
  if (!window.speechSynthesis) return;
  stopSpeech();
  currentUtterance = new SpeechSynthesisUtterance(text);
  currentUtterance.rate = 0.95;
  currentUtterance.pitch = 1.0;
  currentUtterance.lang = 'en-ZA';

  currentUtterance.onstart = () => {
    document.getElementById('btn-stop-speech').style.display = 'flex';
    document.getElementById('btn-read-form').classList.add('active');
    document.getElementById('btn-read-form').setAttribute('aria-label', 'Currently reading — click to stop');
  };
  currentUtterance.onend = currentUtterance.onerror = () => {
    document.getElementById('btn-stop-speech').style.display = 'none';
    document.getElementById('btn-read-form').classList.remove('active');
    document.getElementById('btn-read-form').setAttribute('aria-label', 'Read form instructions aloud');
    if (onEnd) onEnd();
  };
  window.speechSynthesis.speak(currentUtterance);
}

function stopSpeech() {
  if (window.speechSynthesis) {
    window.speechSynthesis.cancel();
    document.getElementById('btn-stop-speech').style.display = 'none';
    document.getElementById('btn-read-form').classList.remove('active');
  }
}

function readFormSummary() {
  const count = document.querySelectorAll('#taskTableBody tr.task-row').length;
  const project = document.getElementById('project');
  const projectName = project.options[project.selectedIndex]?.text || 'not selected';
  const role = document.getElementById('project_role');
  const roleName = role.options[role.selectedIndex]?.text || 'not selected';

  const summary =
    `Create and Assign Tasks form. ` +
    `This form has 8 required fields: Main Project, Role, Task Title, Description, ` +
    `Responsible Person, Priority, Start Date, and Deadline. ` +
    `Currently selected project: ${projectName}. ` +
    `Currently selected role: ${roleName}. ` +
    `${count} task${count !== 1 ? 's' : ''} added to the list so far. ` +
    `Fill in all fields and click Add Task to List to stage a task. ` +
    `When ready, click Create Tasks and Send Emails to submit. ` +
    `Press Alt R to hear this summary again.`;

  speak(summary);
  announce(summary);
}

function readTableSummary() {
  const rows = document.querySelectorAll('#taskTableBody tr.task-row');
  if (rows.length === 0) {
    speak('The task list is empty. Add at least one task before submitting.');
    announce('The task list is empty.');
    return;
  }
  let text = `${rows.length} task${rows.length !== 1 ? 's' : ''} in the list. `;
  rows.forEach((row, i) => {
    const cells = row.querySelectorAll('td');
    const title    = cells[0]?.textContent.trim() || '—';
    const assignee = cells[2]?.textContent.trim() || '—';
    const priority = cells[3]?.textContent.trim() || '—';
    const due      = cells[5]?.textContent.trim() || '—';
    text += `Task ${i + 1}: ${title}, assigned to ${assignee}, priority ${priority}, due ${due}. `;
  });
  speak(text);
  announce(text);
}

// =============================================
// VOICE ASSISTANT
// =============================================
let recognition = null;
let isListening  = false;

function setupRecognition() {
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) return null;
  const r = new SR();
  r.lang = 'en-ZA';
  r.continuous = false;
  r.interimResults = true;

  r.onresult = (event) => {
    let interim = '', final = '';
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const t = event.results[i][0].transcript;
      event.results[i].isFinal ? (final += t) : (interim += t);
    }
    document.getElementById('voice-transcript').textContent = final || interim || '…';
    if (final) handleVoiceCommand(final.toLowerCase().trim());
  };
  r.onerror = (e) => {
    const msgs = {
      'no-speech':    'No speech detected. Please try again.',
      'not-allowed':  'Microphone access denied. Please allow microphone access in browser settings.',
      'audio-capture':'No microphone found.',
      'network':      'Network error during speech recognition.',
    };
    const msg = msgs[e.error] || `Error: ${e.error}`;
    document.getElementById('voice-status').textContent = msg;
    document.getElementById('voice-transcript').textContent = msg;
    setListening(false);
    speak(msg);
  };
  r.onend = () => setListening(false);
  return r;
}

function setListening(state) {
  isListening = state;
  const btn    = document.getElementById('voice-mic-btn');
  const status = document.getElementById('voice-status');
  btn.classList.toggle('listening', state);
  btn.setAttribute('aria-pressed', state ? 'true' : 'false');
  btn.setAttribute('aria-label', state ? 'Stop listening' : 'Start listening');
  btn.querySelector('i').className = state ? 'fas fa-stop' : 'fas fa-microphone';
  status.textContent = state ? 'Listening… speak now' : 'Click the mic to speak';
}

function toggleListening() {
  if (!recognition) recognition = setupRecognition();
  if (!recognition) {
    const msg = 'Speech recognition is not supported in your browser. Try Chrome or Edge.';
    document.getElementById('voice-response').textContent = msg;
    speak(msg);
    return;
  }
  if (isListening) {
    recognition.stop();
  } else {
    document.getElementById('voice-transcript').textContent = '…';
    document.getElementById('voice-response').textContent = '';
    try { recognition.start(); setListening(true); } catch(e) {}
  }
}

function handleVoiceCommand(cmd) {
  let response = '';

  if (cmd.includes('stop') || cmd.includes('quiet') || cmd.includes('silence')) {
    stopSpeech(); response = 'Stopped.';

  } else if (cmd.includes('read') || cmd.includes('instructions') || cmd.includes('help') || cmd.includes('explain')) {
    readFormSummary(); response = 'Reading form instructions now.';

  } else if (cmd.includes('how many task') || cmd.includes('task count') || cmd.includes('task list')) {
    readTableSummary(); response = 'Reading task list now.';

  } else if (cmd.includes('required') || cmd.includes('what field')) {
    response = 'Required fields are: Main Project, Role, Task Title, Description, Responsible Person, Priority, Start Date, and Deadline.';

  } else if (cmd.includes('clear') || cmd.includes('reset')) {
    document.getElementById('taskTitle').value = '';
    document.getElementById('taskDescription').value = '';
    document.getElementById('priority').selectedIndex = 0;
    document.getElementById('startDate').value = '';
    document.getElementById('dueDate').value = '';
    document.querySelectorAll('#assignMenu .assignCheckbox').forEach(cb => cb.checked = false);
    document.querySelector('[data-ms-menu]').dispatchEvent(new Event('change', { bubbles: true }));
    response = 'Form fields cleared.';
    announce('Form fields cleared.', true);

  } else if (cmd.includes('submit') || cmd.includes('create task') || cmd.includes('save')) {
    const count = document.querySelectorAll('#taskTableBody tr.task-row').length;
    if (count === 0) {
      response = 'No tasks in the list yet. Add at least one task before submitting.';
    } else {
      document.getElementById('taskForm').submit();
      response = `Submitting ${count} task${count !== 1 ? 's' : ''} now.`;
    }

  } else if (cmd.includes('dark') || cmd.includes('dark mode')) {
    toggleDarkMode(); response = 'Dark mode toggled.';

  } else if (cmd.includes('contrast') || cmd.includes('high contrast')) {
    toggleHighContrast(); response = 'High contrast mode toggled.';

  } else if (cmd.includes('large text') || cmd.includes('bigger')) {
    toggleLargeText(); response = 'Large text mode toggled.';

  } else if (cmd.includes('back') || cmd.includes('dashboard') || cmd.includes('go back')) {
    response = 'Navigating back to dashboard.';
    setTimeout(() => window.location.href = '../manager/manager_index.php', 1000);

  } else {
    response = `I heard: "${cmd}". Say "help" to hear form instructions, or "how many tasks" for the task list.`;
  }

  document.getElementById('voice-response').textContent = response;
  announce(response, true);
  if (response && !response.startsWith('Reading')) speak(response);
}

function toggleVoicePanel() {
  const panel = document.getElementById('voice-panel');
  const btn   = document.getElementById('btn-voice');
  const isOpen = panel.classList.toggle('open');
  btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  btn.classList.toggle('active', isOpen);
  if (isOpen) {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) document.getElementById('voice-response').textContent = 'Speech recognition is not supported in this browser. Text-to-speech is still available.';
    document.getElementById('voice-mic-btn').focus();
    announce('Voice assistant opened.');
  } else {
    if (isListening && recognition) recognition.stop();
    announce('Voice assistant closed.');
  }
}

// =============================================
// ACCESSIBILITY TOGGLES
// =============================================
function toggleHighContrast() {
  const on = document.body.classList.toggle('high-contrast');
  const btn = document.getElementById('btn-high-contrast');
  btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  btn.classList.toggle('active', on);
  localStorage.setItem('a11y-high-contrast', on);
  announce(on ? 'High contrast enabled.' : 'High contrast disabled.');
}
function toggleLargeText() {
  const on = document.body.classList.toggle('large-text');
  const btn = document.getElementById('btn-large-text');
  btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  btn.classList.toggle('active', on);
  localStorage.setItem('a11y-large-text', on);
  announce(on ? 'Large text enabled.' : 'Large text disabled.');
}
function toggleReduceMotion() {
  const on = document.body.classList.toggle('reduce-motion');
  const btn = document.getElementById('btn-reduce-motion');
  btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  btn.classList.toggle('active', on);
  localStorage.setItem('a11y-reduce-motion', on);
  announce(on ? 'Reduced motion enabled.' : 'Reduced motion disabled.');
}
function toggleDarkMode() {
  // Sync with existing theme system
  toggleTheme();
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const btn = document.getElementById('btn-dark-mode');
  btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
  btn.classList.toggle('active', isDark);
  btn.querySelector('i').className = isDark ? 'fas fa-sun' : 'fas fa-moon';
  announce(isDark ? 'Dark mode enabled.' : 'Dark mode disabled.');
}

// Keyboard shortcuts help
function toggleKbdHelp() {
  const overlay = document.getElementById('kbd-help-overlay');
  const btn     = document.getElementById('btn-kbd-help');
  const isOpen  = overlay.classList.toggle('open');
  btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  if (isOpen) {
    overlay.querySelector('button')?.focus();
    announce('Keyboard shortcuts help opened.');
  } else {
    announce('Keyboard shortcuts help closed.');
  }
}

// Restore saved preferences
(function restoreA11yPrefs() {
  if (localStorage.getItem('a11y-high-contrast') === 'true')  { document.body.classList.add('high-contrast');  document.getElementById('btn-high-contrast').classList.add('active');  document.getElementById('btn-high-contrast').setAttribute('aria-pressed','true'); }
  if (localStorage.getItem('a11y-large-text')    === 'true')  { document.body.classList.add('large-text');      document.getElementById('btn-large-text').classList.add('active');      document.getElementById('btn-large-text').setAttribute('aria-pressed','true'); }
  if (localStorage.getItem('a11y-reduce-motion') === 'true')  { document.body.classList.add('reduce-motion');   document.getElementById('btn-reduce-motion').classList.add('active');   document.getElementById('btn-reduce-motion').setAttribute('aria-pressed','true'); }

  // Respect OS-level preferences
  if (window.matchMedia('(prefers-contrast: more)').matches       && !localStorage.getItem('a11y-high-contrast')) toggleHighContrast();
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches && !localStorage.getItem('a11y-reduce-motion')) toggleReduceMotion();
})();

// =============================================
// GLOBAL KEYBOARD SHORTCUTS
// =============================================
document.addEventListener('keydown', (e) => {
  const inInput = ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName);

  if (e.key === '?' && !e.ctrlKey && !e.altKey && !inInput) {
    toggleKbdHelp(); return;
  }
  if (e.key === 'Escape') {
    stopSpeech();
    if (document.getElementById('kbd-help-overlay').classList.contains('open')) toggleKbdHelp();
    if (document.getElementById('popupOverlay').classList.contains('open')) closePopup();
    if (document.getElementById('voice-panel').classList.contains('open'))  toggleVoicePanel();
    return;
  }
  if (e.altKey) {
    switch(e.key.toLowerCase()) {
      case 'r': e.preventDefault(); readFormSummary();   break;
      case 'v': e.preventDefault(); toggleVoicePanel();  break;
      case 'c': e.preventDefault(); toggleHighContrast(); break;
      case 't': e.preventDefault(); toggleLargeText();   break;
      case 'd': e.preventDefault(); toggleDarkMode();    break;
      case 's': e.preventDefault(); readTableSummary();  break;
    }
  }
});

// Focus trap for popups
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Tab') return;
  const openDialog = [...document.querySelectorAll('[role="dialog"]')]
    .find(d => d.classList.contains('open') || d.style.display === 'flex');
  if (!openDialog) return;
  const focusable = openDialog.querySelectorAll(
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  );
  if (!focusable.length) return;
  const first = focusable[0], last = focusable[focusable.length - 1];
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
});

// =============================================
// TOAST
// =============================================
function showToast(message, type = 'info') {
  document.querySelectorAll('.toast').forEach(t => t.remove());
  const colorMap = { success: '#16a34a', error: '#dc2626', info: '#1d4ed8', warning: '#d97706' };
  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
  toast.style.borderLeftColor = colorMap[type] || colorMap.info;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => {
    toast.style.animation = 'toastOut .3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 3500);
}

// =============================================
// INLINE FIELD VALIDATION
// =============================================
function setFieldError(fieldId, errorId, message) {
  const field = document.getElementById(fieldId);
  const error = document.getElementById(errorId);
  if (!field || !error) return;
  if (message) {
    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('aria-describedby', (field.getAttribute('aria-describedby') || '') + ' ' + errorId);
    error.textContent = message;
    error.classList.add('visible');
  } else {
    field.removeAttribute('aria-invalid');
    error.textContent = '';
    error.classList.remove('visible');
  }
}
function clearAllErrors() {
  ['project','project_role','taskTitle','taskDescription','priority','startDate','dueDate'].forEach(id => {
    const field = document.getElementById(id);
    if (field) field.removeAttribute('aria-invalid');
  });
  document.querySelectorAll('.field-error').forEach(el => {
    el.textContent = ''; el.classList.remove('visible');
  });
  document.getElementById('assign-error').textContent = '';
  document.getElementById('assign-error').classList.remove('visible');
}

// =============================================
// THEME (original logic preserved)
// =============================================
(function initTheme(){
  const root = document.documentElement;
  const saved = localStorage.getItem("theme");
  const prefersDark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
  root.setAttribute("data-theme", saved ? saved : (prefersDark ? "dark" : "light"));
  updateThemeLabel();
  // Sync toolbar button
  const isDark = root.getAttribute("data-theme") === "dark";
  const btn = document.getElementById('btn-dark-mode');
  if (btn) {
    btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    btn.classList.toggle('active', isDark);
    btn.querySelector('i').className = isDark ? 'fas fa-sun' : 'fas fa-moon';
  }
})();
function toggleTheme(){
  const root = document.documentElement;
  const next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
  root.setAttribute("data-theme", next);
  localStorage.setItem("theme", next);
  updateThemeLabel();
}
function updateThemeLabel(){
  const label = document.getElementById("themeLabel");
  const isDark = document.documentElement.getAttribute("data-theme") === "dark";
  if (label) label.textContent = isDark ? "Light mode" : "Dark mode";
}
document.getElementById("themeToggle").addEventListener("click", function() {
  toggleTheme();
  const isDark = document.documentElement.getAttribute("data-theme") === "dark";
  const btn = document.getElementById('btn-dark-mode');
  if (btn) {
    btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    btn.classList.toggle('active', isDark);
    btn.querySelector('i').className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    localStorage.setItem('a11y-dark', isDark);
  }
});

// =============================================
// MULTI-SELECT DROPDOWN
// =============================================
(function initMultiSelect(){
  const wrap   = document.querySelector('.ms-wrap');
  if (!wrap) return;
  const btn    = wrap.querySelector('[data-ms-btn]');
  const menu   = wrap.querySelector('[data-ms-menu]');
  const text   = wrap.querySelector('[data-ms-text]');
  const search = wrap.querySelector('[data-ms-search]');

  function updateText(){
    const names = [];
    menu.querySelectorAll('.ms-item').forEach(label => {
      const cb = label.querySelector('input[type="checkbox"]');
      if (!cb) return;
      if (cb.checked) {
        label.classList.add('selected');
        names.push(cb.getAttribute('data-label') || cb.value);
      } else {
        label.classList.remove('selected');
      }
    });
    text.textContent = names.length
      ? (names.length <= 2 ? names.join(', ') : `${names.slice(0,2).join(', ')} +${names.length - 2} more`)
      : '-- Select Member(s) --';
    // Update aria-label for screen readers
    btn.setAttribute('aria-label', names.length
      ? `Selected members: ${names.join(', ')}. Press to change.`
      : 'Select responsible persons. Press to open list.');
  }

  btn.addEventListener('click', function(e){
    e.preventDefault(); e.stopPropagation();
    const willOpen = !menu.classList.contains('open');
    menu.classList.toggle('open', willOpen);
    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    if (willOpen) setTimeout(() => search?.focus(), 0);
    announce(willOpen ? 'Member selection list opened.' : 'Member selection list closed.');
  });

  menu.addEventListener('click', e => e.stopPropagation());
  document.addEventListener('click', () => {
    menu.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  });

  menu.addEventListener('change', function(e){
    if (e.target?.matches('input[type="checkbox"]')) {
      const label = e.target.getAttribute('data-label') || e.target.value;
      const checked = e.target.checked;
      announce(`${label} ${checked ? 'selected' : 'deselected'}.`);
      updateText();
    }
  });

  if (search){
    search.addEventListener('input', function(){
      const q = (search.value || '').toLowerCase().trim();
      let visible = 0;
      menu.querySelectorAll('.ms-item').forEach(item => {
        const name = (item.querySelector('span')?.textContent || '').toLowerCase();
        const show = name.includes(q);
        item.style.display = show ? 'flex' : 'none';
        if (show) visible++;
      });
      const old = menu.querySelector('.ms-empty-search');
      if (old) old.remove();
      if (q && visible === 0){
        const div = document.createElement('div');
        div.className = 'ms-empty ms-empty-search';
        div.setAttribute('role', 'status');
        div.textContent = 'No matches found.';
        menu.appendChild(div);
      }
      announce(q ? `${visible} member${visible !== 1 ? 's' : ''} match "${q}".` : 'Search cleared.');
    });
  }
  updateText();
})();

// =============================================
// TASK TABLE
// =============================================
function escapeHtml(str) {
  return String(str)
    .replaceAll("&","&amp;").replaceAll("<","&lt;")
    .replaceAll(">","&gt;").replaceAll('"',"&quot;").replaceAll("'","&#039;");
}

function updateTaskCount() {
  const count = document.querySelectorAll('#taskTableBody tr.task-row').length;
  const label = document.getElementById('task-count-label');
  if (label) label.textContent = `${count} task${count !== 1 ? 's' : ''} added`;

  // Show/hide empty state row
  const emptyRow = document.getElementById('empty-table-row');
  if (emptyRow) emptyRow.style.display = count > 0 ? 'none' : '';
}

function addTaskToTable(title, desc, assignName, assignLabel, priority, startDate, dueDate) {
  const tbody = document.getElementById('taskTableBody');
  const row   = document.createElement('tr');
  row.classList.add('task-row');

  // Friendly priority label
  const prioLabels = { low:'Low', normal:'Normal', high:'High', urgent:'Critical' };
  const prioDisplay = prioLabels[priority] || priority;

  row.innerHTML = `
    <td>
      <input type="hidden" name="taskTitle[]" value="${escapeHtml(title)}">
      <span aria-label="Task title: ${escapeHtml(title)}">${escapeHtml(title)}</span>
    </td>
    <td>
      <input type="hidden" name="description[]" value="${escapeHtml(desc)}">
      <span aria-label="Description: ${escapeHtml(desc)}">${escapeHtml(desc)}</span>
    </td>
    <td>
      <input type="hidden" name="assignTo[]" value="${escapeHtml(assignName)}">
      <span aria-label="Assigned to: ${escapeHtml(assignLabel)}">${escapeHtml(assignLabel)}</span>
    </td>
    <td>
      <input type="hidden" name="priority[]" value="${escapeHtml(priority)}">
      <span aria-label="Priority: ${escapeHtml(prioDisplay)}">${escapeHtml(prioDisplay)}</span>
    </td>
    <td>
      <input type="hidden" name="startDate[]" value="${escapeHtml(startDate)}">
      <span aria-label="Start date: ${escapeHtml(startDate)}">${escapeHtml(startDate)}</span>
    </td>
    <td>
      <input type="hidden" name="dueDate[]" value="${escapeHtml(dueDate)}">
      <span aria-label="Deadline: ${escapeHtml(dueDate)}">${escapeHtml(dueDate)}</span>
    </td>
    <td>
      <button type="button" class="removeRow"
              aria-label="Remove task: ${escapeHtml(title)} assigned to ${escapeHtml(assignLabel)}">
        <i class="fas fa-trash" aria-hidden="true"></i> Remove
      </button>
    </td>
  `;
  tbody.appendChild(row);
  updateTaskCount();
}

// =============================================
// FORM LOGIC (DOMContentLoaded)
// =============================================
document.addEventListener('DOMContentLoaded', function() {
  const addBtn  = document.getElementById('addTaskBtn');
  const startEl = document.getElementById('startDate');
  const dueEl   = document.getElementById('dueDate');

  function syncDueMin(){
    if (startEl.value) {
      dueEl.min = startEl.value;
      if (dueEl.value && dueEl.value < startEl.value) {
        dueEl.value = startEl.value;
        announce('Deadline updated to match start date.');
      }
    } else {
      dueEl.removeAttribute('min');
    }
  }
  startEl.addEventListener('change', syncDueMin);
  syncDueMin();

  addBtn.addEventListener('click', function() {
    clearAllErrors();
    const title    = document.getElementById('taskTitle').value.trim();
    const desc     = document.getElementById('taskDescription').value.trim();
    const priority = document.getElementById('priority').value;
    const startDate = startEl.value;
    const dueDate   = dueEl.value;
    const project   = document.getElementById('project').value;
    const role      = document.getElementById('project_role').value;

    const selectedMembers = Array.from(
      document.querySelectorAll('#assignMenu .assignCheckbox:checked')
    ).map(cb => ({ name: cb.value, label: cb.getAttribute('data-label') || cb.value }));

    // Validate each field individually with accessible error messages
    let hasErrors = false;

    if (!project) {
      setFieldError('project', 'project-error', 'Please select a main project.');
      hasErrors = true;
    }
    if (!role) {
      setFieldError('project_role', 'role-error', 'Please select a role.');
      hasErrors = true;
    }
    if (!title) {
      setFieldError('taskTitle', 'title-error', 'Task title is required.');
      hasErrors = true;
    }
    if (!desc) {
      setFieldError('taskDescription', 'desc-error', 'Description is required.');
      hasErrors = true;
    }
    if (selectedMembers.length === 0) {
      const assignError = document.getElementById('assign-error');
      assignError.textContent = 'Please select at least one responsible person.';
      assignError.classList.add('visible');
      document.querySelector('[data-ms-btn]').setAttribute('aria-invalid','true');
      hasErrors = true;
    }
    if (!priority) {
      setFieldError('priority', 'priority-error', 'Please select a priority level.');
      hasErrors = true;
    }
    if (!startDate) {
      setFieldError('startDate', 'start-error', 'Start date is required.');
      hasErrors = true;
    }
    if (!dueDate) {
      setFieldError('dueDate', 'due-error', 'Deadline is required.');
      hasErrors = true;
    }
    if (startDate && dueDate && dueDate < startDate) {
      setFieldError('dueDate', 'due-error', 'Deadline must be on or after the start date.');
      hasErrors = true;
    }

    if (hasErrors) {
      const firstError = document.querySelector('[aria-invalid="true"]');
      if (firstError) firstError.focus();
      const errorCount = document.querySelectorAll('.field-error.visible').length;
      announce(`${errorCount} error${errorCount !== 1 ? 's' : ''} found. Please correct them before adding the task.`, true);
      return;
    }

    // Add one row per selected member
    selectedMembers.forEach(m => {
      addTaskToTable(title, desc, m.name, m.label, priority, startDate, dueDate);
    });

    const added = selectedMembers.length;
    const msg = `${added} task row${added !== 1 ? 's' : ''} added for "${title}". ${document.querySelectorAll('#taskTableBody tr.task-row').length} task${document.querySelectorAll('#taskTableBody tr.task-row').length !== 1 ? 's' : ''} total in list.`;
    announce(msg);
    showToast(msg, 'success');

    // Clear inputs
    document.getElementById('taskTitle').value = '';
    document.getElementById('taskDescription').value = '';
    document.getElementById('priority').selectedIndex = 0;
    startEl.value = '';
    dueEl.value   = '';
    syncDueMin();
    document.querySelectorAll('#assignMenu .assignCheckbox').forEach(cb => cb.checked = false);
    document.querySelector('[data-ms-menu]').dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('taskTitle').focus();
  });

  // Remove row
  document.getElementById('taskTableBody').addEventListener('click', function(e) {
    const btn = e.target.closest('.removeRow');
    if (!btn) return;
    const row = btn.closest('tr');
    const taskName = row.querySelector('td:first-child span')?.textContent || 'task';
    row.remove();
    updateTaskCount();
    const remaining = document.querySelectorAll('#taskTableBody tr.task-row').length;
    const msg = `"${taskName}" removed. ${remaining} task${remaining !== 1 ? 's' : ''} remaining.`;
    announce(msg, true);
    showToast(msg, 'info');
    document.getElementById('addTaskBtn').focus();
  });

  // Init count
  updateTaskCount();

  // Announce page load
  setTimeout(() => {
    announce('Create and Assign Tasks page loaded. Press Alt R to hear form instructions, or ? for keyboard shortcuts.');
  }, 800);
});

// =============================================
// SUB-PROJECT FETCH
// =============================================
document.addEventListener("DOMContentLoaded", function () {
  const projectEl = document.getElementById('project');
  if (!projectEl) return;

  projectEl.addEventListener('change', function () {
    const projectId = this.value;
    if (!projectId) {
      document.getElementById('subProjectGroup').style.display = "none";
      return;
    }
    announce('Loading sub-projects…');
    fetch('get_sub_projects.php?project_id=' + encodeURIComponent(projectId))
      .then(r => r.json())
      .then(data => {
        const subSelect = document.getElementById('subProject');
        subSelect.innerHTML = '<option value="">-- Select Sub-Project (optional) --</option>';
        if (Array.isArray(data) && data.length > 0) {
          data.forEach(sp => {
            const opt = document.createElement('option');
            opt.value = sp.id;
            opt.textContent = sp.sub_project_name;
            subSelect.appendChild(opt);
          });
          document.getElementById('subProjectGroup').style.display = "block";
          announce(`${data.length} sub-project${data.length !== 1 ? 's' : ''} loaded.`);
        } else {
          document.getElementById('subProjectGroup').style.display = "none";
          announce('No sub-projects for this project.');
        }
      })
      .catch(() => {
        document.getElementById('subProjectGroup').style.display = "none";
        announce('Could not load sub-projects.', true);
      });
  });
});

// =============================================
// POPUP
// =============================================
function showPopup(title, message) {
  const overlay = document.getElementById("popupOverlay");
  document.getElementById("popupTitle").textContent   = title;
  document.getElementById("popupMessage").textContent = message;
  overlay.classList.add('open');
  overlay.style.display = 'flex';
  overlay.querySelector('button')?.focus();
  announce(title + '. ' + message, true);
  speak(title + '. ' + message);
}
function closePopup() {
  document.getElementById("popupOverlay").style.display = "none";
  document.getElementById("popupOverlay").classList.remove('open');
  window.location.href = 'manager_index.php?success=1';
}
</script>

</main>
</body>
</html>