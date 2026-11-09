<?php
require_once '../dp.php';
require_once '../PHPMailer-master/PHPMailer-master/src/Exception.php';
require_once '../PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
require_once '../PHPMailer-master/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$smtp_username = getenv('TIDYTRACK_SMTP_USERNAME') ?: '';
$smtp_password = getenv('TIDYTRACK_SMTP_PASSWORD') ?: '';
function sendTaskAssignmentEmail($to_email, $member_name, $task_title, $project_name, $assigned_by, $priority, $due_date) {
    global $smtp_username, $smtp_password;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_username;
        $mail->Password   = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('noreply@investhoodarcade.com', 'Investhood TSM');
        $mail->addAddress($to_email, $member_name);
        $mail->isHTML(true);
        $mail->Subject = "New Task: $task_title – $project_name";

        $priority_color = $priority === 'urgent' ? '#dc2626' : 
                         ($priority === 'high' ? '#e94560' : 
                         ($priority === 'normal' ? '#ffa726' : '#16a34a'));

        $view_link = "https://investhoodarcade.com/Investhood_TSM/investhood/member_tasks.php";

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05);'>
            <div style='background: linear-gradient(135deg, #1d3557, #2a9d8f); color: white; padding: 20px; text-align: center;'>
                <h1 style='margin:0; font-size:1.5rem;'>New Task Assigned</h1>
            </div>
            <div style='padding: 24px;'>
                <p style='margin:0 0 16px;'><strong>Hi $member_name,</strong></p>
                <p style='margin:0 0 20px;'>You've been assigned a new task by <strong>$assigned_by</strong>:</p>

                <div style='background:#f9fafb; padding:16px; border-radius:8px; border-left:4px solid #1d3557; margin:16px 0;'>
                    <p style='margin:4px 0;'><strong>Task:</strong> $task_title</p>
                    <p style='margin:4px 0;'><strong>Project:</strong> $project_name</p>
                    <p style='margin:4px 0;'><strong>Priority:</strong> <span style='color:$priority_color; font-weight:bold; text-transform:uppercase;'>$priority</span></p>
                    <p style='margin:4px 0;'><strong>Due Date:</strong> $due_date</p>
                </div>

                <div style='text-align:center; margin:24px 0;'>
                    <a href='$view_link' style='background:#1d3557; color:white; padding:12px 28px; text-decoration:none; border-radius:8px; font-weight:600; display:inline-block;'>
                        View Task Now
                    </a>
                </div>

                <p style='color:#6b7280; font-size:0.875rem; margin-top:32px;'>
                    Need help? Contact your team leader.<br>
                    <em>Investhood Task Management System</em>
                </p>
            </div>
        </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Task email failed ($to_email): " . $mail->ErrorInfo);
        return false;
    }
}
?>