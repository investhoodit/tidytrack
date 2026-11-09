<?php
session_start();
include '../dp.php';
include '../track.php';

$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$userName = isset($_SESSION['name']) ? $_SESSION['name'] : 'Leader';
$userInitial = strtoupper($userName[0]);

if (!$userId) { die("Unauthorized."); }

// Initial Fetch
$stmt = $conn->prepare("SELECT name, email, phone, bio, profile_picture, status, role, department FROM users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch Projects
$user_projects = [];
$proj_stmt = $conn->prepare("SELECT p.project_name FROM projects_x p JOIN tasks t ON t.project_id = p.project_id WHERE t.assignTo = ? GROUP BY p.project_id");
$proj_stmt->bind_param("s", $userName);
$proj_stmt->execute();
$res = $proj_stmt->get_result();
while ($row = $res->fetch_assoc()) { $user_projects[] = $row['project_name']; }

$message = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. UPLOAD
    if (isset($_POST['upload_picture']) && isset($_FILES['profile_picture'])) {
        $file = $_FILES['profile_picture'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/profiles/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_filename = 'profile_' . $userId . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                if ($user['profile_picture'] && file_exists($user['profile_picture'])) { unlink($user['profile_picture']); }
                $upd_pic = $conn->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
                $upd_pic->bind_param("si", $upload_path, $userId);
                $upd_pic->execute();
                header("Location: profile.php?success=1"); exit();
            }
        }
    }

    // 2. UPDATE PROFILE
    if (isset($_POST['update_profile'])) {
        $upd = $conn->prepare("UPDATE users SET name = ?, phone = ?, department = ?, bio = ? WHERE user_id = ?");
        $upd->bind_param("ssssi", $_POST['name'], $_POST['phone'], $_POST['department'], $_POST['bio'], $userId);
        if ($upd->execute()) { header("Location: profile.php?saved=1"); exit(); }
    }
}

