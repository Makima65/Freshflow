<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\settings.php

// 1. PHP CACHE BUSTERS & SESSION
header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();

include_once '../includes/db_connection.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../admin_login.php");
    exit;
}

$current_role = $_SESSION['role_name'] ?? '';
$user_role_lower = strtolower($current_role);
$is_admin = in_array($user_role_lower, ['admin', 'super admin']);
$is_super_admin = ($user_role_lower === 'super admin');
$current_user_id = $_SESSION['user_id'];

// --- HANDLE FORM SUBMISSIONS (AJAX) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // ACTION 1: UPDATE PROFILE INFO (Name & Phone)
    if ($_POST['action'] === 'update_profile') {
        $fname = trim($_POST['first_name'] ?? '');
        $lname = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        
        $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, phone_number=? WHERE user_id=?");
        $stmt->bind_param("sssi", $fname, $lname, $phone, $current_user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Profile info updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;
    }

    // ACTION 1.5: INSTANT UPDATE PROFILE IMAGE (From Cropper)
    if ($_POST['action'] === 'update_profile_image') {
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../assets/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true); 
            
            // Cropper sends a blob, we'll save it as PNG
            $newFilename = 'profile_' . $current_user_id . '_' . time() . '.png';
            $targetFile = $uploadDir . $newFilename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetFile)) {
                $db_image_path = 'admin_components/assets/uploads/' . $newFilename;
                $stmt = $conn->prepare("UPDATE users SET profile_image=? WHERE user_id=?");
                $stmt->bind_param("si", $db_image_path, $current_user_id);
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Profile picture updated!', 'new_image' => '../../' . $db_image_path]);
                    exit;
                }
            }
        }
        echo json_encode(['success' => false, 'message' => 'Failed to upload image.']);
        exit;
    }

    // ACTION 2: UPDATE PASSWORD
    if ($_POST['action'] === 'update_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        
        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!$user || !password_verify($current_password, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Incorrect current password.']);
            exit;
        }
        
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $update_stmt->bind_param("si", $new_hash, $current_user_id);
        
        if ($update_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Password updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error updating password.']);
        }
        exit;
    }

    // ACTION 3: UPDATE SYSTEM SETTINGS
    if ($_POST['action'] === 'update_system' && $is_admin) {
        // Only Super Admins can update the timeout
        if ($is_super_admin && isset($_POST['session_timeout'])) {
            $timeout = intval($_POST['session_timeout']);
            try {
                $conn->query("CREATE TABLE IF NOT EXISTS system_settings (id INT PRIMARY KEY AUTO_INCREMENT, session_timeout INT DEFAULT 30)");
                $check = $conn->query("SELECT id FROM system_settings WHERE id=1");
                if ($check->num_rows == 0) {
                    $conn->query("INSERT INTO system_settings (id, session_timeout) VALUES (1, $timeout)");
                } else {
                    $conn->query("UPDATE system_settings SET session_timeout=$timeout WHERE id=1");
                }
            } catch(Throwable $e) {}
        }
        
        echo json_encode(['success' => true, 'message' => 'System settings updated successfully!']);
        exit;
    }
}

