<?php
// admin_components/process_admin_login.php
session_start();

// --- CONFIGURATION ---
$MAX_ATTEMPTS = 3;
$LOCKOUT_TIME = 5; // Minutes

// --- 1. CONNECT TO DATABASE ---
if (file_exists('../includes/db_connection.php')) {
    require '../includes/db_connection.php';
} elseif (file_exists('includes/db_connection.php')) {
    require 'includes/db_connection.php';
} else {
    die("System Error: Database connection file not found.");
}

// --- 2. LOAD HELPERS (Mail & Audit) ---
require 'mail_config.php'; 

// Load Audit Helper and set fallback if missing
if (file_exists('../includes/audit_helper.php')) {
    include_once '../includes/audit_helper.php';
} elseif (file_exists('includes/audit_helper.php')) {
    include_once 'includes/audit_helper.php';
}
if (!function_exists('log_audit_action')) { 
    function log_audit_action($a, $b, $c, $d = []) { return true; } 
}

// Force Timezone
date_default_timezone_set('Asia/Manila'); 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        header("Location: admin_login.php?error=empty");
        exit;
    }

    // 3. GET USER DATA
    $stmt = $conn->prepare("SELECT user_id, username, password, email, role, status, login_attempts, locked_until FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // 4. CHECK IF LOCKED OUT
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            header("Location: admin_login.php?error=locked&time=$remaining");
            exit;
        }

        // 5. VERIFY PASSWORD
        if (password_verify($password, $user['password'])) {
            
            // CHECK STATUS
            if (strcasecmp($user['status'], 'Active') !== 0) {
                // AUDIT LOG: Inactive account attempted login
                if(function_exists('log_audit_action')) log_audit_action('Access Denied', 'Authentication', "Attempted login on deactivated account: '{$username}'.");
                
                header("Location: admin_login.php?error=banned");
                exit;
            }

            // --- PASSWORD CORRECT! RESET STRIKES ---
            $reset = $conn->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE user_id = ?");
            $reset->bind_param("i", $user['user_id']);
            $reset->execute();

            // 6. GENERATE 2FA CODE
            $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $otp_expires = date("Y-m-d H:i:s", strtotime('+10 minutes'));
            
            // Generate a unique ID to prevent Gmail from trimming the email
            $unique_id = uniqid();

            // Save to DB
            $update = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires = ? WHERE user_id = ?");
            $update->bind_param("ssi", $otp_code, $otp_expires, $user['user_id']);
            
            if ($update->execute()) {
                // 7. SEND EMAIL
                try {
                    $mail = getMailer();
                    $mail->isHTML(true); 
                    $mail->addAddress($user['email']);
                    $mail->Subject = "Your verification code";
                    
                    // --- HOSTINGER CLONE + FRESHFLOW THEME TEMPLATE ---
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

                    // AUDIT LOG: OTP Sent successfully
                    if(function_exists('log_audit_action')) {
                        log_audit_action('2FA Sent', 'Authentication', "Password verified for '{$username}', OTP sent to email.");
                    }

                    // 8. REDIRECT TO VERIFY
                    session_regenerate_id(true);
                    $_SESSION['temp_user_id'] = $user['user_id'];
                    $_SESSION['2fa_pending'] = true;
                    
                    header("Location: admin_verify.php");
                    exit;

                } catch (Exception $e) {
                    // Email failed
                    header("Location: admin_login.php?error=email_fail");
                    exit;
                }
            }
        } else {
            // --- FAILURE! HANDLE STRIKES ---
            $new_attempts = $user['login_attempts'] + 1;
            
            if ($new_attempts >= $MAX_ATTEMPTS) {
                // Lock the account
                $lock_time = date('Y-m-d H:i:s', strtotime("+$LOCKOUT_TIME minutes"));
                $lock = $conn->prepare("UPDATE users SET login_attempts = ?, locked_until = ? WHERE user_id = ?");
                $lock->bind_param("isi", $new_attempts, $lock_time, $user['user_id']);
                $lock->execute();
                
                // AUDIT LOG: Account Locked
                if(function_exists('log_audit_action')) log_audit_action('Account Locked', 'Security', "Account '{$username}' locked due to maximum failed login attempts.");
                
                header("Location: admin_login.php?error=locked&time=$LOCKOUT_TIME");
            } else {
                // Add a strike
                $strike = $conn->prepare("UPDATE users SET login_attempts = ? WHERE user_id = ?");
                $strike->bind_param("ii", $new_attempts, $user['user_id']);
                $strike->execute();
                
                // AUDIT LOG: Failed Attempt
                if(function_exists('log_audit_action')) log_audit_action('Failed Login', 'Authentication', "Invalid password attempt for username: '{$username}'.");
                
                $tries_left = $MAX_ATTEMPTS - $new_attempts;
                header("Location: admin_login.php?error=invalid&tries=$tries_left");
            }
            exit;
        }
    } else {
        // User Not Found (Fake delay to prevent timing attacks)
        
        // AUDIT LOG: Attempt on a fake/incorrect username
        if(function_exists('log_audit_action')) log_audit_action('Failed Login', 'Authentication', "Failed login attempt for non-existent username: '{$username}'.");
        
        sleep(1);
        header("Location: admin_login.php?error=invalid");
        exit;
    }
} else {
    header("Location: admin_login.php");
    exit;
}
?>