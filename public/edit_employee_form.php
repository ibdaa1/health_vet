<?php
// edit_employee_form.php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Check for user authentication and admin privilege
if (!isset($_SESSION['user']) || $_SESSION['user']['IsAdmin'] != 1) {
    header("Location: login.php");
    exit;
}

require_once(__DIR__ . '/../api/db.php');
$conn->set_charset("utf8mb4");

$error = '';
$success = '';

// Data arrays for dropdowns
$jobTitles = [
    'Head of Section',
    'Veterinarian',
    'Assistant Veterinarian',
    'Clerk Assistant',
    'Administrative Secretary',
    'Driver',
    'Administrative Coordinator',
    'Administrative Officer',
    'Daily Worker',
    'Shift Supervisor',
    'Daily Worker',
    'Clerk Assistant'
];

$departments = ['قسم الرقابة البيئية', 'قسم الرقابة الغذائية', 'قسم الرقابة الصحية', 'قسم الرقابة البيطرية', 'قسم تنظيم ورقابة النفايات']; // أضف الشعب الخاصة بك هنا
$divisions = [
    'وحدة ماوي الشارقة للقطط والكلاب',
    'وحدة التراخيص والتصاريح البيطرية',
    'وحدة المسلخ',
    'وحدة سوق الطيور',
    'شعبة الاستيراد والتصدير',
         'سوق الجبيل',
    'الشعبة الادارية'
];

