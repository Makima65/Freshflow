<?php
// admin_components/includes/db_connection.php

// 1. DATABASE CREDENTIALS (Your Hostinger Setup)
$servername = "localhost";
$username = "u613496064_freshflow_user";
$password = "65!Freshflow657823";
$dbname = "u613496064_freshflow_db"; 

// 2. CREATE CONNECTION
$conn = new mysqli($servername, $username, $password, $dbname);

// 3. CHECK CONNECTION
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- NEW TIMEZONE FIX FOR HOSTINGER (PHILIPPINES) ---
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");
// ----------------------------------------------------

// ============================================================
//  SECURITY: SESSION GUARD & ACCOUNT STATUS CHECK
// ============================================================



if (session_status() == PHP_SESSION_NONE) {
    
    // STRICT SESSION SECURITY (Cookie Flags)
    session_set_cookie_params([
        'lifetime' => 0,               // Cookie dies when browser closes
        'path' => '/',                 // Works across the whole site
        'domain' => '',                // Current domain
        'secure' => isset($_SERVER['HTTPS']), // Only sends over HTTPS if SSL is active
        'httponly' => true,            // PREVENTS JAVASCRIPT HACKING (XSS)
        'samesite' => 'Lax'            // PREVENTS CROSS-SITE FORGERY (CSRF)
    ]);

    session_start();
}

// Only run security checks if the user is logged in
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['user_id'])) {
    
    // --------------------------------------------------------
    // A. BROWSER CHECK (Anti-Hijacking)
    // --------------------------------------------------------
    if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
        session_unset();
        session_destroy();
        // Redirect to root login page
        header("Location: /admin_login.php?error=session_hijack"); 
        exit();
    }

    // --------------------------------------------------------
    // B. NUCLEAR STATUS CHECK (The "Kill Switch")
    // --------------------------------------------------------
    // This checks the DB every time a page loads to see if user is Deleted or Inactive
    $check_id = $_SESSION['user_id'];
    
    // Direct query to ensure speed
    $auth_check = $conn->query("SELECT status FROM users WHERE user_id = $check_id LIMIT 1");
    
    $kill_session = false;

    if ($auth_check->num_rows === 0) {
        // User record was DELETED
        $kill_session = true;
    } else {
        $user_status = $auth_check->fetch_assoc();
        if ($user_status['status'] !== 'Active') {
            // User was SUSPENDED or INACTIVE
            $kill_session = true;
        }
    }

    if ($kill_session) {
        // Destroy the session
        session_unset();
        session_destroy();
        
        // Handle AJAX requests (so buttons fail gracefully)
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'SESSION EXPIRED: Account deleted or deactivated.']);
            exit;
        } else {
            // Standard Page Redirect
            header("Location: /admin_login.php?error=AccountTerminated"); 
            exit;
        }
    }
}
?>