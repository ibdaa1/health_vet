<?php
/**
 * get_variants_with_unit_names.php
 *
 * Returns variants for a given ProductID, resolves unit names from Units table,
 * supports optional filtering by an option id (?i=1 will return variants that include option 1),
 * and guarantees ordering ASC by ProductID then VariantID.
 *
 * Usage:
 *   GET /health_vet/api/get_variants_with_unit_names.php?product_id=21&lang=ar
 *   Optional: &i=1  (filter variants that contain OptionID 1 in their OptionIDs CSV)
 *
 * Place this file in the same folder where require_once 'db.php' works.
 */

header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();

// optional auth like your working endpoints:
// if (!isset($_SESSION['user']['EmpID'])) { echo json_encode(['success'=>false,'message'=>'User not authenticated']); exit; }

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection not found. db.php must create $conn as mysqli.'], JSON_UNESCAPED_UNICODE);
    exit;
}

@$conn->set_charset('utf8mb4');

$lang = (isset($_GET['lang']) && $_GET['lang'] === 'en') ? 'en' : 'ar';
$productId = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$filterOption = isset($_GET['i']) ? intval($_GET['i']) : 0;

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'product_id is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Helper: check whether a column exists in a table
 */
function column_exists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($res && $res->num_rows > 0);
}

