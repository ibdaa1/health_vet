<?php
// ضع هذا في أعلىowner_animals_report.php (أو أي تقرير .php)
require_once __DIR__ . '/../api/verify_report_token.php';
// دعم $payload من verify (بعض النسخ تضعه في $GLOBALS)
if (!isset($payload) && isset($GLOBALS['payload'])) $payload = $GLOBALS['payload'] ?? null;
if (empty($payload) || !is_array($payload)) {
    http_response_code(403);
    echo "<h2>عذراً، الرابط غير صالح أو انتهت صلاحيته.</h2>";
    exit;
}
$record_id = (int)($payload['record_id'] ?? 0);
$token = $_GET['token'] ?? $_POST['token'] ?? '';
// سجّل الاستخدام
if ($token) {
    if ($stmt = $conn->prepare("UPDATE report_tokens SET used_at = NOW() WHERE token = ?")) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اتفاقية تنازل عن حيوان</title>
    <style>
        :root {
            --main-green: #198754; /* أخضر غامق */
            --olive-tone: #495057; /* زيتوني غامق للنصوص والحدود */
            --light-beige: #f8ffef;
            --border-color: var(--olive-tone);
            --text-color: #343a40;
        }
    
        /* إعدادات الطباعة: إزالة البوردر وتعديل الترويسة والتذييل */
        @page {
            size: A4 portrait;
            margin: 0.2cm; /* تقليل الهوامش أكثر لملء الصفحة */
            /* ترقيم الصفحات بالعربية على اليمين */
            @bottom-right {
                content: "صفحة " counter(page) " من " counter(pages);
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                font-size: 8px;
                color: var(--olive-tone);
                padding-bottom: 1px;
            }
            /* ترقيم الصفحات بالإنجليزية على اليسار */
            @bottom-left {
                content: "Page " counter(page) " of " counter(pages);
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                font-size: 8px;
                color: var(--olive-tone);
                padding-bottom: 1px;
            }
        }
    
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: white;
            color: var(--text-color);
            font-size: 10px; /* زيادة حجم الخط الأساسي لملء الصفحة */
            line-height: 1.3; /* زيادة المسافة بين الأسطر قليلاً للقراءة */
            margin: 0;
            padding: 0;
        }
    
        .container {
            max-width: 19.6cm; /* زيادة العرض قليلاً لملء الصفحة */
            width: 100%;
            margin: 0 auto;
            background-color: white;
            padding: 0;
        }
    
        @media print {
            .container {
                border: none;
                padding: 0;
                width: 19.6cm;
            }
            .no-print { display: none !important; }
            .header, .report-body, .declaration, .signatures { page-break-inside: avoid; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media screen and (max-width: 768px) {
            body { font-size: 9px; }
            .container { padding: 5px; max-width: 100%; }
            .header { flex-direction: column; padding: 5px 0; }
            .logo { height: 35px; margin: 3px auto; }
            .header-title h1 { font-size: 14px; }
            .header-title p { font-size: 10px; }
            .report-body { grid-template-columns: 1fr; gap: 3px; }
            .column { border: none; padding: 5px; }
            .signatures { flex-direction: column; min-height: auto; }
            .signature-box, .registrar-info, .surrenderer-signature-box { flex: none; margin-bottom: 5px; width: 100%; }
            .declaration { padding: 5px; }
            table { font-size: 9px; }
            .print-button { width: 100%; margin-bottom: 5px; }
        }
        @media screen and (max-width: 480px) {
            body { font-size: 8px; }
            .header-title h1 { font-size: 12px; }
            .header-title p { font-size: 9px; }
            .logo { height: 25px; }
            .declaration p { font-size: 8.5px; }
            .declaration ul { font-size: 8px; padding-right: 8px; }
            table th, table td { padding: 1px; font-size: 8px; }
        }
    
        /* تحديث الـ Header: تقليل الفراغ */
        .header {
            text-align: center;
            border-bottom: 1px solid var(--main-green);
            padding: 0 0 3px 0; /* تقليل الـ padding */
            margin-bottom: 3px; /* تقليل الهامش */
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: white;
            position: relative;
        }
        .logo {
            height: 45px; /* زيادة حجم اللوغو قليلاً */
            width: auto;
            margin: 0 5px;
            object-fit: contain;
        }
        .header-title {
            flex-grow: 1;
            text-align: center;
            margin: 0;
            color: var(--main-green);
        }
        .header-title h1 { font-size: 16px; margin-bottom: 1px; font-weight: 700; } /* زيادة حجم العنوان */
        .header-title p { color: var(--olive-tone); font-size: 11px; margin: 0; } /* زيادة حجم الفقرة */
    
        /* تنسيق أقسام التقرير - تقليل الفراغ */
        .report-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px; /* تقليل الفجوة */
            margin-bottom: 4px; /* تقليل الهامش */
        }
        .column {
            padding: 4px; /* زيادة الـ padding قليلاً للتوازن */
            background-color: white;
            border: 1px solid var(--border-color);
            border-radius: 2px;
        }
        .arabic { direction: rtl; text-align: right; }
        .english { direction: ltr; text-align: left; }
    
        .section { margin-bottom: 2px; } /* تقليل الهامش */
        .section h2 {
            color: var(--main-green);
            font-size: 11px; /* زيادة حجم العنوان */
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 1px; /* تقليل الـ padding */
            margin-top: 0;
            margin-bottom: 2px; /* تقليل الهامش */
            font-weight: 600;
        }
        .label { font-weight: 600; color: var(--olive-tone); width: 35%; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 1px; background-color: white; } /* تقليل الهامش */
        th, td { border: 1px solid var(--border-color); padding: 2px; font-size: 10px; vertical-align: top; } /* زيادة حجم الخط */
        td:nth-child(2) { font-weight: 500; color: var(--text-color); }
        /* قسم الإقرار/الإخلاء - تقليل الفراغ */
        .declaration {
            background-color: var(--light-beige);
            border: 1px solid var(--main-green);
            padding: 4px; /* تقليل الـ padding */
            margin: 3px 0; /* تقليل الهامش */
            border-radius: 3px;
        }
        .declaration h3 {
            color: var(--main-green);
            text-align: center;
            border-bottom: 1px solid var(--main-green);
            padding-bottom: 1px;
            margin-bottom: 3px; /* تقليل الهامش */
            font-size: 12px; /* زيادة حجم العنوان */
        }
        .declaration p { font-size: 10px; line-height: 1.3; margin-bottom: 2px; text-align: justify; } /* زيادة حجم الخط */
        .declaration ul { font-size: 9px; line-height: 1.3; list-style-type: disc; padding-right: 8px; margin-top: 1px; margin-bottom: 1px; } /* تقليل الأحجام */
        .checkbox-group { display: flex; justify-content: space-around; margin: 2px 0; }
        .checkbox-item { display: flex; align-items: center; font-size: 9px; }
        .checkbox-item input[type="checkbox"] { margin-left: 3px; width: 10px; height: 10px; }
        .description-line, .reason-line {
            border-bottom: 1px solid var(--border-color);
            margin: 2px 0;
            padding-bottom: 1px;
            font-style: italic;
            font-size: 10px;
            line-height: 1.2;
            word-wrap: break-word;
            hyphens: auto;
            min-height: 20px; /* إضافة ارتفاع أدنى للسبب لملء المساحة */
        } /* زيادة حجم الخط وإضافة كسر الكلمات */
    
        /* قسم التوقيعات - تقليل الارتفاع */
        .signatures {
            display: flex;
            justify-content: space-around;
            align-items: stretch;
            margin-top: 8px; /* تقليل الهامش */
            padding-top: 3px; /* تقليل الـ padding */
            border-top: 1px solid var(--border-color);
            min-height: 70px; /* زيادة الارتفاع قليلاً لملء */
        }
        .signature-box, .registrar-info {
            flex: 0 0 45%;
            text-align: center;
            margin: 0 2px; /* تقليل الهامش */
            border: 1px solid var(--border-color);
            padding: 4px; /* تقليل الـ padding */
            border-radius: 2px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .registrar-info {
             text-align: right;
        }
        .surrenderer-signature-box {
            flex: 0 0 45%;
            text-align: center;
            margin: 0 2px;
            border: 1px solid var(--border-color);
            padding: 4px;
            border-radius: 2px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .signature-img {
            border: 1px dashed var(--olive-tone);
            max-width: 130px; /* زيادة الحجم قليلاً */
            max-height: 45px; /* زيادة الحجم قليلاً */
            width: auto;
            height: auto;
            margin-bottom: 2px;
            object-fit: contain;
            align-self: center;
        }
    
        .official-stamp {
            text-align: center;
            font-size: 9px; /* زيادة حجم الخط */
            color: var(--olive-tone);
            margin-top: 6px; /* تقليل الهامش */
            border-top: 1px dashed var(--border-color);
            padding-top: 2px; /* تقليل الـ padding */
        }
    
        .official-stamp .hidden-record { display: none; }
        /* أسلوب زر الطباعة */
        .print-button {
            background-color: var(--main-green);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px; /* زيادة حجم الخط */
            margin: 5px auto;
            display: block;
            width: fit-content;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .print-button:hover {
            background-color: #157347;
        }
    </style>
</head>
<body>
    <div class="container">
        <button class="print-button no-print" onclick="window.print()">طباعة الاتفاقية</button>
        <div class="header">
            <img src="/health_vet/public/dclogo.png" alt="DC Logo" class="logo">
        
            <div class="header-title">
                <h1>اتفاقية تنازل عن حيوان</h1>
                <p style="font-size: 11px; margin: 0; color: var(--main-green);">Animal Waiver Agreement</p>
                </div>
        
            <img src="/health_vet/public/shjmunlogo.png" alt="SHJMUN Logo" class="logo">
        </div>
        <div class="declaration" id="agreementSection" style="display: none;">
            <div class="report-body" style="grid-template-columns: 1fr 1fr; gap: 4px; margin-bottom: 0; border: none; padding: 0;">
                <div class="column arabic">
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green);">اتفاقية تنازل عن حيوان</p>
                    <p style="font-size: 10px; line-height: 1.3; margin-bottom: 2px; text-align: justify;">أقر أنا الموقع أدناه بأنني أتنازل طوعًا عن وصاية وملكية الحيوانات المذكورة أدناه إلى مأوى الشارقة للقطط والكلاب، دون أي وعد بالتسامح أو الإكراه.</p>
                    <p class="reason-line" id="reasonArabic">السبب: ...........................................................</p>
                    <p style="font-size: 10px; line-height: 1.3; margin: 3px 0 2px 0; text-align: justify;">في مقابل موافقة مأوى الشارقة للقطط والكلاب على استلام الحيوان/الحيوانات المذكورة أعلاه، أوافق بموجبه وأقر بشروط هذه الاتفاقية، وأتعهد وأؤكد وألتزم لمأوى الشارقة للقطط والكلاب بما يلي:</p>
                    <ul>
                        <li>أقر بأنني المالك الشرعي أو الوكيل المفوض قانونًا عن مالك الحيوان/الحيوانات المذكورة أعلاه.</li>
                        <li>أفهم أنه بمجرد تسليمي الحيوان/الحيوانات المذكورة أعلاه، لا يمكن إعادتها أو استردادها، وأقر بأن هذا التنازل نافذ ولا يجوز إلغاؤه أو تعديله كليًا أو جزئيًا.</li>
                        <li>من خلال تسليمي هذا الحيوان إلى مأوى الشارقة للقطط والكلاب، أقر وأوافق على أن المأوى يمتلك الحق القانوني الكامل والحصري في اتخاذ أي قرارات أو إجراءات تخص الحيوان/الحيوانات.</li>
                    </ul>
                </div>
                <div class="column english">
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green);">Animal Waiver Agreement</p>
                    <p style="font-size: 10px; line-height: 1.3; margin-bottom: 2px; text-align: justify;">I, the undersigned, hereby voluntarily release the custody and ownership of the animal(s) listed below to Sharjah Cats and Dogs Shelter, without any promise of leniency or coercion.</p>
                    <p class="reason-line" id="reasonEnglish">Reason: ……………………………………………………….</p>
                    <p style="font-size: 10px; line-height: 1.3; margin: 3px 0 2px 0; text-align: justify;">In consideration for the acceptance of the animal(s) described above by Sharjah Cats and Dogs Shelter, of which I hereby agree to and acknowledge the terms of this Agreement, further represent, warrants, and pledges to Sharjah Cats and Dogs Shelter the following:</p>
                    <ul>
                        <li>I attest that I am the lawful owner, or duly authorized agent for the owner, of the animal(s) listed above.</li>
                        <li>I understand that once I relinquish the animal(s), the animal(s) will not be available to be returned and further acknowledge that this waiver is effective and may not be revoked, altered, rescinded or voided in part or whole.</li>
                        <li>By relinquishing this animal to Sharjah Cats and Dogs Shelter, I acknowledge and agree that Sharjah Cats and Dogs Shelter shall have the sole and exclusive legal right to make any and all decisions, and to take any action, regarding the animal(s).</li>
                    </ul>
                </div>
            </div>
        </div>
        <div id="reportContent">
            </div>
        <div class="declaration" id="disclaimerSection" style="display: none;">
            <h3>إقرار إخلاء مسؤولية المتنازل / Surrenderer's Disclaimer Declaration</h3>
            <div class="report-body" style="grid-template-columns: 1fr 1fr; gap: 4px; margin-bottom: 0; border: none; padding: 0;">
                <div class="column arabic" style="border: 1px solid var(--border-color); padding: 4px;">
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green); margin-bottom: 2px;">أقرّ أنا الموقّع أدناه بأنني أتنازل طوعًا عن الحيوان لمأوى الشارقة للقطط والكلاب، وأتحمل كامل المسؤولية عن قراري هذا دون أي وعود أو التزامات من المأوى.</p>
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green); margin-bottom: 2px;">كما أُقرّ بأن المأوى وموظفيه ووكلاءه غير مسؤولين عن أي أضرار أو إصابات قد تنتج عن الحيوان بعد التنازل أو أثناء وجودي في المأوى، ولم يتم تقديم أي ضمانات بشأن حالته الصحية أو ملاءمته للتبني.</p>
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green);">وأتعهد بعدم تحميل المأوى أو موظفيه أي مسؤولية قانونية أو المطالبة بأي تعويض لأي سبب يتعلق بالحيوان المذكور.</p>
                </div>
                <div class="column english" style="border: 1px solid var(--border-color); padding: 4px;">
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green); margin-bottom: 2px;">I, the undersigned, hereby voluntarily surrender the animal to Sharjah Cats and Dogs Shelter and assume full responsibility for this decision without any promises or obligations from the Shelter.</p>
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green); margin-bottom: 2px;">I further acknowledge that the Shelter, its employees, and agents shall not be held liable for any damages, injuries, or losses arising from or caused by the animal after surrender or during my presence at the Shelter, and no warranties, express or implied, have been provided regarding the animal's health status or suitability for adoption.</p>
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green);">Moreover, I covenant and agree not to hold the Shelter or its employees legally responsible or to demand any compensation for any matter pertaining to the surrendered animal.</p>
                </div>
            </div>
        </div>
        <div class="signatures" id="signaturesSection" style="display: none;">
        
             <div class="surrenderer-signature-box">
                <p class="signature-label" style="font-size: 10px; color: var(--main-green); margin-top: 0;">توقيع المتنازل / Surrenderer's Signature</p>
                <div style="flex-grow: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                    <img id="surrendererSignatureImg" class="signature-img" alt="توقيع المتنازل" style="display: none;">
                    <div id="manualSurrendererSignatureLine" style="height: 35px; border-bottom: 1px dashed var(--olive-tone); width: 80%; margin-bottom: 2px; display: none;"></div>
                </div>
                <p class="signature-label" style="margin-bottom: 0; font-size: 10px;">اسم المتنازل ورقم الهاتف / Surrenderer's Name & Phone</p>
                <p id="surrendererSignatureDetails" style="font-weight: bold; font-size: 9px; color: var(--text-color); margin-top: 1px;"></p>
            </div>
        
            <div class="registrar-info">
                 <h3 style="font-weight: 600; color: var(--main-green); margin-bottom: 2px; border-bottom: 1px dashed var(--olive-tone); padding-bottom: 2px; font-size: 10px; text-align: center;">تفاصيل موظف التسجيل / Registrar's Details</h3>
                 <div>
                    <p style="margin: 0; font-size: 9px;"><span class="label">رقم الموظف:</span> <span id="employeeId" style="font-weight: bold; color: var(--text-color);"></span></p>
                    <p style="margin: 0; font-size: 9px;"><span class="label">اسم الموظف:</span> <span id="createdByName" style="font-weight: bold; color: var(--text-color);"></span></p>
                 </div>
                 <p style="margin: 2px 0 0 0; font-size: 8px; color: var(--olive-tone); text-align: center;"> (تم تسجيل الإخلاء من قبل الموظف المذكور - لا يتطلب توقيع)</p>
            </div>
        </div>
        <div class="official-stamp">
            <p>مستند إخلاء مسؤولية تنازل رسمي صادر عن مأوى الشارقة للقطط والكلاب</p>
            <p style="margin: 0; font-size: 8px;">للاستفسارات: أرضي 06-5453054 | هاتف 056-3299669 واتس آب 056-3299669 | مركز الاتصال 993 | بريد إلكتروني info@shjmun.gov.ae</p>
        </div>
    </div>
