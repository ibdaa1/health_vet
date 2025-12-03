<?php
// إيقاف عرض الأخطاء
ini_set('display_errors', 0);
error_reporting(E_ALL);

// الاتصال بقاعدة البيانات
require_once 'db.php';

// جلب رقم الحيوان
$animal_code = $_GET['animal_code'] ?? '';
if (!$animal_code) {
    http_response_code(400);
    die('Missing animal_code');
}

// التحقق من الرقم في DB
$stmt = $conn->prepare("SELECT id FROM tbl_animals WHERE animal_code = ? LIMIT 1");
$stmt->bind_param("s", $animal_code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    die('Animal code not found in database');
}

// توليد رابط QR API (الرقم فقط، حجم صغير 80x80 للطباعة الصغيرة)
$data = urlencode($animal_code);  // الرقم فقط
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?data=" . $data . "&size=80x80&color=000000&bgcolor=FFFFFF";

// إرجاع رابط QR كـ JSON
header('Content-Type: application/json');
echo json_encode(['qr_url' => $qr_url]);
?>