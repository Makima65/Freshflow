<?php
// C:\xampp\htdocs\RalphPHP\admin_components\pages\users.php

// 1. PHP CACHE BUSTERS
header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();
ini_set('display_errors', 0); // Prevent DB warnings from breaking UI
ini_set('log_errors', 1);

include_once '../includes/db_connection.php';

// --- SAFE DATABASE AUTO-PATCHER (Fixes 500 Error) ---
try {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'users'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $col1 = $conn->query("SHOW COLUMNS FROM users LIKE 'phone_number'");
        if ($col1 && $col1->num_rows == 0) {
            @$conn->query("ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) NULL AFTER email");
        }
        $col2 = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
        if ($col2 && $col2->num_rows == 0) {
            @$conn->query("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL AFTER status");
        }
        $col3 = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'");
        if ($col3 && $col3->num_rows == 0) {
            @$conn->query("ALTER TABLE users ADD COLUMN last_login DATETIME NULL AFTER status");
        }
    }
} catch (Throwable $e) { 
    // Silently ignore strict DB errors to prevent HTTP 500
}

// --- CONFIGURATION ---
$auditHelperPath = '../includes/audit_helper.php';
if (file_exists($auditHelperPath)) include_once $auditHelperPath;
else if (!function_exists('log_audit_action')) { function log_audit_action($a, $b, $c, $d = []) { return true; } }

// --- SECURITY CHECK (ALLOW SUPER ADMIN & ADMIN) ---
$allowed_roles = ['admin', 'Super Admin'];
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role_name"], $allowed_roles)) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Authentication required.']);
        exit;
    }
    header("location: ../admin_login.php");
    exit;
}

$current_role = $_SESSION['role_name'];
$current_user_id = $_SESSION['user_id'] ?? 0;

// --- PHOTO UPLOAD HELPER ---
function handle_profile_upload($file_key) {
    if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] == 0) {
        $target_dir = "../../assets/img/users/";
        if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
        
        $file_ext = pathinfo($_FILES[$file_key]["name"], PATHINFO_EXTENSION);
        $file_name = uniqid('user_') . '_' . time() . '.' . $file_ext;
        $target_file = $target_dir . $file_name;
        
        $check = @getimagesize($_FILES[$file_key]["tmp_name"]);
        if($check !== false) {
            if (move_uploaded_file($_FILES[$file_key]["tmp_name"], $target_file)) {
                return "assets/img/users/" . $file_name;
            }
        }
    }
    return NULL;
}

