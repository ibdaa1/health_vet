<?php
// prescriptions_api.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';
session_start();

if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode(['success'=>false,'message'=>'User not authenticated']);
    exit();
}

$currentUser = $_SESSION['user']['EmpID'];
$action = $_REQUEST['action'] ?? '';

switch($action){

    // =========================
    // Get all prescriptions
    // =========================
    case 'get_all':
        $visit_id = $_GET['visit_id'] ?? null;
        $sql = "SELECT p.*, u.EmpName AS prescribed_by_name FROM tbl_prescriptions p
                LEFT JOIN Users u ON p.prescribed_by = u.EmpID ";
        if($visit_id) $sql .= " WHERE visit_id = ?";
        $sql .= " ORDER BY created_at DESC";

        $stmt = $conn->prepare($sql);
        if($visit_id) $stmt->bind_param("i", $visit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $prescriptions = [];
        while($row = $result->fetch_assoc()){
            $prescriptions[] = $row;
        }
        echo json_encode(['success'=>true,'prescriptions'=>$prescriptions]);
        break;

    // =========================
    // Add prescription
    // =========================
    case 'add':
        $visit_id = $_POST['visit_id'] ?? null;
        $animal_code = $_POST['animal_code'] ?? '';
        $medicine_name = $_POST['medicine_name'] ?? '';
        $dosage = $_POST['dosage'] ?? null;
        $frequency = $_POST['frequency'] ?? null;
        $duration = $_POST['duration'] ?? null;
        $notes = $_POST['notes'] ?? null;
        $prescribed_by = $_POST['prescribed_by'] ?? $currentUser;

        if(!$visit_id || !$animal_code || !$medicine_name){
            echo json_encode(['success'=>false,'message'=>'Missing required fields']);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO tbl_prescriptions
            (visit_id, animal_code, medicine_name, dosage, frequency, duration, notes, prescribed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssssi",$visit_id,$animal_code,$medicine_name,$dosage,$frequency,$duration,$notes,$prescribed_by);
        if($stmt->execute()){
            echo json_encode(['success'=>true,'message'=>'Prescription added','id'=>$stmt->insert_id]);
        }else{
            echo json_encode(['success'=>false,'message'=>$stmt->error]);
        }
        break;

    // =========================
    // Update prescription
    // =========================
    case 'update':
        $id = $_POST['id'] ?? null;
        $visit_id = $_POST['visit_id'] ?? null;
        $animal_code = $_POST['animal_code'] ?? '';
        $medicine_name = $_POST['medicine_name'] ?? '';
        $dosage = $_POST['dosage'] ?? null;
        $frequency = $_POST['frequency'] ?? null;
        $duration = $_POST['duration'] ?? null;
        $notes = $_POST['notes'] ?? null;
        $prescribed_by = $_POST['prescribed_by'] ?? $currentUser;

        if(!$id || !$visit_id || !$animal_code || !$medicine_name){
            echo json_encode(['success'=>false,'message'=>'Missing required fields']);
            exit();
        }

        $stmt = $conn->prepare("UPDATE tbl_prescriptions SET
            visit_id=?, animal_code=?, medicine_name=?, dosage=?, frequency=?, duration=?, notes=?, prescribed_by=?, updated_at=NOW()
            WHERE id=?");
        $stmt->bind_param("issssssii",$visit_id,$animal_code,$medicine_name,$dosage,$frequency,$duration,$notes,$prescribed_by,$id);
        if($stmt->execute()){
            echo json_encode(['success'=>true,'message'=>'Prescription updated']);
        }else{
            echo json_encode(['success'=>false,'message'=>$stmt->error]);
        }
        break;

    // =========================
    // Delete prescription
    // =========================
    case 'delete':
        $id = $_POST['id'] ?? null;
        if(!$id){
            echo json_encode(['success'=>false,'message'=>'Prescription ID required']);
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM tbl_prescriptions WHERE id=?");
        $stmt->bind_param("i",$id);
        if($stmt->execute()){
            echo json_encode(['success'=>true,'message'=>'Prescription deleted']);
        }else{
            echo json_encode(['success'=>false,'message'=>$stmt->error]);
        }
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action']);
        break;
}

$stmt->close();
$conn->close();
?>
