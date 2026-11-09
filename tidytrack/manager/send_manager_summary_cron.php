<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../dp.php';

date_default_timezone_set('Africa/Johannesburg');

/* =========================================================
   CONFIG
========================================================= */
$MANAGER_EMAIL = 'jerome.dhlamini@gmail.com';   // Main recipient
$MANAGER_NAME  = 'Jerome Dhlamini';

// CC emails
$CC_EMAILS = [
    'thatoaphane7@gmail.com',
    'khensi23kumbe@gmail.com'
];

$MAIL_FROM  = 'noreply@investhoodprojects.com';
$MAIL_REPLY = 'noreply@investhoodprojects.com';

$APP_URL = 'https://investhoodprojects.com/tidytracker/tidytrack/manager';

$PAGE_NAME         = 'manager_daily_reports';
$LINK_EXPIRY_HOURS = 72;

$LOG_FILE = __DIR__ . '/manager_daily_reports_mail_log.txt';

/* =========================================================
   HELPERS
========================================================= */
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function writeLog(string $message): void {
    global $LOG_FILE;
    @file_put_contents(
        $LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function normalizeReportText(string $text): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') {
        return 'No daily report submitted today.';
    }
    if (mb_strlen($text) > 500) {
        return mb_substr($text, 0, 500) . '...';
    }
    return $text;
}

function isValidEmail(string $email): bool {
    return (bool) filter_var(trim($email), FILTER_VALIDATE_EMAIL);
}

function buildManagerLink(string $token): string {
    global $APP_URL;
    return rtrim($APP_URL, '/') . '/manager_daily_reports.php?access_token=' . urlencode($token);
}

function getManagerUserByEmail(mysqli $conn, string $email): ?array {
    $sql = "
        SELECT user_id, name, email, role
        FROM users
        WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        writeLog("Manager email prepare failed: " . $conn->error);
        return null;
    }

    $stmt->bind_param("s", $email);

    if (!$stmt->execute()) {
        writeLog("Manager email execute failed: " . $stmt->error);
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row && strtolower(trim($row['role'] ?? '')) === 'manager') {
        return $row;
    }

    return null;
}

function getFirstManagerUser(mysqli $conn): ?array {
    $sql = "
        SELECT user_id, name, email, role
        FROM users
        WHERE LOWER(TRIM(role)) = 'manager'
        ORDER BY user_id ASC
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        writeLog("First manager prepare failed: " . $conn->error);
        return null;
    }

    if (!$stmt->execute()) {
        writeLog("First manager execute failed: " . $stmt->error);
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function createManagerAccessToken(mysqli $conn, int $managerUserId, string $pageName, int $hours): ?string {
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));

    $sql = "
        INSERT INTO manager_page_tokens (manager_user_id, token, page_name, expires_at)
        VALUES (?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        writeLog("Token prepare failed: " . $conn->error);
        return null;
    }

    $stmt->bind_param("isss", $managerUserId, $token, $pageName, $expiresAt);

    if (!$stmt->execute()) {
        writeLog("Token execute failed: " . $stmt->error);
        $stmt->close();
        return null;
    }

    $stmt->close();
    return $token;
}

function fetchTodayMemberReports(mysqli $conn, string $today): array {
    $sql = "
        SELECT u.user_id, u.name, dt.task, dt.sent_at
        FROM users u
        LEFT JOIN daily_tasks dt
            ON dt.user_id = u.user_id
           AND dt.sent_date = ?
        WHERE LOWER(TRIM(u.role)) = 'member'
        ORDER BY u.name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        writeLog("Reports prepare failed: " . $conn->error);
        return [];
    }

    $stmt->bind_param("s", $today);

    if (!$stmt->execute()) {
        writeLog("Reports execute failed: " . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'name'    => trim((string)($row['name'] ?? 'Unknown Member')),
            'task'    => normalizeReportText((string)($row['task'] ?? '')),
            'sent_at' => $row['sent_at'] ?? null,
            'status'  => !empty($row['sent_at']) ? 'Submitted' : 'Not submitted',
        ];
    }

    $stmt->close();
    return $rows;
}

