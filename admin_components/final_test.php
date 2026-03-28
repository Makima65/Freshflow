<?php
// public_html/admin_components/final_test.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'includes/PHPMailer/Exception.php';
require 'includes/PHPMailer/PHPMailer.php';
require 'includes/PHPMailer/SMTP.php';

echo "<h1>FreshFlow Professional Email Test</h1>";

$mail = new PHPMailer(true);

try {
    // ---------------------------------------------------
    // HOSTINGER SMTP SETTINGS (The "Paid" Way)
    // ---------------------------------------------------
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';  // <--- Direct to Hostinger
    $mail->SMTPAuth   = true;
    $mail->Port       = 465;                   // <--- Secure SSL Port
    $mail->SMTPSecure = 'ssl';

    // ---------------------------------------------------
    // YOUR NEW CREDENTIALS
    // ---------------------------------------------------
    $mail->Username   = 'admin@freshflow.site';
    
    // IMPORTANT: Use the password you created for the EMAIL account
    // (NOT your Hostinger login password, unless they are the same)
    $mail->Password   = '65!Makima657823'; 

    // ---------------------------------------------------
    // SENDER INFO
    // ---------------------------------------------------
    $mail->setFrom('admin@freshflow.site', 'FreshFlow Admin');
    $mail->addAddress('freshflow.ralph@gmail.com'); // Send to your personal Gmail

    $mail->Subject = 'FreshFlow System: It Works!';
    $mail->Body    = 'This email was sent directly via Hostinger SMTP. No Brevo needed!';

    $mail->send();
    echo "<h2 style='color:green'>SUCCESS! Sent via Hostinger.</h2>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>FAILED</h2>";
    echo "Mailer Error: " . $mail->ErrorInfo;
}
?>