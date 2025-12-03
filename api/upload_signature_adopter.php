<?php
// upload_signature.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

$action = $_POST['action'] ?? '';

if ($action === 'upload_signature') {
    $animal_id = $_POST['animal_id'] ?? 0;
    $signature_data = $_POST['signature'] ?? '';

    if (!$animal_id || empty($signature_data)) {
        echo json_encode(['success' => false, 'message' => 'بيانات مفقودة: ID الحيوان أو التوقيع.']);
        exit;
    }

    $upload_dir = __DIR__ . '/../uploads/owner_signature/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // استخراج البيانات من Data URL
    if (preg_match('/^data:image\/(png|jpeg|gif);base64,(.*)$/', $signature_data, $matches)) {
        $imageData = base64_decode($matches[2]);
        $extension = $matches[1] === 'png' ? 'png' : ($matches[1] === 'jpeg' ? 'jpg' : 'gif');
        $filename = $animal_id . '_signature_' . time() . '.' . $extension;
        $file_path = $upload_dir . $filename;
        $relative_path = 'uploads/adopter_signature/' . $filename;

        if (file_put_contents($file_path, $imageData)) {
            // تحديث قاعدة البيانات
            $stmt = $conn->prepare("UPDATE tbl_adoptions SET adopter_signature	 = ? WHERE 	adoption_id = ?");
            $stmt->bind_param("si", $relative_path, $animal_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'تم حفظ التوقيع.', 'signature_path' => $relative_path]);
            } else {
                unlink($file_path);
                echo json_encode(['success' => false, 'message' => 'فشل في تحديث قاعدة البيانات.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'فشل في حفظ الملف.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'تنسيق التوقيع غير صالح.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'إجراء غير معروف.']);
?>