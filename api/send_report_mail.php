<?php
// send_report_mail.php
// POST: to, subject, body
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
// optionally require db.php if you want to log sends

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success'=>false,'message'=>'Method not allowed']);
  exit;
}

$to = trim($_POST['to'] ?? '');
$subject = trim($_POST['subject'] ?? 'Report from Sharjah Cats & Dogs Shelter');
$body = trim($_POST['body'] ?? '');

if (!$to || !$body) {
  echo json_encode(['success'=>false,'message'=>'Missing to or body']);
  exit;
}

// Simple mail() example (replace with PHPMailer in production)
$headers = "From: " . (defined('MAIL_FROM') ? MAIL_FROM : 'no-reply@yourdomain.com') . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$ok = mail($to, $subject, $body, $headers);

if ($ok) {
  echo json_encode(['success'=>true]);
} else {
  echo json_encode(['success'=>false,'message'=>'mail_failed']);
}
?>