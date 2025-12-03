<?php
session_start();
if (!isset($_SESSION['user']) || !is_array($_SESSION['user']) || empty($_SESSION['user']['EmpName'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];
$current_date = date('Y-m-d');
$current_time = date('H:i');
// Check user roles
$is_admin = $user['IsAdmin'] == 1;
$is_license_manager = $user['IsLicenseManager'] == 1;
$is_admin_or_manager = $is_admin || $is_license_manager;
// New role checks
$has_warehouse_rights = $is_admin || isset($user['warehouse_rights']) && $user['warehouse_rights'] == 1;
$has_clinic_rights = $is_admin || isset($user['clinic_rights']) && $user['clinic_rights'] == 1;
$has_complaints_manager_rights = $is_admin || isset($user['complaints_manager_rights']) && $user['complaints_manager_rights'] == 1;
$has_follow_up_complaints_rights = isset($user['follow_up_complaints']) && $user['follow_up_complaints'] == 1;
$has_super_admin_rights = $is_admin; // IsAdmin already defined
// Language handling
$default_lang = 'en';
$available_langs = ['en', 'ar'];
$current_lang = $default_lang;
if (isset($_GET['lang']) && in_array($_GET['lang'], $available_langs)) {
    $current_lang = $_GET['lang'];
    $_SESSION['lang'] = $current_lang;
} elseif (isset($_SESSION['lang'])) {
    $current_lang = $_SESSION['lang'];
}
// Load translations
$translations = [];
$lang_file = "../languages/{$current_lang}_index.json";
if (file_exists($lang_file)) {
    $translations = json_decode(file_get_contents($lang_file), true);
}
function t($key, $translations) {
    return isset($translations[$key]) ? $translations[$key] : $key;
}
// Set direction based on language
$dir = $current_lang === 'ar' ? 'rtl' : 'ltr';
$text_align = $current_lang === 'ar' ? 'right' : 'left';
$flex_direction = $current_lang === 'ar' ? 'row-reverse' : 'row';
?>
<!DOCTYPE html>
<html lang="<?= $current_lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title><?= t('system_title', $translations) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary-color: #2e7d32;
            --primary-light: #4caf50;
            --primary-dark: #1b5e20;
            --secondary-color: #ede4d4;
            --secondary-dark: #d4c8a8;
            --light-color: #ffffff;
            --beige-light: #ffffff;
            --beige-section: #ffffff;
            --white: #ffffff;
            --shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 12px 35px rgba(46, 125, 50, 0.15);
            --gradient-primary: linear-gradient(135deg, #2e7d32 0%, #4caf50 100%);
            --featured-gradient: linear-gradient(135deg, #ff6b35 0%, #ff8e53 100%);
            --section-gradient: linear-gradient(135deg, #ffffff 0%, #ffffff 100%);
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Cairo', sans-serif;
            background: var(--light-color);
            color: var(--primary-dark);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            padding: 1vw;
            overflow-x: hidden;
        }
        .container {
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 1100px;
            margin: auto;
            padding: 2%;
            position: relative;
            overflow: visible;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            isolation: isolate;
            min-height: 600px;
        }
        .container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--gradient-primary);
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
            z-index: 1;
        }
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-bottom: 1.5%;
            position: relative;
            z-index: 2;
        }
        .titles {
            display: flex;
            flex-direction: column;
            animation: fadeInRight 0.8s ease-out;
        }
        .main-title {
            font-size: clamp(0.8rem, 2vw, 1rem);
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.3rem;
            letter-spacing: -0.5px;
            text-align: <?= $text_align ?>;
        }
        .sub-title {
            font-size: clamp(0.65rem, 1.5vw, 0.75rem);
            color: var(--primary-dark);
            font-weight: 600;
            opacity: 0.9;
            text-align: <?= $text_align ?>;
        }
        .logo {
            height: 35px;
            width: auto;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            transition: transform 0.3s ease;
            animation: fadeInLeft 0.8s ease-out;
            flex-shrink: 0;
        }
        .logo:hover {
            transform: scale(1.05);
        }
        /* Language Switcher */
        .language-switcher {
            position: absolute;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
        }
        .lang-btn {
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 20px;
            padding: 8px 15px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 8px rgba(46, 125, 50, 0.3);
        }
        .lang-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.4);
        }
        /* Section Styling */
        .section {
            width: 100%;
            margin: 5px 0;
            padding: 10px;
            background: var(--section-gradient);
            border-radius: 12px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .section-title {
            font-size: clamp(0.75rem, 1.8vw, 0.9rem);
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--secondary-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-title i {
            color: var(--primary-color);
        }
        /* Main Icons Grid - Increased sizes */
        .icons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
            gap: clamp(8px, 1.5vw, 12px);
            width: 100%;
            animation: fadeInUp 0.8s ease-out;
            position: relative;
            z-index: 5;
        }
        /* Base icon-item styles - Increased sizes */
        .icon-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--primary-dark);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            padding: 0.8rem 0.5rem;
            border-radius: 12px;
            background: var(--white);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(46, 125, 50, 0.08);
            min-height: 85px;
        }
        .icon-item::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--d4c8a8) 100%);
            opacity: 0.1;
            transition: left 0.4s ease;
            z-index: 0;
        }
        .icon-item:hover::before {
            left: 0;
        }
        .icon-item:hover {
            transform: translateY(-4px) scale(1.02);
            background: linear-gradient(135deg, var(--light-color), var(--beige-light));
            color: var(--primary-color);
            box-shadow: var(--shadow-hover), 0 0 12px rgba(46, 125, 50, 0.12);
            border-color: var(--primary-light);
        }
        .icon-item i {
            font-size: clamp(1rem, 2.2vw, 1.4rem);
            margin-bottom: 0.5rem;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            transition: all 0.3s ease;
            z-index: 1;
            position: relative;
        }
        .icon-item:hover i {
            transform: scale(1.06);
            text-shadow: 0 1px 6px rgba(46, 125, 53, 0.2);
        }
        .icon-label {
            font-size: clamp(0.6rem, 1.2vw, 0.75rem);
            font-weight: 600;
            text-align: center;
            z-index: 1;
            position: relative;
            line-height: 1.2;
            transition: color 0.3s ease;
        }
        .icon-item:hover .icon-label {
            color: var(--primary-color) !important;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        /* User Footer - Compact Version */
        .user-footer {
            background: var(--gradient-primary);
            color: var(--white);
            border-radius: 12px;
            padding: 1rem;
            margin-top: auto;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 0.8rem;
            align-items: center;
            width: 100%;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            animation: fadeInUp 1s ease-out 0.2s both;
            z-index: 2;
        }
        .user-footer::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            opacity: 0.05;
            z-index: 0;
        }
        .welcome-section {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            position: relative;
            z-index: 1;
            text-align: <?= $text_align ?>;
        }
        .welcome-text {
            color: var(--white);
            font-size: clamp(0.7rem, 1.8vw, 0.8rem);
            animation: pulse 2s infinite;
        }
        .welcome-text strong {
            color: var(--secondary-color);
            font-weight: 700;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }
        .datetime {
            display: flex;
            gap: 0.8rem;
            font-size: clamp(0.65rem, 1.5vw, 0.75rem);
            position: relative;
            z-index: 1;
        }
        .date, .time {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(255, 255, 255, 0.1);
            padding: 0.3rem 0.6rem;
            border-radius: 12px;
            backdrop-filter: blur(6px);
            transition: all 0.3s ease;
        }
        .date:hover, .time:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.02);
        }
        .time-updater {
            font-weight: 600;
            color: var(--secondary-color);
        }
        .user-actions {
            display: flex;
            gap: 0.5rem;
            position: relative;
            z-index: 1;
        }
        .user-action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--white);
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            padding: 0.6rem;
            transition: all 0.3s ease;
            font-size: clamp(0.6rem, 1.3vw, 0.7rem);
            font-weight: 600;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
        }
        .user-action-btn i {
            font-size: 1rem;
        }
        .user-action-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            color: var(--secondary-color);
        }
        /* Tooltip for icon-only buttons */
        .user-action-btn::after {
            content: attr(title);
            position: absolute;
            bottom: -35px;
            <?= $current_lang === 'ar' ? 'right: 50%;' : 'left: 50%;' ?>
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.7rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
            z-index: 100;
        }
        .user-action-btn:hover::after {
            opacity: 1;
            visibility: visible;
        }
        /* Keyframes and Media Queries */
        @keyframes fadeInRight { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes fadeInLeft { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.8; } }
        @media (max-width: 768px) {
            .icons-grid {
                grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
                gap: 6px;
            }
            .user-footer {
                grid-template-columns: 1fr;
                gap: 0.8rem;
                text-align: center;
            }
            .user-actions {
                justify-content: center;
            }
            .welcome-section {
                text-align: center;
            }
            .language-switcher {
                position: relative;
                top: 0;
                left: 50%;
                transform: translateX(-50%);
                margin-bottom: 10px;
            }
        }
    
        @media (max-width: 480px) {
            .icons-grid {
                grid-template-columns: repeat(auto-fit, minmax(70px, 1fr));
                gap: 5px;
            }
            .datetime {
                justify-content: center;
            }
        }
        /* Staggered animation indices */
        .icons-grid > * { animation: fadeInUp 0.6s ease-out both; opacity: 0; }
        .icons-grid > *:nth-child(1) { animation-delay: 0.1s; }
        .icons-grid > *:nth-child(2) { animation-delay: 0.15s; }
        .icons-grid > *:nth-child(3) { animation-delay: 0.2s; }
        .icons-grid > *:nth-child(4) { animation-delay: 0.25s; }
        .icons-grid > *:nth-child(5) { animation-delay: 0.3s; }
        .icons-grid > *:nth-child(6) { animation-delay: 0.35s; }
        .icons-grid > *:nth-child(7) { animation-delay: 0.4s; }
        .icons-grid > *:nth-child(8) { animation-delay: 0.45s; }
        .icons-grid > *:nth-child(9) { animation-delay: 0.5s; }
    </style>
