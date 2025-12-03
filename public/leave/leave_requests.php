<?php
require_once(__DIR__ . '/../../api/db.php');
$conn->set_charset("utf8mb4");
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$currentEmpID = $_SESSION['user']['EmpID'];

// جلب صلاحيات المستخدم من الجلسة
$isAdmin = $_SESSION['user']['IsAdmin'] == 1;
$canEdit = $_SESSION['user']['CanEdit'] == 1;
$canDelete = $_SESSION['user']['CanDelete'] == 1;

// صلاحية الاعتماد
$stmt_check_approval = $conn->prepare("SELECT LeaveApproval FROM Users WHERE EmpID = ?");
$stmt_check_approval->bind_param("i", $currentEmpID);
$stmt_check_approval->execute();
$approval_result = $stmt_check_approval->get_result();
$user_approval_data = $approval_result->fetch_assoc();
$user_approval_permission = $user_approval_data['LeaveApproval'] ?? 'No';
$can_approve_leaves = ($user_approval_permission === 'Yes');
$stmt_check_approval->close();

$currentUserName = $_SESSION['user']['EmpName'] ?? "الموظف";
$stmt_emp = $conn->prepare("SELECT Division, EmpName, JobTitle FROM Users WHERE EmpID = ?");
$stmt_emp->bind_param("i", $currentEmpID);
$stmt_emp->execute();
$currentEmp = $stmt_emp->get_result()->fetch_assoc();
$stmt_emp->close();
$division = $currentEmp['Division'] ?? null;
$empName = $currentEmp['EmpName'] ?? "موظف";

$stmt_replace = $conn->prepare("SELECT EmpID, EmpName FROM Users WHERE Division = ? AND EmpID != ?");
$stmt_replace->bind_param("si", $division, $currentEmpID);
$stmt_replace->execute();
$replacement_employees = $stmt_replace->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_replace->close();

$unique_values = [
    'divisions' => $conn->query("SELECT DISTINCT Division FROM Users WHERE Division IS NOT NULL ORDER BY Division")->fetch_all(MYSQLI_ASSOC),
    'departments' => $conn->query("SELECT DISTINCT Department FROM Users WHERE Department IS NOT NULL ORDER BY Department")->fetch_all(MYSQLI_ASSOC),
    'sectors' => $conn->query("SELECT DISTINCT SectorID FROM Users WHERE SectorID IS NOT NULL ORDER BY SectorID")->fetch_all(MYSQLI_ASSOC),
    'leave_types' => $conn->query("SELECT DISTINCT LeaveType FROM LeaveRequests WHERE LeaveType IS NOT NULL ORDER BY LeaveType")->fetch_all(MYSQLI_ASSOC),
    'statuses' => $conn->query("SELECT DISTINCT Status FROM LeaveRequests WHERE Status IS NOT NULL ORDER BY Status")->fetch_all(MYSQLI_ASSOC)
];

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
$today = date('Y-m-d');

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

$sql_counts_management = [
    'pending' => "SELECT COUNT(*) FROM LeaveRequests lr LEFT JOIN Users u ON lr.EmpID = u.EmpID WHERE " . $where_sql . " AND lr.Status = 'Pending'",
    'approved' => "SELECT COUNT(*) FROM LeaveRequests lr LEFT JOIN Users u ON lr.EmpID = u.EmpID WHERE " . $where_sql . " AND lr.Status = 'Approved'",
    'rejected' => "SELECT COUNT(*) FROM LeaveRequests lr LEFT JOIN Users u ON lr.EmpID = u.EmpID WHERE " . $where_sql . " AND lr.Status = 'Rejected'",
];
$sql_counts_time = [
    'not_started' => "SELECT COUNT(*) FROM LeaveRequests lr LEFT JOIN Users u ON lr.EmpID = u.EmpID WHERE " . $where_sql . " AND lr.Status = 'Approved' AND lr.StartDate > '{$today}'",
    'ongoing' => "SELECT COUNT(*) FROM LeaveRequests lr LEFT JOIN Users u ON lr.EmpID = u.EmpID WHERE " . $where_sql . " AND lr.Status = 'Approved' AND lr.StartDate <= '{$today}' AND lr.EndDate >= '{$today}'",
    'ended' => "SELECT COUNT(*) FROM LeaveRequests lr LEFT JOIN Users u ON lr.EmpID = u.EmpID WHERE " . $where_sql . " AND lr.Status = 'Approved' AND lr.EndDate < '{$today}'"
];

