<?php
require_once(__DIR__ . '/../../api/db.php');
$conn->set_charset("utf8mb4");
session_start();

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['leave_id'])) {
    $leaveId = intval($_POST['leave_id']);

    $stmt = $conn->prepare("DELETE FROM LeaveRequests WHERE LeaveID = ?");
    $stmt->bind_param("i", $leaveId);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'تم حذف الإجازة بنجاح.';
    } else {
        $response['message'] = 'خطأ في حذف الإجازة: ' . $conn->error;
    }
    $stmt->close();
} else {
    $response['message'] = 'طلب غير صالح.';
}

$conn->close();
echo json_encode($response);
?>