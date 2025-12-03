<?php
// health_vet/api/add_adopter.php
// كامل: إضافة / تعديل / حذف / بحث لتعامل التبني
// متطلبات: db.php يعرّف $conn (mysqli)، ووجود جلسة مستخدم $_SESSION['user']['EmpID']
// ملاحظات: السجلّات (logs) تُرسَل إلى error_log؛ لا نطبع تحذيرات في استجابة JSON.

declare(strict_types=1);

error_reporting(E_ALL);
// لا تعرض الأخطاء في الاستجابة؛ سجّلها فقط
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/api_errors.log'); // تأكد من وجود المجلد logs أو غيّره
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
session_start();

// محاولة رفع حدود الرفع (قد لا تعمل على بعض الاستضافات)
@ini_set('post_max_size', '64M');
@ini_set('upload_max_filesize', '64M');
@ini_set('memory_limit', '256M');

// مجلدات الرفع (تأكد من صلاحيات الكتابة)
$adopter_id_photos_dir = __DIR__ . '/../uploads/adopter_national_id_photo/';
$adopter_signature_dir = __DIR__ . '/../uploads/adopter_signature/';

// اقرأ JSON body إن وُجد
$raw_input = file_get_contents('php://input');
$json_input = null;
if ($raw_input) {
    $tmp = json_decode($raw_input, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $json_input = $tmp;
}

// دالة مساعدة لاسترجاع الحقول من POST أو JSON
function get_input_field(string $key) {
    global $_POST, $json_input;
    if (isset($_POST[$key]) && $_POST[$key] !== '') return $_POST[$key];
    if (is_array($json_input) && array_key_exists($key, $json_input)) return $json_input[$key];
    return null;
}
// دالة trim آمنة
function safe_trim($value): string {
    if (is_string($value)) return trim($value);
    if (is_numeric($value)) return (string)$value;
    return '';
}

// ---------- دوال معالجة الصور والتواقيع ----------
function compress_and_resize_image_and_save(string $file_tmp_path, string $destination_path, int $target_width = 800, int $target_height = 800): bool {
    if (!extension_loaded('gd')) {
        error_log("GD extension not available");
        return false;
    }
    $image_info = @getimagesize($file_tmp_path);
    if ($image_info === false) {
        error_log("getimagesize failed for: $file_tmp_path");
        return false;
    }
    [$original_width, $original_height, $image_type] = $image_info;
    switch ($image_type) {
        case IMAGETYPE_JPEG: $source_image = @imagecreatefromjpeg($file_tmp_path); break;
        case IMAGETYPE_PNG: $source_image = @imagecreatefrompng($file_tmp_path); break;
        case IMAGETYPE_GIF: $source_image = @imagecreatefromgif($file_tmp_path); break;
        default:
            error_log("Unsupported image type: $image_type");
            return false;
    }
    if (!$source_image) {
        error_log("Failed to create source image from tmp: $file_tmp_path");
        return false;
    }
    $original_ratio = $original_width / $original_height;
    $target_ratio = $target_width / $target_height;
    if ($original_ratio > $target_ratio) {
        $new_height = $target_height;
        $new_width = (int) round($target_height * $original_ratio);
    } else {
        $new_width = $target_width;
        $new_height = (int) round($target_width / $original_ratio);
    }
    $tw = (int)$target_width; $th = (int)$target_height;
    $virtual_image = imagecreatetruecolor($tw, $th);
    if ($image_type == IMAGETYPE_PNG || $image_type == IMAGETYPE_GIF) {
        imagealphablending($virtual_image, false);
        imagesavealpha($virtual_image, true);
        $transparent = imagecolorallocatealpha($virtual_image, 255, 255, 255, 127);
        imagefilledrectangle($virtual_image, 0, 0, $tw, $th, $transparent);
    } else {
        $white = imagecolorallocate($virtual_image, 255, 255, 255);
        imagefilledrectangle($virtual_image, 0, 0, $tw, $th, $white);
    }
    $dst_x = (int) round(($tw - $new_width) / 2);
    $dst_y = (int) round(($th - $new_height) / 2);
    $res = imagecopyresampled($virtual_image, $source_image, $dst_x, $dst_y, 0, 0, (int)$new_width, (int)$new_height, (int)$original_width, (int)$original_height);
    if (!$res) { imagedestroy($source_image); imagedestroy($virtual_image); error_log("imagecopyresampled failed"); return false; }
    $saved = false;
    switch ($image_type) {
        case IMAGETYPE_JPEG: $saved = imagejpeg($virtual_image, $destination_path, 85); break;
        case IMAGETYPE_PNG: $saved = imagepng($virtual_image, $destination_path, 6); break;
        case IMAGETYPE_GIF: $saved = imagegif($virtual_image, $destination_path); break;
    }
    imagedestroy($source_image);
    imagedestroy($virtual_image);
    if (!$saved) error_log("Failed to save resized image to: $destination_path");
    return (bool)$saved;
}

function save_signature_from_base64(string $base64_data, string $signature_dir, ?string $national_id = null): ?string {
    if (empty($base64_data) || strpos($base64_data, 'data:image/') !== 0) {
        error_log("Invalid signature base64 data");
        return null;
    }
    $base64_clean = preg_replace('#^data:image/\w+;base64,#i', '', $base64_data);
    $image_data = base64_decode($base64_clean);
    if ($image_data === false || $image_data === '') { error_log("Base64 decode failed"); return null; }
    $tmp = sys_get_temp_dir() . '/' . uniqid('sig_') . '.png';
    if (file_put_contents($tmp, $image_data) === false) { error_log("Failed to write tmp signature"); return null; }
    if (!compress_and_resize_image_and_save($tmp, $tmp, 600, 300)) { @unlink($tmp); error_log("Signature compress failed"); return null; }
    if (!is_dir($signature_dir)) { if (!mkdir($signature_dir, 0755, true)) { @unlink($tmp); error_log("Failed to create signature dir"); return null; } }
    $safe = $national_id ? preg_replace('/[^A-Za-z0-9_-]/','',$national_id) : 'NOSIG';
    $name = $safe . '_SIG_' . time() . '_' . bin2hex(random_bytes(6)) . '.png';
    $full = rtrim($signature_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    if (!rename($tmp, $full)) {
        if (!copy($tmp, $full)) { @unlink($tmp); error_log("Failed to move signature"); return null; }
        @unlink($tmp);
    }
    if (!file_exists($full) || filesize($full) === 0) { @unlink($full); error_log("Saved signature missing"); return null; }
    return 'uploads/adopter_signature/' . $name;
}

function save_uploaded_signature_file(array $file_info, string $signature_dir, ?string $national_id = null): ?string {
    if (!isset($file_info['tmp_name']) || $file_info['error'] !== UPLOAD_ERR_OK) return null;
    $tmp = $file_info['tmp_name'];
    if (!is_dir($signature_dir) && !mkdir($signature_dir, 0755, true)) { error_log("Failed to create signature dir"); return null; }
    $ext = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION)) ?: 'png';
    $safe = $national_id ? preg_replace('/[^A-Za-z0-9_-]/','',$national_id) : 'NOSIG';
    $name = $safe . '_SIG_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $full = rtrim($signature_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    if (compress_and_resize_image_and_save($tmp, $full, 600, 300)) return 'uploads/adopter_signature/' . $name;
    if (move_uploaded_file($tmp, $full)) return 'uploads/adopter_signature/' . $name;
    error_log("Failed to save uploaded signature file");
    return null;
}

