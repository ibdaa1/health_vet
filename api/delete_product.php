<?php
// delete_product.php
// Deletes product and all related data (ProductAttributeValues, tbl_ProductVariants) and removes images
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();

if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode(['success'=>false,'message'=>'User not authenticated']);
    exit;
}

if (!isset($_GET['ProductID'])) {
    echo json_encode(['success'=>false,'message'=>'ProductID required']);
    exit;
}

$ProductID = (int)$_GET['ProductID'];

function respond($ok, $msg) { echo json_encode(['success'=>$ok,'message'=>$msg]); exit; }

$conn->begin_transaction();
try {
    // get product image
    $res = $conn->query("SELECT ProductImage FROM tbl_Products WHERE Product_ID = $ProductID");
    if ($res && $res->num_rows) {
        $row = $res->fetch_assoc();
        $prodImg = $row['ProductImage'];
    } else {
        $prodImg = null;
    }

    // find variant images to delete
    $variantImgs = [];
    $r = $conn->query("SELECT ProductImage FROM tbl_ProductVariants WHERE ProductID = $ProductID");
    if ($r) {
        while ($rr = $r->fetch_assoc()) {
            if (!empty($rr['ProductImage'])) $variantImgs[] = $rr['ProductImage'];
        }
    }

    // delete ProductAttributeValues
    $stmt = $conn->prepare("DELETE FROM ProductAttributeValues WHERE ProductID = ?");
    $stmt->bind_param('i', $ProductID);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    // delete variants
    $stmt2 = $conn->prepare("DELETE FROM tbl_ProductVariants WHERE ProductID = ?");
    $stmt2->bind_param('i', $ProductID);
    if (!$stmt2->execute()) throw new Exception($stmt2->error);
    $stmt2->close();

    // delete product
    $stmt3 = $conn->prepare("DELETE FROM tbl_Products WHERE Product_ID = ?");
    $stmt3->bind_param('i', $ProductID);
    if (!$stmt3->execute()) throw new Exception($stmt3->error);
    $stmt3->close();

    $conn->commit();

    // remove files (after commit)
    $uploadDir = __DIR__ . '/../uploads/ProductImage';
    if ($prodImg) {
        $p = $uploadDir . '/' . $prodImg;
        if (file_exists($p)) @unlink($p);
    }
    foreach ($variantImgs as $vi) {
        $p = $uploadDir . '/' . $vi;
        if (file_exists($p)) @unlink($p);
    }

    respond(true, 'تم حذف المنتج وكل البيانات المرتبطة والصور');
} catch (Exception $e) {
    $conn->rollback();
    respond(false, 'Server error: ' . $e->getMessage());
}
?>