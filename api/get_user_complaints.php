<?php
// htdocs/health_vet/api/get_user_complaints.php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();
header('Content-Type: application/json; charset=utf-8');

// تحميل ملف اتصال قاعدة البيانات
require_once 'db.php';

session_start();

// التحقق من صحة الجلسة
if (!isset($_SESSION['user']['EmpID'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'جلسة المستخدم غير صالحة']);
    exit;
}

$EmpID = (int)$_SESSION['user']['EmpID'];
$isAdmin = isset($_SESSION['user']['IsAdmin']) && (int)$_SESSION['user']['IsAdmin'] === 1;

try {
    // التحقق من اتصال قاعدة البيانات
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception('اتصال قاعدة البيانات غير متوفر');
    }

    // جلب بيانات المستخدم من جدول Users
    $userSql = "SELECT EmpName FROM Users WHERE EmpID = ?";
    $userStmt = $conn->prepare($userSql);
    if ($userStmt === false) {
        throw new Exception('Prepare error for user: ' . $conn->error);
    }
    $userStmt->bind_param('i', $EmpID);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $userInfo = $userResult->fetch_assoc() ?: ['EmpName' => 'غير معروف'];
    $userInfo['EmpID'] = $EmpID;
    $userInfo['IsAdmin'] = $isAdmin;
    $userStmt->close();

    // بناء الاستعلام بناءً على صلاحية المستخدم
    if ($isAdmin) {
        $sql = "SELECT * FROM Complaints ORDER BY ComplaintDate DESC";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception('Prepare error: ' . $conn->error);
        }
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $sql = "SELECT * FROM Complaints WHERE ReceivedByEmpID = ? ORDER BY ComplaintDate DESC";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception('Prepare error: ' . $conn->error);
        }
        $stmt->bind_param('i', $EmpID);
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $result = $stmt->get_result();
    }

    $complaints = [];
    while ($row = $result->fetch_assoc()) {
        // معالجة القيم الفارغة لتجنب مشاكل JSON
        array_walk_recursive($row, function(&$value) {
            if ($value === null) $value = '';
        });
        $complaints[] = $row;
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'data' => $complaints,
        'count' => count($complaints),
        'userInfo' => $userInfo
    ]);

    $stmt->close();

} catch (Exception $e) {
    ob_clean();
    error_log("Exception in get_user_complaints: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ في النظام: ' . $e->getMessage()
    ]);
}

$conn->close();
?>