<?php
// admin_components/verify_account.php
session_start();

// --- 1. CONNECT TO DATABASE ---
if (file_exists('../includes/db_connection.php')) {
    require '../includes/db_connection.php';
} elseif (file_exists('includes/db_connection.php')) {
    require 'includes/db_connection.php';
} else {
    // Fallback Manual Connection
    $conn = new mysqli("localhost", "u613496064_freshflow_new", "1Freshflow_new", "u613496064_freshflow_new");
}

$step = 'verify'; // Default step
$error = '';
$user_id = 0;

// --- 2. HANDLE FORM SUBMISSION (Setting the Password) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pass1 = $_POST['pass1'];
    $pass2 = $_POST['pass2'];
    
    // PHP VALIDATION (Server-side security)
    if ($pass1 !== $pass2) {
        $error = "Passwords do not match.";
        $step = 'form';
    } elseif (strlen($pass1) < 8) {
        $error = "Password must be at least 8 characters.";
        $step = 'form';
    } elseif (!preg_match("/[0-9]/", $pass1)) {
        $error = "Password must contain at least 1 number.";
        $step = 'form';
    } elseif (!preg_match("/[\W]/", $pass1)) { // \W checks for non-word characters (symbols)
        $error = "Password must contain at least 1 special character (!@#$).";
        $step = 'form';
    } else {
        // SAVE PASSWORD
        if (isset($_SESSION['temp_user_id'])) {
            $uid = $_SESSION['temp_user_id'];
            $hash = password_hash($pass1, PASSWORD_DEFAULT);
            
            // Update User: Set Password + Clear Token + Set Active
            $stmt = $conn->prepare("UPDATE users SET password = ?, verification_token = NULL, status = 'Active' WHERE user_id = ?");
            $stmt->bind_param("si", $hash, $uid);
            
            if ($stmt->execute()) {
                // Success! Redirect to Login
                unset($_SESSION['temp_user_id']);
                
                // We removed "../" because admin_login.php is in the same folder
                header("Location: admin_login.php?success=account_active");
                exit;
            } else {
                $error = "Database Error: " . $conn->error;
            }
        } else {
            $error = "Session expired. Please click the email link again.";
        }
    }
}

// --- 3. VERIFY TOKEN (When clicking the link) ---
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    $stmt = $conn->prepare("SELECT user_id, username FROM users WHERE verification_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        // VALID TOKEN!
        $user = $result->fetch_assoc();
        $_SESSION['temp_user_id'] = $user['user_id']; 
        $step = 'form'; // Show the password form
    } else {
        $error = "Invalid or Expired Link.";
        $step = 'error';
    }
} elseif ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $error = "No token provided.";
    $step = 'error';
}

