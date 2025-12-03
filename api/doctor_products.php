<?php
// doctor_products.php
// GET  -> list doctor's products (uses session EmpID)
// POST -> create new doctor product entry (EmpID taken from session) fields: ProductID, VariantID (optional), DefaultQty, DefaultAction, Notes
// DELETE -> delete by DoctorProductID (only owner doctor can delete) via ?id=
// Response: JSON

header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();

if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode(['success'=>false,'message'=>'User not authenticated']);
    exit;
}
$empID = (int)$_SESSION['user']['EmpID'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // list doctor's products
        $stmt = $conn->prepare("
            SELECT dp.DoctorProductID, dp.EmpID, dp.ProductID, dp.VariantID, dp.DefaultQty, dp.DefaultAction, dp.Notes, dp.Active,
                   p.Product_Code, p.Name_AR, p.Name_EN, p.ProductImage, p.Quantity AS ProductQuantity
            FROM tbl_DoctorProducts dp
            JOIN tbl_Products p ON p.Product_ID = dp.ProductID
            WHERE dp.EmpID = ?
            ORDER BY dp.CreatedAt DESC
        ");
        $stmt->bind_param('i', $empID);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) $items[] = $r;
        $stmt->close();
        echo json_encode(['success'=>true,'data'=>$items]);
        exit;
    }

    if ($method === 'POST') {
        // create
        $ProductID = isset($_POST['ProductID']) ? (int)$_POST['ProductID'] : 0;
        $VariantID = isset($_POST['VariantID']) && $_POST['VariantID'] !== '' ? (int)$_POST['VariantID'] : null;
        $DefaultQty = isset($_POST['DefaultQty']) ? max(1,(int)$_POST['DefaultQty']) : 1;
        $DefaultAction = isset($_POST['DefaultAction']) ? $_POST['DefaultAction'] : 'Prescribed';
        $Notes = isset($_POST['Notes']) ? $_POST['Notes'] : null;

        if (!$ProductID) { echo json_encode(['success'=>false,'message'=>'ProductID required']); exit; }

        $stmt = $conn->prepare("INSERT INTO tbl_DoctorProducts (EmpID, ProductID, VariantID, DefaultQty, DefaultAction, Notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiisss', $empID, $ProductID, $VariantID, $DefaultQty, $DefaultAction, $Notes);
        if (!$stmt->execute()) {
            echo json_encode(['success'=>false,'message'=>'Insert failed: ' . $stmt->error]);
            exit;
        }
        $id = $conn->insert_id;
        $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Added to doctor list','DoctorProductID'=>$id]);
        exit;
    }

    if ($method === 'DELETE') {
        // delete by id (owner only)
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) { echo json_encode(['success'=>false,'message'=>'id required']); exit; }
        // verify owner
        $stmt = $conn->prepare("SELECT EmpID FROM tbl_DoctorProducts WHERE DoctorProductID = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$r) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
        if ((int)$r['EmpID'] !== $empID) { echo json_encode(['success'=>false,'message'=>'Not allowed']); exit; }
        $stmt2 = $conn->prepare("DELETE FROM tbl_DoctorProducts WHERE DoctorProductID = ?");
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $stmt2->close();
        echo json_encode(['success'=>true,'message'=>'Deleted']);
        exit;
    }

    // unsupported method
    echo json_encode(['success'=>false,'message'=>'Unsupported method']);
    exit;
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
    exit;
}
?>