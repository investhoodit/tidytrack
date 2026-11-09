<?php
session_start();
include '../dp.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { echo json_encode(['error'=>'Unauthorized']); exit; }

// Create table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS chess_rooms (
        room        CHAR(6)      PRIMARY KEY,
        player_w    INT          NOT NULL,
        player_b    INT          DEFAULT NULL,
        board       TEXT         NOT NULL,
        turn        CHAR(1)      NOT NULL DEFAULT 'w',
        last_move   VARCHAR(50)  DEFAULT NULL,
        result      VARCHAR(80)  DEFAULT NULL,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// Auto-clean rooms older than 2 hours
$conn->query("DELETE FROM chess_rooms WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");

$action = $_GET['action'] ?? '';

// ── INIT board ──
$INIT = [
    ['r','n','b','q','k','b','n','r'],
    ['p','p','p','p','p','p','p','p'],
    ['','','','','','','',''],
    ['','','','','','','',''],
    ['','','','','','','',''],
    ['','','','','','','',''],
    ['P','P','P','P','P','P','P','P'],
    ['R','N','B','Q','K','B','N','R']
];

// ── CREATE ROOM ──
if ($action === 'create') {
    // Generate unique 6-char code
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $chk  = $conn->query("SELECT room FROM chess_rooms WHERE room='$code'");
    } while ($chk->num_rows > 0);

    $boardJson = json_encode($INIT);
    $stmt = $conn->prepare("INSERT INTO chess_rooms (room, player_w, board) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $code, $userId, $boardJson);
    if ($stmt->execute()) {
        echo json_encode(['room' => $code]);
    } else {
        echo json_encode(['error' => 'Could not create room']);
    }
    $stmt->close();
    exit;
}

// ── JOIN ROOM ──
if ($action === 'join') {
    $room = strtoupper(trim($_GET['room'] ?? ''));
    $stmt = $conn->prepare("SELECT player_w, player_b FROM chess_rooms WHERE room = ?");
    $stmt->bind_param("s", $room);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['error' => 'Room not found']); exit; }
    if ($row['player_b'])  { echo json_encode(['error' => 'Room is full']); exit; }
    if ($row['player_w'] == $userId) { echo json_encode(['error' => 'Cannot join your own room']); exit; }

    $stmt = $conn->prepare("UPDATE chess_rooms SET player_b = ? WHERE room = ?");
    $stmt->bind_param("is", $userId, $room);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true]);
    exit;
}

// ── POLL ──
if ($action === 'poll') {
    $room = strtoupper(trim($_GET['room'] ?? ''));
    $stmt = $conn->prepare("SELECT player_w, player_b, board, turn, last_move, result FROM chess_rooms WHERE room = ?");
    $stmt->bind_param("s", $room);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['error' => 'Room not found']); exit; }

    $players = $row['player_b'] ? 2 : 1;
    $lm = $row['last_move'] ? json_decode($row['last_move'], true) : null;
    echo json_encode([
        'players'   => $players,
        'board'     => json_decode($row['board'], true),
        'turn'      => $row['turn'],
        'last_move' => $lm,
        'result'    => $row['result']
    ]);
    exit;
}

// ── MOVE ──
if ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $room      = strtoupper(trim($body['room']  ?? ''));
    $boardData = $body['board']     ?? null;
    $lm        = $body['last_move'] ?? null;
    $nextTurn  = $body['turn']      ?? 'w';

    if (!$room || !$boardData) { echo json_encode(['error' => 'Missing data']); exit; }

    // Check win condition — king missing
    $flat = array_merge(...$boardData);
    $result = null;
    if (!in_array('k', $flat)) $result = '♟ White wins! 🎉';
    if (!in_array('K', $flat)) $result = '♚ Black wins! 🎉';

    $boardJson = json_encode($boardData);
    $lmJson    = $lm ? json_encode($lm) : null;

    $stmt = $conn->prepare("UPDATE chess_rooms SET board=?, turn=?, last_move=?, result=? WHERE room=?");
    $stmt->bind_param("sssss", $boardJson, $nextTurn, $lmJson, $result, $room);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);