<?php
// C:\xampp\htdocs\RalphPHP\admin_components\includes\audit_helper.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 1. SMART DB CONNECTION
// This ensures we find the database file no matter where this helper is included from
if (!isset($conn) || $conn->connect_error) {
    $paths = [
        '../includes/db_connection.php',
        '../../includes/db_connection.php',
        '../../../includes/db_connection.php', 
        'db_connection.php' 
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            include_once $path;
            break;
        }
    }
}

// 2. LOGGING FUNCTION (Matches your 'audit_trail' table screenshot)
if (!function_exists('log_audit_action')) {
    function log_audit_action($action_type, $action_category, $details, $metadata = []) {
        global $conn;

        $user_id = $_SESSION['user_id'] ?? 0;
        $username = $_SESSION['username'] ?? 'System';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '::1';
        
        // Convert array to JSON for the 'metadata' column
        $meta_json = !empty($metadata) ? json_encode($metadata) : null;

        if ($conn) {
            // Matches columns: user_id, username, action_type, action_category, details, metadata, ip_address
            $sql = "INSERT INTO audit_trail (user_id, username, action_type, action_category, details, metadata, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("issssss", $user_id, $username, $action_type, $action_category, $details, $meta_json, $ip_address);
                $stmt->execute();
                $stmt->close();
                return true;
            }
        }
        return false;
    }
}
?>