<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include '../dp.php';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* =========================================================
   MAIL CONFIG (IMPORTANT)
   =========================================================
   When you moved to Investhood Projects, the server may BLOCK
   emails if the From domain doesn't match the hosting domain.

   ✅ Change these 3 values to match the NEW hosting domain.
========================================================= */
$MAIL_FROM  = "noreply@investhoodProjects.com";     // e.g. noreply@investhoodprojects.com
$MAIL_REPLY = "noreply@investhoodprojects.com";     // e.g. support@investhoodprojects.com
$APP_URL    = "noreply@investhoodprojects.com/Investhood_TSM/tidytrack/"; // your new site URL

// Team Leader ID (created_by)
$teamLeaderId = (int)($_SESSION['user_id'] ?? 0);
if ($teamLeaderId <= 0) {
  die("❌ User not logged in. Team Leader ID not found in session.");
}

// Projects
$sqlProjects = "SELECT project_id, project_name FROM projects_x";
$projectsResult = $conn->query($sqlProjects);

/* -----------------------------
   ✅ Detect department column (department OR deparment)
------------------------------ */
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

/* =========================================================
   MAIL HELPER (with proper headers + envelope sender)
========================================================= */
function sendTaskMail($to, $subject, $body, $fromEmail, $replyToEmail){
  // Build safe headers (UTF-8)
  $headers = [];
  $headers[] = "From: Investhood TMS <{$fromEmail}>";
  $headers[] = "Reply-To: {$replyToEmail}";
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/plain; charset=UTF-8";
  $headers[] = "X-Mailer: PHP/" . phpversion();
  $headersStr = implode("\r\n", $headers);

  // IMPORTANT: envelope sender -f helps deliverability & avoids spoof blocks
  $ok = mail($to, $subject, $body, $headersStr, "-f {$fromEmail}");

  if (!$ok) {
    $err = error_get_last();
    error_log("MAIL FAILED => to={$to} | subject={$subject} | err=" . ($err['message'] ?? 'unknown'));
  }
  return $ok;
}

