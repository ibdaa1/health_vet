<?php
// delete_visit_full.php
// Delete a visit and all related data (visit products + restore stock + stock movements + related references).
// Modified:
// - fixed SQL error caused by using prepared placeholders in SHOW queries (some servers don't accept placeholders there).
// - safer handling of dynamic table/column names (escaped).
// - will attempt to remove rows from tables that reference the visit using common column names.
// - still best-effort and destructive: test on staging and backup DB first.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

function json_exit(array $data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* include DB bootstrap (try several locations) */
$candidates = [
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../includes/db.php'
];
$dbIncluded = false;
foreach ($candidates as $p) {
    if ($p && file_exists($p) && is_readable($p)) { require_once $p; $dbIncluded = true; break; }
}
if (!$dbIncluded) json_exit(['success'=>false, 'message'=>'DB bootstrap not found. Tried: '.implode(' | ',$candidates)], 500);
if (!isset($conn) || !($conn instanceof mysqli)) json_exit(['success'=>false,'message'=>'DB connection not available from db.php'],500);

/* start session and get EmpID */
if (session_status() === PHP_SESSION_NONE) session_start();
$empId = 0;
if (!empty($_SESSION['user']['EmpID'])) $empId = intval($_SESSION['user']['EmpID']);
elseif (!empty($_REQUEST['EmpID'])) $empId = intval($_REQUEST['EmpID']);

/* simple request reader */
function req($k) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST[$k])) return trim((string)$_POST[$k]);
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json[$k])) return trim((string)$json[$k]);
    } else {
        if (isset($_GET[$k])) return trim((string)$_GET[$k]);
    }
    return null;
}

$visitIdRaw = req('VisitID') ?? req('visit_id') ?? req('id');
$force = (req('force') === '1' || req('force') === 1) ? 1 : 0;
if (!$visitIdRaw) json_exit(['success'=>false,'message'=>'VisitID is required'],400);
$visitID = intval($visitIdRaw);
if ($visitID <= 0) json_exit(['success'=>false,'message'=>'Invalid VisitID'],400);

/* helpers to detect tables/columns (avoid prepared placeholders for SHOW queries) */
function table_exists(mysqli $conn, $table) {
    $tableEsc = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
    return ($res && $res->num_rows > 0);
}
function column_exists(mysqli $conn, $table, $column) {
    $tableEsc = $conn->real_escape_string($table);
    $colEsc = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'");
    return ($res && $res->num_rows > 0);
}

/* get unitsPerPack and update QuantityBase helpers */
function get_variant_info(mysqli $conn, $variantID) {
    $out = ['UnitsPerPack'=>1.0,'BaseUnit'=>'','QuantityBase'=>null,'ProductID'=>null];
    $stmt = $conn->prepare("SELECT UnitsPerPack, BaseUnit, QuantityBase, ProductID FROM tbl_ProductVariants WHERE VariantID = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $variantID);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows) {
            $r = $res->fetch_assoc();
            $out['UnitsPerPack'] = isset($r['UnitsPerPack']) ? floatval($r['UnitsPerPack']) : 1.0;
            $out['BaseUnit'] = $r['BaseUnit'] ?? '';
            $out['QuantityBase'] = isset($r['QuantityBase']) ? floatval($r['QuantityBase']) : null;
            $out['ProductID'] = isset($r['ProductID']) ? intval($r['ProductID']) : null;
        }
        $stmt->close();
    }
    return $out;
}
function get_product_info(mysqli $conn, $productID) {
    $out = ['UnitsPerPack'=>1.0,'BaseUnit'=>'','QuantityBase'=>null];
    $stmt = $conn->prepare("SELECT Default_UnitsPerPack AS UnitsPerPack, Default_BaseUnit AS BaseUnit, QuantityBase FROM tbl_Products WHERE Product_ID = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $productID);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows) {
            $r = $res->fetch_assoc();
            $out['UnitsPerPack'] = isset($r['UnitsPerPack']) ? floatval($r['UnitsPerPack']) : 1.0;
            $out['BaseUnit'] = $r['BaseUnit'] ?? '';
            $out['QuantityBase'] = isset($r['QuantityBase']) ? floatval($r['QuantityBase']) : null;
        }
        $stmt->close();
    }
    return $out;
}

