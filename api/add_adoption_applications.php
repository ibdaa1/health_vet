<?php
// health_vet/api/add_adoption_applications.php - FIXED PHONE SEARCH VERSION (search phone with/without leading zero)

ob_start(); // Buffer output
error_reporting(E_ALL);
ini_set('display_errors', 1); // 🔴 Display errors temporarily for testing
ini_set('log_errors', 1);
header('Content-Type: application/json; charset=utf-8');

// --- Global Error Handler ---
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        // Clean output buffer before echoing error
        if (ob_get_level() > 0) ob_clean(); 
        error_log('Fatal server error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
        echo json_encode(['success' => false, 'message' => 'Fatal server error. Check server logs.']);
    }
    // Only flush if output buffering is active
    if (ob_get_level() > 0) ob_end_flush();
});

require_once 'db.php';
session_start();

// --- Authentication & Authorization ---
if (!isset($_SESSION['user']['EmpID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
    exit;
}

function isAdmin() {
    return isset($_SESSION['user']['IsAdmin']) && (int)$_SESSION['user']['IsAdmin'] === 1;
}

// --- Input Handling ---
$method = $_SERVER['REQUEST_METHOD'];
$data = [];

if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
    // Read input stream first, then merge with $_POST for form data/file uploads (if applicable)
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
    // Merge post data (e.g. if signature upload uses form-data)
    $data = array_merge($data, $_POST); 
    
    if (empty($data) && $method !== 'GET') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing input data.']);
        exit;
    }
    $action = $data['action'] ?? strtolower($method);
} elseif ($method === 'GET') {
    $action = $_GET['action'] ?? 'search';
    $data = $_GET;
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed: ' . $method]);
    exit;
}

$user_id = (int)$_SESSION['user']['EmpID']; // Cast to int for safety
$user_name = $_SESSION['user']['EmpName'] ?? 'غير معروف'; // للاستخدام في الرسائل إذا لزم

$admin_actions = ['update', 'approve', 'delete'];
if (in_array($action, $admin_actions) && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => "Forbidden: Admin access required for $action."]);
    exit;
}

// --- Action Switch ---
switch ($action) {
    case 'create':
        handleCreate($conn, $data, $user_id);
        break;
    case 'update':
        handleUpdate($conn, $data, $user_id);
        break;
    case 'approve':
        handleApprove($conn, $data, $user_id, $user_name);
        break;
    case 'delete':
        handleDelete($conn, $data);
        break;
    case 'search':
        handleSearch($conn, $data);
        break;
    case 'get_details':
        handleGetDetails($conn, $data);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
        break;
}

$conn->close();
exit;

// =========================================================
// Functions
// =========================================================

function refValues($arr) {
    $refs = [];
    foreach($arr as $key => $value)
        $refs[$key] = &$arr[$key];
    return $refs;
}

function getCheckboxValue($value) {
    // Converts common true/false indicators to 1 or 0
    return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}

