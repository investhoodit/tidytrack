<?php
session_start();
include '../dp.php';

/*
|--------------------------------------------------------------------------
| BA PORTAL ONBOARDING
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['ba_pending_user_id']) ||
    empty($_SESSION['ba_pending_email'])
) {
    header('Location: index.php');
    exit;
}

$userId       = (int) $_SESSION['ba_pending_user_id'];
$pendingEmail = (string) $_SESSION['ba_pending_email'];

// Expire incomplete onboarding after 30 minutes
if (
    empty($_SESSION['ba_pending_started']) ||
    time() - (int) $_SESSION['ba_pending_started'] > 1800
) {
    unset(
        $_SESSION['ba_pending_user_id'],
        $_SESSION['ba_pending_email'],
        $_SESSION['ba_pending_name'],
        $_SESSION['ba_pending_started']
    );
    $_SESSION['ba_login_error'] = 'Your onboarding session expired. Please sign in again.';
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['ba_onboarding_csrf'])) {
    $_SESSION['ba_onboarding_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['ba_onboarding_csrf'];

$error   = '';
$success = '';

$allNotifications = [
    'Project Updates',
    'Team Announcements',
    'Task Notifications',
    'Important Notices',
];

$allInterests = [
    'Project Analysis',
    'Project Management',
    'Task Monitoring',
    'Reports and Documentation',
    'Team Collaboration',
];

function baEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ── Current user ─────────────────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT user_id, name, email, phone, profile_picture, department, role, bio
    FROM users
    WHERE user_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (
    !$user ||
    strcasecmp(trim($user['email'] ?? ''), $pendingEmail) !== 0 ||
    strcasecmp(trim($user['department'] ?? ''), 'BA Team') !== 0
) {
    unset(
        $_SESSION['ba_pending_user_id'],
        $_SESSION['ba_pending_email'],
        $_SESSION['ba_pending_name'],
        $_SESSION['ba_pending_started']
    );
    $_SESSION['ba_login_error'] = 'Your account is not authorized to access this portal.';
    header('Location: index.php');
    exit;
}

/* ── Leader (project 62) ──────────────────────────────────────── */
$leader = null;
$leaderStmt = $conn->prepare("
    SELECT u.user_id, u.name, u.email, u.phone, u.profile_picture, p.project_name
    FROM projects_x p
    LEFT JOIN users u ON u.user_id = p.teamLeadder_id
    WHERE p.project_id = 62
    LIMIT 1
");
if ($leaderStmt) {
    $leaderStmt->execute();
    $leader = $leaderStmt->get_result()->fetch_assoc();
    $leaderStmt->close();
    if ($leader && empty($leader['user_id'])) {
        $leader = null;
    }
}

/* ── Active projects ──────────────────────────────────────────── */
$projects = [];
$projectStmt = $conn->prepare("
    SELECT p.project_id, p.project_name, p.deliverable, p.start_date, p.end_date,
           u.name AS leader_name
    FROM projects_x p
    LEFT JOIN users u ON u.user_id = p.teamLeadder_id
    WHERE p.end_date >= CURDATE()
    ORDER BY p.project_name ASC
");
if ($projectStmt) {
    $projectStmt->execute();
    $res = $projectStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $projects[] = $row;
    }
    $projectStmt->close();
}

/* ── Existing onboarding row ──────────────────────────────────── */
$onboarding = null;
$onboardingStmt = $conn->prepare("SELECT * FROM ba_onboarding WHERE user_id = ? LIMIT 1");
if ($onboardingStmt) {
    $onboardingStmt->bind_param('i', $userId);
    $onboardingStmt->execute();
    $onboarding = $onboardingStmt->get_result()->fetch_assoc();
    $onboardingStmt->close();
} else {
    // Table missing or broken — show clear error later if they try to save
    error_log('ba_onboarding SELECT prepare failed: ' . $conn->error);
}

/* Already done → dashboard */
if ($onboarding && (int) ($onboarding['completed'] ?? 0) === 1) {
    session_regenerate_id(true);
    $_SESSION['ba_authenticated'] = true;
    $_SESSION['ba_user_id']       = (int) $user['user_id'];
    $_SESSION['ba_email']         = $user['email'];
    $_SESSION['ba_name']          = $user['name'] ?? '';
    $_SESSION['ba_department']    = $user['department'] ?? '';
    $_SESSION['ba_developer']     = false;

    unset(
        $_SESSION['ba_pending_user_id'],
        $_SESSION['ba_pending_email'],
        $_SESSION['ba_pending_name'],
        $_SESSION['ba_pending_started'],
        $_SESSION['ba_onboarding_csrf']
    );

    header('Location: dashboard.php');
    exit;
}

$savedPreferences = [];
if ($onboarding && !empty($onboarding['preferences'])) {
    $decoded = json_decode($onboarding['preferences'], true);
    if (is_array($decoded)) {
        $savedPreferences = $decoded;
    }
}

$selectedInterests       = $savedPreferences['interests'] ?? [];
$selectedNotifications   = $savedPreferences['notifications'] ?? [];
$notificationsAllChoice  = $savedPreferences['notifications_all'] ?? '';
$interestsAllChoice      = $savedPreferences['interests_all'] ?? '';
$leaderConfirmed         = $onboarding['leader_confirmed'] ?? 'not_answered';

/* ── POST ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'Your session could not be verified. Please refresh and try again.';
    } else {

        /* ---- Upload picture ---- */
        if (isset($_POST['upload_picture'])) {

            if (
                !isset($_FILES['profile_picture']) ||
                $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK
            ) {
                $error = 'Please select a valid image to upload.';
            } elseif ($_FILES['profile_picture']['size'] > 3 * 1024 * 1024) {
                $error = 'Your profile picture must be smaller than 3 MB.';
            } else {
                $tmpFile  = $_FILES['profile_picture']['tmp_name'];
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($tmpFile);

                $allowedTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                ];

                if (!isset($allowedTypes[$mimeType])) {
                    $error = 'Only JPG, PNG and WebP images are allowed.';
                } elseif (getimagesize($tmpFile) === false) {
                    $error = 'The uploaded file is not a valid image.';
                } else {
                    $uploadDirectory = dirname(__DIR__) . '/uploads/profiles/';

                    if (
                        !is_dir($uploadDirectory) &&
                        !mkdir($uploadDirectory, 0755, true) &&
                        !is_dir($uploadDirectory)
                    ) {
                        $error = 'The profile picture folder could not be created.';
                    } else {
                        $filename      = 'ba_profile_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
                        $absolutePath  = $uploadDirectory . $filename;
                        $databasePath  = '../uploads/profiles/' . $filename;

                        if (move_uploaded_file($tmpFile, $absolutePath)) {
                            $updatePicture = $conn->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
                            $updatePicture->bind_param('si', $databasePath, $userId);

                            if ($updatePicture->execute()) {
                                $updatePicture->close();
                                header('Location: verify.php');
                                exit;
                            }

                            $updatePicture->close();
                            if (is_file($absolutePath)) {
                                unlink($absolutePath);
                            }
                            $error = 'Your profile picture could not be saved.';
                        } else {
                            $error = 'The image could not be uploaded. Please try again.';
                        }
                    }
                }
            }
        }

        /* ---- Complete onboarding ---- */
        if (isset($_POST['complete_onboarding']) && $error === '') {

            $leaderAnswer  = $_POST['leader_confirmed'] ?? '';
            $emailAccepted = isset($_POST['email_communication']) ? 1 : 0;
            $termsAccepted = isset($_POST['accept_terms']) ? 1 : 0;
            $notifAll      = $_POST['notifications_all'] ?? '';
            $interestAll   = $_POST['interests_all'] ?? '';

            if (!in_array($leaderAnswer, ['yes', 'no'], true)) {
                $error = 'Please confirm whether the displayed team leader is correct.';
            } elseif ($emailAccepted !== 1) {
                $error = 'Please acknowledge the email communication notice.';
            } elseif ($termsAccepted !== 1) {
                $error = 'You must accept the terms to continue.';
            } elseif (!in_array($notifAll, ['yes', 'no'], true)) {
                $error = 'Please choose Yes or No for email updates.';
            } elseif (!in_array($interestAll, ['yes', 'no'], true)) {
                $error = 'Please choose Yes or No for interest areas.';
            }

            $interests        = [];
            $notifications    = [];
            $preferencesJson  = '';

            if ($error === '') {
                $notifications = ($notifAll === 'yes') ? $allNotifications : [];
                $interests     = ($interestAll === 'yes') ? $allInterests : [];

                $preferencesJson = json_encode([
                    'interests'          => $interests,
                    'notifications'      => $notifications,
                    'notifications_all'  => $notifAll,
                    'interests_all'      => $interestAll,
                ], JSON_UNESCAPED_UNICODE);

                if ($preferencesJson === false) {
                    $error = 'Your preferences could not be processed.';
                }
            }

            if ($error === '') {
                $checkUser = $conn->prepare("
                    SELECT user_id, email, name, department
                    FROM users
                    WHERE user_id = ?
                    LIMIT 1
                ");
                $checkUser->bind_param('i', $userId);
                $checkUser->execute();
                $currentUser = $checkUser->get_result()->fetch_assoc();
                $checkUser->close();

                if (
                    !$currentUser ||
                    strcasecmp(trim($currentUser['email'] ?? ''), $pendingEmail) !== 0 ||
                    strcasecmp(trim($currentUser['department'] ?? ''), 'BA Team') !== 0
                ) {
                    $error = 'Your account is no longer authorized to access this portal.';
                }
            }

            if ($error === '') {
                /*
                 * Prefer a simple UPSERT pattern that works even if
                 * UNIQUE(user_id) is missing: try UPDATE first, else INSERT.
                 */
                $updated = false;

                $upd = $conn->prepare("
                    UPDATE ba_onboarding
                    SET
                        leader_confirmed = ?,
                        email_communication_accepted = ?,
                        terms_accepted = ?,
                        preferences = ?,
                        completed = 1,
                        completed_at = NOW()
                    WHERE user_id = ?
                ");

                if ($upd) {
                    $upd->bind_param(
                        'siisi',
                        $leaderAnswer,
                        $emailAccepted,
                        $termsAccepted,
                        $preferencesJson,
                        $userId
                    );
                    if ($upd->execute() && $upd->affected_rows > 0) {
                        $updated = true;
                    }
                    $upd->close();
                }

                if (!$updated) {
                    $ins = $conn->prepare("
                        INSERT INTO ba_onboarding (
                            user_id,
                            leader_confirmed,
                            email_communication_accepted,
                            terms_accepted,
                            preferences,
                            completed,
                            completed_at
                        ) VALUES (?, ?, ?, ?, ?, 1, NOW())
                    ");

                    if (!$ins) {
                        error_log('BA onboarding INSERT prepare: ' . $conn->error);
                        $error = 'Could not save onboarding (table error). Check ba_onboarding exists. DB: ' . $conn->error;
                    } else {
                        $ins->bind_param(
                            'isiis',
                            $userId,
                            $leaderAnswer,
                            $emailAccepted,
                            $termsAccepted,
                            $preferencesJson
                        );

                        if (!$ins->execute()) {
                            error_log('BA onboarding INSERT: ' . $ins->error);
                            $error = 'Could not save onboarding. DB: ' . $ins->error;
                            $ins->close();
                        } else {
                            $ins->close();
                            $updated = true;
                        }
                    }
                }

                if ($error === '' && $updated) {
                    session_regenerate_id(true);

                    $_SESSION['ba_authenticated'] = true;
                    $_SESSION['ba_user_id']       = (int) $currentUser['user_id'];
                    $_SESSION['ba_email']         = $currentUser['email'];
                    $_SESSION['ba_name']          = $currentUser['name'] ?? '';
                    $_SESSION['ba_department']    = $currentUser['department'] ?? '';
                    $_SESSION['ba_developer']     = false;

                    $_SESSION['ba_onboarding'] = [
                        'leader_confirmed'  => $leaderAnswer,
                        'interests'         => $interests,
                        'notifications'     => $notifications,
                        'notifications_all' => $notifAll,
                        'interests_all'     => $interestAll,
                    ];

                    unset(
                        $_SESSION['ba_pending_user_id'],
                        $_SESSION['ba_pending_email'],
                        $_SESSION['ba_pending_name'],
                        $_SESSION['ba_pending_started'],
                        $_SESSION['ba_onboarding_csrf']
                    );

                    header('Location: dashboard.php');
                    exit;
                }
            }

            // Keep form values on error
            $notificationsAllChoice = $notifAll;
            $interestsAllChoice     = $interestAll;
            $selectedNotifications  = ($notifAll === 'yes') ? $allNotifications : [];
            $selectedInterests      = ($interestAll === 'yes') ? $allInterests : [];
            $leaderConfirmed        = $leaderAnswer !== '' ? $leaderAnswer : $leaderConfirmed;
        }
    }
}

$profilePicture  = trim($user['profile_picture'] ?? '');
$profileImageUrl = $profilePicture !== '' ? $profilePicture : '';
$userInitial     = strtoupper(substr(trim($user['name'] ?? 'U'), 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
    content="width=device-width, initial-scale=1.0"
    >
    <title>Welcome to TidyTrack | BA Portal</title>
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" >
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
       :root {
  --ink: #172d3a;
  --muted: #586975;
  --teal: #176e70;
  --teal-dark: #115c5e;
  --line: #d9e0de;
  --soft: #f6f7f4;
  --white: #fff;
  --danger: #a33535;
  --success: #15803d;
  --radius: 14px;
}

* { box-sizing: border-box; }
body {
  margin: 0;
  font-family: "DM Sans", Inter, system-ui, sans-serif;
  color: var(--ink);
  background: var(--soft);
  line-height: 1.55;
}
button, input { font: inherit; }

.topbar {
  min-height: 72px;
  padding: 14px 6%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  background: var(--white);
  border-bottom: 1px solid var(--line);
}
.brand {
  display: flex;
  align-items: center;
  gap: 12px;
  font-weight: 800;
  font-size: 18px;
  letter-spacing: -0.03em;
}
.brand-mark {
  width: 40px; height: 40px;
  border-radius: 10px;
  overflow: hidden;
  background: var(--teal);
  color: #fff;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}
.brand-mark img { width: 100%; height: 100%; object-fit: contain; }
.brand small {
  display: block;
  color: var(--muted);
  font-size: 11px;
  font-weight: 600;
  margin-top: 2px;
}
.secure-label {
  color: var(--muted);
  font-size: 12px;
  font-weight: 600;
}
.secure-label i { color: var(--success); margin-right: 6px; }

.page {
  width: min(920px, 100%);
  margin: 0 auto;
  padding: 28px 18px 56px;
}

.intro {
  text-align: center;
  max-width: 640px;
  margin: 0 auto 28px;
}
.eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--teal);
  background: #edf5f3;
  border: 1px solid #d5e8e5;
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
h1 {
  margin: 14px 0 10px;
  font-size: clamp(26px, 5vw, 38px);
  line-height: 1.15;
  letter-spacing: -0.04em;
  font-weight: 800;
}
.intro p { margin: 0; color: var(--muted); font-size: 15px; }

.progress-track {
  width: min(360px, 100%);
  height: 6px;
  border-radius: 99px;
  background: #e4ebe9;
  margin: 22px auto 0;
  overflow: hidden;
}
.progress-fill {
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, var(--teal), #2a9d8f);
}

.alert {
  padding: 13px 15px;
  margin-bottom: 16px;
  border-radius: 12px;
  font-size: 14px;
}
.alert-error {
  background: #fef2f2;
  color: #991b1b;
  border: 1px solid #fecaca;
}
.alert-success {
  background: #f0fdf4;
  color: #166534;
  border: 1px solid #bbf7d0;
}

.layout {
  display: grid;
  gap: 16px;
}

.card {
  background: var(--white);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 22px;
  box-shadow: 0 10px 28px rgba(23, 45, 58, 0.04);
}

.card-heading {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 18px;
}
.section-icon {
  width: 40px; height: 40px;
  border-radius: 11px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  color: var(--teal);
  background: #edf5f3;
}
.card h2 {
  margin: 0 0 4px;
  font-size: 17px;
  font-weight: 800;
  letter-spacing: -0.02em;
}
.card-heading p {
  margin: 0;
  color: var(--muted);
  font-size: 13px;
}

.profile-layout {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 18px;
}
.avatar {
  width: 84px; height: 84px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid #dfecea;
  background: #eef3f2;
  flex-shrink: 0;
}
.avatar-placeholder {
  display: grid;
  place-items: center;
  color: var(--teal);
  font-size: 30px;
  font-weight: 800;
}
.profile-name { margin: 0; font-size: 18px; font-weight: 800; }
.profile-role { color: var(--muted); font-size: 13px; margin-top: 2px; }

.details-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.detail {
  padding: 14px;
  background: var(--soft);
  border: 1px solid var(--line);
  border-radius: 12px;
  min-width: 0;
}
.detail-label {
  display: block;
  color: var(--muted);
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 4px;
}
.detail-value {
  font-size: 14px;
  font-weight: 700;
  overflow-wrap: anywhere;
}
.detail-value a { color: inherit; text-decoration: none; }

.leader-box {
  display: flex;
  gap: 14px;
  align-items: center;
  padding: 16px;
  background: var(--soft);
  border: 1px solid var(--line);
  border-radius: 14px;
}
.leader-avatar {
  width: 68px; height: 68px;
  border-radius: 50%;
  object-fit: cover;
  background: #eef3f2;
  flex-shrink: 0;
}
.leader-name { margin: 0; font-size: 16px; font-weight: 800; }
.leader-meta {
  color: var(--muted);
  font-size: 13px;
  margin-top: 4px;
  overflow-wrap: anywhere;
}
.leader-meta i { width: 16px; color: var(--teal); margin-right: 4px; }
.leader-meta a { color: inherit; text-decoration: none; }

.question {
  margin: 18px 0 10px;
  font-weight: 700;
  font-size: 14px;
}

.radio-options {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.radio-option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 14px;
  border: 1px solid var(--line);
  border-radius: 12px;
  cursor: pointer;
  font-size: 14px;
  background: #fff;
  transition: border-color .15s, background .15s;
}
.radio-option:has(input:checked) {
  border-color: var(--teal);
  background: #edf5f3;
}
input[type="radio"],
input[type="checkbox"] {
  accent-color: var(--teal);
}

.notice {
  padding: 14px;
  border-radius: 12px;
  background: #f0f6f6;
  color: #355663;
  border: 1px solid #c8dddc;
  font-size: 13px;
}
.notice i { color: var(--teal); margin-right: 6px; }

/* Yes / No preference cards */
.choice-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.choice-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 16px;
  border: 1px solid var(--line);
  border-radius: 12px;
  cursor: pointer;
  background: #fff;
  transition: border-color .15s, background .15s, box-shadow .15s;
}
.choice-card:has(input:checked) {
  border-color: var(--teal);
  background: #edf5f3;
  box-shadow: 0 0 0 3px rgba(23, 110, 112, 0.12);
}
.choice-card strong {
  font-size: 14px;
  font-weight: 800;
}
.choice-card small {
  color: var(--muted);
  font-size: 12px;
  line-height: 1.4;
}
.choice-card input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.projects-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}
.projects-count {
  font-size: 12px;
  color: var(--muted);
  background: var(--soft);
  border: 1px solid var(--line);
  border-radius: 999px;
  padding: 6px 10px;
  white-space: nowrap;
}

.table-search {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--line);
  border-radius: 10px;
  margin-bottom: 12px;
  outline: none;
  background: #fff;
  font-size: 16px;
}
.table-search:focus {
  border-color: var(--teal);
  box-shadow: 0 0 0 3px rgba(23, 110, 112, 0.12);
}

