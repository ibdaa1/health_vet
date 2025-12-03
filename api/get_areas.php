<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

// استعلام جلب المناطق من جدول tbl_areas
$sql = "SELECT area_id, area_name_ar, area_name_en FROM tbl_areas ORDER BY area_name_ar ASC";
$result = $conn->query($sql);

$areas = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $areas[] = $row;
    }
}

echo json_encode($areas, JSON_UNESCAPED_UNICODE);
?>
