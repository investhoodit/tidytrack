<?php

declare(strict_types=1);

/**
 * COMMAND-LINE ACCESS ONLY
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

set_time_limit(0);
date_default_timezone_set('Africa/Johannesburg');

/**
 * REQUIRED FILES
 */
require __DIR__ . '/../dp.php';

require_once __DIR__ . '/TCPDF/library/tcpdf.php';

require_once __DIR__
    . '/PHPMailer-master/PHPMailer-master/src/Exception.php';

require_once __DIR__
    . '/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';

require_once __DIR__
    . '/PHPMailer-master/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * EMAIL SETTINGS
 */
const MAIL_FROM = 'noreply@investhoodprojects.com';
const MAIL_FROM_NAME = 'InvestHood Timesheet';
const MAIL_REPLY_TO = 'noreply@investhoodprojects.com';

const TARGET_USER_ID = 11;
const REPORT_RECIPIENT_EMAIL = 'kumbe45nonku@gmail.com';

/**
 * ATTENDANCE SETTINGS
 *
 * 240 minutes or more = Present
 * Less than 240 minutes = Absent
 */
const REQUIRED_DAILY_MINUTES = 240;

/**
 * LOG FILE
 */
$logFile = __DIR__ . '/cron_timesheet_mail.log';

/**
 * OPTIONAL COMMAND-LINE PARAMETERS
 *
 * Example:
 * php cron_timesheet_mail.php --month=7 --year=2026
 */
$options = getopt('', [
    'month::',
    'year::',
]);

/**
 * DEFAULT PERIOD: PREVIOUS MONTH
 */
$defaultPeriod = new DateTimeImmutable('first day of last month');

$month = isset($options['month'])
    ? (int) $options['month']
    : (int) $defaultPeriod->format('n');

$year = isset($options['year'])
    ? (int) $options['year']
    : (int) $defaultPeriod->format('Y');

/**
 * VALIDATE MONTH AND YEAR
 */
if ($month < 1 || $month > 12) {
    exitWithError(
        'Invalid month. Use a value from 1 to 12.',
        $logFile
    );
}

if ($year < 2000 || $year > 2100) {
    exitWithError(
        'Invalid year. Use a value from 2000 to 2100.',
        $logFile
    );
}

/**
 * VALIDATE MEMBER EMAIL
 */
if (
    !filter_var(
        REPORT_RECIPIENT_EMAIL,
        FILTER_VALIDATE_EMAIL
    )
) {
    exitWithError(
        'The configured member email address is invalid.',
        $logFile
    );
}

/**
 * DATABASE SETTINGS
 */
mysqli_report(
    MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
);

$conn->set_charset('utf8mb4');

/**
 * EMAIL DELIVERY LOG TABLE
 *
 * The unique key means the latest delivery result is kept
 * for each member and month.
 */
