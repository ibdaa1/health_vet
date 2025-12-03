<?php
require_once(__DIR__ . '/../../api/db.php');
$conn->set_charset("utf8mb4");
session_start();

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user'])) {
    $response['message'] = 'غير مسجل الدخول';
    echo json_encode($response);
    exit;
}

$currentEmpID = $_SESSION['user']['EmpID'];
$isAdmin = $_SESSION['user']['IsAdmin'] == 1;
$stmt_check_approval = $conn->prepare("SELECT LeaveApproval FROM Users WHERE EmpID = ?");
$stmt_check_approval->bind_param("i", $currentEmpID);
$stmt_check_approval->execute();
$approval_result = $stmt_check_approval->get_result();
$user_approval_data = $approval_result->fetch_assoc();
$can_approve_leaves = ($user_approval_data['LeaveApproval'] === 'Yes');
$stmt_check_approval->close();

if (!$isAdmin && !$can_approve_leaves) {
    $response['message'] = 'ليس لديك صلاحية الاعتماد';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_id']) && isset($_POST['approved_by'])) {
    $leave_id = (int)$_POST['leave_id'];
    $approved_by = (int)$_POST['approved_by'];

    // Verify the leave request exists and is pending
    $stmt = $conn->prepare("SELECT Status FROM LeaveRequests WHERE LeaveID = ? AND Status = 'pending'");
    $stmt->bind_param("i", $leave_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['message'] = 'الإجازة غير موجودة أو ليست معلقة';
        echo json_encode($response);
        $stmt->close();
        $conn->close();
        exit;
    }
    $stmt->close();

    // Update the leave request
    $stmt = $conn->prepare("UPDATE LeaveRequests SET Status = 'approved', ApprovedBy = ?, UpdatedAt = NOW() WHERE LeaveID = ?");
    $stmt->bind_param("ii", $approved_by, $leave_id);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'تم اعتماد الإجازة بنجاح';
    } else {
        $response['message'] = 'فشل في اعتماد الإجازة';
    }
    
    $stmt->close();
} else {
    $response['message'] = 'طلب غير صالح';
}

$conn->close();
echo json_encode($response);
?>