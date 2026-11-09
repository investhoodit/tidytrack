<?php
// admin/api/meeting_attendance.php
header('Content-Type: application/json');
session_start();

// Adjust path to your DB connection file:
require_once __DIR__ . '/../../dp.php';

// Read JSON body
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$event       = $input['event'] ?? '';          // 'join' | 'leave' | 'end'
$roomName    = trim($input['roomName'] ?? '');
$participant = trim($input['participantId'] ?? '');
$displayName = trim($input['displayName'] ?? '');
$tsMs        = $input['timestamp'] ?? null;

if (!$event || !$roomName || !$tsMs) {
  http_response_code(422);
  echo json_encode(['ok'=>false, 'error'=>'Missing fields (event, roomName, timestamp)']);
  exit;
}

$when = date('Y-m-d H:i:s', (int)($tsMs / 1000)); // ms -> Y-m-d H:i:s

// --- find or create an active meeting row for this room ---
$meetingId = null;
$stmt = $conn->prepare("SELECT meeting_id FROM meetings WHERE room_name=? AND ended_at IS NULL ORDER BY meeting_id DESC LIMIT 1");
$stmt->bind_param('s', $roomName);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) { $meetingId = (int)$row['meeting_id']; }
$stmt->close();

if (!$meetingId) {
  $createdBy = $_SESSION['user_id'] ?? null;
  $stmt = $conn->prepare("INSERT INTO meetings (room_name, created_by, started_at) VALUES (?,?,?)");
  $stmt->bind_param('sis', $roomName, $createdBy, $when);
  $stmt->execute();
  $meetingId = $stmt->insert_id;
  $stmt->close();
}

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

if ($event === 'join') {
  if (!$participant) { $participant = 'unknown'; }
  $stmt = $conn->prepare("INSERT INTO meeting_attendance (meeting_id, participant_id, display_name, join_time, user_agent, ip_addr) VALUES (?,?,?,?,?,?)");
  $stmt->bind_param('isssss', $meetingId, $participant, $displayName, $when, $ua, $ip);
  $stmt->execute();
  $stmt->close();
  echo json_encode(['ok'=>true, 'meeting_id'=>$meetingId]); exit;
}

if ($event === 'leave') {
  $stmt = $conn->prepare("
    SELECT attendance_id, join_time FROM meeting_attendance
    WHERE meeting_id=? AND participant_id=? AND leave_time IS NULL
    ORDER BY attendance_id DESC LIMIT 1
  ");
  $stmt->bind_param('is', $meetingId, $participant);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($row) {
    $attendanceId = (int)$row['attendance_id'];
    $joinTime = strtotime($row['join_time']);
    $leaveTime = strtotime($when);
    $dur = max(0, $leaveTime - $joinTime);

    $stmt = $conn->prepare("UPDATE meeting_attendance SET leave_time=?, duration_seconds=? WHERE attendance_id=?");
    $stmt->bind_param('sii', $when, $dur, $attendanceId);
    $stmt->execute();
    $stmt->close();
  }
  echo json_encode(['ok'=>true]); exit;
}

if ($event === 'end') {
  // close meeting
  $stmt = $conn->prepare("UPDATE meetings SET ended_at=? WHERE meeting_id=? AND ended_at IS NULL");
  $stmt->bind_param('si', $when, $meetingId);
  $stmt->execute();
  $stmt->close();

  // close any open attendance rows
  $res = $conn->query("SELECT attendance_id, join_time FROM meeting_attendance WHERE meeting_id={$meetingId} AND leave_time IS NULL");
  while ($r = $res->fetch_assoc()) {
    $aid = (int)$r['attendance_id'];
    $jt  = strtotime($r['join_time']);
    $lt  = strtotime($when);
    $dur = max(0, $lt - $jt);
    $stmt = $conn->prepare("UPDATE meeting_attendance SET leave_time=?, duration_seconds=? WHERE attendance_id=?");
    $stmt->bind_param('sii', $when, $dur, $aid);
    $stmt->execute();
    $stmt->close();
  }
  echo json_encode(['ok'=>true]); exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'Unknown event']);
