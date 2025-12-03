<?php
// ضع هذا في أعلى add_adopter_report.php (أو أي تقرير .php)
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
    <title>اقرار إخلاء مسؤولية</title>
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
            margin: 0.5cm;
            /* ترقيم الصفحات بالعربية على اليمين */
            @bottom-right {
                content: "صفحة " counter(page) " من " counter(pages);
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                font-size: 8px;
                color: var(--olive-tone);
                padding-bottom: 3px;
            }
            /* ترقيم الصفحات بالإنجليزية على اليسار */
            @bottom-left {
                content: "Page " counter(page) " of " counter(pages);
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                font-size: 8px;
                color: var(--olive-tone);
                padding-bottom: 3px;
            }
        }
    
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: white;
            color: var(--text-color);
            font-size: 10px;
            line-height: 1.3;
            margin: 0;
            padding: 0;
        }
    
        .container {
            width: 19.5cm;
            margin: 0 auto;
            background-color: white;
            padding: 0;
        }
    
        @media print {
            .container {
                border: none;
                padding: 0;
            }
            .no-print { display: none !important; }
        }
        /* تحديث الـ Header: تقليل الفراغ */
        .header {
            text-align: center;
            border-bottom: 2px solid var(--main-green);
            padding: 0 0 10px 0;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: white;
            position: relative;
        }
        .logo {
            height: 50px;
            width: auto;
            margin: 0 10px;
            object-fit: contain;
        }
        .header-title {
            flex-grow: 1;
            text-align: center;
            margin: 0;
            color: var(--main-green);
        }
        .header-title h1 { font-size: 16px; margin-bottom: 2px; font-weight: 700; }
        .header-title p { color: var(--olive-tone); font-size: 12px; margin: 0; }
        .print-button {
            position: absolute;
            top: -10px;
            right: 10px;
        }
        .print-button button {
            background-color: var(--main-green);
            color: white;
            border: none;
            padding: 6px 12px;
            font-size: 11px;
            border-radius: 4px;
            cursor: pointer;
        }
        .print-button button:hover {
            background-color: #157347;
        }
    
        /* تنسيق أقسام التقرير - تقليل الفراغ */
        .report-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        .column {
            padding: 5px;
            background-color: white;
            border: 1px solid var(--border-color);
            border-radius: 3px;
        }
        .arabic { direction: rtl; text-align: right; }
        .english { direction: ltr; text-align: left; }
    
        .section { margin-bottom: 8px; }
        .section h2 {
            color: var(--main-green);
            font-size: 12px;
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 3px;
            margin-top: 0;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .label { font-weight: 600; color: var(--olive-tone); width: 35%; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 3px; background-color: white; }
        th, td { border: 1px solid var(--border-color); padding: 3px; font-size: 10px; vertical-align: top; }
        td:nth-child(2) { font-weight: 500; color: var(--text-color); }
        /* قسم الإقرار/الإخلاء - تقليل الفراغ */
        .declaration {
            background-color: var(--light-beige);
            border: 1px solid var(--main-green);
            padding: 8px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .declaration h3 {
            color: var(--main-green);
            text-align: center;
            border-bottom: 1px solid var(--main-green);
            padding-bottom: 3px;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .declaration p { font-size: 9.5px; line-height: 1.3; margin-bottom: 5px; }
    
        /* قسم التوقيعات - تقليل الارتفاع */
        .signatures {
            display: flex;
            justify-content: space-around;
            align-items: stretch;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color);
            min-height: 100px;
        }
        .signature-box, .registrar-info {
            flex: 0 0 45%;
            text-align: center;
            margin: 0 5px;
            border: 1px solid var(--border-color);
            padding: 8px;
            border-radius: 3px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .registrar-info {
             text-align: right;
        }
        .signature-img {
            border: 1px dashed var(--olive-tone);
            max-width: 140px;
            max-height: 50px;
            width: auto;
            height: auto;
            margin: 0 auto 5px auto;
            object-fit: contain;
            display: block;
        }
    
        .official-stamp {
            text-align: center;
            font-size: 9px;
            color: var(--olive-tone);
            margin-top: 15px;
            border-top: 1px dashed var(--border-color);
            padding-top: 5px;
        }
    
        .official-stamp .hidden-record { display: none; }
    
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .header, .report-body, .declaration, .signatures { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="/health_vet/public/dclogo.png" alt="DC Logo" class="logo">
        
            <div class="header-title">
                <h1>مستند إخلاء مسؤولية وتبني حيوان</h1>
                <p style="font-size: 12px; margin: 0; color: var(--main-green);">Animal Adoption and Disclaimer Document</p>
                </div>
        
            <img src="/health_vet/public/shjmunlogo.png" alt="SHJMUN Logo" class="logo">
            <div class="print-button no-print">
                <button onclick="window.print()">طباعة النموذج / Print Form</button>
            </div>
        </div>
        <div id="reportContent">
            </div>
        <div class="declaration" id="declarationSection" style="display: none;">
            <h3>إقرار إخلاء مسؤولية المتبني / Adopter's Disclaimer Declaration</h3>
            <div class="report-body" style="grid-template-columns: 1fr 1fr; gap: 5px; margin-bottom: 0; border: none; padding: 0;">
                <div class="column arabic" style="border: 1px solid var(--border-color); padding: 5px;">
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green);">أقرّ بأنني اطّلعت على التعليمات والإرشادات المقدمة من قبل موظفي مأوى الشارقة للقطط والكلاب بشأن مبادئ الرعاية العامة، وأتعهد بالالتزام بها. كما أتحمل كامل المسؤولية عن التطعيمات والرعاية الصحية والبيطرية للحيوان بعد مغادرته المأوى، وألتزم بتوفير المأوى والغذاء والعناية المناسبة له بطريقة إنسانية.</p>
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green);">وأقرّ بعدم بيع أو التنازل عن الحيوان أو التخلص منه لأي جهة، وأعفي مأوى الشارقة للقطط والكلاب من أي مسؤولية مستقبلية تتعلق بسلوك الحيوان أو حالته الصحية أو أي أضرار قد تنشأ بعد التبني</p>
                </div>
                <div class="column english" style="border: 1px solid var(--border-color); padding: 5px;">
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green);">I hereby acknowledge that I have reviewed and understood the guidelines and instructions provided by the staff of the Sharjah Cat and Dog Shelter regarding general animal care principles, and I pledge to comply with them.</p>
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green);">I accept full responsibility for all vaccinations, health care, and veterinary follow-up required for the animal after it leaves the shelter, and I commit to providing proper shelter, food, and humane care.</p>
                    <p style="font-weight: bold; font-size: 10px; margin-top: 0; color: var(--main-green);">Furthermore, I undertake not to sell, transfer, or abandon the adopted animal to any person or entity, and I release the Sharjah Cat and Dog Shelter from any future liability related to the animal’s behavior, health condition, or any damages that may arise after adoption.</p>
                </div>
            </div>
        </div>
        <div class="signatures" id="signaturesSection" style="display: none;">
        
             <div class="registrar-info">
                 <h3 style="font-weight: 600; color: var(--main-green); margin-bottom: 5px; border-bottom: 1px dashed var(--olive-tone); padding-bottom: 3px; font-size: 11px; text-align: center;">تفاصيل موظف التسجيل / Registrar's Details</h3>
                 <div>
                    <p style="margin: 0; font-size: 10px;"><span class="label">اسم الموظف:</span> <span id="createdByName" style="font-weight: bold; color: var(--text-color);"></span></p>
                 </div>
                 <p style="margin: 5px 0 0 0; font-size: 9px; color: var(--olive-tone); text-align: center;"> (تم تسجيل الإخلاء من قبل الموظف المذكور - لا يتطلب توقيع)</p>
            </div>
        
            <div class="signature-box">
                <p class="signature-label" style="font-size: 11px; color: var(--main-green); margin-top: 0;">توقيع المتبني / Adopter's Signature</p>
                <div style="flex-grow: 1; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                    <img id="adopterSignatureImg" class="signature-img" alt="توقيع المتبني" style="display: none;">
                    <div id="manualSignatureLine" style="height: 40px; border-bottom: 1px dashed var(--olive-tone); width: 80%; margin-bottom: 3px; display: none;"></div>
                </div>
                <p class="signature-label" style="margin-bottom: 0;">اسم المتبني / Adopter's Name</p>
                <p id="adopterSignatureName" style="font-weight: bold; font-size: 10px; color: var(--text-color); margin-top: 1px;"></p>
            </div>
        </div>
        <div class="official-stamp">
            <p>مستند إخلاء مسؤولية رسمي صادر عن مأوى الشارقة للقطط والكلاب</p>
            <p style="margin: 0;">للاستفسارات: أرضي 06-5453054 / هاتف 056-3299669 / مركز الاتصال 993 | بريد إلكتروني info@shjmun.gov.ae</p>
        </div>
    </div>
