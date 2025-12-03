<?php
//check_session.php

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

session_start();

// إذا لم يتم تسجيل الدخول
if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode([
        'success' => false,
        'message' => 'جلسة المستخدم غير صالحة أو منتهية'
    ]);
    exit;
}

// ✅ إذا الجلسة صالحة، أرسل معلومات المستخدم
echo json_encode([
    'success' => true,
    'user' => $_SESSION['user']
]);
exit;
?>
