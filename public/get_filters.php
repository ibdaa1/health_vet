<?php
// get_filters.php
declare(strict_types=1);

// إعدادات صرامة الإخراج
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function($sev, $msg, $file, $line) {
    throw new ErrorException($msg, 0, $sev, $file, $line);
});
header('Content-Type: application/json; charset=utf-8');
if (ob_get_level() === 0) { ob_start(); }

try {
    // الاتصال بقاعدة البيانات
require_once(__DIR__ . '/../api/db.php');
    if (!isset($conn) || !$conn) {
        throw new Exception("لم يتم العثور على اتصال قاعدة البيانات.");
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    // التحقق من تسجيل الدخول
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['user'])) {
        throw new Exception('غير مصرح لك بالوصول. الرجاء تسجيل الدخول.');
    }

    // نوع الفلتر المطلوب (قطاعات أو موظفين)
    $type = $_GET['type'] ?? '';
    $response = [];

    if ($type === 'sectors') {
        // جلب القطاعات
        $sql = "SELECT SectorName FROM tbl_Sectors WHERE Active = 1 ORDER BY SectorName";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        $sectors = [];
        while ($row = $result->fetch_assoc()) {
            $sectors[] = ['SectorName' => $row['SectorName']];
        }
        $response['sectors'] = $sectors;
    } elseif ($type === 'employees') {
        // جلب الموظفين بناءً على القطاع
        $sectorNames = isset($_GET['sector_name']) ? array_filter(array_map('trim', explode(',', $_GET['sector_name']))) : [];
        $sql = "SELECT EmpName, EmpID FROM Users WHERE Active = 1";
        $params = [];
        $types = '';
        if (!empty($sectorNames)) {
            $placeholders = implode(',', array_fill(0, count($sectorNames), '?'));
            $sql .= " AND SectorID IN (SELECT SectorID FROM tbl_Sectors WHERE SectorName IN ($placeholders))";
            foreach ($sectorNames as $sn) {
                $params[] = $sn;
                $types .= 's';
            }
        }
        $sql .= " ORDER BY EmpName";
        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = ['EmpName' => $row['EmpName'], 'EmpID' => $row['EmpID']];
        }
        $response['employees'] = $employees;
    } else {
        throw new Exception('نوع الفلتر غير صالح.');
    }

    // إخراج JSON
    if (ob_get_length()) ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['error' => 'خطأ في الخادم: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>