function sendHtmlMail(
    string $to,
    string $subject,
    string $htmlBody,
    string $plainBody,
    string $fromEmail,
    string $replyToEmail,
    array $ccEmails = []
): bool {
    $boundary = '==Multipart_Boundary_x' . md5((string)microtime()) . 'x';

    $headers = [];
    $headers[] = "From: Investhood Projects <{$fromEmail}>";
    $headers[] = "Reply-To: {$replyToEmail}";

    $validCc = [];
    foreach ($ccEmails as $cc) {
        $cc = trim($cc);
        if ($cc !== '' && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            $validCc[] = $cc;
        }
    }

    if (!empty($validCc)) {
        $headers[] = "Cc: " . implode(',', $validCc);
    }

    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
    $headers[] = "X-Mailer: PHP/" . phpversion();

    $message  = "This is a multi-part message in MIME format.\r\n\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $plainBody . "\r\n\r\n";

    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";

    $message .= "--{$boundary}--";

    writeLog("Attempting send to {$to}" . (!empty($validCc) ? " | cc=" . implode(',', $validCc) : '') . " | subject={$subject}");
    $ok = mail($to, $subject, $message, implode("\r\n", $headers), "-f {$fromEmail}");

    if (!$ok) {
        $err = error_get_last();
        writeLog("MAIL FAILED => to={$to} | subject={$subject} | err=" . ($err['message'] ?? 'unknown'));
    } else {
        writeLog("MAIL SENT => to={$to}" . (!empty($validCc) ? " | cc=" . implode(',', $validCc) : '') . " | subject={$subject}");
    }

    return $ok;
}

/* =========================================================
   START
========================================================= */
if (!isValidEmail($MANAGER_EMAIL)) {
    exit('Invalid manager email.');
}

foreach ($CC_EMAILS as $ccEmail) {
    if ($ccEmail !== '' && !isValidEmail($ccEmail)) {
        exit('Invalid CC email: ' . esc($ccEmail));
    }
}

$managerUser = getManagerUserByEmail($conn, $MANAGER_EMAIL);
if (!$managerUser) {
    $managerUser = getFirstManagerUser($conn);
}

if (!$managerUser) {
    exit('No manager user found in users table.');
}

$managerUserId = (int)$managerUser['user_id'];
$managerNameFromDb = trim((string)($managerUser['name'] ?? 'Manager'));
if ($managerNameFromDb !== '') {
    $MANAGER_NAME = $managerNameFromDb;
}

$today = date('Y-m-d');
$subjectDate = date('d M Y');

$reports = fetchTodayMemberReports($conn, $today);

$token = createManagerAccessToken($conn, $managerUserId, $PAGE_NAME, $LINK_EXPIRY_HOURS);
if (!$token) {
    exit('Could not create secure access token.');
}

$link = buildManagerLink($token);

/* =========================================================
   BUILD EMAIL
========================================================= */
$subject = "Daily Member Reports - {$subjectDate}";

$submittedCount = 0;
foreach ($reports as $r) {
    if (!empty($r['sent_at'])) {
        $submittedCount++;
    }
}
$totalCount = count($reports);

$htmlRows = '';
$plainRows = '';

if (empty($reports)) {
    $htmlRows = '
        <tr>
            <td colspan="3" style="padding:14px;border-bottom:1px solid #eee;color:#666;">
                No member rows were found.
            </td>
        </tr>';
    $plainRows .= "No member rows were found.\n";
} else {
    foreach ($reports as $report) {
        $timeText = !empty($report['sent_at'])
            ? date('H:i', strtotime($report['sent_at']))
            : 'Not submitted';

        $statusColor = !empty($report['sent_at']) ? '#1f9d55' : '#dc2626';
        $statusBg    = !empty($report['sent_at']) ? '#eafaf1' : '#fdecec';

        $htmlRows .= '
            <tr>
                <td style="padding:14px;border-bottom:1px solid #eee;vertical-align:top;">
                    <div style="font-weight:600;color:#222;">' . esc($report['name']) . '</div>
                    <div style="font-size:12px;color:#666;">Member</div>
                </td>
                <td style="padding:14px;border-bottom:1px solid #eee;vertical-align:top;">
                    <span style="
                        display:inline-block;
                        padding:6px 12px;
                        border-radius:999px;
                        font-size:12px;
                        font-weight:600;
                        color:' . $statusColor . ';
                        background:' . $statusBg . ';
                    ">' . esc($timeText) . '</span>
                </td>
                <td style="padding:14px;border-bottom:1px solid #eee;vertical-align:top;color:#333;line-height:1.5;">
                    ' . nl2br(esc($report['task'])) . '
                </td>
            </tr>';

        $plainRows .= $report['name'] . "\n";
        $plainRows .= "Time: {$timeText}\n";
        $plainRows .= "Daily Report: {$report['task']}\n\n";
    }
}

