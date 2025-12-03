<?php
// /htdocs/health_vet/api/db.php

// تفاصيل الاتصال بقاعدة البيانات
// *يجب عليك تغيير هذه القيم*
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'your_db_username'); // اسم مستخدم قاعدة البيانات الخاص بك
define('DB_PASSWORD', 'your_db_password'); // كلمة مرور قاعدة البيانات الخاص بك
define('DB_NAME', 'your_database_name');   // اسم قاعدة البيانات الخاص بك

// إنشاء الاتصال
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// التحقق من الاتصال
if ($conn->connect_error) {
    // يمكنك تسجيل الخطأ بدلاً من عرضه مباشرة في بيئة الإنتاج لأسباب أمنية
    die("فشل الاتصال بقاعدة البيانات: " . $conn->connect_error);
}

// تعيين مجموعة الأحرف (character set) لدعم اللغة العربية
if (!$conn->set_charset("utf8mb4")) {
    // يمكنك التعامل مع خطأ تعيين مجموعة الأحرف هنا
}
?>