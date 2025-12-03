<?php
// get_visitors_summary.php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();
if (!isset($_SESSION['user']['EmpID'])) { echo json_encode(['success'=>false,'message'=>'User not authenticated']); exit; }
try {
    $today = date('Y-m-d');
    $res = $conn->query("SELECT COUNT(*) AS total FROM visitor_interactions");
    $total = $res ? (int)$res->fetch_assoc()['total'] : 0;
    $res = $conn->query("SELECT visit_type, COUNT(*) AS cnt FROM visitor_interactions GROUP BY visit_type");
    $by_type = [];
    if ($res) while ($r = $res->fetch_assoc()) $by_type[$r['visit_type']] = (int)$r['cnt'];
    $res = $conn->query("SELECT COUNT(*) AS today_cnt FROM visitor_interactions WHERE visit_date = '$today'");
    $today_cnt = $res ? (int)$res->fetch_assoc()['today_cnt'] : 0;
    echo json_encode(['success'=>true,'total'=>$total,'today'=>$today_cnt,'by_type'=>$by_type]);
    exit;
} catch(Exception $e){
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}
?>