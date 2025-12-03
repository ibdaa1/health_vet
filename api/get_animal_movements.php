<?php
// get_animal_movements.php
// This file fetches all animal movements from tbl_animal_movements and returns them as JSON.
// Compatible with mysqli (using $conn from db.php) for consistency with get_dashboard_summary.php.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Adjust for security in production
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/db.php'; // Assumes this sets up $conn (mysqli connection)
session_start();

// Optional: Authentication check (similar to get_dashboard_summary.php)
if (!isset($_SESSION['user']['EmpID'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Prepare the query: Fetch all movements, ordered by movement_date DESC
    // Based on the DESCRIBE structure: includes all fields, with LIMIT for performance
    $sql = "
        SELECT 
            id, animal_code, room_id, movement_type, movement_date, 
            reason, notes, created_by, updated_by, created_at, updated_at
        FROM tbl_animal_movements 
        ORDER BY movement_date DESC
        LIMIT 500  -- Limit to prevent overload; can be adjusted via GET param if needed
    ";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception('Failed to execute query: ' . $conn->error);
    }
    
    $movements = [];
    while ($row = $result->fetch_assoc()) {
        $movements[] = $row;
    }
    
    // Close result set
    $result->free();
    
    // Return success with data
    echo json_encode([
        'success' => true,
        'data' => $movements,
        'count' => count($movements),
        'message' => 'تم جلب حركات الحيوانات بنجاح'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Error handling: Log and return JSON error
    error_log("Error in get_animal_movements.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'data' => [],
        'message' => 'خطأ في جلب حركات الحيوانات: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} finally {
    // Close connection if not persistent
    if (isset($conn)) {
        $conn->close();
    }
}
?>