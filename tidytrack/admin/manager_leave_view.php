<?php
// manager_leave_view.php
session_start();
require_once __DIR__ . '/../dp.php';

// Validate ID
if (!isset($_GET['id'])) {
    echo "<div style='color:red;text-align:center;'>Invalid Request: Missing ID</div>";
    exit;
}

$id = (int) $_GET['id'];

// Fetch leave record
$sql = "
    SELECT lr.*, u.name AS employee_name, u.email AS employee_email
    FROM leave_requests lr
    LEFT JOIN users u ON u.user_id = lr.employee_id
    WHERE lr.id = $id
    LIMIT 1
";
$res = $conn->query($sql);

if (!$res || $res->num_rows === 0) {
    echo "<div style='color:red;text-align:center;'>Leave request not found.</div>";
    exit;
}

$r = $res->fetch_assoc();
?>

<!-- ADMIN-STYLE VIEW CONTENT -->
<div style="font-family: Arial, sans-serif;">

    <!-- Title -->
    <h2 style="margin-top:0; color:#1d3557; font-size:22px;">Leave Request Details</h2>

    <!-- Employee -->
    <div style="margin-bottom:12px;">
        <strong>Employee:</strong><br>
        <span style="display:block; background:#f2f2f2; padding:8px; border-left:4px solid #1d3557;">
            <?= htmlspecialchars($r['employee_name']) ?> (<?= htmlspecialchars($r['employee_email']) ?>)
        </span>
    </div>

    <!-- Leave Type -->
    <div style="margin-bottom:12px;">
        <strong>Leave Type:</strong><br>
        <span style="display:block; background:#f2f2f2; padding:8px; border-left:4px solid #1d3557;">
            <?= htmlspecialchars(str_replace('_',' ',$r['leave_type'])) ?>
        </span>
    </div>

    <!-- Dates -->
    <div style="margin-bottom:12px;">
        <strong>From:</strong>
        <div style="background:#eef6ff; padding:8px; border-left:4px solid #457b9d;">
            <?= htmlspecialchars($r['start_date']) ?>
        </div>
    </div>

    <div style="margin-bottom:12px;">
        <strong>To:</strong>
        <div style="background:#eef6ff; padding:8px; border-left:4px solid #457b9d;">
            <?= htmlspecialchars($r['end_date']) ?>
        </div>
    </div>

    <!-- Reason -->
    <div style="margin-bottom:12px;">
        <strong>Reason:</strong><br>
        <div style="background:#fff; padding:10px; border-left:4px solid #1d3557; min-height:40px;">
            <?= nl2br(htmlspecialchars($r['reason'] ?: 'No reason provided')) ?>
        </div>
    </div>

    <!-- Submitted -->
    <div style="margin-bottom:12px;">
        <strong>Submitted At:</strong><br>
        <span style="display:block; padding:8px; background:#f9f9f9; border-left:4px solid #1d3557;">
            <?= htmlspecialchars($r['created_at']) ?>
        </span>
    </div>

    <!-- Status -->
    <div style="margin-bottom:12px;">
        <strong>Status:</strong><br>
        <span id="leaveStatus" style="display:block; font-weight:bold; padding:8px;
            <?php if ($r['status'] === 'pending') echo 'color:#e94560;';
                  elseif ($r['status'] === 'approved') echo 'color:#28a745;';
                  else echo 'color:#dc3545;'; ?>">
            <?= ucfirst($r['status']) ?>
        </span>
    </div>

    <!-- Manager Comment -->
    <?php if (!empty($r['comment'])): ?>
    <div style="margin-bottom:12px;">
        <strong>Manager Comment:</strong><br>
        <div style="background:#fff3cd; padding:10px; border-left:4px solid #ffcc00;">
            <?= nl2br(htmlspecialchars($r['comment'])) ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Hidden value for scripts -->
<input type="hidden" id="leaveId" value="<?= $id ?>">
