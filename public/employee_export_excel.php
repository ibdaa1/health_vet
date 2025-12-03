<?php
// employee_export_excel.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user']) || $_SESSION['user']['IsAdmin'] != 1) {
    header("Location: login.php");
    exit;
}

require_once(__DIR__ . '/../api/db.php');
$conn->set_charset("utf8mb4");

// Get the data
$sql = "SELECT EmpID, EmpName, JobTitle, Department, Division, SectorID FROM Users ORDER BY EmpID ASC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    // Set headers to force download as an Excel file
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="employee_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen("php://output", "w");
    
    // Add BOM for UTF-8 support in Excel
    fputs($output, "\xEF\xBB\xBF");
    
    // Column headers
    fputcsv($output, ['رقم الموظف', 'اسم الموظف', 'الوظيفة', 'القسم', 'الشعبة', 'رقم القطاع']);
    
    // Data rows
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
} else {
    echo "لا توجد بيانات لتصديرها.";
}

$conn->close();
?>