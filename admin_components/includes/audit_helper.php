<?php
// admin_components/includes/audit_helper.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('log_audit_action')) {

    function log_audit_action(string $action_type, string $action_category, string $details, array $metadata = []): bool {
        global $conn;

        // Security check for session variables
        $user_id = $_SESSION['user_id'] ?? 0;
        $username = $_SESSION['username'] ?? 'SYSTEM';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        
        // CAPTURE REAL BROWSER AGENT
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser/Device';
        
        // Ensure connection is available
        if (!$conn || $conn->connect_error) {
            error_log("Audit log failed: Database connection not available.");
            return false;
        }

        // --- SAFE DB AUTO-PATCHER: CREATE DEDICATED USER_AGENT COLUMN ---
        try {
            $colCheck = $conn->query("SHOW COLUMNS FROM audit_trail LIKE 'user_agent'");
            if ($colCheck && $colCheck->num_rows == 0) {
                @$conn->query("ALTER TABLE audit_trail ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address");
            }
        } catch (Throwable $e) {}

        // Check if the audit_trail table has the 'metadata' and 'user_agent' columns
        $has_metadata = false;
        $mCheck = $conn->query("SHOW COLUMNS FROM audit_trail LIKE 'metadata'");
        if ($mCheck && $mCheck->num_rows > 0) $has_metadata = true;

        $has_user_agent = false;
        $aCheck = $conn->query("SHOW COLUMNS FROM audit_trail LIKE 'user_agent'");
        if ($aCheck && $aCheck->num_rows > 0) $has_user_agent = true;

        // Prepare metadata JSON string
        $metadata_json = $has_metadata ? json_encode($metadata) : null;
        if ($metadata_json === false) {
             $metadata_json = json_encode(['error' => 'Metadata encoding failed']);
        }

        // Dynamically insert based on available columns
        if ($has_metadata && $has_user_agent) {
            $sql = "INSERT INTO audit_trail (user_id, username, action_type, action_category, details, ip_address, user_agent, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) $stmt->bind_param("isssssss", $user_id, $username, $action_type, $action_category, $details, $ip_address, $user_agent, $metadata_json);
        } elseif ($has_metadata && !$has_user_agent) {
            $sql = "INSERT INTO audit_trail (user_id, username, action_type, action_category, details, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) $stmt->bind_param("issssss", $user_id, $username, $action_type, $action_category, $details, $ip_address, $metadata_json);
        } else {
            $sql = "INSERT INTO audit_trail (user_id, username, action_type, action_category, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) $stmt->bind_param("isssss", $user_id, $username, $action_type, $action_category, $details, $ip_address);
        }

        if (!$stmt) {
            error_log("Audit log failed to prepare statement: " . $conn->error);
            return false;
        }

        $success = $stmt->execute();
        if (!$success) {
            error_log("Audit log failed to execute statement: " . $stmt->error);
        }
        $stmt->close();
        return $success;
    }
}
?>