$htmlBody = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>' . esc($subject) . '</title>
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#222;">
    <div style="max-width:900px;margin:0 auto;padding:24px;">
        <div style="background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(0,0,0,0.06);">
            <div style="background:#6b46c1;color:#fff;padding:22px 24px;">
                <h2 style="margin:0;font-size:26px;">Daily Member Reports - ' . esc($subjectDate) . '</h2>
                <p style="margin:8px 0 0 0;font-size:14px;opacity:0.95;">
                    Hello ' . esc($MANAGER_NAME) . ', below are today’s daily reports for members only.
                </p>
            </div>

            <div style="padding:20px 24px 8px 24px;">
                <div style="display:inline-block;margin-right:12px;padding:10px 14px;background:#f3f0ff;border-radius:10px;">
                    <strong>Total Members:</strong> ' . esc((string)$totalCount) . '
                </div>
                <div style="display:inline-block;padding:10px 14px;background:#ecfdf3;border-radius:10px;">
                    <strong>Submitted:</strong> ' . esc((string)$submittedCount) . '
                </div>
            </div>

            <div style="padding:16px 24px 26px 24px;">
                <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8f9fc;text-align:left;">
                            <th style="padding:14px;border-bottom:1px solid #e5e7eb;color:#667085;font-size:13px;">MEMBER</th>
                            <th style="padding:14px;border-bottom:1px solid #e5e7eb;color:#667085;font-size:13px;">TIME</th>
                            <th style="padding:14px;border-bottom:1px solid #e5e7eb;color:#667085;font-size:13px;">DAILY REPORT</th>
                        </tr>
                    </thead>
                    <tbody>' . $htmlRows . '</tbody>
                </table>

                <div style="margin-top:24px;">
                    <a href="' . esc($link) . '" style="
                        display:inline-block;
                        background:#6b46c1;
                        color:#ffffff;
                        text-decoration:none;
                        padding:12px 18px;
                        border-radius:10px;
                        font-weight:600;
                    ">Open Manager Daily Reports</a>
                </div>

                <p style="margin:18px 0 0 0;font-size:12px;color:#777;">
                    This secure link expires in ' . esc((string)$LINK_EXPIRY_HOURS) . ' hours.
                </p>
            </div>
        </div>
    </div>
</body>
</html>';

$plainBody  = "Hello {$MANAGER_NAME},\n\n";
$plainBody .= "Below are today's daily reports for members only.\n\n";
$plainBody .= $plainRows . "\n";
$plainBody .= "Open Manager Daily Reports:\n{$link}\n\n";
$plainBody .= "This secure link expires in {$LINK_EXPIRY_HOURS} hours.\n";

/* =========================================================
   SEND
========================================================= */
$sent = sendHtmlMail(
    $MANAGER_EMAIL,
    $subject,
    $htmlBody,
    $plainBody,
    $MAIL_FROM,
    $MAIL_REPLY,
    $CC_EMAILS
);

echo "Recipient: " . esc($MANAGER_EMAIL) . "<br>";
echo "CC: " . esc(implode(', ', $CC_EMAILS)) . "<br>";
echo "Status: " . ($sent ? "SENT" : "FAILED") . "<br>";
echo "Reports Found: " . count($reports) . "<br>";
echo "Link: " . esc($link) . "<br>";
echo "Log file: " . esc($LOG_FILE) . "<br>";

$conn->close();
?>