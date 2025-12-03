<?php
declare(strict_types=1);

// ===== صرامة الإخراج و اللوجينغ =====
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function($sev, $msg, $file, $line){
    throw new ErrorException($msg, 0, $sev, $file, $line);
});
header('Content-Type: application/json; charset=utf-8');
if (ob_get_level() === 0) { ob_start(); }

try {
require_once(__DIR__ . '/../api/db.php');$conn->set_charset("utf8mb4");
    if (!isset($conn) || !$conn) {
        throw new Exception("لم يتم العثور على اتصال قاعدة البيانات \$conn.");
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['user'])) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => 'غير مصرح لك بالوصول. الرجاء تسجيل الدخول.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $user = $_SESSION['user'];
    $isAdmin = $user['IsAdmin'] == 1;

    // ===== قراءة المعاملات =====
    $searchStartDate = $_GET['start_date'] ?? null;
    $searchEndDate   = $_GET['end_date']   ?? null;
    if (!$searchStartDate || !$searchEndDate) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => 'تاريخا البداية والنهاية مطلوبان.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // فلاتر اختيارية
    $employeeName = isset($_GET['employee_name']) ? array_filter(array_map('trim', explode(',', (string)$_GET['employee_name']))) : [];
    $sectorName = isset($_GET['sector_name']) ? array_filter(array_map('trim', explode(',', (string)$_GET['sector_name']))) : [];
    $employeeId = isset($_GET['employee_id']) ? array_filter(array_map('trim', explode(',', (string)$_GET['employee_id']))) : [];
    $shiftType = isset($_GET['shift_type']) ? array_filter(array_map('trim', explode(',', (string)$_GET['shift_type']))) : [];
    $workStatus = isset($_GET['work_status']) ? array_filter(array_map('trim', explode(',', (string)$_GET['work_status']))) : [];

    // للمستخدمين غير الإداريين، قصر على بياناتهم
    if (!$isAdmin) {
        $employeeName = [$user['EmpName']];
        $sectorName = [$user['SectorName']];
        $employeeId = [$user['EmpID']];
    }

    $d1 = DateTime::createFromFormat('Y-m-d', $searchStartDate);
    $d2 = DateTime::createFromFormat('Y-m-d', $searchEndDate);
    if (!$d1 || !$d2) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => 'صيغة التاريخ غير صحيحة.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ===== استعلام الموظفين + المديرين (الآن SectorManagerID varchar) =====
    $sql = "
        SELECT 
            u.EmpName,
            u.EmpID,
            u.Department,
            u.Division,
            u.SectorID,
            s.SectorName,
            s.StartDate,
            s.WorkMod,
            s.ShiftPattern,
            s.FirstShift,
            s.ShiftHours,
            s.SectorManagerID
        FROM Users u
        JOIN tbl_Sectors s ON u.SectorID = s.SectorID
    ";

    $conditions   = ["u.Active = 1"];
    $params       = [];
    $types        = "";

    if (!empty($employeeName)) {
        $placeholders = implode(',', array_fill(0, count($employeeName), '?'));
        $conditions[] = "u.EmpName IN ($placeholders)";
        foreach ($employeeName as $name) { $params[] = $name; $types .= "s"; }
    }
    if (!empty($sectorName)) {
        $placeholders = implode(',', array_fill(0, count($sectorName), '?'));
        $conditions[] = "s.SectorName IN ($placeholders)";
        foreach ($sectorName as $sn) { $params[] = $sn; $types .= "s"; }
    }
    if (!empty($employeeId)) {
        $placeholders = implode(',', array_fill(0, count($employeeId), '?'));
        $conditions[] = "u.EmpID IN ($placeholders)";
        foreach ($employeeId as $id) { $params[] = (int)$id; $types .= "i"; }
    }

    if ($conditions) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $stmt = $conn->prepare($sql);
    if ($params) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    $employees = $res->fetch_all(MYSQLI_ASSOC);

    // جلب أسماء المديرين المتعددين
    $managerNamesById = [];
    if (!empty($employees)) {
        $allManagerIds = [];
        foreach ($employees as $emp) {
            if ($emp['SectorManagerID']) {
                $ids = array_filter(array_map('trim', explode(',', $emp['SectorManagerID'])));
                $allManagerIds = array_merge($allManagerIds, $ids);
            }
        }
        $allManagerIds = array_unique($allManagerIds);
        if (!empty($allManagerIds)) {
            $placeholders = implode(',', array_fill(0, count($allManagerIds), '?'));
            $managerSql = "SELECT EmpID, EmpName FROM Users WHERE EmpID IN ($placeholders)";
            $managerStmt = $conn->prepare($managerSql);
            $managerTypes = str_repeat('i', count($allManagerIds));
            $managerStmt->bind_param($managerTypes, ...$allManagerIds);
            $managerStmt->execute();
            $managerRes = $managerStmt->get_result();
            while ($row = $managerRes->fetch_assoc()) {
                $managerNamesById[(int)$row['EmpID']] = $row['EmpName'];
            }
        }
    }

    // ===== إجازات معتمدة ضمن الفترة =====
    $leave_sql = "
        SELECT EmpID, LeaveType, StartDate, EndDate 
        FROM LeaveRequests
        WHERE Status = 'approved' AND (
            (StartDate <= ? AND EndDate >= ?) OR 
            (StartDate >= ? AND StartDate <= ?)
        )
    ";
    $leave_stmt = $conn->prepare($leave_sql);
    $leave_stmt->bind_param("ssss", $searchEndDate, $searchStartDate, $searchStartDate, $searchEndDate);
    $leave_stmt->execute();
    $leave_result = $leave_stmt->get_result();
    $leaves = [];
    while ($row = $leave_result->fetch_assoc()) {
        $eid = (int)$row['EmpID'];
        if (!isset($leaves[$eid])) $leaves[$eid] = [];
        $cur = new DateTime($row['StartDate']);
        $end = new DateTime($row['EndDate']);
        while ($cur <= $end) {
            $leaves[$eid][$cur->format('Y-m-d')] = $row['LeaveType'];
            $cur->add(new DateInterval('P1D'));
        }
    }

    // ===== دوال مساعدة =====
    function getArabicDayName(string $dayEn): string {
        static $days = [
            'Sunday'=>'الأحد','Monday'=>'الاثنين','Tuesday'=>'الثلاثاء',
            'Wednesday'=>'الأربعاء','Thursday'=>'الخميس','Friday'=>'الجمعة','Saturday'=>'السبت'
        ];
        return $days[$dayEn] ?? $dayEn;
    }

    function getArabicLeaveType(string $t): string {
        static $types = [
            'annual'=>'سنوية','sick'=>'مرضية','emergency'=>'طارئة','compensatory'=>'تعويضية'
        ];
        return $types[$t] ?? $t;
    }

    function mapShiftToArabic(string $shift): string {
        return $shift === 'Morning' ? 'صباحي' : ($shift === 'Evening' ? 'مسائي' : $shift);
    }

    // حساب المدير الحالي بناءً على عدد المديرين ودورات WorkMod
    function getCurrentManager(array $managerIds, string $sectorStartDate, string $currentDateStr, string $workMod): ?array {
        if (empty($managerIds)) return null;

        $numManagers = count($managerIds);
        if ($numManagers === 1) {
            return ['id' => $managerIds[0], 'name' => '']; // سيتم ملء الاسم لاحقاً
        }

        // حساب الدورة بناءً على WorkMod (مثل 6,4 -> work=6, rest=4)
        if (!preg_match('/^(\d+),(\d+)$/', $workMod, $wm)) {
            $workDays = 5; $restDays = 2;
        } else {
            $workDays = (int)$wm[1]; $restDays = (int)$wm[2];
        }
        $cycleLen = $workDays + $restDays;

        $sectorStart = new DateTime($sectorStartDate);
        $currentDate = new DateTime($currentDateStr);
        $diffDays = $sectorStart->diff($currentDate)->days;

        $cycleNumber = floor($diffDays / $cycleLen);
        $managerIndex = $cycleNumber % $numManagers;

        return ['id' => $managerIds[$managerIndex], 'name' => '']; // سيتم ملء الاسم لاحقاً
    }

    // حساب أيام العمل/الراحة والورديات + تطبيق الفلاتر
    function calculateWorkAndShiftDetails(array $employee, string $searchStartDate, string $searchEndDate, array $employeeLeaves, array $shiftType, array $workStatus): array {
        $workDetails = ['work_days'=>0,'rest_days'=>0,'leave_days'=>0,'morning_shifts'=>0,'evening_shifts'=>0,'daily_schedule'=>[]];

        $sectorStartDate = $employee['StartDate'];
        $workMod = $employee['WorkMod'];
        $shiftPattern = $employee['ShiftPattern'];
        $firstShift = $employee['FirstShift'];

        if (!preg_match('/^(\d+),(\d+)$/', $workMod, $wm)) { $w=5; $r=2; } else { $w=(int)$wm[1]; $r=(int)$wm[2]; }
        $cycleLen = $w + $r;

        if (!preg_match('/^(\d+),(\d+)$/', $shiftPattern, $sm)) { $morC=1; $eveC=1; } else { $morC=(int)$sm[1]; $eveC=(int)$sm[2]; }
        $shiftWorkDaysLen = ($morC + $eveC) * $w;

        $sectorStart = new DateTime($sectorStartDate);
        $searchStart = new DateTime($searchStartDate);
        $searchEnd   = new DateTime($searchEndDate);

        $period = new DatePeriod($searchStart, new DateInterval('P1D'), (clone $searchEnd)->modify('+1 day'));

        // كم يوم عمل تم قبل searchStart (لحساب موضعنا داخل نمط الورديات)
        $totalWorkDaysSoFar = 0;
        if ($searchStart > $sectorStart) {
            $tmp = clone $sectorStart;
            $prePeriod = new DatePeriod($tmp, new DateInterval('P1D'), $searchStart);
            foreach ($prePeriod as $d) {
                $key = $d->format('Y-m-d');
                if (isset($employeeLeaves[$key])) continue;
                $dayInCycle = $sectorStart->diff($d)->days % $cycleLen;
                if ($dayInCycle < $w) { $totalWorkDaysSoFar++; }
            }
        }

        foreach ($period as $currentDate) {
            if ($currentDate < $sectorStart) continue;

            $dateKey = $currentDate->format('Y-m-d');
            $isWorking = false;
            $isLeave = false;
            $shiftLabel = 'راحة';

            if (isset($employeeLeaves[$dateKey])) {
                $isLeave = true;
                $workDetails['leave_days']++;
                $shiftLabel = 'إجازة: ' . getArabicLeaveType($employeeLeaves[$dateKey]);
            } else {
                $dayInCycle = $sectorStart->diff($currentDate)->days % $cycleLen;
                if ($dayInCycle < $w) {
                    $isWorking = true;
                    $workDetails['work_days']++;
                    $totalWorkDaysSoFar++;
                    $posInShift = ($totalWorkDaysSoFar - 1) % $shiftWorkDaysLen;

                    if ($firstShift === 'Morning') {
                        $firstBlock = $morC * $w;
                        if ($posInShift < $firstBlock) {
                            $shiftLabel = 'صباحي'; $workDetails['morning_shifts']++;
                        } else {
                            $shiftLabel = 'مسائي'; $workDetails['evening_shifts']++;
                        }
                    } else { // Evening أولاً
                        $firstBlock = $eveC * $w;
                        if ($posInShift < $firstBlock) {
                            $shiftLabel = 'مسائي'; $workDetails['evening_shifts']++;
                        } else {
                            $shiftLabel = 'صباحي'; $workDetails['morning_shifts']++;
                        }
                    }
                } else {
                    $workDetails['rest_days']++;
                }
            }

            // تطبيق فلاتر الواجهة (اختياري)
            $shiftOk = empty($shiftType) || in_array($shiftLabel, $shiftType);
            $statusOk = empty($workStatus) ||
                        (in_array('Work', $workStatus) && $isWorking) ||
                        (in_array('Rest', $workStatus) && !$isWorking && !$isLeave) ||
                        (in_array('Leave', $workStatus) && $isLeave);

            if ($shiftOk && $statusOk) {
                $workDetails['daily_schedule'][] = [
                    'date'       => $dateKey,
                    'day_name'   => getArabicDayName($currentDate->format('l')),
                    'is_working' => $isWorking,
                    'is_on_leave'=> $isLeave,
                    'shift'      => $shiftLabel
                ];
            }
        }

        return $workDetails;
    }

    // ===== تجميع حسب القطاع + حساب المديرين المتعددين بناءً على الدورات =====
    $bySector = [];
    foreach ($employees as $e) {
        $bySector[$e['SectorName']][] = $e;
    }

    $allEmployees = [];
    $dailySchedules = [];

    foreach ($bySector as $sectorName => $emps) {
        $managerIds = [];
        if (!empty($emps[0]['SectorManagerID'])) {
            $managerIds = array_filter(array_map('intval', explode(',', $emps[0]['SectorManagerID'])));
        }

        foreach ($emps as &$empRef) {
            $eid = (int)$empRef['EmpID'];
            $empLeaves = $leaves[$eid] ?? [];
            $wd = calculateWorkAndShiftDetails($empRef, $searchStartDate, $searchEndDate, $empLeaves, $shiftType, $workStatus);

            $empRef['work_days']      = $wd['work_days'];
            $empRef['rest_days']      = $wd['rest_days'];
            $empRef['leave_days']     = $wd['leave_days'];
            $empRef['morning_shifts'] = $wd['morning_shifts'];
            $empRef['evening_shifts'] = $wd['evening_shifts'];
            $empRef['SectorManagerIDs'] = $managerIds; // إضافة IDs
            $empRef['SectorManagerNames'] = implode(', ', array_map(function($id) use ($managerNamesById) {
                return $managerNamesById[$id] ?? '';
            }, $managerIds));

            // لكل يوم، حساب المدير الحالي
            foreach ($wd['daily_schedule'] as &$day) {
                $manager = getCurrentManager($managerIds, $empRef['StartDate'], $day['date'], $empRef['WorkMod']);
                $day['current_manager_name'] = $manager ? ($managerNamesById[$manager['id']] ?? '') : '';
            }
            unset($day);

            $allEmployees[] = $empRef;
            $dailySchedules[$eid] = $wd['daily_schedule'];
        }
        unset($empRef);
    }

    // ===== إخراج JSON نظيف فقط =====
    $payload = ['employees' => $allEmployees, 'daily_schedules' => $dailySchedules];
    if (ob_get_length()) ob_clean();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;

} catch (Throwable $e) {
    // تنظيف أي خرج سابق ثم إعادة خطأ JSON
    if (ob_get_length()) ob_clean();
    echo json_encode(['error' => 'خطأ في الخادم: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>