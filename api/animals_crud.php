<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // إخفاء الأخطاء في بيئة الإنتاج لمنع كسر استجابة JSON

header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();

$action = $_POST['action'] ?? '';
$upload_dir = __DIR__ . '/../uploads/animal_photos/';
$signature_upload_dir = __DIR__ . '/../uploads/animal_signatures/';

// دالة ضغط الصور (غير مُعدلة)
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
    
    $target_width = 800;
    $target_height = 800;
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
            $result = imagejpeg($virtual_image, $destination_path, 95);
            break;
        case IMAGETYPE_PNG:
            $result = imagepng($virtual_image, $destination_path, 6);
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

// دالة حفظ التوقيع (غير مُعدلة)
function saveSignatureImage($base64_data, $animal_id, $sig_upload_dir) {
    if (empty($base64_data) || $base64_data === 'null') return null;

    if (preg_match('/^data:image\/(.*?);base64,/', $base64_data, $matches)) {
        $base64_data = substr($base64_data, strpos($base64_data, ',') + 1);
        $ext = ($matches[1] === 'jpeg') ? 'jpg' : $matches[1];
    } else {
        return null;
    }

    $image_data = base64_decode($base64_data);
    if ($image_data === false) return null;

    if (!is_dir($sig_upload_dir)) {
        if (!mkdir($sig_upload_dir, 0755, true)) {
            error_log("فشل في إنشاء مجلد التوقيعات: " . $sig_upload_dir);
            return null;
        }
    }
    
    $new_filename = 'sig_' . $animal_id . '_' . time() . '.' . $ext;
    $full_destination_path = $sig_upload_dir . $new_filename;

    if (file_put_contents($full_destination_path, $image_data)) {
        return 'uploads/animal_signatures/' . $new_filename;
    }

    return null;
}

