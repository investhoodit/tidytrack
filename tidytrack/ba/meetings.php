<?php
session_start();
require_once '../dp.php';

/* =========================================================
   BA ACCESS CONTROL
========================================================= */

if (empty($_SESSION['ba_authenticated']) ||
    empty($_SESSION['ba_user_id'])) {
    header('Location: index.php');
    exit;
}

$currentUserId = (int) $_SESSION['ba_user_id'];

$authStmt = $conn->prepare("
    SELECT user_id, name, email, department
    FROM users
    WHERE user_id = ?
      AND LOWER(TRIM(department)) = 'ba team'
    LIMIT 1
");

$authStmt->bind_param('i', $currentUserId);
$authStmt->execute();
$currentUser = $authStmt->get_result()->fetch_assoc();
$authStmt->close();

if (!$currentUser) {
    session_destroy();
    header('Location: index.php');
    exit;
}

/* =========================================================
   HELPERS
========================================================= */

function meetingEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function meetingRedirect($message, $type = 'success')
{
    $_SESSION['meeting_flash'] = [
        'message' => $message,
        'type' => $type
    ];

    header('Location: meetings.php');
    exit;
}

function validMeetingDate($date)
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}

function validMeetingTime($time)
{
    $parsed = DateTime::createFromFormat('!H:i', $time);

    return $parsed && $parsed->format('H:i') === $time;
}

/* =========================================================
   CSRF TOKEN
========================================================= */

