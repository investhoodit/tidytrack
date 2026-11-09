<!DOCTYPE html>
<html>
<head>
    <title>Check Your Email</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Poppins',sans-serif;
            background:#eef2f5;
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:1.5rem;
        }
        .card {
            background:#fff;
            border-radius:18px;
            padding:2.5rem 2rem;
            max-width:420px;
            width:100%;
            text-align:center;
            box-shadow:0 10px 30px rgba(0,0,0,0.08);
        }
        .icon {
            width:64px; height:64px;
            background:#1d3557;
            color:#fff;
            border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:1.6rem;
            margin:0 auto 1.2rem;
        }
        h1 { color:#1d3557; font-size:1.5rem; margin-bottom:.8rem; }
        p { color:#555; font-size:.9rem; line-height:1.6; margin-bottom:.8rem; }
        strong { color:#1d3557; }
        a.btn {
            display:inline-block;
            margin-top:1.2rem;
            background:#1d3557;
            color:#fff;
            text-decoration:none;
            padding:.8rem 1.6rem;
            border-radius:8px;
            font-weight:600;
            font-size:.9rem;
        }
        a.btn:hover { background:#2a4b7c; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon"><i class="fas fa-envelope-open-text"></i></div>
        <h1>Check Your Email!</h1>
        <p>We've sent a confirmation link to<br><strong><?php echo htmlspecialchars($_GET['email'] ?? 'your address'); ?></strong></p>
        <p>Please click the link in the email to activate your InvesthoodIT account. You will be unable to log in until confirmed.</p>
        <a class="btn" href="signup.html">Go to the Login Page</a>
    </div>
</body>
</html>