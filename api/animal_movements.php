<?php
// health_vet/api/animal_movements.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Dubai');
require_once 'db.php';

$action = $_GET['action'] ?? '';

function response($success, $message, $data = null) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

// -----------------------------------------------------------
// 1. جلب الحيوانات مع آخر موقع لها
// -----------------------------------------------------------
if ($action === 'animal_status_list') {
    $sql_animals = "SELECT animal_code, animal_name, animal_type 
                    FROM tbl_animals 
                    WHERE is_active = 'Active'";
    $result_animals = $conn->query($sql_animals);
    $animals_data = [];

    if ($result_animals && $result_animals->num_rows > 0) {
        while ($animal = $result_animals->fetch_assoc()) {
            $animal_code = $animal['animal_code'];
            $sql_last_movement = "
                SELECT am.room_id, r.room_name, am.movement_type 
                FROM tbl_animal_movements am 
                LEFT JOIN tbl_rooms r ON am.room_id = r.id 
                WHERE am.animal_code = ? 
                ORDER BY am.movement_date DESC, am.id DESC 
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql_last_movement);
            $stmt->bind_param('s', $animal_code);
            $stmt->execute();
            $last_movement = $stmt->get_result()->fetch_assoc();

            $animal['current_room_id'] = $last_movement['room_id'] ?? null;
            $animal['current_room_name'] = $last_movement['room_name'] ?? null;
            $animal['last_movement_type'] = $last_movement['movement_type'] ?? null;
            $animal['is_in_room'] = (
                $last_movement['movement_type'] == 'In' || 
                $last_movement['movement_type'] == 'Transfer' || 
                $last_movement['movement_type'] == 'TemporaryOut'
            );
            $animals_data[] = $animal;
        }
    }
    response(true, 'Animal status loaded', $animals_data);
}

// -----------------------------------------------------------
// 2. جلب جميع الغرف مع السعة (capacity)
// -----------------------------------------------------------
if ($action === 'all_rooms') {
    $sql = "SELECT id, room_name, room_type, capacity FROM tbl_rooms ORDER BY room_name";
    $result = $conn->query($sql);
    $all_rooms_data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $all_rooms_data[] = $row;
        }
    }
    response(true, 'All rooms loaded', $all_rooms_data);
}

// -----------------------------------------------------------
// 3. جلب قائمة الحركات (عرض الجدول)
// -----------------------------------------------------------
if ($action === 'list') {
    $animal_filter = $_GET['animal_filter'] ?? '';
    $room_filter = $_GET['room_filter'] ?? '';
    $type_filter = $_GET['type_filter'] ?? '';
    $date_filter = $_GET['date_filter'] ?? '';

    $sql = "SELECT am.*, r.room_name, a.animal_name,
                   cu.EmpName AS created_by_name,
                   uu.EmpName AS updated_by_name
            FROM tbl_animal_movements am
            LEFT JOIN tbl_rooms r ON am.room_id = r.id
            LEFT JOIN tbl_animals a ON am.animal_code = a.animal_code
            LEFT JOIN Users cu ON am.created_by = cu.EmpID
            LEFT JOIN Users uu ON am.updated_by = uu.EmpID
            WHERE 1=1";

    if (!empty($animal_filter)) {
        $sql .= " AND am.animal_code = '" . $conn->real_escape_string($animal_filter) . "'";
    }
    if (!empty($room_filter)) {
        $sql .= " AND am.room_id = " . intval($room_filter);
    }
    if (!empty($type_filter)) {
        $sql .= " AND am.movement_type = '" . $conn->real_escape_string($type_filter) . "'";
    }
    if (!empty($date_filter)) {
        $sql .= " AND DATE(am.movement_date) = '" . $conn->real_escape_string($date_filter) . "'";
    }

    $sql .= " ORDER BY am.movement_date DESC, am.id DESC";
    $result = $conn->query($sql);

    if (!$result) {
        response(false, 'Database query failed: ' . $conn->error);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    response(true, 'Data loaded', $rows);
}

// -----------------------------------------------------------
// 4. جلب حركة محددة (للتعديل) + إرجاع قائمة الغرف
// -----------------------------------------------------------
if ($action === 'get' && isset($_GET['id'])) {
    $id = intval($_GET['id']);

    $sql = "SELECT am.*, r.room_name 
            FROM tbl_animal_movements am
            LEFT JOIN tbl_rooms r ON am.room_id = r.id
            WHERE am.id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) response(false, 'Prepare failed: ' . $conn->error);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!($res && $res->num_rows)) {
        response(false, 'Record not found');
    }
    $record = $res->fetch_assoc();

    $rooms = [];
    $sql_rooms = "SELECT id, room_name, room_type, capacity FROM tbl_rooms ORDER BY room_name";
    $res_rooms = $conn->query($sql_rooms);
    if ($res_rooms) {
        while ($r = $res_rooms->fetch_assoc()) {
            $rooms[] = $r;
        }
    }

    response(true, 'Record found', ['movement' => $record, 'all_rooms' => $rooms]);
}

