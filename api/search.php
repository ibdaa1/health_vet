<?php
// search.php
// Unified search across products, variants, visits, doctors.
// GET: q (required), type (products|variants|visits|doctors|all), limit
// Response: { success:true, data: { products:[], variants:[], visits:[], doctors:[] } }

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

function json_exit(array $d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/* load DB */
$paths = [ __DIR__.'/db.php', __DIR__.'/../db.php', __DIR__.'/../../db.php' ];
foreach ($paths as $p) if (file_exists($p)) { require_once $p; break; }
if (!isset($conn) || !($conn instanceof mysqli)) json_exit(['success'=>false,'message'=>'DB connection not found'],500);

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$limit = isset($_GET['limit']) ? max(1,intval($_GET['limit'])) : 50;
if ($q === '') json_exit(['success'=>false,'message'=>'q parameter required'],400);

$out = ['products'=>[], 'variants'=>[], 'visits'=>[], 'doctors'=>[]];

try {
    $like = '%' . $conn->real_escape_string($q) . '%';

    if ($type === 'all' || $type === 'products') {
        $sql = "SELECT Product_ID, Product_Code, Name_AR, Name_EN, Supplier FROM tbl_Products WHERE Name_AR LIKE ? OR Name_EN LIKE ? OR Product_Code LIKE ? LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssi', $like, $like, $like, $limit);
        $stmt->execute(); $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $out['products'][] = $r;
        $stmt->close();
    }

    if ($type === 'all' || $type === 'variants') {
        $sql = "SELECT VariantID, ProductID, SKU, OptionIDs, Quantity, QuantityBase FROM tbl_ProductVariants WHERE SKU LIKE ? OR OptionIDs LIKE ? LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssi', $like, $like, $limit);
        $stmt->execute(); $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $out['variants'][] = $r;
        $stmt->close();
    }

    if ($type === 'all' || $type === 'visits') {
        $sql = "SELECT id, animal_code, visit_date, doctor_empid, visit_type FROM tbl_visits WHERE animal_code LIKE ? OR symptoms LIKE ? OR notes LIKE ? LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssi', $like, $like, $like, $limit);
        $stmt->execute(); $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $out['visits'][] = $r;
        $stmt->close();
    }

    if ($type === 'all' || $type === 'doctors') {
        // Use get_doctors.php table if available; fallback to distinct doctor_empid values from tbl_visits
        $found = false;
        $res = $conn->query("SHOW TABLES LIKE 'tbl_Employees'");
        if ($res && $res->num_rows) $found = 'tbl_Employees';
        if ($found) {
            $sql = "SELECT EmpID, COALESCE(Name, EmpName, FullName, '') AS Name FROM `".$found."` WHERE (Name LIKE ? OR EmpID = ?) LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssi', $like, $q, $limit);
            $stmt->execute(); $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $out['doctors'][] = $row;
            $stmt->close();
        } else {
            $sql = "SELECT DISTINCT doctor_empid FROM tbl_visits WHERE doctor_empid LIKE ? LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('si', $like, $limit);
            $stmt->execute(); $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $out['doctors'][] = $row;
            $stmt->close();
        }
    }

    json_exit(['success'=>true,'data'=>$out],200);
} catch (Throwable $ex) {
    json_exit(['success'=>false,'message'=>$ex->getMessage()],500);
}