<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

$lang = isset($_GET['lang']) && $_GET['lang'] === 'en' ? 'EN' : 'AR';
$nameField = $lang === 'EN' ? 'UnitName_EN' : 'UnitName_AR';

try {
    $stmt = $conn->prepare("SELECT UnitID, $nameField AS Name FROM Units ORDER BY $nameField");
    $stmt->execute();
    $result = $stmt->get_result();
    $units = [];
    while ($row = $result->fetch_assoc()) {
        $units[] = $row;
    }
    echo json_encode(['success'=>true,'data'=>$units], JSON_UNESCAPED_UNICODE);
} catch(Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
?>
