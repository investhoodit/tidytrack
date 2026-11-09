<?php
session_start();
require_once 'dp.php'; //connects to your database

// Get user info from session
$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? null;
$userEmail = $_SESSION['email'] ?? null;
$userRole = $_SESSION['role'] ?? null; 

// If user not logged in, send them to login
if (!$userName || !$userId) {
    header("Location: login.php");
    exit();
}

// --- Calculate User Initial (Used for profile fallback) ---
$userInitial = strtoupper(substr($userName, 0, 2));


// --- Fetch user data (status, profile picture, confirm role) ---
$currentStatus = 'offline';
$profilePicture = null;
$currentRole = $userRole; // Start with session role

if (isset($conn) && $conn instanceof mysqli && $conn->connect_error === null) {
    try {
        $stmt = $conn->prepare("SELECT status, profile_picture, role FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($userData = $result->fetch_assoc()) {
            $currentStatus = $userData['status'] ?? 'offline';
            $profilePicture = $userData['profile_picture'] ?? null;
            
            $profilePicture = $userData['profile_picture'] ?? null;

// Fix path since index.php is in root, not a subfolder
if ($profilePicture) {
    $profilePicture = ltrim(str_replace('../', '', $profilePicture), '/');
}
            // Use the role fetched from DB if available, otherwise keep session role
            $currentRole = $userData['role'] ?? $userRole; 
            $_SESSION['role'] = $currentRole; // Update session with confirmed role
        }
        $stmt->close();

        // --- Update user status to "online" ---
        $stmt = $conn->prepare("UPDATE users SET status = 'online' WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            if (!$stmt->execute()) {
                error_log("Error updating user status for ID {$userId}: " . $stmt->error);
            }
            $stmt->close();
        } else {
            error_log("Error preparing status update statement: " . $conn->error);
        }
        
    } catch (Exception $e) {
        error_log("Database Execution Error: " . $e->getMessage());
    }
}


// --- Determine dashboard link based on the confirmed role ---
$dashboardLink = 'logout.php'; // Default fallback

// Use strtolower() for case-insensitive matching, just in case
$role = strtolower($currentRole);

if ($role === 'member') {
    $dashboardLink = 'member/member_dashboard.php';
} elseif ($role === 'leader') {
    $dashboardLink = 'leader/leader_index.php';
} elseif ($role === 'manager') {
    $dashboardLink = 'manager/manager_index.php';
} elseif ($role === 'admin') {
    $dashboardLink = 'admin/admin_index.php';
}



//for animation 

$teamPics = [];
$res = $conn->query("SELECT name, profile_picture FROM users WHERE status='online' ORDER BY RAND() LIMIT 6");
if ($res) { while ($row = $res->fetch_assoc()) { $teamPics[] = $row; } }

