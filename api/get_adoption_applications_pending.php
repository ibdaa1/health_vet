<?php
// get_adoption_applications_pending.php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();
if (!isset($_SESSION['user']['EmpID'])) { echo json_encode(['success'=>false,'message'=>'User not authenticated']); exit; }

try {
    $sql = "SELECT id, full_name, emirates_id, phone, submission_date, main_caretaker FROM adoption_applications WHERE approved_by IS NULL ORDER BY submission_date ASC";
    $res = $conn->query($sql);
    $data = [];
    if ($res) while ($r = $res->fetch_assoc()) $data[] = $r;
    echo json_encode(['success'=>true,'data'=>$data]);
    exit;
} catch(Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}
?>