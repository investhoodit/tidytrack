<?php
session_start();
include '../dp.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$teamLeaderId = $_SESSION['user_id'] ?? null;
if (!$teamLeaderId) {
    header("Location: ../login.php");
    exit;
}

/* -----------------------------------------
   GET LEADER NAME
----------------------------------------- */
$stmtLeader = $conn->prepare("SELECT name FROM users WHERE user_id = ?");
$stmtLeader->bind_param("i", $teamLeaderId);
$stmtLeader->execute();
$leaderRow = $stmtLeader->get_result()->fetch_assoc();
$leaderName = $leaderRow['name'] ?? '';

/* -----------------------------------------
   HANDLE APPROVE / REJECT
----------------------------------------- */

// if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//     $task_id   = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
//     $new_status = $_POST['status'] ?? '';
//     $comment    = trim($_POST['comment'] ?? '');

//     // Only allow leader transitions from review
//     if ($task_id && in_array($new_status, ['completed','inprogress'], true)) {
//         $stmt = $conn->prepare("UPDATE tasks SET status = ?, comment = ? WHERE task_id = ?");
//         $stmt->bind_param("ssi", $new_status, $comment, $task_id);
//         $stmt->execute();
//         $stmt->close();
//     }
//   echo "<script>
//           document.addEventListener('DOMContentLoaded', function () {
//             showPopup(
//               'Success',
//               '✅ {$success} task(s) capprove 
//             );
//           });
//         </script>";
//     // Avoid resubmitting on refresh
//     header("Location: leader_index.php");
    
//     exit;
// }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_id    = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $new_status = $_POST['status'] ?? '';
    $comment    = trim($_POST['comment'] ?? '');

    if ($task_id && in_array($new_status, ['completed','inprogress'], true)) {
        $stmt = $conn->prepare("UPDATE tasks SET status = ?, comment = ? WHERE task_id = ?");
        $stmt->bind_param("ssi", $new_status, $comment, $task_id);

        if ($stmt->execute()) {

            $popupTitle = "Success";
            $popupMsg = ($new_status === 'completed')
              ? "✅ Task approved successfully!"
              : "❌ Task rejected and sent back to In Progress.";

            echo "<script>
              document.addEventListener('DOMContentLoaded', function () {
                showPopup(" . json_encode($popupTitle) . ", " . json_encode($popupMsg) . ");
              });
            </script>";

        } else {
            echo "<script>
              document.addEventListener('DOMContentLoaded', function () {
                showPopup('Error', '❌ Failed to update task status.');
              });
            </script>";
        }

        $stmt->close();

    } else {
        echo "<script>
          document.addEventListener('DOMContentLoaded', function () {
            showPopup('Warning', '⚠️ Invalid request.');
          });
        </script>";
    }
}


/* -----------------------------------------
   FETCH TASKS WAITING FOR APPROVAL
   (ONLY TASKS CREATED BY LOGGED-IN LEADER)
----------------------------------------- */
$sql = "
    SELECT 
        t.task_id,
        t.taskTitle,
        t.assignTo,
        t.created_at,
        t.code_snippet,
        t.file_upload,
        p.project_name
    FROM tasks t
    LEFT JOIN projects_x p ON t.project_id = p.project_id
    WHERE t.status = 'In review'
      AND t.created_by = ?
    ORDER BY t.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $teamLeaderId); 
$stmt->execute();
$result = $stmt->get_result();
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">