// ---------- جلسة المستخدم ----------
function getCurrentUserEmpID(): int {
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['EmpID'])) {
        throw new Exception("الجلسة غير موجودة. قم بتسجيل الدخول.");
    }
    return (int) $_SESSION['user']['EmpID'];
}

// ---------- بدء منطق الـ API ----------
$action = get_input_field('action') ?? '';

try {
    // ===== search_by_code =====
    if ($action === 'search_by_code') {
        $code = get_input_field('code') ?? '';
        $like = '%' . $code . '%';
        $stmt = $conn->prepare("SELECT id, animal_code, animal_name, animal_type, breed, color, gender FROM tbl_animals WHERE animal_code LIKE ? AND is_active='Active' ORDER BY id DESC LIMIT 20");
        if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // ===== get_animal =====
    if ($action === 'get_animal') {
        $id = intval(get_input_field('id') ?? 0);
        $stmt = $conn->prepare("SELECT * FROM tbl_animals WHERE id=? AND is_active='Active'");
        if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $animal = $stmt->get_result()->fetch_assoc();
        if ($animal) {
            $stmt_p = $conn->prepare("SELECT photo_url FROM tbl_animal_photos WHERE animal_id=? LIMIT 1");
            if ($stmt_p) {
                $stmt_p->bind_param('i', $id);
                $stmt_p->execute();
                $pr = $stmt_p->get_result();
                $photo = $pr->num_rows > 0 ? $pr->fetch_assoc()['photo_url'] : null;
            } else $photo = null;
            echo json_encode(['success' => true, 'animal' => $animal, 'photo' => $photo]);
        } else echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الحيوان.']);
        exit;
    }

    // ===== check_animal_available =====
    if ($action === 'check_animal_available') {
        $animal_code = get_input_field('animal_code') ?? '';
        $stmt = $conn->prepare("SELECT adoption_id FROM tbl_adoptions WHERE animal_code=? LIMIT 1");
        if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);
        $stmt->bind_param('s', $animal_code);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) echo json_encode(['success' => false, 'message' => 'الحيوان مسجل بالفعل في عملية تبني/حجز.']);
        else echo json_encode(['success' => true, 'message' => 'الحيوان متاح للتبني/الحجز.']);
        exit;
    }

    // ===== search_adoption =====
    if ($action === 'search_adoption') {
        $term = get_input_field('term') ?? '';
        $like = '%' . $term . '%';
        $stmt = $conn->prepare("
            SELECT adoption_id, animal_code, adopter_name, adopter_phone, receipt_number, adoption_date, Operation_type
            FROM tbl_adoptions
            WHERE animal_code LIKE ? OR adopter_name LIKE ? OR adopter_phone LIKE ? OR receipt_number LIKE ? OR CAST(adoption_id AS CHAR) LIKE ?
            ORDER BY adoption_date DESC LIMIT 50
        ");
        if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);
        $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // ===== get_adoption =====
    if ($action === 'get_adoption') {
        $adoption_id = intval(get_input_field('adoption_id') ?? 0);
        $stmt = $conn->prepare("
            SELECT a.*, an.animal_name, an.animal_type, an.breed, an.color, an.gender, u.EmpName AS created_by_name
            FROM tbl_adoptions a
            LEFT JOIN tbl_animals an ON a.animal_code=an.animal_code
            LEFT JOIN Users u ON a.created_by=u.EmpID
            WHERE a.adoption_id=? LIMIT 1
        ");
        if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);
        $stmt->bind_param('i', $adoption_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) echo json_encode(['success' => true, 'adoption' => $row]);
        else echo json_encode(['success' => false, 'message' => 'لم يتم العثور على عملية التبني/الحجز.']);
        exit;
    }

    // ===== ADD adoption =====
    if ($action === 'add') {
        $created_by = getCurrentUserEmpID();

        // آخذ الحقول بشكل آمن
        $animal_code = safe_trim(get_input_field('animal_code'));
        $adopter_name = safe_trim(get_input_field('adopter_name'));
        $adopter_national_id = safe_trim(get_input_field('adopter_national_id'));
        $adopter_phone = safe_trim(get_input_field('adopter_phone'));
        $adopter_email_raw = safe_trim(get_input_field('adopter_email'));
        $adopter_email = $adopter_email_raw === '' ? null : $adopter_email_raw;
        $description = get_input_field('description') ?? null;
        $notes = get_input_field('notes') ?? null;
        $adoption_date = safe_trim(get_input_field('adoption_date')) ?: date('Y-m-d');
        $receipt_number = safe_trim(get_input_field('receipt_number')) ?: null;
        $operation_type = safe_trim(get_input_field('operation_type')) ?: 'adopted';

        if ($animal_code === '' || $adopter_name === '' || $adopter_national_id === '' || $adopter_phone === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'بيانات العملية الأساسية مفقودة.']);
            exit;
        }

        // unique receipt check
        if ($receipt_number) {
            $s = $conn->prepare("SELECT adoption_id FROM tbl_adoptions WHERE receipt_number=? LIMIT 1");
            if ($s) {
                $s->bind_param('s', $receipt_number); $s->execute();
                if ($s->get_result()->num_rows > 0) { http_response_code(409); echo json_encode(['success'=>false,'message'=>'رقم الإيصال مستخدم مسبقاً.']); exit; }
            }
        }

        $adopter_national_id_photo = null;
        $adopter_signature = null;

        $conn->begin_transaction();
        try {
            // ensure not already adopted/reserved
            $s = $conn->prepare("SELECT adoption_id FROM tbl_adoptions WHERE animal_code=? LIMIT 1");
            $s->bind_param('s', $animal_code);
            $s->execute();
            if ($s->get_result()->num_rows > 0) throw new Exception("الحيوان مسجل بالفعل في عملية تبني/حجز.");

            // handle id photo upload
            if (isset($_FILES['adopter_national_id_photo']) && $_FILES['adopter_national_id_photo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['adopter_national_id_photo'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif'];
                if (!in_array($ext, $allowed)) throw new Exception("نوع ملف صورة الهوية غير مسموح.");
                if (!is_dir($adopter_id_photos_dir) && !mkdir($adopter_id_photos_dir, 0755, true)) throw new Exception("فشل في إنشاء مجلد صور الهوية.");
                $name = preg_replace('/[^A-Za-z0-9_-]/', '', $adopter_national_id) . '_ID_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $full = $adopter_id_photos_dir . $name;
                if (!compress_and_resize_image_and_save($file['tmp_name'], $full, 1200, 1200)) {
                    if (!move_uploaded_file($file['tmp_name'], $full)) throw new Exception("فشل في معالجة صورة الهوية.");
                }
                $adopter_national_id_photo = 'uploads/adopter_national_id_photo/' . $name;
            }

            // handle signature file or base64
            if (isset($_FILES['adopter_signature_file']) && $_FILES['adopter_signature_file']['error'] === UPLOAD_ERR_OK) {
                $saved = save_uploaded_signature_file($_FILES['adopter_signature_file'], $adopter_signature_dir, $adopter_national_id);
                if ($saved === null) throw new Exception("فشل في حفظ ملف التوقيع.");
                $adopter_signature = $saved;
            } else {
                $sig_b64 = get_input_field('adopter_signature_base64') ?? null;
                if (!empty($sig_b64)) {
                    $saved = save_signature_from_base64($sig_b64, $adopter_signature_dir, $adopter_national_id);
                    if ($saved === null) throw new Exception("فشل في حفظ التوقيع من Base64.");
                    $adopter_signature = $saved;
                }
            }

            // insert record
            $stmt = $conn->prepare("INSERT INTO tbl_adoptions (animal_code, adopter_name, adopter_national_id, adopter_phone, adopter_email, adopter_national_id_photo, adopter_signature, description, notes, adoption_date, created_by, receipt_number, Operation_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);
            $types = "ssssssssssiss";
            $stmt->bind_param($types,
                $animal_code,
                $adopter_name,
                $adopter_national_id,
                $adopter_phone,
                $adopter_email,
                $adopter_national_id_photo,
                $adopter_signature,
                $description,
                $notes,
                $adoption_date,
                $created_by,
                $receipt_number,
                $operation_type
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                if ($adopter_national_id_photo && file_exists(__DIR__ . '/../' . $adopter_national_id_photo)) @unlink(__DIR__ . '/../' . $adopter_national_id_photo);
                if ($adopter_signature && file_exists(__DIR__ . '/../' . $adopter_signature)) @unlink(__DIR__ . '/../' . $adopter_signature);
                throw new Exception("خطأ في الإدراج: " . $err);
            }
            if ($stmt->affected_rows <= 0 || (int)$conn->insert_id <= 0) {
                if ($adopter_national_id_photo && file_exists(__DIR__ . '/../' . $adopter_national_id_photo)) @unlink(__DIR__ . '/../' . $adopter_national_id_photo);
                if ($adopter_signature && file_exists(__DIR__ . '/../' . $adopter_signature)) @unlink(__DIR__ . '/../' . $adopter_signature);
                throw new Exception("فشل الإدراج: لم يتم إنشاء سجل جديد.");
            }
            $new_id = (int)$conn->insert_id;
            $stmt->close();
            $conn->commit();

            $op_text = ($operation_type === 'adopted') ? 'تبني' : 'حجز';
            $msg = "تم تسجيل {$op_text} بنجاح";
            if ($adopter_national_id_photo) $msg .= ' وصورة الهوية (مضغوطة)';
            if ($adopter_signature) $msg .= ' والتوقيع (مضغوط)';
            if ($receipt_number) $msg .= ' ورقم الإيصال: ' . $receipt_number;

            echo json_encode(['success' => true, 'message' => $msg, 'adoption_id' => $new_id]);
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            http_response_code(500);
            error_log("add error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // ===== UPDATE adoption =====
    if ($action === 'update') {
        $updated_by = getCurrentUserEmpID();
        $adoption_id = intval(get_input_field('adoption_id') ?? 0);
        if ($adoption_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'معرف العملية غير صالح.']); exit; }

        $animal_code = safe_trim(get_input_field('animal_code'));
        $adopter_name = safe_trim(get_input_field('adopter_name'));
        $adopter_national_id = safe_trim(get_input_field('adopter_national_id'));
        $adopter_phone = safe_trim(get_input_field('adopter_phone'));
        $adopter_email_raw = safe_trim(get_input_field('adopter_email'));
        $adopter_email = $adopter_email_raw === '' ? null : $adopter_email_raw;
        $description = get_input_field('description') ?? null;
        $notes = get_input_field('notes') ?? null;
        $adoption_date = safe_trim(get_input_field('adoption_date')) ?: date('Y-m-d');
        $receipt_number = safe_trim(get_input_field('receipt_number')) ?: null;
        $operation_type = safe_trim(get_input_field('operation_type')) ?: 'adopted';

        if ($animal_code === '' || $adopter_name === '' || $adopter_national_id === '' || $adopter_phone === '') {
            http_response_code(400); echo json_encode(['success'=>false,'message'=>'بيانات العملية الأساسية مفقودة.']); exit;
        }

        // receipt uniqueness
        if ($receipt_number) {
            $s = $conn->prepare("SELECT adoption_id FROM tbl_adoptions WHERE receipt_number = ? AND adoption_id != ?");
            $s->bind_param('si', $receipt_number, $adoption_id);
            $s->execute();
            if ($s->get_result()->num_rows > 0) { http_response_code(409); echo json_encode(['success'=>false,'message'=>'رقم الإيصال مستخدم مسبقاً.']); exit; }
        }

        $conn->begin_transaction();
        try {
            $s = $conn->prepare("SELECT adopter_national_id_photo, adopter_signature FROM tbl_adoptions WHERE adoption_id = ? LIMIT 1");
            $s->bind_param('i', $adoption_id);
            $s->execute();
            $old = $s->get_result()->fetch_assoc();
            $adopter_national_id_photo = $old['adopter_national_id_photo'] ?? null;
            $adopter_signature = $old['adopter_signature'] ?? null;

            if (isset($_FILES['adopter_national_id_photo']) && $_FILES['adopter_national_id_photo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['adopter_national_id_photo'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif'];
                if (!in_array($ext, $allowed)) throw new Exception("نوع ملف صورة الهوية غير مسموح.");
                if (!is_dir($adopter_id_photos_dir) && !mkdir($adopter_id_photos_dir, 0755, true)) throw new Exception("فشل في إنشاء مجلد صور الهوية.");
                $name = preg_replace('/[^A-Za-z0-9_-]/', '', $adopter_national_id) . '_ID_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $full = $adopter_id_photos_dir . $name;
                if (!compress_and_resize_image_and_save($file['tmp_name'], $full, 1200, 1200)) {
                    if (!move_uploaded_file($file['tmp_name'], $full)) throw new Exception("فشل في معالجة صورة الهوية.");
                }
                if ($adopter_national_id_photo && file_exists(__DIR__ . '/../' . $adopter_national_id_photo)) @unlink(__DIR__ . '/../' . $adopter_national_id_photo);
                $adopter_national_id_photo = 'uploads/adopter_national_id_photo/' . $name;
            }

            if (isset($_FILES['adopter_signature_file']) && $_FILES['adopter_signature_file']['error'] === UPLOAD_ERR_OK) {
                $saved = save_uploaded_signature_file($_FILES['adopter_signature_file'], $adopter_signature_dir, $adopter_national_id);
                if ($saved === null) throw new Exception("فشل في حفظ ملف التوقيع.");
                if ($adopter_signature && file_exists(__DIR__ . '/../' . $adopter_signature)) @unlink(__DIR__ . '/../' . $adopter_signature);
                $adopter_signature = $saved;
            } else {
                $sig_b64 = get_input_field('adopter_signature_base64') ?? null;
                if (!empty($sig_b64)) {
                    $saved = save_signature_from_base64($sig_b64, $adopter_signature_dir, $adopter_national_id);
                    if ($saved === null) throw new Exception("فشل في حفظ التوقيع من Base64.");
                    if ($adopter_signature && file_exists(__DIR__ . '/../' . $adopter_signature)) @unlink(__DIR__ . '/../' . $adopter_signature);
                    $adopter_signature = $saved;
                }
            }

            $stmt = $conn->prepare("UPDATE tbl_adoptions SET animal_code=?, adopter_name=?, adopter_national_id=?, adopter_phone=?, adopter_email=?, adopter_national_id_photo=?, adopter_signature=?, description=?, notes=?, adoption_date=?, updated_by=?, receipt_number=?, Operation_type=? WHERE adoption_id=?");
            if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);
            $types = "ssssssssssissi";
            $stmt->bind_param($types,
                $animal_code,
                $adopter_name,
                $adopter_national_id,
                $adopter_phone,
                $adopter_email,
                $adopter_national_id_photo,
                $adopter_signature,
                $description,
                $notes,
                $adoption_date,
                $updated_by,
                $receipt_number,
                $operation_type,
                $adoption_id
            );
            if (!$stmt->execute()) throw new Exception("خطأ في التحديث: " . $stmt->error);
            $stmt->close();
            $conn->commit();
            $op_text = ($operation_type === 'adopted') ? 'تبني' : 'حجز';
            echo json_encode(['success' => true, 'message' => "تم تحديث {$op_text} بنجاح"]);
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            http_response_code(500);
            error_log("update error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // ===== DELETE adoption =====
    if ($action === 'delete_adoption') {
        $adoption_id = intval(get_input_field('adoption_id') ?? 0);
        if ($adoption_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'معرف غير صالح.']); exit; }
        $conn->begin_transaction();
        try {
            $s = $conn->prepare("SELECT adopter_national_id_photo, adopter_signature FROM tbl_adoptions WHERE adoption_id = ? LIMIT 1");
            if (!$s) throw new Exception("DB prepare failed: " . $conn->error);
            $s->bind_param('i', $adoption_id);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            if ($row) {
                if ($row['adopter_national_id_photo'] && file_exists(__DIR__ . '/../' . $row['adopter_national_id_photo'])) @unlink(__DIR__ . '/../' . $row['adopter_national_id_photo']);
                if ($row['adopter_signature'] && file_exists(__DIR__ . '/../' . $row['adopter_signature'])) @unlink(__DIR__ . '/../' . $row['adopter_signature']);
            }
            $d = $conn->prepare("DELETE FROM tbl_adoptions WHERE adoption_id = ?");
            if (!$d) throw new Exception("DB prepare failed: " . $conn->error);
            $d->bind_param('i', $adoption_id);
            if (!$d->execute()) throw new Exception("خطأ في الحذف: " . $d->error);
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'تم الحذف بنجاح.']);
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            http_response_code(500);
            error_log("delete error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // unknown action
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    error_log("Unexpected error in add_adopter endpoint: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'خطأ داخلي بالخادم']);
    exit;
}
?>