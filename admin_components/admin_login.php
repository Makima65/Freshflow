<?php
session_start();

// 1. THE SMART BOUNCER: CHECK IF ALREADY LOGGED IN
// We check for 'user_id' because that is what your system officially uses!
if (isset($_SESSION['user_id'])) {
$_SESSION['welcome_splash'] = true;
    // If they have an active session, teleport them straight to the dashboard!
    header('Location: pages/dashboard.php');
    exit;
}

// --- SECURITY MESSAGE LOGIC ---
$alert_box = "";
// Check for Errors
if (isset($_GET['error'])) {
    
    // CASE 1: WRONG PASSWORD (WARNING)
    if ($_GET['error'] == 'invalid') {
        $tries = isset($_GET['tries']) ? intval($_GET['tries']) : 3;
        
        // Dynamic Message based on tries left
        if ($tries <= 0) {
            $msg_text = "Account is about to be locked.";
        } else {
            $msg_text = "Warning: You have <b>$tries</b> attempts remaining.";
        }

        $alert_box = "
        <div class='bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 text-red-700 dark:text-red-400 p-4 mb-6 rounded shadow-sm flex items-start animate-fade-in' role='alert'>
            <span class='material-symbols-outlined mr-3 text-red-600 dark:text-red-400 mt-0.5'>error</span>
            <div>
                <p class='font-bold text-sm'>Access Denied</p>
                <p class='text-sm mt-1'>Invalid username or password. <br>$msg_text</p>
            </div>
        </div>";
    } 
    
    // CASE 2: ACCOUNT LOCKED (BAN)
    elseif ($_GET['error'] == 'locked') {
        $time = isset($_GET['time']) ? htmlspecialchars($_GET['time']) : 5;
        $alert_box = "
        <div class='bg-red-900 border-l-4 border-red-500 text-white p-5 mb-6 rounded shadow-lg animate-pulse flex items-center' role='alert'>
            <span class='material-symbols-outlined mr-4 text-4xl'>lock_clock</span>
            <div>
                <p class='font-bold text-lg'>ACCOUNT LOCKED</p>
                <p class='text-sm opacity-90'>This specific user is banned for <b>$time minutes</b>.<br>You may try a different account.</p>
            </div>
        </div>";
    }
    
    // CASE 3: BANNED/INACTIVE
    elseif ($_GET['error'] == 'banned') {
        $alert_box = "
        <div class='bg-orange-100 dark:bg-orange-900/20 border-l-4 border-orange-500 text-orange-800 dark:text-orange-400 p-4 mb-6 rounded text-sm flex items-center' role='alert'>
            <span class='material-symbols-outlined mr-3'>block</span>
            <span>Your account has been deactivated. Please contact the administrator.</span>
        </div>";
    }

    // CASE 4: EMPTY FIELDS
    elseif ($_GET['error'] == 'empty') {
        $alert_box = "
        <div class='bg-yellow-100 dark:bg-yellow-900/20 border-l-4 border-yellow-500 text-yellow-800 dark:text-yellow-400 p-4 mb-6 rounded text-sm flex items-center' role='alert'>
            <span class='material-symbols-outlined mr-3'>warning</span>
            <span>Please enter both username and password.</span>
        </div>";
    }
    
    // CASE 5: TIMEOUT (AUTO-LOGOUT)
    elseif ($_GET['error'] == 'timeout') {
        $alert_box = "
        <div class='bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-400 p-4 mb-6 rounded shadow-sm flex items-start gap-3 animate-fade-in' role='alert'>
            <span class='material-symbols-outlined text-yellow-500 mt-0.5'>schedule</span>
            <div>
                <p class='font-bold text-sm text-yellow-800 dark:text-yellow-400'>Session Expired</p>
                <p class='text-xs text-yellow-700 dark:text-yellow-500 mt-1'>For your security, you have been automatically logged out due to inactivity. Please log in again.</p>
            </div>
        </div>";
    }
}
// Check for Status Messages (Logout/Idle)
else if (isset($_GET['status'])) {
    if ($_GET['status'] === 'idle') {
        $alert_box = "
        <div class='bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-400 text-yellow-800 dark:text-yellow-400 p-4 mb-6 rounded text-sm flex items-center'>
            <span class='material-symbols-outlined mr-3'>timer_off</span>
            <span>You have been logged out due to inactivity.</span>
        </div>";
    } 
    else if ($_GET['status'] === 'loggedout') {
        $alert_box = "
        <div class='bg-green-50 dark:bg-green-900/20 border-l-4 border-green-500 text-green-700 dark:text-green-400 p-4 mb-6 rounded text-sm flex items-center'>
            <span class='material-symbols-outlined mr-3'>check_circle</span>
            <span>You have been successfully logged out.</span>
        </div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login - Perishable Insights</title>
    
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

        /* --- DARK MODE GLOBAL STYLES (Updated with green animated gradient) --- */
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

        /* --- HIGHLY VISIBLE GREEN HOVER GLOW EFFECT --- */
        .login-card-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 50px -12px rgba(30, 58, 29, 0.6), 0 0 25px rgba(30, 58, 29, 0.4);
        }
        .dark .login-card-container:hover {
            box-shadow: 0 25px 50px -12px rgba(74, 222, 128, 0.5), 0 0 35px rgba(74, 222, 128, 0.4);
        }

        .custom-input {
            transition: all 0.3s ease;
            background-color: white;
            border: 1px solid #d1d5db; 
        }
        
        .custom-input:hover {
            border-color: var(--brand-green);
            box-shadow: 0 0 0 4px rgba(30, 58, 29, 0.1); 
        }

        .custom-input:focus {
            border-color: var(--brand-green);
            outline: none;
            box-shadow: 0 0 0 4px rgba(30, 58, 29, 0.25); 
        }

        /* Dark Mode Inputs */
        .dark .custom-input { 
            background-color: rgba(30, 41, 59, 0.6); 
            border-color: #334155; 
            color: #f8fafc; 
        }
        .dark .custom-input:focus { 
            border-color: #4ade80; 
            box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.15); 
        }
        
        /* Animation for alerts */
        .animate-fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* --- NEW: Left Panel Animations --- */
        .bg-animated-green {
            background: linear-gradient(-45deg, #0f240f, #1E3A1D, #2a4e29, #142613);
            background-size: 400% 400%;
            animation: moveGradient 15s ease infinite;
        }
        @keyframes moveGradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .floating-orb-1 {
            animation: float1 8s ease-in-out infinite;
        }
        .floating-orb-2 {
            animation: float2 12s ease-in-out infinite reverse;
        }
        @keyframes float1 {
            0%, 100% { transform: translateY(0) scale(1); opacity: 0.15; }
            50% { transform: translateY(-30px) scale(1.1); opacity: 0.25; }
        }
        @keyframes float2 {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.1; }
            50% { transform: translate(-20px, -20px) scale(1.2); opacity: 0.2; }
        }
        
        /* --- PREMIUM MIDNIGHT EMERALD ANIMATIONS --- */
        .bg-premium-dark {
            /* A very deep mix of midnight slate, rich charcoal, and absolute dark green */
            background: linear-gradient(135deg, #051005, #111827, #0a1c09, #000000);
            background-size: 400% 400%;
            animation: gradientMove 15s ease infinite;
        }
        @keyframes gradientMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .glow-orb-1 {
            animation: floatGlow1 10s ease-in-out infinite;
        }
        .glow-orb-2 {
            animation: floatGlow2 14s ease-in-out infinite reverse;
        }
        
        @keyframes floatGlow1 {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.15; }
            50% { transform: translate(-30px, 30px) scale(1.2); opacity: 0.3; }
        }
        @keyframes floatGlow2 {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.1; }
            50% { transform: translate(30px, -30px) scale(1.3); opacity: 0.25; }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

    <button id="themeToggle" type="button" class="absolute top-6 right-6 p-3 rounded-full bg-white shadow-md text-gray-500 hover:text-[#1E3A1D] hover:bg-gray-100 dark:bg-slate-800 dark:border dark:border-slate-700 dark:hover:bg-slate-700 dark:text-gray-400 dark:hover:text-white transition-all flex items-center justify-center focus:outline-none z-50" title="Toggle Dark/Light Mode">
        <span class="material-symbols-outlined text-2xl transition-transform" id="themeIcon">dark_mode</span>
    </button>

    <div class="w-full max-w-4xl mx-auto flex flex-col lg:flex-row rounded-2xl overflow-hidden login-card-container bg-white dark:bg-slate-900/80 dark:border dark:border-slate-800 shadow-2xl">
        
        <div class="flex w-full lg:w-[45%] flex-col items-center justify-center p-8 lg:p-12 text-white relative overflow-hidden bg-premium-dark transition-all duration-700 border-b lg:border-b-0 lg:border-r border-gray-800/30 min-h-[220px] lg:min-h-[600px]">

            <div class="absolute top-[-10%] left-[-10%] w-64 lg:w-80 h-64 lg:h-80 bg-emerald-500 rounded-full blur-[80px] lg:blur-[100px] glow-orb-1"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-56 lg:w-72 h-56 lg:h-72 bg-[#1E3A1D] rounded-full blur-[60px] lg:blur-[80px] glow-orb-2"></div>

            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-48 lg:w-64 h-48 lg:h-64 bg-white/5 rounded-full blur-2xl lg:blur-3xl animate-pulse"></div>

            <div class="z-10 text-center transform transition duration-500 hover:scale-105">
                <img src="/admin_components/assets/img/FreshflowGmailLogo2.png" alt="FreshFlow Logo" class="w-48 sm:w-56 lg:w-full max-w-[280px] h-auto mb-2 lg:mb-4 mx-auto object-contain drop-shadow-[0_10px_25px_rgba(0,0,0,0.6)]">
            </div>
        </div>

        <div class="w-full lg:w-[55%] flex items-center justify-center p-6 sm:p-8 lg:p-12 bg-[#F8F5EE] dark:bg-transparent relative">
            <div class="w-full max-w-md space-y-4 lg:space-y-6">
                
                <div class="text-left mb-6 lg:mb-8">
                    <h2 class="text-2xl lg:text-3xl font-bold text-[#2B2B2B] dark:text-white mb-2">Welcome Back!</h2>
                    <p class="text-sm lg:text-base text-gray-500 dark:text-slate-400">Sign in to continue</p>
                </div>

                <?php if (!empty($alert_box)) echo $alert_box; ?>

                <form class="space-y-6" action="process_admin_login.php" method="POST">
                    
                    <div class="relative group mt-6">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 z-10 transition-colors group-hover:text-[#1E3A1D] dark:group-hover:text-green-400">person</span>
                        
                        <input id="username" name="username" type="text" required 
                               class="peer custom-input w-full pl-10 pr-4 py-3 rounded-lg text-gray-800 placeholder-transparent outline-none transition duration-300" 
                               placeholder="Username">
                               
                        <label for="username" class="absolute left-10 top-1/2 -translate-y-1/2 text-gray-400 transition-all duration-300 peer-placeholder-shown:top-1/2 peer-placeholder-shown:text-base peer-focus:-top-2.5 peer-focus:left-3 peer-focus:text-xs peer-focus:text-[#1E3A1D] dark:peer-focus:text-green-400 peer-[:not(:placeholder-shown)]:-top-2.5 peer-[:not(:placeholder-shown)]:left-3 peer-[:not(:placeholder-shown)]:text-xs font-semibold pointer-events-none bg-transparent peer-focus:bg-white dark:peer-focus:bg-slate-900 peer-[:not(:placeholder-shown)]:bg-white dark:peer-[:not(:placeholder-shown)]:bg-slate-900 px-1 rounded-sm">Username</label>
                    </div>

                    <div class="relative group mt-8">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 z-10 transition-colors group-hover:text-[#1E3A1D] dark:group-hover:text-green-400">lock</span>
                        
                        <input id="password" name="password" type="password" required 
                               class="peer custom-input w-full pl-10 pr-10 py-3 rounded-lg text-gray-800 placeholder-transparent outline-none transition duration-300" 
                               placeholder="Password">
                               
                        <label for="password" class="absolute left-10 top-1/2 -translate-y-1/2 text-gray-400 transition-all duration-300 peer-placeholder-shown:top-1/2 peer-placeholder-shown:text-base peer-focus:-top-2.5 peer-focus:left-3 peer-focus:text-xs peer-focus:text-[#1E3A1D] dark:peer-focus:text-green-400 peer-[:not(:placeholder-shown)]:-top-2.5 peer-[:not(:placeholder-shown)]:left-3 peer-[:not(:placeholder-shown)]:text-xs font-semibold pointer-events-none bg-transparent peer-focus:bg-white dark:peer-focus:bg-slate-900 peer-[:not(:placeholder-shown)]:bg-white dark:peer-[:not(:placeholder-shown)]:bg-slate-900 px-1 rounded-sm">Password</label>
                            
                        <button type="button" id="togglePassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#1E3A1D] dark:hover:text-green-400 transition-colors z-10">
                            <span class="material-symbols-outlined text-xl" id="toggleIcon">visibility</span>
                        </button>
                    </div>
                    
                    <div class="flex justify-end mt-2">
                        <a href="forgot_password.php" class="text-xs font-bold text-gray-400 hover:text-[#1E3A1D] dark:hover:text-green-400 transition-colors">
                            Forgot Password?
                        </a>
                    </div>
                    
                    <div class="pt-4">
                        <button type="submit" 
                                class="w-full py-3 px-4 rounded-lg text-white font-bold text-lg shadow-lg hover:shadow-xl transition-all transform hover:-translate-y-0.5 active:translate-y-0 bg-[#1E3A1D] hover:bg-[#2a4e29] dark:bg-green-700 dark:hover:bg-green-600">
                            Login
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script>
        // --- PASSWORD TOGGLE LOGIC ---
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.getElementById('toggleIcon');

        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                toggleIcon.textContent = type === 'password' ? 'visibility' : 'visibility_off';
            });
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
    </script>
</body>
</html>