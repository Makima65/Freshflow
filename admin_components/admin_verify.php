<?php
// Error reporting helps us see exact issues
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// --- 1. CONNECT TO DATABASE ---
if (file_exists('../includes/db_connection.php')) {
    require_once '../includes/db_connection.php';
} elseif (file_exists('includes/db_connection.php')) {
    require_once 'includes/db_connection.php';
} else {
    $conn = new mysqli("localhost", "u613496064_freshflow_new", "1Freshflow_new", "u613496064_freshflow_new");
}

// --- 2. LOAD HELPERS (Mail & Audit) ---
require_once 'mail_config.php'; // Required for PHPMailer to match process_admin_login

if (file_exists('../includes/audit_helper.php')) {
    include_once '../includes/audit_helper.php';
} elseif (file_exists('includes/audit_helper.php')) {
    include_once 'includes/audit_helper.php';
}
// Fallback just in case it doesn't load
if (!function_exists('log_audit_action')) { 
    function log_audit_action() { return true; } 
}

// --- 3. SECURITY GATE ---
if (!isset($_SESSION['temp_user_id'])) {
    header("Location: admin_login.php");
    exit;
}

$user_id = $_SESSION['temp_user_id'];
$error_msg = "";
$success_msg = "";

// --- 4. FRESHFLOW THEME EMAIL FUNCTION ---
function sendTwoFactorCode($conn, $user_id, $email, $fullname) {
    // Generate code matching process_admin_login
    $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $unique_id = uniqid();
    
    // Update Database
    $upd = $conn->prepare("UPDATE users SET otp_code=? WHERE user_id=?");
    if(!$upd) return false;
    $upd->bind_param("si", $otp_code, $user_id);
    $upd->execute();
    
    // Send using PHPMailer with the exact Hostinger clone design requested
    try {
        $mail = getMailer();
        $mail->isHTML(true); 
        $mail->addAddress($email);
        $mail->Subject = "Your verification code";
        
        $mail->Body = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Your Verification Code</title>
            <style>
                /* Force link colors to remain green in Apple Mail/Gmail */
                .code-text a { color: #1E3A1D !important; text-decoration: none !important; }
            </style>
        </head>
         <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #F8F5EE;">
            <div style="max-width: 600px; margin: 0 auto; padding: 40px 20px; text-align: center; background-color: #F8F5EE;">
                
                <img src="https://freshflow.site/admin_components/assets/img/FreshflowGmailLogo2.png" alt="Freshflow" width="170" style="margin-bottom: 25px; margin-top: 0; display: inline-block;">
                
                <h2 style="color: #111827; font-size: 24px; font-weight: 700; margin-bottom: 25px; margin-top: 0;">Here is your verification code:</h2>
                
                <div style="border: 1px solid #d1d5db; border-radius: 8px; padding: 14px 0; margin-bottom: 30px; max-width: 320px; margin-left: auto; margin-right: auto; background-color: #ffffff;">
                    <span class="code-text" style="font-size: 36px; font-weight: 800; color: #1E3A1D; letter-spacing: 2px;">
                        <a href="#" style="color: #1E3A1D !important; text-decoration: none !important; cursor: default; pointer-events: none;">{$otp_code}</a>
                    </span>
                </div>
                
                <p style="color: #374151; font-size: 15px; margin-bottom: 15px; margin-top: 0;">Please make sure you never share this code with anyone.</p>
                <p style="color: #374151; font-size: 15px; margin-top: 0; margin-bottom: 35px;"><strong>Note:</strong> The code will expire in 10 minutes.</p>
                
                <hr style="border: none; border-top: 1px solid #d1d5db; margin: 0 auto 25px auto; max-width: 500px;">
                
                <img src="https://freshflow.site/admin_components/assets/img/FreshflowGmailLogo2.png" alt="Freshflow" width="250" style="margin-bottom: 30px; display: inline-block;">

                <p style="color: #6b7280; font-size: 12px; line-height: 1.6; margin-bottom: 20px; max-width: 500px; margin-left: auto; margin-right: auto;">
                    You have received this email because you are registered at Freshflow, to ensure the implementation of our Terms of Service and (or) for other legitimate matters.
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
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// --- 5. HANDLE RESEND (WITH FIX FOR REFRESH SPAM) ---
if (isset($_GET['resend'])) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if($u = $res->fetch_assoc()){
        $fname = $u['first_name'] ?? 'Admin';
        $lname = $u['last_name'] ?? '';
        sendTwoFactorCode($conn, $user_id, $u['email'], trim("$fname $lname"));
        
        // Fix: Redirecting clears the "?resend=1" from the URL so reloading the page won't trigger another email
        header("Location: admin_verify.php?msg=resent");
        exit;
    }
}

// Catch the success message after the redirect
if (isset($_GET['msg']) && $_GET['msg'] === 'resent') {
    $success_msg = "A new code was sent to your email.";
}

// --- 6. HANDLE OTP SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['otp_code'])) {
    $entered = implode('', $_POST['otp_code']);
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id=?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        
        if ($user) {
            $status = $user['status'] ?? 'Active';
            
            if ($status !== 'Active') {
                $error_msg = "Account is inactive.";
            } else if ($user['otp_code'] === $entered) { 
                
                // SUCCESS - Log them in completely
                $_SESSION['loggedin'] = true;
                $_SESSION['user_id'] = $user_id;
                
                $_SESSION['role_name'] = $user['role_name'] ?? $user['role'] ?? 'admin';
                $fname = $user['first_name'] ?? 'Admin';
                $lname = $user['last_name'] ?? '';
                $_SESSION['admin_name'] = trim("$fname $lname");
                
                $conn->query("UPDATE users SET otp_code=NULL, last_login=NOW() WHERE user_id=$user_id");
                unset($_SESSION['temp_user_id']);
                $_SESSION['welcome_splash'] = true;
                header("Location: pages/dashboard.php");
                exit;
            } else {
                $error_msg = "Invalid code. Please try again.";
            }
        } else {
            $error_msg = "User not found.";
        }
    } else {
        $error_msg = "Database query failed. Please contact support.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verify Login - Perishable Insights</title>
    
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/tabicon4.png">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
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

        .otp-input {
            transition: all 0.3s ease;
        }
        .otp-input:focus {
            transform: scale(1.05);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

    <button id="themeToggle" type="button" class="absolute top-6 right-6 p-3 rounded-full bg-white shadow-md text-gray-500 hover:text-[#1E3A1D] hover:bg-gray-100 dark:bg-slate-800 dark:border dark:border-slate-700 dark:hover:bg-slate-700 dark:text-gray-400 dark:hover:text-white transition-all flex items-center justify-center focus:outline-none z-50" title="Toggle Dark/Light Mode">
        <span class="material-symbols-outlined text-2xl transition-transform" id="themeIcon">dark_mode</span>
    </button>

    <div class="w-full max-w-md bg-white dark:bg-slate-900/80 dark:border dark:border-slate-800 p-8 lg:p-10 rounded-2xl relative login-card-container flex flex-col items-center text-center">
        
        <div class="w-16 h-16 bg-[#1E3A1D] text-white rounded-full flex items-center justify-center mb-6 shadow-md border-4 border-[#F8F5EE] dark:border-slate-800">
            <span class="material-symbols-outlined text-3xl">mark_email_read</span>
        </div>

        <h2 class="text-2xl font-bold text-[#2B2B2B] dark:text-white mb-2">Two-Factor Authentication</h2>
        <p class="text-sm text-gray-500 dark:text-slate-400 mb-8">We've sent a 6-digit verification code to your registered email address.</p>

        <?php if($success_msg): ?>
            <div class="w-full bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 p-3 rounded-lg mb-6 text-sm font-semibold border-l-4 border-green-500 flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-lg">check_circle</span>
                <?= $success_msg ?>
            </div>
        <?php endif; ?>

        <?php if($error_msg): ?>
            <div class="w-full bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 p-3 rounded-lg mb-6 text-sm font-semibold border-l-4 border-red-500 flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-lg">error</span>
                <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="w-full space-y-8">
            <div class="flex justify-center gap-2 sm:gap-3">
                <?php for($i=0; $i<6; $i++): ?>
                    <input type="text" name="otp_code[]" maxlength="1" 
                           class="w-10 h-12 sm:w-12 sm:h-14 border border-gray-300 dark:border-slate-600 bg-transparent text-center text-xl sm:text-2xl font-bold text-gray-800 dark:text-white rounded-lg focus:border-[#1E3A1D] dark:focus:border-green-400 focus:ring-2 focus:ring-[#1E3A1D]/20 dark:focus:ring-green-400/20 outline-none otp-input" 
                           pattern="[0-9]*" inputmode="numeric">
                <?php endfor; ?>
            </div>
            
            <button type="submit" class="w-full py-3 px-4 rounded-lg text-white font-bold text-lg shadow-lg hover:shadow-xl transition-all transform hover:-translate-y-0.5 active:translate-y-0 bg-[#1E3A1D] hover:bg-[#2a4e29] dark:bg-green-700 dark:hover:bg-green-600">
                Verify Login
            </button>
        </form>
        
        <div class="mt-8 pt-6 border-t border-gray-200 dark:border-slate-700 w-full flex flex-col sm:flex-row items-center justify-between gap-4 text-sm">
             <a href="?resend=1" class="font-semibold text-[#1E3A1D] dark:text-green-400 hover:underline flex items-center gap-1">
                 <span class="material-symbols-outlined text-sm">refresh</span>
                 Resend Code
             </a>
             <a href="admin_login.php" class="text-gray-500 dark:text-slate-400 hover:text-[#1E3A1D] dark:hover:text-white transition-colors flex items-center gap-1">
                 <span class="material-symbols-outlined text-sm">arrow_back</span>
                 Back to Login
             </a>
        </div>

    </div>

    <script>
        const inputs = document.querySelectorAll('.otp-input');
        inputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/[^0-9]/g, '');
                if(e.target.value !== '' && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
            });
            input.addEventListener('keydown', (e) => {
                if(e.key === 'Backspace' && e.target.value === '' && index > 0) {
                    inputs[index - 1].focus();
                }
            });
        });

        if(inputs.length > 0) { inputs[0].focus(); }

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