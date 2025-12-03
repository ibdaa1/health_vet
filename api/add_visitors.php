<?php
// health_vet/api/add_visitors.php - API لإدارة الزوار (إضافة، تعديل، حذف، بحث)
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
            $filter_type = $_GET['filter_type'] ?? '';
            $id = $_GET['id'] ?? null;
            
            $sql = "SELECT v.*, u.EmpName as created_by_name 
                    FROM tbl_visitors v 
                    LEFT JOIN Users u ON v.created_by = u.EmpID 
                    WHERE 1=1";
            $params = [];
            $types = '';
            
            if ($id) {
                $sql .= " AND v.visitors_id = ?";
                $params[] = $id;
                $types .= 'i';
            } else {
                if ($search) {
                    $sql .= " AND (visitor_name LIKE ? OR contact_number LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $types .= 'ss';
                }
                if ($filter_date) {
                    $sql .= " AND DATE(visitors_date) = ?";
                    $params[] = $filter_date;
                    $types .= 's';
                }
                if ($filter_name) {
                    $sql .= " AND visitor_name LIKE ?";
                    $params[] = "%$filter_name%";
                    $types .= 's';
                }
                if ($filter_type) {
                    $sql .= " AND visit_type = ?";
                    $params[] = $filter_type;
                    $types .= 's';
                }
            }
            
            $sql .= " ORDER BY visitors_date DESC";
            
            $stmt = $conn->prepare($sql);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            $visitors = [];
            while ($row = $result->fetch_assoc()) {
                $visitors[] = $row;
            }
            
            $response['success'] = true;
            $response['data'] = $visitors;
            $response['employees'] = $employees;
            
            break;
            
        case 'POST': // الإضافة أو التعديل أو الحذف بناءً على البيانات
            if (isset($input['action'])) {
                switch ($input['action']) {
                    case 'delete':
                        if (!canDelete()) {
                            throw new Exception('غير مصرح لك بحذف البيانات.');
                        }
                        
                        if (empty($input['visitors_id'])) {
                            throw new Exception('معرف الزائر مطلوب.');
                        }
                        
                        $visitors_id = $input['visitors_id'];
                        $sql = "DELETE FROM tbl_visitors WHERE visitors_id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $visitors_id);
                        
                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $response['success'] = true;
                                $response['message'] = 'تم حذف الزائر بنجاح.';
                            } else {
                                throw new Exception('لم يتم العثور على الزائر للحذف.');
                            }
                        } else {
                            throw new Exception("خطأ في الحذف: " . $conn->error);
                        }
                        break;
                }
            } elseif (!empty($input['visitors_id'])) {
                // التعديل
                if (!canEdit()) {
                    throw new Exception('غير مصرح لك بتعديل البيانات.');
                }
                
                $visitor_name = $input['visitor_name'] ?? '';
                $contact_number = $input['contact_number'] ?? null;
                $visit_type = $input['visit_type'];
                $entity = $input['entity'] ?? 'Environment & Protected Areas Authority';
                $service_type = $input['service_type'];
                $visitors_date = $input['visitors_date'] ?? date('Y-m-d H:i:s');
                $time_in = $input['time_in'] ?? date('Y-m-d H:i:s');
                $time_out = $input['time_out'] ?? null;
                $updated_by = $_SESSION['user']['EmpID'];
                $visitors_id = $input['visitors_id'];
                
                $sql = "UPDATE tbl_visitors SET 
                        visitors_date = ?, time_in = ?, time_out = ?, visitor_name = ?, contact_number = ?, visit_type = ?, entity = ?, service_type = ?, 
                        updated_by = ? 
                        WHERE visitors_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssii", $visitors_date, $time_in, $time_out, $visitor_name, $contact_number, $visit_type, $entity, $service_type, $updated_by, $visitors_id);
                
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $response['success'] = true;
                        $response['message'] = 'تم تعديل الزائر بنجاح.';
                    } else {
                        throw new Exception('لم يتم العثور على الزائر للتعديل.');
                    }
                } else {
                    throw new Exception("خطأ في التحديث: " . $conn->error);
                }
            } else {
                // الإضافة
                if (!canAdd()) {
                    throw new Exception('غير مصرح لك بإضافة بيانات.');
                }
                
                $required_fields = ['visitor_name', 'visit_type', 'service_type'];
                foreach ($required_fields as $field) {
                    if (empty($input[$field])) {
                        throw new Exception("الحقل مطلوب: $field");
                    }
                }
                
                $visitor_name = $input['visitor_name'];
                $contact_number = $input['contact_number'] ?? null;
                $visit_type = $input['visit_type'];
                $entity = $input['entity'] ?? 'Environment & Protected Areas Authority';
                $service_type = $input['service_type'];
                $visitors_date = $input['visitors_date'] ?? date('Y-m-d H:i:s');
                $time_in = $input['time_in'] ?? date('Y-m-d H:i:s');
                $time_out = $input['time_out'] ?? null;
                $created_by = $_SESSION['user']['EmpID'];
                
                $sql = "INSERT INTO tbl_visitors (visitors_date, time_in, time_out, visitor_name, contact_number, visit_type, entity, service_type, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssssss", $visitors_date, $time_in, $time_out, $visitor_name, $contact_number, $visit_type, $entity, $service_type, $created_by);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['data'] = ['id' => $conn->insert_id];
                    $response['message'] = 'تم إضافة الزائر بنجاح.';
                } else {
                    throw new Exception("خطأ في الإدراج: " . $conn->error);
                }
            }
            break;
            
        default:
            throw new Exception('طريقة الطلب غير مدعومة.');
    }
    
} catch (Exception $e) {
    error_log("Visitors API Error: " . $e->getMessage());
    $response['message'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>