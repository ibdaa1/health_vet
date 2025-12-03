/* ---------- PATCH: resolve_or_create_option + attribute processing ---------- */
/**
 * Resolve OptionID from text or create a new option.
 * Returns integer OptionID (>0) or 0 on failure.
 */
function resolve_or_create_option($conn, $AttributeID, $valueText) {
    $valueText = trim($valueText);
    if ($valueText === '') return 0;
    $v = $conn->real_escape_string($valueText);
    $sql = "SELECT OptionID FROM ProductAttributeOptions
            WHERE LOWER(TRIM(Name_EN)) = LOWER(TRIM('$v'))
               OR LOWER(TRIM(Name_AR)) = LOWER(TRIM('$v'))
               OR LOWER(TRIM(Value)) = LOWER(TRIM('$v'))
            LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $row = $res->fetch_assoc()) return intval($row['OptionID']);
    // create new option (Name_EN default = provided text)
    $ins = "INSERT INTO ProductAttributeOptions (AttributeID, Name_AR, Name_EN, Value, CreatedAt, UpdatedAt)
            VALUES (" . intval($AttributeID) . ", '', '" . $conn->real_escape_string($valueText) . "', '" . $conn->real_escape_string($valueText) . "', NOW(), NOW())";
    if ($conn->query($ins)) return intval($conn->insert_id);
    return 0;
}

/* Use this processing code to insert attributes (replace existing loops) */

// $ProductID must be set (for update use existing $ProductID, for create use the newly inserted $ProductID)

// Remove existing attribute rows for this product (update path already did this — keep as is or ensure it runs)
$delAttr = $conn->prepare("DELETE FROM ProductAttributeValues WHERE ProductID = ?");
$delAttr->bind_param('i', $ProductID);
$delAttr->execute();
$delAttr->close();

// Insert attributes (handle OptionID resolution/creation)
if (!empty($attributes) && is_array($attributes)) {
    $insAttr = $conn->prepare("INSERT INTO ProductAttributeValues (ProductID, AttributeID, OptionID, Value, Quantity, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
    foreach ($attributes as $attr) {
        $attrID = isset($attr['AttributeID']) ? (int)$attr['AttributeID'] : 0;
        if (!$attrID) continue;
        $optID = isset($attr['OptionID']) && $attr['OptionID'] !== '' ? (int)$attr['OptionID'] : 0;
        $val = isset($attr['Value']) ? trim($attr['Value']) : '';
        $avalQty = isset($attr['Quantity']) ? (int)$attr['Quantity'] : 0;

        // If OptionID not provided or invalid, try resolve/create
        if (!$optID && $val !== '') {
            $optID = resolve_or_create_option($conn, $attrID, $val);
        }

        // store OptionID and set Value as string of OptionID per requirement
        $valueToStore = $optID ? strval($optID) : $conn->real_escape_string($val);
        // bind: ProductID, AttributeID, OptionID (can be null), Value, Quantity
        if ($optID) {
            $insAttr->bind_param('iiisi', $ProductID, $attrID, $optID, $valueToStore, $avalQty);
        } else {
            // no OptionID resolved -> store OptionID as NULL and Value as original text
            $nullOpt = null;
            // need to pass NULL for integer param; mysqli bind_param doesn't accept null for i - workaround: use string binding
            $insAttr->close();
            $stmtFallback = $conn->prepare("INSERT INTO ProductAttributeValues (ProductID, AttributeID, OptionID, Value, Quantity, CreatedAt, UpdatedAt) VALUES (?, ?, NULL, ?, ?, NOW(), NOW())");
            $stmtFallback->bind_param('iisi', $ProductID, $attrID, $valueToStore, $avalQty);
            $stmtFallback->execute();
            $stmtFallback->close();
            // re-prepare $insAttr for next loop iteration
            $insAttr = $conn->prepare("INSERT INTO ProductAttributeValues (ProductID, AttributeID, OptionID, Value, Quantity, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            continue;
        }
        if (!$insAttr->execute()) {
            // if execution failed for some reason, try fallback single insert to avoid losing data
            $stmtFb = $conn->prepare("INSERT INTO ProductAttributeValues (ProductID, AttributeID, OptionID, Value, Quantity, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            $stmtFb->bind_param('iiisi', $ProductID, $attrID, $optID, $valueToStore, $avalQty);
            $stmtFb->execute();
            $stmtFb->close();
        }
    }
    $insAttr->close();
}