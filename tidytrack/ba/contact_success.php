<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Submitted | BA Portal</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .success-card {
            background: white;
            max-width: 420px;
            width: 100%;
            padding: 35px;
            border-radius: 14px;
            text-align: center;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.08);
        }

        .success-card h1 {
            font-size: 25px;
        }

        .success-card p {
            color: #666;
            line-height: 1.6;
        }

        .success-card a {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 22px;
            background: #c62828;
            color: white;
            text-decoration: none;
            border-radius: 8px;
        }
    </style>
</head>

<body>
    <div class="success-card">
        <h1>Request Submitted</h1>

        <p>
            Your message has been submitted for email delivery.
            The developer can respond to the email address you provided.
        </p>

        <a href="index.php">Return to Login</a>
    </div>
</body>
</html>