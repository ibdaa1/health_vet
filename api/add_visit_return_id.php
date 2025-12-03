<?php
// مثال توضيحي: في نهاية منطق إضافة الزيارة بعد successful INSERT
// افترض أن $conn هو mysqli و أنك نفَّذت INSERT INTO tbl_visits (...) ثم:
$insert_id = $conn->insert_id; // بعد execute
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'success' => true,
  'message' => 'Visit added successfully',
  'id' => $insert_id
]);
exit;
?>