<?php
// dashboard_stats.php
// Complete API returning dashboard statistics for Health Vet.
// Fixed: avoid "Cannot use object of type stdClass as array" by using arrays consistently.
//
// Features:
// - KPIs (visits, doctors, products, variants, dispensed items, stock movements)
// - Low-stock variants (compares MinQuantity to Quantity in packs)
// - Recent visits (raw tbl_visits rows with doctor_empid numeric)
// - Top doctors (doctor_empid + visits count)
// - Most dispensed products (with product names)
// - Dispensed over time series (last N days or between start/end)
// - Distributions and DISTINCT lists for filters (visit_type, CaseStatus, AnimalStatus, distinct_doctor_ids)
//
// Usage: GET /health_vet/api/dashboard_stats.php?recent=12&top=10&low=100&days=30
// Optional: start_date=YYYY-MM-DD & end_date=YYYY-MM-DD to override days range.
//
// IMPORTANT: Back up the existing file before replacing it.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

function json_exit(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/* Load DB connection from likely locations */
$dbPaths = [
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
    __DIR__ . '/../../../db.php'
];
$dbLoaded = false;
foreach ($dbPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $dbLoaded = true;
        break;
    }
}
if (!$dbLoaded) json_exit(['success' => false, 'message' => 'db.php not found in expected locations'], 500);
if (!isset($conn) || !($conn instanceof mysqli)) json_exit(['success' => false, 'message' => 'DB connection ($conn) not available'], 500);

/* helpers */
function get_int_param(string $name, int $default): int {
    if (!isset($_GET[$name]) || $_GET[$name] === '') return $default;
    $v = intval($_GET[$name]);
    return $v > 0 ? $v : $default;
}
function get_str_param(string $name, ?string $default = null): ?string {
    if (!isset($_GET[$name]) || $_GET[$name] === '') return $default;
    return trim((string)$_GET[$name]);
}

$limit_recent = get_int_param('recent', 12);
$limit_top = get_int_param('top', 10);
$limit_low = get_int_param('low', 100);
$days = get_int_param('days', 30);
$start_date = get_str_param('start_date', null);
$end_date = get_str_param('end_date', null);

