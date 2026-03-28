<?php
// admin_components/reset_password.php
session_start();

// Prevent Caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// DB Connection (Same folder structure)
if (file_exists('../includes/db_connection.php')) {
    require '../includes/db_connection.php';
} elseif (file_exists('includes/db_connection.php')) {
    require 'includes/db_connection.php';
} else {
    die("System Error: Database connection file not found.");
}

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$msg = '';
$msgType = '';
$showForm = false;
$showSuccess = false;
$debugInfo = '';

// VALIDATE TOKEN
if (!empty($token)) {
    // Select Token + Server Time
    $sql = "SELECT user_id, reset_expires, NOW() as server_time FROM users WHERE reset_token = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user) {
            $expiry = strtotime($user['reset_expires']);
            $now    = strtotime($user['server_time']);

            if ($expiry > $now) {
                $showForm = true; 
            } else {
                $msg = "This link has expired.";
                $msgType = "error";
                $debugInfo = "Expired.";
            }
        } else {
            $msg = "This link is invalid or has already been used.";
            $msgType = "error";
            $debugInfo = "Token not found.";
        }
    } else {
        $msg = "Database Error.";
        $msgType = "error";
    }
} else {
    // Redirect to neighbor login file
    header("Location: admin_login.php");
    exit;
}

