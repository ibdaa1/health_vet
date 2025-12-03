<?php
session_start();
if (!isset($_SESSION['user']['EmpID'])) {
    header("Location: login.php");
    exit();
}
$user = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>إضافة دواء جديد</title>
<style>
body { font-family: "Tajawal", sans-serif; background: #f8f9fa; padding: 20px; }
.container { background: white; max-width: 750px; margin: auto; padding: 25px; border-radius: 15px; box-shadow: 0 0 12px rgba(0,0,0,0.1); }
select, input { width: 100%; padding: 10px; margin: 8px 0 18px; border: 1px solid #ccc; border-radius: 8px; }
button { background: #28a745; color: white; padding: 12px 18px; border: none; border-radius: 10px; cursor: pointer; }
button:hover { background: #218838; }
label { font-weight: bold; display: block; }
#langToggle { background: #007bff; margin-bottom: 20px; }
#langToggle:hover { background: #0069d9; }
</style>
</head>
<body>
<div class="container">
    <button id="langToggle">English</button>

    <h2 id="formTitle">💊 إضافة دواء جديد</h2>

    <form id="addMedicationForm">
        <label id="labelMainCategory" for="mainCategory">📋 القائمة الرئيسية:</label>
        <select id="mainCategory" required>
            <option value="">-- اختر القائمة الرئيسية --</option>
        </select>

        <label id="labelSubCategory" for="subCategory">📑 القائمة الفرعية:</label>
        <select id="subCategory" required disabled>
            <option value="">-- اختر القائمة الفرعية --</option>
        </select>

        <label id="labelUnit" for="unit">⚖️ الوحدة:</label>
        <select id="unit" required>
            <option value="">-- اختر الوحدة --</option>
        </select>

        <label id="labelMedCode" for="medCode">🔢 كود الدواء:</label>
        <input type="text" id="medCode" placeholder="أدخل الكود الفريد" required>

        <label id="labelQuantity" for="quantity">📊 الكمية:</label>
        <input type="number" id="quantity" value="0">

        <label id="labelMinQuantity" for="minQuantity">🔔 الحد الأدنى للتنبيه:</label>
        <input type="number" id="minQuantity" value="0">

        <label id="labelExpiryDate" for="expiryDate">⏳ تاريخ الانتهاء:</label>
        <input type="date" id="expiryDate">

        <label id="labelSupplier" for="supplier">🏢 المورد:</label>
        <input type="text" id="supplier">

        <button type="submit" id="saveBtn">💾 حفظ الدواء</button>
    </form>

    <p id="status"></p>
</div>
<script src="session_check.js"></script>
<script>
const apiBase = '/health_vet/api/';
let inventoryData = [];
let unitData = [];
let currentLang = 'ar';

// تحميل جميع القوائم
async function loadInventory(lang = 'ar') {
    try {
        const resInv = await fetch(`${apiBase}get_inventory_list.php?lang=${lang}`);
        const dataInv = await resInv.json();
        if (dataInv.success) {
            inventoryData = dataInv.data;
            renderMainCategories();
        }

        const resUnits = await fetch(`${apiBase}get_units.php?lang=${lang}`);
        const dataUnits = await resUnits.json();
        if (dataUnits.success) {
            unitData = dataUnits.data;
        }

    } catch (err) {
        console.error('Error loading data:', err);
    }
}

// عرض القوائم الرئيسية
function renderMainCategories() {
    const mainSelect = document.getElementById('mainCategory');
    mainSelect.innerHTML = '<option value="">-- اختر القائمة الرئيسية --</option>';
    const mainItems = inventoryData.filter(i => i.Type === 'Category');
    mainItems.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.ItemID;
        opt.textContent = item.Name;
        mainSelect.appendChild(opt);
    });

    const subSelect = document.getElementById('subCategory');
    subSelect.innerHTML = '<option value="">-- اختر القائمة الفرعية --</option>';
    subSelect.disabled = true;

    const unitSelect = document.getElementById('unit');
    unitSelect.innerHTML = '<option value="">-- اختر الوحدة --</option>';
}

// عند اختيار القائمة الرئيسية
document.getElementById('mainCategory').addEventListener('change', e => {
    const parentId = parseInt(e.target.value);
    const subSelect = document.getElementById('subCategory');
    subSelect.innerHTML = '<option value="">-- اختر القائمة الفرعية --</option>';

    if (parentId) {
        const subItems = inventoryData.filter(i => i.Type === 'SubCategory' && i.ParentID === parentId);
        if (subItems.length > 0) {
            subItems.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.ItemID;
                opt.textContent = item.Name;
                subSelect.appendChild(opt);
            });
            subSelect.disabled = false;
        } else {
            subSelect.disabled = true;
        }
    } else {
        subSelect.disabled = true;
    }

    // إعادة تعيين الوحدات
    const unitSelect = document.getElementById('unit');
    unitSelect.innerHTML = '<option value="">-- اختر الوحدة --</option>';
});

