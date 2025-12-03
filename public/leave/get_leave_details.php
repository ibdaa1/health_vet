<?php
require_once(__DIR__ . '/../../api/db.php');
$conn->set_charset("utf8mb4");
session_start();

header('Content-Type: application/json');
$response = ['success' => false, 'message' => '', 'data' => null];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['leave_id'])) {
    $leaveId = intval($_GET['leave_id']);

    $stmt = $conn->prepare("SELECT * FROM LeaveRequests WHERE LeaveID = ?");
    $stmt->bind_param("i", $leaveId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $leaveData = $result->fetch_assoc();
        $response['success'] = true;
        $response['data'] = $leaveData;
    } else {
        $response['message'] = 'لم يتم العثور على الإجازة.';
    }
    $stmt->close();
} else {
    $response['message'] = 'طلب غير صالح.';
}

$conn->close();
echo json_encode($response);
?>