<?php
// get_inventory_list.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

// تحديد اللغة من الطلب (ar أو en)
$lang = isset($_GET['lang']) && $_GET['lang'] === 'en' ? 'EN' : 'AR';
$nameField = $lang === 'EN' ? 'Name_EN' : 'Name_AR';

try {
    $sql = "
        SELECT 
            ItemID,
            ParentID,
            $nameField AS Name
        FROM InventoryList
        ORDER BY ParentID, $nameField
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $row['Type'] = is_null($row['ParentID']) ? 'Category' : 'SubCategory';
        $items[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data' => $items
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