// دالة تنظيف المدخلات (sanitizeInput)
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// ========== البحث المتقدم ==========
if($action === 'search_by_field'){
    $field = sanitizeInput($_POST['field'] ?? '');
    $value = sanitizeInput($_POST['value'] ?? '');
    $page = intval($_POST['page'] ?? 1);
    $limit = 10;
    $offset = ($page-1)*$limit;
    $like = "%$value%";
    
    $allowedFields = ['animal_code', 'animal_name', 'breed', 'color', 'country_of_origin', 'owner_name'];
    if (!in_array($field, $allowedFields)) {
        echo json_encode(['success'=>false,'message'=>'حقل البحث غير صالح.']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS id, animal_code, animal_name, breed, color FROM tbl_animals WHERE $field LIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
    $stmt->bind_param("s",$like);
    $stmt->execute();
    $result = $stmt->get_result();
    $matches = [];
    while($row = $result->fetch_assoc()){
        $matches[] = ['id'=>$row['id'],'animal_code'=>$row['animal_code'], 'animal_name'=>$row['animal_name'], 'breed'=>$row['breed'], 'color'=>$row['color']];
    }
    $total = $conn->query("SELECT FOUND_ROWS() as total")->fetch_assoc()['total'];
    echo json_encode(['success'=>true,'data'=>$matches,'total'=>$total,'page'=>$page,'pages'=>max(1,ceil($total/$limit))]);
    exit;
}

// ========== البحث ==========
if($action === 'search_by_code'){
    $code = sanitizeInput($_POST['code'] ?? '');
    $page = intval($_POST['page'] ?? 1);
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
    $id = intval($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM tbl_animals WHERE id=?");
    $stmt->bind_param("i",$id);
    if($stmt->execute()){
        $result = $stmt->get_result();
        if($row = $result->fetch_assoc()){
            // معالجة تاريخ الميلاد: تحويل '0000-00-00' إلى null
            if ($row['birth_date'] === '0000-00-00') {
                $row['birth_date'] = null;
            }
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

// ========== حذف صورة فردية (مع transaction) ==========
if($action === 'delete_photo'){
    $photo_url = sanitizeInput($_POST['photo_url'] ?? '');
    $animal_id = intval($_POST['animal_id'] ?? 0);
    if (empty($photo_url) || !$animal_id) {
        echo json_encode(['success'=>false,'message'=>'مسار الصورة أو معرف الحيوان مفقود.']);
        exit;
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT photo_url FROM tbl_animal_photos WHERE photo_url=? AND animal_id=? FOR UPDATE");
        $stmt->bind_param("si", $photo_url, $animal_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if($row = $result->fetch_assoc()){
            $file_path = __DIR__ . '/../' . $row['photo_url'];
            if(file_exists($file_path)){
                if (!unlink($file_path)) {
                    throw new Exception("فشل في حذف الملف: " . $file_path);
                }
            }
            $stmt_del = $conn->prepare("DELETE FROM tbl_animal_photos WHERE photo_url=? AND animal_id=?");
            $stmt_del->bind_param("si", $photo_url, $animal_id);
            if(!$stmt_del->execute()){ 
                throw new Exception("خطأ في حذف السجل: " . $stmt_del->error);
            }
            if ($stmt_del->affected_rows !== 1) {
                // قد يحدث هذا في حالات نادرة، لكن نكتفي بالتحذير
            }
            $conn->commit();
            echo json_encode(['success'=>true,'message'=>'تم حذف الصورة بنجاح.']);
        } else {
            $conn->rollback();
            echo json_encode(['success'=>false,'message'=>'الصورة غير موجودة في قاعدة البيانات.']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("خطأ في delete_photo: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ========== توليد الكود الفريد ==========
if($action === 'check_unique'){
    $animal_type = sanitizeInput($_POST['animal_type'] ?? '');
    $animal_source = sanitizeInput($_POST['animal_source'] ?? '');
    $registration_date = sanitizeInput($_POST['registration_date'] ?? '');
    if(!$animal_type || !$animal_source || !$registration_date){
        echo json_encode(['success'=>false,'message'=>'المدخلات الأساسية مفقودة لتوليد الكود.']);
        exit;
    }
    $type_char = strtoupper(substr($animal_type, 0, 2));
    $source_char = strtoupper(substr($animal_source, 0, 2));
    $year_part = date('Y', strtotime($registration_date));
    
    $stmt = $conn->prepare("SELECT MAX(SUB) AS max_sub FROM tbl_animals WHERE animal_type=? AND animal_source=? AND YEAR(registration_date)=?");
    $stmt->bind_param("sss", $animal_type, $animal_source, $year_part);
    if (!$stmt->execute()) { 
        echo json_encode(['success'=>false,'message'=>'خطأ في استعلام قاعدة البيانات لتوليد التسلسل: ' . $stmt->error]); 
        exit; 
    }
    $res = $stmt->get_result()->fetch_assoc();
    $next_seq = ($res['max_sub'] ?? 0) + 1;
    $animal_code = $next_seq . '-' . $type_char . '-' . $source_char . '-' . $year_part;
    
    echo json_encode([
        'success'=>true,
        'code'=>$animal_code,
        'sub_seq'=>$next_seq
    ]);
    exit;
}

// ========== إضافة (مع معالجة الخطأ 4) ==========
if($action === 'add'){
    // تنظيف المدخلات
    $animal_name = sanitizeInput($_POST['animal_name'] ?? '');
    $animal_type = sanitizeInput($_POST['animal_type'] ?? '');
    $animal_source = sanitizeInput($_POST['animal_source'] ?? '');
    $is_active = sanitizeInput($_POST['is_active'] ?? 'Active');
    $registration_date = sanitizeInput($_POST['registration_date'] ?? '');
    $created_by = intval($_POST['created_by'] ?? 0);
    $gender = sanitizeInput($_POST['gender'] ?? 'Unknown');
    $breed = sanitizeInput($_POST['breed'] ?? null);
    $color = sanitizeInput($_POST['color'] ?? null);
    $marking = sanitizeInput($_POST['marking'] ?? null);
    $birth_date = sanitizeInput($_POST['birth_date'] ?? null);
    $estimated_age = sanitizeInput($_POST['estimated_age'] ?? null);
    $delivered_by_empid = intval($_POST['delivered_by_empid'] ?? null);
    $owner_name = sanitizeInput($_POST['owner_name'] ?? null);
    $owner_phone = sanitizeInput($_POST['owner_phone'] ?? null);
    $owner_email = filter_var($_POST['owner_email'] ?? null, FILTER_SANITIZE_EMAIL);
    $owner_signature_base64 = $_POST['owner_signature'] ?? null;
    $notes = sanitizeInput($_POST['notes'] ?? null);
    $country_of_origin = sanitizeInput($_POST['country_of_origin'] ?? 'United Arab Emirates');
    $owner_national_id = sanitizeInput($_POST['owner_national_id'] ?? null);
    
    // معالجة صورة الهوية الوطنية
    $owner_national_id_photo = null;
    if(isset($_FILES['owner_national_id_photo']) && $_FILES['owner_national_id_photo']['error'] === UPLOAD_ERR_OK) {
        $id_file = $_FILES['owner_national_id_photo'];
        $ext = strtolower(pathinfo($id_file['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        
        if(in_array($ext, $allowed_ext) && $id_file['size'] <= 5 * 1024 * 1024) {
            $new_filename = 'national_id_' . time() . '.' . $ext;
            $destination = $upload_dir . $new_filename;
            
            if(!is_dir($upload_dir)) {
                 if (!mkdir($upload_dir, 0755, true)) {
                    error_log("فشل في إنشاء مجلد animal_photos"); 
                 }
            }

            if(move_uploaded_file($id_file['tmp_name'], $destination)) {
                $owner_national_id_photo = 'uploads/animal_photos/' . $new_filename;
            }
        }
    }
    
    // معالجة تاريخ الميلاد: إذا فارغ أو '0000-00-00'، اجعله null
    if (empty($birth_date) || $birth_date === '0000-00-00') $birth_date = null;
    if (empty($animal_name)) $animal_name = 'حيوان بدون اسم';
    
    if(!$animal_type || !$animal_source || !$registration_date || !$created_by){ 
        echo json_encode(['success'=>false,'message'=>'بيانات التسجيل الأساسية مفقودة.']); 
        exit;
    }
    
    $conn->begin_transaction();
    try {
        $year_part = date('Y', strtotime($registration_date));
        $stmt_sub = $conn->prepare("SELECT MAX(SUB) AS max_sub FROM tbl_animals WHERE animal_type=? AND animal_source=? AND YEAR(registration_date)=? FOR UPDATE");
        $stmt_sub->bind_param("sss", $animal_type, $animal_source, $year_part);
        $stmt_sub->execute();
        $res_sub = $stmt_sub->get_result()->fetch_assoc();
        $sub_seq = ($res_sub['max_sub'] ?? 0) + 1;
        
        $type_char = strtoupper(substr($animal_type, 0, 2));
        $source_char = strtoupper(substr($animal_source, 0, 2));
        $animal_code = $sub_seq . '-' . $type_char . '-' . $source_char . '-' . $year_part;
        
        $owner_signature_db_path = null;
        $stmt = $conn->prepare("
            INSERT INTO tbl_animals (
                animal_name, SUB, animal_code, animal_type, animal_source, is_active, registration_date,
                gender, breed, color, marking, birth_date, estimated_age,
                delivered_by_empid, owner_name, owner_phone, owner_email, owner_signature,
                notes, created_by, country_of_origin, owner_national_id, owner_national_id_photo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("خطأ في إعداد استعلام الإدخال: " . $conn->error);
        }
        
        $stmt->bind_param("sisssssssssssssssssssss",
            $animal_name, $sub_seq, $animal_code, $animal_type, $animal_source,
            $is_active, $registration_date, $gender, $breed, $color, $marking,
            $birth_date, $estimated_age, $delivered_by_empid, $owner_name,
            $owner_phone, $owner_email, $owner_signature_db_path, $notes,
            $created_by, $country_of_origin, $owner_national_id, $owner_national_id_photo
        );
        
        if(!$stmt->execute()){ 
            $error = $stmt->error;
            if (strpos($error, 'Duplicate entry') !== false) {
                throw new Exception("الحيوان موجود بالفعل (كود مكرر).");
            }
            throw new Exception("خطأ في تنفيذ استعلام الإدخال: " . $error); 
        }
        
        $animal_id = $conn->insert_id;
        
        if ($owner_signature_base64) {
            $new_sig_path = saveSignatureImage($owner_signature_base64, $animal_id, $signature_upload_dir);
            if ($new_sig_path) {
                $owner_signature_db_path = $new_sig_path;
                $stmt_update_sig = $conn->prepare("UPDATE tbl_animals SET owner_signature=? WHERE id=?");
                $stmt_update_sig->bind_param("si", $owner_signature_db_path, $animal_id);
                if (!$stmt_update_sig->execute()) {
                    error_log("فشل تحديث مسار التوقيع للحيوان ID: " . $animal_id);
                }
            }
        }

        $uploaded_files_count = 0;
        $upload_errors = [];
        
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
                
                // ✨ التعديل هنا: معالجة الخطأ رقم 4 (UPLOAD_ERR_NO_FILE)
                if ($files['error'][$key] === UPLOAD_ERR_NO_FILE) {
                    continue; 
                }

                if ($files['error'][$key] !== UPLOAD_ERR_OK) {
                    $error_code = $files['error'][$key];
                    $upload_errors[] = "خطأ في رفع الملف (رمز الخطأ: {$error_code}): " . $files['name'][$key]; 
                    continue;
                }
                
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
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
            'animal_id' => $animal_id,
            'uploaded_photos' => $uploaded_files_count
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        if (!empty($owner_signature_db_path) && file_exists(__DIR__ . '/../' . $owner_signature_db_path)) {
            @unlink(__DIR__ . '/../' . $owner_signature_db_path);
        }
        
        echo json_encode([
            'success' => false,
            'message' => 'خطأ في الإضافة: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ========== تعديل (مع معالجة الخطأ 4) ==========
if($action === 'update'){
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success'=>false,'message'=>'معرف الحيوان مفقود.']);
        exit;
    }
    
    // تنظيف المدخلات
    $animal_name = sanitizeInput($_POST['animal_name'] ?? '');
    $animal_type = sanitizeInput($_POST['animal_type'] ?? '');
    $animal_source = sanitizeInput($_POST['animal_source'] ?? '');
    $is_active = sanitizeInput($_POST['is_active'] ?? 'Active');
    $registration_date = sanitizeInput($_POST['registration_date'] ?? '');
    $animal_code = sanitizeInput($_POST['animal_code'] ?? ''); 
    $sub_seq = intval($_POST['sub_seq'] ?? null);
    $gender = sanitizeInput($_POST['gender'] ?? 'Unknown');
    $breed = sanitizeInput($_POST['breed'] ?? null);
    $color = sanitizeInput($_POST['color'] ?? null);
    $marking = sanitizeInput($_POST['marking'] ?? null);
    $birth_date = sanitizeInput($_POST['birth_date'] ?? null);
    $estimated_age = sanitizeInput($_POST['estimated_age'] ?? null);
    $delivered_by_empid = intval($_POST['delivered_by_empid'] ?? null);
    $owner_name = sanitizeInput($_POST['owner_name'] ?? null);
    $owner_phone = sanitizeInput($_POST['owner_phone'] ?? null);
    $owner_email = filter_var($_POST['owner_email'] ?? null, FILTER_SANITIZE_EMAIL);
    $notes = sanitizeInput($_POST['notes'] ?? null);
    $owner_signature_base64 = $_POST['owner_signature'] ?? null;
    $country_of_origin = sanitizeInput($_POST['country_of_origin'] ?? 'United Arab Emirates');
    $owner_national_id = sanitizeInput($_POST['owner_national_id'] ?? null);
    $current_owner_national_id_photo = sanitizeInput($_POST['current_owner_national_id_photo'] ?? null);
    $current_owner_signature = sanitizeInput($_POST['current_owner_signature'] ?? null);
    $updated_by = intval($_POST['updated_by'] ?? null);
    
    // معالجة تاريخ الميلاد: إذا فارغ أو '0000-00-00'، اجعله null
    if (empty($birth_date) || $birth_date === '0000-00-00') $birth_date = null;
    if (empty($animal_name)) $animal_name = 'حيوان بدون اسم';
    
    if(!$updated_by){ 
        echo json_encode(['success'=>false,'message'=>'معرف المُحدث مفقود.']); 
        exit;
    }
    
    $conn->begin_transaction(); 
    try {
        $owner_signature_db_path = $current_owner_signature;
        if (!empty($owner_signature_base64) && $owner_signature_base64 !== 'null') {
            $new_sig_path = saveSignatureImage($owner_signature_base64, $id, $signature_upload_dir);
            if ($new_sig_path) {
                if ($current_owner_signature && file_exists(__DIR__ . '/../' . $current_owner_signature)) {
                    @unlink(__DIR__ . '/../' . $current_owner_signature);
                }
                $owner_signature_db_path = $new_sig_path;
            }
        }
        
        $owner_national_id_photo = $current_owner_national_id_photo;
        if(isset($_FILES['owner_national_id_photo']) && $_FILES['owner_national_id_photo']['error'] === UPLOAD_ERR_OK) {
            $id_file = $_FILES['owner_national_id_photo'];
            $ext = strtolower(pathinfo($id_file['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            
            if(in_array($ext, $allowed_ext) && $id_file['size'] <= 5 * 1024 * 1024) {
                $new_filename = 'national_id_' . time() . '.' . $ext;
                $destination = $upload_dir . $new_filename;
                
                if(move_uploaded_file($id_file['tmp_name'], $destination)) {
                    $owner_national_id_photo = 'uploads/animal_photos/' . $new_filename;
                    if($current_owner_national_id_photo && file_exists(__DIR__ . '/../' . $current_owner_national_id_photo)) {
                        unlink(__DIR__ . '/../' . $current_owner_national_id_photo);
                    }
                }
            }
        }
        
        $stmt = $conn->prepare("
            UPDATE tbl_animals SET 
                animal_name=?, SUB=?, animal_code=?, animal_type=?, animal_source=?, is_active=?, 
                registration_date=?, gender=?, breed=?, color=?, marking=?, birth_date=?, 
                estimated_age=?, delivered_by_empid=?, owner_name=?, owner_phone=?, 
                owner_email=?, owner_signature=?, notes=?, updated_by=?, country_of_origin=?, 
                owner_national_id=?, owner_national_id_photo=?
            WHERE id=?
        ");
        
        if (!$stmt) {
            throw new Exception("خطأ في إعداد استعلام التحديث: ".$conn->error);
        }
        
        $stmt->bind_param("sisssssssssssssssssssssi",
            $animal_name, $sub_seq, $animal_code, $animal_type, $animal_source, $is_active,
            $registration_date, $gender, $breed, $color, $marking, $birth_date,
            $estimated_age, $delivered_by_empid, $owner_name, $owner_phone,
            $owner_email, $owner_signature_db_path, $notes, $updated_by,
            $country_of_origin, $owner_national_id, $owner_national_id_photo, $id
        );
        
        if($stmt->execute()){
            // معالجة الصور الجديدة
            $uploaded_files_count = 0;
            $upload_errors = [];
            if (isset($_FILES['animal_photos']) && is_array($_FILES['animal_photos']['name'])) {
                $files = $_FILES['animal_photos'];
                $max_photos = 3;
                
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0755, true)) {
                        $upload_errors[] = "فشل في إنشاء مجلد رفع الصور";
                    }
                }
                
                if (!is_writable($upload_dir)) {
                    $upload_errors[] = "المجلد غير قابل للكتابة: " . $upload_dir;
                }
                
                foreach ($files['name'] as $key => $name) {
                    // ✨ التعديل هنا: معالجة الخطأ رقم 4 (UPLOAD_ERR_NO_FILE)
                    if ($files['error'][$key] === UPLOAD_ERR_NO_FILE) {
                        continue; 
                    }

                    if ($files['error'][$key] !== UPLOAD_ERR_OK) {
                        $error_code = $files['error'][$key];
                        $upload_errors[] = "خطأ في رفع الملف (رمز الخطأ: {$error_code}): " . $files['name'][$key]; 
                        continue;
                    }
                    
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                    if(!in_array($ext, $allowed_extensions)) {
                        $upload_errors[] = "نوع الملف غير مسموح: " . $name;
                        continue;
                    }
                    
                    if ($files['size'][$key] > 5 * 1024 * 1024) {
                        $upload_errors[] = "حجم الملف كبير جداً: " . $name;
                        continue;
                    }
                    
                    $new_file_name = $id . '_' . time() . '_' . $key . '.' . $ext;
                    $full_destination_path = $upload_dir . $new_file_name;
                    
                    if (compress_and_resize_image_and_save($files['tmp_name'][$key], $full_destination_path)) {
                        if (savePhotoToDatabase($id, $new_file_name, $updated_by)) {
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
            
            $conn->commit(); 
            
            $message = 'تم تحديث بيانات الحيوان بنجاح';
            if ($uploaded_files_count > 0) {
                $message .= ' وتم رفع ' . $uploaded_files_count . ' صور جديدة';
            }
            if (!empty($upload_errors)) {
                $message .= '. ولكن حدثت بعض الأخطاء: ' . implode(', ', $upload_errors);
            }
            
            echo json_encode(['success'=>true,'message'=>$message]);
        } else {
            $conn->rollback();
            echo json_encode(['success'=>false,'message'=>'خطأ أثناء التحديث: '.$stmt->error]);
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("خطأ في update: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'خطأ في التحديث: ' . $e->getMessage()]);
    }
    exit;
}

// ========== حذف ==========
if($action === 'delete'){
    $id = intval($_POST['id'] ?? 0);
    
    $conn->begin_transaction();
    try {
        $stmt_sig = $conn->prepare("SELECT owner_signature, owner_national_id_photo FROM tbl_animals WHERE id=?");
        $stmt_sig->bind_param("i", $id);
        $stmt_sig->execute();
        $sig_result = $stmt_sig->get_result();
        
        // حذف التوقيع وصورة الهوية
        if($sig_row = $sig_result->fetch_assoc()) {
            $sig_file = __DIR__ . '/../' . $sig_row['owner_signature'];
            if(!empty($sig_row['owner_signature']) && file_exists($sig_file)) {
                @unlink($sig_file);
            }
            $id_photo_file = __DIR__ . '/../' . $sig_row['owner_national_id_photo'];
            if(!empty($sig_row['owner_national_id_photo']) && file_exists($id_photo_file)) {
                @unlink($id_photo_file);
            }
        }
        
        // حذف صور الحيوان المرتبطة
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
        
        // حذف سجلات الصور من DB
        $stmt_del_photos = $conn->prepare("DELETE FROM tbl_animal_photos WHERE animal_id=?");
        $stmt_del_photos->bind_param("i", $id);
        $stmt_del_photos->execute();
        
        // حذف سجل الحيوان الرئيسي
        $stmt = $conn->prepare("DELETE FROM tbl_animals WHERE id=?");
        $stmt->bind_param("i",$id);
        
        if($stmt->execute()){
            if ($stmt->affected_rows !== 1) {
                throw new Exception("لم يتم حذف أي سجل");
            }
            $conn->commit();
            echo json_encode(['success'=>true,'message'=>'تم حذف الحيوان والصور المرتبطة بنجاح.']);
        } else {
            throw new Exception("خطأ في حذف الحيوان: " . $stmt->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("خطأ في delete: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'حدث خطأ أثناء الحذف: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success'=>false,'message'=>'إجراء غير معروف']);
?>