<?php
// C:\xampp\htdocs\RalphPHP\admin_components\fix_role.php
include 'includes/db_connection.php';
session_start();

$username = 'Chrysler'; // <--- Your exact username

echo "<h1>🛠️ Role Repair Tool</h1>";

// 1. CHECK CURRENT STATUS
$check = $conn->query("SELECT role FROM users WHERE username = '$username'");
if ($check->num_rows > 0) {
    $row = $check->fetch_assoc();
    echo "<p>Current Database Role: <strong>[" . $row['role'] . "]</strong> (If this is blank, that's the problem)</p>";
} else {
    die("<h2 style='color:red'>❌ User '$username' not found! Check spelling.</h2>");
}

// 2. FORCE UPDATE
$sql = "UPDATE users SET role = 'Super Admin' WHERE username = '$username'";
if ($conn->query($sql)) {
    echo "<p style='color:green'>✅ Database Updated Successfully.</p>";
} else {
    echo "<p style='color:red'>❌ Update Failed: " . $conn->error . "</p>";
}

// 3. FORCE SESSION UPDATE (So you don't even need to logout)
$_SESSION['role_name'] = 'Super Admin';
$_SESSION['username'] = $username;
$_SESSION['loggedin'] = true;

echo "<h2>✨ SUCCESS!</h2>";
echo "<p>Your session has been forcibly upgraded to <strong>Super Admin</strong>.</p>";
echo "<p><a href='pages/dashboard.php' style='background:green; color:white; padding:10px; text-decoration:none;'>Go to Dashboard Now</a></p>";
?>