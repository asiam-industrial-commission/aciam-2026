<?php
// register_handler.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer from /includes folder
require __DIR__ . '/includes/src/Exception.php';
require __DIR__ . '/includes/src/PHPMailer.php';
require __DIR__ . '/includes/src/SMTP.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'aciambfc_user');
define('DB_PASS', 'JesusChrist@2024');
define('DB_NAME', 'aciambfc_dbase');

// Email configuration
define('SMTP_HOST', 'mail.aciam-bf2026.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'info@aciam-bf2026.com');
define('SMTP_PASS', 'burkina@4321');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Handle registration
    handleRegistration($input);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function handleRegistration($input) {
    // Validate required fields
    $required = ['firstName', 'lastName', 'email', 'ticket', 'country'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate email format
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email format");
    }
    
    // Connect to database
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed");
    }
    
    $conn->set_charset("utf8mb4");
    
    // Prepare and bind
    $stmt = $conn->prepare("INSERT INTO registrations (first_name, last_name, email, company, role, ticket_type, country, message, final_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $firstName = htmlspecialchars(trim($input['firstName']), ENT_QUOTES, 'UTF-8');
    $lastName = htmlspecialchars(trim($input['lastName']), ENT_QUOTES, 'UTF-8');
    $email = trim($input['email']);
    $company = htmlspecialchars(trim($input['company'] ?? ''), ENT_QUOTES, 'UTF-8');
    $role = htmlspecialchars(trim($input['role'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ticket = htmlspecialchars(trim($input['ticket']), ENT_QUOTES, 'UTF-8');
    $country = htmlspecialchars(trim($input['country']), ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars(trim($input['message'] ?? ''), ENT_QUOTES, 'UTF-8');
    $finalAmount = floatval($input['finalAmount'] ?? 0);
    
    $stmt->bind_param("ssssssssd", $firstName, $lastName, $email, $company, $role, $ticket, $country, $message, $finalAmount);
    
    // Execute the statement
    if ($stmt->execute()) {
        $registrationId = $stmt->insert_id;
        
        // Send registration confirmation email
        $emailSent = sendRegistrationEmail($email, $firstName, $lastName, $ticket, $country, $company, $role, $finalAmount);
        
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful' . (!$emailSent ? ' (email notification failed)' : ''),
            'id' => $registrationId,
            'emailSent' => $emailSent
        ]);
    } else {
        throw new Exception("Failed to save registration");
    }
    
    $stmt->close();
    $conn->close();
}

function sendRegistrationEmail($email, $firstName, $lastName, $ticket, $country, $company, $role, $amount) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        
        // Recipients
        $mail->setFrom('info@aciam-bf2026.com', 'ACIAM 2026');
        $mail->addAddress($email, "$firstName $lastName");
        $mail->addReplyTo('asiam.industrial.commission@gmail.com', 'ACIAM Team');
        
        // Content
        $mail->isHTML(false);
        $mail->Subject = 'ACIAM 2026 - Registration Confirmation';
        
        $emailBody = "Dear $firstName $lastName,\n\n";
        $emailBody .= "Thank you for registering for ACIAM 2026!\n\n";
        $emailBody .= "Your registration has been successfully recorded.\n\n";
        $emailBody .= "═══════════════════════════════════════\n";
        $emailBody .= "REGISTRATION DETAILS\n";
        $emailBody .= "═══════════════════════════════════════\n\n";
        $emailBody .= "Name: $firstName $lastName\n";
        $emailBody .= "Email: $email\n";
        if (!empty($company)) {
            $emailBody .= "Company: $company\n";
        }
        if (!empty($role)) {
            $emailBody .= "Role: $role\n";
        }
        $emailBody .= "Ticket Type: $ticket\n";
        $emailBody .= "Country: $country\n";
        if ($amount > 0) {
            $emailBody .= "Amount: €" . number_format($amount, 2) . "\n";
        }
        $emailBody .= "\n═══════════════════════════════════════\n\n";
        $emailBody .= "NEXT STEPS:\n";
        $emailBody .= "• Complete your registration payment using the ACIAM Bank Details or the PayPal option provided\n";
        $emailBody .= "• You will receive a payment confirmation email once completed\n";
        $emailBody .= "• We will send you further event details closer to the date\n\n";
        $emailBody .= "If you have any questions, please contact us at:\n";
        $emailBody .= "info@aciam-bf2026.com\n\n";
        $emailBody .= "We look forward to seeing you at ACIAM 2026!\n\n";
        $emailBody .= "Best regards,\n";
        $emailBody .= "The ACIAM Team\n";
        $emailBody .= "Africa Conference for Industrial and Applied Mathematics";
        
        $mail->Body = $emailBody;
        
        $mail->send();
        
        // Send admin copy
        sendAdminNotification($firstName, $lastName, $email, $company, $role, $ticket, $country, $amount);
        
        return true;
    } catch (Exception $e) {
        error_log("Email error: {$mail->ErrorInfo}");
        return false;
    }
}

function sendAdminNotification($firstName, $lastName, $email, $company, $role, $ticket, $country, $amount) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        
        // Recipients
        $mail->setFrom('info@aciam-bf2026.com', 'ACIAM 2026');
        $mail->addAddress('asiam.industrial.commission@gmail.com');
        
        // Content
        $mail->isHTML(false);
        $mail->Subject = 'New Registration - ACIAM 2026';
        
        $adminBody = "New registration received:\n\n";
        $adminBody .= "Name: $firstName $lastName\n";
        $adminBody .= "Email: $email\n";
        $adminBody .= "Company: $company\n";
        $adminBody .= "Role: $role\n";
        $adminBody .= "Ticket: $ticket\n";
        $adminBody .= "Country: $country\n";
        $adminBody .= "Amount: €" . number_format($amount, 2) . "\n";
        
        $mail->Body = $adminBody;
        
        $mail->send();
        
        return true;
    } catch (Exception $e) {
        error_log("Admin notification error: {$mail->ErrorInfo}");
        return false;
    }
}
?>