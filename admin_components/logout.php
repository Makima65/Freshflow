<?php
// C:\xampp\htdocs\RalphPHP\admin_components\logout.php

session_start();

// Include DB and Audit Helper to log the action before destroying session
include_once 'includes/db_connection.php';
include_once 'includes/audit_helper.php';

// Check if the logout was forced by the inactivity timeout
$is_timeout = (isset($_GET['timeout']) && $_GET['timeout'] == '1');

// --- LOG THE LOGOUT ---
if (isset($_SESSION['user_id']) && function_exists('log_audit_action')) {
    if ($is_timeout) {
        // Log it as an automatic timeout
        log_audit_action('Auto-Logout', 'Authentication', "User automatically logged out due to inactivity.");
    } else {
        // Log it as a normal, manual logout
        log_audit_action('Logout', 'Authentication', "User manually logged out.");
    }
}

// Destroy the session
$_SESSION = array();
session_destroy();

// Redirect to login page based on HOW they logged out
if ($is_timeout) {
    // Sends them to your yellow alert box
    header("location: admin_login.php?error=timeout");
} else {
    // Sends them to the normal logout message
    header("location: admin_login.php?status=loggedout");
}
exit;
?>