<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();
$user = $_SESSION['user'] ?? [];
$EmpID = $user['EmpID'] ?? 0;
$isAdmin = $user['IsAdmin'] ?? 0;
$isManager = $user['IsLicenseManager'] ?? 0;
$action = $_GET['action'] ?? '';
if($action == 'list'){
    // عرض جميع الغرف مع فلترة اختيارية
    $sql = "SELECT * FROM tbl_rooms WHERE 1=1";
    $params = [];
    $types = '';
  
    if (!empty($_GET['name_filter'])) {
        $name_filter = $_GET['name_filter'];
        $sql .= " AND room_name LIKE ?";
        $params[] = "%$name_filter%";
        $types .= 's';
    }
  
    if (!empty($_GET['type_filter'])) {
        $type_filter = $_GET['type_filter'];
        $sql .= " AND room_type = ?";
        $params[] = $type_filter;
        $types .= 's';
    }
  
    $sql .= " ORDER BY id DESC";
  
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rooms = [];
    while($row = $res->fetch_assoc()){
        $rooms[] = $row;
    }
    echo json_encode($rooms);
    exit;
}
if($action == 'get'){
    // جلب بيانات غرفة واحدة
    $id = intval($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM tbl_rooms WHERE id=?");
    $stmt->bind_param('i',$id);
    $stmt->execute();
    $res = $stmt->get_result();
    $room = $res->fetch_assoc();
    echo json_encode($room);
    exit;
}
if($action == 'delete'){
    // حذف الغرفة
    $id = intval($_GET['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM tbl_rooms WHERE id=?");
    $stmt->bind_param('i',$id);
    $stmt->execute();
    if($stmt->affected_rows){
        echo json_encode(['success'=>true,'message'=>'Room deleted successfully']);
    }else{
        echo json_encode(['success'=>false,'message'=>'Delete failed']);
    }
    exit;
}
// إدخال / تعديل
$room_id = intval($_POST['id'] ?? 0);
$room_name = $_POST['room_name'] ?? '';
$room_type = $_POST['room_type'] ?? '';
$location = $_POST['location'] ?? '';
$notes = $_POST['notes'] ?? '';
$capacity = intval($_POST['capacity'] ?? 5);
if(empty($room_name) || empty($room_type)){
    echo json_encode(['success'=>false,'message'=>'Please fill required fields']);
    exit;
}
if($room_id > 0){
    // تعديل الغرفة
    $stmt = $conn->prepare("UPDATE tbl_rooms SET room_name=?, room_type=?, location=?, notes=?, capacity=?, updated_by=?, updated_at=NOW() WHERE id=?");
    $stmt->bind_param('ssssiii',$room_name,$room_type,$location,$notes,$capacity,$EmpID,$room_id);
    $stmt->execute();
    if($stmt->affected_rows >=0){
        echo json_encode(['success'=>true,'message'=>'Room updated successfully']);
    }else{
        echo json_encode(['success'=>false,'message'=>'Update failed']);
    }
}else{
    // إضافة غرفة جديدة
    $stmt = $conn->prepare("INSERT INTO tbl_rooms (room_name, room_type, location, notes, capacity, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())");
    $stmt->bind_param('ssssii',$room_name,$room_type,$location,$notes,$capacity,$EmpID);
    $stmt->execute();
    if($stmt->affected_rows){
        $new_id = $stmt->insert_id;
        echo json_encode(['success'=>true,'message'=>'Room added successfully','room_id'=>$new_id]);
    }else{
        echo json_encode(['success'=>false,'message'=>'Save failed']);
    }
}
?>