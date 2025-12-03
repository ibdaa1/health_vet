<?php
// تعطيل أي إخراج خارج JSON
error_reporting(0);
ini_set('display_errors', 0);

// تنظيف أي إخراج سابق
ob_start();

header('Content-Type: application/json; charset=utf-8');
// تأكد من أن مسار db.php صحيح
require_once 'db.php'; 

session_start();
// تأكد من أن الجلسة تتضمن EmpID
if (!isset($_SESSION['user']['EmpID'])) {
    ob_clean();
    echo json_encode(['success'=>false, 'message'=>'جلسة المستخدم غير صالحة']);
    exit;
}
$EmpID = $_SESSION['user']['EmpID'];
$isAdmin = isset($_SESSION['user']['IsAdmin']) && (int)$_SESSION['user']['IsAdmin'] === 1;
$canEdit = isset($_SESSION['user']['CanEdit']) && (int)$_SESSION['user']['CanEdit'] === 1;
$canDelete = isset($_SESSION['user']['CanDelete']) && (int)$_SESSION['user']['CanDelete'] === 1;

$action = $_REQUEST['action'] ?? 'add';

// --- دالة التحقق من القيم الفارغة ---
function getNullableValue($value) {
    return !empty($value) ? $value : null;
}

// --- دالة التحقق من رقم الهاتف (تم تعديلها) ---
function validatePhone($phone) {
    $phone = trim($phone);
    if (empty($phone)) {
        return false;
    }
    // 🔴 تم التعديل: التحقق فقط من وجود أرقام (أو علامة + في البداية) دون تحديد الطول
    if (!preg_match('/^\+?[0-9]+$/', $phone)) {
        return false;
    }
    return true;
}

