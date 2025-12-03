<?php
// health_vet/api/process_interactions.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

// تضمين ملف الاتصال بقاعدة البيانات (يجب أن يوفر كائن mysqli باسم $conn)
require_once 'db.php';
session_start();

// تعيين المنطقة الزمنية للإمارات
date_default_timezone_set('Asia/Dubai');

// ----------------------------------------------------
// الدوال المساعدة لـ mysqli
// ----------------------------------------------------

// دالة مساعدة لـ call_user_func_array لربط المتغيرات (مطلوبة لـ mysqli)
function ref_values($arr){
    if (strnatcmp(phpversion(),'5.3') >= 0)
    {
        $refs = [];
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
    return $arr;
}

// وظيفة لإرجاع استجابة JSON في حالة وجود خطأ وإيقاف التنفيذ
function sendJsonError($message, $db_error = null, $http_code = 200) {
    http_response_code($http_code);
    $response = ['success' => false, 'message' => $message];
    if ($db_error) {
        if (is_object($db_error) && property_exists($db_error, 'error')) {
             $response['db_error'] = $db_error->error;
        } else if (is_object($db_error) && property_exists($db_error, 'error_list')) {
             $response['db_error'] = 'Execution Error: ' . print_r($db_error->error_list, true);
        } else {
             $response['db_error'] = $db_error;
        }
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// وظيفة لحفظ التوقيع (تم تعديلها لتأكيد الحفظ)
function saveSignature($base64_signature, $name_prefix) {
    if (empty($base64_signature) || strpos($base64_signature, 'data:image/') !== 0) {
        return null; // لا يوجد توقيع جديد للإرسال
    }
    
    if (preg_match('/^data:image\/(\w+);base64,/', $base64_signature, $type)) {
        $data_type = strtolower($type[1]);
        $base64_parts = explode(',', $base64_signature);
        $encoded_data = end($base64_parts);
        $decoded_data = base64_decode($encoded_data);

        if ($decoded_data === false) {
            return null;
        }

        // المسار النسبي الصحيح للمشروع: health_vet/uploads/add_visitor_interactions/
        $upload_dir = '../../uploads/add_visitor_interactions/'; 
        
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                 return 'ERROR: Could not create upload directory.'; 
            }
        }

        $file_name = $name_prefix . '_' . time() . '_' . uniqid() . '.' . $data_type;
        $file_path = $upload_dir . $file_name;

        if (file_put_contents($file_path, $decoded_data)) {
            // المسار الذي سيتم حفظه في قاعدة البيانات
            return 'uploads/add_visitor_interactions/' . $file_name;
        }
    }
    return null;
}

// ----------------------------------------------------
// التحقق الأولي والتهيئة
// ----------------------------------------------------

global $conn;
if (!($conn instanceof mysqli) || $conn->connect_error) {
     sendJsonError('Database connection error: $conn is not a valid mysqli object or connection failed.', $conn->connect_error ?? 'Connection object not found.');
}

// تحديد العملية المطلوبة (عادةً تكون POST للـ CRUD و GET للـ SEARCH)
$action = $_REQUEST['action'] ?? null;

// استقبال البيانات (من POST Body أو GET/POST parameters)
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input_data = json_decode(file_get_contents("php://input"), true);
    if (empty($input_data)) {
        $input_data = $_POST;
    }
} else {
    $input_data = $_GET;
}

if (!$action) {
    sendJsonError('Action parameter is missing (required: add, edit, delete, get_one or search).');
}

// ----------------------------------------------------
// منطق التحويل والتجهيز العام للبيانات
// ----------------------------------------------------

$interaction_id = $input_data['id'] ?? null;
$user_id = $_SESSION['user']['EmpID'] ?? 0;

