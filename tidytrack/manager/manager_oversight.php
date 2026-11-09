<?php
session_start();
require_once __DIR__ . '/../dp.php';

$isTokenAccess = false;
$viewerUserId = null;

function validateManagerAccessToken(mysqli $conn, string $token, string $pageName = 'manager_oversight'): ?array {
    $stmt = $conn->prepare("
        SELECT t.manager_user_id, u.name, u.email, u.role
        FROM manager_page_tokens t
        JOIN users u ON u.user_id = t.manager_user_id
        WHERE t.token = ?
          AND t.page_name = ?
          AND t.expires_at >= NOW()
        LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param("ss", $token, $pageName);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row && strtolower(trim($row['role'])) === 'manager') {
        return $row;
    }
    return null;
}

if (!empty($_GET['access_token'])) {
    $tokenUser = validateManagerAccessToken($conn, trim($_GET['access_token']));
    if (!$tokenUser) {
        http_response_code(403);
        exit("Invalid or expired access link.");
    }

    $isTokenAccess = true;
    $viewerUserId = (int)$tokenUser['manager_user_id'];
} else {
    if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'manager') {
        http_response_code(403);
        exit("Forbidden");
    }

    $viewerUserId = (int)$_SESSION['user_id'];
}
?>