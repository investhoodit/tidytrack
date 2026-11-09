<?php
session_start();
require_once '../dp.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$userRole = strtolower($_SESSION['role']);

// AUTO-DELETE EXPIRED DATA
$conn->query("DELETE FROM sharezone_data WHERE expires_at < NOW()");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShareZone Hub | TidyTrack</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --mac-bg:        linear-gradient(135deg, #f5f5f7 0%, #e5e5ea 100%);
            --mac-card:      rgba(255, 255, 255, 0.75);
            --mac-accent:    #007aff;
            --mac-accent-dark:#0062cc;
            --text-primary:  #1d1d1f;
            --text-secondary:#6e6e73;
            --border:        rgba(0,0,0,0.08);
            --shadow-sm:     0 4px 30px rgba(0,0,0,0.08);
            --shadow-md:     0 20px 50px rgba(0,0,0,0.12);
            --radius:        28px;
            --blur:          blur(20px);
        }

        * { box-sizing: border-box; margin:0; padding:0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--mac-bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* Glassmorphism overlay (tutorial) */
        #intro-overlay {
            position: fixed;
            inset: 0;
            background: rgba(245,245,247,0.92);
            backdrop-filter: var(--blur);
            -webkit-backdrop-filter: var(--blur);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .intro-card {
            background: var(--mac-card);
            backdrop-filter: var(--blur);
            -webkit-backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            padding: 48px 36px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            transition: transform 0.3s ease;
        }
        .intro-card i { font-size: 64px; color: var(--mac-accent); margin-bottom: 24px; }
        .intro-card h2 { font-size: 32px; font-weight: 700; margin-bottom: 16px; }
        .intro-card p { font-size: 17px; color: var(--text-secondary); line-height: 1.5; margin-bottom: 32px; }

        /* Main hub content */
        .hub-container {
            text-align: center;
            width: 100%;
            max-width: 1100px;
        }
        .hub-container h1 {
            font-size: clamp(36px, 6vw, 52px);
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 12px;
        }
        .hub-container > p {
            font-size: 19px;
            color: var(--text-secondary);
            margin-bottom: 48px;
        }

        .hub-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 28px;
        }

        .hub-card {
            background: var(--mac-card);
            backdrop-filter: var(--blur);
            -webkit-backdrop-filter: var(--blur);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 40px 28px;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        .hub-card:hover {
            transform: translateY(-12px);
            box-shadow: var(--shadow-md);
            border-color: rgba(0,122,255,0.3);
        }
        .hub-card:active { transform: translateY(-4px); }
        .hub-card i {
            font-size: 56px;
            color: var(--mac-accent);
            margin-bottom: 24px;
        }
        .hub-card h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .hub-card p {
            font-size: 15px;
            color: var(--text-secondary);
            line-height: 1.45;
        }

        .restricted {
            margin-top: 16px;
            font-size: 14px;
            color: #ff3b30;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* Buttons – centered at bottom */
        .actions {
            margin-top: 60px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
        }
        .mac-btn {
            background: var(--mac-accent);
            color: white;
            border: none;
            padding: 14px 36px;
            border-radius: 999px;
            font-size: 17px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            min-width: 180px;
            box-shadow: 0 4px 12px rgba(0,122,255,0.25);
        }
        .mac-btn:hover { background: var(--mac-accent-dark); transform: translateY(-2px); }
        .mac-btn:active { transform: translateY(0); }
        .mac-btn-secondary {
            background: rgba(255,255,255,0.9);
            color: var(--text-primary);
            border: 1px solid var(--border);
            box-shadow: none;
        }
        .mac-btn-secondary:hover { background: white; }

        @media (max-width: 640px) {
            .intro-card { padding: 36px 24px; }
            .hub-grid { gap: 20px; }
            .hub-card { padding: 32px 20px; }
        }
    </style>
</head>
<body>

    <!-- Tutorial Overlay (shows only once) -->
    <div id="intro-overlay">
        <div class="intro-card" id="step1">
            <i class="fas fa-cloud-arrow-up"></i>
            <h2>Welcome to ShareZone</h2>
            <p>Share files, notes & links easily — everything disappears after <strong>7 days</strong> to keep things fresh and save space.</p>
            <button class="mac-btn" onclick="nextStep(2)">Continue <i class="fas fa-arrow-right" style="margin-left:8px;"></i></button>
        </div>

        <div class="intro-card" id="step2" style="display:none;">
            <h2>Choose Your Space</h2>
            <p><strong>Personal</strong> → private notes & temp files (only you)<br>
               <strong>Groups</strong> → team & leader collaboration<br>
               <strong>Public</strong> → announcements for everyone in TidyTrack</p>
            <button class="mac-btn" onclick="nextStep(3)">Got it — let's go!</button>
        </div>
    </div>

    <div class="hub-container">
        <h1>Where to, <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?>?</h1>
        <p>Pick a workspace to start sharing securely.</p>

        <div class="hub-grid">
            <div class="hub-card" onclick="location.href='personal_hub.php'">
                <i class="fas fa-user-shield"></i>
                <h3>Personal</h3>
                <p>Your private vault. Perfect for drafts, temporary uploads, and personal notes.</p>
            </div>

            <?php 
                $canAccessGroup = ($userRole === 'leader' || $userRole === 'member');
                $groupAction = $canAccessGroup 
                    ? "location.href='group_picker.php'" 
                    : "alert('Group Hubs are only available to Leaders and assigned Project Members.')";
            ?>
            <div class="hub-card" onclick="<?php echo $groupAction; ?>">
                <i class="fas fa-users-rectangle"></i>
                <h3>Groups</h3>
                <p>Collaborate with your team and project leader. Project assignment required.</p>
                <?php if (!$canAccessGroup): ?>
                    <div class="restricted">
                        <i class="fas fa-lock"></i> Restricted
                    </div>
                <?php endif; ?>
            </div>

            <div class="hub-card" onclick="location.href='public_hub.php'">
                <i class="fas fa-globe"></i>
                <h3>Public</h3>
                <p>Share announcements, zips, or links with the entire TidyTrack community.</p>
            </div>
        </div>

        <div class="actions">
            <button class="mac-btn mac-btn-secondary" onclick="location.href='../index.php'">Back to Home</button>
        </div>
    </div>

    <script>
        function nextStep(step) {
            document.getElementById('step1').style.display = 'none';
            if (step === 2) {
                document.getElementById('step2').style.display = 'block';
            } else {
                document.getElementById('intro-overlay').style.display = 'none';
                localStorage.setItem('sharezone_intro_seen', 'true');
            }
        }

        // Skip intro after first time
        if (localStorage.getItem('sharezone_intro_seen')) {
            document.getElementById('intro-overlay').style.display = 'none';
        }
    </script>
</body>
</html>