function handleCreate($conn, $data, $user_id) {
    $required = ['full_name', 'email', 'signature'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: $field."]);
            return;
        }
    }

    // FIXED: Removed non-existent columns: 'declaration', 'admin_signature'
    $fields = [
        'full_name', 'nationality', 'age', 'emirates_id', 'email', 'phone', 'housing_area', 'housing_type',
        'is_house_owner', 'landlord_allows_pets', 'has_pet_space', 'has_children', 'has_allergy',
        'has_other_animals', 'other_animals_details', 'main_caretaker', 'pet_outside', 'has_alternate_caretaker',
        'financial_ability', 'animal_care_knowledge', 'vet_commitment', 'long_term_commitment',
        'signature', 'created_by', 'approved_by'
    ];

    $cols = [];
    $placeholders = [];
    $params = [];
    $types = '';
    
    foreach ($fields as $field) {
        $value = $data[$field] ?? null;
        
        // Handle Checkboxes (convert to 1 or 0)
        if (in_array($field, ['is_house_owner', 'landlord_allows_pets', 'has_pet_space', 'has_children', 
                              'has_allergy', 'has_other_animals', 'pet_outside', 'has_alternate_caretaker', 
                              'vet_commitment', 'long_term_commitment'])) {
            $value = getCheckboxValue($value);
        }
        
        // Handle fixed fields
        if ($field === 'created_by') {
            $value = $user_id; // Already cast to int
        } elseif ($field === 'approved_by') {
            $value = null; // Ensure null on creation
        }

        $cols[] = $field;
        $placeholders[] = '?';
        $params[] = $value;
        
        // Determine type - improved for nulls on int fields
        if ($value === null) {
            // Use 'i' for potential int fields if null, but 's' for safety on strings
            if (in_array($field, ['age', 'is_house_owner', 'landlord_allows_pets', 'has_pet_space', 'has_children', 
                                  'has_allergy', 'has_other_animals', 'pet_outside', 'has_alternate_caretaker', 
                                  'vet_commitment', 'long_term_commitment', 'created_by', 'approved_by'])) {
                $types .= 'i'; // Bind NULL as int
            } else {
                $types .= 's';
            }
        } elseif (is_int($value) || is_bool($value)) {
            $types .= 'i';
        } else {
            $types .= 's';
        }
    }
    
    $sql = "INSERT INTO adoption_applications (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    
    // Log for debugging
    error_log("CREATE SQL: " . $sql);
    error_log("CREATE TYPES: " . $types . " (length: " . strlen($types) . ")");
    error_log("CREATE PARAMS count: " . count($params));
    error_log("CREATE PARAMS: " . json_encode($params));
    
    if (!$stmt = $conn->prepare($sql)) {
        $error_msg = 'Prepare failed: ' . $conn->error;
        error_log("CREATE Prepare Error: " . $error_msg);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $error_msg]);
        return;
    }

    // Bind parameters
    $bindParams = array_merge([$types], $params);
    call_user_func_array([$stmt, 'bind_param'], refValues($bindParams));

    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $new_id = $conn->insert_id;
        error_log("CREATE Execute Success: Affected rows = $affected, Insert ID = $new_id");
        
        if ($affected > 0 && $new_id > 0) {
            echo json_encode(['success' => true, 'message' => 'Application created successfully.', 'id' => $new_id]);
        } else {
            $error_msg = "Execute succeeded but no rows affected (affected: $affected) or no ID generated (ID: $new_id). Check table constraints/auto_increment.";
            error_log("CREATE No ID/Affected Error: " . $error_msg);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $error_msg]);
        }
    } else {
        $error_msg = 'Execute failed: ' . $stmt->error;
        error_log("CREATE Execute Error: " . $error_msg);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $error_msg]);
    }
    $stmt->close();
}

function handleUpdate($conn, $data, $user_id) {
    // FIXED: Removed non-existent columns from allFields: 'declaration', 'admin_signature'
    $id = (int)($data['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing ID for update.']);
        return;
    }

    $updateFields = [];
    $params = [];
    $types = '';
    
    // List of ALL fields (for check and conversion) - matching table schema
    $allFields = [
        'full_name', 'nationality', 'age', 'emirates_id', 'email', 'phone', 'housing_area', 'housing_type',
        'is_house_owner', 'landlord_allows_pets', 'has_pet_space', 'has_children', 'has_allergy',
        'has_other_animals', 'other_animals_details', 'main_caretaker', 'pet_outside', 'has_alternate_caretaker',
        'financial_ability', 'animal_care_knowledge', 'vet_commitment', 'long_term_commitment',
        'signature'
    ];
    $ignored = ['id', 'action', 'created_by', 'created_at', 'updated_at', 'approved_by', '_method', 'signature_path'];

    foreach ($allFields as $key) {
        if (array_key_exists($key, $data) && !in_array($key, $ignored)) {
            $value = $data[$key];
            
            // Handle Checkboxes (convert to 1 or 0)
            if (in_array($key, ['is_house_owner', 'landlord_allows_pets', 'has_pet_space', 'has_children', 
                              'has_allergy', 'has_other_animals', 'pet_outside', 'has_alternate_caretaker', 
                              'vet_commitment', 'long_term_commitment'])) {
                 $value = getCheckboxValue($value);
            }
            
            $updateFields[] = "$key = ?";
            $params[] = $value;
            $types .= is_numeric($value) ? 'i' : 's';
        }
    }
    
    $updateFields[] = 'updated_by = ?';
    $params[] = $user_id; // Already int
    $types .= 'i';

    $params[] = $id;
    $types .= 'i';

    if (empty($updateFields)) {
        echo json_encode(['success' => true, 'message' => 'No changes.']);
        return;
    }

    $sql = 'UPDATE adoption_applications SET ' . implode(', ', $updateFields) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?';

    if (!$stmt = $conn->prepare($sql)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        return;
    }

    $bind_params = array_merge([$types], $params);
    call_user_func_array([$stmt, 'bind_param'], refValues($bind_params));

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed or no changes were made.']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
    }
    $stmt->close();
}


