<?php
// admin_advanced_logs.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

require_once '../dp.php';

// ─── 1. Filters ──────────────────────────────────────────────────────────────
$current_month_start = date('Y-m-01');
$current_date        = date('Y-m-d');

$f_start   = $_GET['start_date'] ?? $current_month_start;
$f_end     = $_GET['end_date']   ?? $current_date;
$f_t_start = $_GET['start_time'] ?? '00:00';
$f_t_end   = $_GET['end_time']   ?? '23:59';
$f_code    = $_GET['room_code']  ?? '';
$f_user    = $_GET['search_user'] ?? '';

// ─── 2. Build WHERE clause ──────────────────────────────────────────────────
$where = ["1=1"];
$where[] = "DATE(joined_at) BETWEEN '$f_start' AND '$f_end'";
$where[] = "TIME(joined_at) BETWEEN '$f_t_start:00' AND '$f_t_end:59'";

if ($f_code !== '') $where[] = "UPPER(room_code) LIKE UPPER('%$f_code%')";
if ($f_user !== '') $where[] = "user_name LIKE '%$f_user%'";

$where_sql = implode(' AND ', $where);

// ─── 3. CSV Export ──────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['User ID', 'Name', 'Role', 'Room Code', 'Joined At', 'Likely Host']);

    $q = "
        SELECT 
            user_id, user_name, role, room_code, joined_at,
            IF(rn = 1, 'Host', '') AS is_host
        FROM (
            SELECT *,
                   ROW_NUMBER() OVER (PARTITION BY room_code, DATE(joined_at) ORDER BY joined_at ASC) AS rn
            FROM meeting_logs
            WHERE $where_sql
        ) t
        ORDER BY joined_at DESC
    ";

    $res = $conn->query($q);
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['user_id'],
            $row['user_name'],
            $row['role'],
            $row['room_code'],
            $row['joined_at'],
            $row['is_host']
        ]);
    }
    exit;
}

// ─── 4. Stats ────────────────────────────────────────────────────────────────
$stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        COUNT(DISTINCT user_id) as unique_users
    FROM meeting_logs 
    WHERE $where_sql
")->fetch_assoc();

// ─── 5. Main data with accurate host detection ──────────────────────────────
$query = "
    SELECT *, 
           IF(rn = 1, 1, 0) AS is_host
    FROM (
        SELECT t.*,
               ROW_NUMBER() OVER (PARTITION BY room_code, DATE(joined_at) ORDER BY joined_at ASC) AS rn
        FROM meeting_logs t
        WHERE $where_sql
    ) sub
    ORDER BY joined_at DESC