if (empty($_SESSION['meetings_csrf'])) {
    $_SESSION['meetings_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['meetings_csrf'];

/* =========================================================
   LOAD USERS
========================================================= */

$users = [];
$departments = [];

$userResult = $conn->query("
    SELECT user_id, name, email, department, role
    FROM users
    WHERE email IS NOT NULL
      AND TRIM(email) <> ''
    ORDER BY department ASC, name ASC
");

if ($userResult) {
    while ($row = $userResult->fetch_assoc()) {
        $users[] = $row;

        $department = trim($row['department'] ?? '');

        if ($department !== '') {
            $departments[$department] = $department;
        }
    }
}

natcasesort($departments);

/* =========================================================
   LOAD MEETING FOR EDITING
========================================================= */

$editMeeting = null;
$editAttendees = [];

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if ($editId) {
    $stmt = $conn->prepare("
        SELECT *
        FROM ba_meetings
        WHERE meeting_id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editMeeting = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($editMeeting) {
        $stmt = $conn->prepare("
            SELECT user_id
            FROM ba_meeting_attendees
            WHERE meeting_id = ?
        ");

        $stmt->bind_param('i', $editId);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $editAttendees[] = (int) $row['user_id'];
        }

        $stmt->close();
    }
}

/* =========================================================
   HANDLE FORM SUBMISSIONS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['meetings_csrf']) ||
        !hash_equals($_SESSION['meetings_csrf'], $postedToken)
    ) {
        meetingRedirect(
            'Your session expired. Please try again.',
            'error'
        );
    }

    $action = $_POST['action'] ?? '';

    /* -----------------------------------------------------
       DELETE MEETING
    ----------------------------------------------------- */

    if ($action === 'delete') {

        $meetingId = (int) ($_POST['meeting_id'] ?? 0);

        if ($meetingId < 1) {
            meetingRedirect('Invalid meeting.', 'error');
        }

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                DELETE FROM ba_meeting_attendees
                WHERE meeting_id = ?
            ");

            $stmt->bind_param('i', $meetingId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                DELETE FROM ba_meetings
                WHERE meeting_id = ?
            ");

            $stmt->bind_param('i', $meetingId);
            $stmt->execute();

            $deleted = $stmt->affected_rows > 0;
            $stmt->close();

            $conn->commit();

            meetingRedirect(
                $deleted
                    ? 'Meeting deleted successfully.'
                    : 'Meeting was not found.',
                $deleted ? 'success' : 'error'
            );

        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Meeting deletion failed: ' . $e->getMessage());

            meetingRedirect(
                'Could not delete the meeting.',
                'error'
            );
        }
    }

    /* -----------------------------------------------------
       UPDATE ATTENDANCE
    ----------------------------------------------------- */

    if ($action === 'attendance') {

        $meetingId = (int) ($_POST['meeting_id'] ?? 0);
        $attendeeId = (int) ($_POST['attendee_id'] ?? 0);
        $attendance = $_POST['attendance_status'] ?? '';

        $allowedAttendance = [
            'Pending',
            'Attended',
            'Absent',
            'Excused'
        ];

        if (
            $meetingId < 1 ||
            $attendeeId < 1 ||
            !in_array($attendance, $allowedAttendance, true)
        ) {
            meetingRedirect('Invalid attendance details.', 'error');
        }

        $stmt = $conn->prepare("
            UPDATE ba_meeting_attendees
            SET attendance_status = ?
            WHERE meeting_id = ?
              AND user_id = ?
        ");

        $stmt->bind_param(
            'sii',
            $attendance,
            $meetingId,
            $attendeeId
        );

        $stmt->execute();
        $stmt->close();

        meetingRedirect('Attendance updated.');
    }

    /* -----------------------------------------------------
       CREATE OR UPDATE MEETING
    ----------------------------------------------------- */

    if ($action === 'save') {

        $meetingId = (int) ($_POST['meeting_id'] ?? 0);

        $title = trim($_POST['title'] ?? '');
        $agenda = trim($_POST['agenda'] ?? '');
        $meetingDate = trim($_POST['meeting_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $meetingLink = trim($_POST['meeting_link'] ?? '');
        $status = $_POST['status'] ?? 'Upcoming';
        $meetingNotes = trim($_POST['meeting_notes'] ?? '');
        $actionItems = trim($_POST['action_items'] ?? '');

        $allowedStatuses = [
            'Upcoming',
            'In Progress',
            'Completed',
            'Cancelled'
        ];

        if (
            $title === '' ||
            strlen($title) > 255 ||
            !validMeetingDate($meetingDate) ||
            !validMeetingTime($startTime) ||
            !validMeetingTime($endTime) ||
            $endTime <= $startTime ||
            !in_array($status, $allowedStatuses, true)
        ) {
            meetingRedirect(
                'Check the title, date, times and meeting status. The end time must be after the start time.',
                'error'
            );
        }

        if (strlen($location) > 255) {
            meetingRedirect(
                'The meeting location is too long.',
                'error'
            );
        }

        if (
            $meetingLink !== '' &&
            (
                !filter_var($meetingLink, FILTER_VALIDATE_URL) ||
                !in_array(
                    strtolower(parse_url($meetingLink, PHP_URL_SCHEME) ?? ''),
                    ['http', 'https'],
                    true
                )
            )
        ) {
            meetingRedirect(
                'Enter a valid meeting URL beginning with https://.',
                'error'
            );
        }

        /*
         * Validate attendees against actual database users.
         */

        $mode = $_POST['recipient_mode'] ?? 'selected';
        $recipientIds = [];

        $validUsers = [];

        foreach ($users as $user) {
            $id = (int) $user['user_id'];

            $validUsers[$id] = $user;
        }

        if ($mode === 'all') {

            $recipientIds = array_keys($validUsers);

        } elseif ($mode === 'department') {

            $department = trim($_POST['department'] ?? '');

            if ($department === '') {
                meetingRedirect(
                    'Select a department for the invitations.',
                    'error'
                );
            }

            foreach ($validUsers as $id => $user) {
                if (
                    strcasecmp(
                        trim($user['department'] ?? ''),
                        $department
                    ) === 0
                ) {
                    $recipientIds[] = $id;
                }
            }

        } else {

            $postedRecipients = $_POST['attendees'] ?? [];

            if (!is_array($postedRecipients)) {
                meetingRedirect(
                    'Invalid attendee selection.',
                    'error'
                );
            }

            foreach ($postedRecipients as $id) {
                $id = filter_var($id, FILTER_VALIDATE_INT);

                if ($id !== false && isset($validUsers[$id])) {
                    $recipientIds[] = (int) $id;
                }
            }
        }

        $recipientIds = array_values(
            array_unique($recipientIds)
        );

        if (empty($recipientIds)) {
            meetingRedirect(
                'Select at least one meeting attendee.',
                'error'
            );
        }

        if (count($recipientIds) > 200) {
            meetingRedirect(
                'A meeting can have a maximum of 200 attendees.',
                'error'
            );
        }

        $conn->begin_transaction();

        try {

            if ($meetingId > 0) {

                $stmt = $conn->prepare("
                    UPDATE ba_meetings
                    SET
                        title = ?,
                        agenda = ?,
                        meeting_date = ?,
                        start_time = ?,
                        end_time = ?,
                        location = ?,
                        meeting_link = ?,
                        status = ?,
                        meeting_notes = ?,
                        action_items = ?
                    WHERE meeting_id = ?
                ");

                $stmt->bind_param(
                    'ssssssssssi',
                    $title,
                    $agenda,
                    $meetingDate,
                    $startTime,
                    $endTime,
                    $location,
                    $meetingLink,
                    $status,
                    $meetingNotes,
                    $actionItems,
                    $meetingId
                );

                $stmt->execute();
                $stmt->close();

                /*
                 * Update attendee list:
                 * remove old invitations and insert the new list.
                 * Existing attendance records are reset for this
                 * meeting when the attendee list is changed.
                 */

                $stmt = $conn->prepare("
                    DELETE FROM ba_meeting_attendees
                    WHERE meeting_id = ?
                ");

                $stmt->bind_param('i', $meetingId);
                $stmt->execute();
                $stmt->close();

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO ba_meetings (
                        title,
                        agenda,
                        meeting_date,
                        start_time,
                        end_time,
                        location,
                        meeting_link,
                        organiser_id,
                        status,
                        meeting_notes,
                        action_items
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    'sssssssisss',
                    $title,
                    $agenda,
                    $meetingDate,
                    $startTime,
                    $endTime,
                    $location,
                    $meetingLink,
                    $currentUserId,
                    $status,
                    $meetingNotes,
                    $actionItems
                );

                $stmt->execute();
                $meetingId = (int) $stmt->insert_id;
                $stmt->close();
            }

            $attendeeStmt = $conn->prepare("
                INSERT INTO ba_meeting_attendees (
                    meeting_id,
                    user_id,
                    attendance_status,
                    invitation_status
                ) VALUES (?, ?, 'Pending', 'Pending')
            ");

            foreach ($recipientIds as $recipientId) {
                $attendeeStmt->bind_param(
                    'ii',
                    $meetingId,
                    $recipientId
                );

                $attendeeStmt->execute();
            }

            $attendeeStmt->close();

            $conn->commit();

            /*
             * GOOGLE MEET API INTEGRATION PLACEHOLDER
             * ------------------------------------------------
             * The meeting_link field is already saved.
             *
             * Later, your Google Meet integration can:
             * 1. Authenticate with Google.
             * 2. Create a Google Calendar event or Meet conference.
             * 3. Save the returned Meet URL in meeting_link.
             * 4. Send invitations using your email service.
             *
             * API credentials should be stored outside public files.
             */

            meetingRedirect(
                $action === 'save'
                    ? 'Meeting saved successfully. Email invitations are not sent yet.'
                    : 'Meeting saved successfully.'
            );

        } catch (Throwable $e) {
            $conn->rollback();

            error_log(
                'Meeting save failed: ' . $e->getMessage()
            );

            meetingRedirect(
                'Could not save the meeting. Check the PHP error log.',
                'error'
            );
        }
    }
}

/* =========================================================
   LOAD MEETINGS
========================================================= */

$meetings = [];

$meetingResult = $conn->query("
    SELECT
        m.*,
        u.name AS organiser_name,
        (
            SELECT COUNT(*)
            FROM ba_meeting_attendees a
            WHERE a.meeting_id = m.meeting_id
        ) AS attendee_count,
        (
            SELECT COUNT(*)
            FROM ba_meeting_attendees a
            WHERE a.meeting_id = m.meeting_id
              AND a.attendance_status = 'Attended'
        ) AS attended_count
    FROM ba_meetings m
    LEFT JOIN users u ON u.user_id = m.organiser_id
    ORDER BY
        m.meeting_date DESC,
        m.start_time DESC
");

if ($meetingResult) {
    while ($row = $meetingResult->fetch_assoc()) {
        $meetings[] = $row;
    }
}

/* =========================================================
   MEETING STATISTICS
========================================================= */

$totalMeetings = count($meetings);
$upcomingCount = 0;
$completedCount = 0;
$today = date('Y-m-d');

foreach ($meetings as $meeting) {
    if (
        $meeting['meeting_date'] >= $today &&
        $meeting['status'] === 'Upcoming'
    ) {
        $upcomingCount++;
    }

    if ($meeting['status'] === 'Completed') {
        $completedCount++;
    }
}

$flash = $_SESSION['meeting_flash'] ?? null;
unset($_SESSION['meeting_flash']);

$defaultDate = date('Y-m-d');
$defaultStart = '10:00';
$defaultEnd = '11:00';

$formMeeting = $editMeeting ?: [
    'meeting_id' => 0,
    'title' => '',
    'agenda' => '',
    'meeting_date' => $defaultDate,
    'start_time' => $defaultStart,
    'end_time' => $defaultEnd,
    'location' => '',
    'meeting_link' => '',
    'status' => 'Upcoming',
    'meeting_notes' => '',
    'action_items' => ''
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Meetings | TidyTrack BA Portal</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap"
      rel="stylesheet">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
:root {
    --primary: #0d9488;
    --primary-dark: #0f766e;
    --background: #f8fafc;
    --text: #172b3a;
    --muted: #64748b;
    --border: #e2e8f0;
    --white: #fff;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
}

.topbar {
    background: white;
    border-bottom: 1px solid var(--border);
    min-height: 76px;
    padding: 14px 6%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 700;
    font-size: 19px;
}

.brand img {
    width: 40px;
    height: 40px;
    object-fit: contain;
}

.brand small {
    display: block;
    color: var(--muted);
    font-size: 11px;
    font-weight: 500;
}

.back-button {
    color: var(--text);
    text-decoration: none;
    border: 1px solid var(--border);
    padding: 10px 15px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 600;
}

.page {
    max-width: 1250px;
    margin: auto;
    padding: 35px 6%;
}

.breadcrumb {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 20px;
}

.breadcrumb a {
    color: var(--primary);
    text-decoration: none;
}

.heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

h1 {
    font-size: 28px;
    margin: 0 0 8px;
}

h2 {
    font-size: 18px;
    margin: 0;
}

p {
    color: var(--muted);
}

.heading p {
    margin: 0;
    font-size: 14px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.stat, .card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
}

.stat {
    padding: 20px;
}

.stat span {
    font-size: 13px;
    color: var(--muted);
}

.stat strong {
    display: block;
    font-size: 27px;
    margin-top: 10px;
}

.card {
    padding: 24px;
    margin-bottom: 24px;
}

.card-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.card-heading p {
    font-size: 13px;
    margin: 6px 0 0;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.field {
    margin-bottom: 18px;
}

.field label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 8px;
}

input[type="text"],
input[type="date"],
input[type="time"],
input[type="search"],
select,
textarea {
    width: 100%;
    border: 1px solid var(--border);
    background: white;
    padding: 12px;
    border-radius: 8px;
    font: inherit;
    font-size: 14px;
    color: var(--text);
}

textarea {
    min-height: 105px;
    resize: vertical;
    line-height: 1.6;
}

input:focus, select:focus, textarea:focus {
    outline: 2px solid #99f6e4;
    border-color: var(--primary);
}

.recipient-panel {
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
}

.recipient-toolbar {
    padding: 12px;
    background: #f8fafc;
    border-bottom: 1px solid var(--border);
}

.recipient-list {
    max-height: 230px;
    overflow-y: auto;
}

.recipient {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
}

.recipient:last-child {
    border-bottom: 0;
}

.recipient input {
    accent-color: var(--primary);
}

.recipient-info {
    flex: 1;
    min-width: 0;
}

.recipient-info strong,
.recipient-info span {
    display: block;
    font-size: 13px;
    overflow-wrap: anywhere;
}

.recipient-info span {
    font-size: 12px;
    color: var(--muted);
    margin-top: 3px;
}

.button {
    border: 0;
    border-radius: 8px;
    padding: 11px 15px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.primary {
    background: var(--primary);
    color: white;
}

.primary:hover {
    background: var(--primary-dark);
}

.secondary {
    background: white;
    color: var(--text);
    border: 1px solid var(--border);
}

.danger {
    color: #991b1b;
    background: #fee2e2;
}

.button-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.flash {
    padding: 14px 16px;
    border-radius: 9px;
    font-size: 13px;
    margin-bottom: 20px;
}

.flash.success {
    background: #dcfce7;
    color: #166534;
}

.flash.error {
    background: #fee2e2;
    color: #991b1b;
}

.badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    background: #f1f5f9;
    color: #475569;
}

.badge.Upcoming {
    background: #dbeafe;
    color: #1d4ed8;
}

.badge.Completed {
    background: #dcfce7;
    color: #166534;
}

.badge.Cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.badge.In.Progress {
    background: #fef3c7;
    color: #92400e;
}

.meeting-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 17px;
}

.meeting-card {
    --meeting-colour: #0d9488;
    background: white;
    border: 1px solid var(--border);
    border-left: 5px solid var(--meeting-colour);
    border-radius: 12px;
    padding: 20px;
    min-width: 0;
}

.meeting-card h3 {
    margin: 0 0 8px;
    font-size: 17px;
    overflow-wrap: anywhere;
}

.meeting-card p {
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.meeting-meta {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    margin: 12px 0;
    font-size: 13px;
}

.meeting-meta i {
    color: var(--meeting-colour);
    width: 16px;
    margin-top: 3px;
}

.meeting-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 18px;
    padding-top: 15px;
    border-top: 1px solid var(--border);
}

.meeting-actions .button {
    padding: 9px 11px;
}

.meeting-link {
    color: var(--primary-dark);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    overflow-wrap: anywhere;
}

.filter-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
}

.empty {
    text-align: center;
    color: var(--muted);
    padding: 30px 15px;
    font-size: 13px;
    grid-column: 1 / -1;
}

.integration-note {
    background: #f0fdfa;
    border: 1px solid #99f6e4;
    border-radius: 9px;
    padding: 13px 15px;
    font-size: 12px;
    color: #115e59;
    line-height: 1.6;
    margin-bottom: 20px;
}

footer {
    text-align: center;
    padding: 25px;
    color: var(--muted);
    font-size: 12px;
}

@media (max-width: 750px) {
    .page {
        padding: 25px 4%;
    }

    .topbar {
        padding: 14px 4%;
    }

    .stats,
    .meeting-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }

    .filter-row {
        grid-template-columns: 1fr;
    }

    .card {
        padding: 17px;
    }

    h1 {
        font-size: 24px;
    }
}
</style>
</head>

<body>

<header class="topbar">
    <div class="brand">
        <img src="assets/images/logo.png"
             alt="TidyTrack"
             onerror="this.style.display='none'">
        <div>
            TidyTrack
            <small>Business Analyst Portal</small>
        </div>
    </div>

    <a href="dashboard.php" class="back-button">
        <i class="fa-solid fa-arrow-left"></i>
        Back to Dashboard
    </a>
</header>

<main class="page">

    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        &nbsp; / &nbsp; Meetings
    </div>

    <div class="heading">
        <div>
            <h1>Meeting Management</h1>
            <p>Organise meetings, invite teams and record outcomes.</p>
        </div>

        <a href="#meetingForm" class="button primary">
            <i class="fa-solid fa-plus"></i>
            Schedule Meeting
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= meetingEscape($flash['type']) ?>">
            <?= meetingEscape($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat">
            <span>Total meetings</span>
            <strong><?= $totalMeetings ?></strong>
        </div>

        <div class="stat">
            <span>Upcoming meetings</span>
            <strong><?= $upcomingCount ?></strong>
        </div>

        <div class="stat">
            <span>Completed meetings</span>
            <strong><?= $completedCount ?></strong>
        </div>
    </section>

    <!-- CREATE OR EDIT MEETING -->

    <section class="card" id="meetingForm">

        <div class="card-heading">
            <div>
                <h2>
                    <i class="fa-solid fa-calendar-plus"></i>
                    <?= $editMeeting ? 'Edit Meeting' : 'Schedule a Meeting' ?>
                </h2>
                <p>All authorised BAs can manage meetings created by the BA Team.</p>
            </div>

            <?php if ($editMeeting): ?>
                <a href="meetings.php" class="button secondary">
                    Cancel Editing
                </a>
            <?php endif; ?>
        </div>

        <div class="integration-note">
            <strong><i class="fa-brands fa-google"></i> Google Meet integration</strong><br>
            You can save a Google Meet link below. The API connection can be
            added later to generate meeting links automatically. Email
            invitations are not sent by this version.
        </div>

        <form method="POST" id="meetingFormElement">

            <input type="hidden" name="csrf_token"
                   value="<?= meetingEscape($csrfToken) ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="meeting_id"
                   value="<?= (int) $formMeeting['meeting_id'] ?>">

            <div class="field">
                <label for="title">Meeting Title *</label>
                <input type="text"
                       name="title"
                       id="title"
                       maxlength="255"
                       required
                       value="<?= meetingEscape($formMeeting['title']) ?>"
                       placeholder="e.g. BA Weekly Progress Meeting">
            </div>

            <div class="field">
                <label for="agenda">Agenda</label>
                <textarea name="agenda"
                          id="agenda"
                          placeholder="What will be discussed?"><?= meetingEscape($formMeeting['agenda']) ?></textarea>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="meeting_date">Meeting Date *</label>
                    <input type="date"
                           name="meeting_date"
                           id="meeting_date"
                           required
                           value="<?= meetingEscape($formMeeting['meeting_date']) ?>">
                </div>

                <div class="field">
                    <label for="status">Meeting Status</label>
                    <select name="status" id="status">
                        <?php foreach (
                            ['Upcoming', 'In Progress', 'Completed', 'Cancelled']
                            as $statusOption
                        ): ?>
                            <option
                                value="<?= meetingEscape($statusOption) ?>"
                                <?= $formMeeting['status'] === $statusOption ? 'selected' : '' ?>>
                                <?= meetingEscape($statusOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="start_time">Start Time *</label>
                    <input type="time"
                           name="start_time"
                           id="start_time"
                           required
                           value="<?= meetingEscape(substr($formMeeting['start_time'], 0, 5)) ?>">
                </div>

                <div class="field">
                    <label for="end_time">End Time *</label>
                    <input type="time"
                           name="end_time"
                           id="end_time"
                           required
                           value="<?= meetingEscape(substr($formMeeting['end_time'], 0, 5)) ?>">
                </div>
            </div>

            <div class="field">
                <label for="location">Location (Optional)</label>
                <input type="text"
                       name="location"
                       id="location"
                       maxlength="255"
                       value="<?= meetingEscape($formMeeting['location']) ?>"
                       placeholder="Office, online or meeting room">
            </div>

            <div class="field">
                <label for="meeting_link">Google Meet Link (Optional)</label>
                <input type="text"
                       name="meeting_link"
                       id="meeting_link"
                       value="<?= meetingEscape($formMeeting['meeting_link']) ?>"
                       placeholder="https://meet.google.com/...">
            </div>

            <div class="field">
                <label for="recipient_mode">Invite Attendees *</label>

                <select name="recipient_mode"
                        id="recipient_mode">

                    <option value="selected">
                        Select individual users
                    </option>

                    <option value="department">
                        Invite an entire department
                    </option>

                    <option value="all">
                        Invite all registered users
                    </option>

                </select>
            </div>

            <div class="field" id="departmentField" hidden>
                <label for="department">Department</label>

                <select name="department" id="department">
                    <option value="">Select department</option>

                    <?php foreach ($departments as $department): ?>
                        <option value="<?= meetingEscape($department) ?>">
                            <?= meetingEscape($department) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field" id="attendeesField">
                <label>Choose Users</label>

                <div class="recipient-panel">
                    <div class="recipient-toolbar">
                        <input type="search"
                               id="attendeeSearch"
                               placeholder="Search by name, email or department...">

                        <div class="button-row">
                            <button type="button"
                                    class="button secondary"
                                    id="selectVisible">
                                Select Visible
                            </button>

                            <button type="button"
                                    class="button secondary"
                                    id="clearAttendees">
                                Clear Selection
                            </button>
                        </div>
                    </div>

                    <div class="recipient-list">
                        <?php foreach ($users as $user): ?>
                            <label class="recipient"
                                   data-search="<?= meetingEscape(strtolower(
                                       $user['name'] . ' '
                                       . $user['email'] . ' '
                                       . ($user['department'] ?? '')
                                   )) ?>">

                                <input type="checkbox"
                                       name="attendees[]"
                                       value="<?= (int) $user['user_id'] ?>"
                                       <?= in_array(
                                           (int) $user['user_id'],
                                           $editAttendees,
                                           true
                                       ) ? 'checked' : '' ?>>

                                <div class="recipient-info">
                                    <strong><?= meetingEscape($user['name']) ?></strong>

                                    <span><?= meetingEscape($user['email']) ?></span>

                                    <span>
                                        <?= meetingEscape($user['department'] ?? 'No department') ?>
                                        ·
                                        <?= meetingEscape($user['role'] ?? '') ?>
                                    </span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="field">
                <label for="meeting_notes">Meeting Notes</label>
                <textarea name="meeting_notes"
                          id="meeting_notes"
                          placeholder="Record discussions and decisions..."><?= meetingEscape($formMeeting['meeting_notes']) ?></textarea>
            </div>

            <div class="field">
                <label for="action_items">Action Items and Follow-ups</label>
                <textarea name="action_items"
                          id="action_items"
                          placeholder="Record actions, responsible people and deadlines..."><?= meetingEscape($formMeeting['action_items']) ?></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="button primary">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <?= $editMeeting ? 'Save Changes' : 'Create Meeting' ?>
                </button>

                <button type="reset" class="button secondary">
                    Clear Form
                </button>
            </div>

        </form>
    </section>

    <!-- MEETING HISTORY -->

    <section class="card" id="meetingHistory">

        <div class="card-heading">
            <div>
                <h2>
                    <i class="fa-solid fa-calendar-days"></i>
                    Meeting History
                </h2>
                <p>View meetings scheduled by every BA.</p>
            </div>
        </div>

        <div class="filter-row">
            <input type="search"
                   id="meetingSearch"
                   placeholder="Search title, organiser, agenda or location...">

            <select id="statusFilter">
                <option value="">All statuses</option>
                <option value="Upcoming">Upcoming</option>
                <option value="In Progress">In Progress</option>
                <option value="Completed">Completed</option>
                <option value="Cancelled">Cancelled</option>
            </select>
        </div>

        <div class="meeting-grid" id="meetingGrid">

            <?php if (empty($meetings)): ?>
                <div class="empty">
                    <i class="fa-regular fa-calendar fa-2x"></i>
                    <p>No meetings have been scheduled yet.</p>
                </div>
            <?php else: ?>

                <?php
                $meetingColours = [
                    '#0d9488',
                    '#2563eb',
                    '#7c3aed',
                    '#db2777',
                    '#ea580c',
                    '#0891b2',
                    '#65a30d',
                    '#9333ea',
                    '#c2410c',
                    '#4f46e5'
                ];
                ?>

                <?php foreach ($meetings as $meeting): ?>

                    <?php
                    $colourIndex = (
                        (int) $meeting['meeting_id']
                    ) % count($meetingColours);

                    $colour = $meetingColours[$colourIndex];

                    $searchText = strtolower(
                        $meeting['title'] . ' '
                        . ($meeting['agenda'] ?? '') . ' '
                        . ($meeting['location'] ?? '') . ' '
                        . ($meeting['organiser_name'] ?? '')
                    );
                    ?>

                    <article class="meeting-card"
                             style="--meeting-colour:<?= meetingEscape($colour) ?>"
                             data-search="<?= meetingEscape($searchText) ?>"
                             data-status="<?= meetingEscape($meeting['status']) ?>">

                        <div class="card-heading">
                            <span class="badge <?= meetingEscape(str_replace(' ', '.', $meeting['status'])) ?>">
                                <?= meetingEscape($meeting['status']) ?>
                            </span>

                            <span style="font-size:12px;color:#64748b;">
                                Meeting #<?= (int) $meeting['meeting_id'] ?>
                            </span>
                        </div>

                        <h3><?= meetingEscape($meeting['title']) ?></h3>

                        <p>
                            <?= meetingEscape(
                                $meeting['agenda'] ?: 'No agenda provided.'
                            ) ?>
                        </p>

                        <div class="meeting-meta">
                            <i class="fa-regular fa-calendar"></i>
                            <span>
                                <?= meetingEscape(
                                    date('D, d M Y', strtotime($meeting['meeting_date']))
                                ) ?>
                            </span>
                        </div>

                        <div class="meeting-meta">
                            <i class="fa-regular fa-clock"></i>
                            <span>
                                <?= meetingEscape(substr($meeting['start_time'], 0, 5)) ?>
                                –
                                <?= meetingEscape(substr($meeting['end_time'], 0, 5)) ?>
                            </span>
                        </div>

                        <div class="meeting-meta">
                            <i class="fa-solid fa-user"></i>
                            <span>
                                Organised by
                                <strong><?= meetingEscape($meeting['organiser_name'] ?? 'Unknown') ?></strong>
                            </span>
                        </div>

                        <div class="meeting-meta">
                            <i class="fa-solid fa-users"></i>
                            <span>
                                <?= (int) $meeting['attendee_count'] ?> invited
                                ·
                                <?= (int) $meeting['attended_count'] ?> attended
                            </span>
                        </div>

                        <?php if (!empty($meeting['location'])): ?>
                            <div class="meeting-meta">
                                <i class="fa-solid fa-location-dot"></i>
                                <span><?= meetingEscape($meeting['location']) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($meeting['meeting_link'])): ?>
                            <div class="meeting-meta">
                                <i class="fa-brands fa-google"></i>

                                <a class="meeting-link"
                                   href="<?= meetingEscape($meeting['meeting_link']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer">
                                    Open Google Meet
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                </a>
                            </div>
                        <?php endif; ?>

                        <div class="meeting-actions">

                            <a href="meetings.php?edit=<?= (int) $meeting['meeting_id'] ?>#meetingForm"
                               class="button secondary">
                                <i class="fa-solid fa-pen"></i>
                                Edit
                            </a>

                            <button type="button"
                                    class="button secondary"
                                    onclick="viewAttendees(<?= (int) $meeting['meeting_id'] ?>)">
                                <i class="fa-solid fa-users"></i>
                                Attendance
                            </button>

                            <form method="POST"
                                  onsubmit="return confirm('Delete this meeting and its attendee records? This cannot be undone.');">

                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?= meetingEscape($csrfToken) ?>">

                                <input type="hidden"
                                       name="action"
                                       value="delete">

                                <input type="hidden"
                                       name="meeting_id"
                                       value="<?= (int) $meeting['meeting_id'] ?>">

                                <button type="submit" class="button danger">
                                    <i class="fa-solid fa-trash"></i>
                                    Delete
                                </button>

                            </form>

                        </div>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>
    </section>

    <!-- ATTENDANCE DETAILS -->

    <section class="card" id="attendanceSection" hidden>
        <div class="card-heading">
            <div>
                <h2>
                    <i class="fa-solid fa-clipboard-check"></i>
                    Meeting Attendance
                </h2>
                <p>Update attendance for the selected meeting.</p>
            </div>

            <button type="button"
                    class="button secondary"
                    onclick="document.getElementById('attendanceSection').hidden=true">
                Close
            </button>
        </div>

        <div id="attendanceContent">
            Select a meeting to manage attendance.
        </div>
    </section>

</main>

<footer>
    TidyTrack Business Analyst Portal &copy; <?= date('Y') ?>
</footer>

<script>
const csrfToken = <?= json_encode($csrfToken) ?>;

const recipientMode = document.getElementById('recipient_mode');
const departmentField = document.getElementById('departmentField');
const attendeesField = document.getElementById('attendeesField');

function updateRecipientMode() {
    departmentField.hidden = recipientMode.value !== 'department';
    attendeesField.hidden = recipientMode.value !== 'selected';
}

recipientMode.addEventListener('change', updateRecipientMode);
updateRecipientMode();

const attendeeRows = [
    ...document.querySelectorAll('.recipient')
];

document.getElementById('attendeeSearch').addEventListener('input', function () {
    const search = this.value.toLowerCase().trim();

    attendeeRows.forEach(row => {
        row.hidden = !row.dataset.search.includes(search);
    });
});

document.getElementById('selectVisible').addEventListener('click', () => {
    attendeeRows.forEach(row => {
        if (!row.hidden) {
            row.querySelector('input').checked = true;
        }
    });
});

document.getElementById('clearAttendees').addEventListener('click', () => {
    document.querySelectorAll('input[name="attendees[]"]').forEach(input => {
        input.checked = false;
    });
});

document.getElementById('meetingSearch').addEventListener('input', filterMeetings);
document.getElementById('statusFilter').addEventListener('change', filterMeetings);

function filterMeetings() {
    const search = document.getElementById('meetingSearch').value.toLowerCase();
    const status = document.getElementById('statusFilter').value;

    document.querySelectorAll('.meeting-card').forEach(card => {
        const matchesSearch = card.dataset.search.includes(search);
        const matchesStatus = !status || card.dataset.status === status;

        card.hidden = !(matchesSearch && matchesStatus);
    });
}

document.getElementById('meetingFormElement').addEventListener('submit', function (event) {
    const mode = recipientMode.value;

    if (mode === 'selected') {
        const selected = document.querySelectorAll(
            'input[name="attendees[]"]:checked'
        ).length;

        if (!selected) {
            event.preventDefault();
            alert('Please select at least one attendee.');
            return;
        }
    }

    if (mode === 'department' &&
        !document.getElementById('department').value) {
        event.preventDefault();
        alert('Please select a department.');
        return;
    }

    if (!confirm('Save this meeting and its attendee list?')) {
        event.preventDefault();
    }
});

/*
 * Attendance uses the same PHP page.
 * The attendance data is loaded from the database by a small
 * request handled through the endpoint below.
 */

async function viewAttendees(meetingId) {
    const section = document.getElementById('attendanceSection');
    const content = document.getElementById('attendanceContent');

    section.hidden = false;
    content.textContent = 'Loading attendance...';

    try {
        const response = await fetch(
            'meetings.php?attendance=' + encodeURIComponent(meetingId),
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        );

        const data = await response.json();

        if (!data.success) {
            content.textContent = data.message || 'Could not load attendance.';
            return;
        }

        content.replaceChildren();

        if (!data.attendees.length) {
            content.textContent = 'No attendees were found.';
            return;
        }

        data.attendees.forEach(attendee => {
            const row = document.createElement('div');

            row.style.cssText =
                'display:flex;align-items:center;justify-content:space-between;gap:15px;padding:12px 0;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;';

            const info = document.createElement('div');

            const name = document.createElement('strong');
            name.textContent = attendee.name;

            const email = document.createElement('div');
            email.textContent = attendee.email || '';
            email.style.cssText = 'font-size:12px;color:#64748b;margin-top:4px;';

            info.append(name, email);

            const form = document.createElement('form');
            form.method = 'POST';
            form.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;';

            const fields = {
                csrf_token: csrfToken,
                action: 'attendance',
                meeting_id: meetingId,
                attendee_id: attendee.user_id
            };

            Object.entries(fields).forEach(([key, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            });

            const select = document.createElement('select');
            select.name = 'attendance_status';

            ['Pending', 'Attended', 'Absent', 'Excused'].forEach(status => {
                const option = document.createElement('option');
                option.value = status;
                option.textContent = status;
                option.selected = attendee.attendance_status === status;
                select.appendChild(option);
            });

            const save = document.createElement('button');
            save.type = 'submit';
            save.className = 'button primary';
            save.textContent = 'Save';

            form.append(select, save);
            row.append(info, form);
            content.appendChild(row);
        });

        section.scrollIntoView({ behavior: 'smooth', block: 'start' });

    } catch (error) {
        content.textContent = 'Could not load attendance. Please try again.';
    }
}
</script>

</body>
</html>
