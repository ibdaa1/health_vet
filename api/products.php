<?php
// get_products.php
// Returns paginated list of products with attributes and variants summary.
// Query params:
//  - lang=ar|en
//  - page (default 1)
//  - per_page (default 12)
//  - q (search across Name_AR/Name_EN/Product_Code/Supplier)
//  - category (InventoryItemID filter)
// Response:
// { success:true, page, per_page, total, data: [ { Product_ID, Name_AR, Name_EN, Product_Code, Supplier, ProductImage, Quantity, MinQuantity, ExpiryDate, attributes: [...], variants_count, variants_total_qty } ] }

header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();

// sanitize inputs
$lang = (isset($_GET['lang']) && $_GET['lang'] === 'en') ? 'en' : 'ar';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 12;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$category = isset($_GET['category']) && is_numeric($_GET['category']) ? (int)$_GET['category'] : 0;

try {
    $offset = ($page - 1) * $per_page;

    // base where clause
    $where = [];
    if ($category) {
        $where[] = "InventoryItemID = " . (int)$category;
    }
    if ($q !== '') {
        $escaped = $conn->real_escape_string($q);
        $where[] = "(Product_Code LIKE '%$escaped%' OR Supplier LIKE '%$escaped%' OR Name_AR LIKE '%$escaped%' OR Name_EN LIKE '%$escaped%')";
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // total count
    $resTotal = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_Products $whereSql");
    $total = 0;
    if ($resTotal) {
        $row = $resTotal->fetch_assoc();
        $total = (int)$row['cnt'];
    }

    // fetch products page
    $sql = "SELECT Product_ID, InventoryItemID, Name_AR, Name_EN, Product_Code, Supplier, ProductImage, Quantity, MinQuantity, ExpiryDate
            FROM tbl_Products
            $whereSql
            ORDER BY Product_ID DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception($conn->error);
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
        echo json_encode(['success'=>true, 'page'=>$page, 'per_page'=>$per_page, 'total'=>$total, 'data'=>[]]);
        exit;
    }

    // fetch attributes for these products
    $idsCsv = implode(',', $productIds);
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

    // fetch variants summary for these products
    $variantsSummary = []; // pid => ['count'=>N, 'total_qty'=>M]
    $qVar = "SELECT ProductID, COUNT(*) AS variants_count, COALESCE(SUM(Quantity),0) AS variants_total_qty FROM tbl_ProductVariants WHERE ProductID IN ($idsCsv) GROUP BY ProductID";
    $rVar = $conn->query($qVar);
    if ($rVar) {
        while ($v = $rVar->fetch_assoc()) {
            $variantsSummary[(int)$v['ProductID']] = ['variants_count' => (int)$v['variants_count'], 'variants_total_qty' => (int)$v['variants_total_qty']];
        }
    }

    // assemble final product data
    $out = [];
    foreach ($products as $p) {
        $pid = (int)$p['Product_ID'];
        $out[] = [
            'Product_ID' => $pid,
            'InventoryItemID' => (int)$p['InventoryItemID'],
            'Name_AR' => $p['Name_AR'],
            'Name_EN' => $p['Name_EN'],
            'Product_Code' => $p['Product_Code'],
            'Supplier' => $p['Supplier'],
            'ProductImage' => $p['ProductImage'],
            'Quantity' => (int)$p['Quantity'],
            'MinQuantity' => (int)$p['MinQuantity'],
            'ExpiryDate' => $p['ExpiryDate'],
            'attributes' => $attrs[$pid] ?? [],
            'variants_count' => $variantsSummary[$pid]['variants_count'] ?? 0,
            'variants_total_qty' => $variantsSummary[$pid]['variants_total_qty'] ?? 0
        ];
    }

    echo json_encode(['success'=>true, 'page'=>$page, 'per_page'=>$per_page, 'total'=>$total, 'data'=>$out]);
    exit;

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>'Server error: '.$e->getMessage()]);
    exit;
}
?>