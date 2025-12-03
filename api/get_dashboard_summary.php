<?php
// get_dashboard_summary.php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();
if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode(['success'=>false,'message'=>'User not authenticated']); exit;
}
try {
    // Animals count (active / inactive)
    $r = $conn->query("SELECT 
        SUM(CASE WHEN is_active='Active' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN is_active='Inactive' THEN 1 ELSE 0 END) AS inactive_count,
        COUNT(*) AS total_animals
      FROM tbl_animals");
    $animals = $r ? $r->fetch_assoc() : ['active_count'=>0,'inactive_count'=>0,'total_animals'=>0];

    // Rooms: count rooms and occupancy by latest movement
    // compute current animals in rooms: take latest movement per animal and count those with movement_type='In'
    $sqlRoomOccupancy = "
      SELECT r.id AS room_id, r.room_name,
             SUM(CASE WHEN a.animal_type IS NOT NULL THEN 1 ELSE 0 END) AS total,
             SUM(CASE WHEN a.animal_type='Dogs' THEN 1 ELSE 0 END) AS dogs,
             SUM(CASE WHEN a.animal_type='Cats' THEN 1 ELSE 0 END) AS cats
      FROM tbl_rooms r
      LEFT JOIN (
        SELECT am1.animal_code, am1.room_id FROM tbl_animal_movements am1
        JOIN (
          SELECT animal_code, MAX(movement_date) AS md FROM tbl_animal_movements GROUP BY animal_code
        ) am2 ON am1.animal_code=am2.animal_code AND am1.movement_date=am2.md
        WHERE am1.movement_type='In'
      ) cur ON cur.room_id = r.id
      LEFT JOIN tbl_animals a ON a.animal_code = cur.animal_code
      GROUP BY r.id, r.room_name
      ORDER BY r.room_name ASC
    ";
    $rooms = [];
    $res = $conn->query($sqlRoomOccupancy);
    if ($res) while ($row = $res->fetch_assoc()) $rooms[] = $row;

    // Adoption applications pending
    $res = $conn->query("SELECT COUNT(*) AS pending_count FROM adoption_applications WHERE approved_by IS NULL");
    $pending_adoptions = $res ? (int)$res->fetch_assoc()['pending_count'] : 0;

    // adoptions summary (tbl_adoptions)
    $res = $conn->query("SELECT Operation_type, COUNT(*) AS cnt FROM tbl_adoptions GROUP BY Operation_type");
    $adoptions = [];
    if ($res) while ($r = $res->fetch_assoc()) $adoptions[$r['Operation_type']] = (int)$r['cnt'];

    // visitors summary: total and by visit_type (today and total)
    $today = date('Y-m-d');
    $res = $conn->query("SELECT COUNT(*) AS total_visitors FROM tbl_visitors");
    $total_visitors = $res ? (int)$res->fetch_assoc()['total_visitors'] : 0;
    $res = $conn->query("SELECT visit_type, COUNT(*) AS cnt FROM tbl_visitors GROUP BY visit_type");
    $visitors_by_type = [];
    if ($res) while ($r = $res->fetch_assoc()) $visitors_by_type[$r['visit_type']] = (int)$r['cnt'];
    $res = $conn->query("SELECT COUNT(*) AS today_visitors FROM tbl_visitors WHERE DATE(visitors_date) = '$today'");
    $today_visitors = $res ? (int)$res->fetch_assoc()['today_visitors'] : 0;

    // incoming animal requests summary by type
    $res = $conn->query("SELECT animal_type, SUM(quantity) AS qty, COUNT(*) AS requests FROM incoming_animal_requests GROUP BY animal_type");
    $incoming = [];
    if ($res) while ($r = $res->fetch_assoc()) $incoming[$r['animal_type']] = ['requests' => (int)$r['requests'], 'quantity' => (int)$r['qty']];

    // rooms count
    $res = $conn->query("SELECT COUNT(*) AS rooms_count FROM tbl_rooms");
    $rooms_count = $res ? (int)$res->fetch_assoc()['rooms_count'] : 0;

    echo json_encode([
      'success'=>true,
      'animals'=>$animals,
      'rooms_count'=>$rooms_count,
      'rooms'=>$rooms,
      'pending_adoption_applications'=>$pending_adoptions,
      'adoptions_summary'=>$adoptions,
      'visitors'=>['total'=>$total_visitors,'today'=>$today_visitors,'by_type'=>$visitors_by_type],
      'incoming_requests'=>$incoming
    ]);
    exit;
} catch(Exception $e){
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}
?>