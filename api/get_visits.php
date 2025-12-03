<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

$animal_id = intval($_GET['animal_id'] ?? 0);
if (!$animal_id) {
    echo json_encode(['success' => false, 'message' => 'Missing animal_id']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM tbl_medical_visits WHERE animal_id = ? ORDER BY visit_date DESC");
$stmt->bind_param("i", $animal_id);
$stmt->execute();
$result = $stmt->get_result();

$visits = [];
while ($row = $result->fetch_assoc()) {
    $visits[] = $row;
}

echo json_encode(['success' => true, 'data' => $visits]);

$stmt->close();
$conn->close();
?>
