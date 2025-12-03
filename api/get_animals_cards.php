<?php
// get_animals_cards.php
// جلب بطاقات الحيوانات مع فلترة وتضمين حالة التبني/الحجز (Operation_type).
// يعرض للحيوان حالة واحدة من: 'محجوز' (reserved) أو 'جاهز للتبني' (available).
// لا يعرض أي حيوان تم تبنيه (Operation_type = 'adopted').
// يتوقع وجود db.php الذي يعرف $conn (mysqli) ومجلد uploads مع صلاحيات كتابة/قراءة.
// ملاحظة: هذا الملف يعيد JSON فقط.
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
// إعدادات عامة
$items_per_page = 12;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
// قوائم مسموح بها لتجنب حقن القيم
$allowed_types = ['Cats', 'Dogs'];
$allowed_sources = ['Stray', 'Adoption'];
$allowed_genders = ['Male', 'Female', 'Mixed', 'Unknown'];
$allowed_statuses = ['available', 'reserved'];
// شروط WHERE مبدئياً
$where_conditions = ["a.is_active = 'Active'"];
$params = [];
$types = '';
// فلترة حسب المصدر (إذا فارغ، يعرض كل المصادر)
$source_filter = isset($_GET['animal_source']) ? trim($_GET['animal_source']) : '';
if ($source_filter !== '' && in_array($source_filter, $allowed_sources, true)) {
    $where_conditions[] = "a.animal_source = ?";
    $params[] = $source_filter;
    $types .= 's';
}
// فلترة حسب النوع إن طُلب
if (!empty($_GET['animal_type']) && in_array($_GET['animal_type'], $allowed_types, true)) {
    $where_conditions[] = "a.animal_type = ?";
    $params[] = $_GET['animal_type'];
    $types .= 's';
}
// فلترة حسب الجنس إن طُلب
if (!empty($_GET['gender']) && in_array($_GET['gender'], $allowed_genders, true)) {
    $where_conditions[] = "a.gender = ?";
    $params[] = $_GET['gender'];
    $types .= 's';
}
// فلترة نصية اختيارية على animal_code أو animal_name
$search_term = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($search_term !== '') {
    $where_conditions[] = "(a.animal_code LIKE ? OR a.animal_name LIKE ?)";
    $params[] = "%{$search_term}%";
    $params[] = "%{$search_term}%";
    $types .= 'ss';
}
$where_sql = implode(' AND ', $where_conditions);
// إعداد SQL الأساسي
if ($page !== null) {
    $offset = ($page - 1) * $items_per_page;
    $main_sql = "SELECT SQL_CALC_FOUND_ROWS a.* FROM tbl_animals a WHERE $where_sql ORDER BY a.registration_date DESC LIMIT ?, ?";
    $full_types = $types . 'ii';
    $full_params = array_merge($params, [$offset, $items_per_page]);
} else {
    $main_sql = "SELECT a.* FROM tbl_animals a WHERE $where_sql ORDER BY a.registration_date DESC";
    $full_types = $types;
    $full_params = $params;
}
try {
    // تحضير الاستعلام
    $stmt = $conn->prepare($main_sql);
    if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);
    // ربط المعاملات الديناميكي
    if (!empty($full_types)) {
        // bind_param requires references
        $bind_names = [];
        $bind_names[] = $full_types;
        foreach ($full_params as $k => $v) $bind_names[] = $full_params[$k];
        $refs = [];
        foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $animals = [];
    $animal_codes = [];
    // جلب بيانات الحيوانات والصور
    while ($row = $result->fetch_assoc()) {
        // جلب صور (حد أقصى 5)
        $photos = [];
        $photo_stmt = $conn->prepare("SELECT photo_url FROM tbl_animal_photos WHERE animal_id = ? LIMIT 5");
        if ($photo_stmt) {
            $photo_stmt->bind_param('i', $row['id']);
            $photo_stmt->execute();
            $pr = $photo_stmt->get_result();
            while ($p = $pr->fetch_assoc()) {
                // التحقق من وجود الملف (اختياري)
                $photo_path = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/health_vet/' . ltrim($p['photo_url'], '/\\');
                if (file_exists($photo_path)) $photos[] = $p['photo_url'];
                else $photos[] = $p['photo_url']; // أو تجاهل إذا تفضل
            }
            $photo_stmt->close();
        }
        // تنظيف الحقول لمنع مشاكل XSS عند العرض
        $row['animal_name'] = htmlspecialchars($row['animal_name'] ?: 'غير معروف', ENT_QUOTES, 'UTF-8');
        $row['breed'] = htmlspecialchars($row['breed'] ?: 'غير معروف', ENT_QUOTES, 'UTF-8');
        $row['color'] = htmlspecialchars($row['color'] ?: 'غير معروف', ENT_QUOTES, 'UTF-8');
        $row['animal_code'] = htmlspecialchars($row['animal_code'] ?: '', ENT_QUOTES, 'UTF-8');
        $row['photos'] = $photos;
        $animals[] = $row;
        $animal_codes[] = $row['animal_code'];
    }
    // الآن نحدد حالة التبني/الحجز لكل animal_code المنشور في النتيجة.
    // نريد آخر سجل (أحدث) لكل animal_code وننظر إلى Operation_type:
    // - 'reserved' => محجوز (status_label = 'محجوز')
    // - 'adopted' => مُتبنى (لكن سيتم استبعاده)
    // - لا يوجد سجل => جاهز للتبني (status_label = 'جاهز للتبني')
    $adoption_map = []; // animal_code => adoption info
    if (!empty($animal_codes)) {
        // إنشاء قائمة آمنة لـ IN ()
        $unique_codes = array_values(array_unique($animal_codes));
        $escaped = array_map(function($c) use ($conn) {
            return "'" . $conn->real_escape_string($c) . "'";
        }, $unique_codes);
        $in_list = implode(',', $escaped);
        // نأخذ أحدث adoption_id لكل animal_code ثم نأخذ تفاصيل تلك السجلات
        $sql_ad = "
            SELECT t.animal_code, a.adoption_id, a.Operation_type, a.adoption_date, a.adopter_name, a.receipt_number
            FROM (
                SELECT animal_code, MAX(adoption_id) AS max_ad
                FROM tbl_adoptions
                WHERE animal_code IN ($in_list)
                GROUP BY animal_code
            ) t
            JOIN tbl_adoptions a ON a.adoption_id = t.max_ad
            WHERE a.animal_code IN ($in_list)
        ";
        $res_ad = $conn->query($sql_ad);
        if ($res_ad) {
            while ($r = $res_ad->fetch_assoc()) {
                $adoption_map[$r['animal_code']] = $r;
            }
        } else {
            // سجل الخطأ
            error_log("Adoption map query failed: " . $conn->error);
        }
    }
    // أضف حالة لكل حيوان
    foreach ($animals as &$an) {
        $code = $an['animal_code'];
        if (isset($adoption_map[$code])) {
            $ad = $adoption_map[$code];
            $op = strtolower($ad['Operation_type'] ?? '');
            if ($op === 'reserved') {
                $an['adoption_status'] = 'reserved';
                $an['status_label'] = 'محجوز';
            } elseif ($op === 'adopted') {
                $an['adoption_status'] = 'adopted';
                $an['status_label'] = 'مُتبنى';
            } else {
                $an['adoption_status'] = 'available';
                $an['status_label'] = 'جاهز للتبني';
            }
            $an['adoption'] = [
                'adoption_id' => (int)$ad['adoption_id'],
                'operation_type' => $ad['Operation_type'],
                'adoption_date' => $ad['adoption_date'],
                'adopter_name' => $ad['adopter_name'],
                'receipt_number' => $ad['receipt_number']
            ];
        } else {
            $an['adoption_status'] = 'available';
            $an['status_label'] = 'جاهز للتبني';
            $an['adoption'] = null;
        }
    }
    unset($an);
    // استبعاد الحيوانات المُتبناة (adopted)
    $animals = array_filter($animals, function($an) {
        return $an['adoption_status'] !== 'adopted';
    });
    // فلترة حسب الحالة إذا طُلب (بعد استبعاد adopted)
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
    if ($status_filter !== '' && in_array($status_filter, $allowed_statuses, true)) {
        $animals = array_filter($animals, function($an) use ($status_filter) {
            return $an['adoption_status'] === $status_filter;
        });
    }
    // حساب العدد الإجمالي
    if ($page !== null) {
        $total_result = $conn->query("SELECT FOUND_ROWS() as total");
        $total_animals = (int)($total_result->fetch_assoc()['total'] ?? count($animals));
    } else {
        $count_sql = "SELECT COUNT(*) as total FROM tbl_animals a WHERE $where_sql";
        $count_stmt = $conn->prepare($count_sql);
        if (!$count_stmt) throw new Exception("Count prepare failed: " . $conn->error);
        if (!empty($types)) {
            $bind_names2 = [];
            $bind_names2[] = $types;
            foreach ($params as $k => $v) $bind_names2[] = $params[$k];
            $refs2 = [];
            foreach ($bind_names2 as $k => $v) $refs2[$k] = &$bind_names2[$k];
            call_user_func_array([$count_stmt, 'bind_param'], $refs2);
        }
        $count_stmt->execute();
        $cr = $count_stmt->get_result();
        $total_animals = (int)($cr->fetch_assoc()['total'] ?? count($animals));
        $count_stmt->close();
    }
    // إعداد pagination
    if ($page !== null) {
        $total_pages = $items_per_page > 0 ? ceil($total_animals / $items_per_page) : 1;
        $pagination = [
            'current_page' => $page,
            'total_pages' => (int)$total_pages,
            'total_animals' => (int)$total_animals,
            'items_per_page' => (int)$items_per_page
        ];
    } else {
        $pagination = [
            'current_page' => 1,
            'total_pages' => 1,
            'total_animals' => (int)$total_animals,
            'items_per_page' => (int)$total_animals
        ];
    }
    echo json_encode([
        'success' => true,
        'data' => array_values($animals), // إعادة ترقيم المصفوفة بعد الفلترة
        'pagination' => $pagination
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log("get_animals_cards error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
} finally {
    if (isset($stmt) && $stmt) $stmt->close();
    $conn->close();
}
?>