<?php
require_once(__DIR__ . '/../../api/db.php');
$conn->set_charset("utf8mb4");
session_start();

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_id'])) {
    $leaveId = intval($_POST['leave_id']);
    $leaveType = $_POST['leave_type'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $replacementId = $_POST['replacement_id'];
    $reason = $_POST['reason'];

    // Correct the bind_param string to match the number of parameters
    $stmt = $conn->prepare("UPDATE LeaveRequests SET LeaveType = ?, StartDate = ?, EndDate = ?, ReplacementEmpID = ?, Reason = ? WHERE LeaveID = ?");
    $stmt->bind_param("sssssi", $leaveType, $startDate, $endDate, $replacementId, $reason, $leaveId);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'تم تحديث الإجازة بنجاح.';
    } else {
        $response['message'] = 'خطأ في تحديث الإجازة: ' . $conn->error;
    }
    $stmt->close();
} else {
    $response['message'] = 'طلب غير صالح.';
}

$conn->close();
echo json_encode($response);
?>