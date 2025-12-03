<?php
// get_product_attributes.php
// Returns attributes with their options, localized by ?lang=ar|en
// If ?item_id=123 is provided returns only attributes linked to the top-level (main) InventoryList parent
// (i.e. it climbs the InventoryList parent chain and uses the root parent item's attributes).
//
// Assumptions (match your DB if different):
// - InventoryList table has columns: ItemID (primary key) and ParentID (0 or NULL for top-level).
// - InventoryAttributes.ItemID refers to InventoryList.ItemID (list/category id).
// - ProductAttributes and ProductAttributeOptions table/columns match existing names used previously.
//
// If your schema uses different column names, adapt the InventoryList query/column names accordingly.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php'; // تأكد أن db.php يعرّف $conn كمُتصّل mysqli

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'DB connection not found (db.php must define $conn as mysqli)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lang = (isset($_GET['lang']) && $_GET['lang'] === 'en') ? 'en' : 'ar';
$itemId = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;

try {
    $attrs = [];

    if ($itemId > 0) {
        // Find the top-level (root) InventoryList parent for the provided item.
        // We climb ParentID until we reach a parent that is 0/NULL or until a safety depth.
        $rootId = $itemId;
        $maxDepth = 20;
        $depth = 0;

        $parentStmt = $conn->prepare("SELECT ParentID FROM InventoryList WHERE ItemID = ?");
        if (!$parentStmt) throw new Exception("Prepare failed: " . $conn->error);

        while ($depth < $maxDepth) {
            $parentStmt->bind_param('i', $rootId);
            $parentStmt->execute();
            $r = $parentStmt->get_result();
            if (!$r) break;
            $row = $r->fetch_assoc();
            if (!$row) break;

            $parent = $row['ParentID'] ?? null;
            // consider NULL, 0 or empty as top-level
            if ($parent === null || $parent === '' || intval($parent) === 0) {
                break;
            }
            // If parent is same as current (defensive), break to avoid infinite loop
            $parentInt = intval($parent);
            if ($parentInt === intval($rootId)) break;

            // climb up
            $rootId = $parentInt;
            $depth++;
        }
        $parentStmt->close();

        // Now fetch InventoryAttributes attached to the root/top-level list item
        $sql = "
            SELECT ia.ID AS InventoryAttributeID, ia.AttributeID, ia.IsRequired, ia.SortOrder, ia.DefaultOptionID,
                   pa.Name_AR, pa.Name_EN
            FROM InventoryAttributes ia
            JOIN ProductAttributes pa ON ia.AttributeID = pa.AttributeID
            WHERE ia.ItemID = ?
            ORDER BY ia.SortOrder, pa.AttributeID
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param('i', $rootId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $aid = (int)$row['AttributeID'];
            $attrs[$aid] = [
                'InventoryAttributeID' => (int)$row['InventoryAttributeID'],
                'AttributeID' => $aid,
                'Name_AR' => $row['Name_AR'],
                'Name_EN' => $row['Name_EN'],
                'label' => ($lang === 'en') ? $row['Name_EN'] : $row['Name_AR'],
                'is_required' => (bool)$row['IsRequired'],
                'sort_order' => (int)$row['SortOrder'],
                'default_option_id' => $row['DefaultOptionID'] !== null ? (int)$row['DefaultOptionID'] : null,
                'Options' => []
            ];
        }
        $stmt->close();
    } else {
        // No item_id: return all global attributes (as before)
        $sql = "SELECT AttributeID, Name_AR, Name_EN FROM ProductAttributes ORDER BY AttributeID";
        $res = $conn->query($sql);
        if (!$res) throw new Exception($conn->error);
        while ($row = $res->fetch_assoc()) {
            $aid = (int)$row['AttributeID'];
            $attrs[$aid] = [
                'AttributeID' => $aid,
                'Name_AR' => $row['Name_AR'],
                'Name_EN' => $row['Name_EN'],
                'label' => ($lang === 'en') ? $row['Name_EN'] : $row['Name_AR'],
                'Options' => []
            ];
        }
    }

    // If there are attributes, fetch all options for them in one query
    if (!empty($attrs)) {
        $ids = implode(',', array_map('intval', array_keys($attrs)));
        $sql2 = "SELECT OptionID, AttributeID, Name_AR, Name_EN FROM ProductAttributeOptions WHERE AttributeID IN ($ids) ORDER BY AttributeID, OptionID";
        $res2 = $conn->query($sql2);
        if (!$res2) throw new Exception($conn->error);
        while ($opt = $res2->fetch_assoc()) {
            $aid = (int)$opt['AttributeID'];
            if (!isset($attrs[$aid])) continue;
            $attrs[$aid]['Options'][] = [
                'OptionID' => (int)$opt['OptionID'],
                'Name_AR' => $opt['Name_AR'],
                'Name_EN' => $opt['Name_EN'],
                'label' => ($lang === 'en') ? $opt['Name_EN'] : $opt['Name_AR']
            ];
        }
    }

    // output as numeric array
    $out = array_values($attrs);
    echo json_encode(['success' => true, 'data' => $out, 'lang' => $lang, 'root_list_id' => ($itemId>0 ? $rootId : null)], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
?>