// Handle update and delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $empId = isset($_POST['EmpID']) ? $_POST['EmpID'] : null;

    if ($_POST['action'] === 'update' && $empId) {
        $empName = $_POST['EmpName'];
        $jobTitle = $_POST['JobTitle'];
        $department = $_POST['Department'];
        $division = $_POST['Division'];
        $sectorId = $_POST['SectorID'];
        
        $sql = "UPDATE Users SET EmpName = ?, JobTitle = ?, Department = ?, Division = ?, SectorID = ? WHERE EmpID = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("sssssi", $empName, $jobTitle, $department, $division, $sectorId, $empId);
            if ($stmt->execute()) {
                $success = "تم تحديث بيانات الموظف بنجاح.";
            } else {
                $error = "خطأ في تحديث البيانات: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($_POST['action'] === 'delete' && $empId) {
        $sql = "DELETE FROM Users WHERE EmpID = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("i", $empId);
            if ($stmt->execute()) {
                $success = "تم حذف الموظف بنجاح.";
            } else {
                $error = "خطأ في حذف الموظف: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Get all sector IDs and names from database for filter
$sectors = [];
$sectorQuery = "SELECT SectorID, SectorName FROM tbl_Sectors ORDER BY SectorID";
$sectorResult = $conn->query($sectorQuery);
if ($sectorResult && $sectorResult->num_rows > 0) {
    while ($row = $sectorResult->fetch_assoc()) {
        $sectors[$row['SectorID']] = $row['SectorName'];
    }
}

// Pagination setup
$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch users based on filters
$whereClause = '1=1';
$params = [];
$types = '';

if (!empty($_GET['filter_empid'])) {
    $whereClause .= " AND u.EmpID = ?";
    $params[] = $_GET['filter_empid'];
    $types .= 'i';
}

if (!empty($_GET['filter_empname'])) {
    $whereClause .= " AND u.EmpName LIKE ?";
    $params[] = '%' . $_GET['filter_empname'] . '%';
    $types .= 's';
}

if (!empty($_GET['filter_jobtitle']) && is_array($_GET['filter_jobtitle'])) {
    $placeholders = implode(',', array_fill(0, count($_GET['filter_jobtitle']), '?'));
    $whereClause .= " AND u.JobTitle IN ($placeholders)";
    $params = array_merge($params, $_GET['filter_jobtitle']);
    $types .= str_repeat('s', count($_GET['filter_jobtitle']));
}

if (!empty($_GET['filter_division']) && is_array($_GET['filter_division'])) {
    $placeholders = implode(',', array_fill(0, count($_GET['filter_division']), '?'));
    $whereClause .= " AND u.Division IN ($placeholders)";
    $params = array_merge($params, $_GET['filter_division']);
    $types .= str_repeat('s', count($_GET['filter_division']));
}

if (!empty($_GET['filter_sectorid']) && is_array($_GET['filter_sectorid'])) {
    $placeholders = implode(',', array_fill(0, count($_GET['filter_sectorid']), '?'));
    $whereClause .= " AND u.SectorID IN ($placeholders)";
    $params = array_merge($params, $_GET['filter_sectorid']);
    $types .= str_repeat('i', count($_GET['filter_sectorid']));
}

// Add filter for empty sector IDs if requested
if (isset($_GET['filter_empty_sector']) && $_GET['filter_empty_sector'] == '1') {
    $whereClause .= " AND (u.SectorID IS NULL OR u.SectorID = '')";
}

// Get total number of records for pagination
$sqlCount = "SELECT COUNT(*) FROM Users u WHERE " . $whereClause;
$stmtCount = $conn->prepare($sqlCount);
if (!empty($params) && !empty($types)) {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$totalRecords = $stmtCount->get_result()->fetch_row()[0];
$totalPages = ceil($totalRecords / $limit);
$stmtCount->close();

// Fetch filtered and paginated data with sector name
$sql = "SELECT u.EmpID, u.EmpName, u.JobTitle, u.Department, u.Division, u.SectorID, s.SectorName 
        FROM Users u 
        LEFT JOIN tbl_Sectors s ON u.SectorID = s.SectorID 
        WHERE " . $whereClause . " 
        ORDER BY u.EmpID ASC 
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
if (!empty($params) && !empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$conn->close();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نموذج إدارة الموظفين</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #008000;
            --secondary-color: #f0f8ff;
            --text-color: #333;
            --header-bg: #fff;
            --table-header-bg: #e6f5e6;
            --border-color: #ddd;
            --hover-color: #f2f2f2;
        }
        body {
            font-family: 'Arial', sans-serif;
            direction: rtl;
            margin: 0;
            padding: 0;
            background-color: var(--secondary-color);
            color: var(--text-color);
            font-size: 14px;
        }
        .container {
            max-width: 1600px;
            margin: 20px auto;
            padding: 20px;
            background-color: var(--header-bg);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-color);
        }
        .header .branding-left {
            display: flex;
            align-items: center;
            gap: 10px;
            order: 1;
        }
        .header .branding-right {
            display: flex;
            align-items: center;
            gap: 10px;
            order: 3;
        }
        .header .branding-right .logo img {
            max-width: 100px;
        }
        .header .branding-left h2 {
            margin: 0;
            font-size: 1.2em;
            color: #555;
            white-space: nowrap;
        }
        .header-title {
            text-align: center;
            flex-grow: 1;
            order: 2;
        }
        .header-title h1 {
            margin: 0;
            font-size: 2.2em;
            color: var(--primary-color);
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            text-align: center;
            font-size: 1.1em;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: var(--table-header-bg);
            border-radius: 8px;
        }
        .filter-form .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-form label {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .filter-form input[type="text"] {
            padding: 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }
        .filter-form .button-group {
            display: flex;
            gap: 10px;
            align-self: flex-end;
        }
        .filter-form button {
            cursor: pointer;
            color: white;
            transition: background-color 0.3s;
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
        }
        .filter-form button[type="submit"] {
            background-color: var(--primary-color);
        }
        .filter-form button[type="submit"]:hover {
            background-color: #006400;
        }
        .filter-form button.clear-btn {
            background-color: #888;
        }
        .filter-form button.clear-btn:hover {
            background-color: #666;
        }
        .filter-form button.home-btn {
            background-color: #17a2b8;
        }
        .filter-form button.home-btn:hover {
            background-color: #138496;
        }
        .filter-form button.clear-all-btn {
            background-color: #dc3545;
        }
        .filter-form button.clear-all-btn:hover {
            background-color: #c82333;
        }

        /* Custom Dropdown for Checkboxes */
        .custom-select {
            position: relative;
            width: 100%;
        }
        .select-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background-color: #fff;
            cursor: pointer;
            user-select: none;
            font-size: 1em;
        }
        .select-box i {
            margin-right: 8px;
            font-size: 0.8em;
            transition: transform 0.3s;
        }
        .select-box.active i {
            transform: rotate(180deg);
        }
        .options-container {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: #fff;
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 4px 4px;
            z-index: 100;
            max-height: 200px;
            overflow-y: auto;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .options-container.active {
            display: block;
        }
        .options-container input[type="text"] {
            width: 95%;
            padding: 8px;
            margin: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .option {
            padding: 10px;
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        .option:hover {
            background-color: var(--hover-color);
        }
        .option input[type="checkbox"] {
            margin-left: 10px;
        }
        .selected-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }
        .filter-badge {
            background-color: var(--primary-color);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            display: inline-flex;
            align-items: center;
        }
        .filter-badge i {
            margin-right: 5px;
            cursor: pointer;
        }
        .empty-table {
            text-align: center;
            padding: 50px;
            color: #999;
            font-size: 1.2em;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        th, td {
            border: 1px solid var(--border-color);
            padding: 8px;
            text-align: right;
            white-space: nowrap;
        }
        th {
            background-color: var(--primary-color);
            color: white;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        tr {
            background-color: #fff;
        }
        tr:hover {
            background-color: var(--hover-color);
        }
        td input, td select {
            width: 100%;
            padding: 6px;
            box-sizing: border-box;
            border: none;
            background: transparent;
            font-size: 1em;
            transition: background-color 0.3s;
        }
        td input:focus, td select:focus {
            background-color: #fff;
            border: 1px solid var(--primary-color);
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
            align-items: center;
        }
        .action-buttons button {
            padding: 6px 10px;
            cursor: pointer;
            border: none;
            border-radius: 4px;
            color: white;
            transition: background-color 0.3s;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .update-btn { background-color: #28a745; }
        .update-btn:hover { background-color: #218838; }
        .delete-btn { background-color: #dc3545; }
        .delete-btn:hover { background-color: #c82333; }
        
        /* Table Wrapper for Scrolling */
        .table-wrapper {
            overflow-x: auto;
            margin-bottom: 20px;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 4px;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
            border-radius: 4px;
            text-decoration: none;
            transition: background-color 0.3s, color 0.3s;
        }
        .pagination a:hover {
            background-color: var(--primary-color);
            color: white;
        }
        .pagination .current-page {
            background-color: var(--primary-color);
            color: white;
            font-weight: bold;
        }
        .pagination .disabled {
            color: #999;
            border-color: #ccc;
            cursor: not-allowed;
        }
        .print-btn, .export-btn {
            background-color: #17a2b8;
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 20px;
            width: fit-content;
            margin-right: auto;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .export-btn {
            background-color: #006400;
        }
        .print-btn:hover {
            background-color: #138496;
        }
        .export-btn:hover {
            background-color: #004d00;
        }
        
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .header { flex-direction: column; align-items: center; text-align: center; }
            .header .branding-left { order: 1; margin-bottom: 10px; flex-direction: column; text-align: center; }
            .header .branding-right { order: 3; margin-top: 10px; }
            .header-title { order: 2; }
            .filter-form { grid-template-columns: 1fr; }
            .table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            th, td { min-width: 120px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="branding-left">
                <h2>إدارة الرقابة والسلامة الصحية</h2>
            </div>
            <div class="header-title">
                <h1>نموذج إدارة الموظفين</h1>
            </div>
            <div class="branding-right">
                <div class="logo">
                    <img src="shjmunlogo.png" alt="شعار بلدية الشارقة">
                </div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error"><i class="fas fa-times-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <form action="" method="get" class="filter-form" id="filter-form">
            <div class="filter-group">
                <label for="filter_empid">رقم الموظف:</label>
                <input type="text" id="filter_empid" name="filter_empid" value="<?php echo htmlspecialchars($_GET['filter_empid'] ?? ''); ?>" placeholder="أدخل رقم الموظف">
            </div>
            <div class="filter-group">
                <label for="filter_empname">اسم الموظف:</label>
                <input type="text" id="filter_empname" name="filter_empname" value="<?php echo htmlspecialchars($_GET['filter_empname'] ?? ''); ?>" placeholder="أدخل اسم الموظف">
            </div>
            <div class="filter-group">
                <label for="jobtitle-select">الوظيفة:</label>
                <div class="custom-select">
                    <div class="select-box" onclick="toggleDropdown('jobtitle')">
                        <span id="jobtitle-selected-text">اختيار الوظائف</span>
                        <i class="fas fa-caret-down"></i>
                    </div>
                    <div class="options-container" id="jobtitle-options">
                        <input type="text" class="search-input" placeholder="ابحث عن وظيفة...">
                        <?php foreach ($jobTitles as $job): ?>
                            <label class="option">
                                <input type="checkbox" name="filter_jobtitle[]" value="<?php echo htmlspecialchars($job); ?>"
                                    <?php echo (isset($_GET['filter_jobtitle']) && in_array($job, $_GET['filter_jobtitle'])) ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars($job); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (isset($_GET['filter_jobtitle']) && is_array($_GET['filter_jobtitle'])): ?>
                <div class="selected-filters" id="jobtitle-filters">
                    <?php foreach ($_GET['filter_jobtitle'] as $selectedJob): ?>
                        <span class="filter-badge">
                            <?php echo htmlspecialchars($selectedJob); ?>
                            <i class="fas fa-times" onclick="removeFilter('filter_jobtitle', '<?php echo urlencode($selectedJob); ?>')"></i>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="filter-group">
                <label for="division-select">الشعبة:</label>
                <div class="custom-select">
                    <div class="select-box" onclick="toggleDropdown('division')">
                        <span id="division-selected-text">اختيار الشعب</span>
                        <i class="fas fa-caret-down"></i>
                    </div>
                    <div class="options-container" id="division-options">
                        <input type="text" class="search-input" placeholder="ابحث عن شعبة...">
                        <?php foreach ($divisions as $division): ?>
                            <label class="option">
                                <input type="checkbox" name="filter_division[]" value="<?php echo htmlspecialchars($division); ?>"
                                    <?php echo (isset($_GET['filter_division']) && in_array($division, $_GET['filter_division'])) ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars($division); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (isset($_GET['filter_division']) && is_array($_GET['filter_division'])): ?>
                <div class="selected-filters" id="division-filters">
                    <?php foreach ($_GET['filter_division'] as $selectedDivision): ?>
                        <span class="filter-badge">
                            <?php echo htmlspecialchars($selectedDivision); ?>
                            <i class="fas fa-times" onclick="removeFilter('filter_division', '<?php echo urlencode($selectedDivision); ?>')"></i>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="filter-group">
                <label for="sectorid-select">رقم القطاع:</label>
                <div class="custom-select">
                    <div class="select-box" onclick="toggleDropdown('sectorid')">
                        <span id="sectorid-selected-text">اختيار القطاعات</span>
                        <i class="fas fa-caret-down"></i>
                    </div>
                    <div class="options-container" id="sectorid-options">
                        <input type="text" class="search-input" placeholder="ابحث عن قطاع...">
                        <?php foreach ($sectors as $sectorId => $sectorName): ?>
                            <label class="option">
                                <input type="checkbox" name="filter_sectorid[]" value="<?php echo $sectorId; ?>"
                                    <?php echo (isset($_GET['filter_sectorid']) && in_array($sectorId, $_GET['filter_sectorid'])) ? 'checked' : ''; ?>>
                                <?php echo $sectorId . " - " . htmlspecialchars($sectorName); ?>
                            </label>
                        <?php endforeach; ?>
                        <label class="option">
                            <input type="checkbox" name="filter_empty_sector" value="1"
                                <?php echo (isset($_GET['filter_empty_sector']) && $_GET['filter_empty_sector'] == '1') ? 'checked' : ''; ?>>
                            (قيم فارغة)
                        </label>
                    </div>
                </div>
                <?php if (isset($_GET['filter_sectorid']) && is_array($_GET['filter_sectorid'])): ?>
                <div class="selected-filters" id="sectorid-filters">
                    <?php foreach ($_GET['filter_sectorid'] as $selectedSector): ?>
                        <span class="filter-badge">
                            <?php echo htmlspecialchars($selectedSector); ?>
                            <i class="fas fa-times" onclick="removeFilter('filter_sectorid', '<?php echo urlencode($selectedSector); ?>')"></i>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (isset($_GET['filter_empty_sector']) && $_GET['filter_empty_sector'] == '1'): ?>
                <div class="selected-filters" id="empty-sector-filter">
                    <span class="filter-badge">
                        (قيم فارغة)
                        <i class="fas fa-times" onclick="removeFilter('filter_empty_sector', '1')"></i>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <div class="button-group">
                <button type="submit" title="تطبيق الفلتر"><i class="fas fa-filter"></i> تصفية</button>
                <button type="button" class="clear-all-btn" onclick="clearAllFilters()" title="إلغاء جميع الفلاتر"><i class="fas fa-times"></i> إلغاء الكل</button>
                <a href="index.php" class="home-btn" style="text-decoration: none; padding: 8px 12px; border-radius: 4px;"><i class="fas fa-home"></i> الرئيسية</a>
            </div>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>رقم الموظف</th>
                        <th>اسم الموظف</th>
                        <th>الوظيفة</th>
                        <th>القسم</th>
                        <th>الشعبة</th>
                        <th>رقم القطاع</th>
                        <th>اسم القطاع</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td data-label="رقم الموظف"><?php echo htmlspecialchars($row['EmpID']); ?></td>
                                <form action="" method="post" class="edit-form">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="EmpID" value="<?php echo htmlspecialchars($row['EmpID']); ?>">
                                    <td data-label="اسم الموظف"><input type="text" name="EmpName" value="<?php echo htmlspecialchars($row['EmpName']); ?>" required></td>
                                    <td data-label="الوظيفة">
                                        <select name="JobTitle" required>
                                            <?php foreach ($jobTitles as $job): ?>
                                                <option value="<?php echo htmlspecialchars($job); ?>" <?php echo ($row['JobTitle'] == $job) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($job); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="القسم">
                                        <select name="Department" required>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo ($row['Department'] == $dept) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($dept); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="الشعبة">
                                        <select name="Division" required>
                                            <?php foreach ($divisions as $div): ?>
                                                <option value="<?php echo htmlspecialchars($div); ?>" <?php echo ($row['Division'] == $div) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($div); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="رقم القطاع"><input type="number" name="SectorID" value="<?php echo htmlspecialchars($row['SectorID'] ?? ''); ?>" min="1" required></td>
                                    <td data-label="اسم القطاع"><?php echo htmlspecialchars($row['SectorName'] ?? 'غير محدد'); ?></td>
                                    <td data-label="الإجراءات" class="action-buttons">
                                        <button type="submit" class="update-btn" title="تحديث"><i class="fas fa-save"></i></button>
                                    </form>
                                    <form action="" method="post" onsubmit="return confirm('هل أنت متأكد من حذف هذا الموظف؟');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="EmpID" value="<?php echo htmlspecialchars($row['EmpID']); ?>">
                                        <button type="submit" class="delete-btn" title="حذف"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty-table">لا توجد بيانات متاحة لعرضها.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <span>صفحة <?php echo $page; ?> من <?php echo $totalPages; ?></span>
            <?php
            $queryString = http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY));
            ?>
            <a href="?page=1&<?php echo htmlspecialchars($queryString); ?>" class="<?php echo ($page == 1) ? 'disabled' : ''; ?>" aria-disabled="<?php echo ($page == 1) ? 'true' : 'false'; ?>">الأولى</a>
            <a href="?page=<?php echo max(1, $page - 1); ?>&<?php echo htmlspecialchars($queryString); ?>" class="<?php echo ($page == 1) ? 'disabled' : ''; ?>" aria-disabled="<?php echo ($page == 1) ? 'true' : 'false'; ?>">السابقة</a>
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
                <a href="?page=<?php echo $i; ?>&<?php echo htmlspecialchars($queryString); ?>" class="<?php echo ($page == $i) ? 'current-page' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <a href="?page=<?php echo min($totalPages, $page + 1); ?>&<?php echo htmlspecialchars($queryString); ?>" class="<?php echo ($page == $totalPages) ? 'disabled' : ''; ?>" aria-disabled="<?php echo ($page == $totalPages) ? 'true' : 'false'; ?>">التالية</a>
            <a href="?page=<?php echo $totalPages; ?>&<?php echo htmlspecialchars($queryString); ?>" class="<?php echo ($page == $totalPages) ? 'disabled' : ''; ?>" aria-disabled="<?php echo ($page == $totalPages) ? 'true' : 'false'; ?>">الأخيرة</a>
        </div>
        <div style="display: flex; gap: 10px; justify-content: flex-start; margin-top: 10px;">
            <button class="print-btn" onclick="printReport()">
                <i class="fas fa-print"></i> طباعة التقرير
            </button>
            <button class="export-btn" onclick="exportToExcel()">
                <i class="fas fa-file-excel"></i> تصدير إكسل
            </button>
        </div>
    </div>
    <script>
        function goBack() {
            window.history.back();
        }

        function toggleDropdown(id) {
            const optionsContainer = document.getElementById(id + '-options');
            const selectBox = optionsContainer.previousElementSibling;
            optionsContainer.classList.toggle('active');
            selectBox.classList.toggle('active');
            if (optionsContainer.classList.contains('active')) {
                optionsContainer.querySelector('.search-input').focus();
            }
        }

        function removeFilter(filterName, filterValue) {
            const url = new URL(window.location.href);
            const searchParams = url.searchParams;
            
            if (filterName === 'filter_empty_sector') {
                searchParams.delete(filterName);
            } else {
                // Get current values for this filter
                const currentValues = searchParams.getAll(filterName + '[]');
                
                // Remove the specific value
                const newValues = currentValues.filter(val => val !== decodeURIComponent(filterValue));
                
                // Remove the parameter completely
                searchParams.delete(filterName + '[]');
                
                // Add back the remaining values
                newValues.forEach(val => {
                    searchParams.append(filterName + '[]', val);
                });
            }
            
            // Reload the page with updated filters
            window.location.href = url.toString();
        }

        function clearAllFilters() {
            window.location.href = window.location.pathname;
        }

        function printReport() {
            // Pass current filter parameters to print page
            const queryString = window.location.search;
            window.open('employee_print_report.php' + queryString, '_blank');
        }

        function exportToExcel() {
            // Pass current filter parameters to export page
            const queryString = window.location.search;
            window.location.href = 'employee_export_excel.php' + queryString;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const dropdowns = ['jobtitle', 'division', 'sectorid'];
            dropdowns.forEach(id => {
                const options = document.getElementById(id + '-options');
                const selectedText = document.getElementById(id + '-selected-text');
                const checkboxes = options.querySelectorAll('input[type="checkbox"]');
                const searchInput = options.querySelector('.search-input');
                
                function updateSelectedText() {
                    const checkedCount = options.querySelectorAll('input[type="checkbox"]:checked').length;
                    if (checkedCount > 0) {
                        selectedText.textContent = `تم اختيار ${checkedCount}`;
                    } else {
                        selectedText.textContent = `اختيار ${id === 'jobtitle' ? 'الوظائف' : (id === 'division' ? 'الشعب' : 'القطاعات')}`;
                    }
                }

                function filterOptions() {
                    const filter = searchInput.value.toLowerCase();
                    const optionsLabels = options.querySelectorAll('.option');
                    optionsLabels.forEach(label => {
                        const text = label.textContent.toLowerCase();
                        if (text.indexOf(filter) > -1) {
                            label.style.display = "";
                        } else {
                            label.style.display = "none";
                        }
                    });
                }
                
                checkboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', updateSelectedText);
                });

                if(searchInput) {
                    searchInput.addEventListener('keyup', filterOptions);
                }
                
                updateSelectedText();
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('.custom-select')) {
                    document.querySelectorAll('.options-container').forEach(container => {
                        container.classList.remove('active');
                        container.previousElementSibling.classList.remove('active');
                    });
                }
            });
        });
    </script>
</body>
</html>