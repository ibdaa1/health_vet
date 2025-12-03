<?php
session_start();
require_once(__DIR__ . '/../../api/db.php');

$conn->set_charset("utf8mb4");

if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$currentEmpID = $_SESSION['user']['EmpID'] ?? null;
if (!isset($currentEmpID)) {
    header("Location: ../login.php");
    exit;
}

// جلب صلاحيات المستخدم
$isAdmin = $_SESSION['user']['IsAdmin'] == 1;

// بناء فلاتر البحث بنفس الطريقة في leave_requests.php
$where_clauses = ["1=1"];
$params = [];
$param_types = "";

if (!$isAdmin) {
    $where_clauses[] = "lr.EmpID = ?";
    $param_types .= "i";
    $params[] = $currentEmpID;
}

$filters = [
    'emp_id' => 'u.EmpID',
    'name' => 'u.EmpName',
    'sector' => 'u.SectorID',
    'department' => 'u.Department',
    'division' => 'u.Division',
    'leave_type' => 'lr.LeaveType',
    'status' => 'lr.Status'
];

foreach ($filters as $get_key => $db_column) {
    if (isset($_GET[$get_key]) && !empty($_GET[$get_key])) {
        if (is_array($_GET[$get_key])) {
            $placeholders = implode(',', array_fill(0, count($_GET[$get_key]), '?'));
            $where_clauses[] = "$db_column IN ($placeholders)";
            $param_types .= str_repeat('s', count($_GET[$get_key]));
            $params = array_merge($params, $_GET[$get_key]);
        } else {
            $where_clauses[] = "$db_column LIKE ?";
            $param_types .= "s";
            $params[] = "%" . $_GET[$get_key] . "%";
        }
    }
}

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if (!empty($start_date)) {
    $where_clauses[] = "lr.StartDate >= ?";
    $param_types .= "s";
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $where_clauses[] = "lr.EndDate <= ?";
    $param_types .= "s";
    $params[] = $end_date;
}
$where_sql = implode(" AND ", $where_clauses);

// استعلام البيانات الكاملة بدون حد (للتقرير)
$sql_leaves = "
SELECT 
    lr.LeaveID, lr.LeaveType, lr.StartDate, lr.EndDate, lr.Reason, lr.Status, lr.ApprovedBy,
    r.EmpName AS ReplacementName,
    u.EmpID, u.EmpName, u.JobTitle, u.Department, u.Division, u.SectorID,
    a.EmpName AS ApproverName
FROM LeaveRequests lr
LEFT JOIN Users r ON lr.ReplacementEmpID = r.EmpID
LEFT JOIN Users u ON lr.EmpID = u.EmpID
LEFT JOIN Users a ON lr.ApprovedBy = a.EmpID
WHERE " . $where_sql . "
ORDER BY lr.CreatedAt DESC
";

$stmt_leaves = $conn->prepare($sql_leaves);
if (!empty($params)) {
    $stmt_leaves->bind_param($param_types, ...$params);
}
$stmt_leaves->execute();
$leaves = $stmt_leaves->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_leaves->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير الإجازات - بلدية مدينة الشارقة</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background: #fff;
            color: #333;
            margin: 0;
            padding: 20px;
            font-size: 12px;
            line-height: 1.4;
        }
        .container {
            max-width: 100%;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #A3D9B5;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #3d9970;
            font-size: 18px;
        }
        .report-date {
            text-align: right;
            font-size: 12px;
            margin-top: 5px;
        }
        .filters {
            margin-bottom: 20px;
            padding: 10px;
            background: #f9f9f9;
            border: 1px solid #ddd;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
            font-size: 11px;
        }
        th {
            background-color: #eaf7ea;
            color: #3d9970;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .status-pending { color: orange; font-weight: bold; }
        .status-approved { color: green; font-weight: bold; }
        .status-rejected { color: red; font-weight: bold; }
        .no-data {
            text-align: center;
            padding: 20px;
            font-size: 14px;
            color: #666;
        }
        @media print {
            body { padding: 0; }
            .container { max-width: none; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            .filters { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>تقرير الإجازات - بلدية مدينة الشارقة</h1>
            <div class="report-date">تاريخ التقرير: <?= date('Y-m-d H:i:s') ?></div>
        </div>

        <?php if (!empty($_GET)): ?>
        <div class="filters">
            <strong>الفلاتر المطبقة:</strong><br>
            <?php foreach ($_GET as $key => $value): ?>
                <?php if (!empty($value) && $key != 'page'): ?>
                    <?= ucfirst($key) ?>: <?= htmlspecialchars($value) ?><br>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>اسم الموظف</th>
                    <th>الرقم الإداري</th>
                    <th>الوظيفة</th>
                    <th>القسم</th>
                    <th>الشعبة</th>
                    <th>القطاع</th>
                    <th>نوع الإجازة</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>الموظف البديل</th>
                    <th>الحالة</th>
                    <th>معتمد من</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leaves)): ?>
                    <tr>
                        <td colspan="12" class="no-data">لا توجد بيانات للعرض</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($leaves as $leave): ?>
                        <tr>
                            <td><?= htmlspecialchars($leave['EmpName'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($leave['EmpID'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($leave['JobTitle'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($leave['Department'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($leave['Division'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($leave['SectorID'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($leave['LeaveType'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($leave['StartDate'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($leave['EndDate'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($leave['ReplacementName'] ?? '-') ?></td>
                            <td><span class="status-<?= strtolower($leave['Status'] ?? 'pending') ?>"><?= htmlspecialchars($leave['Status'] ?? '-') ?></span></td>
                            <td><?= htmlspecialchars($leave['ApproverName'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>