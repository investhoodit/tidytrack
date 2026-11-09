<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';

function sendEmailReminder($toEmail, $taskTitle, $priority, $dueDate) {
    $mail = new PHPMailer(true);
    try {
        // SMTP configuration (example for Gmail)
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = getenv('TIDYTRACK_SMTP_USERNAME') ?: '';
        $mail->Password = getenv('TIDYTRACK_REMINDER_SMTP_PASSWORD') ?: '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Email content
        $mail->setFrom('deandie17@gmail.com', 'INVESTHOOD_TSM');
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = "Task Reminder: $taskTitle";
        $mail->Body = "
            <h3>Task Reminder</h3>
            <p><strong>Task:</strong> $taskTitle</p>
            <p><strong>Priority:</strong> $priority</p>
            <p><strong>Due Date:</strong> $dueDate</p>
            <p>Please complete this task soon!</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email failed: {$mail->ErrorInfo}");
        return false;
    }
}
?>