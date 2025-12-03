<?php
// تأكد أن الجلسة بدأت
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ✅ جزء خاص للتعامل مع طلبات AJAX لجلب الصلاحيات
// هذا الجزء مهم للسماح للملفات الأخرى (مثل JavaScript)
// بالتحقق من صلاحيات المستخدم دون الحاجة لإعادة تحميل الصفحة بالكامل.
if (isset($_GET['action']) && $_GET['action'] === 'get_permissions') {
    header('Content-Type: application/json; charset=utf-8');
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {  // إضافة: تحقق إضافي من أن user هو array
        // إرجاع الصلاحيات ككائن JSON
        echo json_encode([
            'success' => true,
            'permissions' => [
                'canAdd' => (bool) ($_SESSION['user']['CanAdd'] ?? 0),
                'canEdit' => (bool) ($_SESSION['user']['CanEdit'] ?? 0),
                'canDelete' => (bool) ($_SESSION['user']['CanDelete'] ?? 0),
                'canSendWhatsApp' => (bool) ($_SESSION['user']['CanSendWhatsApp'] ?? 0),
                'isAdmin' => (bool) ($_SESSION['user']['IsAdmin'] ?? 0),
                'isLicenseManager' => (bool) ($_SESSION['user']['IsLicenseManager'] ?? 0),
                'active' => (bool) ($_SESSION['user']['Active'] ?? 0),
                'leaveApproval' => ($_SESSION['user']['LeaveApproval'] ?? 'No') === 'Yes',
                'followUpComplaints' => (bool) ($_SESSION['user']['follow_up_complaints'] ?? 0),
                'complaintsManagerRights' => (bool) ($_SESSION['user']['complaints_manager_rights'] ?? 0),
                'clinicRights' => (bool) ($_SESSION['user']['clinic_rights'] ?? 0),
                'warehouseRights' => (bool) ($_SESSION['user']['warehouse_rights'] ?? 0),
                'superAdminRights' => (bool) ($_SESSION['user']['super_admin_rights'] ?? 0)
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);  // إضافة: JSON_PRETTY_PRINT لتسهيل التصحيح
    } else {
        // إذا لم يكن المستخدم مسجلاً للدخول عند طلب الصلاحيات، يتم إرجاع خطأ
        echo json_encode([
            'success' => false,
            'message' => 'المستخدم غير مسجل الدخول. يرجى تسجيل الدخول للوصول إلى الصلاحيات.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit; // هام جداً: يوقف تنفيذ السكريبت بعد إرسال استجابة AJAX
}

// بقية الكود للتحقق من تسجيل الدخول وإعادة التوجيه (كما هو)
// هذا الجزء يحمي الصفحات التي تتضمن ملف auth.php.
// إذا لم يكن المستخدم مسجلاً للدخول، يتم إعادة توجيهه إلى صفحة تسجيل الدخول.
if (!isset($_SESSION['user']) || !is_array($_SESSION['user']) || empty($_SESSION['user']['EmpName'])) {  // إضافة: تحقق إضافي من صحة user وEmpName (كما في index.php)
    // إعادة توجيه المتصفح إلى صفحة تسجيل الدخول
    header('Location: login.php');
    exit; // هام جداً: يوقف تنفيذ السكريبت بعد إعادة التوجيه
}

// يمكن تعريف دوال الصلاحيات هنا لسهولة الوصول إليها في جميع الصفحات التي تتضمن auth.php
// تم إضافة التحقق من function_exists() لمنع خطأ إعادة التعريف
$permission_functions = [  // إضافة: مصفوفة لتجنب التكرار في تعريف الدوال
    'canAdd' => ['CanAdd', 0],
    'canEdit' => ['CanEdit', 0],
    'canDelete' => ['CanDelete', 0],
    'canSendWhatsApp' => ['CanSendWhatsApp', 0],
    'isAdmin' => ['IsAdmin', 0],
    'isLicenseManager' => ['IsLicenseManager', 0],
    'isActive' => ['Active', 0],
    'canFollowUpComplaints' => ['follow_up_complaints', 0],
    'hasComplaintsManagerRights' => ['complaints_manager_rights', 0],
    'hasClinicRights' => ['clinic_rights', 0],
    'hasWarehouseRights' => ['warehouse_rights', 0],
    'hasSuperAdminRights' => ['super_admin_rights', 0],
    'hasLeaveApproval' => ['LeaveApproval', 'No', function($val) { return $val === 'Yes'; }]  // خاص لـ LeaveApproval
];

foreach ($permission_functions as $func_name => $config) {
    if (!function_exists($func_name)) {
        if (count($config) === 3 && is_callable($config[2])) {  // للحالات الخاصة مثل LeaveApproval
            ${$func_name} = function() use ($config) {
                $val = $_SESSION['user'][$config[0]] ?? $config[1];
                return $config[2]($val);
            };
        } else {
            ${$func_name} = function() use ($config) {
                return (bool) ($_SESSION['user'][$config[0]] ?? $config[1]);
            };
        }
    }
}

// ... يمكن إضافة المزيد من الدوال أو التعليمات البرمجية ذات الصلة بالمصادقة هنا
?>