<script src="session_check.js"></script>
    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const animalId = <?php echo json_encode($record_id); ?>;
        const apiUrl = '../api/animals_crud.php';
        const employeesApiUrl = '../api/get_employees.php';
    
        if (animalId) {
        
            const surrendererSignatureDetails = document.getElementById('surrendererSignatureDetails');
            surrendererSignatureDetails.textContent = '... جاري التحميل ...';
            const formData = new FormData();
            formData.append('action', 'get');
            formData.append('id', animalId);
            // 1. جلب بيانات الحيوان
            const fetchAnimalData = fetch(apiUrl, { method: 'POST', credentials:'same-origin', body: formData })
                .then(response => response.json());
            // 2. جلب بيانات الموظفين
            const fetchEmployeesData = fetch(employeesApiUrl, { method: 'POST', credentials:'same-origin' })
                .then(response => response.json());
            // استخدام Promise.all لضمان جلب البيانات قبل المعالجة
            Promise.all([fetchAnimalData, fetchEmployeesData])
                .then(([animalResult, employeesResult]) => {
                
                    if (!animalResult.success || !animalResult.animal || animalResult.animal.animal_source !== 'Surrended') {
                        document.getElementById('reportContent').innerHTML = `<p style="color: var(--main-green); padding: 10px;">خطأ: الحيوان غير موجود أو ليس من نوع 'Surrended'.</p>`;
                        return;
                    }
                
                    const data = animalResult.animal;
                    const employees = (employeesResult.success && Array.isArray(employeesResult.data)) ? employeesResult.data : [];
                
                    // البحث عن اسم الموظف
                    const createdByEmployeeID = data.updated_by || data.created_by; // استخدام updated_by أو created_by
                    let employeeName = 'غير محدد';
                    if (createdByEmployeeID) {
                        const employee = employees.find(emp => emp.EmpID == createdByEmployeeID);
                        employeeName = employee ? employee.EmpName : 'غير محدد';
                    }
                
                    // السبب (افتراضيًا من notes أو 'غير محدد')
                    const reasonAr = data.notes || 'غير محدد';
                    const reasonEn = data.notes || 'Not Specified';
                
                    // تحديث عناصر الاتفاقية
                    document.getElementById('reasonArabic').innerHTML = `السبب: ${reasonAr}`;
                    document.getElementById('reasonEnglish').innerHTML = `Reason: ${reasonEn}`;
                
                    // بناء محتوى التقرير
                    document.getElementById('reportContent').innerHTML = `
                        <div class="report-body">
                            <div class="column arabic">
                                <div class="section">
                                    <h2 style="color: var(--main-green);">بيانات الحيوان</h2>
                                    <table>
                                        <tr><td class="label">الرمز:</td><td>${data.animal_code || 'غير محدد'}</td></tr>
                                        <tr><td class="label">الاسم:</td><td>${data.animal_name || 'غير محدد'}</td></tr>
                                        <tr><td class="label">النوع:</td><td>${data.animal_type || 'غير محدد'}</td></tr>
                                        <tr><td class="label">السلالة:</td><td>${data.breed || 'غير محدد'}</td></tr>
                                        <tr><td class="label">اللون:</td><td>${data.color || 'غير محدد'}</td></tr>
                                        <tr><td class="label">الجنس:</td><td>${data.gender || 'غير محدد'}</td></tr>
                                        <tr><td class="label">تاريخ التسجيل:</td><td>${data.registration_date || 'غير محدد'}</td></tr>
                                    </table>
                                </div>
                                <div class="section">
                                    <h2 style="color: var(--main-green);">بيانات المتنازل</h2>
                                    <table>
                                        <tr><td class="label">الاسم:</td><td>${data.owner_name || 'غير محدد'}</td></tr>
                                        <tr><td class="label">رقم الهوية:</td><td>${data.owner_national_id || 'غير محدد'}</td></tr>
                                        <tr><td class="label">الهاتف:</td><td>${data.owner_phone || 'غير محدد'}</td></tr>
                                        <tr><td class="label">البريد:</td><td>${data.owner_email || 'غير محدد'}</td></tr>
                                    </table>
                                </div>
                            </div>
                            <div class="column english">
                                   <div class="section">
                                        <h2 style="color: var(--main-green);">Animal Details</h2>
                                        <table>
                                            <tr><td class="label">Code:</td><td>${data.animal_code || 'N/A'}</td></tr>
                                            <tr><td class="label">Name:</td><td>${data.animal_name || 'N/A'}</td></tr>
                                            <tr><td class="label">Type:</td><td>${data.animal_type || 'N/A'}</td></tr>
                                            <tr><td class="label">Breed:</td><td>${data.breed || 'N/A'}</td></tr>
                                            <tr><td class="label">Color:</td><td>${data.color || 'N/A'}</td></tr>
                                            <tr><td class="label">Gender:</td><td>${data.gender || 'N/A'}</td></tr>
                                            <tr><td class="label">Registration Date:</td><td>${data.registration_date || 'N/A'}</td></tr>
                                        </table>
                                    </div>
                                    <div class="section">
                                        <h2 style="color: var(--main-green);">Surrenderer Details</h2>
                                        <table>
                                            <tr><td class="label">Name:</td><td>${data.owner_name || 'N/A'}</td></tr>
                                            <tr><td class="label">National ID:</td><td>${data.owner_national_id || 'N/A'}</td></tr>
                                            <tr><td class="label">Phone:</td><td>${data.owner_phone || 'N/A'}</td></tr>
                                            <tr><td class="label">Email:</td><td>${data.owner_email || 'N/A'}</td></tr>
                                        </table>
                                    </div>
                            </div>
                        </div>
                    `;
                
                    document.getElementById('agreementSection').style.display = 'block';
                    document.getElementById('disclaimerSection').style.display = 'block';
                    document.getElementById('signaturesSection').style.display = 'flex';
                
                    const surrendererSignatureImg = document.getElementById('surrendererSignatureImg');
                    const manualSurrendererSignatureLine = document.getElementById('manualSurrendererSignatureLine');
                    const createdByNameElement = document.getElementById('createdByName');
                    const employeeIdElement = document.getElementById('employeeId');
                
                    // تحديث بيانات التوقيعات/الموظف
                    surrendererSignatureDetails.textContent = `${data.owner_name || 'غير محدد'} - ${data.owner_phone || 'غير محدد'}`;
                
                    if (data.owner_signature) {
                        surrendererSignatureImg.src = `../${data.owner_signature}`;
                        surrendererSignatureImg.style.display = 'inline-block';
                        manualSurrendererSignatureLine.style.display = 'none';
                    } else {
                        surrendererSignatureImg.style.display = 'none';
                        manualSurrendererSignatureLine.style.display = 'block';
                    }
                    // تحديث بيانات الموظف المُسجل
                    employeeIdElement.textContent = createdByEmployeeID || 'غير محدد';
                    createdByNameElement.textContent = employeeName;
                
                    setTimeout(() => {
                        window.print();
                    }, 700);
                })
                .catch(error => {
                    console.error('خطأ في التقرير:', error);
                    document.getElementById('reportContent').innerHTML = '<p style="color: var(--main-green); padding: 10px;">خطأ في جلب البيانات من الخادم.</p>';
                });
        } else {
            document.getElementById('reportContent').innerHTML = '<p style="color: var(--main-green); padding: 10px;">معرف الحيوان مفقود.</p>';
        }
    </script>
</body>
</html>