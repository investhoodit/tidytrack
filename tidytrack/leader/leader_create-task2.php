<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include '../dp.php';

// --- Basic checks ---
$teamLeaderId = $_SESSION['user_id'] ?? null;
if (!$teamLeaderId) {
    die("❌ User not logged in. Team Leader ID not found in session.");
}

/* ---------------------------------------------------------
   ✅ Detect department column (department OR deparment)
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

// --- Fetch MAIN projects (projects_x) ---
$sqlProjects = "SELECT project_id, project_name
                FROM projects_x";
$stmtProj = $conn->prepare($sqlProjects);
// $stmtProj->bind_param("i", $teamLeaderId);
$stmtProj->execute();
$projectsResult = $stmtProj->get_result();

$message = "";

// --- POST HANDLER (MULTI-TASK SAVE + EMAIL) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $project_id     = intval($_POST['project_id'] ?? 0);
    $sub_project_id = intval($_POST['sub_project_id'] ?? 0);

    // Final project assignment
    $final_project_id = $sub_project_id ? $sub_project_id : $project_id;

    if (!$final_project_id) {
        $message = "⚠️ Please select a project or sub-project.";
    }

    $role = $_POST['project_role'] ?? '';
    if (!$role) {
        $message = "⚠️ Please select a role.";
    }

    // multi-task arrays (from table rows)
    $titles       = $_POST['taskTitle'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $assignTos    = $_POST['assignTo'] ?? [];   // ✅ still only NAME is posted to DB/email
    $priorities   = $_POST['priority'] ?? [];
    $startDates   = $_POST['startDate'] ?? [];  // saved into created_at
    $dueDates     = $_POST['dueDate'] ?? [];

    if (!$message && (empty($titles) || empty($titles[0]))) {
        $message = "⚠️ At least one task title is required.";
    }

    if (!$message) {

        // Save Start Date into created_at
        $insertSql = "INSERT INTO tasks
                        (taskTitle, description, assignTo, priority, created_at, dueDate, project_id, role, sub_project_id, created_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($insertSql);
        if (!$stmt) die("❌ SQL Error: " . $conn->error);

        $success = 0;
        $emailsSent = 0;

        // Prepare email query once
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

            // basic row validation
            if (!$assn || !$prio || !$start || !$due) continue;

            // enforce due >= start (server-side)
            if (strtotime($due) < strtotime($start)) continue;

            $stmt->bind_param(
                "ssssssisii",
                $title,
                $desc,
                $assn,
                $prio,
                $start,            // created_at
                $due,
                $final_project_id,
                $role,
                $sub_project_id,
                $teamLeaderId
            );

            if ($stmt->execute()) {
                $success++;

                // Fetch user email
                $emailQuery->bind_param("s", $assn);
                $emailQuery->execute();
                $emailRes = $emailQuery->get_result();

                if ($emailRow = $emailRes->fetch_assoc()) {

                    $memberEmail = $emailRow['email'];

                    $subject = "New Task Assigned: " . $title;
                    $messageBody = "Hi $assn,\n\n"
                        . "You have been assigned a new task.\n\n"
                        . "📝 Task: {$title}\n"
                        . "📄 Description: {$desc}\n"
                        . "⚙️ Priority: {$prio}\n"
                        . "📅 Start Date: {$start}\n"
                        . "📅 Due Date: {$due}\n\n"
                        . "Please log into your dashboard to begin:\n"
                        . "https://investhoodprojects.com/tidytracker/tidytrack
                        \n\n"
                        . "Best regards,\n"
                        . "Investhood Task Management System\n";

                    $headers = "From: noreply@investhoodprojects.com\r\n";
                    $headers .= "Reply-To: support@investhoodprojects\r\n";
                    $headers .= "X-Mailer: PHP/" . phpversion();

                    if (@mail($memberEmail, $subject, $messageBody, $headers)) {
                        $emailsSent++;
                    }
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

// --- Members (for checkbox dropdown list) ---
// ✅ UPDATED: include department and build "Name - Department" label (still store NAME only)
$members = [];
// $mQ = $conn->query("SELECT user_id, name, $deptSelect AS department
//                     FROM users
//                     WHERE role = 'member'
//                     ORDER BY name");
// while ($row = $mQ->fetch_assoc()) $members[] = $row;

$mQ = $conn->query("SELECT user_id, name, role, $deptSelect AS department
                    FROM users
                    WHERE role IN ('member','leader')
                    ORDER BY (role = 'leader'), name");
while ($row = $mQ->fetch_assoc()) $members[] = $row;

?>
<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<title>Create / Assign Tasks</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

<style>
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
  --input-height:42px;
  --shadow:0 2px 10px rgba(0,0,0,0.06);
  --shadow-soft:0 1px 6px rgba(0,0,0,0.05);
}
[data-theme="dark"]{
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
body{
  margin:0;
  padding:0;
  font-family:"Poppins",sans-serif;
  background: radial-gradient(1200px circle at 10% -10%, rgba(96,165,250,0.08), transparent 40%),
              radial-gradient(1000px circle at 90% 0%, rgba(34,197,94,0.08), transparent 40%),
              var(--bg);
  color:var(--text);
}
main.page-wrap{max-width:900px;margin:40px auto;padding:0 16px;}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;}
.back-btn{
  display:inline-flex;align-items:center;gap:8px;text-decoration:none;
  background:var(--accent);color:#fff;padding:10px 14px;border-radius:10px;font-weight:600;box-shadow:var(--shadow-soft);
}
.back-btn:hover{background:var(--accent-600);}
.theme-toggle{
  display:inline-flex;align-items:center;gap:8px;background:var(--surface);color:var(--text);
  padding:8px 12px;border:1px solid var(--border);border-radius:999px;font-weight:600;cursor:pointer;box-shadow:var(--shadow-soft);
}
.theme-toggle:hover{background:var(--surface-2);}
.theme-toggle .dot{width:18px;height:18px;border-radius:999px;background:linear-gradient(135deg,#fbbf24,#f97316);}
[data-theme="dark"] .theme-toggle .dot{background:linear-gradient(135deg,#60a5fa,#a78bfa);}

.page-title{text-align:center;font-size:clamp(1.6rem,3vw,2rem);font-weight:600;color:var(--accent);margin-bottom:10px;}
.heading-accent{height:4px;width:120px;margin:0 auto 32px;background:linear-gradient(90deg,var(--accent) 0%,#2a9d8f 100%);border-radius:999px;}

.alert{padding:12px;border-radius:8px;margin-bottom:16px;color:#fff;font-weight:600;}
.bg-red-600{background:var(--red);}

form .form-group{display:flex;flex-direction:column;margin-bottom:16px;}
label{font-weight:500;margin-bottom:6px;color:var(--text);}
input,select,textarea{
  border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:.95rem;height:var(--input-height);
  background:var(--surface);color:var(--text);box-shadow:var(--shadow-soft);
}
textarea{resize:vertical;min-height:90px;height:auto;}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(96,165,250,0.25);}

button{
  background:var(--accent);color:#fff;border:none;border-radius:10px;padding:10px 14px;font-weight:600;cursor:pointer;
  transition:background .2s, transform .15s ease;margin-top:8px;box-shadow:var(--shadow-soft);
}
button:hover{background:var(--accent-600);transform:translateY(-1px);}
button.removeRow{background:var(--red);padding:6px 10px;font-size:.85rem;margin:0;}
button.removeRow:hover{background:#b91c1c;}

#taskTable{width:100%;border-collapse:collapse;margin-top:20px;border-radius:12px;overflow:hidden;box-shadow:var(--shadow);background:var(--surface);}
#taskTable thead{background:var(--accent);color:#fff;}
#taskTable thead th{padding:12px 10px;font-weight:600;text-align:left;}
#taskTable tbody td{padding:10px;border-bottom:1px solid var(--border);vertical-align:middle;}
#taskTable tbody tr:nth-child(even){background:var(--surface-2);}
#taskTable tbody tr:hover{background:rgba(96,165,250,0.12);}

/* Multi-select */
.ms-wrap{position:relative;width:100%;}
.ms-btn{
  width:100%;border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:var(--surface);cursor:pointer;
  display:flex;justify-content:space-between;align-items:center;font-family:inherit;box-shadow:var(--shadow-soft);
}
.ms-menu{
  display:none;position:absolute;top:calc(100% + 6px);left:0;width:100%;
  background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:8px;max-height:260px;overflow:auto;
  z-index:99999;box-shadow:var(--shadow);
}
.ms-menu.open{display:block;}
.ms-item{display:flex;align-items:center;gap:10px;padding:8px;border-radius:10px;cursor:pointer;}
.ms-item:hover{background:rgba(96,165,250,0.12);}
.ms-item input{width:auto;}
.ms-empty{padding:10px;color:var(--muted);}

