<?php
// يجب أن يكون هذا الكود أول شيء في الملف
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['user'];
$isAdmin = $user['IsAdmin'] == 1;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم الدوام</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --color-green: #2b7a78;
            --color-white: #fff;
            --color-beige: #e5e3c9;
            --color-light-green: #d1e0e0;
        }

        body { 
            font-family: 'Tajawal', sans-serif; 
            background-color: var(--color-beige); 
            color: var(--color-green); 
            direction: rtl; 
            text-align: right; 
            margin: 0; 
            padding: 0; 
        }
        .container { 
            max-width: 95%; 
            margin: 2vw auto; 
            background-color: var(--color-white); 
            padding: 2%; 
            border-radius: 12px; 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); 
        }
        header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 2%; 
            padding-bottom: 1%; 
            border-bottom: 2px solid var(--color-green); 
        }
        .logo { 
            width: clamp(80px, 8vw, 100px); 
            height: auto; 
        }
        .header-titles { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            flex-grow: 1;
        }
        h1, h2 { 
            margin: 0.3rem 0; 
            color: var(--color-green); 
        }
        h1 { 
            font-size: clamp(1rem, 2vw, 1.2rem); 
        }
        h2.title-centered { 
            font-size: clamp(1.1rem, 2.2vw, 1.3rem); 
            text-align: center; 
            width: 100%; 
            font-weight: 700; 
            margin-top: 0.5rem;
        }
        
        .controls { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 1rem; 
            justify-content: center; 
            align-items: flex-end; 
            margin-bottom: 2%; 
            background-color: var(--color-beige);
            padding: 1.5rem;
            border-radius: 10px;
            border: 1px solid var(--color-green);
        }
        .filter-group { 
            display: flex; 
            flex-direction: column; 
            flex: 1 1 150px; 
            max-width: 200px; 
        }
        .filter-header {
            cursor: pointer;
            background-color: var(--color-light-green);
            padding: 0.5rem;
            border-radius: 5px;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .filter-content {
            display: none;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--color-green);
            padding: 0.5rem;
            background-color: var(--color-white);
            border-radius: 5px;
            position: relative;
        }
        .filter-content.show {
            display: block;
        }
        .search-input {
            width: 100%;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        label { 
            margin-bottom: 0.5rem; 
            font-weight: bold; 
            color: var(--color-green); 
            font-size: clamp(0.9rem, 1.8vw, 1rem); 
        }
        input[type="date"], select { 
            padding: 0.8rem; 
            border: 1px solid var(--color-green); 
            border-radius: 5px; 
            font-size: clamp(0.9rem, 1.8vw, 1rem); 
            color: var(--color-green); 
            background-color: var(--color-white); 
            transition: all 0.3s; 
        }
        input[type="date"]:focus, select:focus { 
            border-color: #216361; 
            outline: none; 
            box-shadow: 0 0 0 2px var(--color-light-green);
        }
        .checkbox-label {
            display: block;
            margin-bottom: 0.5rem;
            padding: 0.3rem;
            border-radius: 3px;
            transition: background-color 0.2s;
        }
        .checkbox-label:hover {
            background-color: #f0f0f0;
        }
        .buttons-container {
            display: flex;
            gap: 1rem;
            flex: 1 1 100%;
            justify-content: center;
            margin-top: 1rem;
        }
        button { 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 0.5rem; 
            padding: 0.8rem 1.5rem; 
            background-color: var(--color-green); 
            color: var(--color-white); 
            border: none; 
            border-radius: 25px; 
            font-size: clamp(0.9rem, 1.8vw, 1rem); 
            cursor: pointer; 
            transition: all 0.3s; 
            flex: 0 1 auto;
            min-width: 140px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        button:hover { 
            background-color: #216361; 
            transform: translateY(-2px); 
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        button.search-btn {
            background-color: var(--color-green);
        }
        button.print-btn {
            background-color: var(--color-beige);
            color: var(--color-green);
            border: 1px solid var(--color-green);
        }
        button.print-btn:hover {
            background-color: var(--color-green);
            color: var(--color-white);
        }
        button i { 
            font-size: clamp(1rem, 2vw, 1.2rem); 
        }
        
        .results { 
            margin-top: 2%; 
        }
        .sector-card { 
            border: 1px solid var(--color-green); 
            border-radius: 8px; 
            padding: 2%; 
            margin-bottom: 2%; 
            background-color: var(--color-beige); 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); 
        }
        .sector-header { 
            font-size: clamp(1.2rem, 2.5vw, 1.5rem); 
            font-weight: bold; 
            color: var(--color-green); 
            border-bottom: 2px solid var(--color-green); 
            padding-bottom: 1%; 
            margin-bottom: 2%; 
        }
        .employee-table, .schedule-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 2%; 
            background-color: var(--color-white); 
            font-size: clamp(0.8rem, 1.6vw, 0.9rem); 
        }
        .employee-table th, .employee-table td, .schedule-table th, .schedule-table td { 
            border: 1px solid var(--color-green); 
            padding: 0.8rem; 
            text-align: right; 
        }
        .employee-table th, .schedule-table th { 
            background-color: var(--color-beige); 
            color: var(--color-green); 
            font-weight: bold; 
            font-size: clamp(0.9rem, 1.8vw, 1rem); 
        }
        .employee-table tr:nth-child(even), .schedule-table tr:nth-child(even) { 
            background-color: #f5f5e8; 
        }
        .status-work { 
            background-color: var(--color-light-green); 
            color: var(--color-green);
        }
        .status-rest { 
            background-color: #f8e4e4; 
            color: #c0392b;
        }
        .status-leave { 
            background-color: #fff5d9; 
            color: #e67e22;
        }
        .error { 
            color: #c0392b; 
            text-align: center; 
            margin-top: 2%; 
            font-size: clamp(0.9rem, 1.8vw, 1rem); 
        }
        
        @media (max-width: 768px) {
            .container { 
                padding: 3%; 
                margin: 1% auto; 
            }
            .controls { 
                flex-direction: column; 
                align-items: stretch;
                padding: 1rem;
            }
            .filter-group { 
                flex: 1 1 100%; 
                max-width: 100%; 
            }
            .logo { 
                width: clamp(70px, 7vw, 90px); 
            }
            h1 { 
                font-size: clamp(0.9rem, 1.8vw, 1.1rem); 
            }
            h2.title-centered { 
                font-size: clamp(1rem, 2vw, 1.2rem); 
            }
            .employee-table, .schedule-table { 
                display: block; 
                overflow-x: auto; 
            }
            .sector-header { 
                font-size: clamp(1rem, 2.2vw, 1.3rem); 
            }
            button { 
                padding: 0.7rem 1.2rem; 
                min-width: 120px;
            }
            .buttons-container {
                flex-direction: column;
                gap: 0.8rem;
            }
        }
        
        @media (max-width: 480px) {
            .container { 
                padding: 4%; 
            }
            .logo { 
                width: clamp(60px, 6vw, 80px); 
            }
            h1 { 
                font-size: clamp(0.8rem, 1.6vw, 1rem); 
            }
            h2.title-centered { 
                font-size: clamp(0.9rem, 1.8vw, 1.1rem); 
            }
            .employee-table th, .employee-table td, 
            .schedule-table th, .schedule-table td { 
                padding: 0.5rem; 
                font-size: clamp(0.7rem, 1.5vw, 0.8rem); 
            }
            .sector-card { 
                padding: 1.5%; 
            }
            button { 
                padding: 0.6rem 1rem; 
                min-width: 100px;
            }
        }

        @media print {
            body { background-color: var(--color-white); }
            .container { 
                box-shadow: none; 
                border: none; 
                padding: 0; 
                margin: 0; 
                width: 100%; 
            }
            .controls, #print-button { display: none; }
            .sector-card { 
                background-color: var(--color-white); 
                border: 1px solid var(--color-green); 
                page-break-inside: avoid; 
                margin-bottom: 2%; 
            }
            .employee-table, .schedule-table { page-break-inside: auto; }
            .employee-table td, .schedule-table td { background-color: var(--color-white) !important; }
            h1, h2 { color: var(--color-green); }
            .sector-header { border-bottom: 1px solid var(--color-green); }
            .logo { width: 70px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-titles">
                <h1>إدارة الرقابة والسلامة الصحية</h1>
                <h2 class="title-centered">لوحة تحكم الدوام</h2>
            </div>
            <img src="shjmunlogo.png" alt="شعار الهيئة" class="logo">
        </header>
        
        <div class="controls">
            <div class="filter-group">
                <label for="start_date"><i class="fas fa-calendar-alt"></i> تاريخ البداية</label>
                <input type="date" id="start_date">
            </div>
            <div class="filter-group">
                <label for="end_date"><i class="fas fa-calendar-alt"></i> تاريخ النهاية</label>
                <input type="date" id="end_date">
            </div>
            <div class="filter-group">
                <div class="filter-header" onclick="toggleFilter(this)"><i class="fas fa-building"></i> القطاع <i class="fas fa-chevron-down"></i></div>
                <div class="filter-content" id="sector_name_content">
                    <input type="text" class="search-input" placeholder="ابحث عن قطاع..." onkeyup="filterOptions('sector_name_content', this.value)">
                    <!-- سيتم ملؤها عبر JS -->
                </div>
            </div>
            <div class="filter-group">
                <div class="filter-header" onclick="toggleFilter(this)"><i class="fas fa-clock"></i> نوع الشفت <i class="fas fa-chevron-down"></i></div>
                <div class="filter-content" id="shift_type_content">
                    <input type="text" class="search-input" placeholder="ابحث عن نوع شفت..." onkeyup="filterOptions('shift_type_content', this.value)">
                    <label class="checkbox-label"><input type="checkbox" value="صباحي"> صباحي</label>
                    <label class="checkbox-label"><input type="checkbox" value="مسائي"> مسائي</label>
                </div>
            </div>
            <div class="filter-group">
                <div class="filter-header" onclick="toggleFilter(this)"><i class="fas fa-user-clock"></i> حالة الدوام <i class="fas fa-chevron-down"></i></div>
                <div class="filter-content" id="work_status_content">
                    <input type="text" class="search-input" placeholder="ابحث عن حالة دوام..." onkeyup="filterOptions('work_status_content', this.value)">
                    <label class="checkbox-label"><input type="checkbox" value="Work"> عمل</label>
                    <label class="checkbox-label"><input type="checkbox" value="Rest"> راحة</label>
                    <label class="checkbox-label"><input type="checkbox" value="Leave"> إجازة</label>
                </div>
            </div>
            <div class="filter-group">
                <div class="filter-header" onclick="toggleFilter(this)"><i class="fas fa-user"></i> اسم الموظف <i class="fas fa-chevron-down"></i></div>
                <div class="filter-content" id="employee_name_content">
                    <input type="text" class="search-input" placeholder="ابحث عن موظف..." onkeyup="filterOptions('employee_name_content', this.value)">
                    <!-- سيتم ملؤها عبر JS -->
                </div>
            </div>
            <div class="filter-group">
                <div class="filter-header" onclick="toggleFilter(this)"><i class="fas fa-id-card"></i> الرقم الإداري <i class="fas fa-chevron-down"></i></div>
                <div class="filter-content" id="employee_id_content">
                    <input type="text" class="search-input" placeholder="ابحث عن رقم إداري..." onkeyup="filterOptions('employee_id_content', this.value)">
                    <!-- سيتم ملؤها عبر JS -->
                </div>
            </div>
            
            <div class="buttons-container">
                <button class="search-btn" onclick="fetchData()"><i class="fas fa-search"></i> بحث</button>
                <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> طباعة PDF</button>
            </div>
        </div>
        
        <div class="results" id="results">
        </div>
    </div>
    
    <script>
        // متغيرات لتخزين البيانات الأصلية
        let allSectors = [];
        let allEmployees = [];
        let allEmployeeIds = [];

        document.addEventListener('DOMContentLoaded', function() {
            fetchSectors();
            fetchEmployees();
            
            <?php if (!$isAdmin): ?>
                // للموظفين غير المسؤولين: تعطيل الفلاتر وتحديد قيم افتراضية
                document.getElementById('sector_name_content').innerHTML = '<label class="checkbox-label"><input type="checkbox" value="<?php echo htmlspecialchars($user['SectorName']); ?>" checked disabled> <?php echo htmlspecialchars($user['SectorName']); ?></label>';
                document.getElementById('employee_name_content').innerHTML = '<label class="checkbox-label"><input type="checkbox" value="<?php echo htmlspecialchars($user['EmpName']); ?>" checked disabled> <?php echo htmlspecialchars($user['EmpName']); ?></label>';
                document.getElementById('employee_id_content').innerHTML = '<label class="checkbox-label"><input type="checkbox" value="<?php echo htmlspecialchars($user['EmpID']); ?>" checked disabled> <?php echo htmlspecialchars($user['EmpID']); ?></label>';
                
                // إخفاء حقول البحث للموظفين غير المسؤولين
                document.querySelectorAll('.search-input').forEach(input => {
                    input.style.display = 'none';
                });
                
                // إخفاء رأس الفلتر لمنع التعديل
                document.querySelectorAll('.filter-header').forEach(header => {
                    header.style.cursor = 'not-allowed';
                    header.style.opacity = '0.7';
                });
            <?php endif; ?>
        });

        function toggleFilter(header) {
            <?php if (!$isAdmin): ?>
                return; // منع التعديل للموظفين غير المسؤولين
            <?php endif; ?>
            
            const content = header.nextElementSibling;
            content.classList.toggle('show');
        }

        function filterOptions(containerId, searchText) {
            const container = document.getElementById(containerId);
            const labels = container.querySelectorAll('.checkbox-label');
            const searchLower = searchText.toLowerCase();
            
            labels.forEach(label => {
                const text = label.textContent.toLowerCase();
                if (text.includes(searchLower)) {
                    label.style.display = 'block';
                } else {
                    label.style.display = 'none';
                }
            });
        }

        function fetchSectors() {
            fetch('get_filters.php?type=sectors')
                .then(response => response.json())
                .then(data => {
                    allSectors = data.sectors || [];
                    updateSectorFilter();
                })
                .catch(error => console.error('Error fetching sectors:', error));
        }

        function fetchEmployees() {
            const url = `get_filters.php?type=employees`;

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    allEmployees = data.employees || [];
                    updateEmployeeFilter();
                    updateEmployeeIdFilter();
                })
                .catch(error => console.error('Error fetching employees:', error));
        }

        function updateSectorFilter() {
            <?php if ($isAdmin): ?>
                const content = document.getElementById('sector_name_content');
                const searchInput = content.querySelector('.search-input');
                const searchValue = searchInput ? searchInput.value : '';
                
                // حفظ القيم المختارة حالياً
                const selectedValues = Array.from(content.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
                
                content.innerHTML = '<input type="text" class="search-input" placeholder="ابحث عن قطاع..." onkeyup="filterOptions(\'sector_name_content\', this.value)">';
                
                allSectors.forEach(sector => {
                    if (sector.SectorName.toLowerCase().includes(searchValue.toLowerCase())) {
                        const isChecked = selectedValues.includes(sector.SectorName);
                        content.innerHTML += `<label class="checkbox-label"><input type="checkbox" value="${sector.SectorName}" ${isChecked ? 'checked' : ''} onchange="handleSectorChange()"> ${sector.SectorName}</label>`;
                    }
                });
            <?php endif; ?>
        }

        function updateEmployeeFilter() {
            <?php if ($isAdmin): ?>
                const content = document.getElementById('employee_name_content');
                const searchInput = content.querySelector('.search-input');
                const searchValue = searchInput ? searchInput.value : '';
                
                // حفظ القيم المختارة حالياً
                const selectedValues = Array.from(content.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
                
                content.innerHTML = '<input type="text" class="search-input" placeholder="ابحث عن موظف..." onkeyup="filterOptions(\'employee_name_content\', this.value)">';
                
                // فلترة الموظفين حسب البحث
                allEmployees.forEach(employee => {
                    if (employee.EmpName.toLowerCase().includes(searchValue.toLowerCase())) {
                        const isChecked = selectedValues.includes(employee.EmpName);
                        content.innerHTML += `<label class="checkbox-label"><input type="checkbox" value="${employee.EmpName}" ${isChecked ? 'checked' : ''} onchange="handleEmployeeChange()"> ${employee.EmpName}</label>`;
                    }
                });
            <?php endif; ?>
        }

        function updateEmployeeIdFilter() {
            <?php if ($isAdmin): ?>
                const content = document.getElementById('employee_id_content');
                const searchInput = content.querySelector('.search-input');
                const searchValue = searchInput ? searchInput.value : '';
                
                // حفظ القيم المختارة حالياً
                const selectedValues = Array.from(content.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
                
                content.innerHTML = '<input type="text" class="search-input" placeholder="ابحث عن رقم إداري..." onkeyup="filterOptions(\'employee_id_content\', this.value)">';
                
                // فلترة الأرقام الإدارية حسب البحث
                allEmployees.forEach(employee => {
                    if (employee.EmpID.toString().includes(searchValue)) {
                        const isChecked = selectedValues.includes(employee.EmpID.toString());
                        content.innerHTML += `<label class="checkbox-label"><input type="checkbox" value="${employee.EmpID}" ${isChecked ? 'checked' : ''} onchange="handleEmployeeIdChange()"> ${employee.EmpID}</label>`;
                    }
                });
            <?php endif; ?>
        }

        function handleSectorChange() {
            // عند تغيير اختيار القطاع، لا تغيير تلقائي للفلاتر الأخرى
            // يمكن إضافة منطق إضافي هنا إذا لزم الأمر
        }

        function handleEmployeeChange() {
            // عند تغيير اختيار الموظف، لا تغيير تلقائي للفلاتر الأخرى
            // يمكن إضافة منطق إضافي هنا إذا لزم الأمر
        }

        function handleEmployeeIdChange() {
            // عند تغيير اختيار الرقم الإداري، لا تغيير تلقائي للفلاتر الأخرى
            // يمكن إضافة منطق إضافي هنا إذا لزم الأمر
        }

        function fetchData() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            const sectorNames = Array.from(document.querySelectorAll('#sector_name_content input:checked')).map(input => input.value);
            const employeeNames = Array.from(document.querySelectorAll('#employee_name_content input:checked')).map(input => input.value);
            const employeeIds = Array.from(document.querySelectorAll('#employee_id_content input:checked')).map(input => input.value);
            const shiftTypes = Array.from(document.querySelectorAll('#shift_type_content input:checked')).map(input => input.value);
            const workStatuses = Array.from(document.querySelectorAll('#work_status_content input:checked')).map(input => input.value);
            
            if (!startDate || !endDate) {
                alert('الرجاء اختيار تاريخ البداية والنهاية.');
                return;
            }

            let url = `calculate.php?start_date=${startDate}&end_date=${endDate}`;
            
            <?php if (!$isAdmin): ?>
                // للموظفين غير المسؤولين: إرسال بياناتهم فقط
                url += `&employee_name=<?php echo htmlspecialchars($user['EmpName']); ?>`;
                url += `&sector_name=<?php echo htmlspecialchars($user['SectorName']); ?>`;
                url += `&employee_id=<?php echo htmlspecialchars($user['EmpID']); ?>`;
            <?php else: ?>
                // للمسؤولين: إرسال الفلاتر المختارة
                if (sectorNames.length > 0) {
                    url += `&sector_name=${sectorNames.map(encodeURIComponent).join(',')}`;
                }
                if (employeeNames.length > 0) {
                    url += `&employee_name=${employeeNames.map(encodeURIComponent).join(',')}`;
                }
                if (employeeIds.length > 0) {
                    url += `&employee_id=${employeeIds.join(',')}`;
                }
            <?php endif; ?>
            
            if (shiftTypes.length > 0) {
                url += `&shift_type=${shiftTypes.map(encodeURIComponent).join(',')}`;
            }
            if (workStatuses.length > 0) {
                url += `&work_status=${workStatuses.map(encodeURIComponent).join(',')}`;
            }

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    const resultsDiv = document.getElementById('results');
                    resultsDiv.innerHTML = '';
                    if (data.error) {
                        resultsDiv.innerHTML = `<p class="error">${data.error}</p>`;
                        return;
                    }

                    if (data.employees && data.employees.length > 0) {
                        const sectors = {};
                        data.employees.forEach(employee => {
                            const sectorName = employee.SectorName;
                            if (!sectors[sectorName]) {
                                sectors[sectorName] = {
                                    employees: [],
                                    workDays: employee.work_days,
                                    restDays: employee.rest_days,
                                    leaveDays: employee.leave_days,
                                    morningShifts: employee.morning_shifts,
                                    eveningShifts: employee.evening_shifts
                                };
                            }
                            sectors[sectorName].employees.push(employee);
                        });

                        for (const sectorName in sectors) {
                            const sectorData = sectors[sectorName];

                            const sectorCard = document.createElement('div');
                            sectorCard.className = 'sector-card';
                            sectorCard.innerHTML = `
                                <div class="sector-header">
                                    ${sectorName}
                                    <p style="font-size: clamp(0.8rem, 1.6vw, 0.9rem); font-weight: normal; color: var(--color-green);">
                                        عدد أيام العمل: ${sectorData.workDays} | عدد أيام الراحة: ${sectorData.restDays} | عدد أيام الإجازة: ${sectorData.leaveDays}
                                        <br>
                                        شفت صباحي: ${sectorData.morningShifts} | شفت مسائي: ${sectorData.eveningShifts}
                                    </p>
                                </div>
                            `;

                            const employeeTable = document.createElement('table');
                            employeeTable.className = 'employee-table';
                            employeeTable.innerHTML = `
                                <thead>
                                    <tr>
                                        <th>اسم الموظف</th>
                                        <th>الرقم الإداري</th>
                                        <th>رقم القطاع</th>
                                        <th>الإدارة</th>
                                        <th>القسم</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            `;
                            const employeeTbody = employeeTable.querySelector('tbody');
                            sectorData.employees.forEach(employee => {
                                const row = document.createElement('tr');
                                row.innerHTML = `
                                    <td>${employee.EmpName}</td>
                                    <td>${employee.EmpID}</td>
                                    <td>${employee.SectorID}</td>
                                    <td>${employee.Department}</td>
                                    <td>${employee.Division}</td>
                                `;
                                employeeTbody.appendChild(row);
                            });
                            sectorCard.appendChild(employeeTable);
                            
                            const scheduleTable = document.createElement('table');
                            scheduleTable.className = 'schedule-table';
                            scheduleTable.innerHTML = `
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>اليوم</th>
                                        <th>أسماء الموظفين</th>
                                        <th>حالة الدوام</th>
                                        <th>الشفت</th>
                                        <th>مسئول القطاع</th>
                                        <th>ساعات العمل</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            `;
                            const scheduleTbody = scheduleTable.querySelector('tbody');

                            // Collect unique dates and group schedules
                            const scheduleGroups = {};
                            sectorData.employees.forEach(employee => {
                                const dailySchedule = data.daily_schedules[employee.EmpID];
                                if (dailySchedule) {
                                    dailySchedule.forEach(day => {
                                        const key = `${day.date}|${day.is_working ? 'Work' : (day.is_on_leave ? 'Leave' : 'Rest')}|${day.shift || ''}|${day.current_manager_name || ''}`;
                                        if (!scheduleGroups[day.date]) {
                                            scheduleGroups[day.date] = {};
                                        }
                                        if (!scheduleGroups[day.date][key]) {
                                            scheduleGroups[day.date][key] = {
                                                day_name: day.day_name,
                                                status: day.is_working ? 'عمل' : (day.is_on_leave ? 'إجازة' : 'راحة'),
                                                shift: day.shift || '',
                                                managerName: day.current_manager_name || '',
                                                employees: [],
                                                workHours: ''
                                            };
                                            if (day.shift === 'صباحي' || day.shift === 'مسائي') {
                                                const shiftHours = employee.ShiftHours.split(',');
                                                scheduleGroups[day.date][key].workHours = day.shift === 'صباحي' ? shiftHours[0] : (shiftHours[1] || shiftHours[0]);
                                                scheduleGroups[day.date][key].workHours = scheduleGroups[day.date][key].workHours.replace(/(\d{2}):(\d{2})-(\d{2}):(\d{2})/, '$1:$2 ص - $3:$4 م');
                                            }
                                        }
                                        scheduleGroups[day.date][key].employees.push(employee.EmpName);
                                    });
                                }
                            });

                            // Sort dates and populate schedule table
                            const sortedDates = Object.keys(scheduleGroups).sort();
                            sortedDates.forEach(date => {
                                Object.values(scheduleGroups[date]).forEach(group => {
                                    const row = document.createElement('tr');
                                    const statusClass = group.status === 'عمل' ? 'status-work' : (group.status === 'إجازة' ? 'status-leave' : 'status-rest');
                                    row.innerHTML = `
                                        <td>${date}</td>
                                        <td>${group.day_name}</td>
                                        <td>${group.employees.join(', ')}</td>
                                        <td class="${statusClass}">${group.status}</td>
                                        <td>${group.shift}</td>
                                        <td>${group.managerName}</td>
                                        <td>${group.workHours}</td>
                                    `;
                                    scheduleTbody.appendChild(row);
                                });
                            });

                            sectorCard.appendChild(scheduleTable);
                            resultsDiv.appendChild(sectorCard);
                        }
                    } else {
                        resultsDiv.innerHTML = `<p class="error">لا توجد نتائج مطابقة.</p>`;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    document.getElementById('results').innerHTML = `<p class="error">حدث خطأ أثناء جلب البيانات. الرجاء المحاولة مرة أخرى.</p>`;
                });
        }

        // إضافة event listeners لحقول البحث
        document.addEventListener('DOMContentLoaded', function() {
            // تحديث الفلاتر عند الكتابة في حقول البحث
            document.querySelectorAll('.search-input').forEach(input => {
                input.addEventListener('input', function() {
                    const containerId = this.parentElement.id;
                    const searchValue = this.value;
                    
                    if (containerId === 'sector_name_content') {
                        updateSectorFilter();
                    } else if (containerId === 'employee_name_content') {
                        updateEmployeeFilter();
                    } else if (containerId === 'employee_id_content') {
                        updateEmployeeIdFilter();
                    } else {
                        filterOptions(containerId, searchValue);
                    }
                });
            });
        });
    </script>
</body>
</html>