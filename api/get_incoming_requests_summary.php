<?php
// get_incoming_requests_summary.php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();
if (!isset($_SESSION['user']['EmpID'])) { echo json_encode(['success'=>false,'message'=>'User not authenticated']); exit; }
try {
    $res = $conn->query("SELECT animal_type, COUNT(*) AS requests, SUM(quantity) AS total_quantity FROM incoming_animal_requests GROUP BY animal_type");
    $data = [];
    if ($res) while ($r = $res->fetch_assoc()) $data[] = $r;
    echo json_encode(['success'=>true,'data'=>$data]);
    exit;
} catch(Exception $e){
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}
?>