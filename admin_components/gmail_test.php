<?php
// public_html/admin_components/gmail_test.php

// 1. Show all PHP errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2. Load Mailer Components manually
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Adjust these paths if your PHPMailer folder is somewhere else
require 'includes/PHPMailer/Exception.php';
require 'includes/PHPMailer/PHPMailer.php';
require 'includes/PHPMailer/SMTP.php';

echo "<h1>Gmail SMTP SSL/465 Test</h1>";

$mail = new PHPMailer(true);

try {
    // 1. Debugging
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = 'html';

    // 2. Protocol Settings (SSL on Port 465)
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->SMTPSecure = 'ssl'; // Using SSL
    $mail->Port       = 465;   // Using Port 465

    // 3. FORCE ALLOW "INSECURE" CERTIFICATES (Crucial for XAMPP/Localhost)
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    // 4. Credentials
    $mail->Username   = 'ralphchyrslersilva65@gmail.com'; 
    $mail->Password   = 'gvxsonfszwqylduy'; // Spaces removed for safety

    // 5. Send
    $mail->setFrom('ralphchyrslersilva65@gmail.com', 'FreshFlow SSL Test');
    $mail->addAddress('ralphchyrslersilva65@gmail.com');

    $mail->Subject = 'Port 465 SSL Test';
    $mail->Body    = 'If you read this, the SSL connection worked!';

    $mail->send();
    echo "<h2 style='color:green'>SUCCESS! Email sent using Port 465.</h2>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>FAILED</h2>";
    echo "<strong>Mailer Error:</strong> " . $mail->ErrorInfo;
}
?>