// =======================
// 1. إضافة شكوى جديدة
// =======================
if ($action === 'add') {

    // ... (جلب البيانات كما في السابق)
    $ComplaintNo           = trim($_POST['ComplaintNo'] ?? '');
    $ComplaintDate         = getNullableValue($_POST['ComplaintDate']) ?? date('Y-m-d H:i:s');
    $ReceivedByEmpID       = !empty($_POST['ReceivedByEmpID']) ? intval($_POST['ReceivedByEmpID']) : null;
    $ReceivedDate          = getNullableValue($_POST['ReceivedDate']) ?? date('Y-m-d H:i:s');
    $Source                = $_POST['Source'] ?? 'Hotline';
    $EmpID_POST            = intval($_POST['EmpID'] ?? $EmpID);
    $ComplainantName       = trim($_POST['ComplainantName'] ?? '');
    $ComplainantPhone      = trim($_POST['ComplainantPhone'] ?? '');
    $AreaID                = !empty($_POST['AreaID']) ? intval($_POST['AreaID']) : null;
    $Coordinates           = $_POST['Coordinates'] ?? '';
    $City                  = $_POST['City'] ?? 'الشارقة';
    $ComplaintType         = $_POST['ComplaintType'] ?? 'Cats';
    $AnimalCount           = intval($_POST['AnimalCount'] ?? 1);
    $ResponsePriority      = $_POST['ResponsePriority'] ?? 'Scheduled';
    $ComplainantStatement  = $_POST['ComplainantStatement'] ?? '';
    $KPI_Method            = $_POST['KPI_Method'] ?? 'Cage';
    $ComplaintStatus       = $_POST['ComplaintStatus'] ?? 'Open'; // ← أضفت هذا السطر
    $FollowUpDate          = getNullableValue($_POST['FollowUpDate']);
    $FollowUpAction        = $_POST['FollowUpAction'] ?? '';
    $TeamFollowUp          = $_POST['TeamFollowUp'] ?? '';
    $ManagerComment        = $isAdmin ? ($_POST['ManagerComment'] ?? '') : '';
    $FinalStatus           = $isAdmin ? ($_POST['FinalStatus'] ?? 'Pending Close') : 'Pending Close';
    $CloseDate             = $isAdmin ? getNullableValue($_POST['CloseDate']) : null;
    $CreatedBy             = intval($_POST['CreatedBy'] ?? $EmpID);
    $UpdatedBy             = intval($_POST['UpdatedBy'] ?? $EmpID);

    // ========== التحقق من البيانات الإلزامية (تم تعديل هذا القسم) ==========
    
    $validation_errors = [];

    // التحقق من اسم الشاكي ورقم الهاتف
    $is_name_missing = empty($ComplainantName);
    $is_phone_invalid = empty($ComplainantPhone) || !validatePhone($ComplainantPhone);
    
    if ($is_name_missing && $is_phone_invalid) {
        $validation_errors[] = 'اسم الشاكي ورقم الهاتف';
    } elseif ($is_name_missing) {
        $validation_errors[] = 'اسم الشاكي';
    } elseif ($is_phone_invalid) {
        // إذا كان رقم الهاتف موجوداً ولكنه غير صالح (يحتوي على حروف أو رموز غير +)
        if (!empty($ComplainantPhone) && !validatePhone($ComplainantPhone)) {
             ob_clean();
             echo json_encode(['success'=>false, 'message'=>'⚠️ رقم الهاتف غير صحيح. يرجى إدخال أرقام فقط.']);
             exit;
        }
        $validation_errors[] = 'رقم الهاتف';
    }
    
    // التحقق من الموظف المسجل
    if (empty($ReceivedByEmpID)) {
        $validation_errors[] = 'الموظف المسجل';
    }
    
    // التحقق من المنطقة
    if (empty($AreaID)) {
        $validation_errors[] = 'المنطقة';
    }
    
    // التحقق من نوع الشكوى
    if (empty($ComplaintType)) {
        $validation_errors[] = 'نوع الشكوى';
    }

    if (!empty($validation_errors)) {
        ob_clean();
        $missing_fields = implode('، و', $validation_errors);
        echo json_encode(['success'=>false, 'message'=>'⚠️ مطلوب إدخال كل من: ' . $missing_fields]);
        exit;
    }
    
    // ... (بقية الكود تبقى كما هي)
    
    // اسم المنطقة
    $AreaName = '';
    if ($AreaID > 0) {
        $stmt_area = $conn->prepare("SELECT area_name_ar FROM tbl_areas WHERE area_id=? LIMIT 1");
        if (!$stmt_area) {
            echo json_encode(['success'=>false, 'message'=>'خطأ في الاستعلام: ' . $conn->error]);
            exit;
        }
        $stmt_area->bind_param("i", $AreaID);
        $stmt_area->execute();
        $areaRes = $stmt_area->get_result();
        $areaRow = $areaRes->fetch_assoc();
        $AreaName = $areaRow ? $areaRow['area_name_ar'] : '';
        $stmt_area->close();
    }

    // رفع الصور مع معالجة الأخطاء
    $PhotoURLs = [];
    // 🚨 التصحيح هنا: نرجع خطوة للخلف (..) من مجلد 'api'
    $uploadDir = __DIR__.'/../uploads/Complaints/'; 
    
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            echo json_encode(['success'=>false, 'message'=>'فشل إنشاء مجلد الصور']);
            exit;
        }
    }
    
    for($i=1; $i<=3; $i++){
        if(isset($_FILES["Photo_$i"]) && $_FILES["Photo_$i"]["error"]==0){
            $tmpName = $_FILES["Photo_$i"]["tmp_name"];
            $originalName = $_FILES["Photo_$i"]["name"];
            $fileSize = $_FILES["Photo_$i"]["size"];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            // التحقق من نوع الملف
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($ext, $allowedExts)) {
                continue; // تجاهل الملفات غير المدعومة
            }
            
            // التحقق من حجم الملف (5MB)
            if ($fileSize > 5 * 1024 * 1024) {
                continue; // تجاهل الملفات الكبيرة
            }
            
            $newName = $uploadDir.time()."_$i.$ext";
            
            if (function_exists('getimagesize') && function_exists('imagecreatefromstring')) {
                $imageInfo = @getimagesize($tmpName);
                if ($imageInfo === false) {
                    continue; // ملف تالف
                }
                
                list($width,$height) = $imageInfo;
                $src = @imagecreatefromstring(file_get_contents($tmpName));
                
                if ($src === false) {
                    continue; // فشل قراءة الصورة
                }
                
                $dst = imagecreatetruecolor(300,300);
                imagecopyresampled($dst,$src,0,0,0,0,300,300,$width,$height);
                $quality = 80;
                
                $saved = false;
                if(in_array($ext,['jpg','jpeg'])) {
                    $saved = imagejpeg($dst,$newName,$quality);
                } elseif($ext=='png') {
                    $saved = imagepng($dst,$newName,8);
                } elseif($ext=='gif') {
                    $saved = imagegif($dst,$newName);
                }
                
                imagedestroy($src);
                imagedestroy($dst);
                
                if ($saved) {
                    // حفظ المسار النسبي: بدءاً من مجلد uploads (للاستخدام في الكود الأمامي /health_vet/uploads/...)
                    $PhotoURLs[] = 'uploads/Complaints/'.basename($newName);
                }
            } elseif (move_uploaded_file($tmpName, $newName)) {
                $PhotoURLs[] = 'uploads/Complaints/'.basename($newName); 
            }
        }
    }
    $PhotoURLs = implode('|',$PhotoURLs);

    // حساب فرق الوقت بالدقائق
    $ComplaintDT = new DateTime($ComplaintDate);
    $FollowDT = $FollowUpDate ? new DateTime($FollowUpDate) : null;
    $CloseDT  = $CloseDate ? new DateTime($CloseDate) : null;
    $Diff_Receive_FollowUp = $FollowDT ? max(0, round(($FollowDT->getTimestamp()-$ComplaintDT->getTimestamp())/60)) : 0;
    $Diff_FollowUp_Close   = ($FollowDT && $CloseDT) ? max(0, round(($CloseDT->getTimestamp()-$FollowDT->getTimestamp())/60)) : 0;

    // تجهيز الأعمدة والقيم - رقم الشكوى اختياري
    $fields = [
        "ComplaintDate", "ReceivedByEmpID", "ReceivedDate", "Source", "EmpID", "ComplainantName",
        "ComplainantPhone", "Area", "Coordinates", "City", "ComplaintType", "AnimalCount", "ResponsePriority",
        "ComplainantStatement", "KPI_Method", "PhotoURLs", "ComplaintStatus", "FollowUpDate", "FollowUpAction", "TeamFollowUp",
        "ManagerComment", "FinalStatus", "CloseDate", "Diff_Receive_FollowUp", "Diff_FollowUp_Close", "CreatedBy", "UpdatedBy"
    ];
    $params = [
        $ComplaintDate, $ReceivedByEmpID, $ReceivedDate, $Source, $EmpID_POST,
        $ComplainantName, $ComplainantPhone, $AreaName, $Coordinates, $City, $ComplaintType, $AnimalCount, $ResponsePriority,
        $ComplainantStatement, $KPI_Method, $PhotoURLs, $ComplaintStatus, $FollowUpDate, $FollowUpAction, $TeamFollowUp, $ManagerComment,
        $FinalStatus, $CloseDate, $Diff_Receive_FollowUp, $Diff_FollowUp_Close, $CreatedBy, $UpdatedBy
    ];
    $types = "sississssssisssssssssssiiii"; // أضفت s إضافية للـ ComplaintStatus
    
    // إضافة ComplaintNo فقط إذا تم إدخاله
    if($ComplaintNo !== ''){
        array_unshift($fields, "ComplaintNo");
        array_unshift($params, $ComplaintNo);
        $types = "s$types";
    }
    
    $placeholders = array_fill(0, count($fields), '?');

    $sql = "INSERT INTO Complaints (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        echo json_encode(['success'=>false, 'message'=>'خطأ في إعداد الاستعلام: ' . $conn->error]);
        exit;
    }
    
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params); 
    }

    if($stmt->execute()){
        $insertedID = $conn->insert_id;
        // إذا لم يتم إدخال رقم شكوى، استخدم ID كرقم شكوى
        if($ComplaintNo === ''){
            // تعديل هنا: بدون أصفار أمامية (إزالة str_pad)
            $autoComplaintNo = 'C-' . $insertedID;
            $updateStmt = $conn->prepare("UPDATE Complaints SET ComplaintNo=? WHERE ComplaintID=?");
            $updateStmt->bind_param("si", $autoComplaintNo, $insertedID);
            $updateStmt->execute();
            $updateStmt->close();
            $ComplaintNo = $autoComplaintNo;
        }
        
        // تنظيف أي إخراج قبل JSON
        ob_clean();
        echo json_encode([
            'success'=>true,
            'message'=>'تم حفظ الشكوى بنجاح',
            'ComplaintID'=>$insertedID,
            'ComplaintNo'=>$ComplaintNo, 
            'Photos'=>$PhotoURLs
        ]);
    } else {
        ob_clean();
        if ($stmt->errno == 1062) {
            echo json_encode(['success'=>false,'message'=>'⚠️ رقم الشكوى مُستخدم من قبل. يرجى اختيار رقم جديد.']);
        } else {
            echo json_encode(['success'=>false,'message'=>'فشل الحفظ: '.$stmt->error]);
        }
    }
    $stmt->close();
    $conn->close();
    exit;
}

