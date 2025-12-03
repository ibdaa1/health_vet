<?php
// process_adoption.php
// Robust handler for adoption actions: approve, reserve, reject, expire/unreserve, note
// Accepts input via POST form-data or application/json. Also accepts GET for testing.
// POST fields:
//  - action: approve|reserve|reject|expire|unreserve|note
//  - adoption_id (optional) OR application_id (optional)
//  - approved_by (optional) EmpID
//  - notes (optional) for action=note
//
// Response: JSON { success: true|false, message: "...", ... }

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
session_start();

// Optional auth guard (uncomment in production)
// if (!isset($_SESSION['user']['EmpID'])) {
//     echo json_encode(['success'=>false,'message'=>'User not authenticated']);
//     exit;
//}
$actor = isset($_SESSION['user']['EmpID']) ? (int)$_SESSION['user']['EmpID'] : 0;

// read inputs (POST form-data preferred, else JSON body, else GET)
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST)) {
        $input = $_POST;
    } else {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) $input = $json;
    }
} else {
    $input = $_REQUEST;
}

$action = isset($input['action']) ? trim($input['action']) : '';
$adoption_id = isset($input['adoption_id']) && $input['adoption_id'] !== '' ? (int)$input['adoption_id'] : 0;
$application_id = isset($input['application_id']) && $input['application_id'] !== '' ? (int)$input['application_id'] : 0;
$approved_by = isset($input['approved_by']) && $input['approved_by'] !== '' ? (int)$input['approved_by'] : ($actor ?: null);
$notes = isset($input['notes']) ? $input['notes'] : '';

if (!$action || (!$adoption_id && !$application_id)) {
    echo json_encode(['success'=>false,'message'=>'Missing parameters: action and adoption_id/application_id required']);
    exit;
}

$validActions = ['approve','reserve','reject','expire','unreserve','note'];
if (!in_array($action, $validActions, true)) {
    echo json_encode(['success'=>false,'message'=>'Invalid action. Allowed: '.implode(',', $validActions)]);
    exit;
}