if (isset($_GET['success'])) { $message = "Identity Media Updated!"; $message_type = "success"; }
if (isset($_GET['saved'])) { $message = "System Profile Synced!"; $message_type = "success"; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Account Control | TidyTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass-nav { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); }
        .card-hover { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .card-hover:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.04); }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

    <nav class="glass-nav border-b border-slate-200 py-4 px-8 flex justify-between items-center sticky top-0 z-50">
        <div class="flex items-center gap-6">
            <a href="member_dashboard.php" class="text-xl text-slate-800 hover:scale-110 transition-transform">
                <i class="fas fa-house-chimney"></i>
            </a>
            <div class="h-6 w-[1px] bg-slate-200"></div>
            <div class="text-[10px] font-black tracking-[0.3em] text-slate-400 uppercase">TidyTrack Core v2.4.0</div>
        </div>
        <div class="flex items-center gap-3 bg-emerald-50 px-3 py-1 rounded-full border border-emerald-100">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <span class="text-[9px] font-black text-emerald-700 uppercase">System Active</span>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-6 py-10">
        
        <?php if($message): ?>
            <div id="msg" class="mb-8 p-4 rounded-2xl text-center font-bold shadow-xl transition-all <?php echo ($message_type == 'success') ? 'bg-indigo-600 text-white' : 'bg-rose-500 text-white'; ?>">
                <i class="fas fa-check-circle mr-2"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <div class="lg:col-span-4 space-y-6">
                <div class="bg-white rounded-[2.5rem] p-8 shadow-sm border border-slate-200 text-center card-hover">
                    <div class="relative w-36 h-36 mx-auto mb-6">
                        <div class="w-full h-full rounded-full overflow-hidden border-4 border-white shadow-2xl bg-slate-100 flex items-center justify-center">
                            <?php if ($user['profile_picture']): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>?t=<?php echo time(); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <span class="text-6xl font-black text-slate-200"><?php echo $userInitial; ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="POST" enctype="multipart/form-data">
                            <label for="profile_picture" class="absolute bottom-1 right-1 bg-indigo-600 text-white w-11 h-11 rounded-full flex items-center justify-center cursor-pointer hover:bg-indigo-700 border-4 border-white shadow-lg transition-transform hover:scale-110">
                                <i class="fas fa-camera"></i>
                            </label>
                            <input type="file" name="profile_picture" id="profile_picture" class="hidden" onchange="this.form.submit();">
                            <input type="hidden" name="upload_picture">
                        </form>
                    </div>
                    <h2 class="text-2xl font-black text-slate-800 leading-none"><?php echo htmlspecialchars($user['name']); ?></h2>
                    <p class="text-indigo-600 font-bold text-[10px] uppercase tracking-[0.2em] mt-3 mb-6"><?php echo $user['role']; ?></p>
                    <div class="flex justify-center gap-2">
                         <span class="bg-slate-100 text-slate-500 text-[9px] font-black px-3 py-1 rounded-full border border-slate-200 uppercase">ID: TT-00<?php echo $userId; ?></span>
                    </div>
                </div>

                <div class="bg-white rounded-[2rem] p-8 shadow-sm border border-slate-200">
                    <h3 class="font-black text-slate-400 uppercase text-[10px] tracking-widest mb-6 italic">Assigned Projects</h3>
                    <div class="flex flex-wrap gap-2">
                        <?php if($user_projects): foreach($user_projects as $p): ?>
                            <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 px-3 py-2 rounded-xl">
                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                                <span class="text-xs font-bold text-slate-700"><?php echo $p; ?></span>
                            </div>
                        <?php endforeach; else: ?>
                            <p class="text-xs font-medium text-slate-400">No active project links.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-8 space-y-8">
                <div class="bg-white rounded-[3rem] p-10 shadow-sm border border-slate-200">
                    <div class="flex justify-between items-center mb-10">
                        <h3 class="text-xl font-black text-slate-800 uppercase tracking-tighter">Core Settings</h3>
                        <span class="text-[9px] font-black text-slate-300 tracking-widest uppercase italic underline decoration-indigo-500 decoration-2">Verified Account</span>
                    </div>
                    
                    <form method="POST" class="space-y-8">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Legal Name</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-indigo-500/10 outline-none font-bold transition-all" required>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Deployment Team</label>
                                <select name="department" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-indigo-500/10 outline-none font-bold">
                                    <?php 
                                        $teams = [
                                            "BA Team",
                                            "Administrator Team",
                                            "Front End Developer",
                                            "Backend Developer",
                                            "Fullstack Developer",
                                            "Marketing Team",
                                            "Technical Support",
                                            "Project Manager"
                                        ];
                                        foreach($teams as $t):
                                            echo "<option value='$t' ".($user['department'] == $t ? 'selected' : '').">$t</option>";
                                        endforeach;
                                    ?>
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Contact Line</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-indigo-500/10 outline-none font-bold transition-all">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Verified Email</label>
                                <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" class="w-full p-4 bg-slate-100 border border-slate-200 rounded-2xl text-slate-400 cursor-not-allowed font-bold" disabled title="Contact Admin to change email">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Professional Biography</label>
                            <textarea name="bio" rows="4" 
                                placeholder="Sample: Junior Web Developer focused on TidyTrack ICT operations. Specialized in PHP backend management and system optimization." 
                                class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-indigo-500/10 outline-none font-semibold transition-all"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                            <div class="flex gap-4 mt-2">
                                <span class="text-[9px] text-slate-400 italic">● Explain your role</span>
                                <span class="text-[9px] text-slate-400 italic">● List core skills</span>
                            </div>
                        </div>

                        <button type="submit" name="update_profile" class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl hover:bg-indigo-600 transition-all shadow-2xl shadow-indigo-100 tracking-[0.3em] uppercase text-xs">
                            Sync Profile with Core Engine
                        </button>
                    </form>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="bg-white rounded-[2rem] p-8 shadow-sm border border-slate-200 card-hover">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                                <i class="fas fa-shield-halved"></i>
                            </div>
                            <h4 class="font-black text-slate-800 text-xs uppercase tracking-widest">Security Audit</h4>
                        </div>
                        <button class="w-full py-4 border-2 border-dashed border-slate-200 text-slate-400 rounded-2xl font-black text-[10px] tracking-widest hover:border-indigo-400 hover:text-indigo-500 transition-all uppercase">
                            View Access Logs
                        </button>
                    </div>

                    <div class="bg-rose-50 rounded-[2rem] p-8 border border-rose-100 card-hover">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-10 h-10 bg-rose-100 text-rose-600 rounded-xl flex items-center justify-center">
                                <i class="fas fa-user-slash text-xs"></i>
                            </div>
                            <h4 class="font-black text-rose-800 text-xs uppercase tracking-widest">Privacy Zone</h4>
                        </div>
                        <p class="text-[9px] text-rose-600 font-bold mb-6 opacity-70">ACCOUNT TERMINATION IS RESTRICTED TO SYSTEM ADMINISTRATORS.</p>
                        <div class="w-full py-3 bg-white border border-rose-200 text-rose-300 rounded-xl text-center text-[10px] font-black uppercase tracking-tighter">
                            Request Deletion
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>