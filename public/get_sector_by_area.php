<?php
require_once(__DIR__ . '/../api/db.php');
header('Content-Type: application/json; charset=utf-8');

$unit = $_POST['unit'] ?? '';
$area = $_POST['area'] ?? '';

if (empty($unit) || empty($area)) {
    echo json_encode(['success' => false, 'msg' => 'بيانات غير مكتملة']);
    exit;
}

$conn = connectDB();
$subSector = null;

// إذا كانت الوحدة "مجموعات التفتيش الميداني"، جلب القطاع من جدول tbl_areas
if ($unit === 'مجموعات التفتيش الميداني') {
    $stmt = $conn->prepare("SELECT sector_number FROM tbl_areas WHERE area_name_ar = ?");
    $stmt->bind_param('s', $area);
    $stmt->execute();
    $stmt->bind_result($subSector);
    $stmt->fetch();
    $stmt->close();
} else {
    // وحدات أخرى
    switch ($unit) {
        case "مراكز التسوق والمناطق السياحية": $subSector = 7; break;
        case "المنشات الصناعية": $subSector = 8; break;
        case "رخصة اعتماد": $subSector = 9; break;
        case "المقاصف المدرسية": $subSector = 10; break;
        case "المزارع": $subSector = 11; break;
        default: $subSector = null;
    }
}

if ($subSector !== null) {
    echo json_encode(['success' => true, 'Sub_Sector' => $subSector]);
} else {
    echo json_encode(['success' => false, 'msg' => 'تعذر تحديد رقم القطاع']);
}
?>
