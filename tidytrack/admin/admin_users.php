<?php
session_start();
require_once __DIR__ . '/../dp.php';
// Include track2.php to use the attendance constants for display purposes
require_once __DIR__ . '/../track2.php';

// --- AUTH GUARD ---
$userId = $_SESSION['user_id'] ?? null;
$role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed = ['admin'];
if (!$userId || !in_array($role, $allowed, true)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// --- HANDLE USER DELETION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    $userToDeleteId = (int)$_POST['delete_user_id'];

    // 1. Fetch user to check role and get profile picture path
    $check = $conn->prepare("SELECT role, profile_picture FROM users WHERE user_id = ?");
    $check->bind_param("i", $userToDeleteId);
    $check->execute();
    $result = $check->get_result();
    $userData = $result->fetch_assoc();
    $check->close();

    if (!$userData) {
        $_SESSION['error'] = "User not found.";
        header("Location: admin_users.php");
        exit;
    }

    if (strtolower($userData['role']) === 'admin') {
        $_SESSION['error'] = "Cannot delete admin accounts.";
        header("Location: admin_users.php");
        exit;
    }

   // 2. Start Transaction
        $conn->begin_transaction();

        try {
            // Define which tables use which column names
            $tables = [
                // Table Name => Column Name
                'password_reset_requests' => 'user_id',
                'user_sessions'           => 'user_id',
                'attendance_log'          => 'user_id',
                'notifications'           => 'user_id',
                'tasks'                   => 'user_id',
                'daily_tasks'             => 'user_id',
                'employee_ratings'        => 'user_id',
                'group_members'           => 'user_id',
                'leave_requests'          => 'user_id',
                'group_messages'          => 'user_id',
                'meeting_attendance'      => 'user_id',
                'reminders'               => 'user_id',
                'reminder_tasks'          => 'user_id',
                'team_members'            => 'user_id',
                'timesheet'               => 'user_id',
                'tsm_feedback'            => 'user_id',
                'user_activity'           => 'user_id',
                'weekly_reports'          => 'user_id',
                'meeting_chat'            => 'user_id',
                'meeting_participants'    => 'user_id',
                'meeting_reactions'       => 'user_id',
                'meeting_signals'         => 'user_id',
                
                // TABLES THAT  HAVE DIFFERENT COLUMN NAMES:
                'projects_x'              => 'created_by', 
                'project_leader'          => 'leader_id',
                'project_leaders'         => 'leader_id',
                'sub_projects'            => 'created_by',
                'groups'                  => 'created_by',
                'meeting_rooms'           => 'created_by',
                'meeting_settings'        => 'user_id' 
            ];

            foreach ($tables as $tableName => $columnName) {
                // 1. Check if table exists
                $val = $conn->query("SHOW TABLES LIKE '$tableName'");
                if($val->num_rows > 0) {
                    
                    // 2. Check if the column exists in that table to prevent the "Unknown column" error
                    $colCheck = $conn->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
                    if($colCheck->num_rows > 0) {
                        $del = $conn->prepare("DELETE FROM `$tableName` WHERE `$columnName` = ?");
                        $del->bind_param("i", $userToDeleteId);
                        $del->execute();
                        $del->close();
                    }
                }
            }

            // 3. Delete the profile picture
            if (!empty($userData['profile_picture']) && file_exists(__DIR__ . '/../' . $userData['profile_picture'])) {
                unlink(__DIR__ . '/../' . $userData['profile_picture']);
            }

            // 4. Finally, delete the user
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $userToDeleteId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $_SESSION['message'] = "User and all related records deleted successfully.";

        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Critical Error: " . $e->getMessage();
        }

    header("Location: admin_users.php");
    exit;
}