// Ensure we stay on form if there was a submission error
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($error)) {
    $step = 'form';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate Account - FreshFlow</title>
    
    <link rel="icon" type="image/jpeg" href="/admin_components/assets/img/FF.jpg?v=2">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
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
        
        <?php if ($error && $step !== 'form'): ?>
            <div class="text-center w-full">
                <div class="w-16 h-16 bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-400 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-200 dark:border-red-800">
                    <span class="material-symbols-outlined text-4xl">link_off</span>
                </div>
                <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">Link Invalid</h2>
                <p class="text-gray-500 dark:text-slate-400 mb-6 px-4">This verification link has expired or has already been used.</p>
                <a href="admin_login.php" class="inline-flex w-full justify-center px-6 py-3 bg-[#1E3A1D] hover:bg-[#2a4e29] dark:bg-green-700 dark:hover:bg-green-600 text-white rounded-lg transition-all font-bold shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                    Return to Login
                </a>
            </div>

        <?php elseif ($step === 'form'): ?>
            <div class="w-16 h-16 bg-[#1E3A1D] text-white rounded-full flex items-center justify-center mx-auto mb-6 shadow-md border-4 border-[#F8F5EE] dark:border-slate-800">
                <span class="material-symbols-outlined text-3xl">verified_user</span>
            </div>

            <h2 class="text-2xl font-bold text-[#2B2B2B] dark:text-white mb-1 text-center w-full">Secure Account Setup</h2>
            <p class="text-gray-500 dark:text-slate-400 text-sm mb-6 text-center w-full">Create a strong password to activate your account.</p>

            <?php if ($error): ?>
                <div class="w-full p-3 mb-6 text-sm rounded-lg font-semibold flex items-center gap-2 border-l-4 bg-red-50 border-red-500 text-red-700 dark:bg-red-900/20 dark:text-red-400">
                    <span class="material-symbols-outlined text-lg">error</span>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="w-full space-y-6 text-left">
                <div>
                    <label class="block text-sm font-medium text-[#2B2B2B] dark:text-slate-300 mb-1">New Password</label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 group-hover:text-[#1E3A1D] dark:group-hover:text-green-400 transition-colors z-10">lock</span>
                        
                        <input type="password" id="pass1" name="pass1" required 
                               class="w-full pl-10 pr-10 py-3 border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-[#1E3A1D]/20 dark:focus:ring-green-400/20 focus:border-[#1E3A1D] dark:focus:border-green-400 outline-none bg-white dark:bg-slate-900/60 dark:text-white transition-all"
                               placeholder="Enter your password"
                               onkeyup="checkStrength()">
                               
                        <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 cursor-pointer hover:text-[#1E3A1D] dark:hover:text-green-400 transition-colors z-10 text-lg" onclick="toggleVisibility('pass1')">visibility</span>
                    </div>
                    
                    <div class="mt-3 text-xs space-y-1.5 ml-1 font-medium">
                        <p id="req-len" class="text-gray-400 dark:text-slate-500 transition-colors duration-300 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">radio_button_unchecked</span> At least 8 characters</p>
                        <p id="req-num" class="text-gray-400 dark:text-slate-500 transition-colors duration-300 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">radio_button_unchecked</span> At least 1 number (0-9)</p>
                        <p id="req-sym" class="text-gray-400 dark:text-slate-500 transition-colors duration-300 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">radio_button_unchecked</span> At least 1 symbol (!@#$)</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-[#2B2B2B] dark:text-slate-300 mb-1">Confirm Password</label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 group-hover:text-[#1E3A1D] dark:group-hover:text-green-400 transition-colors z-10">lock_reset</span>
                        
                        <input type="password" id="pass2" name="pass2" required 
                               class="w-full pl-10 pr-10 py-3 border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-[#1E3A1D]/20 dark:focus:ring-green-400/20 focus:border-[#1E3A1D] dark:focus:border-green-400 outline-none bg-white dark:bg-slate-900/60 dark:text-white transition-all"
                               placeholder="Re-enter your password"
                               onkeyup="checkMatch()">
                               
                        <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 cursor-pointer hover:text-[#1E3A1D] dark:hover:text-green-400 transition-colors z-10 text-lg" onclick="toggleVisibility('pass2')">visibility</span>
                    </div>
                    <p id="match-msg" class="text-xs mt-2 h-4 font-bold text-right transition-colors"></p>
                </div>
                
                <button type="submit" id="submitBtn" disabled 
                        class="w-full bg-gray-400 dark:bg-slate-700 text-white font-bold text-lg py-3 rounded-lg transition-all mt-2 cursor-not-allowed">
                    Activate Account
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Toggle Password Visibility
        function toggleVisibility(id) {
            const input = document.getElementById(id);
            input.type = input.type === "password" ? "text" : "password";
        }

        // Live Password Strength & Match Validation
        function checkStrength() {
            const val = document.getElementById('pass1').value;
            
            const hasLen = val.length >= 8;
            const hasNum = /[0-9]/.test(val);
            const hasSym = /[\W]/.test(val); 

            updateReq('req-len', hasLen);
            updateReq('req-num', hasNum);
            updateReq('req-sym', hasSym);

            checkMatch(); 
        }

        function updateReq(id, valid) {
            const el = document.getElementById(id);
            const icon = el.querySelector('.material-symbols-outlined');
            if (valid) {
                el.className = "text-green-600 dark:text-green-400 font-bold transition-colors duration-300 flex items-center gap-1";
                icon.innerText = "check_circle"; 
            } else {
                el.className = "text-gray-400 dark:text-slate-500 transition-colors duration-300 flex items-center gap-1";
                icon.innerText = "radio_button_unchecked"; 
            }
        }

        function checkMatch() {
            const p1 = document.getElementById('pass1').value;
            const p2 = document.getElementById('pass2').value;
            const msg = document.getElementById('match-msg');
            const btn = document.getElementById('submitBtn');
            
            const p1Valid = p1.length >= 8 && /[0-9]/.test(p1) && /[\W]/.test(p1);

            if(p2.length === 0) {
                msg.textContent = "";
                btn.disabled = true;
                btn.className = "w-full bg-gray-400 dark:bg-slate-700 text-white font-bold text-lg py-3 rounded-lg transition-all mt-2 cursor-not-allowed";
                return;
            }

            if(p1 === p2) {
                msg.textContent = "Passwords Match";
                msg.className = "text-xs mt-2 h-4 font-bold text-right text-green-600 dark:text-green-400 transition-colors";
                
                if(p1Valid) {
                    btn.disabled = false;
                    btn.className = "w-full bg-[#1E3A1D] hover:bg-[#2a4e29] dark:bg-green-700 dark:hover:bg-green-600 text-white font-bold text-lg py-3 rounded-lg shadow-lg hover:shadow-xl transition-all transform hover:-translate-y-0.5 active:translate-y-0 mt-2";
                }
            } else {
                msg.textContent = "Passwords Do Not Match";
                msg.className = "text-xs mt-2 h-4 font-bold text-right text-red-600 dark:text-red-400 transition-colors";
                btn.disabled = true;
                btn.className = "w-full bg-gray-400 dark:bg-slate-700 text-white font-bold text-lg py-3 rounded-lg transition-all mt-2 cursor-not-allowed";
            }
        }

        // Theme Toggle Script
        const themeToggleBtn = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
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