?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TidyTrack • Work Management Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <style>
        /* COLOR VARIABLES */
        :root {
            --color-bg-primary: #0a0a0a;
            --color-accent-main: #a78bfa; /* Purple-400 */
            --color-accent-glow: #7c3aed; /* Purple-600 */
            --color-glass-bg: rgba(255, 255, 255, 0.05);
            --color-glass-border: rgba(255, 255, 255, 0.1);
            --color-nav-bg: rgba(10, 10, 10, 0.9);
            --color-nav-border: rgba(167, 139, 250, 0.2);
        }

        /* BASE STYLES */
        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--color-bg-primary); 
            color: #e2e8f0; 
            min-height: 100vh;
        }

        /* SCROLLBAR */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--color-bg-primary); }
        ::-webkit-scrollbar-thumb { background: var(--color-accent-main); border-radius: 4px; }

        /* GLASS COMPONENTS */
        .glass { 
            background: var(--color-glass-bg); 
            backdrop-filter: blur(12px); 
            border: 1px solid var(--color-glass-border); 
            transition: all 0.3s ease;
        }
        .nav-glass { 
            background: var(--color-nav-bg); 
            backdrop-filter: blur(16px); 
            border-bottom: 1px solid var(--color-nav-border); 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); 
        }

        /* PROFILE CIRCLE */
        .profile-circle {
            width: 220px; height: 220px; border-radius: 50%;
            background: linear-gradient(135deg, var(--color-accent-main), var(--color-accent-glow));
            display: flex; align-items: center; justify-content: center;
            font-size: 6rem; font-weight: 900; color: white;
            border: 10px solid rgba(167, 139, 250, 0.3);
            box-shadow: 0 30px 60px rgba(124, 58, 237, 0.5);
            overflow: hidden; object-fit: cover;
        }
        
        /* BUTTON GLOW */
        .btn-glow {
            background: linear-gradient(135deg, var(--color-accent-main), var(--color-accent-glow));
            padding: 1rem 3rem; border-radius: 999px; font-weight: 600; color: white;
            box-shadow: 0 10px 30px rgba(124, 58, 237, 0.5);
            transition: all 0.3s ease;
        }
        .btn-glow:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(124, 58, 237, 0.6); }

        /* SCROLL REVEAL */
        .scroll-reveal { opacity: 0; transform: translateY(50px); transition: all 0.9s cubic-bezier(0.4, 0, 0.2, 1); }
        .scroll-reveal.visible { opacity: 1; transform: translateY(0); }

        /* MOBILE MENU */
        #mobile-menu { transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        #mobile-menu.open { transform: translateX(0); }
        
        /* ========================================================= */
        /* ADVANCED LOADER ANIMATION (Fixes "Too Basic" Complaint)   */
        /* ========================================================= */
        #loader { 
            position: fixed; inset: 0; 
            background: var(--color-bg-primary); 
            z-index: 9999; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            transition: opacity 0.8s ease; 
        }
        #loader.hidden { opacity: 0; pointer-events: none; }

        .system-status-indicator {
            position: relative; width: 200px; height: 200px; margin-bottom: 3rem;
            display: flex; align-items: center; justify-content: center;
        }

        /* Inner Core (TT Text) */
        .system-status-indicator::before {
            content: 'TT';
            font-family: 'JetBrains Mono', monospace;
            font-size: 3rem; font-weight: bold;
            color: var(--color-accent-main);
            text-shadow: 0 0 15px var(--color-accent-main);
            z-index: 2;
            animation: core-pulse 1.5s infinite alternate;
        }

        /* Outer Ring (Spinning Data Flow) */
        .system-status-indicator::after {
            content: ''; position: absolute; width: 100%; height: 100%; border-radius: 50%;
            border: 3px solid transparent;
            border-top-color: var(--color-accent-main);
            border-right-color: var(--color-accent-glow);
            animation: data-flow 2s linear infinite; opacity: 0.8;
        }

        /* Secondary Ring (Subtle Spin) */
        .system-status-indicator .secondary-ring {
            position: absolute; width: 150px; height: 150px; border-radius: 50%;
            border: 2px dashed rgba(167, 139, 250, 0.3);
            animation: subtle-spin 5s linear infinite reverse;
        }

        @keyframes core-pulse { 0% { transform: scale(1); opacity: 0.9; } 100% { transform: scale(1.05); opacity: 1; } }
        @keyframes data-flow { to { transform: rotate(360deg); } }
        @keyframes subtle-spin { to { transform: rotate(360deg); } }
        
        
        
        
        .orbit-avatars {
    position: absolute;
    width: 100%; height: 100%;
    animation: orbit-spin 6s linear infinite;
}
.mini-avatar {
    position: absolute;
    width: 32px; height: 32px;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid var(--color-accent-main);
    box-shadow: 0 0 8px rgba(167,139,250,0.6);
    background: #1e1e1e;
    top: 50%; left: 50%;
    transform-origin: center;
}
.mini-avatar img { width:100%; height:100%; object-fit:cover; }
.mini-avatar span { display:flex; align-items:center; justify-content:center; width:100%; height:100%; font-size:.7rem; font-weight:700; color:#fff; }

@keyframes orbit-spin { to { transform: rotate(360deg); } }

@media (max-width: 640px) {
    .mini-avatar { width: 24px; height: 24px; }
    .system-status-indicator { width: 160px; height: 160px; }
}


.tt-notice-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.7);
    z-index: 8000;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
    animation: fadeIn .4s ease;
}
.tt-notice-card {
    background: #151515;
    border: 1px solid var(--color-accent-glow);
    border-radius: 18px;
    padding: 2rem 1.8rem;
    max-width: 380px;
    width: 100%;
    text-align: center;
    position: relative;
    box-shadow: 0 20px 60px rgba(124,58,237,0.4);
    animation: popIn .4s cubic-bezier(.34,1.56,.64,1);
}
.tt-notice-close {
    position: absolute; top: 10px; right: 14px;
    background: none; border: none; color: #aaa;
    font-size: 1.4rem; cursor: pointer; line-height: 1;
}
.tt-notice-close:hover { color: #fff; }
.tt-notice-logo {
    width: 52px; height: 52px;
    margin: 0 auto 0.8rem;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid var(--color-accent-main);
    background: #1e1e1e;
    display: flex; align-items: center; justify-content: center;
}
.tt-notice-logo img { width: 100%; height: 100%; object-fit: contain; }
.tt-notice-from {
    font-size: 11px;
    color: var(--color-accent-main);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
    margin-bottom: .6rem;
}
.tt-notice-card h3 {
    color: #fff;
    font-size: 1.15rem;
    margin-bottom: .6rem;
}
.tt-notice-card p {
    color: #ccc;
    font-size: .85rem;
    line-height: 1.5;
    margin-bottom: .5rem;
}
.tt-notice-deadline {
    color: #f39c12;
    font-size: .8rem;
    margin-top: .8rem;
}

@keyframes fadeIn { from{opacity:0;} to{opacity:1;} }
@keyframes popIn { from{opacity:0;transform:scale(.85) translateY(20px);} to{opacity:1;transform:scale(1) translateY(0);} }

@media (max-width: 480px) {
    .tt-notice-card { padding: 1.5rem 1.2rem; }
    .tt-notice-card h3 { font-size: 1rem; }
    .tt-notice-card p { font-size: .8rem; }
}
    </style>
</head>
<body>

<div id="loader">
    <div class="system-status-indicator">
        <div class="secondary-ring"></div>
         <div class="orbit-avatars" id="orbitAvatars"></div>
    </div>
    <p class="text-2xl" style="color: var(--color-accent-main);"> System Initializing... </p>
    <p class="text-sm text-gray-500 mt-2"> Awaiting server handshake... </p>
</div>

<nav class="fixed top-0 left-0 right-0 nav-glass z-50">
    <div class="max-w-7xl mx-auto px-6 py-5 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center">
    <img src="images/Tidy Track Logomark- Website Scale.png" alt="TidyTrack Logo" class="w-full h-full object-contain">
            </div>
            <div>
                <h1 class="text-2xl font-black bg-gradient-to-r from-white to-purple-300 bg-clip-text text-transparent">TidyTrack</h1>
                <p class="text-xs text-purple-300">Work Management Hub</p>
            </div>
        </div>

        <div class="hidden md:flex items-center gap-10 text-gray-300">
            <a href="sharezone/index.php" class="hover:text-purple-400 font-medium transition">Team Hub</a>
            <a href="#goals" class="hover:text-purple-400 font-medium transition">Goals</a>
            <a href="meeting/member_meetings.php" class="font-bold text-white hover:text-purple-300 transition">Meeting</a>
            <a href="logout.php" class="w-11 h-11 bg-red-500/10 hover:bg-red-500/20 rounded-full flex items-center justify-center transition">
                <i class="fas fa-sign-out-alt text-red-400"></i>
            </a>
        </div>

        <button id="menu-btn" class="md:hidden text-3xl text-purple-400">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div id="mobile-menu" class="fixed inset-0 bg-black/95 backdrop-blur-2xl z-40 transform -translate-x-full md:hidden">
        <div class="flex flex-col items-center justify-center h-full gap-12 text-3xl">
            <a href="sharezone/index.php" class="text-white hover:text-purple-400 transition">Team Hub</a>
            <a href="#goals" class="text-white hover:text-purple-400 transition">Goals</a>
            <a href="meeting/member_meetings.php" class="text-purple-300 font-bold">Meeting</a>
            <a href="logout.php" class="text-red-400">Logout</a>
            <button id="close-menu" class="absolute top-8 right-8 text-4xl text-purple-400">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</nav>

<section class="pt-32 pb-20 text-center px-6">
    <div class="max-w-5xl mx-auto">
        <h2 class="text-5xl md:text-7xl font-black mb-6 leading-tight">
            Welcome back,<br>
            <span class="bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-transparent">
                <?= htmlspecialchars($userName) ?>!
            </span>
        </h2>
        <p class="text-xl md:text-2xl text-gray-400 max-w-3xl mx-auto mb-12">
            Your personalized workspace is ready. Manage projects, track progress, and collaborate seamlessly.
        </p>


     <div class="flex justify-center mb-12">
            <?php if ($profilePicture && file_exists($profilePicture)): ?>
                <img src="<?= htmlspecialchars($profilePicture) ?>" alt="Profile" class="profile-circle">
            <?php else: ?>
                <div class="profile-circle">
                    <?= htmlspecialchars($userInitial) ?>
                </div>
            <?php endif; ?>
        </div>

        <p class="text-xl text-purple-300 font-medium mb-10">
            <?= ucfirst($userRole) ?> • Online Now
        </p>

        <a href="<?= $dashboardLink ?>" class="btn-glow inline-block text-lg">
            <i class="fas fa-rocket mr-3"></i> Launch Dashboard
        </a>
    </div>
</section>

<section id="goals" class="py-24 scroll-reveal">
    <div class="max-w-7xl mx-auto px-6 text-center">
        <h2 class="text-5xl font-black mb-6">Powerful Features for Modern Teams</h2>
        <p class="text-xl text-gray-400 max-w-3xl mx-auto mb-16">
            Everything you need to manage projects efficiently and keep your team productive
        </p>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <div class="glass rounded-2xl p-8 hover:border-purple-500/50 transition-all">
                <i class="fas fa-tasks text-6xl bg-gradient-to-br from-purple-400 to-pink-400 bg-clip-text text-transparent mb-6"></i>
                <h3 class="text-2xl font-bold mb-4">Smart Task Management</h3>
                <p class="text-gray-400">AI-powered prioritization and workflow automation.</p>
            </div>
            <div class="glass rounded-2xl p-8 hover:border-purple-500/50 transition-all">
                <i class="fas fa-users text-6xl bg-gradient-to-br from-purple-400 to-pink-400 bg-clip-text text-transparent mb-6"></i>
                <h3 class="text-2xl font-bold mb-4">Real-time Collaboration</h3>
                <p class="text-gray-400">Instant messaging, file sharing, and live editing.</p>
            </div>
            <div class="glass rounded-2xl p-8 hover:border-purple-500/50 transition-all">
                <i class="fas fa-chart-line text-6xl bg-gradient-to-br from-purple-400 to-pink-400 bg-clip-text text-transparent mb-6"></i>
                <h3 class="text-2xl font-bold mb-4">Advanced Analytics</h3>
                <p class="text-gray-400">Comprehensive insights and performance metrics.</p>
            </div>
            <div class="glass rounded-2xl p-8 hover:border-purple-500/50 transition-all">
                <i class="fas fa-shield-alt text-6xl bg-gradient-to-br from-purple-400 to-pink-400 bg-clip-text text-transparent mb-6"></i>
                <h3 class="text-2xl font-bold mb-4">Enterprise Security</h3>
                <p class="text-gray-400">Bank-level encryption and compliance with standards.</p>
            </div>
            <div class="glass rounded-2xl p-8 hover:border-purple-500/50 transition-all">
                <i class="fas fa-mobile-alt text-6xl bg-gradient-to-br from-purple-400 to-pink-400 bg-clip-text text-transparent mb-6"></i>
                <h3 class="text-2xl font-bold mb-4">Mobile Ready</h3>
                <p class="text-gray-400">Access your workspace from anywhere with responsive design.</p>
            </div>
            <div class="glass rounded-2xl p-8 hover:border-purple-500/50 transition-all">
                <i class="fas fa-bell text-6xl bg-gradient-to-br from-purple-400 to-pink-400 bg-clip-text text-transparent mb-6"></i>
                <h3 class="text-2xl font-bold mb-4">Smart Notifications</h3>
                <p class="text-gray-400">Intelligent alerts that keep you informed without overwhelm.</p>
            </div>
        </div>
    </div>
</section>

<section class="py-24 scroll-reveal bg-gradient-to-b from-transparent to-purple-900/20">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <h3 class="text-5xl font-black mb-6">TidyTrack In-House Apps</h3>
            <p class="text-xl text-gray-300 max-w-2xl mx-auto">
                Explore our suite of integrated applications designed to power your journey
            </p>
        </div>
        
        <div class="grid gap-8 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
            <a href="https://investhoodarcade.com/eSchooling/" class="glass rounded-2xl p-6 group hover:border-purple-500/50 transition-all">
                <div class="aspect-video rounded-lg mb-6 overflow-hidden">
                    <img src="images/code.jpg" alt="E-Learning" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                </div>
                <h4 class="text-xl font-bold text-purple-400 mb-3">Investhood E-Learning</h4>
                <p class="text-gray-300 mb-4">Master skills with interactive courses.</p>
                <div class="flex items-center text-purple-400 group-hover:text-purple-300">
                    <span class="font-medium">Launch →</span>
                </div>
            </a>
            <a href="#" class="glass rounded-2xl p-6 group hover:border-purple-500/50 transition-all">
                <div class="aspect-video rounded-lg mb-6 overflow-hidden">
                    <img src="images/recruitment.jpg" alt="Recruitment" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                </div>
                <h4 class="text-xl font-bold text-purple-400 mb-3">Investhood Recruitment App</h4>
                <p class="text-gray-300 mb-4">Hire top coding talent seamlessly.</p>
                <div class="flex items-center text-purple-400 group-hover:text-purple-300">
                    <span class="font-medium">Coming Soon</span>
                    <i class="fas fa-clock ml-2"></i>
                </div>
            </a>
            <a href="#" class="glass rounded-2xl p-6 group hover:border-purple-500/50 transition-all">
                <div class="aspect-video rounded-lg mb-6 overflow-hidden">
                    <img src="images/work.jpg" alt="TSM" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                </div>
                <h4 class="text-xl font-bold text-purple-400 mb-3">TidyTrack TSM</h4>
                <p class="text-gray-300 mb-4">Code smarter with task management.</p>
                <div class="flex items-center text-purple-400 group-hover:text-purple-300">
                    <span class="font-medium">You're Here</span>
                    <i class="fas fa-map-marker-alt ml-2"></i>
                </div>
            </a>
            <a href="https://example.com/6" class="glass rounded-2xl p-6 group hover:border-purple-500/50 transition-all">
                <div class="aspect-video rounded-lg mb-6 overflow-hidden">
                    <img src="images/ai.jpg" alt="AI Learning" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                </div>
                <h4 class="text-xl font-bold text-purple-400 mb-3">AI Learning</h4>
                <p class="text-gray-300 mb-4">Explore AI-driven coding solutions.</p>
                <div class="flex items-center text-purple-400 group-hover:text-purple-300">
                    <span class="font-medium">Explore AI →</span>
                </div>
            </a>
            <a href="https://investhoodarcade.com/login.php" class="glass rounded-2xl p-6 group hover:border-purple-500/50 transition-all">
                <div class="aspect-video rounded-lg mb-6 overflow-hidden">
                    <img src="images/arcade.jpg" alt="Arcade" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                </div>
                <h4 class="text-xl font-bold text-purple-400 mb-3">Investhood Arcade</h4>
                <p class="text-gray-300 mb-4">Code, play, and learn with games.</p>
                <div class="flex items-center text-purple-400 group-hover:text-purple-300">
                    <span class="font-medium">Play Games →</span>
                </div>
            </a>
            <a href="https://investhoodacademy.com/" class="glass rounded-2xl p-6 group hover:border-purple-500/50 transition-all">
                <div class="aspect-video rounded-lg mb-6 overflow-hidden">
                    <img src="images/bot.jpg" alt="Academy" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                </div>
                <h4 class="text-xl font-bold text-purple-400 mb-3">Investhood Academy</h4>
                <p class="text-gray-300 mb-4">Level up your coding skills.</p>
                <div class="flex items-center text-purple-400 group-hover:text-purple-300">
                    <span class="font-medium">Start Learning →</span>
                </div>
            </a>
        </div>
    </div>
</section>

<section class="py-20 scroll-reveal">
    <div class="max-w-4xl mx-auto px-6 text-center">
        <h3 class="text-5xl font-black mb-10">Contact Us</h3>
        <div class="glass rounded-3xl p-10 max-w-lg mx-auto">
            <p class="text-2xl font-bold mb-4">TidyTrack</p>
            <p class="text-gray-300 mb-3">Email: investhoodit@gmail.com</p>
            <p class="text-gray-300 mb-6">Phone: +27 75 333 8142</p>
            <a href="https://www.linkedin.com/company/investhoodit/" class="text-purple-400 hover:text-purple-300 text-lg">
                <i class="fab fa-linkedin mr-2"></i>Follow us on LinkedIn
            </a>
        </div>
    </div>
</section>

<footer class="py-12 text-center text-gray-500 border-t border-white/10">
    <p>&copy; 2025 TidyTrack. All rights reserved. Powered by INVESTHOOD IT.</p>
</footer>



<div class="tt-notice-overlay" id="profileReminder">
    <div class="tt-notice-card">
        <button class="tt-notice-close" onclick="closeProfileReminder()">&times;</button>
        <div class="tt-notice-logo">
            <img src="images/Tidy Track Logomark- Website Scale.png" alt="TidyTrack">
        </div>
        <div class="tt-notice-from">From the TidyTrack Team</div>
        <h3>Complete Your Profile</h3>
        <p> Please update your profile details, department, and profile picture. If already completed, no further action is required. </p> <p class="tt-notice-deadline"> Deadline: Friday 16 October 2026</strong> </p>
    </div>
</div>

<script>
    // Loader: Hides the #loader element after 900ms once the page is fully loaded.
    window.addEventListener('load', () => {
        const loader = document.getElementById('loader');
        if (loader) {
            setTimeout(() => loader.classList.add('hidden'), 900);
        }
    });
    
    ///for animation 
    const avatars = <?= json_encode($teamPics) ?>;
const orbitBox = document.getElementById('orbitAvatars');
const radius = window.innerWidth < 640 ? 80 : 100;
avatars.forEach((u, i) => {
    const angle = (360 / avatars.length) * i;
    const el = document.createElement('div');
    el.className = 'mini-avatar';
    el.style.transform = `rotate(${angle}deg) translate(${radius}px) rotate(-${angle}deg)`;
    if (u.profile_picture) {
        el.innerHTML = `<img src="${u.profile_picture.replace('../','')}" alt="">`;
    } else {
        el.innerHTML = `<span>${u.name.substring(0,2).toUpperCase()}</span>`;
    }
    orbitBox.appendChild(el);
});

//for alert notfication 
const profileReminder = document.getElementById('profileReminder');
if (profileReminder) {
    setTimeout(closeProfileReminder, 10000);
}
function closeProfileReminder() {
    if (!profileReminder) return;
    profileReminder.style.transition = 'opacity .4s';
    profileReminder.style.opacity = '0';
    setTimeout(() => profileReminder.remove(), 400);
}

    // Mobile menu
    document.getElementById('menu-btn').onclick = () => document.getElementById('mobile-menu').classList.add('open');
    document.getElementById('close-menu').onclick = () => document.getElementById('mobile-menu').classList.remove('open');

    // Scroll reveal
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => entry.isIntersecting && entry.target.classList.add('visible'));
    }, { threshold: 0.1 });

    document.querySelectorAll('.scroll-reveal').forEach(el => observer.observe(el));
</script>
</body>
</html>