<script src="session_check.js"></script>
    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const adoptionId = <?php echo json_encode($record_id); ?>;
        const apiUrl = '/health_vet/api/add_adopter.php';
    
        if (adoptionId) {
        
            const adopterSignatureName = document.getElementById('adopterSignatureName');
            adopterSignatureName.textContent = '... جاري التحميل ...';
            const formData = new FormData();
            formData.append('action', 'get_adoption');
            formData.append('adoption_id', adoptionId);
            fetch(apiUrl, { method: 'POST', credentials:'same-origin', body: formData })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        const data = result.adoption;
                    
                        // بناء الصفوف الديناميكية للجداول بناءً على وجود البيانات
                        const adoptionArabicRows = `
                            <tr><td class="label">تاريخ التبني:</td><td>${data.adoption_date || 'غير محدد'}</td></tr>
                            ${data.description ? `<tr><td class="label">الوصف:</td><td>${data.description}</td></tr>` : ''}
                            ${data.notes ? `<tr><td class="label">الملاحظات:</td><td>${data.notes}</td></tr>` : ''}
                        `;
                        
                        const adoptionEnglishRows = `
                            <tr><td class="label">Adoption Date:</td><td>${data.adoption_date || 'N/A'}</td></tr>
                            ${data.description ? `<tr><td class="label">Description:</td><td>${data.description}</td></tr>` : ''}
                            ${data.notes ? `<tr><td class="label">Notes:</td><td>${data.notes}</td></tr>` : ''}
                        `;
                        
                        const adopterArabicRows = `
                            <tr><td class="label">الاسم:</td><td>${data.adopter_name || 'غير محدد'}</td></tr>
                            <tr><td class="label">رقم الهوية:</td><td>${data.adopter_national_id || 'غير محدد'}</td></tr>
                            <tr><td class="label">الهاتف:</td><td>${data.adopter_phone || 'غير محدد'}</td></tr>
                            ${data.adopter_email ? `<tr><td class="label">البريد:</td><td>${data.adopter_email}</td></tr>` : ''}
                        `;
                        
                        const adopterEnglishRows = `
                            <tr><td class="label">Name:</td><td>${data.adopter_name || 'N/A'}</td></tr>
                            <tr><td class="label">National ID:</td><td>${data.adopter_national_id || 'N/A'}</td></tr>
                            <tr><td class="label">Phone:</td><td>${data.adopter_phone || 'N/A'}</td></tr>
                            ${data.adopter_email ? `<tr><td class="label">Email:</td><td>${data.adopter_email}</td></tr>` : ''}
                        `;
                        
                        const animalArabicRows = `
                            <tr><td class="label">الرمز:</td><td>${data.animal_code || 'غير محدد'}</td></tr>
                            ${data.animal_name ? `<tr><td class="label">الاسم:</td><td>${data.animal_name}</td></tr>` : ''}
                            ${data.animal_type ? `<tr><td class="label">النوع:</td><td>${data.animal_type}</td></tr>` : ''}
                            ${data.breed ? `<tr><td class="label">السلالة:</td><td>${data.breed}</td></tr>` : ''}
                            ${data.color ? `<tr><td class="label">اللون:</td><td>${data.color}</td></tr>` : ''}
                            ${data.gender ? `<tr><td class="label">الجنس:</td><td>${data.gender}</td></tr>` : ''}
                        `;
                        
                        const animalEnglishRows = `
                            <tr><td class="label">Code:</td><td>${data.animal_code || 'N/A'}</td></tr>
                            ${data.animal_name ? `<tr><td class="label">Name:</td><td>${data.animal_name}</td></tr>` : ''}
                            ${data.animal_type ? `<tr><td class="label">Type:</td><td>${data.animal_type}</td></tr>` : ''}
                            ${data.breed ? `<tr><td class="label">Breed:</td><td>${data.breed}</td></tr>` : ''}
                            ${data.color ? `<tr><td class="label">Color:</td><td>${data.color}</td></tr>` : ''}
                            ${data.gender ? `<tr><td class="label">Gender:</td><td>${data.gender}</td></tr>` : ''}
                        `;
                        
                        // بناء محتوى التقرير بالترتيب المطلوب
                        document.getElementById('reportContent').innerHTML = `
                            <div class="report-body">
                                <div class="column arabic">
                                    <div class="section">
                                        <h2 style="color: var(--main-green);">تفاصيل التبني</h2>
                                        <table>
                                            ${adoptionArabicRows}
                                        </table>
                                    </div>
                                    <div class="section">
                                        <h2 style="color: var(--main-green);">بيانات المتبني</h2>
                                        <table>
                                            ${adopterArabicRows}
                                        </table>
                                    </div>
                                    <div class="section">
                                        <h2 style="color: var(--main-green);">بيانات الحيوان (Animal Details)</h2>
                                        <table>
                                            ${animalArabicRows}
                                        </table>
                                    </div>
                                </div>
                                <div class="column english">
                                     <div class="section">
                                        <h2 style="color: var(--main-green);">Adoption Details</h2>
                                        <table>
                                            ${adoptionEnglishRows}
                                        </table>
                                    </div>
                                    <div class="section">
                                        <h2 style="color: var(--main-green);">Adopter Details</h2>
                                        <table>
                                            ${adopterEnglishRows}
                                        </table>
                                    </div>
                                    <div class="section">
                                        <h2 style="color: var(--main-green);">Animal Details</h2>
                                        <table>
                                            ${animalEnglishRows}
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                    
                        document.getElementById('declarationSection').style.display = 'block';
                        document.getElementById('signaturesSection').style.display = 'flex';
                    
                        const adopterSignatureImg = document.getElementById('adopterSignatureImg');
                        const manualSignatureLine = document.getElementById('manualSignatureLine');
                        const createdByName = document.getElementById('createdByName');
                    
                        // تحديث بيانات التوقيعات/الموظف
                        createdByName.textContent = data.created_by_name || 'غير محدد';
                        adopterSignatureName.textContent = data.adopter_name || 'غير محدد';
                    
                        if (data.adopter_signature) {
                            adopterSignatureImg.src = `../${data.adopter_signature}`;
                            adopterSignatureImg.style.display = 'block';
                            manualSignatureLine.style.display = 'none';
                        } else {
                            adopterSignatureImg.style.display = 'none';
                            manualSignatureLine.style.display = 'block';
                        }
                    
                        setTimeout(() => {
                            window.print();
                        }, 700);
                    } else {
                        document.getElementById('reportContent').innerHTML = `<p style="color: var(--main-green); padding: 20px;">خطأ في جلب البيانات: ${result.message}</p>`;
                    }
                })
                .catch(error => {
                    console.error('خطأ في التقرير:', error);
                    document.getElementById('reportContent').innerHTML = '<p style="color: var(--main-green); padding: 20px;">خطأ في جلب البيانات من الخادم.</p>';
                });
        } else {
            document.getElementById('reportContent').innerHTML = '<p style="color: var(--main-green); padding: 20px;">معرف التبني مفقود.</p>';
        }
    </script>
</body>
</html>