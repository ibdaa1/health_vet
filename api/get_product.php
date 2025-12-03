<?php
// get_product.php
// Returns product, its ProductAttributeValues and tbl_ProductVariants (with option names).
// Usage: GET /health_vet/api/get_product.php?ProductID=5
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';

// Validate input
if (!isset($_GET['ProductID']) || !is_numeric($_GET['ProductID'])) {
    echo json_encode(['success' => false, 'message' => 'ProductID is required']);
    exit;
}
$ProductID = (int) $_GET['ProductID'];

try {
    // 1) fetch product
    $stmt = $conn->prepare("SELECT * FROM tbl_Products WHERE Product_ID = ? LIMIT 1");
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('i', $ProductID);
    if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    $product = $res->fetch_assoc();
    $stmt->close();

    // 2) fetch product attributes (ProductAttributeValues)
    $attrs = [];
    $stmt2 = $conn->prepare("SELECT ID, ProductID, AttributeID, OptionID, Value, Quantity, CreatedAt, UpdatedAt FROM ProductAttributeValues WHERE ProductID = ?");
    if (!$stmt2) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt2->bind_param('i', $ProductID);
    $stmt2->execute();
    $r2 = $stmt2->get_result();
    while ($row = $r2->fetch_assoc()) {
        $attrs[] = $row;
    }
    $stmt2->close();

    // 3) fetch variants
    $variants = [];
    $stmt3 = $conn->prepare("SELECT VariantID, ProductID, SKU, OptionIDs, Quantity, MinQuantity, ExpiryDate, ProductImage, CreatedAt, UpdatedAt FROM tbl_ProductVariants WHERE ProductID = ? ORDER BY VariantID DESC");
    if (!$stmt3) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt3->bind_param('i', $ProductID);
    $stmt3->execute();
    $r3 = $stmt3->get_result();

    // collect all option IDs used by variants to fetch names in one query
    $allOptionIDs = [];
    $variantsRaw = [];
    while ($v = $r3->fetch_assoc()) {
        $variantsRaw[] = $v;
        if (!empty($v['OptionIDs'])) {
            $parts = array_filter(array_map('trim', explode(',', $v['OptionIDs'])));
            foreach ($parts as $p) {
                if ('' !== $p && is_numeric($p)) $allOptionIDs[(int)$p] = true;
            }
        }
    }
    $optionMap = []; // OptionID => option row
    if (!empty($allOptionIDs)) {
        $ids = implode(',', array_keys($allOptionIDs));
        $q = "SELECT OptionID, AttributeID, Name_AR, Name_EN FROM ProductAttributeOptions WHERE OptionID IN ($ids)";
        $r = $conn->query($q);
        if ($r) {
            while ($opt = $r->fetch_assoc()) {
                $optionMap[(int)$opt['OptionID']] = $opt;
            }
        }
    }

    // enrich variants with option objects
    foreach ($variantsRaw as $v) {
        $optList = [];
        if (!empty($v['OptionIDs'])) {
            $parts = array_filter(array_map('trim', explode(',', $v['OptionIDs'])));
            foreach ($parts as $p) {
                $oid = (int)$p;
                if (isset($optionMap[$oid])) {
                    $optList[] = $optionMap[$oid];
                } else {
                    // missing option in options table; return placeholder
                    $optList[] = ['OptionID' => $oid, 'AttributeID' => null, 'Name_AR' => '', 'Name_EN' => ''];
                }
            }
        }
        $v['Options'] = $optList;
        $variants[] = $v;
    }

    // Respond
    echo json_encode([
        'success' => true,
        'product' => $product,
        'attributes' => $attrs,
        'variants' => $variants
    ]);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
?>