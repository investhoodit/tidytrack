<?php
// export_attendance_excel.php
// Secure Excel export – no external libraries required

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// 1) Admin access only
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

require_once '../dp.php';

// 2) Read filters (identical to main page logic)
$current_month_start = date('Y-m-01');
$current_date        = date('Y-m-d');

$f_start   = $_GET['start_date'] ?? $current_month_start;
$f_end     = $_GET['end_date']   ?? $current_date;
$f_t_start = $_GET['start_time'] ?? '00:00';
$f_t_end   = $_GET['end_time']   ?? '23:59';
$f_code    = $_GET['room_code']  ?? '';
$f_user    = $_GET['search_user'] ?? '';

// 3) Build WHERE clause
$where = ["1=1"];
$where[] = "DATE(joined_at) BETWEEN '$f_start' AND '$f_end'";
$where[] = "TIME(joined_at) BETWEEN '$f_t_start:00' AND '$f_t_end:59'";

if ($f_code !== '') $where[] = "UPPER(room_code) LIKE UPPER('%$f_code%')";
if ($f_user !== '') $where[] = "user_name LIKE '%$f_user%'";

$where_sql = implode(' AND ', $where);

// 4) Query with accurate host detection (first join per room per day)
$query = "
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

$result = $conn->query($query);

// 5) Excel headers (HTML → XLS)
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance_' . date('Y-m-d') . '.xls"');
ob_clean(); // Remove any accidental output before this point

// 6) Output the Excel file as an HTML table with styling
echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Attendance Export</title>
    <style>
        th { background: #f2f2f2; }
        td, th { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .host-row { background: #e8f0fe; }
        table { border-collapse: collapse; width: 100%; }
    </style>
</head>
<body>
    <h2>Attendance Logs</h2>
    <p>Filters: Date ' . htmlspecialchars($f_start) . ' to ' . htmlspecialchars($f_end) . 
      ' | Time ' . htmlspecialchars($f_t_start) . ' – ' . htmlspecialchars($f_t_end) .
      ($f_code ? ' | Room: ' . htmlspecialchars($f_code) : '') .
      ($f_user ? ' | User: ' . htmlspecialchars($f_user) : '') . '</p>
    <table>
        <thead>
            <tr>
                <th>User ID</th>
                <th>Name</th>
                <th>Role</th>
                <th>Room Code</th>
                <th>Joined At</th>
                <th>Host</th>
            </tr>
        </thead>
        <tbody>';

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $hostClass = $row['is_host'] ? 'host-row' : '';
        echo "<tr class='$hostClass'>
                  <td>{$row['user_id']}</td>
                  <td>" . htmlspecialchars($row['user_name']) . "</td>
                  <td>" . strtoupper($row['role']) . "</td>
                  <td>" . htmlspecialchars($row['room_code']) . "</td>
                  <td>{$row['joined_at']}</td>
                  <td>{$row['is_host']}</td>
                </tr>";
    }
} else {
    echo "<tr><td colspan='6'>No records found</td></tr>";
}

echo '</tbody>
    </table>
</body>
</html>';
exit;
?>