// HANDLE SUBMISSION
if ($_SERVER["REQUEST_METHOD"] == "POST" && $showForm) {
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (strlen($pass) < 8) {
        $msg = "Password must be at least 8 characters.";
        $msgType = "error";
    } elseif (!preg_match("/[0-9]/", $pass)) {
        $msg = "Password must contain at least one number.";
        $msgType = "error";
    } elseif (!preg_match("/[\W_]/", $pass)) { 
        $msg = "Password must contain at least one special character (!@#$).";
        $msgType = "error";
    } elseif ($pass !== $confirm) {
        $msg = "Passwords do not match.";
        $msgType = "error";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        
        $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE reset_token = ?");
        $update->bind_param("ss", $hash, $token);
        
        if ($update->execute()) {
            $showForm = false;
            $showSuccess = true;
        } else {
            $msg = "Database Error: " . $conn->error;
            $msgType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - FreshFlow</title>
    
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
<body class="flex items-center justify-center min-h-screen p-4">

    <button id="themeToggle" type="button" class="absolute top-6 right-6 p-3 rounded-full bg-white shadow-md text-gray-500 hover:text-[#1E3A1D] hover:bg-gray-100 dark:bg-slate-800 dark:border dark:border-slate-700 dark:hover:bg-slate-700 dark:text-gray-400 dark:hover:text-white transition-all flex items-center justify-center focus:outline-none z-50" title="Toggle Dark/Light Mode">
        <span class="material-symbols-outlined text-2xl transition-transform" id="themeIcon">dark_mode</span>
    </button>

    <div class="w-full max-w-md bg-white dark:bg-slate-900/80 dark:border dark:border-slate-800 p-8 lg:p-10 rounded-2xl relative login-card-container flex flex-col items-center">
        
        <?php if ($showSuccess): ?>
            <div class="text-center w-full">
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 dark:bg-green-900/40 mb-4 border border-green-200 dark:border-green-800">
                    <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-4xl">check_circle</span>
                </div>
                <h2 class="text-2xl font-bold text-[#1E3A1D] dark:text-white mb-2">Password Reset!</h2>
                <p class="text-gray-500 dark:text-slate-400 mb-8">Your password has been successfully updated.</p>
                <a href="admin_login.php" class="block w-full bg-[#1E3A1D] hover:bg-[#2a4e29] dark:bg-green-700 dark:hover:bg-green-600 text-white font-bold py-3 px-4 rounded-lg shadow-lg hover:shadow-xl transition-all transform hover:-translate-y-0.5">
                    Go to Login
                </a>
            </div>

        <?php elseif ($showForm): ?>
            <div class="w-16 h-16 bg-[#1E3A1D] text-white rounded-full flex items-center justify-center mx-auto mb-6 shadow-md border-4 border-[#F8F5EE] dark:border-slate-800">
                <span class="material-symbols-outlined text-3xl">key</span>
            </div>

            <h2 class="text-2xl font-bold text-[#2B2B2B] dark:text-white mb-2 text-center w-full">Set New Password</h2>
            <p class="text-gray-500 dark:text-slate-400 text-sm mb-6 text-center w-full">Create a strong password for your account.</p>

            <?php if ($msg): ?>
                <div class="w-full p-3 mb-6 text-sm rounded-lg font-semibold flex items-center gap-2 border-l-4 <?php echo $msgType == 'success' ? 'bg-green-50 border-green-500 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 'bg-red-50 border-red-500 text-red-700 dark:bg-red-900/20 dark:text-red-400'; ?>">
                    <span class="material-symbols-outlined text-lg"><?php echo $msgType == 'success' ? 'check_circle' : 'error'; ?></span>
                    <?php echo $msg; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="w-full space-y-6 text-left">
                <div>
                    <label class="block text-sm font-medium text-[#2B2B2B] dark:text-slate-300 mb-1">New Password</label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 group-hover:text-[#1E3A1D] dark:group-hover:text-green-400 transition-colors z-10">lock</span>
                        
                        <input type="password" name="password" id="password" required 
                               class="w-full pl-10 pr-4 py-3 border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-[#1E3A1D]/20 dark:focus:ring-green-400/20 focus:border-[#1E3A1D] dark:focus:border-green-400 outline-none bg-white dark:bg-slate-900/60 dark:text-white transition-all"
                               placeholder="Enter new password"
                               onkeyup="checkStrength()">
                    </div>
                    
                    <div class="mt-3 text-xs space-y-1.5 ml-1 font-medium">
                        <p id="rule-len" class="text-gray-400 dark:text-slate-500 transition-colors duration-300 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">radio_button_unchecked</span> At least 8 characters</p>
                        <p id="rule-num" class="text-gray-400 dark:text-slate-500 transition-colors duration-300 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">radio_button_unchecked</span> At least one number</p>
                        <p id="rule-sym" class="text-gray-400 dark:text-slate-500 transition-colors duration-300 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">radio_button_unchecked</span> At least one symbol (!@#$)</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-[#2B2B2B] dark:text-slate-300 mb-1">Confirm Password</label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 group-hover:text-[#1E3A1D] dark:group-hover:text-green-400 transition-colors z-10">lock_reset</span>
                        
                        <input type="password" name="confirm_password" required 
                               class="w-full pl-10 pr-4 py-3 border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-[#1E3A1D]/20 dark:focus:ring-green-400/20 focus:border-[#1E3A1D] dark:focus:border-green-400 outline-none bg-white dark:bg-slate-900/60 dark:text-white transition-all"
                               placeholder="Re-enter password">
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-[#1E3A1D] hover:bg-[#2a4e29] dark:bg-green-700 dark:hover:bg-green-600 text-white font-bold text-lg py-3 rounded-lg shadow-lg hover:shadow-xl transition-all transform hover:-translate-y-0.5 active:translate-y-0 mt-2">
                    Update Password
                </button>
            </form>

        <?php else: ?>
            <div class="text-center w-full">
                <div class="w-16 h-16 bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-400 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-200 dark:border-red-800">
                    <span class="material-symbols-outlined text-4xl">error</span>
                </div>
                <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">Link Expired</h2>
                <p class="text-gray-500 dark:text-slate-400 mb-6"><?php echo $msg ? $msg : "This link is invalid."; ?></p>
                
                <?php if ($debugInfo): ?>
                    <div class="bg-gray-100 dark:bg-slate-800 p-2 text-xs font-mono text-gray-500 dark:text-slate-400 rounded-lg mb-6 text-left break-all">
                        Debug: <?php echo $debugInfo; ?>
                    </div>
                <?php endif; ?>

                <a href="forgot_password.php" class="inline-flex w-full justify-center px-6 py-3 bg-[#1E3A1D] hover:bg-[#2a4e29] dark:bg-green-700 dark:hover:bg-green-600 text-white rounded-lg transition-all font-bold shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                    Request a new link
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function checkStrength() {
            const pwd = document.getElementById('password').value;
            const setStatus = (id, valid) => {
                const el = document.getElementById(id);
                const icon = el.querySelector('.material-symbols-outlined');
                if (valid) {
                    el.className = "text-green-600 dark:text-green-400 font-bold transition-colors duration-300 flex items-center gap-1";
                    icon.innerText = "check_circle"; 
                } else {
                    el.className = "text-gray-400 dark:text-slate-500 transition-colors duration-300 flex items-center gap-1";
                    icon.innerText = "radio_button_unchecked"; 
                }
            };
            setStatus('rule-len', pwd.length >= 8);
            setStatus('rule-num', /[0-9]/.test(pwd));
            setStatus('rule-sym', /[\W_]/.test(pwd));
        }

        // Theme Toggle Script
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