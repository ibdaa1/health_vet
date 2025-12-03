<?php
// inventory_api.php - Fixed ambiguity issue
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php'; // Assume db.php has $conn = new mysqli(...);
$action = $_GET['action'] ?? 'list';
$lang = isset($_GET['lang']) && $_GET['lang'] === 'en' ? 'EN' : 'AR';
$nameField = $lang === 'EN' ? 'Name_EN' : 'Name_AR';
// Original table: ItemID, ParentID, Name_AR, Name_EN, CreatedAt, UpdatedAt
try {
    // 1️⃣ عرض القائمة الهرمية مع فلترة وترقيم
    if ($action === 'list') {
        $search = $_GET['search'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 50); // Changed default to 50
        $offset = ($page - 1) * $perPage;
        // Support sorting
        $sortBy = $_GET['sort_by'] ?? 'ItemID';
        $sortDir = $_GET['sort_dir'] ?? 'ASC';
        $allowedSorts = ['ItemID', 'Name_AR', 'Name_EN', 'CreatedAt', 'UpdatedAt'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'ItemID';
        // Fix: Use alias in WHERE to avoid ambiguity
        $where = "il.$nameField LIKE ?";
        $params = ["%$search%"];
        $types = "s";
        // Join for ParentName
        $sql = "SELECT il.ItemID, il.ParentID, il.Name_AR, il.Name_EN, il.CreatedAt, il.UpdatedAt,
                       p.$nameField as ParentName
                FROM InventoryList il
                LEFT JOIN InventoryList p ON il.ParentID = p.ItemID
                WHERE $where ORDER BY il.$sortBy $sortDir LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;
        $types .= "ii";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        // Total count - Fix: Use alias
        $countSql = "SELECT COUNT(*) as total FROM InventoryList il WHERE il.$nameField LIKE ?";
        $countStmt = $conn->prepare($countSql);
        $countStmt->bind_param("s", $params[0]);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        echo json_encode(['success' => true, 'data' => $items, 'total' => $total, 'sort_by' => $sortBy, 'sort_dir' => $sortDir], JSON_UNESCAPED_UNICODE);
        exit();
    }
    // 2️⃣ الحصول على فئة واحدة للتعديل
    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM InventoryList WHERE ItemID = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo json_encode(['success' => !!$row, 'data' => $row ?: []], JSON_UNESCAPED_UNICODE);
        exit();
    }
    // 3️⃣ الحصول على الفئات الأب (للـ dropdown)
    if ($action === 'parents') {
        $sql = "SELECT ItemID, $nameField as Name FROM InventoryList WHERE ParentID = 0 OR ParentID IS NULL ORDER BY ItemID ASC";
        $result = $conn->query($sql);
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $items], JSON_UNESCAPED_UNICODE);
        exit();
    }
    // 4️⃣ إضافة / تحديث
    if (in_array($action, ['add', 'update'])) {
        $input = json_decode(file_get_contents('php://input'), true);
        $ItemID = (int)($input['ItemID'] ?? 0);
        $Name_AR = trim($input['Name_AR'] ?? '');
        $Name_EN = trim($input['Name_EN'] ?? '');
        $ParentID = isset($input['ParentID']) && $input['ParentID'] !== '' ? (int)$input['ParentID'] : null;
        if ($ParentID == 0) $ParentID = null; // Treat 0 as null for top-level
        if (!$Name_AR) {
            echo json_encode(['success' => false, 'message' => 'الاسم بالعربي مطلوب']);
            exit();
        }
        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT INTO InventoryList (Name_AR, Name_EN, ParentID) VALUES (?, ?, ?)");
            if ($ParentID === null) {
                $null = null;
                $stmt->bind_param("ssi", $Name_AR, $Name_EN, $null);
            } else {
                $stmt->bind_param("ssi", $Name_AR, $Name_EN, $ParentID);
            }
        } else {
            $stmt = $conn->prepare("UPDATE InventoryList SET Name_AR=?, Name_EN=?, ParentID=? WHERE ItemID=?");
            if ($ParentID === null) {
                $null = null;
                $stmt->bind_param("ssii", $Name_AR, $Name_EN, $null, $ItemID);
            } else {
                $stmt->bind_param("ssii", $Name_AR, $Name_EN, $ParentID, $ItemID);
            }
        }
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => $action === 'add' ? 'تمت الإضافة' : 'تم التحديث']);
        exit();
    }
    // 5️⃣ حذف (مع حذف الأبناء)
    if ($action === 'delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ItemID = (int)($input['ItemID'] ?? 0);
        if (!$ItemID) {
            echo json_encode(['success' => false, 'message' => 'ID غير موجود']);
            exit();
        }
        // Delete children recursively (simple: delete direct children first)
        $conn->query("DELETE FROM InventoryList WHERE ParentID = $ItemID");
        $stmt = $conn->prepare("DELETE FROM InventoryList WHERE ItemID=?");
        $stmt->bind_param("i", $ItemID);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => 'تم الحذف']);
        exit();
    }
    // 6️⃣ إجراء جماعي
    if ($action === 'bulk') {
        $input = json_decode(file_get_contents('php://input'), true);
        $bulkAction = $_GET['bulk_action'] ?? '';
        $ids = $input['ids'] ?? [];
        if (empty($ids) || empty($bulkAction)) {
            echo json_encode(['success' => false, 'message' => 'بيانات ناقصة']);
            exit();
        }
        if ($bulkAction === 'delete') {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $conn->prepare("DELETE FROM InventoryList WHERE ItemID IN ($placeholders)");
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt->execute();
            // Also delete children
            foreach ($ids as $id) {
                $conn->query("DELETE FROM InventoryList WHERE ParentID = $id");
            }
        }
        echo json_encode(['success' => true, 'message' => 'تم الإجراء الجماعي']);
        exit();
    }
    // 7️⃣ ترتيب (إضافة SortOrder إذا أردت، لكن بدون حقل، استخدم ItemID فقط)
    if ($action === 'sort') {
        $input = json_decode(file_get_contents('php://input'), true);
        $order = $input['order'] ?? [];
        if (empty($order)) {
            echo json_encode(['success' => false, 'message' => 'ترتيب فارغ']);
            exit();
        }
        // Simple re-order: Update ParentID or add SortOrder field if exists; here assume re-insert or update IDs
        // For simplicity, update a hypothetical SortOrder field; if not, skip or implement ID swap
        foreach ($order as $pos => $id) {
            $stmt = $conn->prepare("UPDATE InventoryList SET SortOrder = ? WHERE ItemID = ?");
            $stmt->bind_param("ii", $pos + 1, (int)$id);
            $stmt->execute();
        }
        echo json_encode(['success' => true, 'message' => 'تم الترتيب (بناءً على SortOrder)']);
        exit();
    }
    echo json_encode(['success' => false, 'message' => 'Action غير معروف']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>