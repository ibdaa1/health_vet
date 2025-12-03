<?php
declare(strict_types=1);
/*
  Complete API for health_vet project
  Path: /htdocs/health_vet/api/api.php

  - Expects a db.php (or similar) that defines $conn as mysqli.
  - Provides JSON endpoints (see ?action=...).
  - CORS-safe for basic testing; tighten in production.
*/

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // adjust origin in production
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // short-circuit for preflight
    exit;
}

function json_exit(array $d, int $c = 200) {
    http_response_code($c);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_input(): ?array {
    $raw = file_get_contents('php://input');
    if (!$raw) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function fetch_all(mysqli_result $res): array {
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

/* Load DB connection: try common locations for db.php that defines $conn (mysqli) */
$paths = [
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
    __DIR__ . '/../config.php',
];
foreach ($paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    // Try building from constants if present
    if (defined('DB_HOST') && defined('DB_USER') && defined('DB_NAME')) {
        $dbHost = DB_HOST;
        $dbUser = DB_USER;
        $dbPass = defined('DB_PASS') ? DB_PASS : '';
        $dbName = DB_NAME;
        $dbPort = defined('DB_PORT') ? intval(DB_PORT) : 3306;
        $conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        if ($conn->connect_errno) {
            json_exit(['status' => 'error', 'message' => 'DB connection failed (from constants)', 'error' => $conn->connect_error], 500);
        }
    } else {
        json_exit(['status' => 'error', 'message' => 'DB connection not found. Ensure db.php defines $conn as mysqli or define DB_* constants'], 500);
    }
}

/* session / employee helper */
if (session_status() === PHP_SESSION_NONE) session_start();
$empId = !empty($_SESSION['user']['EmpID']) ? intval($_SESSION['user']['EmpID']) : (!empty($_REQUEST['EmpID']) ? intval($_REQUEST['EmpID']) : 0);

/* action router */
$action = $_GET['action'] ?? $_POST['action'] ?? null;

/* --- endpoints --- */

/* 1) list_inventory: try to reuse get_inventory_list.php if exists, otherwise direct query */
if ($action === 'list_inventory') {
    $getInvPath = __DIR__ . '/get_inventory_list.php';
    if (file_exists($getInvPath)) {
        // include and let it echo its JSON (it expects its own headers)
        // To avoid duplicate headers, we capture output buffer
        ob_start();
        include $getInvPath;
        $out = ob_get_clean();
        // If included script already echoed JSON, forward it
        // Attempt to decode to ensure valid JSON; otherwise return as text
        $decoded = json_decode($out, true);
        if (is_array($decoded)) {
            echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            json_exit(['status' => 'ok', 'data_raw' => $out]);
        }
    } else {
        $sql = "SELECT ItemID, ParentID, Name_AR, Name_EN FROM InventoryList ORDER BY ParentID, Name_AR";
        $res = $conn->query($sql);
        if ($res === false) json_exit(['status' => 'error', 'message' => 'Query failed', 'error' => $conn->error], 500);
        $items = fetch_all($res);
        json_exit(['status' => 'ok', 'items' => $items]);
    }
}

/* 2) list_attributes: admin listing of all attributes */
if ($action === 'list_attributes') {
    $sql = "SELECT AttributeID, Name_AR, Name_EN, CreatedAt FROM ProductAttributes ORDER BY Name_AR";
    $res = $conn->query($sql);
    if ($res === false) json_exit(['status' => 'error', 'message' => 'Query failed', 'error' => $conn->error], 500);
    $rows = fetch_all($res);
    json_exit(['status' => 'ok', 'attributes' => $rows]);
}

/* 3) get_options: options for a specific attribute */
if ($action === 'get_options') {
    $attribute_id = isset($_GET['attribute_id']) ? intval($_GET['attribute_id']) : 0;
    if ($attribute_id <= 0) json_exit(['status' => 'error', 'message' => 'Invalid attribute_id'], 400);
    $stmt = $conn->prepare("SELECT OptionID, AttributeID, Name_AR, Name_EN FROM ProductAttributeOptions WHERE AttributeID = ? ORDER BY OptionID");
    $stmt->bind_param('i', $attribute_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = fetch_all($res);
    $stmt->close();
    json_exit(['status' => 'ok', 'options' => $rows]);
}

/* 4) get_attributes: returns attributes linked to a given inventory item.
      If item is a subcategory, you can decide whether to use its top-level parent — here we return attributes directly linked to provided item.
      For main-only behavior, use get_product_attributes.php tailored earlier.
*/
if ($action === 'get_attributes') {
    $itemId = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
    if ($itemId <= 0) json_exit(['status' => 'error', 'message' => 'Invalid item_id'], 400);

    $stmt = $conn->prepare("
        SELECT ia.ID, ia.AttributeID, ia.IsRequired, ia.SortOrder, ia.DefaultOptionID,
               pa.Name_AR AS attr_ar, pa.Name_EN AS attr_en
        FROM InventoryAttributes ia
        JOIN ProductAttributes pa ON ia.AttributeID = pa.AttributeID
        WHERE ia.ItemID = ?
        ORDER BY ia.SortOrder, pa.AttributeID
    ");
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $res = $stmt->get_result();
    $attrs = fetch_all($res);
    $stmt->close();

    if (empty($attrs)) json_exit(['status' => 'ok', 'attributes' => []]);

    $attrIds = array_unique(array_map(fn($a) => intval($a['AttributeID']), $attrs));
    $inList = implode(',', array_map('intval', $attrIds));

    $opts = [];
    if ($inList !== '') {
        $sql = "SELECT OptionID, AttributeID, Name_AR AS opt_ar, Name_EN AS opt_en FROM ProductAttributeOptions WHERE AttributeID IN ($inList) ORDER BY OptionID";
        $res2 = $conn->query($sql);
        if ($res2 !== false) {
            while ($r = $res2->fetch_assoc()) {
                $opts[intval($r['AttributeID'])][] = $r;
            }
            $res2->free();
        }
    }

    $result = [];
    foreach ($attrs as $a) {
        $result[] = [
            'inventory_attribute_id' => (int)$a['ID'],
            'attribute_id' => (int)$a['AttributeID'],
            'name_ar' => $a['attr_ar'],
            'name_en' => $a['attr_en'],
            'is_required' => (bool)$a['IsRequired'],
            'sort_order' => (int)$a['SortOrder'],
            'default_option_id' => $a['DefaultOptionID'] !== null ? (int)$a['DefaultOptionID'] : null,
            'options' => $opts[$a['AttributeID']] ?? []
        ];
    }
    json_exit(['status' => 'ok', 'attributes' => $result]);
}

/* 5) create_attribute */
if ($action === 'create_attribute') {
    $input = json_input();
    if (!$input) json_exit(['status' => 'error', 'message' => 'Invalid JSON'], 400);
    $name_ar = trim($input['name_ar'] ?? '');
    $name_en = trim($input['name_en'] ?? '');
    if ($name_ar === '' && $name_en === '') json_exit(['status' => 'error', 'message' => 'Name required'], 400);
    $stmt = $conn->prepare("INSERT INTO ProductAttributes (Name_AR, Name_EN, CreatedAt, UpdatedAt) VALUES (?, ?, NOW(), NOW())");
    $stmt->bind_param('ss', $name_ar, $name_en);
    if (!$stmt->execute()) json_exit(['status' => 'error', 'message' => 'Insert failed', 'error' => $stmt->error], 500);
    $id = (int)$conn->insert_id;
    $stmt->close();
    json_exit(['status' => 'ok', 'attribute_id' => $id]);
}

/* 6) create_option */
if ($action === 'create_option') {
    $input = json_input();
    if (!$input) json_exit(['status' => 'error', 'message' => 'Invalid JSON'], 400);
    $attribute_id = isset($input['attribute_id']) ? intval($input['attribute_id']) : 0;
    $name_ar = trim($input['name_ar'] ?? '');
    $name_en = trim($input['name_en'] ?? '');
    if ($attribute_id <= 0 || ($name_ar === '' && $name_en === '')) json_exit(['status' => 'error', 'message' => 'Missing data'], 400);

    $chk = $conn->prepare("SELECT AttributeID FROM ProductAttributes WHERE AttributeID = ?");
    $chk->bind_param('i', $attribute_id);
    $chk->execute();
    $res = $chk->get_result();
    if ($res->num_rows === 0) { $chk->close(); json_exit(['status' => 'error', 'message' => 'Attribute not found'], 404); }
    $chk->close();

    $stmt = $conn->prepare("INSERT INTO ProductAttributeOptions (AttributeID, Name_AR, Name_EN, CreatedAt, UpdatedAt) VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->bind_param('iss', $attribute_id, $name_ar, $name_en);
    if (!$stmt->execute()) json_exit(['status' => 'error', 'message' => 'Insert failed', 'error' => $stmt->error], 500);
    $id = (int)$conn->insert_id;
    $stmt->close();
    json_exit(['status' => 'ok', 'option_id' => $id]);
}

/* 7) link_attribute (InventoryAttributes) */
if ($action === 'link_attribute') {
    $input = json_input();
    if (!$input) json_exit(['status' => 'error', 'message' => 'Invalid JSON'], 400);
    $item_id = isset($input['item_id']) ? intval($input['item_id']) : 0;
    $attribute_id = isset($input['attribute_id']) ? intval($input['attribute_id']) : 0;
    $is_required = isset($input['is_required']) ? intval($input['is_required']) : 0;
    $sort_order = isset($input['sort_order']) ? intval($input['sort_order']) : 0;
    $default_option_id = isset($input['default_option_id']) && $input['default_option_id'] !== '' ? intval($input['default_option_id']) : null;
    if ($item_id <= 0 || $attribute_id <= 0) json_exit(['status' => 'error', 'message' => 'Missing item_id or attribute_id'], 400);

    $chk1 = $conn->prepare("SELECT ItemID FROM InventoryList WHERE ItemID = ?");
    $chk1->bind_param('i', $item_id);
    $chk1->execute();
    $r1 = $chk1->get_result();
    if ($r1->num_rows === 0) { $chk1->close(); json_exit(['status' => 'error', 'message' => 'Inventory item not found'], 404); }
    $chk1->close();

    $chk2 = $conn->prepare("SELECT AttributeID FROM ProductAttributes WHERE AttributeID = ?");
    $chk2->bind_param('i', $attribute_id);
    $chk2->execute();
    $r2 = $chk2->get_result();
    if ($r2->num_rows === 0) { $chk2->close(); json_exit(['status' => 'error', 'message' => 'Attribute not found'], 404); }
    $chk2->close();

    if ($default_option_id !== null) {
        $chk3 = $conn->prepare("SELECT OptionID FROM ProductAttributeOptions WHERE OptionID = ? AND AttributeID = ?");
        $chk3->bind_param('ii', $default_option_id, $attribute_id);
        $chk3->execute();
        $r3 = $chk3->get_result();
        if ($r3->num_rows === 0) $default_option_id = null;
        $chk3->close();
    }

    $exists = $conn->prepare("SELECT ID FROM InventoryAttributes WHERE ItemID = ? AND AttributeID = ?");
    $exists->bind_param('ii', $item_id, $attribute_id);
    $exists->execute();
    $er = $exists->get_result();
    if ($er->num_rows > 0) {
        $row = $er->fetch_assoc();
        $exists->close();
        $upd = $conn->prepare("UPDATE InventoryAttributes SET IsRequired = ?, SortOrder = ?, DefaultOptionID = ?, UpdatedAt = NOW() WHERE ID = ?");
        $def = $default_option_id;
        $upd->bind_param('iiii', $is_required, $sort_order, $def, $row['ID']);
        $upd->execute();
        $upd->close();
        json_exit(['status' => 'ok', 'inventory_attribute_id' => (int)$row['ID'], 'message' => 'updated']);
    } else {
        $exists->close();
        if ($default_option_id === null) {
            $ins = $conn->prepare("INSERT INTO InventoryAttributes (ItemID, AttributeID, IsRequired, SortOrder, DefaultOptionID, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, NULL, NOW(), NOW())");
            $ins->bind_param('iiii', $item_id, $attribute_id, $is_required, $sort_order);
            $ok = $ins->execute();
        } else {
            $ins = $conn->prepare("INSERT INTO InventoryAttributes (ItemID, AttributeID, IsRequired, SortOrder, DefaultOptionID, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            $ins->bind_param('iiiii', $item_id, $attribute_id, $is_required, $sort_order, $default_option_id);
            $ok = $ins->execute();
        }
        if (!$ok) json_exit(['status' => 'error', 'message' => 'Insert failed', 'error' => $conn->error], 500);
        $id = (int)$conn->insert_id;
        $ins->close();
        json_exit(['status' => 'ok', 'inventory_attribute_id' => $id]);
    }
}

/* 8) save_product_attributes: save values for a product (delete existing -> re-insert) */
if ($action === 'save_product_attributes') {
    $input = json_input();
    if (!$input) json_exit(['status' => 'error', 'message' => 'Invalid JSON'], 400);
    $productId = isset($input['product_id']) ? intval($input['product_id']) : 0;
    $attributes = $input['attributes'] ?? [];
    if ($productId <= 0 || !is_array($attributes)) json_exit(['status' => 'error', 'message' => 'Missing product_id or attributes'], 400);

    $chk = $conn->prepare("SELECT Product_ID FROM tbl_Products WHERE Product_ID = ?");
    $chk->bind_param('i', $productId);
    $chk->execute();
    $r = $chk->get_result();
    if ($r->num_rows === 0) { $chk->close(); json_exit(['status' => 'error', 'message' => 'Product not found'], 404); }
    $chk->close();

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM ProductAttributeValues WHERE ProductID = ?");
        $del->bind_param('i', $productId);
        $del->execute();
        $del->close();

        foreach ($attributes as $attr) {
            $attribute_id = isset($attr['attribute_id']) ? intval($attr['attribute_id']) : 0;
            $option_id = isset($attr['option_id']) && $attr['option_id'] !== null && $attr['option_id'] !== '' ? intval($attr['option_id']) : null;
            $value = isset($attr['value']) && $attr['value'] !== '' ? (string)$attr['value'] : null;
            $quantity = isset($attr['quantity']) ? intval($attr['quantity']) : 0;

            if ($attribute_id <= 0) continue;
            if ($option_id === null && ($value === null || $value === '')) continue;

            if ($option_id === null) {
                $ins_null = $conn->prepare("INSERT INTO ProductAttributeValues (ProductID, AttributeID, OptionID, Value, Quantity, CreatedAt, UpdatedAt) VALUES (?, ?, NULL, ?, ?, NOW(), NOW())");
                $ins_null->bind_param('iisi', $productId, $attribute_id, $value, $quantity);
                $ins_null->execute();
                $ins_null->close();
            } else {
                $ins = $conn->prepare("INSERT INTO ProductAttributeValues (ProductID, AttributeID, OptionID, Value, Quantity, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                $ins->bind_param('iiisi', $productId, $attribute_id, $option_id, $value, $quantity);
                $ins->execute();
                $ins->close();
            }
        }

        $conn->commit();
        json_exit(['status' => 'ok', 'message' => 'Attributes saved']);
    } catch (Throwable $e) {
        $conn->rollback();
        json_exit(['status' => 'error', 'message' => 'Save failed', 'error' => $e->getMessage()], 500);
    }
}

/* 9) get_product_values: return saved ProductAttributeValues for product (for editing) */
if ($action === 'get_product_values') {
    $productId = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    if ($productId <= 0) json_exit(['status' => 'error', 'message' => 'Invalid product_id'], 400);

    $chk = $conn->prepare("SELECT Product_ID, InventoryItemID FROM tbl_Products WHERE Product_ID = ?");
    $chk->bind_param('i', $productId);
    $chk->execute();
    $resChk = $chk->get_result();
    if ($resChk->num_rows === 0) { $chk->close(); json_exit(['status' => 'error', 'message' => 'Product not found'], 404); }
    $productRow = $resChk->fetch_assoc();
    $chk->close();

    $sql = "
      SELECT pav.ID, pav.AttributeID, pav.OptionID, pav.Value, pav.Quantity,
             pa.Name_AR AS attr_ar, pa.Name_EN AS attr_en,
             ppo.Name_AR AS opt_ar, ppo.Name_EN AS opt_en
      FROM ProductAttributeValues pav
      LEFT JOIN ProductAttributes pa ON pav.AttributeID = pa.AttributeID
      LEFT JOIN ProductAttributeOptions ppo ON pav.OptionID = ppo.OptionID
      WHERE pav.ProductID = ?
      ORDER BY pav.AttributeID, pav.OptionID, pav.ID
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();

    $values = [];
    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $entry = [
            'id' => (int)$row['ID'],
            'attribute_id' => (int)$row['AttributeID'],
            'option_id' => $row['OptionID'] !== null ? (int)$row['OptionID'] : null,
            'value' => $row['Value'] !== null ? $row['Value'] : null,
            'quantity' => is_null($row['Quantity']) ? 0 : (float)$row['Quantity'],
            'attribute_name_ar' => $row['attr_ar'],
            'attribute_name_en' => $row['attr_en'],
            'option_name_ar' => $row['opt_ar'],
            'option_name_en' => $row['opt_en'],
        ];
        $values[] = $entry;
        $aid = $entry['attribute_id'];
        if (!isset($grouped[$aid])) $grouped[$aid] = [];
        $grouped[$aid][] = $entry;
    }
    $stmt->close();

    json_exit([
        'status' => 'ok',
        'product' => ['id' => (int)$productRow['Product_ID'], 'inventory_item_id' => (int)$productRow['InventoryItemID']],
        'values' => $values,
        'groupedByAttribute' => $grouped
    ]);
}

/* fallback */
json_exit(['status' => 'error', 'message' => 'No action specified or unknown action'], 400);