$counts = ['total' => 0];
$stmt_total = $conn->prepare("SELECT COUNT(*) FROM LeaveRequests lr LEFT JOIN Users u ON lr.EmpID = u.EmpID WHERE " . $where_sql);
if (!empty($params)) { $stmt_total->bind_param($param_types, ...$params); }
$stmt_total->execute();
$counts['total'] = $stmt_total->get_result()->fetch_row()[0];
$stmt_total->close();

foreach ($sql_counts_management as $key => $sql) {
    $stmt = $conn->prepare($sql);
    if (!empty($params)) { $stmt->bind_param($param_types, ...$params); }
    $stmt->execute();
    $counts[$key] = $stmt->get_result()->fetch_row()[0];
    $stmt->close();
}

foreach ($sql_counts_time as $key => $sql) {
    $stmt = $conn->prepare($sql);
    if (!empty($params)) { $stmt->bind_param($param_types, ...$params); }
    $stmt->execute();
    $counts[$key] = $stmt->get_result()->fetch_row()[0];
    $stmt->close();
}

$results_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start_from = ($page - 1) * $results_per_page;
$total_pages = ceil($counts['total'] / $results_per_page);

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
LIMIT ? OFFSET ?";
$param_types .= "ii";
$params[] = $results_per_page;
$params[] = $start_from;

$stmt_leaves = $conn->prepare($sql_leaves);
if (!empty($params)) {
    $stmt_leaves->bind_param($param_types, ...$params);
}
$stmt_leaves->execute();
$leaves = $stmt_leaves->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_leaves->close();
$conn->close();