/* =========================================================
   SUBMIT
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $taskTitle   = trim($_POST['taskTitle'] ?? '');
  $role        = trim($_POST['project_role'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $priority    = trim($_POST['priority'] ?? '');
  $startDate   = trim($_POST['startDate'] ?? '');   // saved into created_at
  $dueDate     = trim($_POST['dueDate'] ?? '');
  $project_id  = (int)($_POST['project_id'] ?? 0);

  // Multi assignees
  $assignedUsers = $_POST['assignTo'] ?? []; // array

  if (
      $taskTitle && $role && $description && $priority &&
      $startDate && $dueDate && $project_id > 0 &&
      is_array($assignedUsers) && count($assignedUsers) > 0
  ) {

    // Insert task
    $stmt = $conn->prepare("
      INSERT INTO tasks (taskTitle, description, assignTo, created_by, priority, created_at, dueDate, project_id, role)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
      $popupMsg = "❌ Prepare failed: " . $conn->error;
      echo "<script>
        document.addEventListener('DOMContentLoaded', function () {
          showPopup('Error', " . json_encode($popupMsg) . ");
        });
      </script>";
    } else {

      // Email lookup by member name
      $emailQuery = $conn->prepare("SELECT email FROM users WHERE name = ? LIMIT 1");
      if (!$emailQuery) {
        $popupMsg = "❌ Email prepare failed: " . $conn->error;
        echo "<script>
          document.addEventListener('DOMContentLoaded', function () {
            showPopup('Error', " . json_encode($popupMsg) . ");
          });
        </script>";
      } else {

        $successCount   = 0;
        $emailFailCount = 0;

        foreach ($assignedUsers as $assignToRaw) {
          $assignTo = trim($assignToRaw);
          if ($assignTo === "") continue;

          // bind_param types: s s s i s s s i s
          $stmt->bind_param(
            "sssisssis",
            $taskTitle,
            $description,
            $assignTo,
            $teamLeaderId,
            $priority,
            $startDate,
            $dueDate,
            $project_id,
            $role
          );

          if ($stmt->execute()) {
            $successCount++;

            // Find member email
            $emailQuery->bind_param("s", $assignTo);
            $emailQuery->execute();
            $emailResult = $emailQuery->get_result();

            if ($emailRow = $emailResult->fetch_assoc()) {
              $memberEmail = trim((string)($emailRow['email'] ?? ''));

              if ($memberEmail !== '') {
                $subject = "New Task Assigned: " . $taskTitle;

                $messageBody =
                  "Hi {$assignTo},\n\n" .
                  "You have been assigned a new task by your Team Leader.\n\n" .
                  "📝 Task: {$taskTitle}\n" .
                  "📄 Description: {$description}\n" .
                  "⚙️ Priority: {$priority}\n" .
                  "📅 Start Date: {$startDate}\n" .
                  "📅 Due Date: {$dueDate}\n\n" .
                  "Please log in to the system to view and start your task:\n" .
                  "{$APP_URL}\n\n" .
                  "Best regards,\n" .
                  "Investhood Task Management System\n";

                // ✅ send with proper headers + envelope sender
                $sent = sendTaskMail($memberEmail, $subject, $messageBody, $MAIL_FROM, $MAIL_REPLY);
                if (!$sent) $emailFailCount++;
              } else {
                $emailFailCount++;
                error_log("MAIL FAILED => empty email for user '{$assignTo}'");
              }
            } else {
              $emailFailCount++;
              error_log("MAIL FAILED => no email row for user '{$assignTo}'");
            }
          }
        }

        $stmt->close();
        $emailQuery->close();

        if ($successCount > 0) {
          $popupMsg = "✅ Task created for {$successCount} member(s).";
          if ($emailFailCount > 0) {
            $popupMsg .= " ⚠️ {$emailFailCount} email(s) failed. (Check server error log)";
          }

          echo "<script>
            document.addEventListener('DOMContentLoaded', function () {
              showPopup('Success', " . json_encode($popupMsg) . ");
            });
          </script>";
        } else {
          echo "<script>
            document.addEventListener('DOMContentLoaded', function () {
              showPopup('Error', '❌ Failed to create tasks.');
            });
          </script>";
        }

      }
    }

  } else {
    echo "<script>
      document.addEventListener('DOMContentLoaded', function () {
        showPopup('Warning', '⚠️ All fields are required (including Start Date + at least 1 member).');
      });
    </script>";
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Task - INVESTHOOD_TSM</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

<style>
:root{
  --bg:#f6f8fb;
  --text:#1f2937;
  --muted:#6b7280;
  --accent:#1d3557;
  --accent-600:#162a44;
  --border:#e5e7eb;
  --green:#16a34a;
  --red:#dc2626;
}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:"Poppins",system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
main{max-width:900px;margin:40px auto;padding:0 16px;}

.page-title{text-align:center;font-size:clamp(1.8rem,3vw,1.4rem);font-weight:600;color:var(--accent);margin-bottom:10px;}
.heading-accent{height:4px;width:120px;margin:0 auto 32px;background:linear-gradient(90deg,var(--accent) 0%,#2a9d8f 100%);border-radius:999px;}

form .form-group{margin-bottom:16px;}
label{display:block;font-weight:700;margin-bottom:6px;}
input,select,textarea{width:100%;border:1px solid var(--border);border-radius:8px;padding:10px 12px;}
textarea{resize:vertical;min-height:100px;}
input:focus,select:focus,textarea:focus{outline:2px solid var(--accent);}

.btn{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer;}
.btn:hover{background:var(--accent-600);}

.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:700px){.grid-2{grid-template-columns:1fr;}}

.muted{color:var(--muted);font-weight:600;font-size:.85rem;}

/* Multi-select */
.ms-wrap{position:relative;width:100%;}
.ms-btn{
  width:100%;
  border:1px solid var(--border);
  border-radius:8px;
  padding:10px 12px;
  background:#fff;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:space-between;
  font-family:inherit;
}
.ms-btn:focus{outline:2px solid var(--accent);}
.ms-menu{
  display:none;
  position:absolute;
  top:calc(100% + 6px);
  left:0;
  width:100%;
  background:#fff;
  border:1px solid var(--border);
  border-radius:10px;
  padding:8px;
  max-height:240px;
  overflow:auto;
  z-index:99999;
  box-shadow:0 10px 30px rgba(0,0,0,.12);
}
.ms-menu.open{display:block;}
.ms-item{display:flex;align-items:center;gap:10px;padding:8px;border-radius:8px;cursor:pointer;}
.ms-item:hover{background:#f1f5f9;}
.ms-item input{width:auto;}
.ms-empty{padding:10px;color:var(--muted);}

.ms-search{
  position:sticky;
  top:0;
  z-index:2;
  background:#fff;
  padding:6px 6px 10px;
  border-bottom:1px solid var(--border);
  border-radius:10px;
}
.ms-search .ms-search-wrap{position:relative;}
.ms-search i{
  position:absolute;
  left:12px;
  top:50%;
  transform:translateY(-50%);
  color:#94a3b8;
  font-size:14px;
}
.ms-search input{
  width:100%;
  padding:10px 12px 10px 34px;
  border:1px solid var(--border);
  border-radius:10px;
  background:#fff;
}

.ms-btn,.ms-btn span,.ms-menu,.ms-item,.ms-item span{color: var(--text) !important;}
.ms-item.selected{background:#eef2ff;border:1px solid #c7d2fe;}
.ms-item.selected span{font-weight:700;color: var(--accent) !important;}

/* Popup */
.popup-overlay{
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.6);
  justify-content:center;
  align-items:center;
  z-index:999999;
}
.popup-box{
  background:#fff;
  padding:25px;
  width:360px;
  max-width:90%;
  border-radius:12px;
  text-align:center;
  box-shadow:0 15px 35px rgba(0,0,0,0.35);
}
.popup-box h3{margin-bottom:10px;color:var(--accent);}
.popup-box p{color:var(--text);font-size:0.95rem;}
.popup-box button{
  margin-top:18px;
  padding:10px 26px;
  border:none;
  border-radius:8px;
  background:var(--accent);
  color:#fff;
  font-weight:600;
  cursor:pointer;
}
.popup-box button:hover{background:var(--accent-600);}
</style>
</head>

<body>

<main class="page-wrap">
  <h1 class="page-title">Create / Assign Task</h1>
  <div class="heading-accent"></div>

  <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="task-form">

    <div class="form-group">
      <label for="project">Project</label>
      <select id="project" name="project_id" required>
        <option value="">-- Select Project --</option>
        <?php
          if ($projectsResult && $projectsResult->num_rows > 0) {
            while ($row = $projectsResult->fetch_assoc()) {
              echo "<option value='".esc($row['project_id'])."'>".esc($row['project_name'])."</option>";
            }
          } else {
            echo "<option disabled>No projects found</option>";
          }
        ?>
      </select>
    </div>

    <div class="form-group">
      <label for="project_role">Role</label>
      <select id="project_role" name="project_role" required>
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

    <div class="form-group">
      <label for="taskTitle">Task Title</label>
      <input type="text" id="taskTitle" name="taskTitle" required>
    </div>

    <div class="form-group">
      <label for="description">Description <span class="muted">(be as specific as possible)</span></label>
      <textarea id="description" name="description" rows="4" required></textarea>
    </div>

    <div class="grid-2">

      <div class="form-group">
        <label>Responsible Person(s)</label>

        <div class="ms-wrap">
          <button type="button" class="ms-btn" data-ms-btn>
            <span data-ms-text>-- Select Member(s) --</span>
            <span>▾</span>
          </button>

          <div class="ms-menu" data-ms-menu>

            <div class="ms-search">
              <div class="ms-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" placeholder="Search members..." data-ms-search>
              </div>
            </div>

            <?php
              $usersQuery = "SELECT name, $deptSelect AS department
                             FROM users
                             WHERE role = 'member'
                             ORDER BY name";
              $usersResult = $conn->query($usersQuery);

              if ($usersResult && $usersResult->num_rows > 0) {
                while ($u = $usersResult->fetch_assoc()) {
                  $rawName = (string)($u['name'] ?? '');
                  $rawDept = trim((string)($u['department'] ?? ''));

                  $displayLabel = $rawDept !== '' ? ($rawName . " - " . $rawDept) : $rawName;
                  $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $rawName);

                  echo "
                    <label class='ms-item' for='assign_".esc($safeId)."' data-ms-item>
                      <input
                        id='assign_".esc($safeId)."'
                        type='checkbox'
                        name='assignTo[]'
                        value='".esc($rawName)."'
                        data-label='".esc($displayLabel)."'
                      >
                      <span>".esc($displayLabel)."</span>
                    </label>
                  ";
                }
              } else {
                echo "<div class='ms-empty'>No members available</div>";
              }
            ?>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label for="priority">Priority</label>
        <select id="priority" name="priority" required>
          <option value="">Select priority</option>
          <option value="low">Low</option>
          <option value="normal">Normal</option>
          <option value="high">High</option>
          <option value="urgent">Critical</option>
        </select>
      </div>

    </div>

    <div class="form-group">
      <label for="startDate">Start Date</label>
      <input type="date" id="startDate" name="startDate" required min="<?= date('Y-m-d') ?>">
    </div>

    <div class="form-group">
      <label for="dueDate">Deadline</label>
      <input type="date" id="dueDate" name="dueDate" required min="<?= date('Y-m-d') ?>">
      <div class="muted" style="margin-top:6px;">Tip: Due date should be the same or after Start date.</div>
    </div>

    <div class="actions">
      <button type="submit" class="btn">
        <i class="fa-solid fa-paper-plane"></i> Create & Assign Task
      </button>
    </div>
  </form>