// =======================
// 2. تعديل شكوى
// =======================
if ($action === 'update') {
    if (!$canEdit) {
        ob_clean();
        echo json_encode(['success'=>false, 'message'=>'ليس لديك صلاحية التعديل']);
        exit;
    }
    $ComplaintID = isset($_POST['ComplaintID']) ? intval($_POST['ComplaintID']) : 0;
    
    if ($ComplaintID <= 0) {
        echo json_encode(['success'=>false, 'message'=>'معرف الشكوى مطلوب للتعديل']);
        exit;
    }

    // ========== التحقق من البيانات الإلزامية للتعديل (تم تعديل هذا القسم أيضاً) ==========
    $ComplainantName = trim($_POST['ComplainantName'] ?? '');
    $ComplainantPhone = trim($_POST['ComplainantPhone'] ?? '');
    $ReceivedByEmpID = !empty($_POST['ReceivedByEmpID']) ? intval($_POST['ReceivedByEmpID']) : null;
    $AreaID = !empty($_POST['AreaID']) ? intval($_POST['AreaID']) : null;
    $ComplaintType = $_POST['ComplaintType'] ?? '';

    $validation_errors = [];

    // التحقق من اسم الشاكي ورقم الهاتف
    $is_name_missing = empty($ComplainantName);
    $is_phone_invalid = empty($ComplainantPhone) || !validatePhone($ComplainantPhone);
    
    if ($is_name_missing && $is_phone_invalid) {
        $validation_errors[] = 'اسم الشاكي ورقم الهاتف';
    } elseif ($is_name_missing) {
        $validation_errors[] = 'اسم الشاكي';
    } elseif ($is_phone_invalid) {
        // إذا كان رقم الهاتف موجوداً ولكنه غير صالح (يحتوي على حروف أو رموز غير +)
        if (!empty($ComplainantPhone) && !validatePhone($ComplainantPhone)) {
             ob_clean();
             echo json_encode(['success'=>false, 'message'=>'⚠️ رقم الهاتف غير صحيح. يرجى إدخال أرقام فقط.']);
             exit;
        }
        $validation_errors[] = 'رقم الهاتف';
    }
    
    // التحقق من الموظف المسجل
    if (empty($ReceivedByEmpID)) {
        $validation_errors[] = 'الموظف المسجل';
    }
    
    // التحقق من المنطقة
    if (empty($AreaID)) {
        $validation_errors[] = 'المنطقة';
    }
    
    // التحقق من نوع الشكوى
    if (empty($ComplaintType)) {
        $validation_errors[] = 'نوع الشكوى';
    }

    if (!empty($validation_errors)) {
        ob_clean();
        $missing_fields = implode('، و', $validation_errors);
        echo json_encode(['success'=>false, 'message'=>'⚠️ مطلوب إدخال كل من: ' . $missing_fields]);
        exit;
    }


    // التحقق من وجود الشكوى وجلب التواريخ الحالية لحساب الفروقات
    $check_stmt = $conn->prepare("SELECT ComplaintNo, ComplaintDate, FollowUpDate, CloseDate FROM Complaints WHERE ComplaintID = ? LIMIT 1");
    if (!$check_stmt) {
        echo json_encode(['success'=>false, 'message'=>'خطأ في الاستعلام: ' . $conn->error]);
        exit;
    }
    $check_stmt->bind_param("i", $ComplaintID);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();
    if ($check_res->num_rows == 0) {
        echo json_encode(['success'=>false, 'message'=>'الشكوى غير موجودة']);
        $check_stmt->close();
        exit;
    }
    $current_complaint = $check_res->fetch_assoc();
    $current_no = $current_complaint['ComplaintNo'];
    $check_stmt->close();

    $fields = [];
    $params = [];
    $types = "";

    // 🔴 التصحيح رقم 1: إضافة ComplaintDate لـ editableMap
    $editableMap = [
        'ComplaintDate' => 's', // ⬅️ **تمت الإضافة هنا لحفظ التاريخ**
        'ComplainantName' => 's', 'ComplainantPhone' => 's', 'Coordinates' => 's', 
        'City' => 's', 'ComplaintType' => 's', 'AnimalCount' => 'i', 'ResponsePriority' => 's', 
        'ComplainantStatement' => 's', 'KPI_Method' => 's', 'PhotoURLs' => 's', 
        'ComplaintStatus' => 's',
        'FollowUpDate' => 's', 'FollowUpAction' => 's', 'TeamFollowUp' => 's', 'ManagerComment' => 's',
        'FinalStatus' => 's', 'CloseDate' => 's', 'Diff_Receive_FollowUp' => 'i', 'Diff_FollowUp_Close' => 'i', 
        'UpdatedBy' => 'i', 'Source' => 's', 'ReceivedByEmpID' => 'i', 'ReceivedDate' => 's'
    ];

    $adminFields = ['ManagerComment', 'FinalStatus', 'CloseDate'];
    
    // معالجة تحديث حقل Area بناءً على AreaID
    if(isset($_POST['AreaID']) && intval($_POST['AreaID']) > 0){
        $AreaID = intval($_POST['AreaID']);
        $stmt_area = $conn->prepare("SELECT area_name_ar FROM tbl_areas WHERE area_id=? LIMIT 1");
        if ($stmt_area) {
            $stmt_area->bind_param("i", $AreaID);
            $stmt_area->execute();
            $areaRes = $stmt_area->get_result();
            $areaRow = $areaRes->fetch_assoc();
            if($areaRow){
                $fields[] = "Area=?";
                $params[] = $areaRow['area_name_ar'];
                $types .= "s";
            }
            $stmt_area->close();
        }
    }
    
    // معالجة باقي الحقول
    foreach($editableMap as $field => $typeChar){
        if(array_key_exists($field, $_POST)){
            // تجاهل حقول المدير إذا لم يكن أدمن
            if (in_array($field, $adminFields) && !$isAdmin) {
                continue;
            }
            $value = $_POST[$field];
            
            // 🔴 التصحيح رقم 2: إضافة ComplaintDate لتطبيق getNullableValue
            if (in_array($field, ['ComplaintDate', 'FollowUpDate', 'CloseDate', 'ReceivedDate'])) {
                $value = getNullableValue($value);
            } else if ($typeChar === 'i') {
                 $value = !empty($value) ? intval($value) : null;
            }
            
            $fields[] = "$field=?";
            $params[] = $value;
            $types .= $typeChar;
        }
    }
    
    // 🔴 التصحيح رقم 3: إعادة حساب فروق الوقت إذا تم تحديث أي تاريخ
    $dates_to_check = ['ComplaintDate', 'FollowUpDate', 'CloseDate'];
    $recalculate_diff = false;

    foreach($dates_to_check as $date_field) {
        if (isset($_POST[$date_field])) {
            $recalculate_diff = true;
            break;
        }
    }

    if ($recalculate_diff) {
        $newComplaintDate = getNullableValue($_POST['ComplaintDate'] ?? $current_complaint['ComplaintDate']);
        $newFollowUpDate  = getNullableValue($_POST['FollowUpDate'] ?? $current_complaint['FollowUpDate']);
        $newCloseDate     = getNullableValue($_POST['CloseDate'] ?? $current_complaint['CloseDate']);

        $ComplaintDT = $newComplaintDate ? new DateTime($newComplaintDate) : null;
        $FollowDT    = $newFollowUpDate ? new DateTime($newFollowUpDate) : null;
        $CloseDT     = $newCloseDate ? new DateTime($newCloseDate) : null;

        $newDiffReceiveFollowUp = ($ComplaintDT && $FollowDT) ? max(0, round(($FollowDT->getTimestamp() - $ComplaintDT->getTimestamp()) / 60)) : 0;
        $newDiffFollowUpClose   = ($FollowDT && $CloseDT) ? max(0, round(($CloseDT->getTimestamp() - $FollowDT->getTimestamp()) / 60)) : 0;

        // إضافة التحديثات إذا لم يتم إرسالها بالفعل كجزء من editableMap (وهي كذلك)
        // يتم التأكد فقط من أن الحقول الجديدة ست overwrite القيم القديمة في $params
        $found_r_f = false; $found_f_c = false;
        
        // التحقق من عدم تكرار الحقول في $fields
        $temp_fields = [];
        foreach ($fields as $f) {
            $temp_fields[] = explode('=', $f)[0];
        }

        if (!in_array('Diff_Receive_FollowUp', $temp_fields)) {
            $fields[] = "Diff_Receive_FollowUp=?";
            $params[] = $newDiffReceiveFollowUp;
            $types .= "i";
        }
        if (!in_array('Diff_FollowUp_Close', $temp_fields)) {
            $fields[] = "Diff_FollowUp_Close=?";
            $params[] = $newDiffFollowUpClose;
            $types .= "i";
        }
    }
    
    // معالجة تحديث ComplaintNo - تحقق من التكرار فقط إذا تغير الرقم
    if(isset($_POST['ComplaintNo'])){
        $ComplaintNo_New = trim($_POST['ComplaintNo']);
        
        if ($ComplaintNo_New !== '' && $current_no !== $ComplaintNo_New) {
            // تحقق من عدم تكرار الرقم
            $check_dup_sql = "SELECT ComplaintID FROM Complaints WHERE ComplaintNo=? AND ComplaintID != ? LIMIT 1";
            $stmt_check_dup = $conn->prepare($check_dup_sql);
            if (!$stmt_check_dup) {
                echo json_encode(['success'=>false, 'message'=>'خطأ في الاستعلام: ' . $conn->error]);
                exit;
            }
            $stmt_check_dup->bind_param("si", $ComplaintNo_New, $ComplaintID);
            $stmt_check_dup->execute();
            $res_check_dup = $stmt_check_dup->get_result();
            
            if($res_check_dup->num_rows > 0){
                echo json_encode(['success'=>false, 'message'=>'⚠️ رقم الشكوى الجديد مُستخدم بالفعل لشكوى أخرى.']);
                $stmt_check_dup->close();
                exit;
            }
            $stmt_check_dup->close();
            
            // إضافة التحديث
            $fields[] = "ComplaintNo=?";
            $params[] = $ComplaintNo_New;
            $types .= "s";
        }
    }

    if(!$fields){
        echo json_encode(['success'=>false, 'message'=>'لا يوجد بيانات لتحديثها']);
        exit;
    }
    
    $sql_set = implode(',',$fields);
    $params[] = $ComplaintID;
    $types  .= "i";

    $sql = "UPDATE Complaints SET $sql_set, UpdatedAt=NOW() WHERE ComplaintID=? LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success'=>false, 'message'=>'خطأ في إعداد الاستعلام: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param($types, ...$params);

    if($stmt->execute()){
        ob_clean();
        echo json_encode(['success'=>true, 'message'=>'تم التعديل بنجاح', 'affected_rows'=>$stmt->affected_rows]);
    } else {
        ob_clean();
        echo json_encode(['success'=>false, 'message'=>'فشل التعديل: '.$stmt->error]);
    }
    $stmt->close();
    $conn->close();
    exit;
}

