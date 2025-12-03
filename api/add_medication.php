<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';
session_start();

if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode(['success'=>false,'message'=>'User not authenticated']);
    exit();
}

$empID = $_SESSION['user']['EmpID'];

// قراءة JSON
$input = json_decode(file_get_contents("php://input"), true);

$InventoryItemID = $input['InventoryItemID'] ?? '';
$Product_Code  = $input['Product_Code'] ?? '';
$UnitID          = $input['UnitID'] ?? ''; // الآن نحصل على UnitID من القائمة المنسدلة
$Quantity        = $input['Quantity'] ?? 0;
$MinQuantity     = $input['MinQuantity'] ?? 0;
$ExpiryDate      = $input['ExpiryDate'] ?? null;
$Supplier        = $input['Supplier'] ?? null;

// تحقق من الحقول الأساسية
if (!$InventoryItemID || !$Product_Code || !$UnitID) {
    echo json_encode([
        'success'=>false,
        'message'=>'الرجاء تعبئة الحقول المطلوبة: InventoryItemID, Product_Code, Unit'
    ]);
    exit();
}

try {
    $stmt = $conn->prepare("
        INSERT INTO tbl_Products
        (InventoryItemID, Product_Code, UnitID, Quantity, MinQuantity, ExpiryDate, Supplier, AddedByEmpID)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$InventoryItemID, $Product_Code, $UnitID, $Quantity, $MinQuantity, $ExpiryDate, $Supplier, $empID]);

    echo json_encode(['success'=>true,'message'=>'تم حفظ الدواء بنجاح']);
} catch(PDOException $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
?>