/* Begin transactional deletion */
try {
    $conn->begin_transaction();

    // 1) Fetch all visit products for this visit (if table exists)
    $visitProducts = [];
    if (table_exists($conn, 'tbl_VisitProducts') && column_exists($conn, 'tbl_VisitProducts', 'VisitID')) {
        $stmt = $conn->prepare("SELECT * FROM tbl_VisitProducts WHERE VisitID = ?");
        if ($stmt) {
            $stmt->bind_param('i', $visitID);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) $visitProducts[] = $r;
            }
            $stmt->close();
        }
    }

    // 2) For each visit product, restore stock (best-effort) and insert reverse stock movement
    foreach ($visitProducts as $vp) {
        $vpID = isset($vp['VisitProductID']) ? intval($vp['VisitProductID']) : null;
        $variantID = isset($vp['VariantID']) ? intval($vp['VariantID']) : (isset($vp['VariantId']) ? intval($vp['VariantId']) : null);
        $productID = isset($vp['ProductID']) ? intval($vp['ProductID']) : (isset($vp['ProductId']) ? intval($vp['ProductId']) : null);

        // Determine restore amount in base units: prefer BaseQuantity column if present
        $baseQty = null;
        if (isset($vp['BaseQuantity']) && $vp['BaseQuantity'] !== null && $vp['BaseQuantity'] !== '') $baseQty = floatval($vp['BaseQuantity']);
        elseif (isset($vp['BaseQty']) && $vp['BaseQty'] !== null && $vp['BaseQty'] !== '') $baseQty = floatval($vp['BaseQty']);

        // fallback: use Quantity * unitsPerPack (Quantity may represent packs)
        $userQty = 0.0;
        if (isset($vp['Quantity']) && $vp['Quantity'] !== null && $vp['Quantity'] !== '') $userQty = floatval($vp['Quantity']);
        elseif (isset($vp['Qty']) && $vp['Qty'] !== null && $vp['Qty'] !== '') $userQty = floatval($vp['Qty']);
        $restoreBase = 0.0;

        // Get unitsPerPack from variant/product
        $unitsPerPack = 1.0;
        if ($variantID) {
            $vInfo = get_variant_info($conn, $variantID);
            $unitsPerPack = $vInfo['UnitsPerPack'] ?: 1.0;
        } elseif ($productID) {
            $pInfo = get_product_info($conn, $productID);
            $unitsPerPack = $pInfo['UnitsPerPack'] ?: 1.0;
        }

        if ($baseQty !== null) $restoreBase = $baseQty;
        else $restoreBase = $userQty * ($unitsPerPack ?: 1.0);

        if ($restoreBase <= 0) {
            $restoreBase = 0.0;
        } else {
            // Update QuantityBase on variant or product
            if ($variantID) {
                $stmt = $conn->prepare("SELECT QuantityBase FROM tbl_ProductVariants WHERE VariantID = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $variantID);
                    $stmt->execute();
                    $rv = $stmt->get_result();
                    $before = 0.0;
                    if ($rv && $rv->num_rows) { $row = $rv->fetch_assoc(); $before = floatval($row['QuantityBase'] ?? 0); }
                    $stmt->close();
                    $after = $before + $restoreBase;
                    $packsLeft = ($unitsPerPack > 0) ? floor($after / $unitsPerPack) : 0;
                    $stmt = $conn->prepare("UPDATE tbl_ProductVariants SET QuantityBase = ?, Quantity = ? WHERE VariantID = ?");
                    if (!$stmt) throw new Exception('Prepare failed updating variant: ' . $conn->error);
                    $stmt->bind_param('dii', $after, $packsLeft, $variantID);
                    if (!$stmt->execute()) throw new Exception('Failed updating variant QuantityBase: ' . $stmt->error);
                    $stmt->close();

                    // Insert reverse stock movement if exists
                    if (table_exists($conn, 'tbl_StockMovements')) {
                        $resCols = $conn->query("SHOW COLUMNS FROM tbl_StockMovements");
                        $cols = [];
                        while ($c = $resCols->fetch_assoc()) $cols[] = $c['Field'];
                        $movement = [
                            'ProductID' => $productID ?? null,
                            'VariantID' => $variantID,
                            'ChangeQty' => $restoreBase,
                            'BeforeQty' => $before,
                            'AfterQty' => $after,
                            'Reason' => "Reversal - delete visit {$visitID}",
                            'ReferenceType' => "visit_delete",
                            'ReferenceID' => $vpID ? $vpID : $visitID,
                            'EmpID' => $empId,
                            'CreatedAt' => date('Y-m-d H:i:s')
                        ];
                        $insCols = []; $insVals = [];
                        foreach ($cols as $col) {
                            if (array_key_exists($col, $movement) && $movement[$col] !== null) {
                                $insCols[] = "`{$col}`";
                                $val = $movement[$col];
                                if (is_string($val)) $insVals[] = "'" . $conn->real_escape_string($val) . "'";
                                else $insVals[] = floatval($val);
                            }
                        }
                        if (!empty($insCols)) {
                            $sql = "INSERT INTO tbl_StockMovements (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insVals) . ")";
                            if (!$conn->query($sql)) throw new Exception('Failed inserting stock movement: ' . $conn->error . ' SQL: ' . $sql);
                        }
                    }
                }
            } else if ($productID) {
                $stmt = $conn->prepare("SELECT QuantityBase FROM tbl_Products WHERE Product_ID = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $productID);
                    $stmt->execute();
                    $rv = $stmt->get_result();
                    $before = 0.0;
                    if ($rv && $rv->num_rows) { $row = $rv->fetch_assoc(); $before = floatval($row['QuantityBase'] ?? 0); }
                    $stmt->close();
                    $after = $before + $restoreBase;
                    $stmt = $conn->prepare("UPDATE tbl_Products SET QuantityBase = ? WHERE Product_ID = ?");
                    if (!$stmt) throw new Exception('Prepare failed updating product: ' . $conn->error);
                    $stmt->bind_param('di', $after, $productID);
                    if (!$stmt->execute()) throw new Exception('Failed updating product QuantityBase: ' . $stmt->error);
                    $stmt->close();

                    if (table_exists($conn, 'tbl_StockMovements')) {
                        $resCols = $conn->query("SHOW COLUMNS FROM tbl_StockMovements");
                        $cols = [];
                        while ($c = $resCols->fetch_assoc()) $cols[] = $c['Field'];
                        $movement = [
                            'ProductID' => $productID,
                            'VariantID' => null,
                            'ChangeQty' => $restoreBase,
                            'BeforeQty' => $before,
                            'AfterQty' => $after,
                            'Reason' => "Reversal - delete visit {$visitID}",
                            'ReferenceType' => "visit_delete",
                            'ReferenceID' => $vpID ? $vpID : $visitID,
                            'EmpID' => $empId,
                            'CreatedAt' => date('Y-m-d H:i:s')
                        ];
                        $insCols = []; $insVals = [];
                        foreach ($cols as $col) {
                            if (array_key_exists($col, $movement) && $movement[$col] !== null) {
                                $insCols[] = "`{$col}`";
                                $val = $movement[$col];
                                if (is_string($val)) $insVals[] = "'" . $conn->real_escape_string($val) . "'";
                                else $insVals[] = floatval($val);
                            }
                        }
                        if (!empty($insCols)) {
                            $sql = "INSERT INTO tbl_StockMovements (" . implode(',', $insCols) . ") VALUES (" . implode(',', $insVals) . ")";
                            if (!$conn->query($sql)) throw new Exception('Failed inserting stock movement: ' . $conn->error . ' SQL: ' . $sql);
                        }
                    }
                }
            }
        }

        // delete the visit product row
        if (isset($vp['VisitProductID'])) {
            $delStmt = $conn->prepare("DELETE FROM tbl_VisitProducts WHERE VisitProductID = ?");
            if ($delStmt) {
                $vidp = intval($vp['VisitProductID']);
                $delStmt->bind_param('i', $vidp);
                if (!$delStmt->execute()) throw new Exception('Failed deleting VisitProduct row: '.$delStmt->error);
                $delStmt->close();
            }
        }
    } // end foreach visitProducts

    // 3) Optionally delete stock movements referencing the visit (if force)
    if ($force && table_exists($conn, 'tbl_StockMovements')) {
        $stmt = $conn->prepare("DELETE FROM tbl_StockMovements WHERE (ReferenceType = 'visit' OR ReferenceType = 'visit_dispense_delete' OR ReferenceType = 'visit_delete') AND ReferenceID = ?");
        if ($stmt) {
            $stmt->bind_param('i', $visitID);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 4) Delete other possible references: best-effort
    $maybeTables = [
        'tbl_Transactions' => 'VisitID',
        'tbl_VisitNotes' => 'VisitID',
        'tbl_VisitPayments' => 'VisitID',
        'tbl_Appointments' => 'VisitID',
        'tbl_ProductReturns' => 'VisitID',
        'tbl_Visits' => 'id',
        'visits' => 'id'
    ];
    foreach ($maybeTables as $tbl => $col) {
        if (!table_exists($conn, $tbl)) continue;
        $tblEsc = $conn->real_escape_string($tbl);
        if ($col === 'id') {
            // try various id column names if 'id' is the generic column name
            $colsToTry = ['id','ID','VisitID','visit_id'];
            foreach ($colsToTry as $c) {
                if (!column_exists($conn, $tbl, $c)) continue;
                $cEsc = $conn->real_escape_string($c);
                $stmt = $conn->prepare("DELETE FROM `{$tblEsc}` WHERE `{$cEsc}` = ?");
                if (!$stmt) continue;
                $stmt->bind_param('i', $visitID);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            if (!column_exists($conn, $tbl, $col)) continue;
            $colEsc = $conn->real_escape_string($col);
            $stmt = $conn->prepare("DELETE FROM `{$tblEsc}` WHERE `{$colEsc}` = ?");
            if ($stmt) {
                $stmt->bind_param('i', $visitID);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // 5) Delete the visit row itself from likely visit table(s)
    $deletedVisitRows = 0;
    $candidateVisitTables = ['tbl_visits','tbl_Visits','visits','tbl_Visit','tbl_visit','Visit','visit'];
    foreach ($candidateVisitTables as $vt) {
        if (!table_exists($conn, $vt)) continue;
        $vtEsc = $conn->real_escape_string($vt);
        $colsToTry = ['VisitID','visit_id','id','ID'];
        foreach ($colsToTry as $col) {
            if (!column_exists($conn, $vt, $col)) continue;
            $colEsc = $conn->real_escape_string($col);
            $stmt = $conn->prepare("DELETE FROM `{$vtEsc}` WHERE `{$colEsc}` = ?");
            if (!$stmt) continue;
            $stmt->bind_param('i', $visitID);
            if ($stmt->execute()) $deletedVisitRows += $stmt->affected_rows;
            $stmt->close();
            if ($deletedVisitRows > 0) break;
        }
        if ($deletedVisitRows > 0) break;
    }

    $conn->commit();

    json_exit([
        'success' => true,
        'message' => 'Visit and related data deleted (best-effort).',
        'visit_id' => $visitID,
        'deleted_visit_rows' => $deletedVisitRows,
        'processed_visit_products' => count($visitProducts),
        'emp' => $empId,
        'force' => $force
    ], 200);

} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    json_exit(['success'=>false,'message'=>'Server error: '.$e->getMessage()],500);
}