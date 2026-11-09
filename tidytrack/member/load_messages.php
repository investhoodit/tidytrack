<?php
session_start();
include '../dp.php';

$myId = $_SESSION['user_id'] ?? null;
$otherId = $_GET['user_id'] ?? null;

if (!$myId || !$otherId) {
  die("Invalid access.");
}

$stmt = $conn->prepare("SELECT * FROM messages 
  WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
  ORDER BY timestamp ASC");
$stmt->bind_param("iiii", $myId, $otherId, $otherId, $myId);
$stmt->execute();
$result = $stmt->get_result();

while ($msg = $result->fetch_assoc()) {
  $side = $msg['sender_id'] == $myId ? 'me' : 'them';
  $text = htmlspecialchars($msg['message']);
  $time = date('H:i', strtotime($msg['timestamp']));
  echo "<div class='bubble {$side}'>
          <div>{$text}</div>
          <small>{$time}</small>
        </div>";
}
$stmt->close();
?>
