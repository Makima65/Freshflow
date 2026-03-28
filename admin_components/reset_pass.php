<?php
// admin_components/reset_password.php
session_start();

// 1. ENABLE ERROR DISPLAY (So we don't get a blank 500 page)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. CHECK FILE PATHS BEFORE INCLUDING
$db_path = __DIR__ . '/includes/db_connection.php';

if (!file_exists($db_path)) {
    die("<div style='color:red; padding:20px; font-family:sans-serif;'>
            <b>Error:</b> Could not find database file.<br>
            Expected location: <b>$db_path</b><br><br>
            Please check if your 'includes' folder is inside 'admin_components'.
         </div>");
}

require $db_path;

$token = $_GET['token'] ?? '';
$msg = '';
$msgType = '';
$showForm = false;

// 3. CHECK IF TOKEN IS MISSING
if (empty($token)) {
    die("Invalid request. No token provided.");
}

// 4. VERIFY TOKEN & EXPIRY
// NOTE: We select 'user_id' specifically. If your DB uses 'id', change 'user_id' to 'id' below.
$sql = "SELECT user_id, username FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database Error in Prepare: " . $conn->error);
}

$stmt->bind_param("s", $token);

if (!$stmt->execute()) {
    die("Database Error in Execute: " . $stmt->error);
}

$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    $showForm = true;
} else {
    $msg = "This link is invalid or has expired.";
    $msgType = "error";
}

// 5. HANDLE PASSWORD UPDATE
if ($_SERVER["REQUEST_METHOD"] == "POST" && $showForm) {
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    // Basic Validation
    if (strlen($pass) < 8) {
        $msg = "Password must be at least 8 characters.";
        $msgType = "error";
    } elseif ($pass !== $confirm) {
        $msg = "Passwords do not match.";
        $msgType = "error";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        
        // Update Password
        // Note: Make sure 'user_id' matches your database column name (e.g. 'id')
        $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE user_id = ?");
        
        if ($update) {
            $update->bind_param("si", $hash, $user['user_id']);
            
            if ($update->execute()) {
                $msg = "Password updated! Redirecting...";
                $msgType = "success";
                $showForm = false;
                header("refresh:2;url=admin_login.php");
            } else {
                $msg = "Update Failed: " . $update->error;
                $msgType = "error";
            }
        } else {
            $msg = "Prepare Update Failed: " . $conn->error;
            $msgType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Reset Password</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F8F5EE; }
        .valid { color: #15803d; } .invalid { color: #9ca3af; }
        .check-icon { font-size: 16px; vertical-align: middle; margin-right: 4px; display: none; }
        .valid .check-icon { display: inline; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md border border-gray-200">
        
        <?php if ($msg): ?>
            <div class="p-4 mb-4 text-sm font-bold rounded border-l-4 <?php echo $msgType == 'success' ? 'bg-green-100 text-green-700 border-green-500' : 'bg-red-100 text-red-700 border-red-500'; ?>">
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <h1 class="text-2xl font-bold text-[#1E3A1D] mb-6 text-center">Set New Password</h1>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">New Password</label>
                    <input type="password" name="password" id="password" required 
                           class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:border-[#1E3A1D]"
                           onkeyup="validatePassword()">
                           
                    <div class="mt-2 text-xs space-y-1 bg-gray-50 p-3 rounded border border-gray-100">
                        <p id="rule-length" class="invalid"><span class="material-icons check-icon">check_circle</span>At least 8 characters</p>
                        <p id="rule-upper" class="invalid"><span class="material-icons check-icon">check_circle</span>One Uppercase</p>
                        <p id="rule-number" class="invalid"><span class="material-icons check-icon">check_circle</span>One Number</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Confirm Password</label>
                    <input type="password" name="confirm_password" required class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:border-[#1E3A1D]">
                </div>
                
                <button type="submit" class="w-full bg-[#1E3A1D] hover:bg-[#142613] text-white font-bold py-3 rounded-lg shadow-lg transition transform hover:-translate-y-0.5">
                    Update Password
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function validatePassword() {
            const pwd = document.getElementById('password').value;
            const setClass = (id, valid) => {
                document.getElementById(id).className = valid ? 'valid font-bold' : 'invalid';
            };
            setClass('rule-length', pwd.length >= 8);
            setClass('rule-upper', /[A-Z]/.test(pwd));
            setClass('rule-number', /[0-9]/.test(pwd));
        }
    </script>
</body>
</html>