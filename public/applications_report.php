<?php
// ضع هذا في أعلى add_reserved_report.php (أو أي تقرير .php)
require_once __DIR__ . '/../api/verify_report_token.php';
// دعم $payload من verify (بعض النسخ تضعه في $GLOBALS)
if (!isset($payload) && isset($GLOBALS['payload'])) $payload = $GLOBALS['payload'] ?? null;
if (empty($payload) || !is_array($payload)) {
    http_response_code(403);
    echo "<h2>عذراً، الرابط غير صالح أو انتهت صلاحيته.</h2>";
    exit;
}
$record_id = (int)($payload['record_id'] ?? 0);
if ($record_id === 0) {
    http_response_code(403);
    echo "<h2>عذراً، رقم السجل غير صالح.</h2>";
    exit;
}
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
    <title>استبيان طلب التبني - Adoption Application Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <style>
        /* تنسيقات للطباعة في صفحة واحدة A4 بخط صغير، ثنائي اللغة */
        :root {
            --primary-color: #384F30;
            --secondary-color: #AA9556;
            --light-tone: #f8ffef;
            --border-color: #dee2e6;
            --text-color: #343a40;
        }
        @page { size: A4 portrait; margin: 0.5cm; }
        body {
            font-family: 'Arial', sans-serif;
            font-size: 8px; /* خط صغير للتناسب في صفحة واحدة */
            line-height: 1.2;
            color: var(--text-color);
            margin: 0; padding: 0;
            background-color: white;
        }
        .report-container {
            width: 19.5cm;
            margin: 0 auto;
            padding: 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 4px;
            margin-bottom: 8px;
        }
        .logo { height: 35px; width: auto; object-fit: contain; }
        .header-title {
            flex-grow: 1;
            text-align: center;
            color: var(--primary-color);
        }
        .header-title h1 {
            font-size: 12px;
            margin-bottom: 1px;
            font-weight: 700;
            text-align: center;
        }
        .header-title p {
            color: var(--secondary-color);
            font-size: 7px;
            margin: 0;
            text-align: center;
        }
        .header-title .h6 {
            font-size: 8px;
            margin-top: 2px !important;
            text-align: center;
        }
        .section-title {
            background-color: var(--primary-color);
            color: white;
            font-size: 9px;
            font-weight: bold;
            padding: 2px 6px;
            margin-top: 6px;
            border-radius: 2px 2px 0 0;
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }
        .section-title:before,
        .section-title:after {
            content: "";
            flex: 1;
            height: 1px;
            background: white;
            margin: 0 5px;
        }
        .content-row {
            padding: 0;
            border-bottom: 1px dashed #eee;
            margin-bottom: 2px;
            display: flex;
            align-items: stretch;
            min-height: 20px;
        }
        .col-ar {
            flex: 1;
            border: 1px solid var(--border-color);
            border-left: none; /* تم التعديل: جعل الحد يبدأ من اليمين (إظهار الحد الأيمن، وإخفاء الحد الداخلي الأيسر) */
            padding: 2px 4px;
            text-align: center;
            direction: rtl;
            unicode-bidi: plaintext;
            font-weight: bold;
            font-size: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .col-value {
            flex: 1;
            border: 1px solid var(--border-color);
            border-left: none; /* تم التعديل: تماشيًا مع التباعد الأيمن والحدود للغة العربية */
            padding: 2px 4px;
            text-align: center;
            direction: ltr;
            font-size: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--light-tone);
        }
        .col-en {
            flex: 1;
            border: 1px solid var(--border-color);
            padding: 2px 4px;
            text-align: center;
            direction: ltr;
            unicode-bidi: plaintext;
            font-weight: bold;
            font-size: 7px;
            color: var(--secondary-color);
            font-style: italic;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .checkbox-options {
            display: flex;
            gap: 10px;
            font-size: 7px;
            direction: ltr;
        }
        .checkbox-options span {
            min-width: 35px;
            text-align: center;
            padding: 1px 3px;
            border-radius: 2px;
            background: white;
            border: 1px solid var(--border-color);
        }
        .select-options {
            display: flex;
            gap: 5px;
            font-size: 7px;
            direction: ltr;
        }
        .select-options span {
            min-width: 40px;
            text-align: center;
            padding: 1px 2px;
            border-radius: 2px;
            background: white;
            border: 1px solid var(--border-color);
        }
        .textarea-field {
            min-height: 20px;
            border: 1px dotted #ccc;
            padding: 2px;
            font-size: 8px;
            background: var(--light-tone);
            display: flex;
            flex-direction: column;
            justify-content: center;
            width: 100%;
        }
        .textarea-field .value-ar {
            text-align: center;
            direction: rtl;
            unicode-bidi: plaintext;
            font-weight: bold;
            color: var(--primary-color);
        }
        .textarea-field .value-en {
            text-align: center;
            direction: ltr;
            unicode-bidi: plaintext;
            color: var(--secondary-color);
            font-style: italic;
        }
        .declaration-ar, .declaration-en {
            border: 1px solid var(--secondary-color);
            padding: 6px;
            margin-top: 8px;
            background-color: #fffaf0;
            font-size: 7.5px;
            line-height: 1.3;
            min-height: 85px; /* لضمان الطول نفسه */
            display: flex;
            flex-direction: column;
        }
        .declaration-ar ul {
            list-style-type: disc;
            padding-right: 12px;
            margin-top: 3px;
            direction: rtl;
            flex-grow: 1;
        }
        .declaration-ar li {
            margin-bottom: 2px;
            direction: rtl;
        }
        .declaration-en ul {
            list-style-type: disc;
            padding-left: 12px;
            margin-top: 3px;
            direction: ltr;
            flex-grow: 1;
        }
        .declaration-en li {
            margin-bottom: 2px;
            direction: ltr;
        }
        .signature-block {
            padding: 4px;
            margin-top: 8px;
            border: 1px solid var(--border-color);
            min-height: 70px;
            border-radius: 2px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .signature-block p {
            margin-bottom: 1px;
            font-size: 8px;
        }
        .signature-line {
            height: 35px;
            margin: 2px 0;
            display: flex;
            justify-content: center;
            align-items: center;
            border-bottom: 1px dashed #ccc;
            font-size: 7px;
        }
        .signature-line img {
            max-height: 100%;
            max-width: 120px;
            object-fit: contain;
        }
        .footer-info {
            font-size: 7px;
            margin-top: 6px;
            padding-top: 3px;
            text-align: center;
            border-top: 1px dashed #eee;
        }
        .contact-footer {
            font-size: 6px;
            text-align: center;
            margin-top: 4px;
            padding-top: 2px;
            border-top: 1px dashed #eee;
            direction: rtl;
        }
        .contact-footer p {
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .contact-footer .line {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 40px;
        }
        @media print {
            .report-container { border: none; padding: 0; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .header, .declaration-ar, .declaration-en, .signature-block { page-break-inside: avoid; }
            button { display: none !important; visibility: hidden; }
        }
    </style>
</head>
<body>
    <div class="report-container">
        <button class="btn btn-secondary mb-2 btn-sm btn-print" onclick="window.print()" style="font-size: 8px;">🖨️ طباعة / Print</button>
        <div class="header">
            <img src="/health_vet/public/dclogo.png" alt="DC Logo" class="logo">
           
            <div class="header-title">
                <h1>استبيان طلب تبني حيوان / Pre-Adoption Application Report</h1>
                <p>رقم الطلب: <span id="report-id" class="text-danger"></span> / Application ID</p>
                <p class="h6">التاريخ: <span id="report-date"></span> / Date</p>
            </div>
           
            <img src="/health_vet/public/shjmunlogo.png" alt="SHJMUN Logo" class="logo">
        </div>
        <div id="report-content">
            <!-- البيانات الشخصية / Personal Details -->
            <div class="section-title">
                <span>البيانات الشخصية</span>
                <span>/</span>
                <span>Personal Information</span>
            </div>
           
            <!-- الاسم الكامل -->
            <div class="content-row">
                <div class="col-ar">الاسم الكامل:</div>
                <div class="col-value">
                    <span id="full_name_val_ar"></span>
                </div>
                <div class="col-en">Full Name:</div>
            </div>
           
            <!-- الجنسية -->
            <div class="content-row">
                <div class="col-ar">الجنسية:</div>
                <div class="col-value">
                    <span id="nationality_val_ar"></span>
                </div>
                <div class="col-en">Nationality:</div>
            </div>
           
            <!-- العمر -->
            <div class="content-row">
                <div class="col-ar">العمر:</div>
                <div class="col-value">
                    <span id="age_val_ar"></span>
                </div>
                <div class="col-en">Age:</div>
            </div>
           
            <!-- رقم الهوية -->
            <div class="content-row">
                <div class="col-ar">رقم الهوية الإماراتية:</div>
                <div class="col-value">
                    <span id="emirates_id_val_ar"></span>
                </div>
                <div class="col-en">Emirates ID:</div>
            </div>
           
            <!-- البريد الإلكتروني -->
            <div class="content-row">
                <div class="col-ar">البريد الإلكتروني:</div>
                <div class="col-value">
                    <span id="email_val_ar"></span>
                </div>
                <div class="col-en">Email:</div>
            </div>
           
            <!-- رقم الهاتف -->
            <div class="content-row">
                <div class="col-ar">رقم الهاتف:</div>
                <div class="col-value">
                    <span id="phone_val_ar"></span>
                </div>
                <div class="col-en">Phone:</div>
            </div>
            <!-- بيانات السكن / Housing Details -->
            <div class="section-title">
                <span>بيانات السكن</span>
                <span>/</span>
                <span>Housing Information</span>
            </div>
           
            <!-- منطقة السكن -->
            <div class="content-row">
                <div class="col-ar">منطقة السكن:</div>
                <div class="col-value">
                    <span id="housing_area_val_ar"></span>
                </div>
                <div class="col-en">What is your housing area?:</div>
            </div>
           
            <!-- صاحب المسكن -->
            <div class="content-row">
                <div class="col-ar">هل أنت صاحب المسكن؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="is_house_owner_yes">نعم / Yes</span>
                        <span id="is_house_owner_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Are you the house owner?</div>
            </div>
           
            <!-- مالك العقار -->
            <div class="content-row">
                <div class="col-ar">إذا كان المسكن مستأجر هل سيسمح مالك العقار بتربيتك للقط / الكلب؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="landlord_allows_pets_yes">نعم / Yes</span>
                        <span id="landlord_allows_pets_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">If rented, will the landlord allow pets?</div>
            </div>
           
            <!-- نوع السكن -->
            <div class="content-row">
                <div class="col-ar">ما هو نوع السكن؟</div>
                <div class="col-value">
                    <div class="select-options">
                        <span id="housing_type_villa">فيلا / Villa</span>
                        <span id="housing_type_apartment">شقة / Apartment</span>
                    </div>
                </div>
                <div class="col-en">What is the type of housing</div>
            </div>
           
            <!-- مكان مناسب -->
            <div class="content-row">
                <div class="col-ar">هل لديك مكان مناسب لرعاية الحيوان في المسكن؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="has_pet_space_yes">نعم / Yes</span>
                        <span id="has_pet_space_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Do you have suitable space for the pet?</div>
            </div>
           
            <!-- أطفال في المنزل -->
            <div class="content-row">
                <div class="col-ar">هل لديك أطفال في المنزل؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="has_children_yes">نعم / Yes</span>
                        <span id="has_children_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Do you have children at home?</div>
            </div>
           
            <!-- حساسية من الحيوانات -->
            <div class="content-row">
                <div class="col-ar">هل يوجد أشخاص لديهم حساسية من الحيوانات داخل المنزل؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="has_allergy_yes">نعم / Yes</span>
                        <span id="has_allergy_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Are there people with animal allergies at home?</div>
            </div>
           
            <!-- حيوانات أخرى -->
            <div class="content-row">
                <div class="col-ar">هل توجد حيوانات أخرى داخل المنزل؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="has_other_animals_yes">نعم / Yes</span>
                        <span id="has_other_animals_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Are there other animals at home?</div>
            </div>
           
            <!-- تفاصيل الحيوانات الأخرى -->
            <div class="content-row">
                <div class="col-ar">في حال نعم، ما هو نوع الحيوان؟ وهل تم تعقيمه؟</div>
                <div class="col-value">
                    <div class="textarea-field">
                        <span class="value-ar" id="other_animals_details_val_ar"></span>
                        <span class="value-en" id="other_animals_details_val_en"></span>
                    </div>
                </div>
                <div class="col-en">If yes, what type and neutered?</div>
            </div>
            <!-- الالتزامات والمسؤوليات / Commitments -->
            <div class="section-title">
                <span>الالتزامات والمسؤوليات</span>
                <span>/</span>
                <span>Commitments and Responsibilities</span>
            </div>
           
            <!-- المسؤول الأساسي -->
            <div class="content-row">
                <div class="col-ar">من سيكون المسؤول الأساسي عن رعاية الحيوان؟</div>
                <div class="col-value">
                    <span id="main_caretaker_val_ar"></span>
                </div>
                <div class="col-en">Who will be the main caretaker?</div>
            </div>
           
            <!-- تجول خارج المنزل -->
            <div class="content-row">
                <div class="col-ar">هل سيسمح للحيوان التجول خارج المنزل؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="pet_outside_yes">نعم / Yes</span>
                        <span id="pet_outside_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Will the pet roam outside?</div>
            </div>
           
            <!-- بديل لرعاية الحيوان -->
            <div class="content-row">
                <div class="col-ar">هل يوجد شخص آخر قادر على رعاية الحيوان في حال عدم تواجدك أو سفرك؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="has_alternate_caretaker_yes">نعم / Yes</span>
                        <span id="has_alternate_caretaker_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Is there an alternate caretaker?</div>
            </div>
           
            <!-- المقدرة المالية -->
            <div class="content-row">
                <div class="col-ar">هل لديك المقدرة المالية لتوفير احتياجات الحيوان؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="financial_ability_yes">نعم / Yes</span>
                        <span id="financial_ability_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Do you have financial ability for pet needs?</div>
            </div>
           
            <!-- معرفة رعاية الحيوانات -->
            <div class="content-row">
                <div class="col-ar">ما مدى معرفتك الأساسية فيما يتعلق برعاية الحيوانات؟</div>
                <div class="col-value">
                    <div class="select-options">
                        <span id="animal_care_knowledge_excellent">ممتاز / Excellent</span>
                        <span id="animal_care_knowledge_average">متوسط / Average</span>
                        <span id="animal_care_knowledge_weak">ضعيف / Weak</span>
                    </div>
                </div>
                <div class="col-en">Your basic knowledge of animal care</div>
            </div>
           
            <!-- الالتزام بالرعاية البيطرية -->
            <div class="content-row">
                <div class="col-ar">هل ستلتزم بتوفير الرعاية البيطرية اللازمة بما في ذلك التطعيمات والفحوصات الدورية؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="vet_commitment_yes">نعم / Yes</span>
                        <span id="vet_commitment_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Will you commit to veterinary care?</div>
            </div>
           
            <!-- الالتزام طويل الأمد -->
            <div class="content-row">
                <div class="col-ar">هل تقر بقدرتك على الاستمرار بالتبني وعدم التخلي عن الحيوان؟</div>
                <div class="col-value">
                    <div class="checkbox-options">
                        <span id="long_term_commitment_yes">نعم / Yes</span>
                        <span id="long_term_commitment_no">لا / No</span>
                    </div>
                </div>
                <div class="col-en">Do you commit to long-term adoption?</div>
            </div>
        </div>
        <!-- الإقرار / Declaration -->
        <div class="row g-2">
            <div class="col-md-6">
                <div class="declaration-ar">
                    <h6 class="text-center fw-bold mb-2" style="color:var(--primary-color); font-size: 9px;">إقرار وتعهد</h6>
                    <ul>
                        <li>أقر بأن كافة المعلومات الواردة أعلاه صحيحة وأن أي معلومات خاطئة قد تؤدي إلى إبطال هذا التبني.</li>
                        <li>لا يمكن تبديل الحيوان المحجوز إلا في حالات محدد وذلك حسب تقييم الطبيب البيطري المناوب.</li>
                        <li>أعلم بأن لدى إدارة مأوى الشارقة للقطط والكلاب الحق برفض التبني في حال عدم تطابق الاشتراطات المطلوبة للتبني.</li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="declaration-en">
                    <h6 class="text-center fw-bold mb-2" style="color:var(--primary-color); font-size: 9px;">Declaration and Pledge</h6>
                    <ul>
                        <li>I affirm that all information provided above is true, and any false information may lead to the cancellation of this adoption.</li>
                        <li>The reserved animal cannot be exchanged except in specific cases determined by the duty veterinarian.</li>
                        <li>I acknowledge that the Sharjah Cats and Dogs Shelter Management has the right to refuse the adoption if the required conditions are not met.</li>
                    </ul>
                </div>
            </div>
        </div>
        <!-- التوقيعات / Signatures -->
        <div class="row g-2 mt-3">
            <div class="col-md-6">
                <div class="signature-block">
                    <p class="h6 fw-bold mb-1" style="color:var(--primary-color); font-size: 8px;">توقيع مقدم الطلب (استخدم اللوحة) / Applicant's Signature (use pad)</p>
                    <div class="signature-line" id="adopter-signature-area">
                        <span class="text-muted" style="font-size: 7px;">جاري تحميل التوقيع... / Loading signature...</span>
                    </div>
                    <p style="font-size: 7px;">الاسم: <span id="adopter-name-sig" class="fw-bold"></span> / Name:</p>
                    <p style="font-size: 7px;">التاريخ: <span id="submission-date-sig" class="fw-bold"></span> / Date:</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="signature-block">
                    <p class="h6 fw-bold mb-1" style="color:var(--secondary-color); font-size: 8px;">Approved by / توقيع الموظف المعتمد</p>
                    <p style="font-size: 7px;">اسم الموظف: <span id="approved-by-name" class="fw-bold"></span> / Employee Name:</p>
                    <p style="font-size: 7px;">رقم الموظف: <span id="approved-by-id" class="fw-bold"></span> / Employee ID:</p>
                </div>
            </div>
        </div>
        <div class="footer-info">
            <p style="margin: 0; font-size: 7px;">هذا المستند صادر بناءً على استبيان طلب التبني رقم: <span id="report-id-footer" class="text-danger fw-bold"></span> / This document is based on Adoption Application No.</p>
        </div>
        <div class="contact-footer">
            <div class="line">
                <span>أرضي 06-5453054</span>
                <span>هاتف 056-3299669</span>
            </div>
            <div class="line">
                <span>مركز الاتصال للشكاوي والاستفسارات /993</span>
                <span>info@shjmun.gov.ae</span>
            </div>
        </div>
    </div>
   <script src="session_check.js"></script>
    <script>
        // PHP Record ID passed securely from server-side
        const phpRecordId = <?php echo json_encode($record_id); ?>;
       
        // --- CONSTANTS AND GLOBAL VARS ---
        const APPLICATION_DATA_API_URL = '/health_vet/api/add_adoption_applications.php';
        const EMPLOYEES_API_URL = '/health_vet/api/get_employees.php';
        const ARABIC_TRANSLATIONS_URL = '/health_vet/languages/ar_add_adoption_applications.json';
        const ENGLISH_TRANSLATIONS_URL = '/health_vet/languages/en_add_adoption_applications.json';
        const SIGNATURE_BASE_PATH = '/health_vet/uploads/add_adoption_applications/'; // المسار الأساسي للتوقيعات
       
        let employeesMap = {};
        let fieldTranslations = { ar: {}, en: {} };
       
        // --- HARDCODED MAPPINGS للترجمات الثابتة ---
        const staticTranslations = {
            'yes': { ar: 'نعم', en: 'Yes' },
            'No': { ar: 'لا', en: 'No' },
            'not_provided': { ar: 'غير مُقدم', en: 'N/A' },
            'villa': { ar: 'فيلا', en: 'Villa' },
            'apartment': { ar: 'شقة', en: 'Apartment' },
            'excellent': { ar: 'ممتاز', en: 'Excellent' },
            'average': { ar: 'متوسط', en: 'Average' },
            'weak': { ar: 'ضعيف', en: 'Weak' },
        };
        // --- HELPER FUNCTIONS ---
        function getTranslation(key, lang) {
            if (fieldTranslations[lang][key]) return fieldTranslations[lang][key];
            if (staticTranslations[key] && staticTranslations[key][lang]) return staticTranslations[key][lang];
            return key;
        }
        function getParameterByName(name) {
            name = name.replace(/[\[\]]/g, '\\$&');
            const regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)');
            const results = regex.exec(window.location.href);
            if (!results) return null;
            if (!results[2]) return '';
            return decodeURIComponent(results[2].replace(/\+/g, ' '));
        }
        function formatValue(key, value, lang = 'ar') {
            if (value === null || value === '' || value === undefined) {
                return getTranslation('not_provided', lang);
            }
            // للحقول المنطقية (checkboxes)
            if (['is_house_owner', 'landlord_allows_pets', 'has_pet_space', 'has_children', 'has_allergy', 'has_other_animals', 'pet_outside', 'has_alternate_caretaker', 'vet_commitment', 'long_term_commitment', 'financial_ability'].includes(key)) {
                const statusKey = value == 1 ? 'yes' : 'No';
                return getTranslation(statusKey, lang);
            }
            // للحقول الاختيارية (select)
            if (['housing_type', 'animal_care_knowledge'].includes(key)) {
                const translationKey = value.toLowerCase().replace(/[^a-z]/g, '');
                return getTranslation(translationKey, lang);
            }
            if (key === 'created_at' || key === 'submission_date') {
                const date = new Date(value);
                return lang === 'ar' ? date.toLocaleDateString('ar-AE') : date.toLocaleDateString('en-US');
            }
            return value;
        }
        function updateCheckboxDisplay(key, value) {
            const yesEl = document.getElementById(key + '_yes');
            const noEl = document.getElementById(key + '_no');
            if (yesEl && noEl) {
                if (value == 1) {
                    yesEl.style.backgroundColor = '#d4edda'; // أخضر للنعم
                    yesEl.style.fontWeight = 'bold';
                    noEl.style.backgroundColor = '#f8f9fa';
                    noEl.style.fontWeight = 'normal';
                } else {
                    yesEl.style.backgroundColor = '#f8f9fa';
                    yesEl.style.fontWeight = 'normal';
                    noEl.style.backgroundColor = '#d4edda'; // أخضر لللا
                    noEl.style.fontWeight = 'bold';
                }
            }
        }
        function updateSelectDisplay(key, value) {
            const options = ['excellent', 'average', 'weak', 'villa', 'apartment'];
            options.forEach(opt => {
                const el = document.getElementById(key + '_' + opt);
                if (el) {
                    if (opt === value.toLowerCase()) {
                        el.style.backgroundColor = '#d4edda'; // مميز
                        el.style.fontWeight = 'bold';
                    } else {
                        el.style.backgroundColor = '#f8f9fa';
                        el.style.fontWeight = 'normal';
                    }
                }
            });
        }
        // --- CORE FETCH FUNCTIONS ---
        async function fetchTranslations() {
            try {
                const [arResponse, enResponse] = await Promise.all([
                    axios.get(ARABIC_TRANSLATIONS_URL),
                    axios.get(ENGLISH_TRANSLATIONS_URL)
                ]);
                fieldTranslations.ar = arResponse.data;
                fieldTranslations.en = enResponse.data;
            } catch (error) {
                console.warn("Failed to fetch translation files.", error);
            }
        }
        async function fetchEmployees() {
            try {
                const response = await axios.get(EMPLOYEES_API_URL);
                if (response.data.success && response.data.data) {
                    response.data.data.forEach(emp => {
                        employeesMap[emp.EmpID] = emp.EmpName;
                    });
                }
            } catch (error) {
                console.error("Failed to fetch employee list.", error);
            }
        }
        async function loadReportData(id) {
            try {
                const response = await axios.get(APPLICATION_DATA_API_URL, {
                    params: { action: 'get_details', id: id }
                });
                if (response.data.success && response.data.data) {
                    const data = response.data.data;
                    const empName = employeesMap[data.approved_by] || data.approved_by || 'غير محدد / N/A';
                    data.approved_by_name = empName;
                    renderReport(data);
                } else {
                    showError('خطأ في تحميل بيانات الطلب. (تأكد من API).');
                }
            } catch (error) {
                showError(`فشل في الاتصال: ${error.message}`);
            }
        }
        function showError(message) {
            const contentDiv = document.getElementById('report-content');
            if (contentDiv) {
                contentDiv.innerHTML = `<div class="alert alert-danger" role="alert">${message}</div>`;
            }
        }
        // --- RENDER FUNCTION ---
        function renderReport(data) {
            // تحديث العناوين العامة
            document.getElementById('report-id').textContent = data.id;
            document.getElementById('report-id-footer').textContent = data.id;
            document.getElementById('report-date').textContent = formatValue('submission_date', data.submission_date, 'ar') + ' / ' + formatValue('submission_date', data.submission_date, 'en');
            document.getElementById('adopter-name-sig').textContent = data.full_name || '................................';
            document.getElementById('submission-date-sig').textContent = formatValue('submission_date', data.submission_date, 'ar') + ' / ' + formatValue('submission_date', data.submission_date, 'en');
            document.getElementById('approved-by-name').textContent = data.approved_by_name;
            document.getElementById('approved-by-id').textContent = data.approved_by || 'غير محدد';
            // عرض التوقيع من المسار الكامل (data.signature)
            const signaturePath = data.signature; // المسار الكامل مثل /health_vet/uploads/add_adoption_applications/sig_690740eecc135.png
            const adopterSigArea = document.getElementById('adopter-signature-area');
            if (signaturePath) {
                const sigImg = `<img src="${signaturePath}" alt="Adopter Signature" onerror="this.onerror=null;this.outerHTML='<span class=\\'text-danger fw-bold text-center\\' style=\\'display: block; font-size: 7px;\\'>❌ لم يتم العثور على التوقيع</span>';" />`;
                adopterSigArea.innerHTML = sigImg;
            } else {
                adopterSigArea.innerHTML = '<span class="text-muted" style="font-size: 7px;">لا توقيع متاح / No signature available</span>';
            }
            // عرض البيانات الشخصية
            document.getElementById('full_name_val_ar').textContent = formatValue('full_name', data.full_name, 'ar');
            document.getElementById('nationality_val_ar').textContent = formatValue('nationality', data.nationality, 'ar');
            document.getElementById('age_val_ar').textContent = data.age || 'غير محدد';
            document.getElementById('emirates_id_val_ar').textContent = formatValue('emirates_id', data.emirates_id, 'ar');
            document.getElementById('email_val_ar').textContent = formatValue('email', data.email, 'ar');
            document.getElementById('phone_val_ar').textContent = formatValue('phone', data.phone, 'ar');
            // بيانات السكن
            document.getElementById('housing_area_val_ar').textContent = formatValue('housing_area', data.housing_area, 'ar');
            updateCheckboxDisplay('is_house_owner', data.is_house_owner);
            updateCheckboxDisplay('landlord_allows_pets', data.landlord_allows_pets);
            updateSelectDisplay('housing_type', data.housing_type);
            updateCheckboxDisplay('has_pet_space', data.has_pet_space);
            updateCheckboxDisplay('has_children', data.has_children);
            updateCheckboxDisplay('has_allergy', data.has_allergy);
            updateCheckboxDisplay('has_other_animals', data.has_other_animals);
            document.getElementById('other_animals_details_val_ar').textContent = data.other_animals_details ? formatValue('other_animals_details', data.other_animals_details, 'ar') : 'غير مُقدم';
            document.getElementById('other_animals_details_val_en').textContent = data.other_animals_details ? formatValue('other_animals_details', data.other_animals_details, 'en') : 'N/A';
            // الالتزامات
            document.getElementById('main_caretaker_val_ar').textContent = formatValue('main_caretaker', data.main_caretaker, 'ar');
            updateCheckboxDisplay('pet_outside', data.pet_outside);
            updateCheckboxDisplay('has_alternate_caretaker', data.has_alternate_caretaker);
            updateCheckboxDisplay('financial_ability', data.financial_ability);
            updateSelectDisplay('animal_care_knowledge', data.animal_care_knowledge);
            updateCheckboxDisplay('vet_commitment', data.vet_commitment);
            updateCheckboxDisplay('long_term_commitment', data.long_term_commitment);
        }
        // --- MAIN ENTRY POINT ---
        document.addEventListener('DOMContentLoaded', async function() {
            await Promise.all([
                fetchTranslations(),
                fetchEmployees()
            ]);
           
            let appId = phpRecordId; // استخدم الـ ID الآمن من PHP أولاً
            if (!appId) {
                appId = getParameterByName('id'); // fallback إلى URL إذا لم يكن متوفر
            }
            if (appId) {
                loadReportData(appId);
            } else {
                showError('خطأ: رقم الطلب مفقود / Error: ID missing');
            }
        });
    </script>
</body>
</html>