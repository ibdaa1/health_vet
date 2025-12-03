<?php
// get_data.php
// Generic data provider with safe whitelisting and simple search/pagination
// Usage examples:
//  - get_data.php?resource=adoptions&page=1&per_page=50
//  - get_data.php?resource=adoption_applications&q=ahmed
//  - get_data.php?resource=visitor_interactions&single=1&id=12
//  - get_data.php?table=tbl_animals&page=1&per_page=200&q=abc  (fallback to explicit table name if allowed)
//
// Requirements:
//  - include db.php that provides $conn (mysqli)
//  - return JSON: { success:true, data:[...], total: N } or { success:false, message: '...' }

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';    // must provide $conn (mysqli)
require_once __DIR__ . '/config.php'; // optional, for logging settings

function respond($obj) {
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

function log_debug($msg) {
    // optional debug log (file controlled by hosting permissions)
    $f = __DIR__ . '/get_data_debug.log';
    @file_put_contents($f, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// whitelist of logical resources -> actual DB table name and searchable columns
$resources = [
    // existing (examples)
    'adoptions' => ['table' => 'tbl_adoptions', 'search' => ['adopter_name','adopter_phone','animal_code','adoption_id','adopter_email']],
    'animals' => ['table' => 'tbl_animals', 'search' => ['animal_code','animal_name','owner_name','animal_type']],
    'visits' => ['table' => 'visits', 'search' => ['animal_code','doctor_name','visit_type']], // adjust if real table differs
    // newly requested
    'adoption_applications' => ['table' => 'adoption_applications', 'search' => ['full_name','phone','email','submission_date']],
    'visitor_interactions' => ['table' => 'visitor_interactions', 'search' => ['visitor_full_name','visitor_contact_number','entity']],
    'complaints' => ['table' => 'Complaints', 'search' => ['ComplaintNo','ComplainantName','ComplainantPhone','ComplaintStatus']],
    // other resources you may want to expose:
    'products' => ['table' => 'tbl_products', 'search' => ['Product_Code','Name_AR','Name_EN']],
];

// allow direct table param but only for specific safe table names (map of allowed)
$allowedTables = array_column($resources, 'table');
$allowedTables = array_combine($allowedTables, $allowedTables);

// parse params
$resource = trim($_GET['resource'] ?? $_GET['r'] ?? '');
$tableParam = trim($_GET['table'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = (int)($_GET['per_page'] ?? ($_GET['limit'] ?? 100));
if ($per_page <= 0) $per_page = 100;
$single = isset($_GET['single']) ? (int)$_GET['single'] : 0;
$id = $_GET['id'] ?? ($_GET['record_id'] ?? null);
$idField = $_GET['idField'] ?? null; // optional id field name
$orderBy = $_GET['order_by'] ?? $_GET['sort'] ?? ''; // optional

// determine table to query
$table = null;
$searchFields = [];

if ($resource && isset($resources[$resource])) {
    $table = $resources[$resource]['table'];
    $searchFields = $resources[$resource]['search'];
} elseif ($tableParam) {
    // explicit table requested - only allow if in allowed list
    if (isset($allowedTables[$tableParam])) {
        $table = $tableParam;
        // no predefined search fields -> will search common columns
    } else {
        respond(['success'=>false,'message'=>'Requested table not allowed']);
    }
} else {
    respond(['success'=>false,'message'=>'No resource/table specified']);
}

$offset = ($page - 1) * $per_page;

// build base SQL safely (table name from whitelist only)
$tbl = $conn->real_escape_string($table);

// when single requested and id provided, fetch single row by id field
if ($single && $id !== null) {
    // decide id field
    $idFieldFinal = $idField ?: (strpos($table, 'adoptions') !== false ? 'adoption_id' : (strpos($table, 'Complaints') !== false ? 'ComplaintID' : 'id'));
    // safe: idField must be alphanumeric + underscore
    if (!preg_match('/^[A-Za-z0-9_]+$/', $idFieldFinal)) $idFieldFinal = 'id';
    $sql = "SELECT * FROM `{$tbl}` WHERE `{$idFieldFinal}` = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        log_debug("prepare failed single: " . $conn->error);
        respond(['success'=>false,'message'=>'DB prepare error']);
    }
    // bind id as string or int
    if (is_numeric($id)) $stmt->bind_param('i', $id);
    else $stmt->bind_param('s', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    respond(['success'=>true,'data'=> $row ? [$row] : [], 'count' => $row ? 1 : 0]);
}

// build WHERE clauses for search
$where = [];
$params = [];
$types = '';

if ($q !== '') {
    // if resource has explicit search fields use them, otherwise try common fields
    if (empty($searchFields)) {
        $searchFields = ['name','title','animal_code','animal_name','full_name','phone','email','ComplaintNo','ComplainantName'];
    }
    $qLike = '%' . $q . '%';
    $sub = [];
    foreach ($searchFields as $f) {
        // ensure field name safe
        if (!preg_match('/^[A-Za-z0-9_]+$/', $f)) continue;
        $sub[] = "`$f` LIKE ?";
        $params[] = $qLike;
        $types .= 's';
    }
    if ($sub) $where[] = '(' . implode(' OR ', $sub) . ')';
}

// If specific id provided (non-single) allow filter by id field
if ($id !== null) {
    $idFieldFinal = $idField ?: 'id';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $idFieldFinal)) $idFieldFinal = 'id';
    $where[] = "`{$idFieldFinal}` = ?";
    if (is_numeric($id)) { $params[] = (int)$id; $types .= 'i'; }
    else { $params[] = (string)$id; $types .= 's'; }
}

// assemble SQL
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$order_sql = '';
if ($orderBy && preg_match('/^[A-Za-z0-9_,\s`]+$/', $orderBy)) {
    $order_sql = "ORDER BY $orderBy";
} else {
    // sensible default ordering per table
    if (stripos($tbl, 'adoptions') !== false) $order_sql = "ORDER BY adoption_date DESC";
    elseif (stripos($tbl, 'adoption_applications') !== false) $order_sql = "ORDER BY submission_date DESC";
    elseif (stripos($tbl, 'visitor_interactions') !== false) $order_sql = "ORDER BY visit_date DESC";
    elseif (stripos($tbl, 'Complaints') !== false) $order_sql = "ORDER BY ComplaintDate DESC";
    else $order_sql = "ORDER BY id DESC";
}

// count total for pagination
$count_sql = "SELECT COUNT(*) AS total FROM `{$tbl}` $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt === false) {
    log_debug("count prepare failed: " . $conn->error . " sql=" . $count_sql);
    respond(['success'=>false,'message'=>'DB prepare error']);
}
if ($types) {
    // bind params for count
    $bind_names = [];
    $bind_names[] = $types;
    for ($i=0;$i<count($params);$i++) $bind_names[] = &$params[$i];
    // call_user_func_array requires references
    call_user_func_array([$count_stmt, 'bind_param'], $bind_names);
}
$count_stmt->execute();
$count_res = $count_stmt->get_result();
$total = ($count_res && $count_res->fetch_assoc()) ? (int)$count_res->fetch_assoc()['total'] : 0;
// Note: above fetch_assoc() consumed row; to avoid complication, re-run simple query for total
$count_stmt->close();
// safer total fetch:
$total = 0;
$res2 = $conn->query($count_sql);
if ($res2) {
    $r = $res2->fetch_assoc();
    $total = (int)($r['total'] ?? 0);
}

// main select with limit
$sql = "SELECT * FROM `{$tbl}` $where_sql $order_sql LIMIT ?, ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    log_debug("select prepare failed: " . $conn->error . " sql=" . $sql);
    respond(['success'=>false,'message'=>'DB prepare error']);
}

// bind params (existing search/id params) then offset, limit
$bindParams = $params; // copy
$types_for_bind = $types;
$bindParams[] = $offset;
$bindParams[] = $per_page;
$types_for_bind .= 'ii';

// prepare binding
if ($bindParams) {
    $bind_names = [];
    $bind_names[] = $types_for_bind;
    for ($i=0;$i<count($bindParams);$i++) $bind_names[] = &$bindParams[$i];
    call_user_func_array([$stmt, 'bind_param'], $bind_names);
}

$stmt->execute();
$result = $stmt->get_result();

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) $data[] = $row;
}
$stmt->close();

// respond
respond(['success'=>true,'data'=>$data,'total'=>$total,'page'=>$page,'per_page'=>$per_page]);