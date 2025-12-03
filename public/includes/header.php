<?php
// header.php
$page_title = $page_title ?? 'حيوانات للتبني - مركز الرعاية البيطرية';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-green: #28a745;
            --secondary-beige: #f5f5dc;
            --background-white: #ffffff;
            --text-dark: #343a40;
            --border-color: #ced4da;
            --light-gray: #f8f9fa;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.1);
            --cat-color: #ff9ff3;
            --dog-color: #54a0ff;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Tahoma', sans-serif;
            background-color: var(--light-gray);
            color: var(--text-dark);
            line-height: 1.6;
        }
        
        body[dir="ltr"] {
            font-family: 'Arial', sans-serif;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        /* الهيدر */
        .header {
            background: linear-gradient(135deg, var(--primary-green) 0%, #1e7e34 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .page-title {
            font-size: 2.2em;
            margin-bottom: 10px;
        }
        
        .page-subtitle {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1em;
        }
        
        .contact-icon {
            background: white;
            color: var(--primary-green);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2em;
        }
        
        /* زر تبديل اللغة */
        .language-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }
        
        .language-toggle button {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            .page-title {
                font-size: 1.8em;
            }
        }
    </style>
</head>
<body>
    <!-- زر تبديل اللغة -->
    <div class="language-toggle">
        <button id="lang-toggle">EN</button>
    </div>
    
    <!-- الهيدر -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <div class="header-text">
                    <h1 class="page-title">حيوانات للتبني</h1>
                    <p class="page-subtitle">مركز الرعاية البيطرية - امنح حيوانًا أليفًا منزلاً دافئًا</p>
                </div>
                <div class="contact-info">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <span>9715597403340</span>
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <span>zedanmahmoud99@gmail.com</span>
                    </div>
                </div>
            </div>
        </div>
    </header>