<?php
require_once(__DIR__ . '/../../api/db.php');
$conn->set_charset("utf8mb4");
session_start();

// Initialize response array
$response = ['success' => false, 'message' => ''];

// Check if user is logged in
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['EmpID'])) {
    $response['message'] = 'يجب تسجيل الدخول لتقديم طلب إجازة';
    echo json_encode($response);
    exit;
}

$currentEmpID = $_SESSION['user']['EmpID'];

// Check if the request is POST and required fields are present
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_type = isset($_POST['leave_type']) ? trim($_POST['leave_type']) : '';
    $start_date = isset($_POST['start_date']) ? trim($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? trim($_POST['end_date']) : '';
    $replacement_id = isset($_POST['replacement_id']) ? trim($_POST['replacement_id']) : null;
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

    // Validate required fields
    if (empty($leave_type)) {
        $response['message'] = 'نوع الإجازة مطلوب';
        echo json_encode($response);
        exit;
    }
    if (empty($start_date)) {
        $response['message'] = 'تاريخ البداية مطلوب';
        echo json_encode($response);
        exit;
    }
    if (empty($end_date)) {
        $response['message'] = 'تاريخ النهاية مطلوب';
        echo json_encode($response);
        exit;
    }

    // Validate date format and logic
    try {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        if ($end < $start) {
            $response['message'] = 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية';
            echo json_encode($response);
            exit;
        }
    } catch (Exception $e) {
        $response['message'] = 'تنسيق التاريخ غير صالح';
        echo json_encode($response);
        exit;
    }

    // Validate replacement_id (if provided)
    if (!empty($replacement_id)) {
        $stmt = $conn->prepare("SELECT EmpID FROM Users WHERE EmpID = ?");
        $stmt->bind_param("i", $replacement_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $response['message'] = 'الموظف البديل غير موجود';
            echo json_encode($response);
            $stmt->close();
            exit;
        }
        $stmt->close();
    }

    // Insert the leave request
    $stmt = $conn->prepare("
        INSERT INTO LeaveRequests (EmpID, LeaveType, StartDate, EndDate, ReplacementEmpID, Reason, Status, CreatedAt, UpdatedAt)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
    ");
    $stmt->bind_param("isssis", $currentEmpID, $leave_type, $start_date, $end_date, $replacement_id, $reason);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'تم تسجيل الإجازة بنجاح';
    } else {
        $response['message'] = 'فشل في تسجيل الإجازة: ' . $conn->error;
    }

    $stmt->close();
} else {
    $response['message'] = 'طلب غير صالح';
}

$conn->close();
echo json_encode($response);
?>