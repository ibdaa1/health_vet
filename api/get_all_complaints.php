<?php
// htdocs/health_vet/api/get_all_complaints.php
// مخصص للمدراء - يجلب جميع الشكاوى والإحصائيات المفصلة

// تفعيل تسجيل الأخطاء وعدم عرضها للمستخدم لضمان استجابة JSON سليمة
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1); // تأكد من أن سجل الأخطاء يعمل على الخادم لديك

header('Content-Type: application/json; charset=utf-8');

// تضمين ملف قاعدة البيانات (المفترض أنه يحدد $conn)
// تأكد من وجود هذا الملف وإعداد الاتصال بشكل صحيح
require_once 'db.php'; 

session_start();

// دالة مساعدة لإنهاء الطلب مع رسالة خطأ JSON
function sendError($message, $logError = true) {
    if ($logError) {
        error_log("Error in get_all_complaints.php: " . $message);
    }
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// -----------------------------------------------------------------
// 1. التحقق من الاتصال وقاعدة البيانات
// -----------------------------------------------------------------

if (!isset($conn) || $conn->connect_error) {
    sendError('خطأ فادح في الاتصال بقاعدة البيانات. ' . ($conn->connect_error ?? 'لم يتم تحديد $conn.'), false);
}

// -----------------------------------------------------------------
// 2. التحقق من صحة الجلسة وصلاحية المدير
// -----------------------------------------------------------------

if (!isset($_SESSION['user']['EmpID']) || (int)($_SESSION['user']['IsAdmin'] ?? 0) !== 1) {
    sendError('غير مصرح لك بالوصول، يجب أن تكون مديراً. (خطأ في الجلسة)', false);
}

$EmpID = (int)$_SESSION['user']['EmpID'];

try {
    // -----------------------------------------------------------------
    // 3. جلب بيانات المستخدم (المدير)
    // -----------------------------------------------------------------
    $userSql = "SELECT EmpName, JobTitle, Department FROM Users WHERE EmpID = ?";
    $userStmt = $conn->prepare($userSql);
    if ($userStmt === false) {
        sendError('Prepare error for user info: ' . $conn->error);
    }
    $userStmt->bind_param('i', $EmpID);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $userInfo = $userResult->fetch_assoc() ?: ['EmpName' => 'غير معروف', 'JobTitle' => '', 'Department' => ''];
    $userInfo['EmpID'] = $EmpID;
    $userStmt->close();

    // -----------------------------------------------------------------
    // 4. بناء الاستعلام وتطبيق الفلترة
    // -----------------------------------------------------------------
    
    $where_conditions = [];
    $params = [];
    $types = '';

    // عوامل الفلترة من الـ GET
    $dateFrom = $_GET['from'] ?? null;
    $dateTo = $_GET['to'] ?? null;
    $filterType = $_GET['type'] ?? null;
    $filterPriority = $_GET['priority'] ?? null;
    $filterSource = $_GET['source'] ?? null;
    $filterArea = $_GET['area'] ?? null;
    
    // فلترة التاريخ
    // نستخدم C.ReceivedDate هنا لأن الاستعلام الرئيسي يستخدم الاسم المستعار C
    if (!empty($dateFrom)) {
        $where_conditions[] = "C.ReceivedDate >= ?";
        $params[] = $dateFrom . ' 00:00:00';
        $types .= 's';
    }
    if (!empty($dateTo)) {
        $where_conditions[] = "C.ReceivedDate <= ?";
        $params[] = $dateTo . ' 23:59:59';
        $types .= 's';
    }

    // ... (بقية فلاتر البحث) ...
    // نستخدم أسماء الأعمدة المجردة هنا لأنها لا تحتاج لاسم مستعار في استعلامات الإحصائيات بعد التعديل
    if (!empty($filterType)) {
        $where_conditions[] = "ComplaintType = ?";
        $params[] = $filterType;
        $types .= 's';
    }
    if (!empty($filterPriority)) {
        $where_conditions[] = "ResponsePriority = ?";
        $params[] = $filterPriority;
        $types .= 's';
    }
    if (!empty($filterSource)) {
        $where_conditions[] = "Source = ?";
        $params[] = $filterSource;
        $types .= 's';
    }
    if (!empty($filterArea)) {
        // إذا كان هناك اسم مستعار C، يجب استخدامه هنا أيضًا
        $where_conditions[] = "C.Area LIKE ?"; 
        $params[] = "%" . $filterArea . "%";
        $types .= 's';
    }


    $where_sql = count($where_conditions) > 0 ? " WHERE " . implode(' AND ', $where_conditions) : "";
    
    // الاستعلام الرئيسي لجلب بيانات الشكاوى
    $sql_complaints = "SELECT 
                        C.*, 
                        U.EmpName AS ReceivedByEmpName,
                        -- KPI 1: تأخير التسجيل (الاستلام - الشكوى)
                        DATEDIFF(C.ReceivedDate, C.ComplaintDate) AS RegistrationDelayDays,
                        -- KPI 2: تأخير المتابعة (المتابعة - الاستلام)
                        DATEDIFF(C.FollowUpDate, C.ReceivedDate) AS FollowUpDelayDays,
                        -- KPI 3: أيام المتابعة/الاستجابة (الإغلاق/اليوم - المتابعة)
                        DATEDIFF(COALESCE(C.CloseDate, NOW()), C.FollowUpDate) AS ResponseDays
                       FROM Complaints C
                       LEFT JOIN Users U ON C.ReceivedByEmpID = U.EmpID
                       {$where_sql} 
                       ORDER BY C.ComplaintID DESC";

    $stmt_complaints = $conn->prepare($sql_complaints);
    if ($stmt_complaints === false) {
        sendError('Prepare error for complaints: ' . $conn->error);
    }
    
    // ربط البارامترات فقط إذا كانت هناك فلاتر مطبقة
    if (!empty($types)) {
        // استخدم call_user_func_array للربط الديناميكي
        $bind_params = array_merge([$types], $params);
        $ref_params = [];
        foreach($bind_params as $key => $value) {
            $ref_params[$key] = &$bind_params[$key];
        }
        call_user_func_array([$stmt_complaints, 'bind_param'], $ref_params);
    }

    if (!$stmt_complaints->execute()) {
        sendError('Execute error for complaints: ' . $stmt_complaints->error);
    }
    $result_complaints = $stmt_complaints->get_result();
    
    $complaints = [];
    while ($row = $result_complaints->fetch_assoc()) {
        // تأكد من أن جميع الحقول التي قيمتها NULL يتم تحويلها إلى سلسلة فارغة
        array_walk_recursive($row, function(&$value) {
            if ($value === null) $value = '';
        });
        $complaints[] = $row;
    }
    $stmt_complaints->close();

    // -----------------------------------------------------------------
    // 5. الإحصائيات الخاصة بالموظفين (KPIs) (لا تحتاج لفلترة)
    // -----------------------------------------------------------------
    $sql_employee_stats = "SELECT
                                C.ReceivedByEmpID AS EmpID, 
                                U.EmpName,
                                COUNT(C.ComplaintID) AS TotalComplaints,
                                SUM(CASE WHEN C.ComplaintStatus = 'Open' THEN 1 ELSE 0 END) AS OpenComplaints,
                                SUM(CASE WHEN C.FinalStatus = 'Pending Close' THEN 1 ELSE 0 END) AS PendingCloseCount
                           FROM Complaints C
                           INNER JOIN Users U ON C.ReceivedByEmpID = U.EmpID
                           GROUP BY C.ReceivedByEmpID
                           ORDER BY TotalComplaints DESC";
    $result_emp_stats = $conn->query($sql_employee_stats);
    $employee_stats = [];
    while ($row = $result_emp_stats->fetch_assoc()) {
        $employee_stats[] = $row;
    }

    // -----------------------------------------------------------------
    // 6. الإحصائيات الخاصة بالرسوم البيانية (Charts) (تستخدم الفلترة)
    // *** تم التعديل هنا بإضافة "C" كاسم مستعار لجدول "Complaints" ***
    // -----------------------------------------------------------------
    
    $stats_queries = [
        'ComplaintType' => "SELECT ComplaintType, COUNT(*) as count FROM Complaints C {$where_sql} GROUP BY ComplaintType",
        'ResponsePriority' => "SELECT ResponsePriority, COUNT(*) as count FROM Complaints C {$where_sql} GROUP BY ResponsePriority",
        'Source' => "SELECT Source, COUNT(*) as count FROM Complaints C {$where_sql} GROUP BY Source",
        'FinalStatus' => "SELECT FinalStatus, COUNT(*) as count FROM Complaints C {$where_sql} GROUP BY FinalStatus",
        'Area' => "SELECT Area, COUNT(*) as count FROM Complaints C {$where_sql} GROUP BY Area"
    ];

    $chart_stats = [];
    // يجب أن تبدأ المصفوفة بالـ $types ثم الـ $params لعملية الربط الديناميكي
    $types_and_params = !empty($types) ? array_merge([$types], $params) : []; 
    
    foreach ($stats_queries as $key => $sql) {
        $stmt_stats = $conn->prepare($sql);
        if ($stmt_stats === false) {
             error_log("Prepare error for chart stat ($key): " . $conn->error);
             continue;
        }

        if (!empty($types)) {
            // استخدام نفس البارامترات للـ Where clause
            $ref_params = [];
            foreach($types_and_params as $k => $value) {
                $ref_params[$k] = &$types_and_params[$k];
            }
            // يجب أن تكون الدالة قادرة على التعامل مع مصفوفة ديناميكية من المراجع
            // call_user_func_array([$stmt_stats, 'bind_param'], $ref_params);
            
            // بديل أكثر وضوحًا باستخدام splat operator في PHP 5.6+ أو استخدام call_user_func_array
            if (count($ref_params) > 0) {
                 call_user_func_array([$stmt_stats, 'bind_param'], $ref_params);
            }
        }
        
        $stmt_stats->execute();
        $result_stats = $stmt_stats->get_result();
        
        $chart_stats[$key] = [];
        while ($row = $result_stats->fetch_assoc()) {
            $chart_stats[$key][] = $row;
        }
        $stmt_stats->close();
    }
    
    $total_pending_close = array_sum(array_column($employee_stats, 'PendingCloseCount'));
    
    // -----------------------------------------------------------------
    // 7. إرسال الاستجابة الناجحة
    // -----------------------------------------------------------------
    echo json_encode([
        'success' => true,
        'data' => $complaints,
        'userInfo' => $userInfo,
        'employeeStats' => $employee_stats,
        'chartStats' => $chart_stats,
        'totalPendingClose' => $total_pending_close
    ]);

} catch (Exception $e) {
    sendError('حدث خطأ غير متوقع في النظام: ' . $e->getMessage());
}

$conn->close();
?>