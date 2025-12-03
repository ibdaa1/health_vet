<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();

$action = $_POST['action'] ?? '';
$upload_dir = __DIR__ . '/../uploads/animal_photos/';

// دالة ضغط الصور
function compress_and_resize_image_and_save($file_tmp_path, $destination_path) {
    if (!extension_loaded('gd')) return false;
    
    $image_info = @getimagesize($file_tmp_path);
    if ($image_info === false) return false;
    
    list($original_width, $original_height, $image_type) = $image_info;
    
    switch ($image_type) {
        case IMAGETYPE_JPEG: 
            $source_image = @imagecreatefromjpeg($file_tmp_path);
            break;
        case IMAGETYPE_PNG: 
            $source_image = @imagecreatefrompng($file_tmp_path);
            imagealphablending($source_image, false);
            imagesavealpha($source_image, true);
            break;
        case IMAGETYPE_GIF: 
            $source_image = @imagecreatefromgif($file_tmp_path);
            break;
        default: return false;
    }
    
    if (!$source_image) return false;
    
    $target_width = 300;
    $target_height = 300;
    $original_ratio = $original_width / $original_height;
    $target_ratio = $target_width / $target_height;
    
    if ($original_ratio > $target_ratio) {
        $new_height = $target_height;
        $new_width = $target_height * $original_ratio;
    } else {
        $new_width = $target_width;
        $new_height = $target_width / $original_ratio;
    }
    
    $virtual_image = imagecreatetruecolor($target_width, $target_height);
    
    if ($image_type == IMAGETYPE_PNG || $image_type == IMAGETYPE_GIF) {
        imagealphablending($virtual_image, false);
        imagesavealpha($virtual_image, true);
        $transparent = imagecolorallocatealpha($virtual_image, 255, 255, 255, 127);
        imagefilledrectangle($virtual_image, 0, 0, $target_width, $target_height, $transparent);
    }
    
    imagecopyresampled($virtual_image, $source_image, 
                      ($target_width - $new_width) / 2, 
                      ($target_height - $new_height) / 2, 
                      0, 0, 
                      $new_width, $new_height, 
                      $original_width, $original_height);
    
    $result = false;
    switch ($image_type) {
        case IMAGETYPE_JPEG:
            $result = imagejpeg($virtual_image, $destination_path, 85);
            break;
        case IMAGETYPE_PNG:
            $result = imagepng($virtual_image, $destination_path, 8);
            break;
        case IMAGETYPE_GIF:
            $result = imagegif($virtual_image, $destination_path);
            break;
    }
    
    imagedestroy($source_image);
    imagedestroy($virtual_image);
    
    return $result;
}