<style>
  :root{
    --bg: #f6f7fb;
    --card: #ffffff;
    --border: #e8e8ee;
    --muted: #6b7280;
    --text: #0f172a;

    --blue: #2563eb;
    --blue-600: #1d4ed8;
    --green: #16a34a;
    --red: #dc2626;
  }

  body{
    background: var(--bg);
    font-family: 'Poppins', sans-serif;
    color: var(--text);
  }

  /* Page header (matches dashboard vibe) */
  .page-title{
    font-weight: 800;
    letter-spacing: -0.02em;
  }

  .subtle{
    color: var(--muted);
    font-size: 12px;
  }

  /* Card container (like dashboard cards) */
  .table-container{
    margin-top: 18px;
    background: var(--card);
    padding: 18px;
    border-radius: 16px;
    border: 1px solid var(--border);
    box-shadow: 0 14px 40px rgba(15, 23, 42, 0.06);
  }

  /* Table style (Jira/ClickUp-ish) */
  table{
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    overflow: hidden;
    border-radius: 14px;
  }

  thead th{
    background: #f8fafc;
    color: #475569;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
  }

  tbody td{
    padding: 14px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
    font-size: 14px;
  }

  tbody tr:hover{
    background: #f8fbff;
  }

  /* icon buttons */
  .icon-btn{
    font-size: 18px;
    cursor: pointer;
    margin-right: 8px;
    color: #64748b;
    transition: 0.15s ease;
  }
  .icon-btn:hover{ color: var(--blue); transform: translateY(-1px); }

  /* comment input */
  .comment-input{
    width: 100%;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    background: #fff;
    outline: none;
    transition: 0.15s ease;
    font-size: 13px;
  }
  .comment-input:focus{
    border-color: rgba(37, 99, 235, .5);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, .12);
  }

  /* Approve / Reject buttons (same style as dashboard) */
  .btn-action{
    padding: 10px 14px;
    border-radius: 12px;
    border: 1px solid transparent;
    cursor: pointer;
    font-weight: 700;
    color: #fff;
    box-shadow: 0 8px 20px rgba(0,0,0,.08);
    transition: 0.15s ease;
  }
  .btn-action:hover{ transform: translateY(-1px); }
  .btn-approve{ background: var(--green); }
  .btn-reject{ background: var(--red); }

  /* snippet box */
  .snippet-box{
    background: #0b1220;
    color: #d1fae5;
    padding: 12px;
    border-radius: 12px;
    margin-top: 10px;
    display: none;
    white-space: pre-wrap;
    border: 1px solid rgba(255,255,255,.10);
  }
  .snippet-box pre{
    margin: 0;
    font-size: 12px;
    line-height: 1.5;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  }

  /* Popup (match dashboard modal style) */
  .popup-overlay{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(2,6,23,0.6);
    justify-content:center;
    align-items:center;
    z-index:999999;
    backdrop-filter: blur(6px);
  }
  .popup-box{
    background:#fff;
    padding:22px;
    width:380px;
    max-width:90%;
    border-radius:16px;
    text-align:center;
    box-shadow: 0 30px 80px rgba(0,0,0,.35);
    border: 1px solid #e5e7eb;
  }
  .popup-box h3{
    margin-bottom:8px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: #0f172a;
  }
  .popup-box p{
    color:#64748b;
    font-size: 14px;
    margin: 0;
  }
  .popup-box button{
    margin-top:16px;
    padding:10px 26px;
    border:none;
    border-radius:12px;
    background: var(--blue);
    color:white;
    font-weight:800;
    cursor:pointer;
    transition: 0.15s ease;
  }
  .popup-box button:hover{ background: var(--blue-600); }

  /* ===== Dark mode: works if leader_index adds body.dark ===== */
  body.dark{
    --bg: #0b1220;
    --card: #0f172a;
    --border: rgba(255,255,255,.10);
    --muted: #94a3b8;
    --text: #e5e7eb;
  }
  body.dark .table-container{
    box-shadow: none;
  }
  body.dark thead th{
    background: rgba(255,255,255,.04);
    color: #cbd5e1;
    border-bottom: 1px solid rgba(255,255,255,.10);
  }
  body.dark tbody td{
    border-bottom: 1px solid rgba(255,255,255,.06);
  }
  body.dark tbody tr:hover{
    background: rgba(59,130,246,.08);
  }
  body.dark .comment-input{
    background: #0b1220;
    border-color: rgba(255,255,255,.12);
    color: #e5e7eb;
  }
  body.dark .popup-box{
    background: #0f172a;
    border-color: rgba(255,255,255,.10);
  }
  body.dark .popup-box h3{ color: #e5e7eb; }
  body.dark .popup-box p{ color: #94a3b8; }
</style>


<main class="p-4">
    <h1 class="text-2xl font-bold text-center mb-4">Approve Completed Tasks</h1>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Task Title</th>
                    <th>Files / Snippet</th>
                    <th>Completed By</th>
                    <th>Date</th>
                    <th>Comment</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['project_name'] ?? 'N/A') ?></td>

                    <td><strong><?= htmlspecialchars($row['taskTitle']) ?></strong></td>

                    <td>
                        <?php if (!empty($row['code_snippet'])): ?>
                            <i class="fas fa-code icon-btn" onclick="toggleSnippet(<?= $row['task_id'] ?>)"></i>

                            <div id="snippet-<?= $row['task_id'] ?>" class="snippet-box">
                                <pre><code><?= htmlspecialchars($row['code_snippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></pre>
                            </div>
                        <?php else: ?>
                            <span style="font-size:12px;color:#aaa;">No snippet</span>
                        <?php endif; ?>

                        <?php if (!empty($row['file_upload'])): ?>
                            <?php $filePath = "../member/Uploads/screenshots/" . basename($row['file_upload']); ?>
                            <a href="<?= $filePath ?>" target="_blank">
                                <i class="fas fa-file icon-btn"></i>
                            </a>
                        <?php endif; ?>
                    </td>

                    <td><?= htmlspecialchars($row['assignTo']) ?></td>

                    <td><?= date("M d, Y", strtotime($row['created_at'])) ?></td>

                    <td><input type="text" class="comment-input" id="comment-<?= $row['task_id'] ?>"></td>

                    <td class="py-2 px-4 text-center">

                        <form method="POST" action="leader_approve-tasks.php" style="display:inline;">
                            <input type="hidden" name="task_id" value="<?= (int)$row['task_id'] ?>">
                            <input type="hidden" name="status" value="completed">
                            <input type="hidden" name="comment">
                            <button type="submit" class="px-4 py-2 rounded-lg bg-green-600 text-white font-semibold shadow-sm">Approve</button>
                        </form>

                        <form method="POST" action="leader_approve-tasks.php" style="display:inline;">
                            <input type="hidden" name="task_id" value="<?= (int)$row['task_id'] ?>">
                            <input type="hidden" name="status" value="inprogress">
                            <input type="hidden" name="comment">
                            <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white font-semibold shadow-sm ml-2">Reject</button>
                        </form>

                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Popup Modal -->
<div id="popupOverlay" class="popup-overlay">
  <div class="popup-box">
    <h3 id="popupTitle"></h3>
    <p id="popupMessage"></p>
    <button type="button" onclick="closePopup()">OK</button>
  </div>
</div>
    
</main>

<script>
function toggleSnippet(id) {
    let div = document.getElementById("snippet-" + id);
    if (div) div.style.display = div.style.display === "block" ? "none" : "block";
}


function showPopup(title, message) {
  document.getElementById("popupTitle").innerText = title;
  document.getElementById("popupMessage").innerText = message;
  document.getElementById("popupOverlay").style.display = "flex";
}

function closePopup() {
  document.getElementById("popupOverlay").style.display = "none";
  window.location.href = 'leader_index.php?success=1';
}




document.querySelectorAll("form[action='leader_approve-tasks.php']").forEach(form => {
  form.addEventListener("submit", function () {
    const taskId = form.querySelector("input[name='task_id']").value;
    const commentValue = document.getElementById("comment-" + taskId).value;

    const hiddenComment = form.querySelector("input[name='comment']");
    if (hiddenComment) hiddenComment.value = commentValue;
  });
});


</script>
