<?php
// /health_vet/api/auth.php - نقطة نهاية لجلب بيانات المستخدم عبر AJAX

// 1. إعدادات PHP الأساسية
ini_set('display_errors', 0);
error_reporting(0);

// 2. بدء الجلسة
// هذا السطر ضروري لقراءة الجلسة التي تم إنشاؤها في login.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. إعداد استجابة JSON
header('Content-Type: application/json; charset=utf-8');

// 4. التحقق من الصلاحيات كدوال
if (!function_exists('canAdd')) {
    function canAdd() {
        return isset($_SESSION['user']['CanAdd']) && (int)$_SESSION['user']['CanAdd'] === 1;
    }
}
if (!function_exists('canEdit')) {
    function canEdit() {
        return isset($_SESSION['user']['CanEdit']) && (int)$_SESSION['user']['CanEdit'] === 1;
    }
}
// يمكنك إضافة باقي الدوال (canDelete, isAdmin, إلخ) هنا إذا أردت استخدامها مباشرة

// 5. بناء الاستجابة
$response = [];

if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    // المستخدم مسجل الدخول
    $response = [
        'success' => true,
        // إرجاع جميع بيانات المستخدم المخزنة في الجلسة (الاسم، القطاع، EmpID...)
        'user_data' => $_SESSION['user'], 
        
        // إرجاع الصلاحيات الأساسية لسهولة استخدامها في الواجهة الأمامية
        'permissions' => [
            'canAdd'           => canAdd(),
            'canEdit'          => canEdit(),
            'canDelete'        => isset($_SESSION['user']['CanDelete']) && (int)$_SESSION['user']['CanDelete'] === 1,
            'canSendWhatsApp'  => isset($_SESSION['user']['CanSendWhatsApp']) && (int)$_SESSION['user']['CanSendWhatsApp'] === 1,
            'isLicenseManager' => isset($_SESSION['user']['IsLicenseManager']) && (int)$_SESSION['user']['IsLicenseManager'] === 1,
            'isAdmin'          => isset($_SESSION['user']['IsAdmin']) && (int)$_SESSION['user']['IsAdmin'] === 1,
        ]
    ];
} else {
    // المستخدم غير مسجل الدخول
    $response = [
        'success' => false,
        'message' => 'User not authenticated.',
        'user_data' => null,
        'permissions' => []
    ];
}

// 6. طباعة الاستجابة
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>