<?php
// employee_print_report.php
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

// Fetch all data and order it for custom grouping
$sql = "SELECT 
            U.EmpID, 
            U.EmpName, 
            U.JobTitle, 
            U.Department, 
            U.Division, 
            U.SectorID, 
            S.SectorName,
            SM.EmpName AS SectorManagerName
        FROM Users AS U
        LEFT JOIN tbl_Sectors AS S ON U.SectorID = S.SectorID
        LEFT JOIN Users AS SM ON S.SectorManagerID = SM.EmpID
        ORDER BY U.Division, U.SectorID, U.JobTitle, U.EmpName";

$result = $conn->query($sql);

$report_data = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $report_data[] = $row;
    }
}
$total_employees = count($report_data);
$conn->close();

// Separate "رئيس قسم الرقابة الغذائية"
$head_of_department_employees = array_filter($report_data, function($employee) {
    return $employee['JobTitle'] === 'رئيس قسم الرقابة الغذائية';
});

// Separate employees with JobTitle starting with "رئيس شعبة" and those with SectorID = 1
$head_of_division_employees = array_filter($report_data, function($employee) {
    return strpos($employee['JobTitle'], 'رئيس شعبة') === 0 || $employee['SectorID'] == 1;
});

// Filter out the separated employees from the main data
$remaining_employees = array_filter($report_data, function($employee) {
    return $employee['JobTitle'] !== 'رئيس قسم الرقابة الغذائية' && strpos($employee['JobTitle'], 'رئيس شعبة') !== 0 && $employee['SectorID'] != 1;
});

// Custom sort function to prioritize specific job titles
usort($remaining_employees, function($a, $b) {
    $job_priorities = [
        'مفتش رقابة أغذية أول' => 2,
        'مفتش رقابة أغذية ثان' => 3,
    ];

    $a_priority = $job_priorities[$a['JobTitle']] ?? 99;
    $b_priority = $job_priorities[$b['JobTitle']] ?? 99;

    // Primary sort by Division
    $division_cmp = strcmp($a['Division'], $b['Division']);
    if ($division_cmp !== 0) {
        return $division_cmp;
    }

    // Secondary sort by SectorID, with 0s at the end
    $a_sector = (int)$a['SectorID'];
    $b_sector = (int)$b['SectorID'];
    if ($a_sector === 0 && $b_sector !== 0) return 1;
    if ($a_sector !== 0 && $b_sector === 0) return -1;
    if ($a_sector !== $b_sector) {
        return $a_sector - $b_sector;
    }

    // Tertiary sort by job title priority
    if ($a_priority !== $b_priority) {
        return $a_priority - $b_priority;
    }

    // Final sort by EmpName
    return strcmp($a['EmpName'], $b['EmpName']);
});