";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Logs • TidyTrack</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            /* Light mode variables */
            --primary-light: #8b5cf6;
            --primary-dark: #7c3aed;
            --bg-light: #ffffff;
            --card-light: #ffffff;
            --border-light: #e2e8f0;
            --text-light: #0f172a;
            --text-muted-light: #64748b;
            --gray-light: #f8fafc;
            --host-bg-light: #f3e8ff;
            --input-bg-light: #ffffff;
            --hover-light: rgba(139,92,246,0.04);
            --badge-bg-light: #e2e8f0;
            --badge-text-light: #475569;
            
            /* Dark mode variables */
            --primary-dark-mode: #a78bfa;
            --primary-dark-dark: #8b5cf6;
            --bg-dark: #0f172a;
            --card-dark: #1e293b;
            --border-dark: #334155;
            --text-dark: #f1f5f9;
            --text-muted-dark: #94a3b8;
            --gray-dark: #1e293b;
            --host-bg-dark: #2e1a4a;
            --input-bg-dark: #1e293b;
            --hover-dark: rgba(167,139,250,0.1);
            --badge-bg-dark: #334155;
            --badge-text-dark: #e2e8f0;
            
            /* Default to light mode */
            --primary: var(--primary-light);
            --primary-hover: var(--primary-dark);
            --bg: var(--bg-light);
            --card: var(--card-light);
            --border: var(--border-light);
            --text: var(--text-light);
            --text-muted: var(--text-muted-light);
            --gray-light: var(--gray-light);
            --host-bg: var(--host-bg-light);
            --input-bg: var(--input-bg-light);
            --hover-bg: var(--hover-light);
            --badge-bg: var(--badge-bg-light);
            --badge-text: var(--badge-text-light);
        }

        /* Dark mode class */
        body.dark-mode {
            --primary: var(--primary-dark-mode);
            --primary-hover: var(--primary-dark-dark);
            --bg: var(--bg-dark);
            --card: var(--card-dark);
            --border: var(--border-dark);
            --text: var(--text-dark);
            --text-muted: var(--text-muted-dark);
            --gray-light: var(--gray-dark);
            --host-bg: var(--host-bg-dark);
            --input-bg: var(--input-bg-dark);
            --hover-bg: var(--hover-dark);
            --badge-bg: var(--badge-bg-dark);
            --badge-text: var(--badge-text-dark);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.2s ease;
        }

        .container { max-width: 1280px; margin: 0 auto; padding: 32px 20px; }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
        }

        /* Dark mode toggle button */
        .theme-toggle {
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--text);
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.2s;
            margin-left: 8px;
        }

        .theme-toggle:hover {
            background: var(--gray-light);
            transform: scale(1.05);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.95rem;
        }

        .btn-primary { 
            background: var(--primary); 
            color: white; 
            border: 1px solid transparent;
        }
        .btn-primary:hover { 
            background: var(--primary-hover); 
            opacity: 0.9;
        }
        .btn-outline { 
            background: var(--card); 
            border: 1px solid var(--border); 
            color: var(--text); 
        }
        .btn-outline:hover { 
            background: var(--gray-light); 
        }

        .filter-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 28px;
            margin-bottom: 40px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            transition: background-color 0.3s ease;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        input[type="date"],
        input[type="time"],
        input[type="text"] {
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            background: var(--input-bg);
            color: var(--text);
            transition: background-color 0.3s ease, border-color 0.2s ease;
        }

        input[type="date"]:focus,
        input[type="time"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }

        body.dark-mode input[type="date"]:focus,
        body.dark-mode input[type="time"]:focus,
        body.dark-mode input[type="text"]:focus {
            box-shadow: 0 0 0 3px rgba(167,139,250,0.2);
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            align-self: flex-end;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--gray-light);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px 20px;
            text-align: center;
            transition: background-color 0.3s ease;
        }

        .stat-card small {
            display: block;
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .stat-card .number {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--primary);
        }

        .table-container {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            transition: background-color 0.3s ease;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        th, td {
            padding: 16px 20px;
            text-align: left;
        }

        th {
            background: var(--gray-light);
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border-bottom: 2px solid var(--border);
            transition: background-color 0.3s ease;
        }

        tr {
            border-bottom: 1px solid var(--border);
            transition: background-color 0.2s ease;
        }

        tr:hover {
            background: var(--hover-bg);
        }

        .host-row {
            background: var(--host-bg) !important;
            font-weight: 500;
        }

        .host-badge {
            background: var(--primary);
            color: white;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .room-code {
            font-family: ui-monospace, monospace;
            color: var(--primary);
            font-weight: 500;
        }

        .time {
            font-family: ui-monospace, monospace;
            color: #2563eb;
        }

        body.dark-mode .time {
            color: #60a5fa;
        }

        .role-badge {
            background: var(--badge-bg);
            color: var(--badge-text);
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-block;
            transition: background-color 0.3s ease;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-muted);
            font-size: 1.1rem;
        }

        .empty-state i {
            font-size: 3.5rem;
            opacity: 0.3;
            margin-bottom: 16px;
            display: block;
        }

        @media (max-width: 768px) {
            .filter-grid { grid-template-columns: 1fr; }
            .filter-actions { flex-direction: column; width: 100%; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .header-left { flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <div class="header-left">
            <h1>Attendance Intelligence</h1>
            <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
                <i class="fas fa-moon"></i>
            </button>
        </div>
        <div>
              <a href="export_attendance_excel.php?<?= htmlspecialchars($_SERVER['QUERY_STRING']) ?>" class="btn btn-outline">
        <i class="fas fa-file-excel"></i> Export Excel
    </a>
            <a href="../admin/admin_index.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </header>

    <form class="filter-card" method="GET">
        <div class="filter-grid">
            <div class="form-group">
                <label>Date Range</label>
                <div style="display:flex; gap:12px;">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($f_start) ?>">
                    <input type="date" name="end_date"   value="<?= htmlspecialchars($f_end) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Time Window</label>
                <div style="display:flex; gap:12px;">
                    <input type="time" name="start_time" value="<?= htmlspecialchars($f_t_start) ?>">
                    <input type="time" name="end_time"   value="<?= htmlspecialchars($f_t_end) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Room Code</label>
                <input type="text" name="room_code" placeholder="INVESTHOOD, ABC123..." value="<?= htmlspecialchars($f_code) ?>">
            </div>

            <div class="form-group">
                <label>User / Name</label>
                <input type="text" name="search_user" placeholder="Search participant..." value="<?= htmlspecialchars($f_user) ?>">
            </div>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Apply Filters
            </button>
            <a href="admin_attendaceM.php" class="btn btn-outline">
                <i class="fas fa-undo"></i> Reset Filters
            </a>
        </div>
    </form>

    <div class="stats-grid">
        <div class="stat-card">
            <small>Total Entries</small>
            <div class="number"><?= number_format($stats['total']) ?></div>
        </div>
        <div class="stat-card">
            <small>Unique Participants</small>
            <div class="number"><?= number_format($stats['unique_users']) ?></div>
        </div>
        <div class="stat-card">
            <small>Time Window</small>
            <div class="number"><?= htmlspecialchars($f_t_start) ?> – <?= htmlspecialchars($f_t_end) ?></div>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Participant</th>
                    <th>Room</th>
                    <th>Date</th>
                    <th>Join Time</th>
                    <th>Role</th>
                    <th>Host?</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr class="<?= $row['is_host'] ? 'host-row' : '' ?>">
                        <td style="font-weight: 500;"><?= htmlspecialchars($row['user_name']) ?></td>
                        <td><span class="room-code"><?= htmlspecialchars($row['room_code']) ?></span></td>
                        <td><?= date('d M Y', strtotime($row['joined_at'])) ?></td>
                        <td><span class="time"><?= date('H:i:s', strtotime($row['joined_at'])) ?></span></td>
                        <td><span class="role-badge"><?= strtoupper($row['role']) ?></span></td>
                        <td>
                            <?php if ($row['is_host']): ?>
                                <span class="host-badge">
                                    <i class="fas fa-crown"></i> Host
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="empty-state">
                        <i class="far fa-calendar-xmark"></i>
                        No matching attendance records found with current filters.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function() {
    // Check for saved dark mode preference
    const savedDarkMode = localStorage.getItem('darkMode');
    const themeToggle = document.getElementById('themeToggle');
    const icon = themeToggle.querySelector('i');
    
    // Function to set dark mode
    function setDarkMode(isDark) {
        if (isDark) {
            document.body.classList.add('dark-mode');
            icon.className = 'fas fa-sun';
            localStorage.setItem('darkMode', 'enabled');
        } else {
            document.body.classList.remove('dark-mode');
            icon.className = 'fas fa-moon';
            localStorage.setItem('darkMode', 'disabled');
        }
    }
    
    // Apply saved preference
    if (savedDarkMode === 'enabled') {
        setDarkMode(true);
    } else if (savedDarkMode === 'disabled') {
        setDarkMode(false);
    } else {
        // Check system preference if no saved preference
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        setDarkMode(systemPrefersDark);
    }
    
    // Toggle dark mode on button click
    themeToggle.addEventListener('click', () => {
        const isDark = document.body.classList.contains('dark-mode');
        setDarkMode(!isDark);
    });
    
    // Listen for system preference changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        // Only change if user hasn't manually set a preference
        if (!localStorage.getItem('darkMode')) {
            setDarkMode(e.matches);
        }
    });
})();
</script>

</body>
</html>