// عند اختيار القائمة الفرعية، جلب الوحدات المناسبة
document.getElementById('subCategory').addEventListener('change', e => {
    const subId = parseInt(e.target.value);
    const unitSelect = document.getElementById('unit');
    unitSelect.innerHTML = '<option value="">-- اختر الوحدة --</option>';

    // يمكن إضافة شرط لوحدات مرتبطة بـ SubCategory، حالياً نظهر كل الوحدات
    unitData.forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.UnitID;
        opt.textContent = u.Name;
        unitSelect.appendChild(opt);
    });
});

// زر تغيير اللغة
document.getElementById('langToggle').addEventListener('click', e => {
    currentLang = currentLang === 'ar' ? 'en' : 'ar';
    e.target.textContent = currentLang === 'ar' ? 'English' : 'العربية';
    updateFormLabels();
    loadInventory(currentLang);
});

// تحديث نصوص الحقول حسب اللغة
function updateFormLabels() {
    const labels = {
        ar: {
            formTitle: "💊 إضافة دواء جديد",
            labelMainCategory: "📋 القائمة الرئيسية:",
            labelSubCategory: "📑 القائمة الفرعية:",
            labelUnit: "⚖️ الوحدة:",
            labelMedCode: "🔢 كود الدواء:",
            labelQuantity: "📊 الكمية:",
            labelMinQuantity: "🔔 الحد الأدنى للتنبيه:",
            labelExpiryDate: "⏳ تاريخ الانتهاء:",
            labelSupplier: "🏢 المورد:",
            saveBtn: "💾 حفظ الدواء"
        },
        en: {
            formTitle: "💊 Add New Medication",
            labelMainCategory: "📋 Main Category:",
            labelSubCategory: "📑 Sub Category:",
            labelUnit: "⚖️ Unit:",
            labelMedCode: "🔢 Medication Code:",
            labelQuantity: "📊 Quantity:",
            labelMinQuantity: "🔔 Minimum Alert:",
            labelExpiryDate: "⏳ Expiry Date:",
            labelSupplier: "🏢 Supplier:",
            saveBtn: "💾 Save Medication"
        }
    };
    Object.keys(labels[currentLang]).forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = labels[currentLang][id];
    });
}

// حفظ الدواء
document.getElementById('addMedicationForm').addEventListener('submit', async e => {
    e.preventDefault();
    const payload = {
        InventoryItemID: document.getElementById('subCategory').value || document.getElementById('mainCategory').value,
        Product_Code: document.getElementById('medCode').value,
        UnitID: document.getElementById('unit').value, // حفظ UnitID
        Quantity: document.getElementById('quantity').value,
        MinQuantity: document.getElementById('minQuantity').value,
        ExpiryDate: document.getElementById('expiryDate').value,
        Supplier: document.getElementById('supplier').value
    };

    const res = await fetch(`${apiBase}add_medication.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    });

    const result = await res.json();
    document.getElementById('status').textContent = result.message || (result.success ? "تم الحفظ بنجاح" : "خطأ غير معروف");
});

// تحميل الصفحة
window.onload = () => loadInventory(currentLang);
</script>
</body>
</html>
