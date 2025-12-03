<?php
// get_products.php (robust — updated)
// Returns paginated list of products. Detects inventory/category table name dynamically
// and expands category filter to include descendant categories.
// Query params: lang, page, per_page, q, category
//
// Changes in this version:
// - Do NOT select product-level Quantity/MinQuantity/ExpiryDate columns (they were removed).
// - Compute Quantity/MinQuantity/ExpiryDate from tbl_ProductVariants (aggregates).
// - Defensive checks for missing tables/columns to avoid SQL errors.
// - Keeps previous filtering and pagination behavior.

header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();

$lang = (isset($_GET['lang']) && $_GET['lang'] === 'en') ? 'en' : 'ar';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(200, (int)$_GET['per_page'])) : 12;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$category = isset($_GET['category']) && is_numeric($_GET['category']) ? (int)$_GET['category'] : 0;

try {
    $offset = ($page - 1) * $per_page;

    // Helper: detect inventory/category table
    function findInventoryTable($conn) {
        $candidates = [
            'tbl_InventoryList',
            'tbl_inventory_list',
            'InventoryList',
            'inventory_list',
            'tbl_inventory',
            'inventory'
        ];
        foreach ($candidates as $t) {
            $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $t);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $stmt->close();
                    return $t;
                }
                $stmt->close();
            }
        }
        return null;
    }

    $inventoryTable = findInventoryTable($conn); // may be null

    // Build WHERE parts (safe)
    $whereParts = [];

    // Category expansion
    if ($category && $inventoryTable) {
        $categoryIds = [];

        // Try recursive CTE (MySQL 8+)
        $ctesql = "
            WITH RECURSIVE cte AS (
              SELECT ItemID FROM " . $conn->real_escape_string($inventoryTable) . " WHERE ItemID = ?
              UNION ALL
              SELECT i.ItemID FROM " . $conn->real_escape_string($inventoryTable) . " i JOIN cte ON i.ParentID = cte.ItemID
            )
            SELECT ItemID FROM cte
        ";
        $stmtCte = $conn->prepare($ctesql);
        if ($stmtCte) {
            $stmtCte->bind_param('i', $category);
            if ($stmtCte->execute()) {
                $resCte = $stmtCte->get_result();
                while ($r = $resCte->fetch_assoc()) $categoryIds[] = (int)$r['ItemID'];
            }
            $stmtCte->close();
        }

        // Fallback PHP traversal if CTE not supported or returned nothing
        if (!count($categoryIds)) {
            $categoryIds = [$category];
            $ptr = 0;
            while ($ptr < count($categoryIds)) {
                $pid = (int)$categoryIds[$ptr++];
                $resChildren = $conn->query("SELECT ItemID FROM " . $conn->real_escape_string($inventoryTable) . " WHERE ParentID = " . intval($pid));
                if ($resChildren) {
                    while ($cr = $resChildren->fetch_assoc()) {
                        $cid = (int)$cr['ItemID'];
                        if (!in_array($cid, $categoryIds, true)) $categoryIds[] = $cid;
                    }
                }
            }
        }

        if (count($categoryIds)) {
            $idsCsv = implode(',', array_map('intval', array_unique($categoryIds)));
            $whereParts[] = "InventoryItemID IN ($idsCsv)";
        } else {
            $whereParts[] = "0=1";
        }
    } elseif ($category && !$inventoryTable) {
        // Inventory table not found -> ignore category filter but do not error
    }

    if ($q !== '') {
        // escape percent-match safely
        $escaped = $conn->real_escape_string($q);
        $whereParts[] = "(Product_Code LIKE '%$escaped%' OR Supplier LIKE '%$escaped%' OR Name_AR LIKE '%$escaped%' OR Name_EN LIKE '%$escaped%')";
    }

    $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // total count (products)
    $resTotal = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_Products $whereSql");
    $total = 0;
    if ($resTotal) {
        $row = $resTotal->fetch_assoc();
        $total = (int)$row['cnt'];
    }

    // fetch product base rows (do NOT select product-level Quantity etc.)
    $sql = "SELECT Product_ID, InventoryItemID, Name_AR, Name_EN, Product_Code, Supplier, ProductImage
            FROM tbl_Products
            $whereSql
            ORDER BY Product_ID DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('ii', $per_page, $offset);
    $stmt->execute();
    $res = $stmt->get_result();

    $products = [];
    $productIds = [];
    while ($r = $res->fetch_assoc()) {
        $products[] = $r;
        $productIds[] = (int)$r['Product_ID'];
    }
    $stmt->close();

    if (!count($productIds)) {
        echo json_encode(['success' => true, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'data' => []]);
        exit;
    }

    // prepare CSV of ids for subsequent queries (safe because ints)
    $idsCsv = implode(',', array_map('intval', $productIds));

    // fetch attributes for these products
    $attrs = [];
    $qAttr = "SELECT pav.ProductID, pav.AttributeID, pav.OptionID, pav.Value, o.Name_AR AS OptionName_AR, o.Name_EN AS OptionName_EN
              FROM ProductAttributeValues pav
              LEFT JOIN ProductAttributeOptions o ON o.OptionID = pav.OptionID
              WHERE pav.ProductID IN ($idsCsv)
              ORDER BY pav.ProductID, pav.AttributeID";
    $rAttr = $conn->query($qAttr);
    if ($rAttr) {
        while ($arow = $rAttr->fetch_assoc()) {
            $pid = (int)$arow['ProductID'];
            if (!isset($attrs[$pid])) $attrs[$pid] = [];
            $attrs[$pid][] = $arow;
        }
    }

    // fetch variants summary (count, sum quantity, min(minquantity), earliest expiry)
    $variantsSummary = [];
    // Check if tbl_ProductVariants exists
    $hasVariantsTable = false;
    $resCheck = $conn->query("SHOW TABLES LIKE 'tbl_ProductVariants'");
    if ($resCheck && $resCheck->num_rows) $hasVariantsTable = true;

    if ($hasVariantsTable) {
        // Use MIN(NULLIF(ExpiryDate,'0000-00-00')) to ignore '0000-00-00' sentinel values
        $qVar = "SELECT ProductID,
                        COUNT(*) AS variants_count,
                        COALESCE(SUM(Quantity),0) AS variants_total_qty,
                        COALESCE(MIN(MinQuantity),0) AS min_min_quantity,
                        MIN(NULLIF(ExpiryDate,'0000-00-00')) AS earliest_expiry
                 FROM tbl_ProductVariants
                 WHERE ProductID IN ($idsCsv)
                 GROUP BY ProductID";
        $rVar = $conn->query($qVar);
        if ($rVar) {
            while ($v = $rVar->fetch_assoc()) {
                $pid = (int)$v['ProductID'];
                $variantsSummary[$pid] = [
                    'variants_count' => (int)$v['variants_count'],
                    'variants_total_qty' => (float)$v['variants_total_qty'],
                    'min_min_quantity' => isset($v['min_min_quantity']) ? (int)$v['min_min_quantity'] : 0,
                    'earliest_expiry' => $v['earliest_expiry'] !== null ? $v['earliest_expiry'] : null
                ];
            }
        }
    }

    // assemble output: include computed Quantity/MinQuantity/ExpiryDate from variantsSummary
    $out = [];
    foreach ($products as $p) {
        $pid = (int)$p['Product_ID'];
        $vs = $variantsSummary[$pid] ?? null;
        $computedQuantity = $vs ? $vs['variants_total_qty'] : 0;
        $computedMinQuantity = $vs ? $vs['min_min_quantity'] : 0;
        $computedExpiry = $vs ? $vs['earliest_expiry'] : null;

        $out[] = [
            'Product_ID' => $pid,
            'InventoryItemID' => isset($p['InventoryItemID']) ? (int)$p['InventoryItemID'] : null,
            'Name_AR' => $p['Name_AR'],
            'Name_EN' => $p['Name_EN'],
            'Product_Code' => $p['Product_Code'],
            'Supplier' => $p['Supplier'],
            'ProductImage' => $p['ProductImage'],
            // computed fields derived from variants (since product-level columns were removed)
            'Quantity' => (float)$computedQuantity,
            'MinQuantity' => (int)$computedMinQuantity,
            'ExpiryDate' => $computedExpiry,
            'attributes' => $attrs[$pid] ?? [],
            'variants_count' => $vs ? $vs['variants_count'] : 0,
            'variants_total_qty' => $vs ? $vs['variants_total_qty'] : 0
        ];
    }

    echo json_encode(['success' => true, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'data' => $out]);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
?>