try {
    // detect optional BaseUnitID column
    $hasBaseUnitID = column_exists($conn, 'tbl_ProductVariants', 'BaseUnitID');

    // build SQL with optional filter on OptionIDs via FIND_IN_SET
    $selectFields = "VariantID, ProductID, OptionIDs, SKU, Quantity, MinQuantity, ExpiryDate, ProductImage, BaseUnit, UnitsPerPack, QuantityBase";
    if ($hasBaseUnitID) $selectFields .= ", BaseUnitID";

    $sql = "SELECT {$selectFields}
            FROM tbl_ProductVariants
            WHERE ProductID = ?";
    if ($filterOption > 0) {
        $sql .= " AND FIND_IN_SET(?, OptionIDs)";
    }
    $sql .= " ORDER BY ProductID ASC, VariantID ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

    if ($filterOption > 0) {
        $stmt->bind_param('ii', $productId, $filterOption);
    } else {
        $stmt->bind_param('i', $productId);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $variants = [];
    while ($row = $res->fetch_assoc()) {
        // parse OptionIDs into array of ints
        $optCsv = isset($row['OptionIDs']) ? trim((string)$row['OptionIDs']) : '';
        $optArr = [];
        if ($optCsv !== '') {
            foreach (explode(',', $optCsv) as $o) {
                $o = trim($o);
                if ($o === '') continue;
                $optArr[] = intval($o);
            }
        }

        $variants[] = [
            'VariantID' => isset($row['VariantID']) ? (int)$row['VariantID'] : null,
            'ProductID' => isset($row['ProductID']) ? (int)$row['ProductID'] : null,
            'OptionIDs' => $optCsv,
            'OptionIDsArray' => $optArr,
            'SKU' => $row['SKU'] ?? '',
            'Quantity' => isset($row['Quantity']) ? (float)$row['Quantity'] : 0,
            'MinQuantity' => isset($row['MinQuantity']) ? (int)$row['MinQuantity'] : 0,
            'ExpiryDate' => $row['ExpiryDate'] ?? null,
            'ProductImage' => $row['ProductImage'] ?? null,
            'BaseUnitRaw' => $row['BaseUnit'] ?? null,
            'BaseUnitID' => $hasBaseUnitID && array_key_exists('BaseUnitID', $row) && $row['BaseUnitID'] !== null ? (int)$row['BaseUnitID'] : null,
            'UnitsPerPack' => isset($row['UnitsPerPack']) ? (float)$row['UnitsPerPack'] : null,
            'QuantityBase' => isset($row['QuantityBase']) ? (float)$row['QuantityBase'] : null,
            'ResolvedUnitID' => null,
            'UnitName_AR' => null,
            'UnitName_EN' => null,
            'unit_label' => null
        ];
    }
    $stmt->close();

    if (empty($variants)) {
        echo json_encode(['success' => true, 'variants' => [], 'lang' => $lang], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // collect candidate ids and texts to lookup in Units
    $ids = [];
    $texts = [];
    foreach ($variants as $v) {
        if (!empty($v['BaseUnitID'])) {
            $ids[] = intval($v['BaseUnitID']);
            continue;
        }
        if ($v['BaseUnitRaw'] !== null && is_numeric($v['BaseUnitRaw'])) {
            $ids[] = intval($v['BaseUnitRaw']);
            continue;
        }
        if ($v['BaseUnitRaw'] !== null && trim((string)$v['BaseUnitRaw']) !== '') {
            $texts[] = trim((string)$v['BaseUnitRaw']);
        }
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $texts = array_values(array_unique(array_filter($texts, fn($x) => $x !== '')));

    // lookup Units in batch
    $unitsById = [];
    $unitsByAR = [];
    $unitsByEN = [];

    $whereParts = [];
    $params = [];
    $types = '';

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $whereParts[] = "UnitID IN ($placeholders)";
        foreach ($ids as $id) { $params[] = $id; $types .= 'i'; }
    }
    if (!empty($texts)) {
        $namePlaceholders = implode(',', array_fill(0, count($texts), '?'));
        $whereParts[] = "(UnitName_AR IN ($namePlaceholders) OR UnitName_EN IN ($namePlaceholders))";
        foreach ($texts as $t) { $params[] = $t; $types .= 's'; }
        foreach ($texts as $t) { $params[] = $t; $types .= 's'; }
    }

    if (!empty($whereParts)) {
        $sqlU = "SELECT UnitID, UnitName_AR, UnitName_EN FROM Units WHERE " . implode(' OR ', $whereParts) . " ORDER BY UnitID ASC";
        $stmtU = $conn->prepare($sqlU);
        if ($stmtU) {
            if (!empty($params)) {
                $refs = [];
                $refs[] = & $types;
                foreach ($params as $k => $v) $refs[] = & $params[$k];
                call_user_func_array([$stmtU, 'bind_param'], $refs);
            }
            $stmtU->execute();
            $resU = $stmtU->get_result();
            while ($r = $resU->fetch_assoc()) {
                $uid = (int)$r['UnitID'];
                $unitsById[$uid] = ['UnitID' => $uid, 'UnitName_AR' => $r['UnitName_AR'], 'UnitName_EN' => $r['UnitName_EN']];
                if ($r['UnitName_AR'] !== null) $unitsByAR[trim($r['UnitName_AR'])] = $unitsById[$uid];
                if ($r['UnitName_EN'] !== null) $unitsByEN[trim($r['UnitName_EN'])] = $unitsById[$uid];
            }
            $stmtU->close();
        }
    }

    // resolve each variant's unit
    foreach ($variants as &$v) {
        $resolved = null;
        if (!empty($v['BaseUnitID']) && isset($unitsById[$v['BaseUnitID']])) {
            $resolved = $unitsById[$v['BaseUnitID']];
        } else {
            if ($v['BaseUnitRaw'] !== null && is_numeric($v['BaseUnitRaw'])) {
                $bid = intval($v['BaseUnitRaw']);
                if (isset($unitsById[$bid])) $resolved = $unitsById[$bid];
            }
            if ($resolved === null && $v['BaseUnitRaw'] !== null) {
                $raw = trim((string)$v['BaseUnitRaw']);
                if ($raw !== '' && isset($unitsByAR[$raw])) $resolved = $unitsByAR[$raw];
                if ($resolved === null && isset($unitsByEN[$raw])) $resolved = $unitsByEN[$raw];
            }
        }

        if ($resolved) {
            $v['ResolvedUnitID'] = $resolved['UnitID'];
            $v['UnitName_AR'] = $resolved['UnitName_AR'];
            $v['UnitName_EN'] = $resolved['UnitName_EN'];
            $v['unit_label'] = ($lang === 'en') ? ($resolved['UnitName_EN'] ?? $resolved['UnitName_AR']) : ($resolved['UnitName_AR'] ?? $resolved['UnitName_EN']);
        } else {
            $v['unit_label'] = $v['BaseUnitRaw'] ?? null;
        }
    }
    unset($v);

    echo json_encode(['success' => true, 'variants' => $variants, 'lang' => $lang], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
?>