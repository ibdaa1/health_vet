<?php
// get_animals.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

$action = $_GET['action'] ?? '';

if ($action === 'get_public') {
    try {
        $stmt = $conn->prepare("
            SELECT 
                id, animal_name, animal_code, animal_type, animal_source, is_active, registration_date,
                gender, breed, color, marking, estimated_age, notes,
                owner_name, owner_phone, owner_email
            FROM tbl_animals 
            WHERE is_active = 'Active' 
            ORDER BY animal_type, registration_date DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $animals = [];
        while ($row = $result->fetch_assoc()) {
            // جلب كل الصور المرتبطة بالحيوان
            $photo_stmt = $conn->prepare("SELECT photo_url FROM tbl_animal_photos WHERE animal_id = ? ORDER BY id ASC");
            $photo_stmt->bind_param("i", $row['id']);
            $photo_stmt->execute();
            $photo_result = $photo_stmt->get_result();
            $photos = [];
            while ($photo = $photo_result->fetch_assoc()) {
                $photos[] = $photo['photo_url'];
            }
            $row['photos'] = $photos;  // كل الصور الآن
            $animals[] = $row;
        }
        echo json_encode(['success' => true, 'animals' => $animals]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'خطأ في جلب البيانات: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'إجراء غير معروف.']);
}
?>