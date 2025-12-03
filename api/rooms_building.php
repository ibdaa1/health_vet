<?php
// /health_vet/api/rooms_building.php

// 1. إظهار الأخطاء لتشخيص مشكلة HTTP 500
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Dubai');

require_once 'db.php';

// 2. فحص اتصال قاعدة البيانات
if ($conn->connect_error) {
    // هذا سيظهر للمستخدم بدلاً من خطأ 500
    echo json_encode(['success'=>false, 'message'=>'فشل الاتصال بقاعدة البيانات: ' . $conn->connect_error]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action !== 'list') {
    echo json_encode(['success'=>false,'message'=>'الإجراء غير محدد']);
    exit;
}

// فلتر النوع (All, Cats, Dogs)
$typeFilter = $_GET['type'] ?? 'All';
$typeFilter = $conn->real_escape_string($typeFilter);

// 3. جلب الغرف - مع تضمين حقل capacity
if ($typeFilter === 'All') {
    $roomSql = "SELECT id, room_name, room_type, capacity FROM tbl_rooms ORDER BY id";
} else {
    $roomSql = "SELECT id, room_name, room_type, capacity FROM tbl_rooms WHERE room_type = '$typeFilter' ORDER BY id";
}

$roomRes = $conn->query($roomSql);
if ($roomRes === false) {
    // 4. فحص أخطاء استعلام الغرف وإرجاعها
    echo json_encode(['success'=>false,'message'=>'خطأ في استعلام الغرف: '.$conn->error . ' (SQL: ' . $roomSql . ')']);
    exit;
}

$rooms = [];

while ($room = $roomRes->fetch_assoc()) {
    $roomId = (int)$room['id'];

    // 5. الاستعلام المعدل لجلب الحيوانات الموجودة حاليًا في الغرفة (باستخدام MAX(id) لتحديد آخر حركة)
    $animalSql = "
        SELECT a.animal_code, a.animal_name, a.animal_type
        FROM tbl_animals a
        JOIN tbl_animal_movements m ON m.animal_code = a.animal_code
        WHERE m.id IN (
            SELECT MAX(id)
            FROM tbl_animal_movements
            GROUP BY animal_code
        )
        AND m.room_id = $roomId
        AND a.is_active = 'Active'
        AND m.movement_type IN ('In', 'Transfer') -- نعتبر أن 'Out' تعني خروج الحيوان من المبنى/المنشأة
    ";
    
    // إذا كان نوع الغرفة محدداً، فلتر الحيوانات أيضًا
    if ($typeFilter !== 'All') {
        $animalSql .= " AND a.animal_type = '$typeFilter'";
    }

    $animalRes = $conn->query($animalSql);
    $animals = [];
    if ($animalRes === false) {
        // 6. فحص أخطاء استعلام الحيوانات وإرجاعها
        // لا نخرج من السكربت، بل نسجل الخطأ ونستمر في الغرف التالية
        error_log('خطأ في استعلام الحيوانات للغرفة ' . $roomId . ': ' . $conn->error . ' (SQL: ' . $animalSql . ')');
        // يمكن إرسال رسالة خطأ داخل البطاقة إذا أردت
    } else {
        while ($a = $animalRes->fetch_assoc()) {
            $animals[] = [
                'code' => $a['animal_code'],
                'name' => $a['animal_name'],
                'type' => $a['animal_type']
            ];
        }
    }

    $rooms[] = [
        'id' => $roomId,
        'name' => $room['room_name'],
        'type' => $room['room_type'],
        'capacity' => (int)$room['capacity'], // تمرير قيمة السعة
        'animals' => $animals
    ];
}

// إرجاع النتيجة
echo json_encode(['success'=>true,'data'=>$rooms], JSON_UNESCAPED_UNICODE);
exit;