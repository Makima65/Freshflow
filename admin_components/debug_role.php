<?php
session_start();
echo "<h1 style='font-family:sans-serif;'>🕵️ Session Inspector</h1>";
echo "<pre style='background:#eee; padding:10px; font-size:16px;'>";

echo "<b>Username:</b> " . ($_SESSION['username'] ?? 'NOT SET') . "\n";
echo "<b>Current Session Role:</b> " . ($_SESSION['role_name'] ?? 'NOT SET') . "\n";
echo "<b>Required for Access:</b> 'admin' OR 'Super Admin'\n";

echo "</pre>";

echo "<br><a href='logout.php' style='background:red; color:white; padding:10px; text-decoration:none;'>CLICK HERE TO FORCE LOGOUT</a>";
?>