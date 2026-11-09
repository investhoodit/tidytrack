<?php
// sharezone_actions.php
// AJAX endpoint for likes and comments
session_start();
require_once '../dp.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit;
}

$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['name'];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

// ─────────────────────────────────────────
// TOGGLE LIKE
// ─────────────────────────────────────────
if ($action === 'toggle_like') {
    $postId = (int)($_POST['post_id'] ?? 0);
    if (!$postId) { echo json_encode(['error' => 'Invalid post']); exit; }

    // Check if already liked
    $check = $conn->prepare("SELECT id FROM sharezone_likes WHERE post_id = ? AND user_id = ?");
    $check->bind_param("ii", $postId, $userId);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if ($exists) {
        // Unlike
        $del = $conn->prepare("DELETE FROM sharezone_likes WHERE post_id = ? AND user_id = ?");
        $del->bind_param("ii", $postId, $userId);
        $del->execute();
        $del->close();
        $liked = false;
    } else {
        // Like
        $ins = $conn->prepare("INSERT INTO sharezone_likes (post_id, user_id) VALUES (?, ?)");
        $ins->bind_param("ii", $postId, $userId);
        $ins->execute();
        $ins->close();
        $liked = true;
    }

    // Get new count
    $cnt = $conn->prepare("SELECT COUNT(*) as c FROM sharezone_likes WHERE post_id = ?");
    $cnt->bind_param("i", $postId);
    $cnt->execute();
    $count = $cnt->get_result()->fetch_assoc()['c'];
    $cnt->close();

    echo json_encode(['liked' => $liked, 'count' => (int)$count]);
    exit;
}

// ─────────────────────────────────────────
// GET COMMENTS
// ─────────────────────────────────────────
if ($action === 'get_comments') {
    $postId = (int)($_GET['post_id'] ?? 0);
    if (!$postId) { echo json_encode(['comments' => []]); exit; }

    $stmt = $conn->prepare("
        SELECT c.id, c.user_id, c.user_name, c.content, c.created_at, u.profile_picture
        FROM sharezone_comments c
        LEFT JOIN users u ON c.user_id = u.user_id
        WHERE c.post_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Format time
    $now = new DateTime();
    foreach ($rows as &$r) {
        $ago = $now->diff(new DateTime($r['created_at']));
        if ($ago->y) $r['time_ago'] = $ago->y . 'y ago';
        elseif ($ago->m) $r['time_ago'] = $ago->m . 'mo ago';
        elseif ($ago->d) $r['time_ago'] = $ago->d . 'd ago';
        elseif ($ago->h) $r['time_ago'] = $ago->h . 'h ago';
        elseif ($ago->i) $r['time_ago'] = $ago->i . 'm ago';
        else $r['time_ago'] = 'just now';
    }

    echo json_encode(['comments' => $rows, 'current_user_id' => $userId]);
    exit;
}

// ─────────────────────────────────────────
// POST COMMENT
// ─────────────────────────────────────────
if ($action === 'post_comment') {
    $postId  = (int)($_POST['post_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if (!$postId || $content === '') {
        echo json_encode(['error' => 'Missing data']); exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO sharezone_comments (post_id, user_id, user_name, content) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("iiss", $postId, $userId, $userName, $content);
    $stmt->execute();
    $newId = $conn->insert_id;
    $stmt->close();

    // Get comment count
    $cnt = $conn->prepare("SELECT COUNT(*) as c FROM sharezone_comments WHERE post_id = ?");
    $cnt->bind_param("i", $postId);
    $cnt->execute();
    $count = $cnt->get_result()->fetch_assoc()['c'];
    $cnt->close();

    // Fetch profile pic
    $pic = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
    $pic->bind_param("i", $userId);
    $pic->execute();
    $picRow = $pic->get_result()->fetch_assoc();
    $pic->close();

    echo json_encode([
        'id'          => $newId,
        'user_name'   => $userName,
        'user_id'     => $userId,
        'content'     => $content,
        'time_ago'    => 'just now',
        'profile_picture' => $picRow['profile_picture'] ?? null,
        'comment_count' => (int)$count
    ]);
    exit;
}

// ─────────────────────────────────────────
// DELETE COMMENT
// ─────────────────────────────────────────
if ($action === 'delete_comment') {
    $commentId = (int)($_POST['comment_id'] ?? 0);
    if (!$commentId) { echo json_encode(['error' => 'Invalid']); exit; }

    // Only own comments
    $stmt = $conn->prepare("DELETE FROM sharezone_comments WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $commentId, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    echo json_encode(['deleted' => $affected > 0]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);