.table-wrap {
  width: 100%;
  overflow-x: auto;
  border: 1px solid var(--line);
  border-radius: 12px;
  -webkit-overflow-scrolling: touch;
}
table {
  border-collapse: collapse;
  width: 100%;
  min-width: 640px;
  font-size: 13px;
}
th, td {
  padding: 12px 14px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--line);
}
th {
  background: var(--soft);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
}
.project-name { font-weight: 800; }
.muted { color: var(--muted); }

.terms {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  font-size: 13px;
  color: #3d4f57;
  padding: 14px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: #fff;
}
.terms input { margin-top: 3px; flex-shrink: 0; }

.actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
}
.primary-button {
  border: 0;
  border-radius: 10px;
  padding: 14px 22px;
  background: var(--teal);
  color: #fff;
  font-weight: 800;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: background .18s, transform .15s;
}
.primary-button:hover { background: var(--teal-dark); }
.primary-button:disabled { opacity: .65; cursor: not-allowed; }

.secondary-button {
  margin-top: 10px;
  padding: 9px 12px;
  border: 1px solid var(--line);
  background: #fff;
  border-radius: 9px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 700;
  color: var(--teal);
}
.secondary-button:hover { background: #edf5f3; }

.empty-state {
  text-align: center;
  padding: 24px;
  color: var(--muted);
}
.footer {
  text-align: center;
  padding: 22px 15px;
  color: var(--muted);
  font-size: 12px;
  border-top: 1px solid var(--line);
  background: #fff;
}

/* Mobile */
@media (max-width: 700px) {
  .topbar { padding: 12px 16px; }
  .page { padding: 20px 14px 40px; }
  .card { padding: 16px; border-radius: 12px; }
  .details-grid,
  .radio-options,
  .choice-grid {
    grid-template-columns: 1fr;
  }
  .profile-layout {
    flex-direction: column;
    align-items: flex-start;
  }
  .avatar { width: 72px; height: 72px; }
  .leader-box {
    flex-direction: column;
    align-items: flex-start;
  }
  .actions,
  .primary-button { width: 100%; }
  .primary-button { justify-content: center; }
  .projects-header {
    flex-direction: column;
    align-items: flex-start;
  }
  h1 { font-size: 26px; }
}
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">
        <div class="brand-mark">
            <img
                src="assets/images/logo.png"
                alt="TidyTrack logo"
                onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=&quot;fa-solid fa-layer-group&quot;></i>';"
            >
        </div>
        <div>
            TidyTrack
            <small>Business Analyst Portal</small>
        </div>
    </div>
    <div class="secure-label">
        <i class="fa-solid fa-shield-halved"></i>
        Secure onboarding
    </div>
</header>

<main class="page">

    <section class="intro">
        <span class="eyebrow">
            <i class="fa-solid fa-sparkles"></i>
            Welcome aboard
        </span>
        <h1>
            Welcome,
            <?= baEscape($user['name'] ?: 'BA Team member') ?>!
        </h1>
        <p>
            Confirm your details, meet your team leader,
            and finish setting up your workspace.
        </p>
        <div class="progress-track">
            <div class="progress-fill"></div>
        </div>
    </section>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= baEscape($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <?= baEscape($success) ?>
        </div>
    <?php endif; ?>

    <form
        method="POST"
        action="verify.php"
        enctype="multipart/form-data"
        id="onboardingForm"
        novalidate
    >
        <input type="hidden" name="csrf_token" value="<?= baEscape($csrfToken) ?>">
        <!-- Ensures complete action is always posted (even if button is disabled) -->
        <input type="hidden" name="complete_onboarding" id="completeFlag" value="">

        <div class="layout">

            <!-- PROFILE -->
            <section class="card">
                <div class="card-heading">
                    <div class="section-icon"><i class="fa-solid fa-user"></i></div>
                    <div>
                        <h2>Your profile</h2>
                        <p>Check that your account information is correct.</p>
                    </div>
                </div>

                <div class="profile-layout">
                    <?php if ($profileImageUrl !== ''): ?>
                        <img
                            class="avatar"
                            src="<?= baEscape($profileImageUrl) ?>"
                            alt="Your profile picture"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='grid';"
                        >
                        <div class="avatar avatar-placeholder" style="display:none;">
                            <?= baEscape($userInitial) ?>
                        </div>
                    <?php else: ?>
                        <div class="avatar avatar-placeholder">
                            <?= baEscape($userInitial) ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <p class="profile-name"><?= baEscape($user['name']) ?></p>
                        <div class="profile-role">
                            <?= baEscape($user['role'] ?: 'BA Team member') ?>
                            ·
                            <?= baEscape($user['department']) ?>
                        </div>
                        <button type="button" class="secondary-button" id="openPictureBtn">
                            <i class="fa-solid fa-camera"></i>
                            Update profile picture
                        </button>
                    </div>
                </div>

                <div class="details-grid">
                    <div class="detail">
                        <span class="detail-label">Full name</span>
                        <div class="detail-value"><?= baEscape($user['name']) ?></div>
                    </div>
                    <div class="detail">
                        <span class="detail-label">Email address</span>
                        <div class="detail-value"><?= baEscape($user['email']) ?></div>
                    </div>
                    <div class="detail">
                        <span class="detail-label">Contact number</span>
                        <div class="detail-value">
                            <?php if (!empty($user['phone'])): ?>
                                <a href="tel:<?= baEscape($user['phone']) ?>"><?= baEscape($user['phone']) ?></a>
                            <?php else: ?>
                                Not provided
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="detail">
                        <span class="detail-label">Department</span>
                        <div class="detail-value"><?= baEscape($user['department']) ?></div>
                    </div>
                </div>
            </section>

            <!-- TEAM LEADER -->
            <section class="card">
                <div class="card-heading">
                    <div class="section-icon"><i class="fa-solid fa-people-group"></i></div>
                    <div>
                        <h2>Your BA team leader</h2>
                        <p>From the leader assigned to the BA project in TidyTrack.</p>
                    </div>
                </div>

                <?php if ($leader): ?>
                    <div class="leader-box">
                        <?php if (!empty($leader['profile_picture'])): ?>
                            <img
                                class="leader-avatar"
                                src="<?= baEscape($leader['profile_picture']) ?>"
                                alt="Team leader"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='grid';"
                            >
                            <div class="leader-avatar avatar-placeholder" style="display:none;">
                                <?= baEscape(strtoupper(substr($leader['name'] ?? 'L', 0, 1))) ?>
                            </div>
                        <?php else: ?>
                            <div class="leader-avatar avatar-placeholder">
                                <?= baEscape(strtoupper(substr($leader['name'] ?? 'L', 0, 1))) ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <p class="leader-name"><?= baEscape($leader['name'] ?? 'Team leader') ?></p>
                            <div class="leader-meta">
                                <i class="fa-solid fa-envelope"></i>
                                <?php if (!empty($leader['email'])): ?>
                                    <a href="mailto:<?= baEscape($leader['email']) ?>"><?= baEscape($leader['email']) ?></a>
                                <?php else: ?>
                                    Email not available
                                <?php endif; ?>
                            </div>
                            <div class="leader-meta">
                                <i class="fa-solid fa-phone"></i>
                                <?php if (!empty($leader['phone'])): ?>
                                    <a href="tel:<?= baEscape($leader['phone']) ?>"><?= baEscape($leader['phone']) ?></a>
                                <?php else: ?>
                                    Contact number not available
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <p class="question">Is this the correct leader for your BA team?</p>
                    <div class="radio-options">
                        <label class="radio-option">
                            <input type="radio" name="leader_confirmed" value="yes"
                                <?= $leaderConfirmed === 'yes' ? 'checked' : '' ?> required>
                            <span><strong>Yes, that's correct</strong></span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="leader_confirmed" value="no"
                                <?= $leaderConfirmed === 'no' ? 'checked' : '' ?> required>
                            <span><strong>No, I need assistance</strong></span>
                        </label>
                    </div>
                    <p class="muted" style="font-size:12px;margin-top:10px;">
                        If you select No, we save that so the leader assignment can be checked.
                    </p>
                <?php else: ?>
                    <div class="notice">
                        <i class="fa-solid fa-circle-info"></i>
                        The BA project leader could not be found.
                        You can still continue — please confirm with your administrator.
                    </div>
                    <p class="question">Have you confirmed your team leader?</p>
                    <div class="radio-options">
                        <label class="radio-option">
                            <input type="radio" name="leader_confirmed" value="yes" required>
                            Yes, I have confirmed my leader.
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="leader_confirmed" value="no" required>
                            No, I need assistance.
                        </label>
                    </div>
                <?php endif; ?>
            </section>

            <!-- PICTURE UPLOAD -->
            <section class="card" id="pictureUploadSection" style="display:none;">
                <div class="card-heading">
                    <div class="section-icon"><i class="fa-solid fa-camera"></i></div>
                    <div>
                        <h2>Update your picture</h2>
                        <p>JPG, PNG or WebP — max 3 MB.</p>
                    </div>
                </div>
                <input
                    type="file"
                    id="pictureInput"
                    name="profile_picture"
                    accept="image/jpeg,image/png,image/webp"
                >
                <div class="actions">
                    <button
                        class="primary-button"
                        type="submit"
                        name="upload_picture"
                        value="1"
                        id="uploadPictureBtn"
                    >
                        <i class="fa-solid fa-upload"></i>
                        Upload picture
                    </button>
                </div>
            </section>

            <!-- PROJECTS -->
            <section class="card">
                <div class="projects-header">
                    <div class="card-heading" style="margin:0;">
                        <div class="section-icon"><i class="fa-solid fa-diagram-project"></i></div>
                        <div>
                            <h2>Explore TidyTrack projects</h2>
                            <p>Current projects and their leaders.</p>
                        </div>
                    </div>
                    <span class="projects-count"><?= count($projects) ?> projects</span>
                </div>

                <input
                    type="search"
                    id="projectSearch"
                    class="table-search"
                    placeholder="Search projects or leaders..."
                    aria-label="Search projects"
                >

                <?php if (!empty($projects)): ?>
                    <div class="table-wrap">
                        <table id="projectsTable">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Team leader</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Deliverable</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projects as $project): ?>
                                    <tr>
                                        <td class="project-name"><?= baEscape($project['project_name']) ?></td>
                                        <td><?= baEscape($project['leader_name'] ?: 'Not assigned') ?></td>
                                        <td><?= baEscape($project['start_date'] ?: '—') ?></td>
                                        <td><?= baEscape($project['end_date'] ?: '—') ?></td>
                                        <td><?= baEscape($project['deliverable'] ?: 'No description') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p id="noProjectsFound" class="empty-state" style="display:none;">
                        No matching projects were found.
                    </p>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-folder-open"></i>
                        <p>No active projects were found.</p>
                    </div>
                <?php endif; ?>
            </section>

            <!-- STAY INFORMED -->
            <section class="card">
                <div class="card-heading">
                    <div class="section-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
                    <div>
                        <h2>Stay informed</h2>
                        <p>Choose how you want to receive updates.</p>
                    </div>
                </div>

                <div class="notice">
                    <i class="fa-solid fa-circle-info"></i>
                    Your email <strong><?= baEscape($user['email']) ?></strong>
                    may be used for project updates, team announcements,
                    task notices and important portal messages.
                </div>

                <p class="question">Receive all email updates?</p>
                <div class="choice-grid">
                    <label class="choice-card">
                        <input type="radio" name="notifications_all" value="yes" required
                            <?= ($notificationsAllChoice === 'yes' || ($notificationsAllChoice === '' && !empty($selectedNotifications))) ? 'checked' : '' ?>>
                        <strong><i class="fa-solid fa-check"></i> Yes, all updates</strong>
                        <small>Project, team, task and important notices.</small>
                    </label>
                    <label class="choice-card">
                        <input type="radio" name="notifications_all" value="no"
                            <?= ($notificationsAllChoice === 'no') ? 'checked' : '' ?>>
                        <strong><i class="fa-solid fa-xmark"></i> No, none</strong>
                        <small>Skip optional email notifications for now.</small>
                    </label>
                </div>

                <p class="question">Interested in all BA focus areas?</p>
                <div class="choice-grid">
                    <label class="choice-card">
                        <input type="radio" name="interests_all" value="yes" required
                            <?= ($interestsAllChoice === 'yes' || ($interestsAllChoice === '' && !empty($selectedInterests))) ? 'checked' : '' ?>>
                        <strong><i class="fa-solid fa-check"></i> Yes, all areas</strong>
                        <small>Analysis, management, monitoring, reports, collaboration.</small>
                    </label>
                    <label class="choice-card">
                        <input type="radio" name="interests_all" value="no"
                            <?= ($interestsAllChoice === 'no') ? 'checked' : '' ?>>
                        <strong><i class="fa-solid fa-xmark"></i> No preference</strong>
                        <small>You can refine this later.</small>
                    </label>
                </div>
            </section>

            <!-- TERMS -->
            <section class="card">
                <div class="card-heading">
                    <div class="section-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <div>
                        <h2>Before you continue</h2>
                        <p>Please review and accept the following.</p>
                    </div>
                </div>

                <label class="terms">
                    <input type="checkbox" name="email_communication" value="1" required>
                    <span>
                        I acknowledge that my registered email may be used for
                        official TidyTrack work-related communications.
                    </span>
                </label>

                <div style="height:12px;"></div>

                <label class="terms">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    <span>
                        I confirm that I have reviewed my details and understand
                        that portal access depends on my assigned responsibilities.
                        I agree to use TidyTrack responsibly and keep information confidential.
                    </span>
                </label>

                <div class="actions">
                    <button
                        type="submit"
                        class="primary-button"
                        id="completeButton"
                    >
                        Complete setup
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </div>
            </section>

        </div>
    </form>
</main>

<footer class="footer">
    TidyTrack Business Analyst Portal
    &copy; <?= date('Y') ?>.
    All rights reserved.
</footer>

<script>
(function () {
    const form = document.getElementById('onboardingForm');
    const completeButton = document.getElementById('completeButton');
    const completeFlag = document.getElementById('completeFlag');
    const projectSearch = document.getElementById('projectSearch');
    const projectsTable = document.getElementById('projectsTable');
    const noProjectsFound = document.getElementById('noProjectsFound');
    const pictureInput = document.getElementById('pictureInput');
    const pictureUploadSection = document.getElementById('pictureUploadSection');
    const openPictureBtn = document.getElementById('openPictureBtn');

    if (projectSearch && projectsTable) {
        projectSearch.addEventListener('input', function () {
            const term = this.value.toLowerCase().trim();
            const rows = projectsTable.querySelectorAll('tbody tr');
            let visible = 0;
            rows.forEach(function (row) {
                const match = row.textContent.toLowerCase().includes(term);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (noProjectsFound) noProjectsFound.style.display = visible === 0 ? 'block' : 'none';
            projectsTable.style.display = visible === 0 ? 'none' : 'table';
        });
    }

    if (openPictureBtn && pictureUploadSection) {
        openPictureBtn.addEventListener('click', function () {
            pictureUploadSection.style.display = 'block';
            pictureUploadSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            if (pictureInput) pictureInput.click();
        });
    }

    if (pictureInput && pictureUploadSection) {
        pictureInput.addEventListener('change', function () {
            if (this.files.length > 0) pictureUploadSection.style.display = 'block';
        });
    }

    function showFieldError(msg) {
        let box = document.querySelector('.alert-error');
        if (!box) {
            box = document.createElement('div');
            box.className = 'alert alert-error';
            box.setAttribute('role', 'alert');
            const intro = document.querySelector('.intro');
            if (intro && intro.parentNode) {
                intro.parentNode.insertBefore(box, intro.nextSibling);
            }
        }
        box.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + msg;
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (form && completeButton && completeFlag) {
        form.addEventListener('submit', function (event) {
            const submitter = event.submitter;

            // Picture upload — clear complete flag
            if (submitter && submitter.name === 'upload_picture') {
                completeFlag.value = '';
                return;
            }

            // Complete setup
            completeFlag.value = '1';

            const leader = form.querySelector('input[name="leader_confirmed"]:checked');
            const notif = form.querySelector('input[name="notifications_all"]:checked');
            const interest = form.querySelector('input[name="interests_all"]:checked');
            const emailAck = form.querySelector('input[name="email_communication"]');
            const terms = form.querySelector('input[name="accept_terms"]');

            if (!leader) {
                event.preventDefault();
                showFieldError('Please confirm your team leader (Yes or No).');
                return;
            }
            if (!notif) {
                event.preventDefault();
                showFieldError('Please choose Yes or No for email updates.');
                return;
            }
            if (!interest) {
                event.preventDefault();
                showFieldError('Please choose Yes or No for interest areas.');
                return;
            }
            if (!emailAck || !emailAck.checked) {
                event.preventDefault();
                showFieldError('Please acknowledge the email communication notice.');
                return;
            }
            if (!terms || !terms.checked) {
                event.preventDefault();
                showFieldError('You must accept the terms to continue.');
                return;
            }

            // Do NOT disable before submit — flag is already set
            completeButton.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin"></i> Saving your setup...';
            completeButton.style.pointerEvents = 'none';
            completeButton.style.opacity = '0.75';
        });
    }
})();
</script>

</body>
</html>