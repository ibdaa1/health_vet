<?php
// procedures_api.php
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
    // Get all procedures
    // =========================
    case 'get_all':
        $visit_id = $_GET['visit_id'] ?? null;
        $sql = "SELECT p.*, u.EmpName AS performed_by_name FROM tbl_procedures p
                LEFT JOIN Users u ON p.performed_by = u.EmpID ";
        if($visit_id) $sql .= " WHERE visit_id = ?";
        $sql .= " ORDER BY performed_at DESC";

        $stmt = $conn->prepare($sql);
        if($visit_id) $stmt->bind_param("i", $visit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $procedures = [];
        while($row = $result->fetch_assoc()){
            $procedures[] = $row;
        }
        echo json_encode(['success'=>true,'procedures'=>$procedures]);
        break;

    // =========================
    // Add procedure
    // =========================
    case 'add':
        $visit_id = $_POST['visit_id'] ?? null;
        $animal_code = $_POST['animal_code'] ?? '';
        $procedure_name = $_POST['procedure_name'] ?? '';
        $description = $_POST['description'] ?? null;
        $performed_by = $_POST['performed_by'] ?? $currentUser;
        $performed_at = $_POST['performed_at'] ?? date('Y-m-d H:i:s');
        $notes = $_POST['notes'] ?? null;

        if(!$visit_id || !$animal_code || !$procedure_name){
            echo json_encode(['success'=>false,'message'=>'Missing required fields']);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO tbl_procedures
            (visit_id, animal_code, procedure_name, description, performed_by, performed_at, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssis",$visit_id,$animal_code,$procedure_name,$description,$performed_by,$performed_at,$notes);
        if($stmt->execute()){
            echo json_encode(['success'=>true,'message'=>'Procedure added','id'=>$stmt->insert_id]);
        }else{
            echo json_encode(['success'=>false,'message'=>$stmt->error]);
        }
        break;

    // =========================
    // Update procedure
    // =========================
    case 'update':
        $id = $_POST['id'] ?? null;
        $visit_id = $_POST['visit_id'] ?? null;
        $animal_code = $_POST['animal_code'] ?? '';
        $procedure_name = $_POST['procedure_name'] ?? '';
        $description = $_POST['description'] ?? null;
        $performed_by = $_POST['performed_by'] ?? $currentUser;
        $performed_at = $_POST['performed_at'] ?? date('Y-m-d H:i:s');
        $notes = $_POST['notes'] ?? null;

        if(!$id || !$visit_id || !$animal_code || !$procedure_name){
            echo json_encode(['success'=>false,'message'=>'Missing required fields']);
            exit();
        }

        $stmt = $conn->prepare("UPDATE tbl_procedures SET
            visit_id=?, animal_code=?, procedure_name=?, description=?, performed_by=?, performed_at=?, notes=?, updated_at=NOW()
            WHERE id=?");
        $stmt->bind_param("issssssi",$visit_id,$animal_code,$procedure_name,$description,$performed_by,$performed_at,$notes,$id);
        if($stmt->execute()){
            echo json_encode(['success'=>true,'message'=>'Procedure updated']);
        }else{
            echo json_encode(['success'=>false,'message'=>$stmt->error]);
        }
        break;

    // =========================
    // Delete procedure
    // =========================
    case 'delete':
        $id = $_POST['id'] ?? null;
        if(!$id){
            echo json_encode(['success'=>false,'message'=>'Procedure ID required']);
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM tbl_procedures WHERE id=?");
        $stmt->bind_param("i",$id);
        if($stmt->execute()){
            echo json_encode(['success'=>true,'message'=>'Procedure deleted']);
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
