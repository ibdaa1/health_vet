<?php
// health_vet/api/upload_adoption_signature.php - معالجة رفع وحفظ ملف التوقيع الخاص بطلبات التبني

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

// 1. التحقق من وجود الملف
if (empty($_FILES['signature'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No signature file uploaded.']);
    exit;
}

$file = $_FILES['signature'];

// 2. تحديد مسار الحفظ الفعلي والمسار العام
// المسار الفعلي (Target Directory) من موقع ملف API 
// health_vet/api/upload_adoption_signature.php -> health_vet/uploads/add_adoption_applications
$uploadDir = '../uploads/add_adoption_applications/'; 
// المسار العام (المسار الذي يتم حفظه في قاعدة البيانات)
$publicPath = '/health_vet/uploads/add_adoption_applications/'; 

// 3. التأكد من وجود المجلد
if (!is_dir($uploadDir)) {
    // حاول إنشاء المجلد مع إعطاء صلاحيات الكتابة (0777)
    if (!mkdir($uploadDir, 0777, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory. Check file permissions (should be 777).']);
        exit;
    }
}

// 4. إنشاء اسم ملف فريد
$fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
$fileName = uniqid('sig_') . '.' . $fileExtension;
$targetFilePath = $uploadDir . $fileName;
$databasePath = $publicPath . $fileName;

// 5. محاولة نقل الملف
if (move_uploaded_file($file['tmp_name'], $targetFilePath)) {
    echo json_encode([
        'success' => true, 
        'message' => 'Adoption signature uploaded successfully.', 
        'path' => $databasePath // هذا المسار يتم إرساله إلى API CRUD
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
}

exit;
?>