</main>

<div id="popupOverlay" class="popup-overlay">
  <div class="popup-box">
    <h3 id="popupTitle"></h3>
    <p id="popupMessage"></p>
    <button type="button" onclick="closePopup()">OK</button>
  </div>
</div>

<script>
function showPopup(title, message) {
  document.getElementById("popupTitle").innerText = title;
  document.getElementById("popupMessage").innerText = message;
  document.getElementById("popupOverlay").style.display = "flex";
}
function closePopup() {
  document.getElementById("popupOverlay").style.display = "none";
  window.location.href = 'manager_index.php?success=1';
}

/* Multi-select dropdown + Search */
(function initResponsiblePersonsMultiSelect(){
  function run(){
    const wrap = document.querySelector('.ms-wrap');
    if (!wrap) return;

    const btn    = wrap.querySelector('[data-ms-btn]');
    const menu   = wrap.querySelector('[data-ms-menu]');
    const text   = wrap.querySelector('[data-ms-text]');
    const search = wrap.querySelector('[data-ms-search]');
    if (!btn || !menu || !text) return;

    function updateText(){
      const items = menu.querySelectorAll('.ms-item');
      const names = [];

      items.forEach(label => {
        const cb = label.querySelector('input[type="checkbox"]');
        if (!cb) return;

        if (cb.checked) {
          label.classList.add('selected');
          const display = cb.getAttribute('data-label') || cb.value;
          names.push(display);
        } else {
          label.classList.remove('selected');
        }
      });

      text.textContent = names.length
        ? (names.length <= 2 ? names.join(', ') : `${names.slice(0,2).join(', ')} +${names.length - 2}`)
        : '-- Select Member(s) --';
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      menu.classList.toggle('open');
      if (menu.classList.contains('open')) setTimeout(() => search?.focus(), 0);
    });

    menu.addEventListener('click', e => e.stopPropagation());
    document.addEventListener('click', function () { menu.classList.remove('open'); });

    menu.addEventListener('change', updateText);

    if (search) {
      search.addEventListener('input', function(){
        const q = (search.value || '').toLowerCase().trim();
        const items = menu.querySelectorAll('.ms-item');

        let visible = 0;
        items.forEach(item => {
          const name = (item.querySelector('span')?.textContent || item.textContent || '').toLowerCase();
          const show = name.includes(q);
          item.style.display = show ? 'flex' : 'none';
          if (show) visible++;
        });

        const old = menu.querySelector('.ms-empty-search');
        if (old) old.remove();

        if (q && visible === 0) {
          const div = document.createElement('div');
          div.className = 'ms-empty ms-empty-search';
          div.textContent = 'No matches.';
          menu.appendChild(div);
        }
      });
    }

    updateText();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();

/* Enforce dueDate >= startDate */
document.addEventListener('DOMContentLoaded', function () {
  const start = document.getElementById('startDate');
  const due = document.getElementById('dueDate');
  if(!start || !due) return;

  function syncMinDue(){
    if(start.value){
      due.min = start.value;
      if(due.value && due.value < start.value){
        due.value = start.value;
      }
    } else {
      due.min = "<?= date('Y-m-d') ?>";
    }
  }
  start.addEventListener('change', syncMinDue);
  syncMinDue();
});
</script>

</body>
</html>
