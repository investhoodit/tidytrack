<?php
function log_activity($user_id, $activity) {
    include 'dp.php';
    $ip = $_SERVER['REMOTE_ADDR'];
    $agent = $_SERVER['HTTP_USER_AGENT'];

    $stmt = $conn->prepare("INSERT INTO user_activity (user_id, activity_type, ip_address, user_agent) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $activity, $ip, $agent);
    $stmt->execute();
}
?>
