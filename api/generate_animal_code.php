<?php
// اظهار جميع الأخطاء والتحذيرات
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
require_once 'db.php'; // الاتصال بقاعدة البيانات

// استقبال البيانات
$type = trim($_GET['type'] ?? '');
$source = trim($_GET['source'] ?? '');
$year = intval($_GET['year'] ?? date('Y'));

// التحقق من البيانات
if (!$type || !$source) {
    echo json_encode(['success' => false, 'message' => 'Type or source missing.']);
    exit;
}

// اختصارات للرمز
$short_type = strtoupper(substr($type, 0, 1)); // C أو D
$short_source = strtoupper(substr($source, 0, 1)); // S أو A

try {
    // الحصول على آخر رقم تسلسلي لهذا النوع والسنة
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_animals WHERE animal_type=? AND YEAR(registration_date)=?");
    $stmt->bind_param("si", $type, $year); // s=string, i=integer
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $next_num = ($row['count'] ?? 0) + 1;

    // صياغة الرقم التسلسلي 4 أرقام
    $next_num_formatted = str_pad($next_num, 4, '0', STR_PAD_LEFT);

    $animal_code = $short_type . '-' . $short_source . '-' . $year . '-' . $next_num_formatted;

    echo json_encode(['success' => true, 'animal_code' => $animal_code]);
    exit;

} catch (Exception $e) {
    error_log("Generate Animal Code Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
?>
