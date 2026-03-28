<?php
session_start();
include_once 'db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // ACTION 1: Mark ALL as read
        if ($_POST['action'] === 'mark_notifications_read') {
            $conn->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
            echo json_encode(['success' => true]);
            exit;
        }
        
        // ACTION 2: Mark ONE specific notification as read
        if ($_POST['action'] === 'mark_one_read' && isset($_POST['notif_id'])) {
            $id = intval($_POST['notif_id']);
            $conn->query("UPDATE notifications SET is_read = 1 WHERE id = $id");
            echo json_encode(['success' => true]);
            exit;
        }
        
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>