// =======================
// 3. حذف شكوى
// =======================
if ($action === 'delete') {
    if (!$canDelete) {
        ob_clean();
        echo json_encode(['success'=>false, 'message'=>'ليس لديك صلاحية الحذف']);
        exit;
    }
    $ComplaintID = isset($_POST['ComplaintID']) ? intval($_POST['ComplaintID']) : 0;
    
    if ($ComplaintID <= 0) {
        echo json_encode(['success'=>false, 'message'=>'معرف الشكوى مطلوب']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM Complaints WHERE ComplaintID=? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['success'=>false, 'message'=>'خطأ في الاستعلام: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("i", $ComplaintID);
    
    if($stmt->execute() && $stmt->affected_rows > 0){
        ob_clean();
        echo json_encode(['success'=>true, 'message'=>'تم الحذف بنجاح']);
    } else {
        ob_clean();
        echo json_encode(['success'=>false, 'message'=>'فشل الحذف أو الشكوى غير موجودة']);
    }
    $stmt->close();
    $conn->close();
    exit;
}

// =======================
// 4. فلترة الشكاوى
// =======================
if ($action === 'filter') {
    $where = [];
    $params = [];
    $types = "";
    $filterable = [
        'ComplaintNo', 'Source', 'ComplainantName', 'City', 'Area', 'ComplaintType', 'ResponsePriority', 'FinalStatus'
    ];
    
    foreach($filterable as $field){
        if(!empty($_REQUEST[$field])){
            $where[] = "$field=?";
            $params[] = $_REQUEST[$field];
            $types .= "s";
        }
    }
    
    if(!empty($_REQUEST['date_from'])){
        $where[] = "ComplaintDate >= ?";
        $params[] = $_REQUEST['date_from'];
        $types .= "s";
    }
    
    if(!empty($_REQUEST['date_to'])){
        $where[] = "ComplaintDate <= ?";
        $params[] = $_REQUEST['date_to'].' 23:59:59';
        $types .= "s";
    }
    
    $sql = "SELECT * FROM Complaints";
    if($where){
        $sql .= " WHERE ".implode(' AND ', $where);
    }
    $sql .= " ORDER BY ComplaintDate DESC LIMIT 100";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success'=>false, 'message'=>'خطأ في الاستعلام: ' . $conn->error]);
        exit;
    }
    
    if($params){
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $res = $stmt->get_result();
    $data = [];
    
    while($row = $res->fetch_assoc()){
        $data[] = $row;
    }
    
    ob_clean();
    echo json_encode(['success'=>true, 'data'=>$data]);
    $stmt->close();
    $conn->close();
    exit;
}

// =======================
// أمر غير معروف
// =======================
ob_clean();
echo json_encode(['success'=>false, 'message'=>'أمر غير معروف: ' . htmlspecialchars($action)]);
exit;
?>