<?php
// admin_components/approve_reset.php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database Connection
if (file_exists('../includes/db_connection.php')) {
    require '../includes/db_connection.php';
} elseif (file_exists('includes/db_connection.php')) {
    require 'includes/db_connection.php';
} else {
    die("System Error: Database connection file not found.");
}

require 'mail_config.php'; 

// Audit Helper
if (file_exists('../includes/audit_helper.php')) {
    include_once '../includes/audit_helper.php';
} elseif (file_exists('includes/audit_helper.php')) {
    include_once 'includes/audit_helper.php';
}
if (!function_exists('log_audit_action')) { 
    function log_audit_action($a, $b, $c, $d = []) { return true; } 
}

date_default_timezone_set('Asia/Manila');

$status_msg = "";
$status_type = "";

if (isset($_GET["token"]) && isset($_GET["email"])) {
    $token = $_GET["token"];
    $email = $_GET["email"];

    // FIXED DATABASE COLUMN NAMES HERE
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name FROM users WHERE email = ? AND reset_token = ? AND reset_expires > NOW()");
    $stmt->bind_param("ss", $email, $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Update the token expiration so the user has 1 fresh hour to click it
        $new_expiry = date("Y-m-d H:i:s", time() + 60 * 60); 
        $upd = $conn->prepare("UPDATE users SET reset_expires = ? WHERE email = ?");
        $upd->bind_param("ss", $new_expiry, $email);
        $upd->execute();

        try {
            $mail = getMailer();
            $mail->isHTML(true);
            $mail->addAddress($email);
            $mail->Subject = "Password Reset Approved";
            
            $reset_link = "https://freshflow.site/admin_components/reset_password.php?token=$token";
            $unique_id = uniqid();

            // The beautiful Freshflow email to the USER
            $mail->Body = <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <style>
                    .btn-link { color: #ffffff !important; text-decoration: none !important; }
                </style>
            </head>
             <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #F8F5EE;">
                <div style="max-width: 600px; margin: 0 auto; padding: 40px 20px; text-align: center; background-color: #F8F5EE;">
                    
                    <img src="https://freshflow.site/admin_components/assets/img/FreshflowGmailLogo2.png" alt="Freshflow" width="170" style="margin-bottom: 25px; margin-top: 0; display: inline-block;">
                    
                    <h2 style="color: #111827; font-size: 24px; font-weight: 700; margin-bottom: 15px;">Your Password Reset was Approved</h2>
                    
                    <p style="color: #374151; font-size: 15px; margin-bottom: 25px;">The Admin has approved your request. Click the button below to reset your password. This link will expire in 1 hour.</p>
                    
                    <div style="margin-bottom: 35px;">
                        <a href="{$reset_link}" class="btn-link" style="background-color: #1E3A1D; color: #ffffff; padding: 14px 28px; border-radius: 6px; font-weight: bold; text-decoration: none; display: inline-block; letter-spacing: 0.5px;">Reset Password</a>
                    </div>
                    
                    <p style="color: #374151; font-size: 14px; margin-bottom: 35px;">Or copy and paste this link into your browser:<br>
                    <a href="{$reset_link}" style="color: #1E3A1D; font-size: 12px; word-break: break-all;">{$reset_link}</a></p>
                    
                    <hr style="border: none; border-top: 1px solid #d1d5db; margin: 0 auto 25px auto; max-width: 500px;">
                    
                    <p style="color: #6b7280; font-size: 12px; line-height: 1.6; margin-bottom: 20px;">
                        If you did not request a password reset, please contact the administrator immediately.
                    </p>
                    
                    <div style="display: none; white-space: nowrap; font: 15px courier; line-height: 0;">
                        &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;
                        Thread-Breaker: {$unique_id}
                    </div>
                </div>
            </body>
            </html>
HTML;

            $mail->send();

            if(function_exists('log_audit_action')) {
                @log_audit_action('Password Reset Approved', 'Security', "Super Admin approved password reset for {$email}. Reset link sent to user.");
            }

            $status_msg = "Successfully approved! The reset email has been sent to {$email}.";
            $status_type = "success";

        } catch (Exception $e) {
            $status_msg = "Error sending email to user: {$mail->ErrorInfo}";
            $status_type = "error";
        }
    } else {
        $status_msg = "Invalid or expired request. The token may have already been used or expired.";
        $status_type = "error";
    }
} else {
    $status_msg = "Invalid approval link.";
    $status_type = "error";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Approval - FreshFlow</title>
    
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />

    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        tailwind.config = { darkMode: 'class' };
    </script>

    <style>
        :root {
            --brand-green: #1E3A1D;
            --brand-cream: #F8F5EE;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--brand-cream); transition: background-color 0.3s ease; }
        .dark body { background: linear-gradient(-45deg, #000000, #0a1c09, #000000, #132b12); background-size: 400% 400%; animation: gradient 20s ease infinite; color: #f8fafc; }
        @keyframes gradient { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        
        .login-card-container { animation: fade-in-up 0.6s ease-out forwards; opacity: 0; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); transition: transform 0.4s ease, box-shadow 0.4s ease; }
        @keyframes fade-in-up { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .login-card-container:hover { transform: translateY(-5px); box-shadow: 0 25px 50px -12px rgba(30, 58, 29, 0.6), 0 0 25px rgba(30, 58, 29, 0.4); }
        .dark .login-card-container:hover { box-shadow: 0 25px 50px -12px rgba(74, 222, 128, 0.5), 0 0 35px rgba(74, 222, 128, 0.4); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <button id="themeToggle" type="button" class="absolute top-6 right-6 p-3 rounded-full bg-white shadow-md text-gray-500 hover:text-[#1E3A1D] transition-all flex items-center justify-center z-50 dark:bg-slate-800 dark:text-gray-400 dark:hover:text-white">
        <span class="material-symbols-outlined text-2xl" id="themeIcon">dark_mode</span>
    </button>

    <div class="bg-white dark:bg-slate-900/80 p-8 rounded-2xl shadow-xl max-w-md w-full text-center login-card-container dark:border dark:border-slate-800 relative z-10">
        <?php if ($status_type === 'success'): ?>
            <div class="w-16 h-16 bg-green-100 dark:bg-green-900/40 text-green-600 dark:text-green-400 rounded-full flex items-center justify-center mx-auto mb-4 border border-green-200 dark:border-green-800">
                <span class="material-symbols-outlined text-4xl">check_circle</span>
            </div>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">Request Approved</h2>
            <p class="text-gray-600 dark:text-slate-400 mb-8"><?php echo $status_msg; ?></p>
        <?php else: ?>
            <div class="w-16 h-16 bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-400 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-200 dark:border-red-800">
                <span class="material-symbols-outlined text-4xl">error</span>
            </div>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">Approval Failed</h2>
            <p class="text-gray-600 dark:text-slate-400 mb-8"><?php echo $status_msg; ?></p>
        <?php endif; ?>
        
        <a href="admin_login.php" class="inline-flex w-full justify-center px-6 py-3 bg-[#1E3A1D] hover:bg-[#2a4e29] dark:bg-green-700 dark:hover:bg-green-600 text-white rounded-lg transition-all font-bold shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
            Go to Login
        </a>
    </div>

    <script>
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
    </script>
</body>
</html>