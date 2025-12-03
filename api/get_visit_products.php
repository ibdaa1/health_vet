<?php
// get_visit_products.php
// Returns JSON list of dispensed products for a given VisitID
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();

// require authentication (optional)
if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode(['success'=>false,'message'=>'User not authenticated']);
    exit;
}

$VisitID = isset($_GET['VisitID']) ? (int)$_GET['VisitID'] : 0;
if (!$VisitID) {
    echo json_encode(['success'=>false,'message'=>'VisitID required']);
    exit;
}

$sql = "SELECT vp.VisitProductID, vp.VisitID, vp.ProductID, vp.VariantID, vp.EmpID, vp.Quantity, vp.Action, vp.Notes, vp.CreatedAt,
               p.Name_AR, p.Name_EN, p.Product_Code, p.ProductImage,
               v.SKU, v.OptionIDs, v.Quantity AS VariantQuantity
        FROM tbl_VisitProducts vp
        LEFT JOIN tbl_Products p ON p.Product_ID = vp.ProductID
        LEFT JOIN tbl_ProductVariants v ON v.VariantID = vp.VariantID
        WHERE vp.VisitID = ?
        ORDER BY vp.CreatedAt ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) { echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$conn->error]); exit; }
$stmt->bind_param('i', $VisitID);
$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($r = $res->fetch_assoc()) {
    $data[] = [
      'VisitProductID' => (int)$r['VisitProductID'],
      'VisitID' => (int)$r['VisitID'],
      'ProductID' => (int)$r['ProductID'],
      'VariantID' => $r['VariantID'] !== null ? (int)$r['VariantID'] : null,
      'EmpID' => $r['EmpID'] !== null ? (int)$r['EmpID'] : null,
      'Quantity' => (int)$r['Quantity'],
      'Action' => $r['Action'],
      'Notes' => $r['Notes'],
      'CreatedAt' => $r['CreatedAt'],
      'ProductName' => $r['Name_AR'] ?: $r['Name_EN'] ?: $r['Product_Code'],
      'Product_Code' => $r['Product_Code'],
      'ProductImage' => $r['ProductImage'],
      'SKU' => $r['SKU'],
      'OptionIDs' => $r['OptionIDs'],
      'VariantQuantity' => $r['VariantQuantity']
    ];
}
$stmt->close();
echo json_encode(['success'=>true,'data'=>$data]);
exit;
?>