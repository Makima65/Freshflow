<?php
// public_html/mail_config.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =================================================================
// 1. LOCATE PHPMAILER FILES
// We try to find PHPMailer inside 'admin_components/includes'
// because this file sits in the ROOT folder.
// =================================================================

// Define the path to your PHPMailer folder
$phpMailerPath = __DIR__ . '/admin_components/includes/PHPMailer';

// Check if the path is correct, otherwise try the local includes
if (!file_exists($phpMailerPath . '/Exception.php')) {
    $phpMailerPath = __DIR__ . '/includes/PHPMailer'; // Fallback
}

require $phpMailerPath . '/Exception.php';
require $phpMailerPath . '/PHPMailer.php';
require $phpMailerPath . '/SMTP.php';


function getMailer() {
    $mail = new PHPMailer(true);
    
    // Server Settings (Hostinger SMTP)
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Port       = 465;
    $mail->SMTPSecure = 'ssl'; // Hostinger uses SSL on 465

    // YOUR CREDENTIALS
    $mail->Username   = 'admin@freshflow.site';
    $mail->Password   = '65!Makima657823'; 

    // Default Sender
    $mail->setFrom('admin@freshflow.site', 'FreshFlow Admin');
    $mail->isHTML(true);

    return $mail;
}
?>