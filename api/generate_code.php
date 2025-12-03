<?php
// /health_vet/api/generate_code.php - لتوليد واختبار رمز الحيوان الفريد بشكل مستقل

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php'; 

date_default_timezone_set('Asia/Dubai');
header('Content-Type: application/json; charset=utf-8');

// التأكد من وجود اتصال قاعدة البيانات
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
}

// **********************************************
// ********* دالة المساعدة لتوليد رمز الحيوان *******
// **********************************************
function generateAnimalCode($conn, $animal_type, $animal_source, $registration_date) {
    try {
        $type_char = strtoupper(substr($animal_type, 0, 1)); 
        $source_char = strtoupper(substr($animal_source, 0, 1));
        $year = date('Y', strtotime($registration_date));

        // 🚨 الجزء الأكثر عرضة للفشل: استعلام جلب أعلى ID
        $stmt_max_id = $conn->query("SELECT MAX(id) AS max_id FROM tbl_animals");
        $next_sequential_id = 1;

        if ($stmt_max_id) {
            $row = $stmt_max_id->fetch_assoc();
            // نضيف 1 لأعلى ID موجود
            $next_sequential_id = ($row['max_id'] ?? 0) + 1; 
        } else {
            // هذا يحدث إذا فشل الاستعلام (مثل عدم وجود الجدول)
            throw new Exception("SQL Error retrieving MAX(id): " . $conn->error);
        }
        
        $padded_id = str_pad($next_sequential_id, 4, '0', STR_PAD_LEFT);
        return "{$type_char}-{$source_char}-{$year}-{$padded_id}";

    } catch (Exception $e) {
        // نرفع الخطأ لكي يتم إرجاعه في الـ JSON
        throw new Exception("Code Generation Failed: " . $e->getMessage());
    }
}

// ⚠️ اختبار مباشر: يمكنك تغيير هذه القيم لاختبار حالات مختلفة
$test_type = $_GET['type'] ?? 'Cats';
$test_source = $_GET['source'] ?? 'Stray';
$test_date = $_GET['date'] ?? date('Y-m-d H:i:s');

try {
    $generated_code = generateAnimalCode($conn, $test_type, $test_source, $test_date);
    echo json_encode(['success' => true, 'code' => $generated_code, 'test_id' => ($generated_code === 'C-S-'.date('Y').'-0001' ? 1 : null)], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

exit;
?>