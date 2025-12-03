<?php
// add_visit.php
// Handles add / update / delete of visits and returns the inserted id on add.
// Compatible with the frontend JS which expects one of: id, visit_id, insertId, etc.
// Supports form-data (POST) and returns JSON.
// Path: htdocs/health_vet/api/add_visit.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';
session_start();

// Require authenticated user
if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}
$currentUser = (int) $_SESSION['user']['EmpID'];

// Read action (add / update / delete / get_all). Default to add when POST without action.
$action = $_POST['action'] ?? $_REQUEST['action'] ?? '';
$action = trim(strtolower($action));

// Allowed enum values for new columns (keep in sync with DB ENUM)
$allowedCaseStatus = ['In Progress', 'Semi Progress', 'Completed'];
$allowedAnimalStatus = ['Adoption', 'Return'];

try {
    if ($action === 'get_all') {
        // Return visits list (includes the new CaseStatus and AnimalStatus)
        $stmt = $conn->prepare("
            SELECT v.id, v.animal_code, v.visit_date, v.visit_type,
                   v.symptoms, v.notes, v.doctor_empid,
                   v.CaseStatus, v.AnimalStatus,
                   d.EmpName AS doctor_name,
                   v.created_by, v.updated_by, v.created_at, v.updated_at
            FROM tbl_visits v
            LEFT JOIN Users d ON v.doctor_empid = d.EmpID
            ORDER BY v.visit_date DESC
        ");
        if (!$stmt) {
            echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$conn->error]);
            exit;
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $visits = [];
        while ($r = $res->fetch_assoc()) $visits[] = $r;
        echo json_encode(['success' => true, 'visits' => $visits]);
        $stmt->close();
        exit;
    }

    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Visit ID required']); exit; }
        $stmt = $conn->prepare("DELETE FROM tbl_visits WHERE id = ?");
        if (!$stmt) { echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$conn->error]); exit; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success'=>true, 'message'=>'Visit deleted successfully']);
        } else {
            echo json_encode(['success'=>false, 'message'=>$stmt->error ?: 'Visit not found']);
        }
        $stmt->close();
        exit;
    }

    if ($action === 'update') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $animal_code = $_POST['animal_code'] ?? '';
        $doctor_empid = isset($_POST['doctor_empid']) && $_POST['doctor_empid'] !== '' ? (int)$_POST['doctor_empid'] : $currentUser;
        $visit_date = $_POST['visit_date'] ?? date('Y-m-d H:i:s');
        $visit_type = $_POST['visit_type'] ?? 'Checkup';
        $symptoms = isset($_POST['symptoms']) ? $_POST['symptoms'] : null;
        $notes = isset($_POST['notes']) ? $_POST['notes'] : null;

        // New fields
        $caseStatus = $_POST['CaseStatus'] ?? 'In Progress';
        $animalStatus = $_POST['AnimalStatus'] ?? 'Return';
        if (!in_array($caseStatus, $allowedCaseStatus, true)) $caseStatus = 'In Progress';
        if (!in_array($animalStatus, $allowedAnimalStatus, true)) $animalStatus = 'Return';

        if (!$id || !$animal_code || !$doctor_empid || !$visit_type) {
            echo json_encode(['success'=>false,'message'=>'Missing required fields']);
            exit;
        }

        $stmt = $conn->prepare("
            UPDATE tbl_visits SET
              animal_code = ?, doctor_empid = ?, visit_date = ?, visit_type = ?,
              CaseStatus = ?, AnimalStatus = ?,
              symptoms = ?, notes = ?, updated_by = ?
            WHERE id = ?
        ");
        if (!$stmt) { echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$conn->error]); exit; }
        // bind types: s i s s s s s s i  => 'sissssssi'
        $stmt->bind_param('sissssssii',
            $animal_code,
            $doctor_empid,
            $visit_date,
            $visit_type,
            $caseStatus,
            $animalStatus,
            $symptoms,
            $notes,
            $currentUser,
            $id
        );
        $stmt->execute();
        if ($stmt->errno) {
            echo json_encode(['success'=>false,'message'=>$stmt->error]);
        } else {
            echo json_encode(['success'=>true,'message'=>'Visit updated successfully','id'=>$id]);
        }
        $stmt->close();
        exit;
    }

    // Default: add new visit (when action is 'add' or empty)
    // Accept POST fields
    $animal_code = $_POST['animal_code'] ?? '';
    $doctor_empid = isset($_POST['doctor_empid']) && $_POST['doctor_empid'] !== '' ? (int)$_POST['doctor_empid'] : $currentUser;
    $visit_date = $_POST['visit_date'] ?? date('Y-m-d H:i:s');
    $visit_type = $_POST['visit_type'] ?? 'Checkup';
    $symptoms = isset($_POST['symptoms']) ? $_POST['symptoms'] : null;
    $notes = isset($_POST['notes']) ? $_POST['notes'] : null;

    // New fields: CaseStatus and AnimalStatus with validation/defaults
    $caseStatus = $_POST['CaseStatus'] ?? 'In Progress';
    $animalStatus = $_POST['AnimalStatus'] ?? 'Return';
    if (!in_array($caseStatus, $allowedCaseStatus, true)) $caseStatus = 'In Progress';
    if (!in_array($animalStatus, $allowedAnimalStatus, true)) $animalStatus = 'Return';

    if (!$animal_code || !$doctor_empid || !$visit_type) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_visits
        (animal_code, doctor_empid, visit_date, visit_type, CaseStatus, AnimalStatus, symptoms, notes, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) { echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$conn->error]); exit; }
    // bind types: s i s s s s s s i i -> 'sissssssi i' => 'sissssssii'
    $stmt->bind_param(
        'sissssssii',
        $animal_code,
        $doctor_empid,
        $visit_date,
        $visit_type,
        $caseStatus,
        $animalStatus,
        $symptoms,
        $notes,
        $currentUser,
        $currentUser
    );
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        // Reliable insert id
        $insert_id = $conn->insert_id;
        // Return multiple common keys for frontend compatibility
        echo json_encode([
            'success' => true,
            'message' => 'Visit added successfully',
            'visit_id' => $insert_id,
            'id' => $insert_id,
            'insertId' => $insert_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error ?: 'Insert failed']);
    }
    $stmt->close();
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
} finally {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) { /* stmt already closed above where applicable */ }
    $conn->close();
}
?>