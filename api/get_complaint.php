<?php
// get_complaint.php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

session_start();

if (!isset($_SESSION['user']['EmpID'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'جلسة المستخدم غير صالحة']);
    exit;
}

$ComplaintID = isset($_GET['ComplaintID']) ? intval($_GET['ComplaintID']) : 0;

if ($ComplaintID <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'معرف الشكوى مطلوب']);
    exit;
}

try {
    $sql = "SELECT * FROM Complaints WHERE ComplaintID = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $ComplaintID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        ob_clean();
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'الشكوى غير موجودة']);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'خطأ في جلب البيانات: ' . $e->getMessage()]);
}
?>