// --- FETCH CURRENT USER DATA ---
$stmt = $conn->prepare("SELECT username, first_name, last_name, email, phone_number, profile_image FROM users WHERE user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$my_profile = $stmt->get_result()->fetch_assoc();

$safe_profile_img = !empty($my_profile['profile_image']) ? "../../" . $my_profile['profile_image'] : '';
$initials = strtoupper(substr($my_profile['first_name'] ?? 'U', 0, 1) . substr($my_profile['last_name'] ?? 'U', 0, 1));

// --- FETCH SYSTEM SETTINGS (For Super Admin Auto-Logout) ---
$session_timeout = 30;
if ($is_super_admin) {
    try {
        $sys_query = $conn->query("SELECT session_timeout FROM system_settings WHERE id=1 LIMIT 1");
        if ($sys_query && $sys_query->num_rows > 0) {
            $sys_data = $sys_query->fetch_assoc();
            $session_timeout = intval($sys_data['session_timeout']);
        }
    } catch (Throwable $e) { }
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        tailwind.config = { darkMode: 'class' };
    </script>

    <style>
        :root { --brand-green: #1E3A1D; --brand-cream: #F8F5EE; }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); color: #2B2B2B; transition: background-color 0.3s ease; }
        
        /* --- DARK MODE GLOBAL STYLES (Pulled from your other project) --- */
       /* --- DARK MODE GLOBAL STYLES (Pulled from your other project) --- */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        /* Input Fields */
        .form-input { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; padding: 0.6rem 0.75rem; width: 100%; }
        .form-input:focus { outline: none; border-color: #1E3A1D; box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        
        /* Dark Mode Inputs */
        .dark .form-input { background-color: rgba(30, 41, 59, 0.6); border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.15); }
        
        /* Tabs */
        .tab-btn { transition: all 0.2s; border-left: 3px solid transparent; }
        .tab-btn.active { background-color: #f3f4f6; border-left-color: #1E3A1D; color: #1E3A1D; font-weight: 700; }
        
        /* Dark Mode Tabs */
        .dark .tab-btn { color: #94a3b8; }
        .dark .tab-btn:hover { background-color: rgba(30, 41, 59, 0.6); color: #f8fafc; }
        .dark .tab-btn.active { background-color: rgba(30, 41, 59, 0.9); border-left-color: #4ade80; color: #4ade80; font-weight: 700; }

        .tab-content { display: none; animation: fadeIn 0.3s ease-in-out; }
        .tab-content.active { display: block; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        /* Make Cropper look like a circle */
        .cropper-view-box, .cropper-face { border-radius: 50%; }
        .cropper-modal { background: rgba(0, 0, 0, 0.8); }
    </style>
</head>

<body class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main id="main-content" class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex justify-between items-center mb-8 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">settings</span> Settings
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">Manage your account and system preferences.</p>
            </div>
            
            <div class="flex items-center gap-4">
                <button id="themeToggle" type="button" class="p-2 rounded-full text-gray-500 hover:text-[#1E3A1D] hover:bg-gray-200 dark:hover:bg-gray-700 dark:text-gray-400 dark:hover:text-white transition-all flex items-center justify-center focus:outline-none" title="Toggle Dark/Light Mode">
                    <span class="material-icons text-2xl transition-transform" id="themeIcon">dark_mode</span>
                </button>

                <div class="bg-white dark:bg-slate-900/50 px-4 py-2 rounded-lg shadow-sm border border-gray-200 dark:border-slate-800 flex items-center gap-3">
                    <span class="text-xs text-gray-500 dark:text-slate-400 uppercase font-bold tracking-wider">Role</span>
                    <span class="bg-[#1E3A1D] dark:bg-green-900/80 text-white dark:text-green-300 border border-transparent dark:border-green-800 text-xs px-2 py-1 rounded font-bold"><?= htmlspecialchars($current_role) ?></span>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-hidden flex flex-col md:flex-row gap-6">
            
            <div class="w-full md:w-64 flex-shrink-0 bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 overflow-hidden flex flex-col h-fit">
                <div class="p-4 bg-gray-50 dark:bg-slate-900 border-b border-gray-100 dark:border-slate-800 flex items-center gap-3">
                    <?php if(!empty($safe_profile_img)): ?>
                        <img src="<?= $safe_profile_img ?>" id="sidebarProfileImg" class="w-10 h-10 rounded-full object-cover border border-gray-200 dark:border-slate-700">
                    <?php else: ?>
                        <img src="" id="sidebarProfileImg" class="w-10 h-10 rounded-full object-cover border border-gray-200 dark:border-slate-700 hidden">
                        <div id="sidebarInitial" class="w-10 h-10 rounded-full bg-green-100 dark:bg-slate-800 text-green-700 dark:text-green-400 font-bold flex items-center justify-center text-sm border border-green-200 dark:border-slate-700">
                            <?= $initials ?>
                        </div>
                    <?php endif; ?>
                    <div class="overflow-hidden">
                        <div class="font-bold text-sm text-gray-900 dark:text-white truncate"><?= htmlspecialchars($my_profile['first_name'] . ' ' . $my_profile['last_name']) ?></div>
                        <div class="text-xs text-gray-500 dark:text-slate-400 font-mono truncate">@<?= htmlspecialchars($my_profile['username']) ?></div>
                    </div>
                </div>
                
                <nav class="flex flex-col py-2">
                    <button onclick="switchTab('profile')" id="tab-profile" class="tab-btn active text-left px-5 py-3 text-sm font-medium flex items-center gap-3"><span class="material-icons text-lg">person</span> My Profile</button>
                    <button onclick="switchTab('security')" id="tab-security" class="tab-btn text-left px-5 py-3 text-sm font-medium flex items-center gap-3"><span class="material-icons text-lg">security</span> Security & Login</button>
                    <?php if($is_admin): ?>
                    <div class="mx-4 my-2 border-t border-gray-100 dark:border-slate-800"></div>
                    <button onclick="switchTab('system')" id="tab-system" class="tab-btn text-left px-5 py-3 text-sm font-medium flex items-center gap-3"><span class="material-icons text-lg">tune</span> System Preferences</button>
                    <?php endif; ?>
                </nav>
            </div>

            <div class="flex-1 bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 overflow-y-auto custom-scroll relative">
                
                <div id="content-profile" class="tab-content active p-6 md:p-8">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2 border-b border-gray-200 dark:border-slate-800 pb-4">
                        <span class="material-icons text-[#1E3A1D] dark:text-green-400">badge</span> Personal Information
                    </h2>
                    
                    <div class="flex items-center gap-6 mb-8">
                        <div class="relative group w-28 h-28 flex-shrink-0">
                            <img id="profilePreview" src="<?= !empty($safe_profile_img) ? $safe_profile_img : 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=' ?>" 
                                 class="w-full h-full rounded-full object-cover border-4 border-white dark:border-slate-800 shadow-md <?= empty($safe_profile_img) ? 'bg-gray-200 dark:bg-slate-800' : '' ?>">
                            
                            <?php if(empty($safe_profile_img)): ?>
                                <span id="placeholderIcon" class="material-icons text-4xl text-gray-400 dark:text-slate-600 absolute inset-0 flex items-center justify-center pointer-events-none">add_a_photo</span>
                            <?php endif; ?>
                            
                            <div class="absolute inset-0 bg-black bg-opacity-70 rounded-full flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity space-y-1 overflow-hidden z-10">
                                <button type="button" onclick="viewProfileImage()" class="text-white text-[10px] font-bold uppercase tracking-wider hover:text-green-300 transition-colors flex items-center gap-1 w-full justify-center py-1">
                                    <span class="material-icons text-[14px]">visibility</span> View
                                </button>
                                <div class="w-12 border-t border-white/30"></div>
                                <button type="button" onclick="document.getElementById('profileImageInput').click();" class="text-white text-[10px] font-bold uppercase tracking-wider hover:text-green-300 transition-colors flex items-center gap-1 w-full justify-center py-1">
                                    <span class="material-icons text-[14px]">folder</span> Upload
                                </button>
                                <div class="w-12 border-t border-white/30"></div>
                                <button type="button" onclick="openCameraModal()" class="text-white text-[10px] font-bold uppercase tracking-wider hover:text-green-300 transition-colors flex items-center gap-1 w-full justify-center py-1">
                                    <span class="material-icons text-[14px]">photo_camera</span> Camera
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-900 dark:text-white">Profile Picture</h3>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">JPG or PNG. Picture saves automatically when cropped.</p>
                            <input type="file" id="profileImageInput" accept="image/png, image/jpeg" class="hidden" onchange="handleFileSelect(event)">
                        </div>
                    </div>

                    <form id="profileForm" onsubmit="submitForm(event, 'profileForm', 'update_profile')" class="max-w-2xl border-t border-gray-100 dark:border-slate-800 pt-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">First Name</label>
                                <input type="text" name="first_name" class="form-input" value="<?= htmlspecialchars($my_profile['first_name']) ?>">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Last Name</label>
                                <input type="text" name="last_name" class="form-input" value="<?= htmlspecialchars($my_profile['last_name']) ?>">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Email Address</label>
                                <input type="email" class="form-input bg-gray-50 dark:bg-slate-800/50 text-gray-500 dark:text-slate-500 cursor-not-allowed" value="<?= htmlspecialchars($my_profile['email']) ?>" readonly>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Phone Number</label>
                                <input type="text" name="phone_number" class="form-input font-mono" value="<?= htmlspecialchars($my_profile['phone_number'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="flex justify-end pt-4 border-t border-gray-100 dark:border-slate-800">
                            <button type="submit" class="bg-[#1E3A1D] dark:bg-green-700 text-white px-6 py-2.5 rounded-lg text-sm font-bold shadow-md hover:bg-[#2a4e29] dark:hover:bg-green-600 transition transform active:scale-95 flex items-center gap-2">
                                <span class="material-icons text-sm">save</span> Save Info
                            </button>
                        </div>
                    </form>
                </div>

                <div id="content-security" class="tab-content p-6 md:p-8">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2 border-b border-gray-200 dark:border-slate-800 pb-4"><span class="material-icons text-[#1E3A1D] dark:text-green-400">gpp_good</span> Security & Authentication</h2>
                    <div class="max-w-2xl">
                        <h3 class="font-bold text-gray-900 dark:text-white mb-4 text-sm">Change Password</h3>
                        <form id="passwordForm" onsubmit="submitForm(event, 'passwordForm', 'update_password')">
                            <div class="space-y-4 mb-2">
                                <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Current Password</label><input type="password" name="current_password" class="form-input" required></div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">New Password</label><input type="password" id="new_password" name="new_password" class="form-input" required onkeyup="checkStrength(); checkMatch();"></div>
                                    <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Confirm New Password</label><input type="password" id="confirm_password" name="confirm_password" class="form-input" required onkeyup="checkMatch();"></div>
                                </div>
                            </div>
                            <ul class="text-xs space-y-1 mb-6 mt-3 bg-gray-50 dark:bg-slate-800/50 p-3 rounded border border-gray-100 dark:border-slate-700">
                                <li id="rule-len" class="text-gray-400 dark:text-slate-500 flex items-center gap-1 transition-colors"><span class="material-icons text-[14px]">circle</span> At least 8 characters</li>
                                <li id="rule-num" class="text-gray-400 dark:text-slate-500 flex items-center gap-1 transition-colors"><span class="material-icons text-[14px]">circle</span> At least 1 number</li>
                                <li id="rule-char" class="text-gray-400 dark:text-slate-500 flex items-center gap-1 transition-colors"><span class="material-icons text-[14px]">circle</span> At least 1 special char</li>
                                <li id="rule-match" class="text-gray-400 dark:text-slate-500 flex items-center gap-1 transition-colors"><span class="material-icons text-[14px]">circle</span> Passwords match</li>
                            </ul>
                            <div class="flex justify-end pt-4 border-t border-gray-100 dark:border-slate-800">
                                <button type="submit" id="btnUpdatePassword" class="bg-gray-800 dark:bg-slate-700 text-white px-6 py-2.5 rounded-lg text-sm font-bold shadow-md hover:bg-gray-900 dark:hover:bg-slate-600 transition flex items-center gap-2" disabled>
                                    <span class="material-icons text-sm">key</span> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if($is_admin): ?>
                <div id="content-system" class="tab-content p-6 md:p-8">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2 border-b border-gray-200 dark:border-slate-800 pb-4"><span class="material-icons text-[#1E3A1D] dark:text-green-400">domain</span> Global System Preferences</h2>
                    <form id="systemForm" onsubmit="submitForm(event, 'systemForm', 'update_system')" class="max-w-3xl">
                        <div class="bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-400 p-4 mb-8 text-sm text-yellow-800 dark:text-yellow-300"><strong>Warning:</strong> Changes made here affect the entire application immediately.</div>
                        <h3 class="font-bold text-gray-900 dark:text-white mb-4 text-sm">Company Details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
                            <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Company Name</label><input type="text" class="form-input font-bold" value="Freshflow Enterprises"></div>
                            <div><label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Support Email</label><input type="email" class="form-input" value="support@freshflow.site"></div>
                        </div>

                        <?php if($is_super_admin): ?>
                        <h3 class="font-bold text-gray-900 dark:text-white mb-4 text-sm border-t dark:border-slate-800 pt-6 border-gray-100">Security Controls</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Auto-Logout (Session Timeout)</label>
                                <select name="session_timeout" class="form-input bg-white dark:bg-slate-800 cursor-pointer font-bold">
                                    <option value="1" <?= $session_timeout == 1 ? 'selected' : '' ?>>1 Minute of Inactivity</option>
                                    <option value="5" <?= $session_timeout == 5 ? 'selected' : '' ?>>5 Minutes of Inactivity</option>
                                    <option value="15" <?= $session_timeout == 15 ? 'selected' : '' ?>>15 Minutes of Inactivity</option>
                                    <option value="30" <?= $session_timeout == 30 ? 'selected' : '' ?>>30 Minutes of Inactivity</option>
                                    <option value="60" <?= $session_timeout == 60 ? 'selected' : '' ?>>1 Hour of Inactivity</option>
                                </select>
                                <p class="text-[10px] text-gray-400 dark:text-slate-500 mt-1">If a user is inactive for this long, they are forced to log in again.</p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-end pt-4 border-t border-gray-100 dark:border-slate-800">
                            <button type="submit" class="bg-[#1E3A1D] dark:bg-green-700 text-white px-6 py-2.5 rounded-lg text-sm font-bold shadow-md hover:bg-[#2a4e29] dark:hover:bg-green-600 transition transform active:scale-95 flex items-center gap-2">
                                <span class="material-icons text-sm">save_as</span> Save System Settings
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </main>

    <div id="cameraModal" class="fixed inset-0 z-[120] bg-black bg-opacity-90 hidden flex items-center justify-center backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl overflow-hidden w-full max-w-md flex flex-col">
            <div class="bg-[#1E3A1D] p-4 text-white flex justify-between items-center">
                <h2 class="font-bold flex items-center gap-2"><span class="material-icons">photo_camera</span> Take Photo</h2>
                <button type="button" onclick="closeCameraModal()" class="text-gray-300 hover:text-white"><span class="material-icons">close</span></button>
            </div>
            <div class="p-4 bg-black flex justify-center items-center h-[350px] relative">
                <video id="cameraVideo" class="w-full h-full object-cover rounded-lg transform scale-x-[-1]" autoplay playsinline></video>
                <canvas id="cameraCanvas" class="hidden"></canvas>
            </div>
            <div class="p-4 bg-gray-50 flex justify-center border-t">
                <button type="button" onclick="capturePhoto()" class="bg-[#1E3A1D] text-white w-16 h-16 rounded-full border-4 border-gray-300 shadow-lg flex items-center justify-center hover:bg-[#2a4e29] hover:border-gray-400 transition-all active:scale-95">
                    <span class="material-icons text-3xl">camera</span>
                </button>
            </div>
        </div>
    </div>

    <div id="cropModal" class="fixed inset-0 z-[130] bg-black bg-opacity-90 hidden flex items-center justify-center backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl overflow-hidden w-full max-w-lg flex flex-col">
            <div class="bg-gray-900 p-4 text-white flex justify-between items-center">
                <h2 class="font-bold flex items-center gap-2"><span class="material-icons">crop</span> Adjust Picture</h2>
                <button type="button" onclick="closeCropModal()" class="text-gray-400 hover:text-white"><span class="material-icons">close</span></button>
            </div>
            <div class="p-4 bg-gray-100 flex justify-center items-center h-[400px]">
                <div class="w-full h-full max-w-[350px] max-h-[350px]">
                    <img id="imageToCrop" src="" class="max-w-full block">
                </div>
            </div>
            <div class="p-4 bg-white flex justify-end gap-3 border-t">
                <button type="button" onclick="closeCropModal()" class="px-5 py-2 text-gray-600 font-bold hover:bg-gray-100 rounded-lg text-sm">Cancel</button>
                <button type="button" onclick="cropAndUpload()" id="btnCropSave" class="bg-[#1E3A1D] text-white px-6 py-2 rounded-lg text-sm font-bold shadow-md hover:bg-[#2a4e29] flex items-center gap-2">
                    <span class="material-icons text-sm">check_circle</span> Save Picture
                </button>
            </div>
        </div>
    </div>

    <div id="imageViewerModal" class="fixed inset-0 z-[110] bg-black bg-opacity-90 hidden flex items-center justify-center backdrop-blur-sm transition-opacity" onclick="closeImageViewer()">
        <div class="relative max-w-4xl max-h-[90vh] p-4 flex flex-col items-center" onclick="event.stopPropagation();">
            <button type="button" onclick="closeImageViewer()" class="absolute -top-12 right-0 text-white hover:text-gray-300 transition focus:outline-none"><span class="material-icons text-4xl">close</span></button>
            <img id="fullImageView" src="" class="max-w-full max-h-[85vh] rounded-lg shadow-2xl object-contain border-2 border-white/20">
        </div>
    </div>

    <div id="flashMessage" class="fixed bottom-6 right-6 z-[100] bg-[#1E3A1D] dark:bg-slate-800 dark:border dark:border-slate-700 text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform translate-y-20 transition-all duration-300 opacity-0 pointer-events-none">
        <span class="material-icons text-green-400" id="flashIcon">check_circle</span>
        <div><h4 class="font-bold text-sm">Notification</h4><p class="text-xs text-gray-300" id="flashText"></p></div>
    </div>

    <script>
        // --- UI & TABS ---
        function switchTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            document.getElementById('content-' + tabId).classList.add('active');
        }

        // --- THEME TOGGLE LOGIC ---
        const themeToggleBtn = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');

        if (document.documentElement.classList.contains('dark')) {
            themeIcon.textContent = 'light_mode';
        } else {
            themeIcon.textContent = 'dark_mode';
        }

        themeToggleBtn.addEventListener('click', function() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
                themeIcon.textContent = 'dark_mode';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
                themeIcon.textContent = 'light_mode';
            }
        });

        const showFlash = (msg, type = 'success') => {
            document.getElementById('flashText').textContent = msg;
            const fm = document.getElementById('flashMessage');
            
            if (document.documentElement.classList.contains('dark')) {
                fm.className = `fixed bottom-6 right-6 z-[100] ${type === 'error' ? 'bg-red-900 border border-red-700' : 'bg-slate-800 border border-slate-700'} text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform transition-all duration-300`;
            } else {
                fm.className = `fixed bottom-6 right-6 z-[100] ${type === 'error' ? 'bg-red-700' : 'bg-[#1E3A1D]'} text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform transition-all duration-300`;
            }
            
            document.getElementById('flashIcon').textContent = type === 'error' ? 'error' : 'check_circle';
            fm.classList.remove('translate-y-20', 'opacity-0');
            setTimeout(() => fm.classList.add('translate-y-20', 'opacity-0'), 3000);
        };

        function viewProfileImage() {
            const imgSrc = document.getElementById('profilePreview').src;
            if(imgSrc.includes('data:image/gif')) return showFlash("No picture to view.", "error");
            document.getElementById('fullImageView').src = imgSrc;
            document.getElementById('imageViewerModal').classList.remove('hidden');
        }
        function closeImageViewer() { document.getElementById('imageViewerModal').classList.add('hidden'); }

        // --- CAMERA LOGIC ---
        let videoStream = null;
        
        async function openCameraModal() {
            try {
                videoStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } });
                document.getElementById('cameraVideo').srcObject = videoStream;
                document.getElementById('cameraModal').classList.remove('hidden');
            } catch (err) {
                showFlash("Camera access denied or unavailable.", "error");
            }
        }

        function closeCameraModal() {
            document.getElementById('cameraModal').classList.add('hidden');
            if(videoStream) videoStream.getTracks().forEach(track => track.stop());
        }

        function capturePhoto() {
            const video = document.getElementById('cameraVideo');
            const canvas = document.getElementById('cameraCanvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            
            // Mirror flip matching the video view
            ctx.translate(canvas.width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const dataUrl = canvas.toDataURL('image/png');
            closeCameraModal();
            openCropModal(dataUrl);
        }

        // --- CROPPER LOGIC ---
        let cropper = null;

        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (e) => openCropModal(e.target.result);
                reader.readAsDataURL(file);
            }
            event.target.value = ''; // Reset input
        }

        function openCropModal(imageSrc) {
            const image = document.getElementById('imageToCrop');
            image.src = imageSrc;
            document.getElementById('cropModal').classList.remove('hidden');
            
            if(cropper) cropper.destroy();
            cropper = new Cropper(image, {
                aspectRatio: 1, // Force Square/Circle
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
                restore: false,
                guides: false,
                center: false,
                highlight: false,
                cropBoxMovable: false,
                cropBoxResizable: false,
                toggleDragModeOnDblclick: false,
            });
        }

        function closeCropModal() {
            document.getElementById('cropModal').classList.add('hidden');
            if(cropper) cropper.destroy();
        }

        async function cropAndUpload() {
            if(!cropper) return;
            const btn = document.getElementById('btnCropSave');
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Saving...';
            btn.disabled = true;

            // Get cropped image as a File Blob
            cropper.getCroppedCanvas({ width: 400, height: 400 }).toBlob(async (blob) => {
                const formData = new FormData();
                formData.append('action', 'update_profile_image');
                formData.append('profile_image', blob, 'profile.png');

                try {
                    const response = await fetch('settings.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if(result.success) {
                        // Instantly update pictures on screen
                        document.getElementById('profilePreview').src = result.new_image;
                        document.getElementById('profilePreview').classList.remove('bg-gray-200', 'dark:bg-slate-800');
                        document.getElementById('sidebarProfileImg').src = result.new_image;
                        document.getElementById('sidebarProfileImg').classList.remove('hidden');
                        const icon = document.getElementById('placeholderIcon');
                        if(icon) icon.style.display = 'none';
                        const init = document.getElementById('sidebarInitial');
                        if(init) init.style.display = 'none';
                        
                        showFlash(result.message);
                        closeCropModal();
                    } else {
                        showFlash(result.message, 'error');
                    }
                } catch(e) { showFlash("Upload failed.", 'error'); }
                
                btn.innerHTML = '<span class="material-icons text-sm">check_circle</span> Save Picture';
                btn.disabled = false;
            }, 'image/png');
        }

        // --- PASSWORD & STANDARD FORMS ---
        let isPwdValid = false; let isMatchValid = false;
        function checkStrength() {
            const pwd = document.getElementById('new_password').value;
            const setStatus = (id, v) => {
                const el = document.getElementById(id);
                el.className = v ? "text-green-600 dark:text-green-400 font-bold flex items-center gap-1" : "text-gray-400 dark:text-slate-500 flex items-center gap-1";
                el.querySelector('.material-icons').innerText = v ? "check_circle" : "circle";
            };
            const len = pwd.length >= 8; const num = /[0-9]/.test(pwd); const char = /[^A-Za-z0-9]/.test(pwd);
            setStatus('rule-len', len); setStatus('rule-num', num); setStatus('rule-char', char);
            isPwdValid = (len && num && char); toggleSubmitBtn();
        }
        function checkMatch() {
            const pwd = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const el = document.getElementById('rule-match');
            const match = (pwd && pwd === confirm);
            el.className = match ? "text-green-600 dark:text-green-400 font-bold flex items-center gap-1" : "text-gray-400 dark:text-slate-500 flex items-center gap-1";
            el.querySelector('.material-icons').innerText = match ? "check_circle" : "circle";
            isMatchValid = match; toggleSubmitBtn();
        }
        function toggleSubmitBtn() {
            const btn = document.getElementById('btnUpdatePassword');
            btn.disabled = !(isPwdValid && isMatchValid);
            btn.className = btn.disabled ? "bg-gray-800 text-white px-6 py-2.5 rounded-lg text-sm font-bold shadow-md opacity-50 cursor-not-allowed flex items-center gap-2" : "bg-gray-800 text-white px-6 py-2.5 rounded-lg text-sm font-bold shadow-md hover:bg-gray-900 transition flex items-center gap-2";
        }

        async function submitForm(event, formId, actionName) {
            event.preventDefault();
            const form = document.getElementById(formId);
            const btn = event.target.querySelector('button[type="submit"]');
            const orig = btn.innerHTML;
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span>...'; btn.disabled = true;

            const fd = new FormData(form); fd.append('action', actionName);
            try {
                const res = await fetch('settings.php', { method: 'POST', body: fd });
                const data = await res.json();
                if(data.success) {
                    showFlash(data.message);
                    if (actionName === 'update_password') { form.reset(); checkStrength(); checkMatch(); }
                } else showFlash(data.message, 'error');
            } catch (e) { showFlash("Error saving.", 'error'); }
            finally { btn.innerHTML = orig; btn.disabled = actionName === 'update_password' ? (!isPwdValid || !isMatchValid) : false; }
        }
    </script>
</body>
</html>