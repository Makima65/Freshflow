<?php
// admin_components/includes/mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// These paths assume the PHPMailer folder is in the same directory as this file
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        // 1. GMAIL SETTINGS
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        // 2. YOUR GMAIL CREDENTIALS
        $mail->Username   = 'ralphchyrslersilva65@gmail.com'; 
        
        // UPDATED: New App Password with spaces removed
        $mail->Password   = 'lnztdfxbebzdcdfo'; 
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // 3. SENDER INFO
        $mail->setFrom('ralphchyrslersilva65@gmail.com', 'FreshFlow Admin');
        $mail->addAddress($to);

        // 4. CONTENT
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error for server-side debugging
        error_log("Gmail Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>