function handleApprove($conn, $data, $user_id, $user_name) {
    // FIXED: Removed admin_signature update since column doesn't exist
    $id = (int)($data['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing ID for approve.']);
        return;
    }

    // التحقق من عدم الموافقة السابقة (اختياري: إذا كان approved_by موجودًا بالفعل)
    $checkSql = 'SELECT approved_by FROM adoption_applications WHERE id = ?';
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param('i', $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($checkResult && $checkResult['approved_by'] != null) {
        echo json_encode(['success' => false, 'message' => 'الطلب معتمد بالفعل بواسطة موظف آخر.']);
        return;
    }

    $sql = 'UPDATE adoption_applications SET approved_by = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?';
    $types = 'iii'; 
    $params = [$user_id, $user_id, $id];

    if (!$stmt = $conn->prepare($sql)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        return;
    }

    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // تسجيل في الـ log إذا لزم (اختياري)
            error_log("Application $id approved by user $user_id ($user_name) at " . date('Y-m-d H:i:s'));
            echo json_encode(['success' => true, 'message' => "تم الموافقة بنجاح بواسطة $user_name."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Not found or already approved.']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
    }
    $stmt->close();
}

function handleDelete($conn, $data) {
    $id = (int)($data['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing ID for delete.']);
        return;
    }

    $sql = 'DELETE FROM adoption_applications WHERE id = ?';

    if (!$stmt = $conn->prepare($sql)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        return;
    }

    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Not found.']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
    }
    $stmt->close();
}

function handleSearch($conn, $data) {
    $search_term = trim($data['q'] ?? ''); 
    $results = [];
    $params = [];
    $types = '';

    if (empty($search_term)) {
        // Return latest 10 if no search term
        $sql = 'SELECT id, full_name, phone, emirates_id FROM adoption_applications ORDER BY id DESC LIMIT 10';
    } else {
        // FIXED: Handle leading zero for UAE phone numbers (05xxx -> 5xxx)
        $like_term = '%' . $search_term . '%'; // For name/ID with spaces/symbols
        $clean_search = preg_replace('/[^\d]/', '', $search_term); // Clean to digits only
        $like_clean = '%' . $clean_search . '%'; // For phone numeric match
        $like_phone_no_leading_zero = '%' . ltrim($clean_search, '0') . '%'; // Remove leading zero for DB match
        
        $sql = 'SELECT id, full_name, phone, emirates_id FROM adoption_applications 
                WHERE full_name LIKE ? OR emirates_id LIKE ? OR phone LIKE ? OR phone LIKE ?
                LIMIT 20';
        
        $params = [$like_term, $like_term, $like_clean, $like_phone_no_leading_zero];
        $types = 'ssss';
    }
    
    // Log for debugging
    error_log("SEARCH SQL: " . $sql);
    error_log("SEARCH PARAMS: " . json_encode($params));
    
    if (!$stmt = $conn->prepare($sql)) {
        $error_msg = 'Search Prepare failed: ' . $conn->error . ' | SQL: ' . $sql;
        error_log("SEARCH Prepare Error: " . $error_msg);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $error_msg]);
        return;
    }

    if (!empty($params)) {
        $bind_params = array_merge([$types], $params);
        call_user_func_array([$stmt, 'bind_param'], refValues($bind_params));
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $results = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        'success' => true, 
        'count' => count($results), 
        'data' => $results, 
        'debug_search_term' => $search_term, 
        'debug_clean' => $clean_search ?? 'N/A',
        'debug_phone_variants' => [$like_clean, $like_phone_no_leading_zero ?? 'N/A'],
        'debug_sql' => $sql // Temp debug
    ]);
}

function handleGetDetails($conn, $data) {
    $id = (int)($data['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing ID.']);
        return;
    }

    $sql = 'SELECT * FROM adoption_applications WHERE id = ?';

    if (!$stmt = $conn->prepare($sql)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        return;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appData = $result->fetch_assoc();
    $stmt->close();

    if ($appData) {
        echo json_encode(['success' => true, 'data' => $appData]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Not found.']);
    }
}
?>