try {
    $conn->begin_transaction();

    // Handle application-level actions if application_id provided
    if ($application_id) {
        if ($action === 'approve') {
            $stmt = $conn->prepare("UPDATE adoption_applications SET approved_by = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('iii', $approved_by, $actor, $application_id);
            $stmt->execute(); $stmt->close();
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("UPDATE adoption_applications SET approved_by = -1, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('ii', $actor, $application_id);
            $stmt->execute(); $stmt->close();
        } elseif ($action === 'note') {
            $append = "[".date('Y-m-d H:i:s')."] Note by Emp:$actor: " . $notes;
            $stmt = $conn->prepare("UPDATE adoption_applications SET notes = CONCAT(IFNULL(notes,''), ?) , updated_by = ?, updated_at = NOW() WHERE id = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('sii', $append, $actor, $application_id);
            $stmt->execute(); $stmt->close();
        }
        // continue to adoption branch if adoption_id also provided
    }

    // If adoption_id provided: operate on tbl_adoptions and related animal record & movements
    if ($adoption_id) {
        // fetch adoption record
        $stmt = $conn->prepare("SELECT adoption_id, animal_code, Operation_type, adoption_date, notes FROM tbl_adoptions WHERE adoption_id = ? LIMIT 1");
        if (!$stmt) throw new Exception('Prepare failed: '.$conn->error);
        $stmt->bind_param('i', $adoption_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $ad = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$ad) { $conn->rollback(); echo json_encode(['success'=>false,'message'=>'Adoption record not found']); exit; }
        $animal_code = $ad['animal_code'];
        $now = date('Y-m-d H:i:s');

        if ($action === 'approve') {
            // convert (reserved -> adopted) or adopt directly
            $stmt = $conn->prepare("UPDATE tbl_adoptions SET Operation_type = 'adopted', updated_by = ?, updated_at = NOW() WHERE adoption_id = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('ii', $actor, $adoption_id); $stmt->execute(); $stmt->close();

            // set animal inactive
            $stmt = $conn->prepare("UPDATE tbl_animals SET is_active = 'Inactive', updated_by = ? WHERE animal_code = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('is', $actor, $animal_code); $stmt->execute(); $stmt->close();

            // insert animal movement: Out / Adoption
            $note = "Adopted via adoption_id:$adoption_id by Emp:$actor";
            $stmt = $conn->prepare("INSERT INTO tbl_animal_movements (animal_code, room_id, movement_type, movement_date, reason, notes, created_by, updated_by, created_at, updated_at) VALUES (?, NULL, 'Out', ?, 'Adoption', ?, ?, ?, NOW(), NOW())");
            if (!$stmt) throw new Exception($conn->error);
            // param types: s (animal_code), s (movement_date), s (notes), i (created_by), i (updated_by)
            $stmt->bind_param('sssii', $animal_code, $now, $note, $actor, $actor);
            $stmt->execute(); $stmt->close();

            // append note to adoption
            $append = "[" . $now . "] Approved by EmpID:$actor";
            $stmt = $conn->prepare("UPDATE tbl_adoptions SET notes = CONCAT(IFNULL(notes,''), ?) WHERE adoption_id = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('si', $append, $adoption_id); $stmt->execute(); $stmt->close();

            $conn->commit();
            echo json_encode(['success'=>true,'message'=>'Adoption approved. Animal set to Inactive and movement recorded.']);
            exit;
        }

        if ($action === 'reserve') {
            // Prevent reserving an already adopted record
            if (isset($ad['Operation_type']) && $ad['Operation_type'] === 'adopted') {
                $conn->rollback();
                echo json_encode(['success'=>false, 'message' => 'لا يمكن حجز سجل سبق وأن تم اعتماده.']);
                exit;
            }

            // set reserved
            $stmt = $conn->prepare("UPDATE tbl_adoptions SET Operation_type = 'reserved', updated_by = ?, updated_at = NOW() WHERE adoption_id = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('ii', $actor, $adoption_id); $stmt->execute(); $stmt->close();

            // create TemporaryOut movement with expiry info (4 days)
            $expiry = date('Y-m-d H:i:s', strtotime('+4 days'));
            $note = "Reserved via adoption_id:$adoption_id by Emp:$actor; expires on $expiry";
            $stmt = $conn->prepare("INSERT INTO tbl_animal_movements (animal_code, room_id, movement_type, movement_date, reason, notes, created_by, updated_by, created_at, updated_at) VALUES (?, NULL, 'TemporaryOut', ?, 'Adoption', ?, ?, ?, NOW(), NOW())");
            if (!$stmt) throw new Exception($conn->error);
            // param types: s (animal_code), s (movement_date), s (notes), i (created_by), i (updated_by)
            $stmt->bind_param('sssii', $animal_code, $now, $note, $actor, $actor);
            $stmt->execute(); $stmt->close();

            // append note to adoption
            $append = "[" . $now . "] Reserved by EmpID:$actor, expires: $expiry";
            $stmt = $conn->prepare("UPDATE tbl_adoptions SET notes = CONCAT(IFNULL(notes,''), ?) WHERE adoption_id = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('si', $append, $adoption_id); $stmt->execute(); $stmt->close();

            $conn->commit();
            echo json_encode(['success'=>true,'message'=>'Adoption reserved. TemporaryOut movement recorded with 4-day expiry.','expiry'=>$expiry]);
            exit;
        }

        if ($action === 'reject') {
            $append = "[" . $now . "] Rejected by EmpID:$actor";
            $stmt = $conn->prepare("UPDATE tbl_adoptions SET notes = CONCAT(IFNULL(notes,''), ?), updated_by = ?, updated_at = NOW() WHERE adoption_id = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('sii', $append, $actor, $adoption_id);
            $stmt->execute(); $stmt->close();
            $conn->commit();
            echo json_encode(['success'=>true,'message'=>'Adoption rejected.']);
            exit;
        }

        if ($action === 'expire' || $action === 'unreserve') {
            // reservation expired: insert movement In (ReservationExpired), set animal active, append note
            $note = "Reservation expired for adoption_id:$adoption_id; auto-processed on $now";
            $stmt = $conn->prepare("INSERT INTO tbl_animal_movements (animal_code, room_id, movement_type, movement_date, reason, notes, created_by, updated_by, created_at, updated_at) VALUES (?, NULL, 'In', NOW(), 'ReservationExpired', ?, ?, ?, NOW(), NOW())");
            if (!$stmt) { $conn->rollback(); throw new Exception($conn->error); }
            // param types: s (animal_code), s (notes), i (created_by), i (updated_by)
            $stmt->bind_param('ssii', $animal_code, $note, $actor, $actor);
            $stmt->execute(); $stmt->close();

            // set animal active
            $stmt2 = $conn->prepare("UPDATE tbl_animals SET is_active = 'Active', updated_by = ? WHERE animal_code = ?");
            if (!$stmt2) { $conn->rollback(); throw new Exception($conn->error); }
            $stmt2->bind_param('is', $actor, $animal_code);
            $stmt2->execute(); $stmt2->close();

            // append adoption note
            $append = "[" . $now . "] Reservation expired and auto-processed by system";
            $stmt3 = $conn->prepare("UPDATE tbl_adoptions SET notes = CONCAT(IFNULL(notes,''), ?) WHERE adoption_id = ?");
            if (!$stmt3) { $conn->rollback(); throw new Exception($conn->error); }
            $stmt3->bind_param('si', $append, $adoption_id);
            $stmt3->execute(); $stmt3->close();

            $conn->commit();
            echo json_encode(['success'=>true,'message'=>'Reservation expired: animal restored to Active and movement logged.']);
            exit;
        }

        if ($action === 'note') {
            $append = "[" . $now . "] Note by EmpID:$actor: " . $notes;
            $stmt = $conn->prepare("UPDATE tbl_adoptions SET notes = CONCAT(IFNULL(notes,''), ?) WHERE adoption_id = ?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('si', $append, $adoption_id);
            $stmt->execute(); $stmt->close();
            $conn->commit();
            echo json_encode(['success'=>true,'message'=>'Notes appended to adoption record.']);
            exit;
        }
    }

    // Commit any application-only updates
    $conn->commit();
    echo json_encode(['success'=>true,'message'=>'Processed']);
    exit;

} catch (Exception $e) {
    if ($conn) @$conn->rollback();
    error_log('process_adoption error: ' . $e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Server error: ' . $e->getMessage()]);
    exit;
}
?>