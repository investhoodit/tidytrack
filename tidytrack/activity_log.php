<?php
include 'db.php';
$result = $conn->query("SELECT * FROM user_activity ORDER BY timestamp DESC");

echo "<h2>User Activity Log</h2>";
while($row = $result->fetch_assoc()) {
    echo "User {$row['user_id']} - {$row['activity_type']} - {$row['ip_address']} - {$row['timestamp']}<br>";
}
?>
