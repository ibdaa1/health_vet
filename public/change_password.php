<?php
ob_start();
// change_password.php
// تأكد ألا يوجد أي مسافات بيضاء أو أسطر فارغة قبل علامة <?php

// 1. بدء الجلسة - يجب أن تكون أول شيء على الإطلاق في الملف
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. تفعيل عرض الأخطاء أثناء التطوير (قم بتعطيلها عند النشر)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1); // لتسجيل الأخطاء في ملف
ini_set('error_log', __DIR__ . '/change_password_errors.log'); // مسار ملف سجل الأخطاء

// 3. تضمين ملف اتصال قاعدة البيانات - يتم تضمينه مرة واحدة فقط
require_once(__DIR__ . '/../api/db.php');
// 4. التحقق من تسجيل الدخول ووجود بيانات المستخدم في الجلسة
//    إذا لم يكن المستخدم مسجلاً، يتم توجيهه إلى صفحة تسجيل الدخول.
if (!isset($_SESSION['user']) || !is_array($_SESSION['user']) || empty($_SESSION['user']['username'])) {
    header("Location: login.php");
    exit;
}

// 5. استخراج بيانات المستخدم من الجلسة
$user_session_data = $_SESSION['user'];
$username_from_session = $user_session_data['username'];

$error = '';    // متغير لتخزين رسالة الخطأ
$success = '';  // متغير لتخزين رسالة النجاح