// Group the sorted data by Division and then by Sector
$grouped_data = [];
foreach ($remaining_employees as $employee) {
    $division = $employee['Division'];
    $sector = $employee['SectorID'];
    
    if (!isset($grouped_data[$division])) {
        $grouped_data[$division] = [];
    }
    if (!isset($grouped_data[$division][$sector])) {
        $grouped_data[$division][$sector] = [];
    }
    $grouped_data[$division][$sector][] = $employee;
}

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير الموظفين</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            direction: rtl;
            background-color: #f9f9f9;
            color: #333;
        }
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #A3D9B5;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header-left {
            display: flex;
            align-items: center;
        }
        .header-right {
            display: flex;
            align-items: center;
        }
        .header-center {
            text-align: center;
            flex-grow: 1;
        }
        .header h3 {
            margin: 0;
            color: #3d9970;
            font-size: 18px;
        }
        .logo {
            width: 100px;
            height: auto;
        }
        .report-info {
            text-align: left;
            font-size: 14px;
            color: #777;
        }
        .report-info p {
            margin: 0;
        }
        .group-header {
            background-color: #d9f2d9;
            color: #5a8c5a;
            padding: 10px;
            margin-top: 20px;
            font-size: 18px;
            border-radius: 5px 5px 0 0;
        }
        .group-header.main {
            background-color: #b8e2b8;
            color: #3d9970;
            font-size: 20px;
            margin-top: 30px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: right;
            font-size: 12px;
        }
        th {
            background-color: #eaf7ea;
            color: #3d9970;
        }
        .highlighted-row {
            background-color: #fcfce7;
        }
        .highlighted-row td {
            font-weight: bold;
        }
        .text-center {
            text-align: center;
        }
        .report-footer {
            margin-top: 20px;
            text-align: left;
            font-size: 16px;
            font-weight: bold;
            color: #3d9970;
        }
        .print-btn {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .print-btn:hover {
            background-color: #45a049;
        }
        @media print {
            body {
                background-color: #fff;
            }
            .container {
                box-shadow: none;
                padding: 0;
            }
            .header {
                border-bottom: 1px solid #000;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="no-print">
            <button class="print-btn" onclick="window.print()">طباعة التقرير</button>
        </div>
        <div class="header">
            <div class="header-right">
                <h3>إدارة الرقابة والسلامة الصحية</h3>
            </div>
            <div class="header-center">
                <h3>تقرير بيانات الموظفين</h3>
            </div>
            <div class="header-left">
                <img src="shjmunlogo.png" alt="شعار بلدية الشارقة" class="logo">
            </div>
        </div>
        
        <?php if (!empty($head_of_department_employees)): ?>
            <div class="group-header main">
                رئيس قسم الرقابة الغذائية (عدد الموظفين: <?php echo count($head_of_department_employees); ?>)
            </div>
            <table>
                <thead>
                    <tr>
                        <th>رقم الموظف</th>
                        <th>اسم الموظف</th>
                        <th>الوظيفة</th>
                        <th>القسم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($head_of_department_employees as $row): ?>
                        <tr class="highlighted-row">
                            <td><?php echo htmlspecialchars($row['EmpID']); ?></td>
                            <td><?php echo htmlspecialchars($row['EmpName']); ?></td>
                            <td><?php echo htmlspecialchars($row['JobTitle']); ?></td>
                            <td><?php echo htmlspecialchars($row['Department']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($head_of_division_employees)): ?>
            <div class="group-header main">
                المسئولين (عدد الموظفين: <?php echo count($head_of_division_employees); ?>)
            </div>
            <table>
                <thead>
                    <tr>
                        <th>رقم الموظف</th>
                        <th>اسم الموظف</th>
                        <th>الوظيفة</th>
                        <th>القسم</th>
                        <th>الشعبة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($head_of_division_employees as $row): ?>
                        <tr class="highlighted-row">
                            <td><?php echo htmlspecialchars($row['EmpID']); ?></td>
                            <td><?php echo htmlspecialchars($row['EmpName']); ?></td>
                            <td><?php echo htmlspecialchars($row['JobTitle']); ?></td>
                            <td><?php echo htmlspecialchars($row['Department']); ?></td>
                            <td><?php echo htmlspecialchars($row['Division']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($grouped_data)): ?>
            <?php foreach ($grouped_data as $division_name => $sectors): ?>
                <?php foreach ($sectors as $sector_id => $employees): ?>
                    <div class="group-header main">
                        <?php
                            $sector_display = '';
                            if ($sector_id == 0) {
                                $sector_display = 'لا يوجد قطاع';
                            } else {
                                $sector_display = 'القطاع: ' . htmlspecialchars($sector_id);
                                if (!empty($employees[0]['SectorName'])) {
                                    $sector_display = htmlspecialchars($employees[0]['SectorName']);
                                }
                                $sector_display .= ' (المسؤول: ' . htmlspecialchars($employees[0]['SectorManagerName'] ?? 'غير محدد') . ')';
                            }
                            $sector_display .= ' (عدد الموظفين: ' . count($employees) . ')';
                        ?>
                        <?php echo $sector_display; ?>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>رقم الموظف</th>
                                <th>اسم الموظف</th>
                                <th>الوظيفة</th>
                                <th>القسم</th>
                                <th>الشعبة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $row): ?>
                                <?php 
                                $is_inspector = (strpos($row['JobTitle'], 'مفتش رقابة أغذية') === 0);
                                $row_class = $is_inspector ? '' : 'highlighted-row';
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td><?php echo htmlspecialchars($row['EmpID']); ?></td>
                                    <td><?php echo htmlspecialchars($row['EmpName']); ?></td>
                                    <td><?php echo htmlspecialchars($row['JobTitle']); ?></td>
                                    <td><?php echo htmlspecialchars($row['Department']); ?></td>
                                    <td><?php echo htmlspecialchars($row['Division']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align: center;">لا توجد بيانات متاحة لإنشاء التقرير.</p>
        <?php endif; ?>

        <div class="report-footer">
            <p>إجمالي عدد الموظفين: <?php echo $total_employees; ?></p>
        </div>
    </div>
</body>
</html>