.ms-btn,.ms-btn span,.ms-menu,.ms-item,.ms-item span{color:var(--text)!important;}
.ms-item.selected{background:#eef2ff;border:1px solid #c7d2fe;}
[data-theme="dark"] .ms-item.selected{background:rgba(96,165,250,0.14);border:1px solid rgba(96,165,250,0.35);}

.ms-search{
  width:100%;
  padding:10px 12px;
  border:1px solid var(--border);
  border-radius:10px;
  background:var(--surface);
  color:var(--text);
  outline:none;
  box-sizing:border-box;
}

/* Popup */
.popup-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);justify-content:center;align-items:center;z-index:9999;}
.popup-box{background:var(--surface);padding:25px;width:360px;max-width:90%;border-radius:12px;text-align:center;box-shadow:0 15px 35px rgba(0,0,0,0.35);}
.popup-box h3{margin-bottom:10px;color:var(--accent);}
.popup-box p{color:var(--text);font-size:.95rem;}
.popup-box button{margin-top:18px;padding:10px 26px;border:none;border-radius:8px;background:var(--accent);color:#fff;font-weight:600;cursor:pointer;}
.popup-box button:hover{background:var(--accent-600);}
</style>
</head>

<body>
<main class="page-wrap">

  <div class="topbar">
    <a href="../leader/leader_index.php" class="back-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="width:18px;height:18px">
        <path d="M15 18l-6-6 6-6" />
      </svg>
      Back to Dashboard
    </a>

    <button type="button" class="theme-toggle" id="themeToggle">
      <span class="dot" aria-hidden="true"></span>
      <span id="themeLabel">Dark mode</span>
    </button>
  </div>

  <h1 class="page-title">Create / Assign Task(s)</h1>
  <div class="heading-accent"></div>

  <?php if ($message): ?>
    <div class="alert bg-red-600"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="POST" action="leader_create-task2.php" id="taskForm">

    <!-- MAIN PROJECT -->
    <div class="form-group">
      <label for="project">Main Project</label>
      <select id="project" name="project_id" required>
        <option value="">-- Select Project --</option>
        <?php while ($p = $projectsResult->fetch_assoc()): ?>
          <option value="<?= (int)$p['project_id'] ?>"><?= htmlspecialchars($p['project_name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>

    <div class="form-group" id="subProjectGroup" style="display:none;">
      <label for="subProject">Sub-Project</label>
      <select id="subProject" name="sub_project_id">
        <option value="">-- Select Sub-Project --</option>
      </select>
    </div>

    <!-- ROLE -->
    <div class="form-group">
      <label for="project_role">Role</label>
      <select name="project_role" id="project_role" required>
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
    </div>

    <!-- TASK INPUTS -->
    <div class="form-group">
      <label for="taskTitle">Task Title</label>
      <input type="text" id="taskTitle" placeholder="Enter task title">
    </div>

    <div class="form-group">
      <label for="taskDescription">Description</label>
      <textarea id="taskDescription" placeholder="Enter task description"></textarea>
    </div>

    <!-- Responsible Person(s) -->
    <div class="form-group">
      <label>Responsible Person(s)</label>
      <div class="ms-wrap">
        <button type="button" class="ms-btn" data-ms-btn>
          <span data-ms-text>-- Select Member(s) --</span>
          <span>▾</span>
        </button>

        <div class="ms-menu" data-ms-menu id="assignMenu">
          <div style="padding:8px;">
            <input type="text" class="ms-search" data-ms-search placeholder="Search member...">
          </div>

          <?php if (count($members) > 0): ?>
                             <?php foreach ($members as $m):
              $rawName = (string)($m['name'] ?? '');
              $rawDept = trim((string)($m['department'] ?? ''));
              $rawRole = (string)($m['role'] ?? '');
              $display = $rawDept !== '' ? ($rawName . " - " . $rawDept) : $rawName;
              if ($rawRole === 'leader') $display .= " (Leader)";

              $nameEsc = htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8');
              $dispEsc = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');

              $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $rawName);
              $spanStyle = $rawRole === 'leader' ? " style=\"color:#1d3557;font-weight:700;\"" : "";
            ?>
              <label class="ms-item" for="assign_<?= htmlspecialchars($safeId, ENT_QUOTES, 'UTF-8') ?>">
                <!-- ✅ value = NAME (DB/email), data-label = "Name - Department (Leader)" (UI) -->
                <input
                  id="assign_<?= htmlspecialchars($safeId, ENT_QUOTES, 'UTF-8') ?>"
                  type="checkbox"
                  value="<?= $nameEsc ?>"
                  data-label="<?= $dispEsc ?>"
                  class="assignCheckbox"
                >
                <span<?= $spanStyle ?>><?= $dispEsc ?></span>
              </label>
            <?php endforeach; ?>
            
          <?php else: ?>
            <div class="ms-empty">No members available</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label for="priority">Priority</label>
      <select id="priority">
        <option value="">-- Select Priority --</option>
        <option value="low">Low</option>
        <option value="normal">Normal</option>
        <option value="high">High</option>
        <option value="urgent">Urgent</option>
      </select>
    </div>

    <!-- Start/Deadline -->
    <div class="form-group">
      <label for="startDate">Start Date</label>
      <input type="date" id="startDate">
    </div>

    <div class="form-group">
      <label for="dueDate">Deadline</label>
      <input type="date" id="dueDate">
    </div>

    <div class="form-group">
      <button type="button" id="addTaskBtn">+ Add Task</button>
    </div>

    <!-- TASK TABLE -->
    <table id="taskTable">
      <thead>
        <tr>
          <th>Title</th>
          <th>Description</th>
          <th>Assign To</th>
          <th>Priority</th>
          <th>Start Date</th>
          <th>Deadline</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <!-- SUBMIT -->
    <div class="form-group">
      <button type="submit" class="btn">Create Task(s)</button>
    </div>
  </form>

  <!-- Popup -->
  <div id="popupOverlay" class="popup-overlay">
    <div class="popup-box">
      <h3 id="popupTitle"></h3>
      <p id="popupMessage"></p>
      <button type="button" onclick="closePopup()">OK</button>
    </div>
  </div>

<script>
/* THEME */
(function initTheme(){
  const root = document.documentElement;
  const saved = localStorage.getItem("theme");
  const prefersDark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;

  root.setAttribute("data-theme", saved ? saved : (prefersDark ? "dark" : "light"));
  updateThemeLabel();
})();
function toggleTheme(){
  const root = document.documentElement;
  const current = root.getAttribute("data-theme") || "light";
  const next = current === "dark" ? "light" : "dark";
  root.setAttribute("data-theme", next);
  localStorage.setItem("theme", next);
  updateThemeLabel();
}
function updateThemeLabel(){
  const label = document.getElementById("themeLabel");
  const current = document.documentElement.getAttribute("data-theme");
  if (label) label.textContent = current === "dark" ? "Light mode" : "Dark mode";
}
document.getElementById("themeToggle").addEventListener("click", toggleTheme);

/* MULTI-SELECT + SEARCH (single handler only) */
(function initMultiSelect(){
  const wrap = document.querySelector('.ms-wrap');
  if (!wrap) return;

  const btn = wrap.querySelector('[data-ms-btn]');
  const menu = wrap.querySelector('[data-ms-menu]');
  const text = wrap.querySelector('[data-ms-text]');
  const search = wrap.querySelector('[data-ms-search]');

  function updateText(){
    const items = menu.querySelectorAll('.ms-item');
    const names = [];
    items.forEach(label => {
      const cb = label.querySelector('input[type="checkbox"]');
      if (!cb) return;

      if (cb.checked) {
        label.classList.add('selected');
        // ✅ display "Name - Department" in dropdown button
        names.push(cb.getAttribute('data-label') || cb.value);
      } else {
        label.classList.remove('selected');
      }
    });

    text.textContent = names.length
      ? (names.length <= 2 ? names.join(', ') : `${names.slice(0,2).join(', ')} +${names.length - 2}`)
      : '-- Select Member(s) --';
  }

  btn.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    menu.classList.toggle('open');
    if (menu.classList.contains('open')) setTimeout(() => search && search.focus(), 0);
  });

  menu.addEventListener('click', e => e.stopPropagation());
  document.addEventListener('click', () => menu.classList.remove('open'));

  menu.addEventListener('change', function(e){
    if (e.target && e.target.matches('input[type="checkbox"]')) updateText();
  });

  if (search){
    search.addEventListener('input', function(){
      const q = (search.value || '').toLowerCase().trim();
      const items = menu.querySelectorAll('.ms-item');
      let visible = 0;

      items.forEach(item => {
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
        div.textContent = 'No matches.';
        menu.appendChild(div);
      }
    });
  }

  updateText();
})();

/* TASK TABLE */
function escapeHtml(str){
  return String(str)
    .replaceAll("&","&amp;")
    .replaceAll("<","&lt;")
    .replaceAll(">","&gt;")
    .replaceAll('"',"&quot;")
    .replaceAll("'","&#039;");
}

// ✅ UPDATED: show "Name - Department" but post only NAME
function addTaskToTable(title, desc, assignName, assignLabel, priority, startDate, dueDate) {
  const tableBody = document.querySelector('#taskTable tbody');
  const row = document.createElement('tr');

  row.innerHTML = `
    <td><input type="hidden" name="taskTitle[]" value="${escapeHtml(title)}">${escapeHtml(title)}</td>
    <td><input type="hidden" name="description[]" value="${escapeHtml(desc)}">${escapeHtml(desc)}</td>
    <td>
      <input type="hidden" name="assignTo[]" value="${escapeHtml(assignName)}">
      ${escapeHtml(assignLabel)}
    </td>
    <td><input type="hidden" name="priority[]" value="${escapeHtml(priority)}">${escapeHtml(priority)}</td>
    <td><input type="hidden" name="startDate[]" value="${escapeHtml(startDate)}">${escapeHtml(startDate)}</td>
    <td><input type="hidden" name="dueDate[]" value="${escapeHtml(dueDate)}">${escapeHtml(dueDate)}</td>
    <td><button type="button" class="removeRow">Remove</button></td>
  `;
  tableBody.appendChild(row);
}

document.addEventListener('DOMContentLoaded', function() {
  const addBtn = document.getElementById('addTaskBtn');
  const startEl = document.getElementById('startDate');
  const dueEl   = document.getElementById('dueDate');

  function syncDueMin(){
    if (startEl.value) {
      dueEl.min = startEl.value;
      if (dueEl.value && dueEl.value < startEl.value) dueEl.value = startEl.value;
    } else {
      dueEl.removeAttribute('min');
    }
  }
  startEl.addEventListener('change', syncDueMin);
  syncDueMin();

  addBtn.addEventListener('click', function() {
    const title = document.getElementById('taskTitle').value.trim();
    const desc = document.getElementById('taskDescription').value.trim();
    const priority = document.getElementById('priority').value;
    const startDate = startEl.value;
    const dueDate = dueEl.value;

    // ✅ UPDATED: keep name for DB, label for UI
    const selectedMembers = Array.from(document.querySelectorAll('#assignMenu .assignCheckbox:checked'))
      .map(cb => ({
        name: cb.value,
        label: cb.getAttribute('data-label') || cb.value
      }));

    if (!title || !desc || selectedMembers.length === 0 || !priority || !startDate || !dueDate) {
      alert('Please fill all fields and select at least 1 member (including Start Date).');
      return;
    }
    if (dueDate < startDate) {
      alert('Deadline must be the same or after Start Date.');
      return;
    }

    selectedMembers.forEach(m => addTaskToTable(title, desc, m.name, m.label, priority, startDate, dueDate));

    document.getElementById('taskTitle').value = '';
    document.getElementById('taskDescription').value = '';
    document.getElementById('priority').selectedIndex = 0;
    startEl.value = '';
    dueEl.value = '';
    syncDueMin();

    document.querySelectorAll('#assignMenu .assignCheckbox').forEach(cb => cb.checked = false);

    // refresh dropdown label + selection highlight
    const fakeChange = new Event('change', { bubbles: true });
    document.querySelector('#assignMenu').dispatchEvent(fakeChange);
  });

  document.querySelector('#taskTable tbody').addEventListener('click', function(e) {
    if (e.target.classList.contains('removeRow')) {
      e.target.closest('tr').remove();
    }
  });
});

/* SUB-PROJECT FETCH */
document.addEventListener("DOMContentLoaded", function () {
  const projectEl = document.getElementById('project');
  if (!projectEl) return;

  projectEl.addEventListener('change', function () {
    let projectId = this.value;

    if (!projectId) {
      document.getElementById('subProjectGroup').style.display = "none";
      return;
    }

    fetch('get_sub_projects.php?project_id=' + encodeURIComponent(projectId))
      .then(response => response.json())
      .then(data => {
        let subSelect = document.getElementById('subProject');
        subSelect.innerHTML = '<option value="">-- Select Sub-Project --</option>';

        if (Array.isArray(data) && data.length > 0) {
          data.forEach(sp => {
            subSelect.innerHTML += `<option value="${sp.id}">${sp.sub_project_name}</option>`;
          });
          document.getElementById('subProjectGroup').style.display = "block";
        } else {
          document.getElementById('subProjectGroup').style.display = "none";
        }
      })
      .catch(() => {
        document.getElementById('subProjectGroup').style.display = "none";
      });
  });
});

/* POPUP */
function showPopup(title, message) {
  document.getElementById("popupTitle").innerText = title;
  document.getElementById("popupMessage").innerText = message;
  document.getElementById("popupOverlay").style.display = "flex";
}
function closePopup() {
  document.getElementById("popupOverlay").style.display = "none";
  window.location.href = 'leader_index.php?success=1';
}
</script>

</main>
</body>
</html>