// -----------------------------------------------------------
// 5. إضافة أو تعديل حركة مع التحقق من السعة
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $animal_code = $conn->real_escape_string($_POST['animal_code'] ?? '');
    $room_id = empty($_POST['room_id']) ? null : intval($_POST['room_id']);
    $movement_type = $conn->real_escape_string($_POST['movement_type'] ?? 'In');
    $movement_date = $conn->real_escape_string($_POST['movement_date'] ?? date('Y-m-d H:i:s'));
    $reason = $conn->real_escape_string($_POST['reason'] ?? 'Transfer');
    $notes = $conn->real_escape_string($_POST['notes'] ?? '');
    $created_by = intval($_POST['created_by'] ?? 0);
    $updated_by = intval($_POST['updated_by'] ?? 0);

    if (empty($animal_code)) {
        response(false, 'Animal code is required');
    }

    $check_animal = $conn->prepare("SELECT animal_code FROM tbl_animals WHERE animal_code = ?");
    $check_animal->bind_param('s', $animal_code);
    $check_animal->execute();
    if (!$check_animal->get_result()->num_rows) {
        response(false, 'Animal not found');
    }

    $room_id_param = $room_id !== null ? $room_id : null;

    // تحديث حركة
    if ($id > 0) {
        if (!$updated_by) {
            response(false, 'Updated By (EmpID) required for update');
        }

        $sql = "UPDATE tbl_animal_movements SET 
                    animal_code=?,
                    room_id=?,
                    movement_type=?,
                    movement_date=?,
                    reason=?,
                    notes=?,
                    updated_by=?,
                    updated_at=NOW()
                WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sissssii', $animal_code, $room_id_param, $movement_type, $movement_date, $reason, $notes, $updated_by, $id);

        if ($stmt->execute()) {
            response(true, 'تم تحديث الحركة بنجاح ✅');
        } else {
            response(false, 'Update failed: ' . $conn->error);
        }

    } else {
        // إضافة جديدة
        if (!$created_by) {
            response(false, 'Created By (EmpID) required for insert');
        }

        // التحقق من السعة إذا كانت حركة إضافة أو نقل (غير خروج)
        if ($room_id_param !== null && ($movement_type === 'In' || $movement_type === 'Transfer')) {
            // حساب عدد الحيوانات الحالية في الغرفة (بناءً على آخر حركة لكل حيوان نشيط)
            $sql_current_count = "SELECT COUNT(DISTINCT am.animal_code) as count 
                                  FROM tbl_animal_movements am 
                                  JOIN tbl_animals a ON am.animal_code = a.animal_code 
                                  WHERE a.is_active = 'Active' 
                                  AND am.room_id = ? 
                                  AND am.movement_type IN ('In', 'Transfer') 
                                  AND am.id = (
                                      SELECT MAX(am2.id) 
                                      FROM tbl_animal_movements am2 
                                      WHERE am2.animal_code = am.animal_code 
                                  )";
            $stmt_count = $conn->prepare($sql_current_count);
            $stmt_count->bind_param('i', $room_id_param);
            $stmt_count->execute();
            $current_count_result = $stmt_count->get_result()->fetch_assoc();
            $current_count = intval($current_count_result['count']);

            // جلب سعة الغرفة
            $sql_capacity = "SELECT capacity FROM tbl_rooms WHERE id = ?";
            $stmt_capacity = $conn->prepare($sql_capacity);
            $stmt_capacity->bind_param('i', $room_id_param);
            $stmt_capacity->execute();
            $capacity_result = $stmt_capacity->get_result()->fetch_assoc();
            $capacity = intval($capacity_result['capacity'] ?? 5);

            if ($current_count >= $capacity) {
                response(false, 'الغرفة ممتلئة بالكامل (' . $current_count . '/' . $capacity . '). لا يمكن إضافة حيوانات جديدة.');
            }
        }

        $sql = "INSERT INTO tbl_animal_movements 
                (animal_code, room_id, movement_type, movement_date, reason, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sissssi', $animal_code, $room_id_param, $movement_type, $movement_date, $reason, $notes, $created_by);

        if ($stmt->execute()) {
            response(true, 'تمت إضافة الحركة بنجاح ✅');
        } else {
            response(false, 'Insert failed: ' . $conn->error);
        }
    }
}

// -----------------------------------------------------------
// 6. حذف حركة
// -----------------------------------------------------------
if ($action === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $sql = "DELETE FROM tbl_animal_movements WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        response(true, 'تم حذف الحركة بنجاح 🗑️');
    } else {
        response(false, 'Delete failed: ' . $conn->error);
    }
}

// -----------------------------------------------------------
// طلب غير معروف
// -----------------------------------------------------------
response(false, 'Invalid request');
?>