// 6. معالجة طلب تغيير كلمة المرور عند إرسال النموذج (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // جلب البيانات المدخلة من النموذج باستخدام Null Coalescing Operator للقيم الفارغة
    $currentPassword   = $_POST['current_password'] ?? '';
    $newPassword       = $_POST['new_password'] ?? '';
    $confirmPassword   = $_POST['confirm_password'] ?? '';

    // التحقق من أن جميع الحقول المطلوبة مملوءة
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = "يرجى تعبئة جميع حقول كلمة المرور.";
    } 
    // التحقق من تطابق كلمة المرور الجديدة مع تأكيدها
    elseif ($newPassword !== $confirmPassword) {
        $error = "كلمة المرور الجديدة وتأكيدها غير متطابقين.";
    } 
    else {
        // 7. جلب الهاش (Hash) الحالي لكلمة المرور من قاعدة البيانات
        $stmt = $conn->prepare("SELECT Password FROM Users WHERE Username = ?");
        if (!$stmt) {
            $error = "خطأ في تحضير استعلام جلب كلمة المرور: " . $conn->error;
            error_log("Database prepare error (get password): " . $conn->error);
        } else {
            $stmt->bind_param("s", $username_from_session); // ربط اسم المستخدم بالاستعلام
            $stmt->execute();
            $result = $stmt->get_result();

            // 8. التحقق مما إذا تم العثور على المستخدم في قاعدة البيانات
            if ($result && $result->num_rows > 0) {
                $dbUser = $result->fetch_assoc();
                $hashedCurrentPassword = $dbUser['Password']; // كلمة المرور المشفرة المخزنة في DB

                // 9. التحقق من صحة كلمة المرور الحالية المدخلة باستخدام password_verify
                if (password_verify($currentPassword, $hashedCurrentPassword)) {
                    // 10. تشفير كلمة المرور الجديدة
                    $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    
                    // 11. التأكد من أن كلمة المرور الجديدة ليست هي نفسها القديمة
                    if (password_verify($newPassword, $hashedCurrentPassword)) {
                        $error = "❗ كلمة المرور الجديدة لا يجب أن تطابق الحالية.";
                    } else {
                        // 12. تحديث كلمة المرور في قاعدة البيانات
                        $updateStmt = $conn->prepare("UPDATE Users SET Password = ? WHERE Username = ?");
                        if (!$updateStmt) {
                            $error = "خطأ في تحضير استعلام التحديث: " . $conn->error;
                            error_log("Database prepare error (update password): " . $conn->error);
                        } else {
                            $updateStmt->bind_param("ss", $hashedNewPassword, $username_from_session);

                            if ($updateStmt->execute()) {
                                $success = "✅ تم تغيير كلمة المرور بنجاح. يرجى تسجيل الدخول مجدداً.";
                                // مسح حقول POST لتجنب إعادة إرسال البيانات عند تحديث الصفحة
                                unset($_POST['current_password'], $_POST['new_password'], $_POST['confirm_password']);

                                // *** الخطوات الجديدة لإنهاء الجلسة وإعادة التوجيه ***
                                session_unset();   // إزالة جميع متغيرات الجلسة
                                session_destroy(); // تدمير الجلسة
                                // إعادة التوجيه إلى صفحة تسجيل الدخول
                                header("Location: login.php?message=password_changed"); // يمكن إضافة رسالة
                                exit; // إنهاء تنفيذ السكريبت بعد إعادة التوجيه
                                // ***************************************************

                            } else {
                                $error = "❌ حدث خطأ أثناء تحديث كلمة المرور: " . $updateStmt->error;
                                error_log("Database execute error (update password): " . $updateStmt->error);
                            }
                            $updateStmt->close(); // إغلاق بيان التحديث
                        }
                    }
                } else {
                    $error = "❌ كلمة المرور الحالية غير صحيحة.";
                }
            } else {
                $error = "❌ لم يتم العثور على معلومات المستخدم في قاعدة البيانات.";
                error_log("User not found in DB for Username: " . $username_from_session . " despite active session.");
            }
            $stmt->close(); // إغلاق بيان جلب كلمة المرور
        }
    }
}
$conn->close(); // إغلاق اتصال قاعدة البيانات في نهاية السكريبت
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تغيير كلمة المرور - نظام بلدية الشارقة</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* تعريف المتغيرات لألوان وتأثيرات سهلة التعديل */
        :root {
            --primary-color: #2e7d32;      /* أخضر داكن أساسي */
            --primary-light: #4caf50;      /* أخضر فاتح للمكونات */
            --primary-dark: #1b5e20;       /* أخضر أغمق عند التفاعل */
            --text-color: #333;            /* لون النص الأساسي */
            --text-light: #666;            /* لون النص الثانوي/الخفيف */
            --border-color: #ddd;          /* لون الحدود الافتراضي */
            --error-color: #d32f2f;        /* أحمر لرسائل الخطأ */
            --success-color: #388e3c;      /* أخضر لرسائل النجاح */
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1); /* ظل للعناصر */
        }

        /* تنسيقات عامة للجسم والخط */
        body {
            font-family: 'Tajawal', sans-serif; /* استخدام خط تاجوال */
            background-color: #f9f9f9;       /* خلفية فاتحة */
            color: var(--text-color);        /* لون النص */
            line-height: 1.6;                /* تباعد الأسطر */
            direction: rtl;                  /* اتجاه النص من اليمين لليسار */
            margin: 0;
            padding: 0;
            display: flex;                   /* استخدام Flexbox للتوسيط */
            justify-content: center;         /* توسيط أفقي */
            align-items: center;             /* توسيط رأسي */
            min-height: 100vh;               /* ضمان ملء الصفحة بالكامل */
        }

        /* تنسيق حاوية النموذج الرئيسية */
        .container {
            max-width: 500px;                /* أقصى عرض للحاوية */
            background: white;               /* خلفية بيضاء */
            padding: 30px;                   /* مسافة داخلية */
            border-radius: 10px;             /* حواف مستديرة */
            box-shadow: var(--shadow);       /* ظل للحاوية */
            width: 90%;                      /* عرض مرن للشاشات الصغيرة */
        }

        /* تنسيق عنوان الصفحة */
        h1 {
            text-align: center;              /* توسيط النص */
            color: var(--primary-color);     /* لون أخضر أساسي */
            margin-bottom: 30px;             /* مسافة سفلية */
            padding-bottom: 15px;            /* مسافة سفلية داخلية */
            border-bottom: 1px solid var(--border-color); /* خط سفلي */
        }

        /* تنسيق مجموعات حقول النموذج */
        .form-group {
            margin-bottom: 20px;             /* مسافة سفلية بين المجموعات */
            text-align: right;               /* محاذاة النص لليمين للحقول */
        }

        /* تنسيق تسميات الحقول */
        label {
            display: block;                  /* لجعل التسمية تظهر في سطر خاص */
            margin-bottom: 8px;              /* مسافة سفلية */
            font-weight: 500;                /* وزن الخط متوسط */
        }

        /* تنسيق حقول الإدخال */
        input {
            width: 100%;                     /* عرض كامل */
            padding: 12px 15px;              /* مسافة داخلية */
            border: 1px solid var(--border-color); /* حدود رفيعة */
            border-radius: 6px;              /* حواف مستديرة */
            font-size: 1rem;                 /* حجم الخط */
            font-family: 'Tajawal', sans-serif; /* استخدام خط تاجوال */
        }

        /* تنسيق حقول الإدخال عند التركيز عليها */
        input:focus {
            border-color: var(--primary-color);     /* تغيير لون الحدود */
            outline: none;                          /* إزالة الخط الأزرق الافتراضي */
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2); /* إضافة ظل خفيف */
        }

        /* تنسيق حاوية حقل كلمة المرور (لزر الإظهار/الإخفاء) */
        .password-container {
            position: relative;              /* لتحديد موضع الزر داخلها */
            display: flex;                   /* لتنظيم حقل الإدخال وزر الإظهار */
            align-items: center;             /* توسيط العناصر عموديًا */
        }

        /* تعديل حقل الإدخال داخل حاوية كلمة المرور */
        .password-container input {
            flex-grow: 1;                    /* يجعل حقل الإدخال يأخذ المساحة المتاحة */
            padding-left: 40px;              /* لترك مساحة لزر الإظهار */
        }

        /* تنسيق زر إظهار/إخفاء كلمة المرور */
        .toggle-password {
            position: absolute;              /* تحديد الموضع المطلق */
            left: 10px;                      /* وضعه على اليسار (مع اتجاه RTL) */
            top: 50%;                        /* توسيط رأسي */
            transform: translateY(-50%);     /* ضبط التوسيط الرأسي */
            background: none;                /* بدون خلفية */
            border: none;                    /* بدون حدود */
            cursor: pointer;                 /* مؤشر الفأرة كيد */
            color: var(--text-light);        /* لون النص الثانوي */
            font-size: 1.2em;                /* تكبير أيقونة العين */
            padding: 5px;                    /* مسافة داخلية حول الأيقونة */
        }

        /* تنسيق زر الإرسال */
        .btn {
            display: block;                  /* عرض الزر ككتلة */
            width: 100%;                     /* عرض كامل */
            padding: 12px;                   /* مسافة داخلية */
            background: var(--primary-color); /* لون خلفية أخضر أساسي */
            color: white;                    /* لون النص أبيض */
            border: none;                    /* بدون حدود */
            border-radius: 6px;              /* حواف مستديرة */
            font-size: 1rem;                 /* حجم الخط */
            cursor: pointer;                 /* مؤشر الفأرة كيد */
            margin-top: 20px;                /* مسافة علوية */
            text-align: center;              /* توسيط النص */
            text-decoration: none;           /* إزالة التسطير */
            transition: background-color 0.3s ease; /* تأثير الانتقال عند التفاعل */
        }

        /* تنسيق زر الإرسال عند التحويم */
        .btn:hover {
            background: var(--primary-dark); /* تغيير اللون عند التحويم */
        }

        /* تنسيق رسائل التنبيه (خطأ/نجاح) */
        .alert {
            padding: 15px;                   /* مسافة داخلية */
            margin-bottom: 20px;             /* مسافة سفلية */
            border-radius: 6px;              /* حواف مستديرة */
            text-align: center;              /* توسيط النص */
            font-weight: 600;                /* وزن الخط سميك */
        }

        /* تنسيق رسائل الخطأ */
        .error {
            background: #ffebee;             /* خلفية حمراء فاتحة */
            color: var(--error-color);       /* لون النص أحمر */
            border: 1px solid #ffcdd2;       /* حدود حمراء أغمق */
        }

        /* تنسيق رسائل النجاح */
        .success {
            background: #e8f5e9;             /* خلفية خضراء فاتحة */
            color: var(--success-color);     /* لون النص أخضر */
            border: 1px solid #c8e6c9;       /* حدود خضراء أغمق */
        }

        /* تنسيق رابط العودة لصفحة تسجيل الدخول */
        .back-to-login {
            display: block;                  /* عرض ككتلة */
            text-align: center;              /* توسيط النص */
            margin-top: 20px;                /* مسافة علوية */
            color: var(--primary-color);     /* لون أخضر أساسي */
            text-decoration: none;           /* إزالة التسطير */
            font-weight: 500;                /* وزن خط متوسط */
            transition: color 0.3s ease;     /* تأثير الانتقال عند التفاعل */
        }

        /* تنسيق رابط العودة لصفحة تسجيل الدخول عند التحويم */
        .back-to-login:hover {
            color: var(--primary-dark);      /* تغيير اللون عند التحويم */
            text-decoration: underline;      /* إضافة تسطير عند التحويم */
        }

        /* التنسيقات المتجاوبة للشاشات الصغيرة (أقل من 600 بكسل) */
        @media (max-width: 600px) {
            .container {
                margin: 20px auto;           /* توسيط مع هوامش */
                padding: 20px;               /* تقليل المسافة الداخلية */
            }
            h1 {
                font-size: 1.8em;            /* تصغير حجم العنوان */
            }
            input {
                font-size: 0.9em;            /* تصغير حجم خط حقول الإدخال */
            }
            .btn {
                font-size: 0.95em;           /* تصغير حجم خط الزر */
                padding: 10px;               /* تقليل المسافة الداخلية للزر */
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>تغيير كلمة المرور</h1>

        <?php if ($error): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="current_password">كلمة المرور الحالية</label>
                <div class="password-container">
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    <button type="button" class="toggle-password" onclick="togglePassword('current_password')" aria-label="إظهار كلمة المرور">👁️</button>
                </div>
            </div>

            <div class="form-group">
                <label for="new_password">كلمة المرور الجديدة</label>
                <div class="password-container">
                    <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                    <button type="button" class="toggle-password" onclick="togglePassword('new_password')" aria-label="إظهار كلمة المرور">👁️</button>
                </div>
                <small style="color: var(--text-light); text-align: right; display: block; margin-top: 5px;">(لا يشترط أن تحتوي على أرقام أو حروف خاصة)</small>
            </div>

            <div class="form-group">
                <label for="confirm_password">تأكيد كلمة المرور الجديدة</label>
                <div class="password-container">
                    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                    <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')" aria-label="إظهار كلمة المرور">👁️</button>
                </div>
            </div>

            <button type="submit" class="btn">تغيير كلمة المرور</button>
        </form>

        <a href="login.php" class="back-to-login">العودة إلى صفحة تسجيل الدخول</a>
    </div>

    <script>
        // دالة تبديل نوع حقل كلمة المرور (إظهار/إخفاء)
        function togglePassword(id) {
            const input = document.getElementById(id);
            // تبديل النوع بين 'password' و 'text'
            input.type = input.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>
</html>