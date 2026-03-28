<?php
// C:\xampp\htdocs\RalphPHP\admin_components\request_otp.php
session_start();
include 'includes/db_connection.php';
include 'includes/mailer.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$otp = rand(100000, 999999);
$expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));

// Save OTP
$stmt = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE user_id = ?");
$stmt->bind_param("ssi", $otp, $expiry, $user_id);
$stmt->execute();

// Get Email
$res = $conn->query("SELECT email, first_name FROM users WHERE user_id = $user_id");
$row = $res->fetch_assoc();

if (!$row['email']) {
    echo json_encode(['success' => false, 'message' => 'No email found on file.']);
    exit;
}

// Send OTP
$body = "
    <div style='font-family: sans-serif; padding: 20px; text-align: center;'>
        <h2 style='color: #d9534f;'>FreshFlow Security</h2>
        <p>Hello {$row['first_name']},</p>
        <p>Your One-Time Password (OTP) is:</p>
        <h1 style='background: #eee; padding: 15px; display: inline-block; letter-spacing: 5px; font-size: 30px;'>$otp</h1>
        <p>This code expires in 5 minutes.</p>
    </div>
";

if (sendEmail($row['email'], "Security OTP Code", $body)) {
    echo json_encode(['success' => true, 'message' => 'OTP sent to ' . $row['email']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send OTP via Brevo.']);
}
?>