try {
    // Prepare output structure (use arrays everywhere)
    $out = [
        'counts' => [],
        'low_stock_products' => [],
        'recent_visits' => [],
        'top_doctors' => [],
        'most_dispensed_products' => [],
        'dispensed_over_time' => [],
        'visit_type_distribution' => [],
        'case_status_distribution' => [],
        'animal_status_distribution' => [],
        'distinct_visit_types' => [],
        'distinct_case_statuses' => [],
        'distinct_animal_statuses' => [],
        'distinct_doctor_ids' => []
    ];

    // KPIs
    $q = $conn->query("SELECT COUNT(*) AS cnt FROM `tbl_visits`");
    $out['counts']['visits'] = ($q && ($r = $q->fetch_assoc())) ? intval($r['cnt']) : 0;

    $q = $conn->query("SELECT COUNT(*) AS cnt FROM `tbl_VisitProducts`");
    $out['counts']['dispensed_items'] = ($q && ($r = $q->fetch_assoc())) ? intval($r['cnt']) : 0;

    $q = $conn->query("SELECT COUNT(*) AS cnt FROM `tbl_ProductVariants`");
    $out['counts']['variants'] = ($q && ($r = $q->fetch_assoc())) ? intval($r['cnt']) : 0;

    $q = $conn->query("SELECT COUNT(*) AS cnt FROM `tbl_Products`");
    $out['counts']['products'] = ($q && ($r = $q->fetch_assoc())) ? intval($r['cnt']) : 0;

    $q = $conn->query("SELECT COUNT(*) AS cnt FROM `tbl_StockMovements`");
    $out['counts']['stock_movements'] = ($q && ($r = $q->fetch_assoc())) ? intval($r['cnt']) : 0;

    // distinct doctor count (ignore NULL/0)
    $q = $conn->query("SELECT COUNT(DISTINCT CASE WHEN `doctor_empid` IS NULL OR `doctor_empid` = 0 THEN NULL ELSE `doctor_empid` END) AS cnt FROM `tbl_visits`");
    $out['counts']['doctors'] = ($q && ($r = $q->fetch_assoc())) ? intval($r['cnt']) : 0;

    // LOW STOCK: compare MinQuantity to QuantityPacks
    $sql_low = "
      SELECT pv.VariantID, pv.ProductID, pv.SKU, pv.OptionIDs, pv.Quantity, pv.UnitsPerPack, pv.QuantityBase, pv.MinQuantity, pv.ProductImage,
             COALESCE(NULLIF(p.Name_AR,''), NULLIF(p.Name_EN,''), p.Product_Code, '') AS ProductName,
             COALESCE(NULLIF(pv.Quantity,0), (CASE WHEN pv.UnitsPerPack IS NOT NULL AND pv.UnitsPerPack <> 0 THEN pv.QuantityBase / pv.UnitsPerPack ELSE NULL END)) AS QuantityPacks
      FROM `tbl_ProductVariants` pv
      LEFT JOIN `tbl_Products` p ON p.Product_ID = pv.ProductID
      WHERE pv.MinQuantity IS NOT NULL
        AND COALESCE(NULLIF(pv.Quantity,0), (CASE WHEN pv.UnitsPerPack IS NOT NULL AND pv.UnitsPerPack <> 0 THEN pv.QuantityBase / pv.UnitsPerPack ELSE NULL END)) <= pv.MinQuantity
      ORDER BY QuantityPacks ASC
      LIMIT " . intval($limit_low) . "
    ";
    $res = $conn->query($sql_low);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['QuantityPacks'] = isset($r['QuantityPacks']) ? round(floatval($r['QuantityPacks']), 4) : null;
            $out['low_stock_products'][] = $r;
        }
        $res->free();
    }

    // Recent visits
    $stmt = $conn->prepare("SELECT id, animal_code, visit_date, doctor_empid, visit_type, symptoms, notes, CaseStatus, AnimalStatus, created_at FROM `tbl_visits` ORDER BY visit_date DESC LIMIT ?");
    if ($stmt) {
        $stmt->bind_param('i', $limit_recent);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $out['recent_visits'][] = $row;
        $stmt->close();
    }

    // Top doctors (by id)
    $stmt = $conn->prepare("SELECT doctor_empid, COUNT(*) AS visits FROM `tbl_visits` WHERE doctor_empid IS NOT NULL AND doctor_empid <> 0 GROUP BY doctor_empid ORDER BY visits DESC LIMIT ?");
    if ($stmt) {
        $stmt->bind_param('i', $limit_top);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $out['top_doctors'][] = $row;
        $stmt->close();
    }

    // Most dispensed products
    $sql_most = "
      SELECT vp.ProductID, COALESCE(p.Name_AR, p.Name_EN, p.Product_Code, '') AS product_name,
             SUM(vp.BaseQuantity) AS total_base, SUM(vp.Quantity) AS total_packs
      FROM `tbl_VisitProducts` vp
      LEFT JOIN `tbl_Products` p ON p.Product_ID = vp.ProductID
      GROUP BY vp.ProductID
      ORDER BY total_base DESC
      LIMIT " . intval($limit_top);
    $res = $conn->query($sql_most);
    if ($res) {
        while ($r = $res->fetch_assoc()) $out['most_dispensed_products'][] = $r;
        $res->free();
    }

    // Dispensed over time (series)
    if ($start_date && $end_date) {
        $sd = $conn->real_escape_string($start_date);
        $ed = $conn->real_escape_string($end_date);
        $sql_series = "SELECT DATE(CreatedAt) AS d, SUM(BaseQuantity) AS total_base FROM `tbl_VisitProducts` WHERE CreatedAt BETWEEN '{$sd}' AND '{$ed}' GROUP BY DATE(CreatedAt) ORDER BY d ASC";
        $res = $conn->query($sql_series);
        if ($res) {
            while ($r = $res->fetch_assoc()) $out['dispensed_over_time'][] = ['date' => $r['d'], 'total_base' => floatval($r['total_base'])];
            $res->free();
        }
    } else {
        $sql_series = "SELECT DATE(CreatedAt) AS d, SUM(BaseQuantity) AS total_base FROM `tbl_VisitProducts` WHERE CreatedAt >= DATE_SUB(CURDATE(), INTERVAL " . intval($days) . " DAY) GROUP BY DATE(CreatedAt) ORDER BY d ASC";
        $res = $conn->query($sql_series);
        $map = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $map[$r['d']] = floatval($r['total_base']);
            $res->free();
        }
        $period = new DatePeriod(new DateTime('-' . intval($days) . ' days'), new DateInterval('P1D'), new DateTime('+1 day'));
        foreach ($period as $dt) {
            $d = $dt->format('Y-m-d');
            $out['dispensed_over_time'][] = ['date' => $d, 'total_base' => $map[$d] ?? 0.0];
        }
    }

    // Distributions
    $res = $conn->query("SELECT visit_type, COUNT(*) AS cnt FROM `tbl_visits` GROUP BY visit_type ORDER BY cnt DESC");
    if ($res) { while ($r = $res->fetch_assoc()) $out['visit_type_distribution'][] = $r; $res->free(); }

    $res = $conn->query("SELECT CaseStatus, COUNT(*) AS cnt FROM `tbl_visits` GROUP BY CaseStatus ORDER BY cnt DESC");
    if ($res) { while ($r = $res->fetch_assoc()) $out['case_status_distribution'][] = $r; $res->free(); }

    $res = $conn->query("SELECT AnimalStatus, COUNT(*) AS cnt FROM `tbl_visits` GROUP BY AnimalStatus ORDER BY cnt DESC");
    if ($res) { while ($r = $res->fetch_assoc()) $out['animal_status_distribution'][] = $r; $res->free(); }

    // DISTINCT lists for filters
    $res = $conn->query("SELECT DISTINCT visit_type FROM `tbl_visits` WHERE visit_type IS NOT NULL AND visit_type <> '' ORDER BY visit_type");
    if ($res) { while ($r = $res->fetch_assoc()) $out['distinct_visit_types'][] = $r['visit_type']; $res->free(); }

    $res = $conn->query("SELECT DISTINCT CaseStatus FROM `tbl_visits` WHERE CaseStatus IS NOT NULL AND CaseStatus <> '' ORDER BY CaseStatus");
    if ($res) { while ($r = $res->fetch_assoc()) $out['distinct_case_statuses'][] = $r['CaseStatus']; $res->free(); }

    $res = $conn->query("SELECT DISTINCT AnimalStatus FROM `tbl_visits` WHERE AnimalStatus IS NOT NULL AND AnimalStatus <> '' ORDER BY AnimalStatus");
    if ($res) { while ($r = $res->fetch_assoc()) $out['distinct_animal_statuses'][] = $r['AnimalStatus']; $res->free(); }

    // DISTINCT doctor ids present in visits (ignore null/0)
    $res = $conn->query("SELECT DISTINCT doctor_empid FROM `tbl_visits` WHERE doctor_empid IS NOT NULL AND doctor_empid <> 0 ORDER BY doctor_empid");
    if ($res) { while ($r = $res->fetch_assoc()) $out['distinct_doctor_ids'][] = $r['doctor_empid']; $res->free(); }

    json_exit(['success' => true, 'data' => $out], 200);

} catch (Throwable $ex) {
    json_exit(['success' => false, 'message' => $ex->getMessage()], 500);
}