$conn->query("
    CREATE TABLE IF NOT EXISTS timesheet_email_log (
        email_log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        month TINYINT UNSIGNED NOT NULL,
        year SMALLINT UNSIGNED NOT NULL,
        recipient_email VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL,
        error_message TEXT NULL,
        sent_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_period (
            user_id,
            month,
            year
        )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/**
 * PDF CLASS
 *
 * Now includes a branded header with logo, company name and address.
 */
class CronTimesheetPDF extends TCPDF
{
    // Path to the logo image – adjust if your folder structure differs
    private const LOGO_PATH = __DIR__ . '/../images/INVESTHOOD.jpg';

    public function Header(): void
    {
        // 1) Blue header rectangle
        $this->SetFillColor(29, 53, 87);
        $this->Rect(0, 0, $this->getPageWidth(), 45, 'F');

        // 2) Logo (if file exists)
        if (file_exists(self::LOGO_PATH)) {
            $this->Image(self::LOGO_PATH, 15, 10, 30);
        }

        // 3) Company name and address
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(50, 10);
        $this->Cell(0, 10, 'Investhood IT', 0, 1, 'L');

        $this->SetFont('helvetica', '', 10);
        $this->SetX(50);
        $this->MultiCell(0, 5, "136 2nd St Randjespark\nJohannesburg, 1685\nSouth Africa\nPhone: 075 333 8142", 0, 'L');

        // Reset Y position (content will start at the top margin)
        $this->SetY(50);
    }

    public function Footer(): void
    {
        $this->SetY(-15);

        $this->SetFont(
            'helvetica',
            'I',
            8
        );

        $this->Cell(
            0,
            10,
            'Page '
                . $this->getAliasNumPage()
                . '/'
                . $this->getAliasNbPages(),
            0,
            false,
            'C'
        );
    }
}

/**
 * EXIT AND WRITE FATAL ERROR TO LOG
 */
function exitWithError(
    string $message,
    string $logFile
): void {
    writeLog(
        $logFile,
        'FATAL',
        $message
    );

    fwrite(
        STDERR,
        $message . PHP_EOL
    );

    exit(1);
}

/**
 * WRITE MESSAGE TO LOG FILE
 */
function writeLog(
    string $logFile,
    string $level,
    string $message
): void {
    $line = sprintf(
        "[%s] [%s] %s%s",
        date('Y-m-d H:i:s'),
        $level,
        $message,
        PHP_EOL
    );

    file_put_contents(
        $logFile,
        $line,
        FILE_APPEND | LOCK_EX
    );
}

/**
 * ESCAPE HTML
 */
function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * FORMAT MINUTES AS HOURS AND MINUTES
 */
function formatMinutes(int $minutes): string
{
    if ($minutes <= 0) {
        return '—';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    if (
        $hours > 0
        && $remainingMinutes > 0
    ) {
        return "{$hours}h {$remainingMinutes}min";
    }

    if ($hours > 0) {
        return "{$hours}h";
    }

    return "{$remainingMinutes}min";
}

/**
 * CREATE SAFE PDF FILE NAME
 */
function safeFilePart(string $value): string
{
    $value = preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '_',
        $value
    );

    return trim(
        (string) $value,
        '_'
    ) ?: 'employee';
}

/**
 * SAVE EMAIL DELIVERY RESULT
 */
function saveDeliveryStatus(
    mysqli $conn,
    int $userId,
    int $month,
    int $year,
    string $email,
    string $status,
    ?string $errorMessage
): void {
    $statement = $conn->prepare("
        INSERT INTO timesheet_email_log (
            user_id,
            month,
            year,
            recipient_email,
            status,
            error_message,
            sent_at
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            IF(? = 'sent', NOW(), NULL)
        )
        ON DUPLICATE KEY UPDATE
            recipient_email = VALUES(recipient_email),
            status = VALUES(status),
            error_message = VALUES(error_message),
            sent_at = IF(
                VALUES(status) = 'sent',
                NOW(),
                sent_at
            ),
            updated_at = NOW()
    ");

    $statement->bind_param(
        'iiissss',
        $userId,
        $month,
        $year,
        $email,
        $status,
        $errorMessage,
        $status
    );

    $statement->execute();
    $statement->close();
}

/**
 * LOAD AND CALCULATE TIMESHEET DATA
 *
 * Attendance rules:
 *
 * - Monday to Friday are working days.
 * - 240 minutes or more is Present.
 * - Less than 240 minutes is Absent.
 * - Saturday and Sunday are excluded.
 * - attendance_log is used only for optional notes.
 */
function loadTimesheetData(
    mysqli $conn,
    int $userId,
    int $month,
    int $year
): array {
    /**
     * LOAD ATTENDANCE NOTES
     *
     * The attendance status itself does not decide attendance.
     */
    $attendanceStatement = $conn->prepare("
        SELECT
            date,
            attendance,
            absence_reason
        FROM attendance_log
        WHERE user_id = ?
          AND YEAR(date) = ?
          AND MONTH(date) = ?
        ORDER BY date ASC
    ");

    $attendanceStatement->bind_param(
        'iii',
        $userId,
        $year,
        $month
    );

    $attendanceStatement->execute();

    $attendanceResult =
        $attendanceStatement->get_result();

    $attendance = [];

    while (
        $row = $attendanceResult->fetch_assoc()
    ) {
        $attendance[$row['date']] = $row;
    }

    $attendanceStatement->close();

    /**
     * LOAD DAILY LOGGED MINUTES
     */
    $sessionStatement = $conn->prepare("
        SELECT
            session_date,
            SUM(minutes) AS total_minutes
        FROM user_sessions
        WHERE user_id = ?
          AND YEAR(session_date) = ?
          AND MONTH(session_date) = ?
        GROUP BY session_date
        ORDER BY session_date ASC
    ");

    $sessionStatement->bind_param(
        'iii',
        $userId,
        $year,
        $month
    );

    $sessionStatement->execute();

    $sessionResult =
        $sessionStatement->get_result();

    $dailyMinutes = [];

    while (
        $row = $sessionResult->fetch_assoc()
    ) {
        $dailyMinutes[$row['session_date']] =
            (int) $row['total_minutes'];
    }

    $sessionStatement->close();

    /**
     * BUILD ALL DATES IN THE MONTH
     */
    $daysInMonth = cal_days_in_month(
        CAL_GREGORIAN,
        $month,
        $year
    );

    $dates = [];

    $totalDays = 0;
    $present = 0;
    $absent = 0;

    for (
        $day = 1;
        $day <= $daysInMonth;
        $day++
    ) {
        $date = sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            $day
        );

        $dates[] = $date;

        $dayNumber = (int) date(
            'N',
            strtotime($date)
        );

        $isWeekend = $dayNumber >= 6;

        /**
         * Do not count Saturday or Sunday.
         */
        if ($isWeekend) {
            continue;
        }

        $totalDays++;

        $minutes =
            $dailyMinutes[$date] ?? 0;

        if ($minutes >= REQUIRED_DAILY_MINUTES) {
            $present++;
        } else {
            $absent++;
        }
    }

    /**
     * TOTAL SESSION TIME
     */
    $totalMinutes = array_sum(
        $dailyMinutes
    );

    $totalHours = round(
        $totalMinutes / 60,
        2
    );

    /**
     * Present days and full days now use the same rule.
     */
    $fullDays = $present;

    /**
     * CALCULATE ATTENDANCE PERCENTAGE
     */
    $attendanceRate = $totalDays > 0
        ? round(
            ($present / $totalDays) * 100,
            1
        )
        : 0;

    return [
        'attendance' => $attendance,
        'daily_minutes' => $dailyMinutes,
        'dates' => $dates,
        'total_days' => $totalDays,
        'present' => $present,
        'absent' => $absent,
        'total_hours' => $totalHours,
        'total_minutes' => $totalMinutes,
        'full_days' => $fullDays,
        'attendance_rate' => $attendanceRate,
    ];
}

/**
 * CREATE EMPLOYEE TIMESHEET PDF
 *
 * Enhanced with color‑coded attendance and a compact two‑column summary.
 */
function createTimesheetPdf(
    array $user,
    array $data,
    int $month,
    int $year
): string {
    $period = date(
        'F Y',
        strtotime(
            sprintf(
                '%04d-%02d-01',
                $year,
                $month
            )
        )
    );

    $pdf = new CronTimesheetPDF(
        PDF_PAGE_ORIENTATION,
        PDF_UNIT,
        PDF_PAGE_FORMAT,
        true,
        'UTF-8',
        false
    );

    $pdf->SetCreator('InvestHood');
    $pdf->SetAuthor('InvestHood');

    $pdf->SetTitle(
        'Timesheet - ' . $user['name']
    );

    $pdf->SetSubject(
        'Monthly Timesheet'
    );

    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);

    // Increase top margin to accommodate the new branded header
    $pdf->SetMargins(15, 55, 15);
    $pdf->SetAutoPageBreak(true, 25);

    $pdf->AddPage();

    /**
     * HTML with embedded CSS – summary now uses two columns.
     */
    $html = <<<HTML
<html>
<head>
<style>
    body { font-family: helvetica, sans-serif; color: #333; }
    h2 { color: #1e3a5f; border-bottom: 2px solid #1e3a5f; padding-bottom: 6px; }
    .employee-details { background: #f8fafc; padding: 10px; border-left: 4px solid #1e3a5f; margin-bottom: 12px; }
    .summary-table { width: 100%; border-collapse: collapse; margin: 8px 0; }
    .summary-table td { padding: 5px 8px; border: none; }
    .summary-label { font-weight: bold; width: 40%; background-color: #f0f2f8; }
    .summary-value { width: 60%; background-color: #f8fafc; }
    .summary-row-alt td { background-color: #f8fafc; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 10px; }
    .data-table th { background-color: #1e3a5f; color: #ffffff; padding: 6px; text-align: left; }
    .data-table td { padding: 5px; border: 1px solid #ccc; }
    .present { background-color: #d4edda; color: #155724; }
    .absent { background-color: #f8d7da; color: #721c24; }
    .weekend { background-color: #e9ecef; color: #495057; }
    .footer-note { font-size: 8pt; color: #666; margin-top: 15px; }
</style>
</head>
<body>
HTML;

    /**
     * EMPLOYEE DETAILS
     */
    $html .= '<div class="employee-details">';
    $html .= '<h2>' . e((string) $user['name']) . '</h2>';
    $html .= '<p><strong>Email:</strong> ' . e((string) $user['email']) . ' | ';
    $html .= '<strong>Department:</strong> ' . e((string) $user['department']) . ' | ';
    $html .= '<strong>Role:</strong> ' . e(ucfirst((string) $user['role'])) . '</p>';
    $html .= '<p><strong>Period:</strong> ' . e($period) . '</p>';
    $html .= '</div>';

    /**
     * COMPACT TWO‑COLUMN SUMMARY
     */
    $html .= '
        <table class="summary-table">
            <tr>
                <td class="summary-label">Working Days</td>
                <td class="summary-value">' . $data['total_days'] . '</td>
            </tr>
            <tr class="summary-row-alt">
                <td class="summary-label">Present (4h+)</td>
                <td class="summary-value" style="color:#155724;font-weight:bold;">' . $data['present'] . '</td>
            </tr>
            <tr>
                <td class="summary-label">Absent (&lt;4h)</td>
                <td class="summary-value" style="color:#721c24;font-weight:bold;">' . $data['absent'] . '</td>
            </tr>
            <tr class="summary-row-alt">
                <td class="summary-label">Total Logged</td>
                <td class="summary-value">' . e(formatMinutes((int) $data['total_minutes'])) . '</td>
            </tr>
            <tr>
                <td class="summary-label">Total Hours</td>
                <td class="summary-value">' . number_format((float) $data['total_hours'], 1) . 'h</td>
            </tr>
            <tr class="summary-row-alt">
                <td class="summary-label">Attendance Rate</td>
                <td class="summary-value">' . number_format((float) $data['attendance_rate'], 1) . '%</td>
            </tr>
        </table>
    ';

    /**
     * DAILY TABLE
     */
    $html .= '
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Attendance</th>
                    <th>Logged</th>
                    <th>Completed 4h</th>
                    <th>Reason / Notes</th>
                </tr>
            </thead>
            <tbody>
    ';

    foreach ($data['dates'] as $date) {
        $record = $data['attendance'][$date] ?? null;
        $minutes = (int) ($data['daily_minutes'][$date] ?? 0);
        $dayNumber = (int) date('N', strtotime($date));
        $isWeekend = $dayNumber >= 6;

        if ($isWeekend) {
            $attendance = 'Weekend';
            $class = 'weekend';
            $completed = '—';
            $reason = 'Scheduled day off';
        } elseif ($minutes >= REQUIRED_DAILY_MINUTES) {
            $attendance = 'Present';
            $class = 'present';
            $completed = 'Yes';
            $reason = 'Completed at least 4 logged hours';
        } else {
            $attendance = 'Absent';
            $class = 'absent';
            $completed = 'No';
            if ($minutes > 0) {
                $reason = 'Logged less than 4 hours';
            } else {
                $reason = 'No logged work time';
            }
            if ($record && !empty($record['absence_reason'])) {
                $reason .= ' — ' . $record['absence_reason'];
            }
        }

        $html .= '<tr>';
        $html .= '<td>' . date('d M Y', strtotime($date)) . '</td>';
        $html .= '<td>' . date('D', strtotime($date)) . '</td>';
        $html .= '<td class="' . $class . '">' . e($attendance) . '</td>';
        $html .= '<td>' . e(formatMinutes($minutes)) . '</td>';
        $html .= '<td>' . e($completed) . '</td>';
        $html .= '<td>' . e($reason ?: '—') . '</td>';
        $html .= '</tr>';
    }

    $html .= '
            </tbody>
        </table>
    ';

    /**
     * FOOTER NOTES
     */
    $html .= '
        <p class="footer-note">
            <em>Attendance is calculated from logged session time.</em>
        </p>
        <p class="footer-note">
            <em>Timesheet generated automatically by InvestHood.</em>
        </p>
    ';

    $html .= '</body></html>';

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->lastPage();

    return $pdf->Output('', 'S');
}

/**
 * SEND TIMESHEET EMAIL
 */
function sendTimesheetEmail(
    array $user,
    array $data,
    string $pdfContent,
    int $month,
    int $year
): void {
    $period = date(
        'F Y',
        strtotime(
            sprintf(
                '%04d-%02d-01',
                $year,
                $month
            )
        )
    );

    $memberName =
        e((string) $user['name']);

    $memberEmail =
        e(REPORT_RECIPIENT_EMAIL);

    $formattedMinutes = e(
        formatMinutes(
            (int) $data['total_minutes']
        )
    );

    $totalHours = number_format(
        (float) $data['total_hours'],
        1
    );

    $attendanceRate = number_format(
        (float) $data['attendance_rate'],
        1
    );

    /**
     * HTML EMAIL
     */
    $emailBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Your Timesheet</title>
</head>

<body
    style="
        margin:0;
        padding:0;
        background:#f4f6fa;
        font-family:Arial,Helvetica,sans-serif;
        color:#334155
    "
>
    <div
        style="
            max-width:600px;
            margin:30px auto;
            background:#ffffff;
            border-radius:16px;
            overflow:hidden;
            box-shadow:0 8px 24px rgba(15,23,42,0.08)
        "
    >
        <div
            style="
                background:#1e3a5f;
                color:#ffffff;
                padding:25px 30px;
                text-align:center
            "
        >
            <h1 style="margin:0;font-size:24px">
                Your Timesheet Report
            </h1>

            <p style="margin:6px 0 0">
                {$period}
            </p>
        </div>

        <div style="padding:30px">
            <p>
                Dear <strong>{$memberName}</strong>,
            </p>

            <p>
                Your detailed timesheet for
                <strong>{$period}</strong>
                is attached to this email as a PDF.
            </p>

            <p style="font-size:14px;color:#64748b">
                Member email: {$memberEmail}
            </p>

            <table
                role="presentation"
                style="
                    width:100%;
                    background:#f8fafc;
                    border-left:4px solid #1e3a5f;
                    border-collapse:collapse
                "
            >
                <tr>
                    <td style="padding:8px">
                        Working Days
                    </td>
                    <td style="padding:8px;text-align:right">
                        {$data['total_days']}
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px">
                        Present (4h+)
                    </td>
                    <td
                        style="
                            padding:8px;
                            text-align:right;
                            color:#155724;
                            font-weight:bold
                        "
                    >
                        {$data['present']}
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px">
                        Absent (&lt;4h)
                    </td>
                    <td
                        style="
                            padding:8px;
                            text-align:right;
                            color:#721c24;
                            font-weight:bold
                        "
                    >
                        {$data['absent']}
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px">
                        Total Logged
                    </td>
                    <td style="padding:8px;text-align:right">
                        {$formattedMinutes}
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px">
                        Total Hours
                    </td>
                    <td style="padding:8px;text-align:right">
                        {$totalHours}h
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px">
                        Attendance Rate
                    </td>
                    <td style="padding:8px;text-align:right">
                        {$attendanceRate}%
                    </td>
                </tr>
            </table>

            <p
                style="
                    margin-top:20px;
                    padding:12px;
                    background:#f8fafc;
                    color:#64748b;
                    font-size:13px;
                    line-height:1.6
                "
            >
                Attendance is calculated using your logged session
                time. Logging at least 240 minutes marks you Present.
                Logging fewer than 240 minutes marks you Absent.
                Saturdays and Sundays are excluded.
            </p>

            <p style="margin-top:24px">
                Regards,<br>
                <strong>InvestHood Timesheet System</strong>
            </p>

            <p
                style="
                    margin-top:20px;
                    font-size:12px;
                    color:#94a3b8
                "
            >
                This is an automated email. Please do not reply.
            </p>
        </div>
    </div>
</body>
</html>
HTML;

    /**
     * PLAIN-TEXT EMAIL
     */
    $plainBody =
        "Dear "
        . $user['name']
        . ",\n\n";

    $plainBody .=
        "Your detailed timesheet for {$period} "
        . "is attached to this email as a PDF.\n\n";

    $plainBody .=
        "Working Days: "
        . $data['total_days']
        . "\n";

    $plainBody .=
        "Present (4h+): "
        . $data['present']
        . "\n";

    $plainBody .=
        "Absent (less than 4h): "
        . $data['absent']
        . "\n";

    $plainBody .=
        "Total Logged: "
        . formatMinutes(
            (int) $data['total_minutes']
        )
        . "\n";

    $plainBody .=
        "Total Hours: "
        . number_format(
            (float) $data['total_hours'],
            1
        )
        . "h\n";

    $plainBody .=
        "Attendance Rate: "
        . number_format(
            (float) $data['attendance_rate'],
            1
        )
        . "%\n\n";

    $plainBody .=
        "Attendance is calculated using your logged session time.\n";

    $plainBody .=
        "240 minutes or more is Present.\n";

    $plainBody .=
        "Less than 240 minutes is Absent.\n";

    $plainBody .=
        "Saturdays and Sundays are excluded.\n\n";

    $plainBody .=
        "Regards,\nInvestHood Timesheet System\n";

    /**
     * PDF ATTACHMENT NAME
     */
    $attachmentName = sprintf(
        'timesheet_%s_%04d-%02d.pdf',
        safeFilePart(
            (string) $user['name']
        ),
        $year,
        $month
    );

    /**
     * SEND EMAIL
     */
    $mail = new PHPMailer(true);

    $mail->isMail();
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(
        MAIL_FROM,
        MAIL_FROM_NAME
    );

    $mail->addAddress(
        REPORT_RECIPIENT_EMAIL,
        (string) $user['name']
    );

    $mail->addReplyTo(
        MAIL_REPLY_TO,
        'InvestHood'
    );

    $mail->Subject =
        "Your Timesheet - "
        . $period;

    $mail->isHTML(true);
    $mail->Body = $emailBody;
    $mail->AltBody = $plainBody;

    $mail->addStringAttachment(
        $pdfContent,
        $attachmentName,
        'base64',
        'application/pdf'
    );

    $mail->send();
}


/**
 * START CRON RUN
 */
$periodLabel = sprintf(
    '%04d-%02d',
    $year,
    $month
);

writeLog(
    $logFile,
    'INFO',
    sprintf(
        'Starting timesheet email run for %s and user ID %d.',
        $periodLabel,
        TARGET_USER_ID
    )
);

/**
 * LOAD ONLY THE REQUESTED MEMBER
 */
$userStatement = $conn->prepare("
    SELECT
        user_id,
        name,
        COALESCE(email, '') AS email,
        role,
        COALESCE(
            department,
            'Not assigned'
        ) AS department
    FROM users
    WHERE user_id = ?
      AND role = 'member'
    LIMIT 1
");

$targetUserId = TARGET_USER_ID;

$userStatement->bind_param(
    'i',
    $targetUserId
);

$userStatement->execute();

$userResult =
    $userStatement->get_result();

$user =
    $userResult->fetch_assoc();

$userStatement->close();

if (!$user) {
    $conn->close();

    exitWithError(
        sprintf(
            'Member with user ID %d was not found.',
            TARGET_USER_ID
        ),
        $logFile
    );
}

/**
 * The report is always sent to the fixed member email supplied
 * in the configuration, even if the users table contains a
 * different email address.
 */
$user['email'] =
    REPORT_RECIPIENT_EMAIL;

$userId =
    (int) $user['user_id'];

$recipientEmail =
    REPORT_RECIPIENT_EMAIL;

$sentCount = 0;
$failedCount = 0;

try {
    /**
     * CALCULATE ATTENDANCE
     */
    $data = loadTimesheetData(
        $conn,
        $userId,
        $month,
        $year
    );

    /**
     * CREATE PDF
     */
    $pdfContent = createTimesheetPdf(
        $user,
        $data,
        $month,
        $year
    );

    /**
     * SEND EMAIL
     */
    sendTimesheetEmail(
        $user,
        $data,
        $pdfContent,
        $month,
        $year
    );

    /**
     * SAVE SUCCESS RESULT
     */
    saveDeliveryStatus(
        $conn,
        $userId,
        $month,
        $year,
        $recipientEmail,
        'sent',
        null
    );

    $sentCount = 1;

    writeLog(
        $logFile,
        'SUCCESS',
        sprintf(
            'Sent %s timesheet for user %d to %s. '
                . 'Present: %d, Absent: %d, Attendance: %.1f%%.',
            $periodLabel,
            $userId,
            $recipientEmail,
            $data['present'],
            $data['absent'],
            $data['attendance_rate']
        )
    );
} catch (Throwable $exception) {
    $failedCount = 1;

    $error =
        $exception->getMessage();

    try {
        saveDeliveryStatus(
            $conn,
            $userId,
            $month,
            $year,
            $recipientEmail,
            'failed',
            $error
        );
    } catch (Throwable $logException) {
        $error .=
            ' | Unable to save database status: '
            . $logException->getMessage();
    }

    writeLog(
        $logFile,
        'ERROR',
        "User {$userId} "
            . "({$recipientEmail}): "
            . $error
    );
}

/**
 * FINAL CRON SUMMARY
 */
$summary = sprintf(
    'Completed %s for user %d: %d sent, %d failed.',
    $periodLabel,
    $userId,
    $sentCount,
    $failedCount
);

writeLog(
    $logFile,
    $failedCount > 0
        ? 'WARNING'
        : 'INFO',
    $summary
);

echo $summary . PHP_EOL;

$conn->close();

exit(
    $failedCount > 0
        ? 1
        : 0
);