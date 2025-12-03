<?php
// health_vet/api/incoming_animal_requests.php - API لإدارة طلبات استلام الحيوانات (إضافة، تعديل، حذف، بحث)
// يدعم: GET للبحث/جلب (مع id لجلب واحد)، POST للإضافة أو التعديل أو الحذف (بناءً على البيانات)

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'فشل في الاتصال بقاعدة البيانات.']));
}

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'data' => [], 'message' => ''];

// دوال الصلاحيات
if (!function_exists('canAdd')) {
    function canAdd() {
        return isset($_SESSION['user']['CanAdd']) && (int)$_SESSION['user']['CanAdd'] === 1;
    }
}
if (!function_exists('canEdit')) {
    function canEdit() {
        return isset($_SESSION['user']['CanEdit']) && (int)$_SESSION['user']['CanEdit'] === 1;
    }
}
if (!function_exists('canDelete')) {
    function canDelete() {
        return isset($_SESSION['user']['CanDelete']) && (int)$_SESSION['user']['CanDelete'] === 1;
    }
}

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول. يرجى تسجيل الدخول.']));
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    // جلب قائمة الموظفين
    $employees_result = $conn->query("SELECT EmpID, EmpName FROM Users ORDER BY EmpName ASC");
    $employees = [];
    while ($row = $employees_result->fetch_assoc()) {
        $employees[] = $row;
    }
    
    switch ($method) {
        case 'GET': // البحث/جلب البيانات (مع فلاتر أو id لجلب واحد)
            $search = $_GET['search'] ?? '';
            $filter_date = $_GET['filter_date'] ?? '';
            $filter_name = $_GET['filter_name'] ?? '';
            $filter_animal_type = $_GET['filter_animal_type'] ?? '';
            $id = $_GET['id'] ?? null;
            
            $sql = "SELECT r.*, u.EmpName as created_by_name 
                    FROM incoming_animal_requests r 
                    LEFT JOIN Users u ON r.created_by = u.EmpID 
                    WHERE 1=1";
            $params = [];
            $types = '';
            
            if ($id) {
                $sql .= " AND r.id = ?";
                $params[] = $id;
                $types .= 'i';
            } else {
                if ($search) {
                    $sql .= " AND (requester_name LIKE ? OR contact_number LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $types .= 'ss';
                }
                if ($filter_date) {
                    $sql .= " AND DATE(request_date) = ?";
                    $params[] = $filter_date;
                    $types .= 's';
                }
                if ($filter_name) {
                    $sql .= " AND requester_name LIKE ?";
                    $params[] = "%$filter_name%";
                    $types .= 's';
                }
                if ($filter_animal_type) {
                    $sql .= " AND animal_type = ?";
                    $params[] = $filter_animal_type;
                    $types .= 's';
                }
            }
            
            $sql .= " ORDER BY request_date DESC";
            
            $stmt = $conn->prepare($sql);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            $requests = [];
            while ($row = $result->fetch_assoc()) {
                $requests[] = $row;
            }
            
            $response['success'] = true;
            $response['data'] = $requests;
            $response['employees'] = $employees;
            
            break;
            
        case 'POST': // الإضافة أو التعديل أو الحذف بناءً على البيانات
            if (isset($input['action'])) {
                switch ($input['action']) {
                    case 'delete':
                        if (!canDelete()) {
                            throw new Exception('غير مصرح لك بحذف البيانات.');
                        }
                        
                        if (empty($input['id'])) {
                            throw new Exception('معرف الطلب مطلوب.');
                        }
                        
                        $id_val = $input['id'];
                        $sql = "DELETE FROM incoming_animal_requests WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $id_val);
                        
                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $response['success'] = true;
                                $response['message'] = 'تم حذف الطلب بنجاح.';
                            } else {
                                throw new Exception('لم يتم العثور على الطلب للحذف.');
                            }
                        } else {
                            throw new Exception("خطأ في الحذف: " . $conn->error);
                        }
                        break;
                }
            } elseif (!empty($input['id'])) {
                // التعديل
                if (!canEdit()) {
                    throw new Exception('غير مصرح لك بتعديل البيانات.');
                }
                
                $request_date = $input['request_date'] ?? date('Y-m-d');
                $requester_name = $input['requester_name'] ?? '';
                $contact_number = $input['contact_number'] ?? null;
                $animal_type = $input['animal_type'];
                $quantity = $input['quantity'] ?? 1;
                $reason_for_abandonment = $input['reason_for_abandonment'] ?? null;
                $submission_method = $input['submission_method'];
                $notes = $input['notes'] ?? null;
                $updated_by = $_SESSION['user']['EmpID'];
                $id_val = $input['id'];
                
                $sql = "UPDATE incoming_animal_requests SET 
                        request_date = ?, requester_name = ?, contact_number = ?, animal_type = ?, quantity = ?, reason_for_abandonment = ?, 
                        submission_method = ?, notes = ?, updated_by = ? 
                        WHERE id = ?";
                $types = "ssssisssii";
                $params = [$request_date, $requester_name, $contact_number, $animal_type, $quantity, $reason_for_abandonment, 
                          $submission_method, $notes, $updated_by, $id_val];
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $response['success'] = true;
                        $response['message'] = 'تم تعديل الطلب بنجاح.';
                    } else {
                        throw new Exception('لم يتم العثور على الطلب للتعديل.');
                    }
                } else {
                    throw new Exception("خطأ في التحديث: " . $conn->error);
                }
            } else {
                // الإضافة
                if (!canAdd()) {
                    throw new Exception('غير مصرح لك بإضافة بيانات.');
                }
                
                $required_fields = ['requester_name', 'animal_type', 'submission_method'];
                foreach ($required_fields as $field) {
                    if (empty($input[$field])) {
                        throw new Exception("الحقل مطلوب: $field");
                    }
                }
                
                $request_date = $input['request_date'] ?? date('Y-m-d');
                $requester_name = $input['requester_name'];
                $contact_number = $input['contact_number'] ?? null;
                $animal_type = $input['animal_type'];
                $quantity = $input['quantity'] ?? 1;
                $reason_for_abandonment = $input['reason_for_abandonment'] ?? null;
                $submission_method = $input['submission_method'];
                $notes = $input['notes'] ?? null;
                $created_by = $_SESSION['user']['EmpID'];
                
                $sql = "INSERT INTO incoming_animal_requests (request_date, requester_name, contact_number, animal_type, quantity, reason_for_abandonment, submission_method, notes, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $types = "ssssissss";
                $params = [$request_date, $requester_name, $contact_number, $animal_type, $quantity, $reason_for_abandonment, 
                          $submission_method, $notes, $created_by];
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['data'] = ['id' => $conn->insert_id];
                    $response['message'] = 'تم إضافة الطلب بنجاح.';
                } else {
                    throw new Exception("خطأ في الإدراج: " . $conn->error);
                }
            }
            break;
            
        default:
            throw new Exception('طريقة الطلب غير مدعومة.');
    }
    
} catch (Exception $e) {
    error_log("Incoming Animal Requests API Error: " . $e->getMessage());
    $response['message'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>