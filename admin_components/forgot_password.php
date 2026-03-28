<?php
// admin_components/forgot_password.php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (file_exists('../includes/db_connection.php')) {
    require '../includes/db_connection.php';
} elseif (file_exists('includes/db_connection.php')) {
    require 'includes/db_connection.php';
} else {
    die("System Error: Database connection file not found.");
}

require 'mail_config.php'; 

if (file_exists('../includes/audit_helper.php')) {
    include_once '../includes/audit_helper.php';
} elseif (file_exists('includes/audit_helper.php')) {
    include_once 'includes/audit_helper.php';
}
if (!function_exists('log_audit_action')) { 
    function log_audit_action($a, $b, $c, $d = []) { return true; } 
}

date_default_timezone_set('Asia/Manila');

$message = "";
$messageType = ""; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);

    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        $token = bin2hex(random_bytes(16));
        // Give the admin 24 hours to approve the request
        $expiry = date("Y-m-d H:i:s", time() + 60 * 60 * 24); 

        $update_stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?");
        $update_stmt->bind_param("sss", $token, $expiry, $email);
        $update_stmt->execute();

        // --- FIND THE SUPER ADMIN EMAIL ---
        $admin_stmt = $conn->prepare("SELECT email FROM users WHERE role = 'Super Admin' LIMIT 1");
        
        $super_admin_email = "admin@freshflow.site"; // Fallback email
        
        if ($admin_stmt) {
            $admin_stmt->execute();
            $admin_result = $admin_stmt->get_result();
            if ($admin_row = $admin_result->fetch_assoc()) {
                $super_admin_email = $admin_row['email'];
            }
        }

        try {
            $mail = getMailer();
            $mail->isHTML(true);
            
            // SEND TO SUPER ADMIN
            $mail->addAddress($super_admin_email);
            $mail->Subject = "ACTION REQUIRED: Password Reset Request for " . $user['first_name'];
            
            // The link the Admin will click to approve
            $approve_link = "https://freshflow.site/admin_components/approve_reset.php?token=$token&email=" . urlencode($email);
            $unique_id = uniqid(); // Gmail Anti-trim hack

            // BRANDED FRESHFLOW EMAIL TEMPLATE
            $mail->Body = <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Password Reset Request</title>
                <style>
                    /* Force link colors */
                    .btn-link { color: #ffffff !important; text-decoration: none !important; }
                </style>
            </head>
             <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #F8F5EE;">
                <div style="max-width: 600px; margin: 0 auto; padding: 40px 20px; text-align: center; background-color: #F8F5EE;">
                    
                    <img src="https://freshflow.site/admin_components/assets/img/FreshflowGmailLogo2.png" alt="Freshflow" width="170" style="margin-bottom: 25px; margin-top: 0; display: inline-block;">
                    
                    <h2 style="color: #111827; font-size: 24px; font-weight: 700; margin-bottom: 25px; margin-top: 0;">Password Reset Request</h2>
                    
                    <p style="color: #374151; font-size: 15px; margin-bottom: 15px; margin-top: 0;">Hello Admin,</p>
                    <p style="color: #374151; font-size: 15px; margin-bottom: 15px; margin-top: 0;">The following user has requested a password reset:</p>
                    
                    <div style="border: 1px solid #d1d5db; border-radius: 8px; padding: 20px; margin-bottom: 30px; max-width: 400px; margin-left: auto; margin-right: auto; background-color: #ffffff; text-align: left;">
                        <p style="color: #111827; font-size: 15px; margin-top: 0; margin-bottom: 10px;"><strong>Name:</strong> {$user['first_name']} {$user['last_name']}</p>
                        <p style="color: #111827; font-size: 15px; margin-top: 0; margin-bottom: 0;"><strong>Email:</strong> {$email}</p>
                    </div>
                    
                    <p style="color: #374151; font-size: 15px; margin-top: 0; margin-bottom: 35px;">If you have verified this request with the user, click the button below to approve it and send them the reset link.</p>
                    
                    <div style="margin-bottom: 35px;">
                        <a href="{$approve_link}" class="btn-link" style="background-color: #1E3A1D; color: #ffffff; padding: 14px 28px; border-radius: 6px; font-weight: bold; text-decoration: none; display: inline-block; letter-spacing: 0.5px;">Approve Password Reset</a>
                    </div>
                    
                    <hr style="border: none; border-top: 1px solid #d1d5db; margin: 0 auto 25px auto; max-width: 500px;">
                    
                    <img src="https://freshflow.site/admin_components/assets/img/FreshflowGmailLogo2.png" alt="Freshflow" width="250" style="margin-bottom: 30px; display: inline-block;">

                    <p style="color: #6b7280; font-size: 12px; line-height: 1.6; margin-bottom: 20px; max-width: 500px; margin-left: auto; margin-right: auto;">
                        If this request is unauthorized, simply ignore this email. The request will expire in 24 hours.
                    </p>
                    
                    <p style="color: #9ca3af; font-size: 12px; margin-top: 20px;">
                        © 2024-2026 Freshflow.
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
                @log_audit_action('Password Reset Requested', 'Security', "Password reset requested for {$email}. Sent to Super Admin for approval.");
            }
            
            $message = "Your request has been sent to the Super Admin for approval. You will receive an email once approved.";
            $messageType = "success";

        } catch (Exception $e) {
            $message = "Message could not be sent. Mailer error: {$mail->ErrorInfo}";
            $messageType = "error";
        }
    } else {
        // Prevent email enumeration
        $message = "Your request has been sent to the Super Admin for approval. You will receive an email once approved.";
        $messageType = "success";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - FreshFlow</title>
    
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
            --text-dark: #2B2B2B;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--brand-cream); 
            transition: background-color 0.3s ease;
        }

        .dark body {
            background: linear-gradient(-45deg, #000000, #0a1c09, #000000, #132b12);
            background-size: 400% 400%;
            animation: gradient 20s ease infinite;
            color: #f8fafc;
        }
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .login-card-container {
            animation: fade-in-up 0.6s ease-out forwards;
            opacity: 0;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            transition: transform 0.4s ease, box-shadow 0.4s ease;
        }
        @keyframes fade-in-up {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-card-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 50px -12px rgba(30, 58, 29, 0.6), 0 0 25px rgba(30, 58, 29, 0.4);
        }
        .dark .login-card-container:hover {
            box-shadow: 0 25px 50px -12px rgba(74, 222, 128, 0.5), 0 0 35px rgba(74, 222, 128, 0.4);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <button id="themeToggle" type="button" class="absolute top-6 right-6 p-3 rounded-full bg-white shadow-md text-gray-500 hover:text-[#1E3A1D] hover:bg-gray-100 dark:bg-slate-800 dark:border dark:border-slate-700 dark:hover:bg-slate-700 dark:text-gray-400 dark:hover:text-white transition-all flex items-center justify-center focus:outline-none z-50" title="Toggle Dark/Light Mode">
        <span class="material-symbols-outlined text-2xl transition-transform" id="themeIcon">dark_mode</span>
    </button>

    <div class="w-full max-w-md bg-white dark:bg-slate-900/80 dark:border dark:border-slate-800 p-8 lg:p-10 rounded-2xl relative login-card-container flex flex-col items-center text-center">
        
        <div class="w-16 h-16 bg-[#1E3A1D] text-white rounded-full flex items-center justify-center mx-auto mb-6 shadow-md border-4 border-[#F8F5EE] dark:border-slate-800">
            <span class="material-symbols-outlined text-3xl">lock_reset</span>
        </div>
        
        <h2 class="text-2xl font-bold text-[#2B2B2B] dark:text-white mb-2">Forgot Password</h2>
        <p class="text-sm text-gray-500 dark:text-slate-400 mb-8">Enter your email. A request will be sent to the Super Admin for approval.</p>

        <?php if (!empty($message)): ?>
            <div class="w-full mb-6 p-3 rounded-lg text-sm font-semibold flex items-center justify-center gap-2 
                <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border-l-4 border-green-500 dark:bg-green-900/20 dark:text-green-400' : 'bg-red-50 text-red-600 border-l-4 border-red-500 dark:bg-red-900/20 dark:text-red-400'; ?>">
                <span class="material-symbols-outlined text-lg"><?php echo $messageType === 'success' ? 'check_circle' : 'error'; ?></span>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="forgot_password.php" class="w-full space-y-6 text-left">
            <div>
                <label for="email" class="block text-sm font-medium text-[#2B2B2B] dark:text-slate-300 mb-1">Email Address</label>
                <div class="relative group">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 group-hover:text-[#1E3A1D] dark:group-hover:text-green-400 transition-colors z-10">mail</span>
                    
                    <input type="email" id="email" name="email" required placeholder="Enter your email"
                           class="w-full pl-10 pr-4 py-3 border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-[#1E3A1D]/20 dark:focus:ring-green-400/20 focus:border-[#1E3A1D] dark:focus:border-green-400 outline-none bg-white dark:bg-slate-900/60 dark:text-white transition-all">
                </div>
            </div>
            
            <button type="submit" class="w-full py-3 px-4 bg-[#1E3A1D] hover:bg-[#2a4e29] dark:bg-green-700 dark:hover:bg-green-600 text-white font-bold text-lg rounded-lg shadow-lg hover:shadow-xl transition-all transform hover:-translate-y-0.5 active:translate-y-0">
                Request Reset
            </button>
        </form>

        <div class="mt-8 pt-6 border-t border-gray-200 dark:border-slate-700 w-full">
            <a href="admin_login.php" class="text-sm text-gray-500 dark:text-slate-400 hover:text-[#1E3A1D] dark:hover:text-white font-medium flex items-center justify-center gap-1 transition-colors">
                <span class="material-symbols-outlined text-sm">arrow_back</span>
                Back to Login
            </a>
        </div>
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