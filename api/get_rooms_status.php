<?php
// get_rooms_status.php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();
if (!isset($_SESSION['user']['EmpID'])) { echo json_encode(['success'=>false,'message'=>'User not authenticated']); exit; }
try {
    $sql = "
      SELECT r.id AS room_id, r.room_name, r.capacity,
             COUNT(cur.animal_code) AS occupancy,
             SUM(CASE WHEN a.animal_type='Dogs' THEN 1 ELSE 0 END) AS dogs,
             SUM(CASE WHEN a.animal_type='Cats' THEN 1 ELSE 0 END) AS cats
      FROM tbl_rooms r
      LEFT JOIN (
        SELECT am1.animal_code, am1.room_id FROM tbl_animal_movements am1
        JOIN (
          SELECT animal_code, MAX(movement_date) AS md FROM tbl_animal_movements GROUP BY animal_code
        ) am2 ON am1.animal_code = am2.animal_code AND am1.movement_date = am2.md
        WHERE am1.movement_type = 'In'
      ) cur ON cur.room_id = r.id
      LEFT JOIN tbl_animals a ON a.animal_code = cur.animal_code
      GROUP BY r.id, r.room_name, r.capacity
      ORDER BY r.room_name ASC
    ";
    $res = $conn->query($sql);
    $rooms = [];
    if ($res) while ($r = $res->fetch_assoc()) $rooms[] = $r;
    echo json_encode(['success'=>true,'rooms'=>$rooms]);
    exit;
} catch(Exception $e){
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}
?>