</head>
<body>
    <div class="container">
        <div class="language-switcher">
            <button class="lang-btn" onclick="switchLanguage()">
                <i class="fas fa-language"></i>
                <?= $current_lang === 'en' ? 'العربية' : 'English' ?>
            </button>
        </div>
        <div class="header-row">
            <img src="shjmunlogo.png?v=<?= time() ?>" alt="<?= t('logo_alt', $translations) ?>" class="logo" />
            <div class="titles">
                <div class="main-title"><?= t('municipality_title', $translations) ?></div>
                <div class="sub-title"><?= t('shelter_title', $translations) ?></div>
            </div>
        </div>
        <!-- Complaints Section -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-bullhorn"></i>
                <?= t('complaints_section', $translations) ?>
            </div>
            <div class="icons-grid">
                <a href="/health_vet/public/add_Complaints.html" class="icon-item">
                    <i class="fas fa-bullhorn"></i>
                    <span class="icon-label"><?= t('complaints', $translations) ?></span>
                </a>
                <?php if ($has_complaints_manager_rights): ?>
                <a href="/health_vet/public/admin_complaints_dashboard.html" class="icon-item">
                    <i class="fas fa-chart-bar"></i>
                    <span class="icon-label"><?= t('complaints_dashboard', $translations) ?></span>
                </a>
                <?php endif; ?>
                <?php if ($has_follow_up_complaints_rights): ?>
                <a href="/health_vet/public/user_complaints.html" class="icon-item">
                    <i class="fas fa-tasks"></i>
                    <span class="icon-label"><?= t('follow_up_complaints', $translations) ?></span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <!-- Animal Section -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-paw"></i>
                <?= t('animal_section', $translations) ?>
            </div>
            <div class="icons-grid">
                <a href="/health_vet/public/add_animals.html" class="icon-item">
                    <i class="fas fa-plus-circle"></i>
                    <span class="icon-label"><?= t('add_edit_animal', $translations) ?></span>
                </a>
                <a href="/health_vet/public/add_room.html" class="icon-item">
                    <i class="fas fa-door-open"></i>
                    <span class="icon-label"><?= t('add_edit_rooms', $translations) ?></span>
                </a>
                <a href="/health_vet/public/animal_building.html" class="icon-item">
                    <i class="fas fa-warehouse"></i>
                    <span class="icon-label"><?= t('view_buildings', $translations) ?></span>
                </a>
                <a href="/health_vet/public/animal_movements.html" class="icon-item">
                    <i class="fas fa-route"></i>
                    <span class="icon-label"><?= t('animal_movements', $translations) ?></span>
                </a>
                <?php if ($is_admin): ?>
                <a href="/health_vet/public/add_adopter.html" class="icon-item">
                    <i class="fas fa-house-user"></i>
                    <span class="icon-label"><?= t('adoption_section', $translations) ?></span>
                </a>
                <?php endif; ?>
                <a href="/health_vet/public/animal_cards.html" class="icon-item">
                    <i class="fas fa-list-alt"></i>
                    <span class="icon-label"><?= t('general_animal_view', $translations) ?></span>
                </a>
            </div>
        </div>
        <!-- Employee Section -->
        <?php if ($has_super_admin_rights): ?>
        <div class="section">
            <div class="section-title">
                <i class="fas fa-users-cog"></i>
                <?= t('employee_section', $translations) ?>
            </div>
            <div class="icons-grid">
                <a href="employee_form.php" class="icon-item">
                    <i class="fas fa-user-plus"></i>
                    <span class="icon-label"><?= t('add_employee', $translations) ?></span>
                </a>
                <a href="edit_employee_form.php" class="icon-item">
                    <i class="fas fa-user-edit"></i>
                    <span class="icon-label"><?= t('edit_employees', $translations) ?></span>
                </a>
                <a href="work_mod_form.php" class="icon-item">
                    <i class="fas fa-briefcase"></i>
                    <span class="icon-label"><?= t('daily_work', $translations) ?></span>
                </a>
                <a href="leave/leave_requests.php" class="icon-item">
                    <i class="fas fa-plane-departure"></i>
                    <span class="icon-label"><?= t('leave_requests', $translations) ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>
        <!-- Admin Dashboard Section -->
        <?php if ($is_admin): ?>
        <div class="section">
            <div class="section-title">
                <i class="fas fa-tachometer-alt"></i>
                <?= t('admin_dashboard_section', $translations) ?>
            </div>
            <div class="icons-grid">
                <a href="/health_vet/public/dashboard.html" class="icon-item">
                    <i class="fas fa-chart-line"></i>
                    <span class="icon-label"><?= t('dashboard', $translations) ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>
        <!-- Warehouse Section -->
        <?php if ($has_warehouse_rights): ?>
        <div class="section">
            <div class="section-title">
                <i class="fas fa-warehouse"></i>
                <?= t('warehouse_section', $translations) ?>
            </div>
            <div class="icons-grid">
                <a href="/health_vet/public/inventory.html" class="icon-item">
                    <i class="fas fa-boxes"></i>
                    <span class="icon-label"><?= t('inventory_management', $translations) ?></span>
                </a>
                <a href="/health_vet/public/products.html" class="icon-item">
                    <i class="fas fa-list"></i>
                    <span class="icon-label"><?= t('products', $translations) ?></span>
                </a>
                <a href="/health_vet/public/add_Products.html" class="icon-item">
                    <i class="fas fa-plus-square"></i>
                    <span class="icon-label"><?= t('add_products', $translations) ?></span>
                </a>
                <a href="/health_vet/public/attributes.html" class="icon-item">
                    <i class="fas fa-tags"></i>
                    <span class="icon-label"><?= t('attributes', $translations) ?></span>
                </a>
                <a href="/health_vet/public/alerts.html" class="icon-item">
                    <i class="fas fa-bell"></i>
                    <span class="icon-label"><?= t('alerts', $translations) ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>
        <!-- Clinic Visit Section -->
        <?php if ($has_clinic_rights): ?>
        <div class="section">
            <div class="section-title">
                <i class="fas fa-stethoscope"></i>
                <?= t('clinic_visit_section', $translations) ?>
            </div>
            <div class="icons-grid">
                <a href="/health_vet/public/add_visit.html" class="icon-item">
                    <i class="fas fa-calendar-plus"></i>
                    <span class="icon-label"><?= t('add_visit', $translations) ?></span>
                </a>
                <a href="/health_vet/public/clinc_dashboard.html" class="icon-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="icon-label"><?= t('clinic_dashboard', $translations) ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>
        <!-- Customer Service Section -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-headset"></i>
                <?= t('customer_service_section', $translations) ?>
            </div>
            <div class="icons-grid">
                <a href="/health_vet/public/add_visitors.html" class="icon-item">
                    <i class="fas fa-users"></i>
                    <span class="icon-label"><?= t('add_visitors', $translations) ?></span>
                </a>
                <a href="/health_vet/public/add_visitor_interactions.html" class="icon-item">
                    <i class="fas fa-handshake"></i>
                    <span class="icon-label"><?= t('visitor_interactions', $translations) ?></span>
                </a>
                <a href="/health_vet/public/add_applications.html" class="icon-item">
                    <i class="fas fa-file-alt"></i>
                    <span class="icon-label"><?= t('adoption_applications', $translations) ?></span>
                </a>
                <a href="/health_vet/public/incoming_animal_requests.html" class="icon-item">
                    <i class="fas fa-hand-holding-heart"></i>
                    <span class="icon-label"><?= t('surrender_requests', $translations) ?></span>
                </a>
                <a href="/health_vet/public/send_reports.html" class="icon-item">
                    <i class="fas fa-paper-plane"></i>
                    <span class="icon-label"><?= t('send_reports', $translations) ?></span>
                </a>
                <a href="/health_vet/public/Declarations.html" class="icon-item">
                    <i class="fas fa-file-contract"></i>
                    <span class="icon-label"><?= t('declarations', $translations) ?></span>
                </a>
            </div>
        </div>
        <div class="user-footer">
            <div class="welcome-section">
                <p class="welcome-text"><?= t('welcome', $translations) ?> <strong><?= htmlspecialchars($user['EmpName']) ?></strong></p>
                <div class="datetime">
                    <div class="date">
                        <i class="far fa-calendar-alt"></i>
                        <span><?= $current_date ?></span>
                    </div>
                    <div class="time">
                        <i class="far fa-clock"></i>
                        <span class="time-updater" id="live-time"><?= $current_time ?></span>
                    </div>
                </div>
            </div>
        
            <div class="user-actions">
                <a href="change_password.php" class="user-action-btn" title="<?= t('change_password', $translations) ?>">
                    <i class="fas fa-key"></i>
                </a>
                <a href="logout.php" class="user-action-btn" title="<?= t('logout', $translations) ?>">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>
    <script>
        // Language switching function
        function switchLanguage() {
            const currentLang = '<?= $current_lang ?>';
            const newLang = currentLang === 'en' ? 'ar' : 'en';
            window.location.href = `?lang=${newLang}`;
        }
        // Live Time Update
        function updateLiveTime() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
            const liveTimeElement = document.getElementById('live-time');
            if (liveTimeElement) {
                liveTimeElement.textContent = timeStr;
            }
        }
        setInterval(updateLiveTime, 1000);
        updateLiveTime();
        // Enhanced Click Handling for Smooth Navigation
        document.querySelectorAll('.icon-item, .user-action-btn').forEach(item => {
            item.addEventListener('click', function(e) {
                if (this.href) {
                    e.preventDefault();
                    const target = this.closest('.icon-item') || this;
                    target.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        window.location.href = this.href;
                    }, 150);
                }
            });
        });
    
        // General fix for faster clicks on mobile devices
        document.addEventListener('touchstart', function() {}, false);
    </script>
</body>
</html>