// تحضير البيانات المشتركة (تستخدم فقط في ADD و EDIT)
$visit_date = $input_data['visit_date'] ?? date('Y-m-d');
$visit_type = trim($input_data['visit_type'] ?? '');
$entity = trim($input_data['entity'] ?? '');
$visitor_full_name = trim($input_data['visitor_full_name'] ?? '');
$visitor_age = (int)($input_data['visitor_age'] ?? 0);
$visitor_gender = trim($input_data['visitor_gender'] ?? '');
$visitor_nationality = trim($input_data['visitor_nationality'] ?? '');
$visitor_contact_number = trim($input_data['visitor_contact_number'] ?? '');

// تحويل القيم المنطقية إلى أعداد صحيحة
$allergies_to_animals = filter_var($input_data['allergies_to_animals'] ?? 0, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false ? 0 : 1;
$agree_to_interact = filter_var($input_data['agree_to_interact'] ?? 1, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false ? 0 : 1;
$on_medication = filter_var($input_data['on_medication'] ?? 0, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false ? 0 : 1;
$medication_affects_interaction = filter_var($input_data['medication_affects_interaction'] ?? 0, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false ? 0 : 1;

// بيانات الولي
$parent_guardian_name = trim($input_data['parent_guardian_name'] ?? '');
$parent_guardian_contact = trim($input_data['parent_guardian_contact'] ?? '');
$number_of_children = (int)($input_data['number_of_children'] ?? 0);
$relationship_to_visitor = trim($input_data['relationship_to_visitor'] ?? '');
$disclaimer_signed = filter_var($input_data['disclaimer_signed'] ?? 0, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false ? 0 : 1;


// ----------------------------------------------------
// منطق التوجيه بناءً على 'action'
// ----------------------------------------------------

switch ($action) {
    
    case 'get_one':
        // ------------------
        // عملية جلب سجل واحد (SELECT)
        // ------------------
        if (empty($interaction_id)) {
            sendJsonError('Missing interaction ID for fetching data.');
        }

        $sql = "SELECT * FROM visitor_interactions WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            sendJsonError('Failed to prepare SQL statement for GET_ONE.', $conn);
        }
        
        $stmt->bind_param("i", $interaction_id);

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $data = $result->fetch_assoc();
            $stmt->close();

            if ($data) {
                // تحويل القيم المنطقية إلى 0/1 كأرقام (للتوافق مع HTML)
                foreach(['allergies_to_animals', 'agree_to_interact', 'on_medication', 'medication_affects_interaction', 'disclaimer_signed'] as $field) {
                    $data[$field] = (int)$data[$field];
                }
                // تنظيف الحقول النصية من '0' الفارغة
                $string_fields = ['visitor_full_name', 'entity', 'visitor_gender', 'visitor_nationality', 'visitor_contact_number', 'parent_guardian_name', 'parent_guardian_contact', 'relationship_to_visitor'];
                foreach ($string_fields as $field) {
                    if (isset($data[$field]) && $data[$field] === '0') {
                        $data[$field] = '';
                    }
                }
                echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
            } else {
                sendJsonError('No interaction found with ID: ' . $interaction_id);
            }
        } else {
            $stmt->close();
            sendJsonError('Failed to execute database query for GET_ONE.', $stmt);
        }
        break;
        
    case 'add':
    case 'edit':
        
        // التحقق من الحقول المطلوبة مع التعامل مع '0' كقيمة فارغة
        if (strlen($visitor_full_name) == 0 || $visitor_full_name === '0' || strlen($visit_type) == 0 || $visit_type === '0') {
            sendJsonError('Missing required fields: visitor full name and visit type.');
        }

        // حفظ التوقيعات (يتم حفظها فقط إذا تم إرسال بيانات Base64 جديدة)
        $visitor_signature_path = saveSignature($input_data['visitor_signature'] ?? '', 'visitor');
        $parent_guardian_signature_path = saveSignature($input_data['parent_guardian_signature'] ?? '', 'parent');
        
        // التحقق من أخطاء حفظ التوقيع
        if ($visitor_signature_path !== null && strpos($visitor_signature_path, 'ERROR:') === 0) {
             sendJsonError($visitor_signature_path);
        }
        if ($parent_guardian_signature_path !== null && strpos($parent_guardian_signature_path, 'ERROR:') === 0) {
             sendJsonError($parent_guardian_signature_path);
        }

        if ($action === 'add') {
            // ------------------
            // عملية الإضافة (INSERT)
            // ------------------
            $created_by = $user_id;
            $updated_by = $user_id;

            $sql = "INSERT INTO visitor_interactions (
                visit_date, visit_type, entity, visitor_full_name, visitor_age, visitor_gender, 
                visitor_nationality, visitor_contact_number, allergies_to_animals, agree_to_interact, 
                on_medication, medication_affects_interaction, parent_guardian_name, parent_guardian_contact, 
                number_of_children, relationship_to_visitor, visitor_signature, parent_guardian_signature, 
                disclaimer_signed, created_by, updated_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )";
            
            $params = [
                $visit_date, $visit_type, $entity, $visitor_full_name, $visitor_age, $visitor_gender, 
                $visitor_nationality, $visitor_contact_number, $allergies_to_animals, $agree_to_interact, 
                $on_medication, $medication_affects_interaction, $parent_guardian_name, $parent_guardian_contact, 
                $number_of_children, $relationship_to_visitor, $visitor_signature_path ?? null, $parent_guardian_signature_path ?? null, 
                $disclaimer_signed, $created_by, $updated_by
            ];
            // تصحيح أنواع المتغيرات بناءً على هيكل الجدول (21 متغير: s s s s i s s s i i i i s s i s s s i i i)
            $types = 'ssssisssiiiississsiii'; 
            $message = 'Visitor interaction recorded successfully.';

        } else { // $action === 'edit'
            // ------------------
            // عملية التعديل (UPDATE)
            // ------------------
            if (empty($interaction_id)) {
                sendJsonError('Missing interaction ID for editing.');
            }
            $updated_by = $user_id;
            
            // بناء استعلام UPDATE بشكل ديناميكي
            $sql_parts = [];
            $params = [];
            $types = '';
            
            // الحقول الثابتة
            $fields_map = [
                'visit_date' => $visit_date, 'visit_type' => $visit_type, 'entity' => $entity, 'visitor_full_name' => $visitor_full_name, 
                'visitor_age' => $visitor_age, 'visitor_gender' => $visitor_gender, 'visitor_nationality' => $visitor_nationality, 
                'visitor_contact_number' => $visitor_contact_number, 'allergies_to_animals' => $allergies_to_animals, 
                'agree_to_interact' => $agree_to_interact, 'on_medication' => $on_medication, 'medication_affects_interaction' => $medication_affects_interaction, 
                'parent_guardian_name' => $parent_guardian_name, 'parent_guardian_contact' => $parent_guardian_contact, 
                'number_of_children' => $number_of_children, 'relationship_to_visitor' => $relationship_to_visitor, 
                'disclaimer_signed' => $disclaimer_signed, 'updated_by' => $updated_by
            ];
            
            $fields_types = 'ssssisssiiiissisii'; // تصحيح أنواع الحقول الثابتة (18 حقل: s s s s i s s s i i i i s s i s i i)
            $i = 0;
            foreach ($fields_map as $field => $value) {
                $sql_parts[] = "$field = ?";
                $params[] = $value;
                $types .= $fields_types[$i];
                $i++;
            }
            
            // إضافة حقول التوقيع فقط إذا تم إرسال توقيع جديد (أو null للحذف)
            if (isset($input_data['visitor_signature']) && $input_data['visitor_signature'] === '') {
                $sql_parts[] = "visitor_signature = NULL";
            } elseif ($visitor_signature_path !== null) {
                $sql_parts[] = "visitor_signature = ?";
                $params[] = $visitor_signature_path;
                $types .= 's';
            }
            if (isset($input_data['parent_guardian_signature']) && $input_data['parent_guardian_signature'] === '') {
                $sql_parts[] = "parent_guardian_signature = NULL";
            } elseif ($parent_guardian_signature_path !== null) {
                $sql_parts[] = "parent_guardian_signature = ?";
                $params[] = $parent_guardian_signature_path;
                $types .= 's';
            }
            
            $sql = "UPDATE visitor_interactions SET " . implode(', ', $sql_parts) . " WHERE id = ?";
            $params[] = $interaction_id;
            $types .= 'i';
            $message = 'Visitor interaction updated successfully.';
        }

        try {
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                sendJsonError('Failed to prepare SQL statement.', $conn);
            }
            
            // ربط المتغيرات
            if (!call_user_func_array([$stmt, 'bind_param'], ref_values(array_merge([$types], $params)))) {
                 sendJsonError('Failed to bind parameters. Types: ' . $types . ', Params Count: ' . count($params), $stmt);
            }

            if ($stmt->execute()) {
                $final_id = $action === 'add' ? $conn->insert_id : $interaction_id;
                $stmt->close();
                echo json_encode(['success' => true, 'message' => $message, 'id' => $final_id], JSON_UNESCAPED_UNICODE);
            } else {
                $stmt->close();
                sendJsonError('Failed to execute database operation.', $conn->error); // استخدام $conn->error للحصول على آخر خطأ
            }
        } catch (Exception $e) {
            sendJsonError('Application error: ' . $e->getMessage());
        }
        break;
        
    case 'delete':
        // ------------------
        // عملية الحذف (DELETE)
        // ------------------
        if (empty($interaction_id)) {
             sendJsonError('Missing interaction ID for deletion.');
        }
        $sql = "DELETE FROM visitor_interactions WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) { sendJsonError('Failed to prepare SQL statement.', $conn); }
        $stmt->bind_param("i", $interaction_id);
        if ($stmt->execute()) {
             $message = $stmt->affected_rows > 0 ? 'Interaction deleted successfully.' : 'No interaction found with this ID.';
             $stmt->close();
             echo json_encode(['success' => true, 'message' => $message, 'id' => $interaction_id], JSON_UNESCAPED_UNICODE);
        } else { $stmt->close(); sendJsonError('Failed to execute deletion.', $stmt->error); }
        break;

    case 'search':
        // ------------------
        // عملية البحث (SELECT with filters)
        // ------------------
        $search_term = trim($input_data['search_term'] ?? '');
        $search_date = trim($input_data['search_date'] ?? '');
        $search_phone = trim($input_data['search_phone'] ?? '');
        $search_entity = trim($input_data['search_entity'] ?? '');

        $sql = "SELECT id, visit_date, visitor_full_name, visitor_contact_number, entity FROM visitor_interactions WHERE 1=1";
        $params = [];
        $types = '';
        
        if (!empty($search_term)) { $sql .= " AND visitor_full_name LIKE ?"; $params[] = '%' . $search_term . '%'; $types .= 's'; }
        if (!empty($search_date)) { $sql .= " AND visit_date = ?"; $params[] = $search_date; $types .= 's'; }
        if (!empty($search_phone)) { $sql .= " AND visitor_contact_number LIKE ?"; $params[] = '%' . $search_phone . '%'; $types .= 's'; }
        if (!empty($search_entity)) { $sql .= " AND entity LIKE ?"; $params[] = '%' . $search_entity . '%'; $types .= 's'; }
        
        $sql .= " ORDER BY visit_date DESC";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) { sendJsonError('Failed to prepare SQL statement.', $conn); }
        
        if (!empty($params)) {
             if (!call_user_func_array([$stmt, 'bind_param'], ref_values(array_merge([$types], $params)))) { sendJsonError('Failed to bind search parameters.', $stmt); }
        }

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $results_array = [];
            while($row = $result->fetch_assoc()) { $results_array[] = $row; }
            $stmt->close();
            echo json_encode(['success' => true, 'count' => count($results_array), 'results' => $results_array], JSON_UNESCAPED_UNICODE);
        } else { $stmt->close(); sendJsonError('Failed to execute search query.', $stmt->error); }
        break;
        
    default:
        sendJsonError('Invalid action specified.');
        break;
}

if ($conn) {
    $conn->close();
}
?>