// --- FETCH USERS AND PENDING RESET REQUESTS ---
$stmt = $conn->prepare("
    SELECT u.user_id, u.name, u.email, u.role, u.status, u.profile_picture,
           r.request_id, r.reason, r.other_reason
    FROM users u
    LEFT JOIN password_reset_requests r 
        ON u.user_id = r.user_id AND r.status = 'pending'
    ORDER BY u.role DESC, u.name ASC
");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- GROUP USERS ---
$groupedUsers = [
    'admin' => [],
    'manager' => [],
    'leader' => [],
    'member' => []
];
foreach ($users as $user) {
    $roleKey = strtolower($user['role']);
    if (isset($groupedUsers[$roleKey])) {
        $groupedUsers[$roleKey][] = $user;
    }
}
?>


<!DOCTYPE html>
<html lang="en-ZA">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin • Manage Users</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <style>
      :root {
    --bg: #f6f8fb;
    --card: #ffffff;
    --ink: #1d3557;
    --muted: #6b7b93;
    --accent: #2a9d8f;
    --line: #e9eef5;
    --red: #e74c3c;
    --green: #2ecc71;
}

/* DARK MODE */
.dark {
    --bg: #0b1220;
    --card: #0f172a;
    --ink: #e5e7eb;
    --muted: #94a3b8;
    --accent: #2dd4bf;
    --line: rgba(255,255,255,0.1);
    --red: #f87171;
    --green: #34d399;
}
        * {box-sizing: border-box;}
        body {margin: 0; background: var(--bg); font-family: Poppins, sans-serif; color: var(--ink);}
        header {position: sticky; top: 0; z-index: 10; background: #fff; display: flex; align-items: center; justify-content: space-between; padding: 1rem; border-bottom: 1px solid var(--line);}
        .wrap {max-width: 1200px; margin: 1rem auto; padding: 0 1rem;}
        .btn {border: 1px solid var(--line); background: #fff; border-radius: 10px; padding: .5rem .8rem; color: var(--ink); text-decoration: none; display: inline-flex; gap: .5rem; align-items: center;}
        .user-list {display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; margin-top: 1rem;}
        .user-card {background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 1rem; display: flex; align-items: center; gap: 1rem; transition: transform 0.2s ease; cursor: pointer; position: relative;}
        .user-card:hover {transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.05);}
        .user-card .reset-badge {position: absolute; top: 8px; right: 8px; background: var(--red); color: #fff; padding: 4px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: 600;}
        .user-avatar {width: 60px; height: 60px; border-radius: 50%; background-color: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 600; flex-shrink: 0;}
        .user-avatar img {width: 100%; height: 100%; object-fit: cover; border-radius: 50%;}
        .user-info {flex-grow: 1;}
        .user-info h5 {margin: 0; font-size: 1.1rem;}
        .user-info p {margin: 0; font-size: 0.9rem; color: var(--muted);}
        .user-info .role {font-weight: 600; text-transform: capitalize;}
        .role-section {margin-top: 2rem;}
        .role-section h3 {margin-bottom: 1rem;}
        
        /* Modal Styles */
        .modal {display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); justify-content: center; align-items: center;}
        .modal-content {background-color: var(--card); padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); position: relative;}
        #attendanceModal .modal-content {max-width: 800px; min-height: 550px;}
        .close-btn {color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; position: absolute; top: 10px; right: 20px;}
        .close-btn:hover, .close-btn:focus {color: #000; text-decoration: none; cursor: pointer;}
        .modal-user-details {text-align: center;}
        .modal-user-details .avatar {width: 120px; height: 120px; font-size: 3rem; margin: 0 auto 1rem;}
        .modal-user-details h4 {margin: 0; font-size: 1.5rem;}
        .modal-user-details p {margin: 0.2rem 0; color: var(--muted);}
        .reset-request {margin-top: 1rem; padding: 1rem; background: #fcebeb; border-radius: 8px;}
        .reset-request p {margin: 0.5rem 0;}
        .action-buttons {display: flex; gap: 1rem; margin-top: 1rem; justify-content: center; flex-wrap: wrap;}
        .action-btn {padding: 0.75rem 1.5rem; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: background-color 0.2s;}
        .accept-btn {background-color: var(--accent); color: #fff;}
        .accept-btn:hover {background-color: #21867a;}
        .reject-btn {background-color: var(--red); color: #fff;}
        .reject-btn:hover {background-color: #c0392b;}
        .attendance-btn {background-color: #f7a530; color: #fff;}
        .attendance-btn:hover {background-color: #e0962d;}
        .delete-form {margin-top: 1.5rem;}
        .delete-btn {background-color: var(--red); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer;}
        .delete-btn:hover {background-color: #c0392b;}
        .message-box {padding: 1rem; border-radius: 8px; margin-bottom: 1rem; font-weight: 600; position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 1001; width: 90%; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);}
        .message-box.success {background-color: #e6f3e6; color: var(--green); border-left: 5px solid var(--green);}
        .message-box.error {background-color: #fcebeb; color: var(--red); border-left: 5px solid var(--red);}
        
        /* Attendance Modal Specific Styles */
        .tab-buttons {display: flex; border-bottom: 2px solid var(--line); margin-bottom: 1.5rem;}
        .tab-btn {flex-grow: 1; padding: 0.75rem 0; background: none; border: none; cursor: pointer; font-weight: 600; color: var(--muted); border-bottom: 3px solid transparent; transition: all 0.2s;}
        .tab-btn.active {color: var(--ink); border-bottom-color: var(--accent);}
        .tab-content {padding-top: 0.5rem; max-height: 300px; overflow-y: auto;}

        /* Daily Status Card */
        .daily-status-card {background-color: var(--bg); padding: 1.5rem; border-radius: 10px; text-align: center; margin-bottom: 1.5rem; border: 1px solid var(--line);}
        .daily-status-card h5 {margin: 0 0 0.5rem 0; color: var(--muted); font-size: 1rem;}
        .daily-status-card strong {font-size: 2rem; display: block; margin-bottom: 0.5rem;}
        .daily-status-card .status {font-weight: 600; font-size: 1.1rem;}
        .status.present {color: var(--green);}
        .status.absent {color: var(--red);}
        
        /* Attendance Table */
        .attendance-table {width: 100%; border-collapse: collapse; font-size: 0.9rem;}
        .attendance-table th, .attendance-table td {padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--line);}
        .attendance-table th {background-color: var(--bg); font-weight: 600; color: var(--ink); position: sticky; top: 0; z-index: 5;} /* Sticky header for scrolling */
        .attendance-table tr:last-child td {border-bottom: none;}
        .attendance-table .present {color: var(--green); font-weight: 600;}
        .attendance-table .absent {color: var(--red); font-weight: 600;}
        .attendance-summary {margin-top: 0.5rem; padding: 1rem; border-radius: 8px; background: #e0f4f1; border: 1px solid var(--accent); margin-bottom: 1rem;}
        .attendance-summary p {margin: 0.3rem 0;}
        body {
    margin: 0;
    background: var(--bg);
    font-family: Poppins, sans-serif;
    color: var(--ink);
    transition: background .25s ease, color .25s ease;
}

header {
    background: var(--card);
    color: var(--ink);
    border-bottom: 1px solid var(--line);
}

.modal-content {
    background-color: var(--card);
    color: var(--ink);
}

.reset-request {
    background: rgba(231, 76, 60, 0.1);
}

.attendance-summary {
    background: rgba(42, 157, 143, 0.1);
}
.dark * {
    color: var(--ink);
}
    </style>
</head>
<body>


    <div class="wrap">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="message-box success"><?= htmlspecialchars($_SESSION['message']) ?></div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message-box error"><?= htmlspecialchars($_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php foreach ($groupedUsers as $roleName => $users): ?>
            <div class="role-section">
                <h3><?= ucfirst($roleName) ?>s (<?= count($users) ?>)</h3>
                <div class="user-list">
                    <?php if (empty($users)): ?>
                        <p class="muted">No <?= strtolower($roleName) ?>s found.</p>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <div class="user-card" data-user-id="<?= $user['user_id'] ?>" data-user-name="<?= htmlspecialchars($user['name']) ?>" data-user-role="<?= strtolower(htmlspecialchars($user['role'])) ?>">
                                <div class="user-avatar">
                                    <?php if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                                        <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile Picture">
                                    <?php else: ?>
                                        <?= strtoupper($user['name'][0] ?? 'U') ?>
                                    <?php endif; ?>
                                </div>
                                <div class="user-info">
                                    <h5><?= htmlspecialchars($user['name']) ?></h5>
                                    <p class="role"><?= htmlspecialchars($user['role']) ?></p>
                                </div>
                                <?php if (!empty($user['request_id'])): ?>
                                    <span class="reset-badge">Password Reset Request</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- User Details Modal (Existing) -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" data-modal="userModal">&times;</span>
            <div id="modal-details" class="modal-user-details">
                <!-- Details fetched via JS go here -->
            </div>
            <!-- Delete Form (Used for submission) -->
            <form id="deleteForm" class="delete-form" method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                <input type="hidden" name="delete_user_id" id="deleteUserId">
            </form>
        </div>
    </div>
    
    <!-- Attendance Modal (New) -->
    <div id="attendanceModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" data-modal="attendanceModal">&times;</span>
            <h3 id="attendanceUserName">Attendance Records</h3>
            
            <div class="daily-status-card">
                <h5>CURRENT DAY STATUS</h5>
                <p id="dailyMinutes"><strong>--</strong></p>
                <p id="dailyStatus" class="status absent">Loading...</p>
            </div>

            <div class="tab-buttons">
                <button class="tab-btn active" data-tab="weekly">This Week (Mon-Fri)</button>
                <button class="tab-btn" data-tab="monthly">This Month</button>
            </div>

            <div id="weeklyContent" class="tab-content">
                <table class="attendance-table">
                    <thead><tr><th>Date</th><th>Status</th><th>Absence Reason</th></tr></thead>
                    <tbody id="weeklyAttendanceBody"><tr><td colspan="3" style="text-align:center;">Loading weekly data...</td></tr></tbody>
                </table>
            </div>

            <div id="monthlyContent" class="tab-content" style="display:none;">
                 <div id="monthlySummary" class="attendance-summary"></div>
                <table class="attendance-table">
                    <thead><tr><th>Date</th><th>Status</th><th>Absence Reason</th></tr></thead>
                    <tbody id="monthlyAttendanceBody"><tr><td colspan="3" style="text-align:center;">Loading monthly data...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>


    <script>
        // Use a custom message display instead of alert()
        function showMessage(type, message) {
            // Remove any existing messages
            document.querySelectorAll('.message-box').forEach(box => box.remove());

            const wrap = document.querySelector('body');
            const box = document.createElement('div');
            box.className = `message-box ${type}`;
            box.textContent = message;
            wrap.prepend(box);
            setTimeout(() => {
                box.remove();
            }, 5000);
        }
        
        // Utility function to check if a date is a weekend (Sat or Sun)
        function isWeekend(dateString) {
            const date = new Date(dateString);
            const dayOfWeek = date.getDay(); // 0 is Sunday, 6 is Saturday
            return dayOfWeek === 0 || dayOfWeek === 6;
        }

        // Utility function to calculate the number of working days (Mon-Fri) in a given month
        function getWorkingDaysInMonth(year, month) {
            // month is 0-indexed (0=Jan, 11=Dec)
            const date = new Date(year, month, 1);
            let count = 0;
            // Loop until the month changes
            while (date.getMonth() === month) {
                const day = date.getDay();
                // Check if it's Monday (1) through Friday (5). 0=Sun, 6=Sat
                if (day !== 0 && day !== 6) {
                    count++;
                }
                date.setDate(date.getDate() + 1);
            }
            return count;
        }


        document.addEventListener('DOMContentLoaded', () => {
            const userCards = document.querySelectorAll('.user-card');
            const userModal = document.getElementById('userModal');
            const attendanceModal = document.getElementById('attendanceModal');
            const modalDetails = document.getElementById('modal-details');
            const deleteUserIdInput = document.getElementById('deleteUserId');

            // Close buttons for both modals
            document.querySelectorAll('.close-btn').forEach(btn => {
                btn.addEventListener('click', (event) => {
                    const modalId = event.target.dataset.modal === 'attendanceModal' ? attendanceModal : userModal;
                    modalId.style.display = 'none';
                });
            });

            window.addEventListener('click', (event) => {
                if (event.target === userModal) {
                    userModal.style.display = 'none';
                }
                if (event.target === attendanceModal) {
                    attendanceModal.style.display = 'none';
                }
            });

            userCards.forEach(card => {
                card.addEventListener('click', () => {
                    const userId = card.dataset.userId;
                    fetchUserDetails(userId);
                });
            });

            // --- USER DETAILS MODAL LOGIC ---
            function fetchUserDetails(userId) {
                // Clear previous details
                modalDetails.innerHTML = '';
                
                fetch('fetch_user_details.php?id=' + userId)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(user => {
                        if (user.error) {
                            showMessage('error', user.error);
                            return;
                        }
                        
                        // --- 1. Reset Request HTML ---
                        let resetRequestHtml = '';
                        if (user.reset_request) {
                            resetRequestHtml = `
                                <div class="reset-request">
                                    <p><strong>Password Reset Request:</strong></p>
                                    <p><strong>Reason:</strong> ${user.reset_reason}</p>
                                    ${user.reset_other_reason ? `<p><strong>Details:</strong> ${user.reset_other_reason}</p>` : ''}
                                    <div class="action-buttons">
                                        <button type="button" class="action-btn accept-btn" onclick="handleResetRequest(${user.request_id}, 'approved')">Accept</button>
                                        <button type="button" class="action-btn reject-btn" onclick="handleResetRequest(${user.request_id}, 'rejected')">Reject</button>
                                    </div>
                                </div>
                            `;
                        }

                        // --- 2. Main Details HTML ---
                        modalDetails.innerHTML = `
                            <div class="user-avatar avatar">
                                ${user.profile_picture ? `<img src="${user.profile_picture}" alt="Profile Picture">` : (user.initial || 'U')}
                            </div>
                            <h4>${user.name}</h4>
                            <p><strong>Role:</strong> <span class="role">${user.role}</span></p>
                            <p><strong>Status:</strong> ${user.status}</p>
                            <p><strong>Email:</strong> ${user.email}</p>
                            <p><strong>Phone:</strong> ${user.phone || 'N/A'}</p>
                            <p><strong>Department:</strong> ${user.department || 'N/A'}</p>
                            <p><strong>Bio:</strong> ${user.bio || 'N/A'}</p>
                            ${resetRequestHtml}
                            <div class="action-buttons">
                                <button type="button" class="action-btn attendance-btn" onclick="showAttendanceModal(${user.user_id}, '${user.name}')">
                                    <i class="fa-solid fa-calendar-check"></i> Attendance
                                </button>
                                <button type="button" class="action-btn reject-btn delete-btn-in-modal" onclick="document.getElementById('deleteForm').submit();">
                                    <i class="fa-solid fa-trash-can"></i> Delete
                                </button>
                            </div>
                        `;
                        
                        deleteUserIdInput.value = userId;

                        // Hide delete button for admins
                        const deleteBtnInModal = modalDetails.querySelector('.delete-btn-in-modal');
                        if (deleteBtnInModal && user.role.toLowerCase() === 'admin') {
                            deleteBtnInModal.style.display = 'none';
                        }
                        
                        userModal.style.display = 'flex';
                    })
                    .catch(error => {
                        console.error('Error fetching user details:', error);
                        showMessage('error', 'Could not fetch user details. Please try again.');
                    });
            }

            // --- ATTENDANCE MODAL LOGIC ---
            const weeklyContent = document.getElementById('weeklyContent');
            const monthlyContent = document.getElementById('monthlyContent');
            const tabButtons = document.querySelectorAll('#attendanceModal .tab-btn');

            tabButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const targetTab = e.target.dataset.tab;
                    
                    // Deactivate all
                    tabButtons.forEach(b => b.classList.remove('active'));
                    weeklyContent.style.display = 'none';
                    monthlyContent.style.display = 'none';

                    // Activate selected
                    e.target.classList.add('active');
                    document.getElementById(targetTab + 'Content').style.display = 'block';
                });
            });


            window.showAttendanceModal = function(userId, userName) {
                // Set modal title
                document.getElementById('attendanceUserName').textContent = `Attendance for ${userName}`;
                
                // Hide user details modal, show attendance modal
                userModal.style.display = 'none';
                attendanceModal.style.display = 'flex';
                
                // Reset to weekly view
                document.querySelector('.tab-btn[data-tab="weekly"]').click();

                // Start loading data
                fetchAttendanceData(userId);
            };

            function fetchAttendanceData(userId) {
                const weeklyBody = document.getElementById('weeklyAttendanceBody');
                const monthlyBody = document.getElementById('monthlyAttendanceBody');
                const monthlySummary = document.getElementById('monthlySummary');
                const dailyMinutes = document.getElementById('dailyMinutes');
                const dailyStatus = document.getElementById('dailyStatus');
                
                // Set loading state
                dailyMinutes.innerHTML = '<strong>Loading...</strong>';
                dailyStatus.textContent = 'Loading...';
                dailyStatus.className = 'status absent'; 
                weeklyBody.innerHTML = '<tr><td colspan="3" style="text-align:center;">Loading weekly data...</td></tr>';
                monthlyBody.innerHTML = '<tr><td colspan="3" style="text-align:center;">Loading monthly data...</td></tr>';
                monthlySummary.innerHTML = 'Loading summary...';


                fetch('api/fetch_user_attendance.php?user_id=' + userId)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                             showMessage('error', data.error);
                             return;
                        }

                        // --- 1. DAILY STATUS ---
                        dailyMinutes.innerHTML = `<strong>${data.daily_status.display}</strong>`;
                        dailyStatus.textContent = data.daily_status.status;
                        dailyStatus.className = 'status ' + (data.daily_status.minutes > 0 ? (data.daily_status.status.startsWith('Present') ? 'present' : 'absent') : 'absent');

                        // --- 2. WEEKLY LOG (Mon-Fri) ---
                        // The PHP API already filters to Mon-Fri, so we just render.
                        renderTable(weeklyBody, data.weekly_log, 'No attendance records for this week (Mon-Fri).', false);
                        
                        // --- 3. MONTHLY LOG (Filter out weekends on the client-side) ---
                        const workingDayMonthlyLog = data.monthly_log.filter(item => !isWeekend(item.date));

                        renderTable(monthlyBody, workingDayMonthlyLog, 'No working day attendance records for this month.', true);
                        renderMonthlySummary(workingDayMonthlyLog, data.month_name);

                    })
                    .catch(error => {
                        console.error('Error fetching attendance:', error);
                        showMessage('error', 'Could not fetch attendance data. Check console for details.');
                        weeklyBody.innerHTML = '<tr><td colspan="3" style="text-align:center; color: var(--red);">Error loading data.</td></tr>';
                        monthlyBody.innerHTML = '<tr><td colspan="3" style="text-align:center; color: var(--red);">Error loading data.</td></tr>';
                    });
            }

            // The 'isMonthly' flag ensures the monthly table only shows working day attendance.
            function renderTable(tbodyElement, logData, emptyMessage) {
                if (logData.length === 0) {
                    tbodyElement.innerHTML = `<tr><td colspan="3" style="text-align:center; color: var(--muted);">${emptyMessage}</td></tr>`;
                    return;
                }

                let html = logData.map(item => {
                    const statusClass = item.attendance === 'Yes' ? 'present' : 'absent';
                    // Show absence reason only if the status is 'No'
                    const reason = item.attendance === 'No' ? (item.absence_reason || 'Reason not recorded') : '--'; 
                    return `
                        <tr>
                            <td>${item.date}</td>
                            <td class="${statusClass}">${item.attendance}</td>
                            <td>${reason}</td>
                        </tr>
                    `;
                }).join('');
                tbodyElement.innerHTML = html;
            }

            function renderMonthlySummary(workingDayLogData, monthName) {
                const now = new Date();
                const currentMonth = now.getMonth();
                const currentYear = now.getFullYear();
                
                // Calculate total possible working days (Mon-Fri)
                const totalWorkingDays = getWorkingDaysInMonth(currentYear, currentMonth);

                const attendedDays = workingDayLogData.filter(item => item.attendance === 'Yes').length;
                const absentDays = workingDayLogData.filter(item => item.attendance === 'No').length;
                
                // Note: The total working days might be higher than attended + absent if a user is mid-month.
                const daysRecorded = attendedDays + absentDays;

                const summaryHtml = `
                    <p><strong>Month:</strong> ${monthName}</p>
                    <p><strong>Total Working Days in Month:</strong> ${totalWorkingDays}</p>
                    <p><strong>Recorded Days (Present/Absent):</strong> ${daysRecorded} out of ${totalWorkingDays} so far.</p>
                    <p><strong>Total Attended Days:</strong> <span class="present">${attendedDays}</span></p>
                    <p><strong>Total Absent Days:</strong> <span class="absent">${absentDays}</span></p>
                `;
                document.getElementById('monthlySummary').innerHTML = summaryHtml;
            }

            // Keeping the original structure for password reset request handling
            window.handleResetRequest = function(requestId, action) {
                if (!confirm(`Are you sure you want to ${action} this password reset request?`)) return;
                fetch('api/handle_reset_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ request_id: requestId, action: action })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`Password reset request ${action} successfully.`);
                        userModal.style.display = 'none';
                        location.reload();
                    } else {
                        alert(data.error || 'Failed to process request.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            };
        });
    </script>
</body>
</html>