<?php
// get_doctors.php   — Optimized for clinic doctors only

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function json_exit(array $data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

set_error_handler(function($sev,$msg,$file,$line){ throw new ErrorException($msg,0,$sev,$file,$line); });

try {
    // Load DB connection
    $dbPaths = [
        __DIR__ . '/db.php',
        __DIR__ . '/../db.php',
        __DIR__ . '/../../db.php'
    ];
    $dbFound = false;
    foreach ($dbPaths as $p) {
        if (file_exists($p)) {
            require_once $p;
            $dbFound = true;
            break;
        }
    }
    if (!$dbFound) json_exit(['success'=>false,'message'=>"db.php not found"], 500);
    if (!isset($conn) || !($conn instanceof mysqli))
        json_exit(['success'=>false,'message'=>'DB connection not found'], 500);

    // Optional search
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $debug = isset($_GET['debug']) && ($_GET['debug']=='1' || $_GET['debug']==='true');

    // Candidate user tables
    $candidates = ['tbl_users','tbl_Employees','employees','users','tbl_staff','staff','tbl_employees'];
    $foundTable = null;

    foreach ($candidates as $t) {
        $res = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($t)."'");
        if ($res && $res->num_rows > 0) { $foundTable = $t; break; }
    }

    if (!$foundTable) {
        $sql = "SELECT TABLE_NAME FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND (TABLE_NAME LIKE '%user%' OR TABLE_NAME LIKE '%emp%' OR TABLE_NAME LIKE '%staff%')
                LIMIT 1";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0)
            $foundTable = $res->fetch_row()[0];
    }

    if (!$foundTable)
        json_exit(['success'=>false,'message'=>'Users table not found'], 500);

    // Fetch columns
    $cols = [];
    $colsRes = $conn->query("SHOW COLUMNS FROM `$foundTable`");
    while ($c = $colsRes->fetch_assoc()) $cols[] = $c['Field'];

    // Detect important columns
    $idCol = in_array('EmpID', $cols) ? 'EmpID' : (in_array('ID', $cols) ? 'ID' : $cols[0]);
    $nameCol = in_array('EmpName', $cols) ? 'EmpName' : (in_array('FullName', $cols) ? 'FullName' : null);

    $clinicCol = in_array('clinic_rights', $cols) ? 'clinic_rights' : null;
    $statusCol = in_array('status', $cols) ? 'status' : null;
    $deletedCol = in_array('deleted', $cols) ? 'deleted' : null;

    if (!$clinicCol)
        json_exit(['success'=>false,'message'=>'clinic_rights column not found'], 500);

    // Build SELECT
    $sql = "SELECT 
                `$idCol` AS EmpID,
                `$nameCol` AS EmpName
            FROM `$foundTable`";

    // Always enforce doctors only
    $where = [];
    $where[] = "`$clinicCol` = 1";   // Required

    if ($statusCol)
        $where[] = "`$statusCol` = 'active'";

    if ($deletedCol)
        $where[] = "`$deletedCol` = 0";

    // Optional search
    if ($q !== '') {
        $qEsc = $conn->real_escape_string($q);
        $where[] = "(`$nameCol` LIKE '%$qEsc%' OR `$idCol` = '$qEsc')";
    }

    $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY `$nameCol` ASC LIMIT 1000";

    if ($debug) $debugInfo = ['table'=>$foundTable,'sql'=>$sql];

    $res = $conn->query($sql);
    if (!$res) json_exit(['success'=>false,'message'=>$conn->error,'_sql'=>$sql],500);

    $data = [];
    while ($row = $res->fetch_assoc()) $data[] = $row;

    $response = [
        'success'=>true,
        'count'=>count($data),
        'data'=>$data
    ];
    if ($debug) $response['_debug']=$debugInfo;

    json_exit($response);

} catch (Throwable $e) {
    json_exit(['success'=>false,'message'=>$e->getMessage()],500);
}
