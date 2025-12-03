<?php
// get_animal_details.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

// تضمين ملف اتصال قاعدة البيانات
// يجب أن يكون ملف db.php موجودًا ومتوفرًا
require_once 'db.php'; 

// التحقق من وجود animal_code في طلب GET
if (!isset($_GET['animal_code']) || empty($_GET['animal_code'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'رمز الحيوان (animal_code) مطلوب.']);
    exit;
}

$animal_code = $_GET['animal_code'];

try {
    // 1. الاستعلام الرئيسي لجلب تفاصيل الحيوان
    $sql = "SELECT a.* FROM tbl_animals a
            WHERE a.animal_code = ?
            LIMIT 1"; // لضمان جلب حيوان واحد فقط

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $animal_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404); // Not Found
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على حيوان بهذا الرمز.']);
        $stmt->close();
        $conn->close();
        exit;
    }

    $animal = $result->fetch_assoc();
    $animal_id = $animal['id'];
    $stmt->close();

    // 2. جلب الصور الخاصة بالحيوان
    $photo_stmt = $conn->prepare("SELECT photo_url FROM tbl_animal_photos WHERE animal_id = ?");
    $photo_stmt->bind_param("i", $animal_id);
    $photo_stmt->execute();
    $photo_result = $photo_stmt->get_result();
    
    $photos = [];
    while ($photo = $photo_result->fetch_assoc()) {
        $photos[] = $photo['photo_url'];
    }
    $animal['photos'] = $photos;
    $photo_stmt->close();

    // 3. تنظيف البيانات (مماثل لملف get_animals_cards.php)
    $animal['animal_name'] = $animal['animal_name'] ?? 'غير معروف';
    $animal['breed'] = $animal['breed'] ?? 'غير معروف';
    $animal['color'] = $animal['color'] ?? 'غير معروف';
    $animal['marking'] = $animal['marking'] ?? 'لا توجد';
    $animal['estimated_age'] = $animal['estimated_age'] ?? 'غير معروف';

    // 4. إرجاع البيانات
    echo json_encode([
        'success' => true,
        'data' => $animal
    ]);

} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    error_log('Error fetching animal details: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'خطأ في جلب البيانات: ' . $e->getMessage(),
        'error_details' => $e->getFile() . ':' . $e->getLine()
    ]);
} finally {
    // إغلاق الاتصال
    if (isset($conn)) {
        $conn->close();
    }
}
?>