function savePhotoToDatabase($animal_id, $filename, $uploaded_by) {
    global $conn;
    $photo_url = 'uploads/animal_photos/' . $filename;
    $stmt = $conn->prepare("INSERT INTO tbl_animal_photos (animal_id, photo_url, uploaded_by) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $animal_id, $photo_url, $uploaded_by);
    return $stmt->execute();
}

function uploadSingleImage($file_key, $animal_id, $prefix, &$errors, $allowed_extensions) {
    global $upload_dir, $conn;
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $name = $_FILES[$file_key]['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_extensions)) {
        $errors[] = "نوع الملف غير مسموح: " . $name;
        return null;
    }
    if ($_FILES[$file_key]['size'] > 5 * 1024 * 1024) {
        $errors[] = "حجم الملف كبير جداً: " . $name;
        return null;
    }
    $new_file_name = $animal_id . '_' . $prefix . '_' . time() . '.' . $ext;
    $full_destination_path = $upload_dir . $new_file_name;
    if (compress_and_resize_image_and_save($_FILES[$file_key]['tmp_name'], $full_destination_path)) {
        return 'uploads/animal_photos/' . $new_file_name;
    } else {
        $errors[] = "فشل في معالجة الصورة: " . $name;
        return null;
    }
}

function deleteFileIfExists($file_path) {
    $full_path = __DIR__ . '/../' . $file_path;
    if ($file_path && file_exists($full_path)) {
        unlink($full_path);
    }
}

// ========== البحث ==========
if($action === 'search_by_code'){
    $code = $_POST['code'] ?? '';
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    $limit = 10;
    $offset = ($page-1)*$limit;
    $like = "%$code%";
    $stmt = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS id, animal_code FROM tbl_animals WHERE animal_code LIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
    $stmt->bind_param("s",$like);
    $stmt->execute();
    $result = $stmt->get_result();
    $matches = [];
    while($row = $result->fetch_assoc()){
        $matches[] = ['id'=>$row['id'],'animal_code'=>$row['animal_code']];
    }
    $total = $conn->query("SELECT FOUND_ROWS() as total")->fetch_assoc()['total'];
    echo json_encode(['success'=>true,'data'=>$matches,'total'=>$total,'page'=>$page,'pages'=>max(1,ceil($total/$limit))]);
    exit;
}

// ========== جلب بيانات حيوان ==========
if($action === 'get'){
    $id = $_POST['id'] ?? 0;
    $stmt = $conn->prepare("SELECT * FROM tbl_animals WHERE id=?");
    $stmt->bind_param("i",$id);
    if($stmt->execute()){
        $result = $stmt->get_result();
        if($row = $result->fetch_assoc()){
            $stmt_photos = $conn->prepare("SELECT photo_url FROM tbl_animal_photos WHERE animal_id=?");
            $stmt_photos->bind_param("i", $id);
            $stmt_photos->execute();
            $res_p = $stmt_photos->get_result();
            $photos = [];
            while($rp = $res_p->fetch_assoc()) $photos[] = $rp['photo_url'];
            echo json_encode(['success'=>true,'animal'=>$row, 'photos'=>$photos]);
        } else {
            echo json_encode(['success'=>false,'message'=>'لم يتم العثور على الحيوان.']);
        }
    } else {
        echo json_encode(['success'=>false,'message'=>'خطأ في قاعدة البيانات.']);
    }
    exit;
}

// ========== توليد الكود الفريد ==========
if($action === 'check_unique'){
    $animal_type = $_POST['animal_type'] ?? '';
    $animal_source = $_POST['animal_source'] ?? '';
    $registration_date = $_POST['registration_date'] ?? '';
    if(!$animal_type || !$animal_source || !$registration_date){
        echo json_encode(['success'=>false,'message'=>'المدخلات الأساسية مفقودة لتوليد الكود.']);
        exit;
    }
    $type_char = strtoupper(substr($animal_type, 0, 1));
    $source_char = strtoupper(substr($animal_source, 0, 1));
    $year_part = date('Y', strtotime($registration_date));
    $stmt = $conn->prepare("SELECT COUNT(id) AS count FROM tbl_animals WHERE animal_type=? AND animal_source=? AND YEAR(registration_date)=?");
    $stmt->bind_param("sss", $animal_type, $animal_source, $year_part);
    if (!$stmt->execute()) { 
        echo json_encode(['success'=>false,'message'=>'خطأ في استعلام قاعدة البيانات لتوليد التسلسل: ' . $stmt->error]); 
        exit; 
    }
    $res = $stmt->get_result()->fetch_assoc();
    $next_seq = $res['count'] + 1;
    $animal_code = $next_seq . '-' . $type_char . '-' . $source_char . '-' . $year_part;
    echo json_encode([
        'success'=>true,
        'code'=>$animal_code,
        'sub'=>$next_seq
    ]);
    exit;
}

// ========== إضافة ==========
if($action === 'add'){
    $animal_name = $_POST['animal_name'] ?? ''; // إضافة الحقل المطلوب
    $animal_type = $_POST['animal_type'] ?? '';
    $animal_source = $_POST['animal_source'] ?? '';
    $is_active = $_POST['is_active'] ?? 'Active';
    $registration_date = $_POST['registration_date'] ?? '';
    $animal_code = $_POST['animal_code'] ?? '';
    $created_by = $_POST['created_by'] ?? null;
    $gender = $_POST['gender'] ?? 'Unknown';
    $breed = $_POST['breed'] ?? null;
    $color = $_POST['color'] ?? null;
    $marking = $_POST['marking'] ?? null;
    $birth_date = $_POST['birth_date'] ?? null;
    $estimated_age = $_POST['estimated_age'] ?? null;
    $delivered_by_empid = $_POST['delivered_by_empid'] ?? null;
    $owner_name = $_POST['owner_name'] ?? null;
    $owner_phone = $_POST['owner_phone'] ?? null;
    $owner_email = $_POST['owner_email'] ?? null;
    $notes = $_POST['notes'] ?? null;
    $owner_national_id = $_POST['owner_national_id'] ?? null;
    
    if (empty($birth_date)) $birth_date = null;
    if (empty($animal_name)) $animal_name = 'حيوان بدون اسم'; // قيمة افتراضية للحقل المطلوب
    
    if(!$animal_type || !$animal_code){
        echo json_encode(['success'=>false,'message'=>'بيانات التسجيل الأساسية مفقودة.']); 
        exit;
    }
    
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    $upload_errors = [];
    $owner_national_id_photo = null;
    $owner_signature = null;
    
    $conn->begin_transaction();
    try {
        // استعلام معدل ليشمل جميع الحقول المطلوبة
        $stmt = $conn->prepare("
            INSERT INTO tbl_animals (
                animal_name, animal_code, animal_type, animal_source, is_active, registration_date,
                gender, breed, color, marking, birth_date, estimated_age,
                delivered_by_empid, owner_name, owner_phone, owner_email, owner_national_id,
                notes, owner_national_id_photo, owner_signature, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("خطأ في إعداد الاستعلام: " . $conn->error);
        }
        
        // ربط المعلمات مع سلسلة أنواع صحيحة (20 معلمة = 20 حرف)
        $stmt->bind_param("sssssssssssssssssssss",
            $animal_name,      // s
            $animal_code,      // s
            $animal_type,      // s
            $animal_source,    // s
            $is_active,        // s
            $registration_date,// s
            $gender,           // s
            $breed,            // s
            $color,            // s
            $marking,          // s
            $birth_date,       // s
            $estimated_age,    // s
            $delivered_by_empid, // s (سيتم تحويله لسلسلة)
            $owner_name,       // s
            $owner_phone,      // s
            $owner_email,      // s
            $owner_national_id, // s
            $notes,            // s
            $owner_national_id_photo, // s
            $owner_signature,  // s
            $created_by        // s (سيتم تحويله لسلسلة)
        );
        
        if(!$stmt->execute()){ 
            throw new Exception("خطأ في تنفيذ الاستعلام: " . $stmt->error); 
        }
        
        $animal_id = $conn->insert_id;
        
        // رفع صور الحيوان
        $uploaded_files_count = 0;
        if (isset($_FILES['animal_photos']) && is_array($_FILES['animal_photos']['name'])) {
            $files = $_FILES['animal_photos'];
            $max_photos = 3;
            
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception("فشل في إنشاء مجلد رفع الصور");
                }
            }
            
            if (!is_writable($upload_dir)) {
                throw new Exception("المجلد غير قابل للكتابة: " . $upload_dir);
            }
            
            foreach ($files['name'] as $key => $name) {
                if ($uploaded_files_count >= $max_photos) break;
                
                if ($files['error'][$key] !== UPLOAD_ERR_OK) {
                    $upload_errors[] = "خطأ في رفع الملف: " . $files['name'][$key];
                    continue;
                }
                
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if(!in_array($ext, $allowed_extensions)) {
                    $upload_errors[] = "نوع الملف غير مسموح: " . $name;
                    continue;
                }
                
                if ($files['size'][$key] > 5 * 1024 * 1024) {
                    $upload_errors[] = "حجم الملف كبير جداً: " . $name;
                    continue;
                }
                
                $new_file_name = $animal_id . '_' . time() . '_' . $key . '.' . $ext;
                $full_destination_path = $upload_dir . $new_file_name;
                
                if (compress_and_resize_image_and_save($files['tmp_name'][$key], $full_destination_path)) {
                    if (savePhotoToDatabase($animal_id, $new_file_name, $created_by)) {
                        $uploaded_files_count++;
                    } else {
                        if (file_exists($full_destination_path)) {
                            unlink($full_destination_path);
                        }
                        $upload_errors[] = "فشل في حفظ بيانات الصورة: " . $name;
                    }
                } else {
                    $upload_errors[] = "فشل في معالجة الصورة: " . $name;
                }
            }
        }
        
        // رفع صورة الهوية الوطنية
        $new_id_photo = uploadSingleImage('owner_national_id_photo', $animal_id, 'owner_id', $upload_errors, $allowed_extensions);
        if ($new_id_photo) {
            $owner_national_id_photo = $new_id_photo;
            $conn->query("UPDATE tbl_animals SET owner_national_id_photo = '" . $conn->real_escape_string($owner_national_id_photo) . "' WHERE id = $animal_id");
        }
        
        // رفع صورة التوقيع
        $new_sig = uploadSingleImage('owner_signature_photo', $animal_id, 'owner_sig', $upload_errors, $allowed_extensions);
        if ($new_sig) {
            $owner_signature = $new_sig;
            $conn->query("UPDATE tbl_animals SET owner_signature = '" . $conn->real_escape_string($owner_signature) . "' WHERE id = $animal_id");
        }
        
        $conn->commit();
        
        $message = 'تم إضافة الحيوان بنجاح';
        if ($uploaded_files_count > 0) {
            $message .= ' وتم رفع ' . $uploaded_files_count . ' صور';
        }
        if (!empty($upload_errors)) {
            $message .= '. ولكن حدثت بعض الأخطاء: ' . implode(', ', $upload_errors);
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'code' => $animal_code,
            'uploaded_photos' => $uploaded_files_count
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'خطأ في الإضافة: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ========== تعديل ==========
if($action === 'update'){
    $id = $_POST['id'] ?? 0;
    $animal_name = $_POST['animal_name'] ?? ''; // إضافة الحقل المطلوب
    $animal_type = $_POST['animal_type'] ?? '';
    $animal_source = $_POST['animal_source'] ?? '';
    $is_active = $_POST['is_active'] ?? 'Active';
    $registration_date = $_POST['registration_date'] ?? '';
    $animal_code = $_POST['animal_code'] ?? '';
    $gender = $_POST['gender'] ?? 'Unknown';
    $breed = $_POST['breed'] ?? null;
    $color = $_POST['color'] ?? null;
    $marking = $_POST['marking'] ?? null;
    $birth_date = $_POST['birth_date'] ?? null;
    $estimated_age = $_POST['estimated_age'] ?? null;
    $delivered_by_empid = $_POST['delivered_by_empid'] ?? null;
    $owner_name = $_POST['owner_name'] ?? null;
    $owner_phone = $_POST['owner_phone'] ?? null;
    $owner_email = $_POST['owner_email'] ?? null;
    $notes = $_POST['notes'] ?? null;
    $owner_national_id = $_POST['owner_national_id'] ?? null;
    if (empty($birth_date)) $birth_date = null;
    if (empty($animal_name)) $animal_name = 'حيوان بدون اسم'; // قيمة افتراضية للحقل المطلوب
    $updated_by = $_POST['updated_by'] ?? null;
    
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    $upload_errors = [];
    
    // جلب المسارات الحالية للحذف إذا تم رفع جديد
    $stmt_current = $conn->prepare("SELECT owner_national_id_photo, owner_signature, animal_code FROM tbl_animals WHERE id=?");
    $stmt_current->bind_param("i", $id);
    $stmt_current->execute();
    $current = $stmt_current->get_result()->fetch_assoc();
    $current_id_photo = $current['owner_national_id_photo'] ?? null;
    $current_sig = $current['owner_signature'] ?? null;
    $original_code = $current['animal_code'] ?? '';
    
    // التحقق من عدم تكرار الكود الفريد
    if ($animal_code !== $original_code) {
        $stmt_check = $conn->prepare("SELECT id FROM tbl_animals WHERE animal_code=? AND id != ?");
        $stmt_check->bind_param("si", $animal_code, $id);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            echo json_encode(['success'=>false,'message'=>'الرمز الفريد موجود بالفعل.']);
            exit;
        }
    }
    
    // معالجة الصور الجديدة إذا وجدت
    $new_id_photo = uploadSingleImage('owner_national_id_photo', $id, 'owner_id', $upload_errors, $allowed_extensions);
    if ($new_id_photo) {
        deleteFileIfExists($current_id_photo);
        $owner_national_id_photo = $new_id_photo;
    } else {
        $owner_national_id_photo = $_POST['current_owner_national_id_photo'] ?? null;
    }
    
    $new_sig = uploadSingleImage('owner_signature_photo', $id, 'owner_sig', $upload_errors, $allowed_extensions);
    if ($new_sig) {
        deleteFileIfExists($current_sig);
        $owner_signature = $new_sig;
    } else {
        $owner_signature = $_POST['current_owner_signature'] ?? null;
    }
    
    // استعلام معدل ليشمل جميع الحقول المطلوبة
    $stmt = $conn->prepare("
        UPDATE tbl_animals SET 
            animal_name=?, animal_code=?, animal_type=?, animal_source=?, is_active=?, 
            registration_date=?, gender=?, breed=?, color=?, marking=?, birth_date=?, 
            estimated_age=?, delivered_by_empid=?, owner_name=?, owner_phone=?, 
            owner_email=?, owner_national_id=?, notes=?, owner_national_id_photo=?, 
            owner_signature=?, updated_by=? 
        WHERE id=?
    ");
    
    if (!$stmt) {
        echo json_encode(['success'=>false,'message'=>'خطأ في إعداد استعلام التحديث: '.$conn->error]);
        exit;
    }
    
    // ربط المعلمات مع سلسلة أنواع صحيحة (21 معلمة = 20 حرف + i)
    $stmt->bind_param("sssssssssssssssssssssi",
        $animal_name,        // s
        $animal_code,        // s
        $animal_type,        // s
        $animal_source,      // s
        $is_active,          // s
        $registration_date,  // s
        $gender,             // s
        $breed,              // s
        $color,              // s
        $marking,            // s
        $birth_date,         // s
        $estimated_age,      // s
        $delivered_by_empid, // s (سيتم تحويله لسلسلة)
        $owner_name,         // s
        $owner_phone,        // s
        $owner_email,        // s
        $owner_national_id,  // s
        $notes,              // s
        $owner_national_id_photo, // s
        $owner_signature,    // s
        $updated_by,         // s (سيتم تحويله لسلسلة)
        $id                  // i
    );
    
    if($stmt->execute()){
        $message = 'تم تحديث بيانات الحيوان بنجاح';
        if (!empty($upload_errors)) {
            $message .= '. ولكن حدثت بعض الأخطاء: ' . implode(', ', $upload_errors);
        }
        echo json_encode(['success'=>true,'message'=>$message]);
    } else {
        // حذف الملفات الجديدة إذا فشل التحديث
        if ($new_id_photo) deleteFileIfExists($new_id_photo);
        if ($new_sig) deleteFileIfExists($new_sig);
        echo json_encode(['success'=>false,'message'=>'خطأ أثناء التحديث: '.$stmt->error]);
    }
    exit;
}

// ========== حذف ==========
if($action === 'delete'){
    $id = $_POST['id'] ?? 0;
    
    // جلب المسارات للحذف
    $stmt_current = $conn->prepare("SELECT owner_national_id_photo, owner_signature FROM tbl_animals WHERE id=?");
    $stmt_current->bind_param("i", $id);
    $stmt_current->execute();
    $current = $stmt_current->get_result()->fetch_assoc();
    $current_id_photo = $current['owner_national_id_photo'] ?? null;
    $current_sig = $current['owner_signature'] ?? null;
    
    // حذف الصور الشخصية
    deleteFileIfExists($current_id_photo);
    deleteFileIfExists($current_sig);
    
    $stmt_photos = $conn->prepare("SELECT photo_url FROM tbl_animal_photos WHERE animal_id=?");
    $stmt_photos->bind_param("i",$id);
    $stmt_photos->execute();
    $result = $stmt_photos->get_result();
    
    while($row = $result->fetch_assoc()){
        $file = __DIR__ . '/../' . $row['photo_url'];
        if(file_exists($file)) {
            unlink($file);
        }
    }
    
    $conn->query("DELETE FROM tbl_animal_photos WHERE animal_id=$id");
    
    $stmt = $conn->prepare("DELETE FROM tbl_animals WHERE id=?");
    $stmt->bind_param("i",$id);
    
    if($stmt->execute()){
        echo json_encode(['success'=>true,'message'=>'تم حذف الحيوان والصور المرتبطة بنجاح.']);
    } else {
        echo json_encode(['success'=>false,'message'=>'حدث خطأ أثناء الحذف: '.$stmt->error]);
    }
    exit;
}

echo json_encode(['success'=>false,'message'=>'إجراء غير معروف']);
?>