// --- HANDLE REQUESTS ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    ob_clean();
    header('Content-Type: application/json');
    
    // ============================================================
    // CSRF SECURITY CHECK
    // ============================================================
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Security Warning: Invalid CSRF Token. Please refresh the page.']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // 1. DELETE USER
    if ($action === 'delete_user') {
        $target_id = intval($_POST['user_id']);

        if ($target_id == $current_user_id) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
            exit;
        }
        
        $target = $conn->query("SELECT role, username FROM users WHERE user_id = $target_id")->fetch_assoc();
        if (!$target) { 
            echo json_encode(['success' => false, 'message' => 'User not found.']); 
            exit; 
        }

        if ($target['role'] === 'Super Admin' && $current_role !== 'Super Admin') {
            echo json_encode(['success' => false, 'message' => 'Insufficient permissions: You cannot delete a Super Admin.']); 
            exit;
        }
        
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            if(function_exists('log_audit_action')) log_audit_action('Delete User', 'Users', "Deleted user: " . $target['username']);
            echo json_encode(['success' => true, 'message' => 'User deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;
    }

    // 2. UNLOCK USER (SUPER ADMIN ONLY)
    if ($action === 'unlock_user') {
        if ($current_role !== 'Super Admin') {
            echo json_encode(['success' => false, 'message' => 'Only Super Admin can unlock accounts.']);
            exit;
        }

        $target_id = intval($_POST['user_id']);
        $stmt = $conn->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $target_id);
        
        if ($stmt->execute()) {
            if(function_exists('log_audit_action')) log_audit_action('Security Unlock', 'Users', "Super Admin unlocked user ID: $target_id");
            echo json_encode(['success' => true, 'message' => 'User successfully unlocked.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;
    }

    // 3. ADD / EDIT USER
    if ($action === 'save_user') {
        $user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $username = trim($_POST['username']);
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $phone_number = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email']); 
        $password = trim($_POST['password'] ?? '');
        $role = $_POST['role'];
        
        // If user is editing themselves, force status to Active to prevent self-deactivation
        if ($user_id == $current_user_id) {
            $status = 'Active';
        } else {
            $status = $_POST['status'] ?? 'Active';
        }

        if ($role === 'Super Admin' && $current_role !== 'Super Admin') {
            echo json_encode(['success' => false, 'message' => 'You are not authorized to assign the Super Admin role.']); exit;
        }

        if ($user_id > 0) {
            $check_role = $conn->query("SELECT role FROM users WHERE user_id = $user_id")->fetch_assoc();
            if ($check_role && $check_role['role'] === 'Super Admin' && $current_role !== 'Super Admin') {
                echo json_encode(['success' => false, 'message' => 'You cannot edit a Super Admin account.']); exit;
            }
        }

        if (empty($username) || empty($first_name) || empty($last_name) || empty($email)) { 
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']); exit; 
        }

        $checkUser = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $checkUser->bind_param("si", $username, $user_id);
        $checkUser->execute();
        if ($checkUser->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Username taken.']); exit;
        }

        $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $checkEmail->bind_param("si", $email, $user_id);
        $checkEmail->execute();
        if ($checkEmail->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Email address already in use.']); exit;
        }

        $profile_image = handle_profile_upload('profile_image');

        if ($user_id > 0) {
            // UPDATE EXISTING USER
            $query = "UPDATE users SET username=?, first_name=?, last_name=?, phone_number=?, email=?, role=?, status=?";
            $params = [$username, $first_name, $last_name, $phone_number, $email, $role, $status];
            $types = "sssssss";

            if (!empty($password)) {
                $query .= ", password=?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
                $types .= "s";
            }
            if ($profile_image) {
                $query .= ", profile_image=?";
                $params[] = $profile_image;
                $types .= "s";
            }
            $query .= " WHERE user_id=?";
            $params[] = $user_id;
            $types .= "i";

            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                if(function_exists('log_audit_action')) log_audit_action('Update User', 'Users', "Updated user: $username ($status)");
                echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error.']);
            }
        } else {
            // INSERT NEW USER
            $token = bin2hex(random_bytes(32));
            
            // Auto-generate a secure random password if left empty during creation
            if (empty($password)) {
                $password = bin2hex(random_bytes(8)); 
            }

            $stmt = $conn->prepare("INSERT INTO users (username, password, first_name, last_name, phone_number, email, role, status, verification_token, is_verified, login_attempts, profile_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)");
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt->bind_param("ssssssssss", $username, $hashed_password, $first_name, $last_name, $phone_number, $email, $role, $status, $token, $profile_image);
            
            if ($stmt->execute()) {
                require_once '../mail_config.php'; 
                $verifyLink = "https://freshflow.site/admin_components/verify_account.php?token=$token";
                
                $unique_id = uniqid(); // Gmail Anti-trim hack
                
                // --- HOSTINGER CLONE + FRESHFLOW THEME TEMPLATE FOR NEW USER ---
                $emailBody = <<<HTML
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>Welcome to Freshflow</title>
                </head>
                <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #F8F5EE;">
                    <div style="max-width: 600px; margin: 0 auto; padding: 40px 20px; text-align: center; background-color: #F8F5EE;">
                        
                        <img src="https://freshflow.site/admin_components/assets/img/FreshflowGmailLogo2.png" alt="Freshflow" width="170" style="margin-bottom: 25px; margin-top: 0; display: inline-block;">
                        
                        <h2 style="color: #111827; font-size: 24px; font-weight: 700; margin-bottom: 25px; margin-top: 0;">Welcome to Freshflow!</h2>
                        
                        <p style="color: #374151; font-size: 15px; margin-bottom: 15px; margin-top: 0;">Hello <b>{$first_name}</b>,</p>
                        <p style="color: #374151; font-size: 15px; margin-bottom: 15px; margin-top: 0;">An administrator has created an account for you ({$email}).</p>
                        <p style="color: #374151; font-size: 15px; margin-bottom: 30px; margin-top: 0;">Please click the button below to verify your email and set your password:</p>
                        
                        <div style="margin-bottom: 35px;">
                            <a href="{$verifyLink}" style="background-color: #1E3A1D; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 4px; font-size: 16px; font-weight: 600; display: inline-block;">Verify Email</a>
                        </div>
                        
                        <hr style="border: none; border-top: 1px solid #d1d5db; margin: 0 auto 25px auto; max-width: 500px;">
                        
                        <p style="color: #6b7280; font-size: 12px; line-height: 1.6; margin-bottom: 20px; max-width: 500px; margin-left: auto; margin-right: auto;">
                            You have received this email because an account was created for you at Freshflow. If you did not request this, you can safely ignore this email.
                        </p>
                        <p style="color: #9ca3af; font-size: 12px; margin-top: 20px;">
                            &copy; 2024-2026 Freshflow.
                        </p>

                        <div style="display: none; white-space: nowrap; font: 15px courier; line-height: 0;">
                            &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; 
                            Thread-Breaker: {$unique_id}
                        </div>
                    </div>
                </body>
                </html>
HTML;
                try {
                    $mail = getMailer();
                    $mail->isHTML(true); 
                    $mail->addAddress($email, "$first_name $last_name");
                    $mail->Subject = "Welcome to FreshFlow - Verify Your Account";
                    $mail->Body = $emailBody;
                    $mail->send();

                    if(function_exists('log_audit_action')) log_audit_action('Create User', 'Users', "Created user $username and sent verification email.");
                    
                    echo json_encode(['success' => true, 'message' => 'User created! Verification email sent successfully.']);
                } catch (Exception $e) {
                    echo json_encode(['success' => true, 'message' => 'User created, but email failed: ' . $mail->ErrorInfo]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            }
        }
        exit;
    }
}
ob_end_flush();

// Generate CSRF for Page
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =================================================================================
// FILTER & PAGINATION LOGIC
// =================================================================================
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search_query = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

$where_clauses = [];
$params = [];
$types = '';

if ($search_query !== '') {
    $where_clauses[] = "(username LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $like_search = "%$search_query%";
    array_push($params, $like_search, $like_search, $like_search, $like_search);
    $types .= 'ssss';
}
if ($role_filter !== 'all') {
    $where_clauses[] = "role = ?";
    $params[] = $role_filter;
    $types .= 's';
}
if ($status_filter !== 'all') {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$where_sql = '';
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// Total records for pagination
$count_sql = "SELECT COUNT(*) as total FROM users $where_sql";
$stmt_count = $conn->prepare($count_sql);
if ($types) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Fetch paginated data
$data_sql = "SELECT * FROM users $where_sql ORDER BY role DESC, created_at DESC LIMIT ? OFFSET ?";
$stmt_data = $conn->prepare($data_sql);
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';
$stmt_data->bind_param($types, ...$params);
$stmt_data->execute();
$users = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch Metrics for Cards (Unfiltered)
$metrics = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN LOWER(role) IN ('admin', 'super admin') THEN 1 ELSE 0 END) as admins,
        SUM(CASE WHEN LOWER(role) = 'staff' OR LOWER(role) = 'standard user' THEN 1 ELSE 0 END) as staff
    FROM users
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshFlow - User Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
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
        
        /* DARK MODE BODY */
        .dark body {
            background-color: #000000;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
            background-size: 16px 16px;
            color: #f8fafc;
        }

        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        .modal-z { z-index: 50; }
        
        /* FORM INPUTS (Light & Dark Support) */
        .form-input { background-color: #ffffff; border: 1px solid #d1d5db; color: #374151; border-radius: 0.5rem; transition: all 0.2s; padding: 0.5rem 0.75rem; }
        .form-input:focus { outline: none; border-color: #1E3A1D; box-shadow: 0 0 0 3px rgba(30, 58, 29, 0.1); }
        
        .dark .form-input { background-color: #1e293b; border-color: #334155; color: #f8fafc; }
        .dark .form-input:focus { border-color: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.1); }

        /* STATUS BADGES */
        .status-Active { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .dark .status-Active { background-color: rgba(22, 101, 52, 0.2); color: #86efac; border-color: rgba(74, 222, 128, 0.3); }

        .status-Inactive { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .dark .status-Inactive { background-color: rgba(153, 27, 27, 0.2); color: #fca5a5; border-color: rgba(248, 113, 113, 0.3); }

        .status-Locked { background-color: #fee2e2; color: #ef4444; border: 1px solid #f87171; }
        .dark .status-Locked { background-color: rgba(239, 68, 68, 0.2); color: #fca5a5; border-color: rgba(248, 113, 113, 0.3); }
    </style>
    
    <script>
        (function() {
            window.onpageshow = function(event) {
                if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
                    document.body.style.display = 'none';
                    window.location.reload(); 
                }
            };
        })();
        
        // Expose current user ID to JS
        const currentUserId = <?= intval($current_user_id) ?>;
    </script>
</head>

<body style="display:none;" id="secure-body" class="flex h-screen overflow-hidden">

    <?php include '../includes/sidebar.php'; ?>

    <main class="ml-20 flex-1 flex flex-col h-screen overflow-hidden relative transition-all duration-300 p-6 md:p-8">
        
        <header class="flex justify-between items-center mb-8 flex-shrink-0">
            <div>
                <h1 class="text-3xl font-bold text-[#1E3A1D] dark:text-white tracking-tight flex items-center gap-3">
                    <span class="material-icons text-3xl dark:text-green-400">group</span> User Management
                </h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1 ml-11">
                    Manage system access, roles, and security for your team.
                </p>
            </div>
            <button onclick="openModal()" class="bg-[#1E3A1D] dark:bg-green-600 text-white hover:bg-[#2a4e29] dark:hover:bg-green-500 px-5 py-2.5 rounded-lg text-sm font-bold shadow-lg transition transform active:scale-95 flex items-center gap-2">
                <span class="material-icons text-sm">person_add</span> Add New User
            </button>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6 flex-shrink-0">
            
            <div class="bg-white dark:bg-slate-900/80 border border-blue-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(59,130,246,0.2)] dark:hover:shadow-[0_0_20px_rgba(59,130,246,0.3)] dark:hover:border-blue-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Total Users</p>
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= $metrics['total'] ?? 0 ?></p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">people</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-green-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(34,197,94,0.2)] dark:hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] dark:hover:border-green-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">Active Status</p>
                    <p class="text-3xl font-bold text-green-700 dark:text-green-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= $metrics['active'] ?? 0 ?></p>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg group-hover:bg-green-200 dark:group-hover:bg-green-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">verified_user</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-orange-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(249,115,22,0.2)] dark:hover:shadow-[0_0_20px_rgba(249,115,22,0.3)] dark:hover:border-orange-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors">System Admins</p>
                    <p class="text-3xl font-bold text-orange-600 dark:text-orange-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= $metrics['admins'] ?? 0 ?></p>
                </div>
                <div class="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400 rounded-lg group-hover:bg-orange-200 dark:group-hover:bg-orange-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">admin_panel_settings</span>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900/80 border border-purple-100 dark:border-slate-800 p-5 rounded-xl flex items-center justify-between cursor-default group hover:-translate-y-1 hover:shadow-[0_8px_20px_rgba(168,85,247,0.2)] dark:hover:shadow-[0_0_20px_rgba(168,85,247,0.3)] dark:hover:border-purple-400 transition-all duration-300 shadow-sm">
                <div class="text-left">
                    <p class="text-xs font-bold text-gray-400 dark:text-slate-400 uppercase tracking-wider group-hover:text-purple-600 dark:group-hover:text-purple-400 transition-colors">Staff Members</p>
                    <p class="text-3xl font-bold text-purple-600 dark:text-purple-400 mt-1 font-mono group-hover:scale-110 transition-transform origin-left"><?= $metrics['staff'] ?? 0 ?></p>
                </div>
                <div class="p-3 bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 rounded-lg group-hover:bg-purple-200 dark:group-hover:bg-purple-800/50 transition-colors">
                    <span class="material-icons group-hover:drop-shadow-[0_0_8px_currentColor] transition-all">badge</span>
                </div>
            </div>

        </div>

        <form method="GET" id="filterForm" class="bg-white dark:bg-slate-900/80 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-slate-800 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 flex-shrink-0">
            <div class="flex gap-2 w-full md:w-auto">
               <select name="status" class="pl-3 pr-8 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 bg-white dark:bg-slate-800 cursor-pointer transition text-gray-700 dark:text-white font-medium">
                    <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?>>Inactive Only</option>
                </select>

                <select name="role" class="pl-3 pr-8 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 bg-white dark:bg-slate-800 cursor-pointer transition text-gray-700 dark:text-white">
                    <option value="all" <?= $role_filter == 'all' ? 'selected' : '' ?>>All Roles</option>
                    <option value="admin" <?= $role_filter == 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="Staff" <?= $role_filter == 'Staff' ? 'selected' : '' ?>>Staff</option>
                    <option value="Super Admin" <?= $role_filter == 'Super Admin' ? 'selected' : '' ?>>Owner</option>
                </select>
            </div>

            <div class="relative w-full md:w-64">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><span class="material-icons text-sm">search</span></span>
                <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search name or email..." class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 dark:border-slate-700 rounded-lg focus:outline-none focus:border-[#1E3A1D] dark:focus:border-green-400 bg-white dark:bg-slate-800 text-gray-900 dark:text-white transition" autocomplete="off">
            </div>
            
            <button type="submit" class="hidden">Submit</button>
        </form>

        <div id="tableDataArea" class="bg-white dark:bg-slate-900/80 rounded-xl shadow border border-gray-200 dark:border-slate-800 flex-1 overflow-hidden flex flex-col mb-4">
            <div class="overflow-y-auto flex-1 custom-scroll pb-24">
                <table class="w-full text-left">
                    <thead class="bg-[#1E3A1D] dark:bg-slate-800 text-white text-xs uppercase font-bold sticky top-0 z-10">
                        <tr>
                            <th class="p-4 pl-6">System User</th>
                            <th class="p-4">Contact Info</th>
                            <th class="p-4">Role / Authorization</th>
                            <th class="p-4">Status & Login</th>
                            <th class="p-4 pr-8 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800 text-sm text-gray-700 dark:text-gray-300">
                        <?php if(empty($users)): ?>
                            <tr><td colspan="5" class="p-8 text-center text-gray-400 dark:text-slate-500 italic">No users found matching your criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach($users as $u): 
                                $isMe = ($u['user_id'] == $current_user_id);
                                $isLocked = ($u['locked_until'] && strtotime($u['locked_until']) > time());
                                $actualStatus = $isLocked ? 'Locked' : $u['status'];
                                $hasPhoto = !empty($u['profile_image']);
                                $initial = strtoupper(substr($u['first_name'], 0, 1));
                                
                                $safe_phone = preg_replace('/[^0-9\+\-\s]/', '', $u['phone_number'] ?? '');
                                
                                // Role Badge Colors 
$roleBadge = 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-300 border-gray-200 dark:border-slate-700';
if($u['role'] === 'Super Admin') $roleBadge = 'bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 border-purple-200 dark:border-purple-800/50';
else if(strtolower($u['role']) === 'admin') $roleBadge = 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-800/50';

// THIS IS THE MAGIC TRICK: If DB says 'Super Admin', show 'Owner'
$displayRole = ($u['role'] === 'Super Admin') ? 'Owner' : ucwords($u['role']);
?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition <?= $isMe ? 'bg-green-50/30 dark:bg-green-900/10' : '' ?> group">
                                <td class="p-4 pl-6 align-middle">
                                    <div class="flex items-center gap-4">
                                        <?php if($hasPhoto): ?>
                                            <img src="../../<?= htmlspecialchars($u['profile_image']) ?>" class="w-10 h-10 rounded-full object-cover border border-gray-200 dark:border-slate-700 shadow-sm flex-shrink-0">
                                        <?php else: ?>
                                            <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-slate-700 border border-gray-300 dark:border-slate-600 shadow-sm flex items-center justify-center font-bold text-gray-600 dark:text-slate-300 flex-shrink-0">
                                                <?= $initial ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-bold text-gray-900 dark:text-white text-sm flex items-center gap-2">
                                                <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                                                <?php if($isMe): ?><span class="text-[9px] bg-[#1E3A1D] dark:bg-green-600 text-white px-1.5 py-0.5 rounded font-bold uppercase tracking-wider">You</span><?php endif; ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-slate-400 font-mono mt-0.5">@<?= htmlspecialchars($u['username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 align-middle">
                                    <div class="flex flex-col gap-1 text-xs">
                                        <div class="flex items-center gap-2 text-gray-600 dark:text-slate-300"><span class="material-icons text-[14px] text-gray-400 dark:text-slate-500">email</span> <?= htmlspecialchars($u['email'] ?? '-') ?></div>
                                        <?php if(!empty($safe_phone)): ?>
                                            <div class="flex items-center gap-2 text-gray-600 dark:text-slate-300"><span class="material-icons text-[14px] text-gray-400 dark:text-slate-500">phone</span> <?= htmlspecialchars($safe_phone) ?></div>
                                        <?php else: ?>
                                            <div class="flex items-center gap-2 text-gray-400 dark:text-slate-500 italic"><span class="material-icons text-[14px] opacity-50">phone</span> Not provided</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-4 align-middle">
                                    <div class="flex flex-col items-start gap-1">
                                        <span class="inline-block px-2.5 py-1 rounded text-[10px] font-bold uppercase tracking-wider border shadow-sm <?= $roleBadge ?>">
                                            <?= htmlspecialchars($displayRole) ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="p-4 align-middle">
                                    <div class="flex flex-col gap-1.5 items-start">
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wider status-<?= $actualStatus ?>">
                                            <?php if($actualStatus=='Locked'): ?><span class="material-icons text-[12px]">lock</span><?php endif; ?> <?= $actualStatus ?>
                                        </span>
                                        <div class="text-[10px] text-gray-400 dark:text-slate-500">
                                            <?php if($u['last_login']): ?>
                                                Last active: <span class="font-bold text-gray-500 dark:text-slate-400"><?= date('M d, h:i A', strtotime($u['last_login'])) ?></span>
                                            <?php else: ?>
                                                <i class="italic">Never logged in</i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 pr-6 align-middle">
                                    <div class="flex justify-end items-center">
                                        <?php 
                                            // Determine interaction permissions
                                            $canEdit = true;
                                            $canDelete = !$isMe;
                                            $canUnlock = ($isLocked && $current_role === 'Super Admin');
                                            
                                            // Standard Admin cannot modify Super Admin
                                            if ($current_role !== 'Super Admin' && $u['role'] === 'Super Admin') {
                                                $canEdit = false;
                                                $canDelete = false;
                                            }
                                        ?>
                                        
                                        <?php if($canEdit || $canDelete || $canUnlock): ?>
                                            <div class="relative inline-block dropdown-container">
                                                <button type="button" onclick="toggleMenu(event, 'user-menu-<?= $u['user_id'] ?>')" class="p-1.5 text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-slate-700 rounded-full transition focus:outline-none flex items-center justify-center opacity-50 group-hover:opacity-100">
                                                    <span class="material-icons">more_vert</span>
                                                </button>
                                                
                                                <div id="user-menu-<?= $u['user_id'] ?>" class="user-dropdown-menu hidden absolute right-0 top-full mt-1 w-40 bg-white dark:bg-slate-800 rounded-lg shadow-[0_3px_10px_rgb(0,0,0,0.15)] border border-gray-200 dark:border-slate-700 z-[60] overflow-hidden">
                                                    <div class="flex flex-col">
                                                        <?php if($canUnlock): ?>
                                                            <button type="button" onclick="unlockUser(<?= $u['user_id'] ?>)" class="w-full text-left px-4 py-2.5 text-xs text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-900/30 flex items-center gap-2 font-semibold transition border-b border-gray-100 dark:border-slate-700">
                                                                <span class="material-icons text-sm">lock_open</span> Unlock Account
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if($canEdit): ?>
                                                            <button type="button" onclick="editUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8') ?>)" class="w-full text-left px-4 py-2.5 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center gap-2 font-semibold transition">
                                                                <span class="material-icons text-sm">edit</span> Edit Profile
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if($canDelete): ?>
                                                            <button type="button" onclick="deleteUser(<?= $u['user_id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')" class="w-full text-left px-4 py-2.5 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 flex items-center gap-2 font-semibold transition border-t border-gray-100 dark:border-slate-700">
                                                                <span class="material-icons text-sm">person_remove</span> Delete
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-[10px] text-gray-400 dark:text-slate-500 italic uppercase font-bold tracking-wider">Restricted</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_pages > 0): ?>
            <div class="p-4 border-t border-gray-200 dark:border-slate-800 bg-gray-50 dark:bg-slate-900 flex flex-col md:flex-row justify-between items-center text-sm z-10 sticky bottom-0">
                <span class="text-gray-500 dark:text-slate-400 mb-2 md:mb-0">
                    Showing <span class="font-bold text-gray-900 dark:text-white"><?= $total_records > 0 ? $offset + 1 : 0 ?></span> 
                    to <span class="font-bold text-gray-900 dark:text-white"><?= min($offset + $limit, $total_records) ?></span> 
                    of <span class="font-bold text-gray-900 dark:text-white"><?= $total_records ?></span> users
                </span>
                
                <?php if($total_pages > 1): ?>
                <div class="flex items-center gap-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-1 shadow-sm">
                    <?php for($i=1; $i<=$total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&role=<?= urlencode($role_filter) ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search_query) ?>" 
                           class="w-8 h-8 flex items-center justify-center rounded font-bold transition <?= $i === $page ? 'bg-[#1E3A1D] dark:bg-green-600 text-white shadow' : 'bg-transparent text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="flashMessage" class="fixed bottom-6 right-6 z-[100] bg-[#1E3A1D] dark:bg-green-700 text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform translate-y-20 transition-all duration-300 opacity-0 pointer-events-none">
        <span class="material-icons text-green-400" id="flashIcon">check_circle</span>
        <div><h4 class="font-bold text-sm">Notification</h4><p class="text-xs text-gray-300" id="flashText"></p></div>
    </div>

    <div id="userModal" class="fixed inset-0 bg-[#1E3A1D] dark:bg-black bg-opacity-40 dark:bg-opacity-60 hidden flex items-center justify-center modal-z backdrop-blur-sm transition-opacity">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden max-h-[90vh] flex flex-col border border-gray-200 dark:border-slate-700 relative">
            <form id="userForm" enctype="multipart/form-data" class="flex flex-col flex-1 overflow-hidden h-full m-0">
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="user_id" id="user_id">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="bg-[#1E3A1D] dark:bg-slate-800 p-5 text-white flex justify-between items-center flex-shrink-0 relative z-10">
                    <h2 class="text-lg font-bold flex items-center gap-2"><span class="material-icons" id="modalIcon">person_add</span> <span id="modalTitle">Add New User</span></h2>
                    <button type="button" onclick="closeModal()" class="text-gray-300 hover:text-white transition"><span class="material-icons">close</span></button>
                </div>
            
                <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-gray-50 dark:bg-slate-900 relative z-0">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        
                        <div class="md:col-span-1 flex flex-col items-center justify-start pt-2">
                            <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-3 text-center w-full">Profile Photo</label>
                            <label class="cursor-pointer group relative">
                                <div class="w-32 h-32 rounded-full border-4 border-white dark:border-slate-700 shadow-md bg-white dark:bg-slate-800 flex items-center justify-center overflow-hidden relative">
                                    <img id="imagePreview" src="" class="absolute inset-0 w-full h-full object-cover z-10 hidden">
                                    <span id="imagePlaceholder" class="material-icons text-4xl text-gray-300 dark:text-slate-500 group-hover:text-[#1E3A1D] dark:group-hover:text-green-400 transition z-0">add_a_photo</span>
                                </div>
                                <div class="absolute inset-0 bg-black bg-opacity-40 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity z-20">
                                    <span class="text-white text-[10px] font-bold uppercase tracking-wider">Change</span>
                                </div>
                                <input type="file" name="profile_image" accept="image/*" class="hidden" onchange="previewImage(this)">
                            </label>
                        </div>

                        <div class="md:col-span-2 space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">First Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="first_name" id="first_name" required class="form-input text-sm font-medium w-full">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Last Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="last_name" id="last_name" required class="form-input text-sm font-medium w-full">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Email Address <span class="text-red-500">*</span></label>
                                    <input type="email" name="email" id="email" required class="form-input text-sm w-full">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Phone Number</label>
                                    <input type="text" name="phone_number" id="phone_number" class="form-input text-sm font-mono w-full" placeholder="09XX-XXX-XXXX">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Username <span class="text-red-500">*</span></label>
                                <input type="text" name="username" id="username" required class="form-input text-sm font-mono w-full bg-gray-50 dark:bg-slate-800">
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">System Role</label>
                                    <select name="role" id="role" class="form-input text-sm font-bold bg-white dark:bg-slate-800 cursor-pointer w-full">
                                        <option value="admin">Admin</option>
                                        <option value="Staff">Staff</option>
                                        <?php if ($current_role === 'Super Admin'): ?>
    <option value="Super Admin">Owner</option>
<?php endif; ?>
                                    </select>
                                </div>
                                <div id="statusContainer">
                                    <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Account Status</label>
                                    <select name="status" id="status" class="form-input text-sm font-bold bg-white dark:bg-slate-800 cursor-pointer w-full">
                                        <option value="Active">Active</option>
                                        <option value="Inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-gray-200 dark:border-slate-800 mt-2 hidden" id="passwordContainer">
                                <label class="block text-xs font-bold text-gray-500 dark:text-slate-400 uppercase mb-1">Password</label>
                                <input type="password" name="password" id="password" class="form-input text-sm w-full" placeholder="Enter new password...">
                                <p id="passHelp" class="text-[10px] text-gray-400 dark:text-slate-500 mt-1">Leave blank to keep current password.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="p-5 border-t border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end gap-3 flex-shrink-0 relative z-20">
                    <button type="button" onclick="closeModal()" class="px-5 py-2.5 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-800 rounded-lg text-sm font-bold transition">Cancel</button>
                    <button type="submit" id="submitBtn" class="bg-[#1E3A1D] dark:bg-green-600 text-white px-6 py-2.5 rounded-lg text-sm font-bold hover:bg-[#2a4e29] dark:hover:bg-green-500 shadow-md transition transform hover:-translate-y-0.5 flex items-center gap-2">
                        <span class="material-icons text-sm" id="btnIcon">save</span> <span id="btnText">Save User</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('secure-body').style.display = 'block';
        const modal = document.getElementById('userModal');
        const form = document.getElementById('userForm');

        let flashTimeout;
        const showFlash = (msg, type = 'success') => {
            if(flashTimeout) clearTimeout(flashTimeout);
            document.getElementById('flashText').textContent = msg;
            const fm = document.getElementById('flashMessage');
            const fi = document.getElementById('flashIcon');
            fm.className = `fixed bottom-6 right-6 z-[100] ${type === 'error' ? 'bg-red-700' : 'bg-[#1E3A1D] dark:bg-green-700'} text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 transform transition-all duration-300`;
            fi.textContent = type === 'error' ? 'error' : 'check_circle';
            fm.classList.remove('translate-y-20', 'opacity-0');
            flashTimeout = setTimeout(() => { fm.classList.add('translate-y-20', 'opacity-0'); }, 3000);
        };

        function toggleMenu(event, menuId) {
            event.stopPropagation();
            const menu = document.getElementById(menuId);
            const isHidden = menu.classList.contains('hidden');
            document.querySelectorAll('.user-dropdown-menu').forEach(m => m.classList.add('hidden'));
            if (isHidden) menu.classList.remove('hidden');
        }

        document.addEventListener('click', function() {
            document.querySelectorAll('.user-dropdown-menu').forEach(menu => menu.classList.add('hidden'));
        });

        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const placeholder = document.getElementById('imagePlaceholder');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function openModal() {
            form.reset();
            document.getElementById('user_id').value = '';
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('modalIcon').textContent = 'person_add';
            document.getElementById('btnText').textContent = 'Create User';
            document.getElementById('btnIcon').textContent = 'add_circle';
            
            // Completely hide the password section during Creation
            document.getElementById('passwordContainer').classList.add('hidden');
            document.getElementById('password').required = false;

            // Make sure Status is visible if we are creating
            document.getElementById('statusContainer').classList.remove('hidden');

            document.getElementById('imagePreview').classList.add('hidden'); 
            document.getElementById('imagePreview').src = ""; 
            document.getElementById('imagePlaceholder').classList.remove('hidden');

            modal.classList.remove('hidden');
        }

        function closeModal() {
            modal.classList.add('hidden');
        }
        
        // ==========================================
        // HTMX-STYLE AJAX ENGINE (Users)
        // ==========================================
        const filterForm = document.getElementById('filterForm');
        const liveSearchInput = document.getElementById('searchInput');
        const tableContainer = document.getElementById('tableDataArea');
        const resetBtn = document.getElementById('resetFiltersBtn');
        let searchTimeout;

        function performAjaxSearch(fetchUrl = null) {
            if (!tableContainer) return;

            let url;
            if (fetchUrl) {
                url = new URL(fetchUrl, window.location.origin);
            } else {
                url = new URL(window.location.pathname, window.location.origin);
                if (filterForm) {
                    const formData = new FormData(filterForm);
                    for (const [key, value] of formData.entries()) {
                        if (value) url.searchParams.set(key, value);
                    }
                }
            }

            tableContainer.style.opacity = '0.5';

            fetch(url.toString())
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const newDoc = parser.parseFromString(html, 'text/html');
                
                const newTableContainer = newDoc.getElementById('tableDataArea');
                if (newTableContainer) {
                    tableContainer.innerHTML = newTableContainer.innerHTML;
                }

                window.history.pushState({}, '', url.toString());
                tableContainer.style.opacity = '1';
            })
            .catch(err => {
                console.error('AJAX Error:', err);
                tableContainer.style.opacity = '1';
            });
        }

        if (liveSearchInput) {
            liveSearchInput.addEventListener('keyup', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(performAjaxSearch, 300);
            });
            liveSearchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault();
            });
        }

        if (filterForm) {
            const inputs = filterForm.querySelectorAll('select');
            inputs.forEach(input => {
                input.addEventListener('change', () => performAjaxSearch());
            });

            filterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                performAjaxSearch();
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (liveSearchInput) liveSearchInput.value = '';
                if (filterForm) {
                    const selects = filterForm.querySelectorAll('select');
                    selects.forEach(select => {
                        select.value = select.options[0].value;
                    });
                }
                performAjaxSearch();
            });
        }

        document.addEventListener('click', function(e) {
            const pageLink = e.target.closest('a[href*="?page="]');
            if (pageLink && tableContainer && tableContainer.contains(pageLink)) {
                e.preventDefault(); 
                performAjaxSearch(pageLink.href); 
            }
        });
        // ==========================================
        // END OF AJAX ENGINE
        // =========================================
        
        

        window.editUser = (user) => {
            document.getElementById('modalTitle').textContent = 'Edit User Profile';
            document.getElementById('modalIcon').textContent = 'manage_accounts';
            document.getElementById('btnText').textContent = 'Update User';
            document.getElementById('btnIcon').textContent = 'save';
            
            // Show password section for Editing, but keep it strictly optional
            document.getElementById('passwordContainer').classList.remove('hidden');
            document.getElementById('password').required = false;
            document.getElementById('password').value = ''; 

            document.getElementById('user_id').value = user.user_id;
            document.getElementById('username').value = user.username;
            document.getElementById('first_name').value = user.first_name;
            document.getElementById('last_name').value = user.last_name;
            document.getElementById('email').value = user.email;
            document.getElementById('phone_number').value = user.phone_number || '';
            
            // Prevent marking yourself as Inactive
            if (parseInt(user.user_id) === currentUserId) {
                document.getElementById('statusContainer').classList.add('hidden');
            } else {
                document.getElementById('statusContainer').classList.remove('hidden');
            }
            
            // Fix Case-Sensitivity: Map roles to exact dropdown values safely
            let dbRole = (user.role || '').trim().toLowerCase();
let finalRole = user.role; 

if (dbRole === 'standard user' || dbRole === 'staff') {
    finalRole = 'Staff';
} else if (dbRole === 'admin') {
    finalRole = 'admin'; 
} else if (dbRole === 'super admin' || dbRole === 'owner') {
    finalRole = 'Super Admin'; // MAGIC TRICK: Mapping to the hidden value
}
            
            document.getElementById('role').value = finalRole;
            document.getElementById('status').value = user.status;

            if(user.profile_image) { 
                document.getElementById('imagePreview').src = "../../" + user.profile_image; 
                document.getElementById('imagePreview').classList.remove('hidden'); 
                document.getElementById('imagePlaceholder').classList.add('hidden'); 
            } else {
                document.getElementById('imagePreview').classList.add('hidden'); 
                document.getElementById('imagePreview').src = ""; 
                document.getElementById('imagePlaceholder').classList.remove('hidden');
            }

            modal.classList.remove('hidden');
        }

        async function unlockUser(id) {
            if(!confirm(`Are you sure you want to unlock this account?`)) return;
            const formData = new FormData();
            formData.append('action', 'unlock_user');
            formData.append('user_id', id);
            formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
            try {
                const res = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
                if(res.success) { showFlash(res.message); setTimeout(() => location.reload(), 1000); } 
                else { showFlash(res.message, 'error'); }
            } catch(e) { showFlash("Error unlocking user.", "error"); }
        }

        async function deleteUser(id, name) {
            if(!confirm(`Are you sure you want to permanently delete user: ${name}?`)) return;
            const formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('user_id', id);
            formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
            try {
                const res = await fetch('', { method: 'POST', body: formData }).then(r => r.json());
                if(res.success) { showFlash(res.message); setTimeout(() => location.reload(), 1000); } 
                else { showFlash(res.message, 'error'); }
            } catch(e) { showFlash("Error deleting user.", "error"); }
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="animate-spin material-icons text-sm">autorenew</span> Saving...';
            btn.disabled = true;

            try {
                const res = await fetch('', { method: 'POST', body: new FormData(form) }).then(r => r.json());
                if(res.success) {
                    showFlash(res.message);
                    closeModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showFlash(res.message, 'error');
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } catch(e) { 
                showFlash("Error saving user.", "error"); 
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        });
    </script>
</body>
</html>