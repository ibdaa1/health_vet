<?php
/**
 * add_Products.php
 * Robust endpoint for create/update/delete products and variants.
 * Fixes:
 *  - Ensure SKU is generated when missing.
 *  - Avoid passing expressions to mysqli_stmt::bind_param (use variables).
 *  - Validate InventoryItemID to avoid FK errors.
 *
 * Backup your existing file before replacing.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

function json_exit(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/* Load DB */
$dbPaths = [
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php'
];
$dbLoaded = false;
foreach ($dbPaths as $p) {
    if (file_exists($p)) { require_once $p; $dbLoaded = true; break; }
}
if (!$dbLoaded || !isset($conn) || !($conn instanceof mysqli)) json_exit(['success'=>false,'message'=>'DB connection not found (db.php)'],500);
if (session_status() === PHP_SESSION_NONE) session_start();

/* Helpers */
function has_table(mysqli $conn, string $table): bool {
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return ($res && $res->num_rows > 0);
}
function table_has_column(mysqli $conn, string $table, string $col): bool {
    if (!has_table($conn, $table)) return false;
    $res = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "` LIKE '" . $conn->real_escape_string($col) . "'");
    return ($res && $res->num_rows > 0);
}
function handle_image_upload_field(string $fieldName) {
    $uploadDir = __DIR__ . '/../uploads/ProductImage';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) return ['error'=>'Failed to create upload dir'];
    }
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) return ['filename'=>null];
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['error'=>'Upload error code: ' . $file['error']];
    if ($file['size'] > 5 * 1024 * 1024) return ['error'=>'File too large (max 5MB)'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
    $allowed = ['image/jpeg'=>'jpg','image/pjpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) return ['error'=>'Unsupported image type: ' . $mime];
    try { $name = time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime]; }
    catch (Exception $e) { $name = time() . '_' . substr(md5(uniqid('',true)),0,12) . '.' . $allowed[$mime]; }
    $dest = $uploadDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return ['error'=>'Failed to move uploaded file'];
    @chmod($dest, 0644);
    return ['filename'=>$name];
}
function validate_inventory_item(mysqli $conn, $inventoryItemId): ?int {
    if ($inventoryItemId === null || $inventoryItemId === '') return null;
    $iid = intval($inventoryItemId);
    if ($iid <= 0) return null;
    if (!has_table($conn, 'InventoryList')) return null;
    $stmt = $conn->prepare("SELECT 1 FROM InventoryList WHERE ItemID = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $iid);
    $stmt->execute();
    $r = $stmt->get_result();
    $exists = ($r && $r->num_rows > 0);
    $stmt->close();
    return $exists ? $iid : null;
}
function find_users_table_and_id(mysqli $conn) {
    $candidates = ['Users','tbl_Users','tbl_Employees','tbl_employees','employees','users','tbl_staff','staff'];
    foreach ($candidates as $t) {
        $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
        if ($res && $res->num_rows) {
            $colsRes = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($t) . "`");
            $cols = [];
            if ($colsRes) while ($c = $colsRes->fetch_assoc()) $cols[] = $c['Field'];
            $idCandidates = ['EmpID','EmpId','empid','id','ID','user_id','UserID'];
            $idCol = null;
            foreach ($idCandidates as $ic) if (in_array($ic, $cols)) { $idCol = $ic; break; }
            $nameCandidates = ['Name','FullName','EmpName','display_name','Name_AR','Name_EN','name'];
            $nameCol = null;
            foreach ($nameCandidates as $nc) if (in_array($nc, $cols)) { $nameCol = $nc; break; }
            return ['table'=>$t,'id'=>$idCol,'name'=>$nameCol];
        }
    }
    return null;
}
function validate_empid(mysqli $conn, $candidate): ?int {
    if (!$candidate) return null;
    $candidate = intval($candidate);
    if ($candidate <= 0) return null;
    $det = find_users_table_and_id($conn);
    if (!$det || !$det['id']) return null;
    $tbl = $det['table']; $idCol = $det['id'];
    $stmt = $conn->prepare("SELECT 1 FROM `" . $conn->real_escape_string($tbl) . "` WHERE `" . $conn->real_escape_string($idCol) . "` = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $candidate);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows);
    $stmt->close();
    return $ok ? $candidate : null;
}

/* Parse input */
$raw = file_get_contents('php://input');
$isForm = !empty($_POST) || !empty($_FILES);
$in = [];
if ($isForm) { foreach ($_POST as $k=>$v) $in[$k] = $v; }
else { $decoded = json_decode($raw, true); if (is_array($decoded)) $in = $decoded; }

/* Fields */
$action = strtolower(trim($in['action'] ?? ($_REQUEST['action'] ?? 'save')));
$ProductID = isset($in['ProductID']) && $in['ProductID'] !== '' ? intval($in['ProductID']) : null;
$InventoryItemID_raw = $in['InventoryItemID'] ?? null;
$Product_Code_raw = isset($in['Product_Code']) ? trim((string)$in['Product_Code']) : '';
$Name_AR = $in['Name_AR'] ?? '';
$Name_EN = $in['Name_EN'] ?? '';
$Supplier = $in['Supplier'] ?? '';
$attributes = isset($in['Attributes']) ? (is_array($in['Attributes']) ? $in['Attributes'] : (json_decode($in['Attributes'], true) ?: [])) : [];
$variants = isset($in['Variants']) ? (is_array($in['Variants']) ? $in['Variants'] : (json_decode($in['Variants'], true) ?: [])) : [];

/* Emp */
$sessionEmp = $_SESSION['user']['EmpID'] ?? $_SESSION['EmpID'] ?? null;
$reqEmp = $in['EmpID'] ?? null;
$empCandidate = $sessionEmp ?? $reqEmp ?? null;
$empID = validate_empid($conn, $empCandidate);

/* Inventory validation */
$InventoryItemID_valid = validate_inventory_item($conn, $InventoryItemID_raw);
$inventory_warning = null;
if ($InventoryItemID_raw !== null && $InventoryItemID_raw !== '' && $InventoryItemID_valid === null) {
    $inventory_warning = 'InventoryItemID not found — will NOT be saved to avoid FK constraint';
}

/* Product image */
$uploadedProductImage = null;
if ($isForm) {
    $imgRes = handle_image_upload_field('ProductImage');
    if (isset($imgRes['error'])) json_exit(['success'=>false,'message'=>'ProductImage upload error: '.$imgRes['error']],400);
    $uploadedProductImage = $imgRes['filename'] ?? null;
}

/* Transaction */
$conn->begin_transaction();
try {
    if ($action === 'delete') {
        if (!$ProductID) throw new Exception('ProductID required for delete');
        if (has_table($conn, 'tbl_ProductVariants')) {
            $stmt = $conn->prepare("DELETE FROM tbl_ProductVariants WHERE ProductID = ?");
            $stmt->bind_param('i', $ProductID); $stmt->execute(); $stmt->close();
        }
        if (has_table($conn, 'ProductAttributeValues')) {
            $stmt = $conn->prepare("DELETE FROM ProductAttributeValues WHERE ProductID = ?");
            $stmt->bind_param('i', $ProductID); $stmt->execute(); $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM tbl_Products WHERE Product_ID = ? LIMIT 1");
        $stmt->bind_param('i', $ProductID); $stmt->execute(); $stmt->close();
        $conn->commit();
        json_exit(['success'=>true,'message'=>'Product deleted','ProductID'=>$ProductID]);
    }

    /* UPDATE path */
    if ($ProductID) {
        // fetch existing product
        $existing = null;
        $stmt = $conn->prepare("SELECT * FROM tbl_Products WHERE Product_ID = ? LIMIT 1");
        $stmt->bind_param('i', $ProductID); $stmt->execute(); $res = $stmt->get_result();
        if ($res && $res->num_rows) $existing = $res->fetch_assoc();
        $stmt->close();

        // Build update dynamic
        $setParts = []; $bindTypes = ''; $bindVals = [];
        if ($InventoryItemID_valid !== null && table_has_column($conn,'tbl_Products','InventoryItemID')) { $setParts[] = "`InventoryItemID` = ?"; $bindTypes.='i'; $bindVals[] = $InventoryItemID_valid; }
        if ($Product_Code_raw !== '' && table_has_column($conn,'tbl_Products','Product_Code')) { $setParts[] = "`Product_Code` = ?"; $bindTypes.='s'; $bindVals[] = $Product_Code_raw; }
        if ($Name_AR !== '' && table_has_column($conn,'tbl_Products','Name_AR')) { $setParts[] = "`Name_AR` = ?"; $bindTypes.='s'; $bindVals[] = $Name_AR; }
        if ($Name_EN !== '' && table_has_column($conn,'tbl_Products','Name_EN')) { $setParts[] = "`Name_EN` = ?"; $bindTypes.='s'; $bindVals[] = $Name_EN; }
        if ($Supplier !== '' && table_has_column($conn,'tbl_Products','Supplier')) { $setParts[] = "`Supplier` = ?"; $bindTypes.='s'; $bindVals[] = $Supplier; }
        if ($uploadedProductImage !== null && table_has_column($conn,'tbl_Products','ProductImage')) { $setParts[] = "`ProductImage` = ?"; $bindTypes.='s'; $bindVals[] = $uploadedProductImage; }
        if ($empID !== null && table_has_column($conn,'tbl_Products','UpdatedByEmpID')) { $setParts[] = "`UpdatedByEmpID` = ?"; $bindTypes.='i'; $bindVals[] = $empID; }
        if (table_has_column($conn,'tbl_Products','UpdatedAt')) $setParts[] = "`UpdatedAt` = NOW()";

        if (!empty($setParts)) {
            $sql = "UPDATE tbl_Products SET " . implode(', ', $setParts) . " WHERE Product_ID = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error . ' SQL: ' . $sql);
            if ($bindTypes !== '') {
                $bindTypes .= 'i'; $bindVals[] = $ProductID;
                $refs = []; $refs[] = & $bindTypes; foreach ($bindVals as $k => $v) $refs[] = & $bindVals[$k];
                call_user_func_array([$stmt, 'bind_param'], $refs);
            } else {
                $stmt->bind_param('i', $ProductID);
            }
            if (!$stmt->execute()) throw new Exception('Execute update failed: ' . $stmt->error);
            $stmt->close();
        }

        // Attributes: delete + insert
        if (has_table($conn,'ProductAttributeValues')) {
            $stmt = $conn->prepare("DELETE FROM ProductAttributeValues WHERE ProductID = ?");
            $stmt->bind_param('i', $ProductID); $stmt->execute(); $stmt->close();
            if (!empty($attributes) && is_array($attributes)) {
                $ins = $conn->prepare("INSERT INTO ProductAttributeValues (ProductID, AttributeID, OptionID, Value, Quantity, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                if (!$ins) throw new Exception('Prepare insert attribute failed: ' . $conn->error);
                foreach ($attributes as $attr) {
                    $attrID = isset($attr['AttributeID']) ? intval($attr['AttributeID']) : 0; if (!$attrID) continue;
                    $optID = isset($attr['OptionID']) && $attr['OptionID'] !== '' ? intval($attr['OptionID']) : null;
                    $val = isset($attr['Value']) ? (string)$attr['Value'] : '';
                    $avalQty = isset($attr['Quantity']) ? intval($attr['Quantity']) : 0;
                    $optToBind = $optID !== null ? $optID : null;
                    $ins->bind_param('iiisi', $ProductID, $attrID, $optToBind, $val, $avalQty);
                    if (!$ins->execute()) throw new Exception('Insert attribute failed: ' . $ins->error);
                }
                $ins->close();
            }
        }

        // Variants UPDATE: delete existing then insert provided variants (preserve images)
        if (has_table($conn,'tbl_ProductVariants')) {
            // existing map
            $existingVariantMap = [];
            $resOld = $conn->query("SELECT OptionIDs, ProductImage, UnitsPerPack FROM tbl_ProductVariants WHERE ProductID = " . intval($ProductID));
            if ($resOld) while ($r = $resOld->fetch_assoc()) $existingVariantMap[trim($r['OptionIDs'])] = $r;

            $del = $conn->prepare("DELETE FROM tbl_ProductVariants WHERE ProductID = ?");
            $del->bind_param('i', $ProductID); $del->execute(); $del->close();

            $sqlV = "INSERT INTO tbl_ProductVariants (ProductID, SKU, OptionIDs, Quantity, MinQuantity, ExpiryDate, ProductImage, BaseUnit, UnitsPerPack, QuantityBase, CreatedAt, UpdatedAt)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmtV = $conn->prepare($sqlV);
            if (!$stmtV) throw new Exception('Prepare insert variant failed: ' . $conn->error);

            foreach ($variants as $v) {
                $optionIDs = [];
                if (isset($v['OptionIDs'])) {
                    if (is_array($v['OptionIDs'])) $optionIDs = array_map('intval', $v['OptionIDs']);
                    else $optionIDs = array_filter(array_map('trim', explode(',', (string)$v['OptionIDs'])), fn($x)=>$x!=='');
                }
                $optionCSV = implode(',', $optionIDs);

                // SKU generation if empty
                $rawSku = isset($v['SKU']) ? trim((string)$v['SKU']) : '';
                $pid_for_sku = intval($ProductID);
                if ($rawSku === '') {
                    if (!empty($Product_Code_raw)) $rawSku = $Product_Code_raw . '-' . ($optionCSV ?: uniqid());
                    else $rawSku = ($pid_for_sku ? (string)$pid_for_sku : 'P' . time()) . '-' . ($optionCSV ?: substr(md5(uniqid('',true)),0,6));
                }
                $sku = $rawSku;

                // UnitsPerPack fallback
                $unitsPerPack = null;
                if (isset($v['UnitsPerPack']) && $v['UnitsPerPack'] !== '') $unitsPerPack = (float)$v['UnitsPerPack'];
                elseif (isset($existingVariantMap[$optionCSV]) && $existingVariantMap[$optionCSV]['UnitsPerPack'] !== null) $unitsPerPack = (float)$existingVariantMap[$optionCSV]['UnitsPerPack'];
                else $unitsPerPack = 1.0;

                $qty = isset($v['Quantity']) ? (float)$v['Quantity'] : 0.0;
                $minq = isset($v['MinQuantity']) ? intval($v['MinQuantity']) : 0;
                $expiry = (!empty($v['ExpiryDate']) && $v['ExpiryDate'] !== '0000-00-00') ? $v['ExpiryDate'] : null;

                // variant image
                $optionKey = str_replace(',', '_', $optionCSV);
                $vimg = null;
                if ($isForm) {
                    $fieldName = 'VariantImage_' . $optionKey;
                    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] !== UPLOAD_ERR_NO_FILE) {
                        $imgRes = handle_image_upload_field($fieldName);
                        if (isset($imgRes['error'])) throw new Exception('Variant image upload error: ' . $imgRes['error']);
                        $vimg = $imgRes['filename'] ?? null;
                    } elseif (isset($existingVariantMap[$optionCSV]['ProductImage'])) {
                        $vimg = $existingVariantMap[$optionCSV]['ProductImage'];
                    }
                } else {
                    if (isset($existingVariantMap[$optionCSV]['ProductImage'])) $vimg = $existingVariantMap[$optionCSV]['ProductImage'];
                }

                $qbase = isset($v['QuantityBase']) && $v['QuantityBase'] !== '' ? (float)$v['QuantityBase'] : ($qty * ($unitsPerPack ?? 1.0));

                // Prepare variables for bind_param (must be variables, not expressions)
                $skuBind = $sku;
                $optionCSVBind = $optionCSV !== '' ? $optionCSV : null;
                $qtyBind = $qty;
                $minqBind = $minq;
                $expiryBind = $expiry !== null ? $expiry : null;
                $vimgBind = $vimg !== null ? $vimg : null;
                $baseUnitBind = isset($v['BaseUnit']) && $v['BaseUnit'] !== '' ? $v['BaseUnit'] : null;
                $unitsPerPackBind = $unitsPerPack;
                $qbaseBind = $qbase;

                // bind: types => i (ProductID), s sku, s optionCSV, d qty, i minq, s expiry, s vimg, s baseUnit, d unitsPerPack, d qbase
                $types = 'issdisssdd';
                $stmtV->bind_param($types,
                    $ProductID,
                    $skuBind,
                    $optionCSVBind,
                    $qtyBind,
                    $minqBind,
                    $expiryBind,
                    $vimgBind,
                    $baseUnitBind,
                    $unitsPerPackBind,
                    $qbaseBind
                );
                if (!$stmtV->execute()) throw new Exception('Insert variant failed: ' . $stmtV->error);
            } // end variants loop
            $stmtV->close();
        } // end variants handling

        $conn->commit();
        $resp = ['success'=>true,'message'=>'Product updated','ProductID'=>$ProductID];
        if ($inventory_warning) $resp['note'] = $inventory_warning;
        json_exit($resp);
    } // end UPDATE

    /* CREATE path */
    $productCols = [];
    $resCols = $conn->query("SHOW COLUMNS FROM `tbl_Products`");
    if ($resCols) while ($c = $resCols->fetch_assoc()) $productCols[] = $c['Field'];

    $fields = []; $placeholders = []; $types = ''; $values = [];
    if (in_array('InventoryItemID',$productCols) && $InventoryItemID_valid !== null) { $fields[]='InventoryItemID'; $placeholders[]='?'; $types.='i'; $values[]=$InventoryItemID_valid; }
    if (in_array('Product_Code',$productCols) && $Product_Code_raw !== '') { $fields[]='Product_Code'; $placeholders[]='?'; $types.='s'; $values[]=$Product_Code_raw; }
    if (in_array('Name_AR',$productCols)) { $fields[]='Name_AR'; $placeholders[]='?'; $types.='s'; $values[]=$Name_AR; }
    if (in_array('Name_EN',$productCols)) { $fields[]='Name_EN'; $placeholders[]='?'; $types.='s'; $values[]=$Name_EN; }
    if (in_array('Supplier',$productCols)) { $fields[]='Supplier'; $placeholders[]='?'; $types.='s'; $values[]=$Supplier; }
    if (in_array('ProductImage',$productCols) && $uploadedProductImage !== null) { $fields[]='ProductImage'; $placeholders[]='?'; $types.='s'; $values[]=$uploadedProductImage; }
    if (in_array('AddedByEmpID',$productCols) && $empID !== null) { $fields[]='AddedByEmpID'; $placeholders[]='?'; $types.='i'; $values[]=$empID; }

    if (empty($fields)) throw new Exception('No insertable product columns detected');

    $sql = "INSERT INTO tbl_Products (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare insert product failed: ' . $conn->error . ' SQL: ' . $sql);
    if ($types !== '') {
        $refs = []; $refs[] = & $types;
        foreach ($values as $k => $v) $refs[] = & $values[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    if (!$stmt->execute()) throw new Exception('Execute insert product failed: ' . $stmt->error . ' SQL: ' . $sql);
    $newProductID = intval($stmt->insert_id);
    $stmt->close();

    // insert attributes
    if (has_table($conn,'ProductAttributeValues') && !empty($attributes)) {
        $ins = $conn->prepare("INSERT INTO ProductAttributeValues (ProductID, AttributeID, OptionID, Value, Quantity, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        if (!$ins) throw new Exception('Prepare insert attribute failed: ' . $conn->error);
        foreach ($attributes as $attr) {
            $attrID = isset($attr['AttributeID']) ? intval($attr['AttributeID']) : 0; if (!$attrID) continue;
            $optID = isset($attr['OptionID']) && $attr['OptionID'] !== '' ? intval($attr['OptionID']) : null;
            $val = isset($attr['Value']) ? (string)$attr['Value'] : '';
            $avalQty = isset($attr['Quantity']) ? intval($attr['Quantity']) : 0;
            $optToBind = $optID !== null ? $optID : null;
            $ins->bind_param('iiisi', $newProductID, $attrID, $optToBind, $val, $avalQty);
            if (!$ins->execute()) throw new Exception('Insert attribute failed: ' . $ins->error);
        }
        $ins->close();
    }

    // insert variants (create)
    if (has_table($conn,'tbl_ProductVariants') && !empty($variants)) {
        $sqlV = "INSERT INTO tbl_ProductVariants (ProductID, SKU, OptionIDs, Quantity, MinQuantity, ExpiryDate, ProductImage, BaseUnit, UnitsPerPack, QuantityBase, CreatedAt, UpdatedAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $stmtV = $conn->prepare($sqlV);
        if (!$stmtV) throw new Exception('Prepare insert variant failed: ' . $conn->error);

        foreach ($variants as $v) {
            $optionIDs = [];
            if (isset($v['OptionIDs'])) {
                if (is_array($v['OptionIDs'])) $optionIDs = array_map('intval', $v['OptionIDs']);
                else $optionIDs = array_filter(array_map('trim', explode(',', (string)$v['OptionIDs'])), fn($x)=>$x!=='');
            }
            $optionCSV = implode(',', $optionIDs);

            // SKU fallback
            $rawSku = isset($v['SKU']) ? trim((string)$v['SKU']) : '';
            if ($rawSku === '') {
                if (!empty($Product_Code_raw)) $rawSku = $Product_Code_raw . '-' . ($optionCSV ?: uniqid());
                else $rawSku = ($newProductID ? (string)$newProductID : 'P' . time()) . '-' . ($optionCSV ?: substr(md5(uniqid('',true)),0,6));
            }
            $skuBind = $rawSku;

            $qty = isset($v['Quantity']) ? (float)$v['Quantity'] : 0.0;
            $minq = isset($v['MinQuantity']) ? intval($v['MinQuantity']) : 0;
            $expiryBind = (!empty($v['ExpiryDate']) && $v['ExpiryDate'] !== '0000-00-00') ? $v['ExpiryDate'] : null;

            // variant image file
            $vimg = null;
            if ($isForm) {
                $optionKey = str_replace(',', '_', $optionCSV);
                $fieldName = 'VariantImage_' . $optionKey;
                if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] !== UPLOAD_ERR_NO_FILE) {
                    $imgRes = handle_image_upload_field($fieldName);
                    if (isset($imgRes['error'])) throw new Exception('Variant image upload error: ' . $imgRes['error']);
                    $vimg = $imgRes['filename'] ?? null;
                }
            }

            $unitsPerPack = isset($v['UnitsPerPack']) && $v['UnitsPerPack'] !== '' ? (float)$v['UnitsPerPack'] : 1.0;
            $qbase = isset($v['QuantityBase']) && $v['QuantityBase'] !== '' ? (float)$v['QuantityBase'] : ($qty * $unitsPerPack);

            $optionCSVBind = $optionCSV !== '' ? $optionCSV : null;
            $vimgBind = $vimg !== null ? $vimg : null;
            $baseUnitBind = isset($v['BaseUnit']) && $v['BaseUnit'] !== '' ? $v['BaseUnit'] : null;
            $qtyBind = $qty;
            $minqBind = $minq;
            $unitsPerPackBind = $unitsPerPack;
            $qbaseBind = $qbase;

            $typesV = 'issdisssdd';
            $stmtV->bind_param($typesV,
                $newProductID,
                $skuBind,
                $optionCSVBind,
                $qtyBind,
                $minqBind,
                $expiryBind,
                $vimgBind,
                $baseUnitBind,
                $unitsPerPackBind,
                $qbaseBind
            );
            if (!$stmtV->execute()) throw new Exception('Insert variant failed: ' . $stmtV->error);
        }
        $stmtV->close();
    }

    $conn->commit();
    $resp = ['success'=>true,'message'=>'Product created','ProductID'=>$newProductID];
    if ($inventory_warning) $resp['note'] = $inventory_warning;
    json_exit($resp, 201);

} catch (Throwable $ex) {
    $conn->rollback();
    if (!empty($uploadedProductImage)) {
        $p = __DIR__ . '/../uploads/ProductImage/' . $uploadedProductImage;
        if (file_exists($p)) @unlink($p);
    }
    json_exit(['success'=>false,'message'=>'Server error: ' . $ex->getMessage(),'trace'=>$ex->getTraceAsString()], 500);
}
?>