$filter_query = http_build_query($_GET);
$pdf_link = "leave_export_pdf.php?" . $filter_query;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نموذج الإجازات</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background: #f9f9f9;
            padding: clamp(10px, 2vw, 15px);
            color: #333;
            font-size: clamp(12px, 1.5vw, 14px);
        }
        .container {
            max-width: 95%;
            margin: auto;
            padding: clamp(10px, 2vw, 15px);
            background: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #A3D9B5;
            padding-bottom: clamp(8px, 1.5vw, 10px);
            margin-bottom: clamp(10px, 2vw, 15px);
        }
        .header h3 {
            margin: 0;
            color: #3d9970;
            font-size: clamp(16px, 2vw, 18px);
        }
        .header-left, .header-right {
            display: flex;
            align-items: center;
        }
        .header-center {
            text-align: center;
            flex-grow: 1;
        }
        .logo {
            width: clamp(80px, 10vw, 100px);
            height: auto;
        }
        .back-btn {
            background-color: #f4f4f4;
            color: #555;
            border: 1px solid #ccc;
            padding: clamp(6px, 1vw, 8px) clamp(12px, 2vw, 16px);
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: clamp(12px, 1.5vw, 14px);
        }
        .back-btn:hover {
            background-color: #e0e0e0;
        }
        .form-section {
            background: #f4f4f4;
            padding: clamp(10px, 2vw, 15px);
            border-radius: 8px;
            margin-bottom: clamp(15px, 3vw, 20px);
            border: 1px solid #ddd;
            max-width: 600px;
            width: 100%;
            box-sizing: border-box;
        }
        .form-section h2 {
            font-size: clamp(14px, 1.8vw, 16px);
            margin-bottom: clamp(8px, 1.5vw, 10px);
        }
        label {
            display: block;
            margin-top: clamp(6px, 1vw, 8px);
            font-weight: bold;
            font-size: clamp(12px, 1.5vw, 14px);
        }
        input[type="text"], input[type="date"], select, textarea {
            width: 100%;
            padding: clamp(6px, 1vw, 8px);
            margin-top: clamp(3px, 0.5vw, 4px);
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: clamp(12px, 1.5vw, 14px);
        }
        button {
            padding: clamp(8px, 1.5vw, 10px) clamp(15px, 2.5vw, 20px);
            margin-top: clamp(10px, 1.5vw, 15px);
            background: #4CAF50;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(12px, 1.5vw, 14px);
        }
        button:hover {
            background: #45a049;
        }
        .filter-container {
            margin-bottom: clamp(10px, 2vw, 15px);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: clamp(10px, 1.5vw, 15px);
            padding: clamp(10px, 1.5vw, 15px);
            background: #f0f8f0;
            border-radius: 8px;
        }
        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: clamp(8px, 1vw, 10px);
        }
        .filter-actions button, .filter-actions a {
            padding: 5px;
            font-size: clamp(10px, 1.2vw, 12px);
            width: clamp(30px, 4vw, 40px);
            height: clamp(30px, 4vw, 40px);
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            border: 1px solid #ccc;
        }
        .filter-actions a.back-btn {
            background-color: #f4f4f4;
            color: #555;
            text-decoration: none;
        }
        .filter-actions a.back-btn:hover {
            background-color: #e0e0e0;
        }
        .filter-actions button[name="filter"] {
            background-color: #4CAF50;
            color: white;
        }
        .filter-actions button[name="filter"]:hover {
            background-color: #45a049;
        }
        .filter-actions a[href*="leave_export_pdf.php"] {
            background-color: #dc3545;
            color: white;
        }
        .filter-actions a[href*="leave_export_pdf.php"]:hover {
            background-color: #c82333;
        }
        .filter-actions i {
            font-size: clamp(14px, 1.8vw, 16px);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            box-shadow: 0 0 5px rgba(0,0,0,0.05);
            font-size: clamp(10px, 1.2vw, 12px);
        }
        th, td {
            border: 1px solid #ddd;
            padding: clamp(8px, 1vw, 10px);
            text-align: center;
        }
        th {
            background-color: #eaf7ea;
            color: #3d9970;
            font-size: clamp(11px, 1.3vw, 13px);
        }
        tr:hover {
            background-color: #f1f1f1;
        }
        .status-pending { color: orange; font-weight: bold; }
        .status-approved { color: green; font-weight: bold; }
        .status-rejected { color: red; font-weight: bold; }
        .time-status {
            margin-top: 5px;
            font-size: clamp(9px, 1.1vw, 11px);
            font-weight: bold;
        }
        .time-status.not-started { color: #f0ad4e; }
        .time-status.ongoing { color: #5cb85c; }
        .time-status.ended { color: #d9534f; }
        .status-counts {
            display: flex;
            justify-content: space-around;
            margin-bottom: clamp(10px, 2vw, 15px);
            padding: clamp(10px, 1.5vw, 15px);
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .status-counts div {
            text-align: center;
            font-size: clamp(14px, 1.8vw, 16px);
        }
        .status-counts .pending-count { color: orange; }
        .status-counts .approved-count { color: green; }
        .status-counts .rejected-count { color: red; }
        .status-counts .total { color: #337ab7; }
        .status-counts .not-started { color: #f0ad4e; }
        .status-counts .ongoing { color: #5cb85c; }
        .status-counts .ended { color: #d9534f; }
        .pagination {
            margin-top: clamp(10px, 2vw, 15px);
            text-align: center;
        }
        .pagination a, .pagination span {
            padding: clamp(6px, 1vw, 8px) clamp(10px, 1.5vw, 12px);
            border: 1px solid #ccc;
            text-decoration: none;
            color: #333;
            margin: 0 clamp(3px, 0.5vw, 5px);
            border-radius: 4px;
            font-size: clamp(12px, 1.5vw, 14px);
        }
        .pagination a:hover {
            background-color: #f0f0f0;
        }
        .pagination .active {
            background-color: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        .action-buttons button {
            margin: 0 2px;
            padding: clamp(4px, 0.8vw, 5px) clamp(6px, 1vw, 8px);
            font-size: clamp(9px, 1.1vw, 10px);
            border-radius: 3px;
            border: none;
        }
        .edit-btn { background-color: #ffc107; color: white; }
        .delete-btn { background-color: #dc3545; color: white; }
        .approve-btn { background-color: #28a745; color: white; }
        input[type="date"] {
            direction: rtl;
            text-align: right;
        }
        .multi-select-dropdown {
            position: relative;
        }
        .multi-select-toggle {
            width: 100%;
            padding: clamp(6px, 1vw, 8px);
            cursor: pointer;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            text-align: right;
            font-size: clamp(12px, 1.5vw, 14px);
        }
        .multi-select-options {
            display: none;
            position: absolute;
            background: #fff;
            border: 1px solid #ccc;
            max-height: 150px;
            overflow-y: auto;
            z-index: 10;
            width: 100%;
            text-align: right;
            border-radius: 4px;
        }
        .multi-select-options label {
            display: block;
            padding: clamp(4px, 0.8vw, 5px);
            font-size: clamp(11px, 1.3vw, 13px);
        }
        .multi-select-options label:hover {
            background-color: #f0f0f0;
        }
        .search-input {
            width: 100%;
            padding: clamp(4px, 0.8vw, 5px);
            border-bottom: 1px solid #ccc;
            margin-bottom: clamp(4px, 0.8vw, 5px);
            font-size: clamp(11px, 1.3vw, 13px);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: clamp(8px, 1.5vw, 10px);
            }
            .header {
                flex-direction: column;
                align-items: center;
                gap: clamp(8px, 1.5vw, 10px);
            }
            .header h3 {
                font-size: clamp(14px, 1.8vw, 16px);
            }
            .logo {
                width: clamp(60px, 8vw, 80px);
            }
            .form-section {
                padding: clamp(8px, 1.5vw, 10px);
                max-width: 100%;
            }
            .filter-container {
                grid-template-columns: 1fr;
            }
            .status-counts {
                flex-direction: column;
                align-items: center;
                gap: clamp(8px, 1.5vw, 10px);
            }
            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            th, td {
                padding: clamp(6px, 0.8vw, 8px);
                font-size: clamp(9px, 1.1vw, 11px);
            }
            .action-buttons button {
                padding: clamp(3px, 0.6vw, 4px) clamp(5px, 0.8vw, 6px);
                font-size: clamp(8px, 1vw, 9px);
            }
            .filter-actions {
                justify-content: center;
                flex-wrap: wrap;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: clamp(5px, 1vw, 8px);
            }
            .container {
                padding: clamp(5px, 1vw, 8px);
            }
            .form-section {
                max-width: 100%;
            }
            label, input, select, textarea, button {
                font-size: clamp(10px, 1.3vw, 12px);
            }
            .filter-actions button, .filter-actions a {
                width: clamp(25px, 3.5vw, 35px);
                height: clamp(25px, 3.5vw, 35px);
            }
            .filter-actions i {
                font-size: clamp(12px, 1.5vw, 14px);
            }
            .pagination a, .pagination span {
                padding: clamp(4px, 0.8vw, 6px) clamp(8px, 1.2vw, 10px);
                font-size: clamp(10px, 1.3vw, 12px);
            }
        }

        /* Print-specific styles */
        @media print {
            body { background: #fff; }
            .container { box-shadow: none; padding: 0; }
            .form-section, .filter-container, .filter-actions, .action-buttons { display: none; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            th, td { font-size: 10px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <a href="../edit_employee_form.php" class="back-btn">
            <i class="fas fa-arrow-right"></i> رجوع
        </a>
        <div class="header-center">
            <h3>نموذج الإجازات</h3>
        </div>
        <div class="header-left">
            <img src="../shjmunlogo.png" alt="شعار" class="logo">
        </div>
    </div>

    <div class="form-section">
        <h2>تسجيل الإجازة للموظف: <?= htmlspecialchars($empName) ?></h2>
        <form id="leave-form">
            <input type="hidden" name="leave_id" id="main-leave-id">
            
            <label>نوع الإجازة:</label>
            <select name="leave_type" id="main-leave-type" required>
                <option value="" disabled selected>-- اختر نوع الإجازة --</option>
                <option value="annual">إجازة سنوية</option>
                <option value="sick">إجازة مرضية</option>
                <option value="emergency">إجازة طارئة</option>
                <option value="compensatory">إجازة تعويضية</option>
            </select>
            <label>تاريخ البداية:</label>
            <input type="date" name="start_date" id="main-start-date" required>
            <label>تاريخ النهاية:</label>
            <input type="date" name="end_date" id="main-end-date" required>
            <label>الموظف البديل:</label>
            <select name="replacement_id" id="main-replacement-id">
                <option value="">-- اختر موظف بديل --</option>
                <?php foreach($replacement_employees as $emp): ?>
                    <option value="<?= $emp['EmpID'] ?>"><?= htmlspecialchars($emp['EmpName']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>سبب الإجازة:</label>
            <textarea name="reason" id="main-reason" rows="3"></textarea>
            <button type="submit" id="submit-button" name="submit_leave">تسجيل الإجازة</button>
        </form>
    </div>

    <div class="status-counts">
        <div>إجمالي السجلات: <span class="total"><?= $counts['total'] ?></span></div>
        <div>إجازات معلقة: <span class="pending-count"><?= $counts['pending'] ?></span></div>
        <div>إجازات معتمدة: <span class="approved-count"><?= $counts['approved'] ?></span></div>
        <div>إجازات مرفوضة: <span class="rejected-count"><?= $counts['rejected'] ?></span></div>
    </div>
    <div class="status-counts">
        <div>إجازات لم تبدأ: <span class="not-started"><?= $counts['not_started'] ?></span></div>
        <div>إجازات سارية: <span class="ongoing"><?= $counts['ongoing'] ?></span></div>
        <div>إجازات منتهية: <span class="ended"><?= $counts['ended'] ?></span></div>
    </div>

    <h2>سجل الإجازات</h2>
    <?php if ($isAdmin || $can_approve_leaves): ?>
    <form method="get" action="">
        <div class="filter-container">
            <div>
                <label>الرقم الإداري:</label>
                <input type="text" name="emp_id" placeholder="الرقم الإداري" value="<?= htmlspecialchars($_GET['emp_id'] ?? '') ?>">
            </div>
            <div>
                <label>الاسم:</label>
                <input type="text" name="name" placeholder="اسم الموظف" value="<?= htmlspecialchars($_GET['name'] ?? '') ?>">
            </div>
            <div class="multi-select-dropdown">
                <label>القطاع:</label>
                <div class="multi-select-toggle" onclick="toggleDropdown(this)">-- اختر القطاع --</div>
                <div class="multi-select-options">
                    <input type="text" class="search-input" placeholder="بحث...">
                    <?php foreach($unique_values['sectors'] as $sector): ?>
                        <label>
                            <input type="checkbox" name="sector[]" value="<?= htmlspecialchars($sector['SectorID']) ?>"
                                <?= in_array($sector['SectorID'], $_GET['sector'] ?? []) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($sector['SectorID']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="multi-select-dropdown">
                <label>القسم:</label>
                <div class="multi-select-toggle" onclick="toggleDropdown(this)">-- اختر القسم --</div>
                <div class="multi-select-options">
                    <input type="text" class="search-input" placeholder="بحث...">
                    <?php foreach($unique_values['departments'] as $dept): ?>
                        <label>
                            <input type="checkbox" name="department[]" value="<?= htmlspecialchars($dept['Department']) ?>"
                                <?= in_array($dept['Department'], $_GET['department'] ?? []) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($dept['Department']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="multi-select-dropdown">
                <label>الشعبة:</label>
                <div class="multi-select-toggle" onclick="toggleDropdown(this)">-- اختر الشعبة --</div>
                <div class="multi-select-options">
                    <input type="text" class="search-input" placeholder="بحث...">
                    <?php foreach($unique_values['divisions'] as $div): ?>
                        <label>
                            <input type="checkbox" name="division[]" value="<?= htmlspecialchars($div['Division']) ?>"
                                <?= in_array($div['Division'], $_GET['division'] ?? []) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($div['Division']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="multi-select-dropdown">
                <label>نوع الإجازة:</label>
                <div class="multi-select-toggle" onclick="toggleDropdown(this)">-- اختر نوع الإجازة --</div>
                <div class="multi-select-options">
                    <input type="text" class="search-input" placeholder="بحث...">
                    <?php foreach($unique_values['leave_types'] as $type): ?>
                        <label>
                            <input type="checkbox" name="leave_type[]" value="<?= htmlspecialchars($type['LeaveType']) ?>"
                                <?= in_array($type['LeaveType'], $_GET['leave_type'] ?? []) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($type['LeaveType']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="multi-select-dropdown">
                <label>حالة الإجازة:</label>
                <div class="multi-select-toggle" onclick="toggleDropdown(this)">-- اختر الحالة --</div>
                <div class="multi-select-options">
                    <input type="text" class="search-input" placeholder="بحث...">
                    <?php foreach($unique_values['statuses'] as $status): ?>
                        <label>
                            <input type="checkbox" name="status[]" value="<?= htmlspecialchars($status['Status']) ?>"
                                <?= in_array($status['Status'], $_GET['status'] ?? []) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($status['Status']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <label>تاريخ البداية:</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
            </div>
            <div>
                <label>تاريخ النهاية:</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" name="filter" title="فلترة"><i class="fas fa-filter"></i></button>
            <a href="leave_requests.php" class="back-btn" title="مسح الفلاتر"><i class="fas fa-redo"></i></a>
            <a href="<?= htmlspecialchars($pdf_link) ?>" class="back-btn" target="_blank" style="background-color: #dc3545; color: white;" title="طباعة PDF">
                <i class="fas fa-file-pdf"></i>
            </a>
        </div>
    </form>
    <?php endif; ?>

    <table id="leaves-table">
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
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($leaves as $leave):
                $status_class = strtolower($leave['Status'] ?? 'pending');
                $start = new DateTime($leave['StartDate']);
                $end = new DateTime($leave['EndDate']);
                $now = new DateTime();
                $time_status = '';
                $time_status_class = '';

                if ($now > $end) {
                    $time_status = 'منتهية';
                    $time_status_class = 'ended';
                } elseif ($now >= $start && $now <= $end) {
                    $time_status = 'سارية';
                    $time_status_class = 'ongoing';
                } else {
                    $time_status = 'لم تبدأ';
                    $time_status_class = 'not-started';
                }

                // تحديد صلاحيات التعديل
                $can_edit = false;
                if ($isAdmin || $can_approve_leaves) {
                    $can_edit = true;
                } elseif ($leave['EmpID'] === $currentEmpID && $leave['Status'] === 'pending') {
                    $can_edit = true;
                }
                
                // تحديد صلاحية الحذف
                $can_delete = ($isAdmin || ($canDelete && $leave['Status'] === 'pending'));
                
                // تحديد صلاحية الاعتماد
                $can_approve = ($isAdmin || $can_approve_leaves) && $leave['Status'] === 'pending';
            ?>
            <tr data-leave-id="<?= $leave['LeaveID'] ?>">
                <td><?= htmlspecialchars($leave['EmpName'] ?? '-') ?></td>
                <td><?= htmlspecialchars($leave['EmpID'] ?? '-') ?></td>
                <td><?= htmlspecialchars($leave['JobTitle'] ?? '-') ?></td>
                <td><?= htmlspecialchars($leave['Department'] ?? '-') ?></td>
                <td><?= htmlspecialchars($leave['Division'] ?? '-') ?></td>
                <td><?= htmlspecialchars($leave['SectorID'] ?? '-') ?></td>
                <td><?= htmlspecialchars($leave['LeaveType'] ?? '-') ?></td>
                <td><?= $leave['StartDate'] ?></td>
                <td><?= $leave['EndDate'] ?></td>
                <td><?= htmlspecialchars($leave['ReplacementName'] ?? '-') ?></td>
                <td>
                    <span class="status-<?= $status_class ?>">
                        <?= htmlspecialchars($leave['Status'] ?? 'pending') ?>
                    </span>
                    <br>
                    <span class="time-status <?= $time_status_class ?>">
                        <?= $time_status ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($leave['ApproverName'] ?? '-') ?></td>
                <td class="action-buttons">
                    <?php if ($can_edit): ?>
                        <button class="edit-btn" data-leave-id="<?= $leave['LeaveID'] ?>" title="تعديل"><i class="fas fa-edit"></i></button>
                    <?php endif; ?>
                    <?php if ($can_delete): ?>
                        <button class="delete-btn" data-leave-id="<?= $leave['LeaveID'] ?>" title="حذف"><i class="fas fa-trash"></i></button>
                    <?php endif; ?>
                    <?php if ($can_approve): ?>
                        <button class="approve-btn" data-leave-id="<?= $leave['LeaveID'] ?>" data-emp-id="<?= $currentEmpID ?>" title="اعتماد"><i class="fas fa-check"></i></button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="pagination">
        <?php for($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script>
    function toggleDropdown(element) {
        $(element).siblings('.multi-select-options').toggle();
    }

    $(document).on('click', function(event) {
        if (!$(event.target).closest('.multi-select-dropdown').length) {
            $('.multi-select-options').hide();
        }
    });
    
    $('.search-input').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        var optionsContainer = $(this).parent('.multi-select-options');
        optionsContainer.find('label').each(function() {
            var labelText = $(this).text().toLowerCase();
            if (labelText.includes(searchTerm)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    function resetForm() {
        $('#leave-form')[0].reset();
        $('#main-leave-id').val('');
        $('#main-leave-type').val('');
        $('#submit-button').text('تسجيل الإجازة');
    }

    $('#leave-form').on('submit', function(e) {
        e.preventDefault();
        if (!$('#main-leave-type').val()) {
            alert('يرجى اختيار نوع الإجازة.');
            return;
        }
        const leaveId = $('#main-leave-id').val();
        const url = leaveId ? 'update_leave.php' : 'process_leave_request.php';

        $.ajax({
            url: url,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(leaveId ? 'تم تحديث الإجازة بنجاح!' : 'تم تسجيل الإجازة بنجاح!');
                    location.reload();
                } else {
                    alert('حدث خطأ: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error: " + status + " - " + error);
                alert("حدث خطأ في الاتصال بالخادم. يرجى المحاولة لاحقًا.");
            }
        });
    });

    $('#leaves-table').on('click', '.delete-btn', function() {
        if (confirm('هل أنت متأكد من حذف هذه الإجازة؟')) {
            const leaveId = $(this).data('leave-id');
            $.ajax({
                url: 'delete_leave.php',
                method: 'POST',
                data: { leave_id: leaveId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('تم حذف الإجازة بنجاح.');
                        location.reload();
                    } else {
                        alert('حدث خطأ: ' + response.message);
                    }
                }
            });
        }
    });

    $('#leaves-table').on('click', '.approve-btn', function() {
        const leaveId = $(this).data('leave-id');
        const empId = $(this).data('emp-id');
        $.ajax({
            url: 'approve_leave.php',
            method: 'POST',
            data: { leave_id: leaveId, approved_by: empId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('تم اعتماد الإجازة بنجاح!');
                    location.reload();
                } else {
                    alert('حدث خطأ: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error: " + status + " - " + error);
                alert("حدث خطأ في الاتصال بالخادم. يرجى المحاولة لاحقًا.");
            }
        });
    });

    $('#leaves-table').on('click', '.edit-btn', function() {
        const leaveId = $(this).data('leave-id');
        $.ajax({
            url: 'get_leave_details.php',
            method: 'GET',
            data: { leave_id: leaveId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#main-leave-id').val(response.data.LeaveID);
                    $('#main-leave-type').val(response.data.LeaveType);
                    $('#main-start-date').val(response.data.StartDate);
                    $('#main-end-date').val(response.data.EndDate);
                    $('#main-replacement-id').val(response.data.ReplacementEmpID);
                    $('#main-reason').val(response.data.Reason);
                    $('#submit-button').text('حفظ التعديلات');
                    $('html, body').animate({
                        scrollTop: $("#leave-form").offset().top
                    }, 500);
                } else {
                    alert('خطأ في جلب بيانات الإجازة: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error: " + status + " - " + error);
                alert("حدث خطأ في الاتصال بالخادم. يرجى المحاولة لاحقًا.");
            }
        });
    });
</script>
</body>
</html>