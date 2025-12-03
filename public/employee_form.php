<?php
  // employee_form.php
  // تفعيل عرض الأخطاء أثناء التطوير
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
  if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
  }
  // التحقق من تسجيل الدخول
  if (!isset($_SESSION['user'])) {
      header("Location: login.php");
      exit;
  }
  $user = $_SESSION['user'];
  // التحقق من صلاحية الأدمن (هذا النموذج مخصص للمسؤولين فقط)
  if (empty($user['IsAdmin']) || $user['IsAdmin'] != 1) {
      header("Location: no_permission.php"); // صفحة لإظهار رسالة عدم الصلاحية
      exit;
  }
  // تضمين اتصال قاعدة البيانات
  require_once(__DIR__ . '/../api/db.php');
  $conn->set_charset("utf8mb4");
  $error = '';
  $success = '';
  $currentEmployee = null;
  $EmpID = $_GET['EmpID'] ?? ''; // المعرف من الـ URL للبحث
  // Placeholder arrays for Job Titles, Departments, and Divisions (in a real app, these would come from DB)
  $jobTitles = [
      'Head of Section',
      'Veterinarian',
      'Assistant Veterinarian',
      'Clerk Assistant',
      'Administrative Secretary',
      'Driver',
      'Administrative Coordinator',
      'Administrative Officer',
      'Daily Worker',
      'Shift Supervisor',
      'Daily Worker',
      'Clerk Assistant'
  ];
  $departments = ['قسم الرقابة البيئية', 'قسم الرقابة الغذائية', 'قسم الرقابة الصحية', 'قسم الرقابة البيطرية', 'قسم تنظيم ورقابة النفايات']; // أضف الشعب الخاصة بك هنا
  $divisions = [
      'وحدة ماوي الشارقة للقطط والكلاب',
      'وحدة التراخيص والتصاريح البيطرية',
      'وحدة المسلخ',
      'وحدة سوق الطيور',
      'شعبة الاستيراد والتصدير',
           'سوق الجبيل',
      'الشعبة الادارية'
  ];
  $leaveApprovals = ['Yes', 'No']; // قيم صلاحيات الموافقة على الإجازات
  // جلب جميع الموظفين للعرض في الجدول
  $employees = [];
  $search_filter = '';
  $filter_value = '';
  // معالجة الفلترة
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['filter'])) {
      $conditions = [];
   
      if (!empty($_GET['filter_empid'])) {
          $conditions[] = "EmpID = " . intval($_GET['filter_empid']);
      }
   
      if (!empty($_GET['filter_empname'])) {
          $conditions[] = "EmpName = '" . $conn->real_escape_string($_GET['filter_empname']) . "'";
      }
   
      if (!empty($_GET['filter_jobtitle'])) {
          $conditions[] = "JobTitle = '" . $conn->real_escape_string($_GET['filter_jobtitle']) . "'";
      }
   
      if (!empty($_GET['filter_department'])) {
          $conditions[] = "Department = '" . $conn->real_escape_string($_GET['filter_department']) . "'";
      }
   
      if (!empty($_GET['filter_division'])) {
          $conditions[] = "Division = '" . $conn->real_escape_string($_GET['filter_division']) . "'";
      }
   
      if (!empty($_GET['filter_leaveapproval'])) {
          $conditions[] = "LeaveApproval = '" . $conn->real_escape_string($_GET['filter_leaveapproval']) . "'";
      }
   
      if (!empty($conditions)) {
          $search_filter = " WHERE " . implode(" AND ", $conditions);
      }
  }
  $sql = "SELECT * FROM Users" . $search_filter . " ORDER BY EmpID ASC";
  $result = $conn->query($sql);
  if ($result && $result->num_rows > 0) {
      while ($row = $result->fetch_assoc()) {
          $employees[] = $row;
      }
  }
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
      $searchID = trim($_GET['EmpID_search'] ?? '');
      if (!empty($searchID)) {
          $EmpID = $searchID; // استخدام معرف البحث الجديد
      }
  }
  // --- معالجة حذف الموظف ---
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
      header('Content-Type: application/json');
      $EmpID_delete = trim($_POST['EmpID'] ?? '');
      if (empty($EmpID_delete)) {
          echo json_encode(['success' => false, 'msg' => 'معرف الموظف مطلوب للحذف.']);
          exit;
      }
      $emp_id_delete = intval($EmpID_delete);
      // جلب مسار الصورة لحذف الملف
      $stmt = $conn->prepare("SELECT profile_image FROM Users WHERE EmpID = ?");
      if ($stmt) {
          $stmt->bind_param("i", $emp_id_delete);
          $stmt->execute();
          $result = $stmt->get_result();
          if ($row = $result->fetch_assoc()) {
              if (!empty($row['profile_image']) && file_exists(__DIR__ . '/../' . $row['profile_image'])) {
                  unlink(__DIR__ . '/../' . $row['profile_image']);
              }
          }
          $stmt->close();
      }
      // حذف من قاعدة البيانات
      $stmt = $conn->prepare("DELETE FROM Users WHERE EmpID = ?");
      if ($stmt) {
          $stmt->bind_param("i", $emp_id_delete);
          if ($stmt->execute()) {
              echo json_encode(['success' => true, 'msg' => 'تم حذف الموظف بنجاح.']);
          } else {
              echo json_encode(['success' => false, 'msg' => 'خطأ أثناء الحذف: ' . $stmt->error]);
          }
          $stmt->close();
      } else {
          echo json_encode(['success' => false, 'msg' => 'خطأ في تحضير استعلام الحذف: ' . $conn->error]);
      }
      exit;
  }
  // --- معالجة حفظ البيانات (POST - إضافة أو تعديل) ---
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
      $EmpID_post_str = trim($_POST['EmpID'] ?? '');
      $emp_id_post = intval($EmpID_post_str);
      $EmpName = trim($_POST['EmpName'] ?? '');
      $JobTitle = trim($_POST['JobTitle'] ?? '');
      $Username = trim($_POST['Username'] ?? '');
      $Password = $_POST['Password'] ?? ''; // فارغة للتحديث
      $Department = trim($_POST['Department'] ?? '');
      $Division = trim($_POST['Division'] ?? '');
      $CanAdd = isset($_POST['CanAdd']) ? 1 : 0;
      $CanEdit = isset($_POST['CanEdit']) ? 1 : 0;
      $CanDelete = isset($_POST['CanDelete']) ? 1 : 0;
      $CanSendWhatsApp = isset($_POST['CanSendWhatsApp']) ? 1 : 0;
      $Active = isset($_POST['Active']) ? 1 : 0;
      $IsAdmin = isset($_POST['IsAdmin']) ? 1 : 0;
      $IsLicenseManager = isset($_POST['IsLicenseManager']) ? 1 : 0;
      $LeaveApproval = $_POST['LeaveApproval'] ?? 'No';
      $SectorID = !empty(trim($_POST['SectorID'] ?? '')) ? intval($_POST['SectorID']) : null;
      $Email = !empty(trim($_POST['Email'] ?? '')) ? trim($_POST['Email']) : null;
      $Phone = !empty(trim($_POST['Phone'] ?? '')) ? trim($_POST['Phone']) : null;
      $follow_up_complaints = isset($_POST['follow_up_complaints']) ? 1 : 0;
      $complaints_manager_rights = isset($_POST['complaints_manager_rights']) ? 1 : 0;
      $clinic_rights = isset($_POST['clinic_rights']) ? 1 : 0;
      $warehouse_rights = isset($_POST['warehouse_rights']) ? 1 : 0;
      $super_admin_rights = isset($_POST['super_admin_rights']) ? 1 : 0;
      $profile_image = null; // للصورة الجديدة
      // معالجة رفع الصورة
      if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
          $upload_dir = __DIR__ . '/../uploads/profile_images/';
          if (!is_dir($upload_dir)) {
              mkdir($upload_dir, 0755, true);
          }
          $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
          $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
          if (in_array(strtolower($file_extension), $allowed_extensions)) {
              $new_filename = $EmpID_post_str . '_' . time() . '.' . $file_extension;
              $upload_path = $upload_dir . $new_filename;
              if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                  $profile_image = '/health_vet/uploads/profile_images/' . $new_filename;
                  // حذف الصورة القديمة إذا كانت موجودة
                  if (!empty($currentEmployee['profile_image']) && file_exists(__DIR__ . '/../' . $currentEmployee['profile_image'])) {
                      unlink(__DIR__ . '/../' . $currentEmployee['profile_image']);
                  }
              } else {
                  $error = "خطأ في رفع الصورة.";
              }
          } else {
              $error = "نوع الملف غير مدعوم. يرجى رفع صورة (JPG, PNG, GIF).";
          }
      }
      // تحقق الحقول الأساسية
      if (empty($EmpID_post_str) || empty($EmpName) || empty($JobTitle) || empty($Username) || empty($Department)) {
          $error = "يرجى ملء جميع الحقول المطلوبة.";
      } else {
          // تحقق وجود الموظف
          $stmt = $conn->prepare("SELECT EmpID FROM Users WHERE EmpID = ?");
          if (!$stmt) {
              $error = "خطأ في تحضير استعلام التحقق: " . $conn->error;
          } else {
              $stmt->bind_param("i", $emp_id_post);
              $stmt->execute();
              $exists = $stmt->get_result()->fetch_assoc();
              $stmt->close();
              if ($exists) {
                  // تحديث
                  $update_success = false;
                  if (!empty($Password)) {
                      $hashed_password = password_hash($Password, PASSWORD_DEFAULT);
                      if (!empty($profile_image)) {
                          // UPDATE مع كلمة مرور وصورة: 24 param (ssssssiiiiiiisiss siiiii -> مصحح)
                          $stmt = $conn->prepare("UPDATE Users
                              SET EmpName=?, JobTitle=?, Username=?, Password=?, Department=?, Division=?,
                                  CanAdd=?, CanEdit=?, CanDelete=?, CanSendWhatsApp=?, Active=?, IsAdmin=?, IsLicenseManager=?,
                                  LeaveApproval=?, SectorID=?, Email=?, Phone=?, profile_image=?,
                                  follow_up_complaints=?, complaints_manager_rights=?, clinic_rights=?, warehouse_rights=?, super_admin_rights=?
                              WHERE EmpID=?");
                          if ($stmt) {
                              $stmt->bind_param(
                                  "ssssssiiiiiiisiss siiiii",  // مصحح: ssssss(6) + iiiiiii(7) + s i s s s(Leave i Sector s Email s Phone s image) + iiiii(5) + i(1) = 24
                                  $EmpName, $JobTitle, $Username, $hashed_password, $Department, $Division,
                                  $CanAdd, $CanEdit, $CanDelete, $CanSendWhatsApp, $Active, $IsAdmin, $IsLicenseManager,
                                  $LeaveApproval, $SectorID, $Email, $Phone, $profile_image,
                                  $follow_up_complaints, $complaints_manager_rights, $clinic_rights, $warehouse_rights, $super_admin_rights,
                                  $emp_id_post
                              );
                              $update_success = $stmt->execute();
                              $stmt->close();
                          }
                      } else {
                          // UPDATE مع كلمة مرور بدون صورة: 23 param (ssssssiiiiiiisissiiiii -> مصحح)
                          $stmt = $conn->prepare("UPDATE Users
                              SET EmpName=?, JobTitle=?, Username=?, Password=?, Department=?, Division=?,
                                  CanAdd=?, CanEdit=?, CanDelete=?, CanSendWhatsApp=?, Active=?, IsAdmin=?, IsLicenseManager=?,
                                  LeaveApproval=?, SectorID=?, Email=?, Phone=?,
                                  follow_up_complaints=?, complaints_manager_rights=?, clinic_rights=?, warehouse_rights=?, super_admin_rights=?
                              WHERE EmpID=?");
                          if ($stmt) {
                              $stmt->bind_param(
                                  "ssssssiiiiiiisissiiiii",  // مصحح: ssssss(6) + iiiiiii(7) + s i s s(Leave i Sector s Email s Phone) + iiiii(5) + i(1) = 23
                                  $EmpName, $JobTitle, $Username, $hashed_password, $Department, $Division,
                                  $CanAdd, $CanEdit, $CanDelete, $CanSendWhatsApp, $Active, $IsAdmin, $IsLicenseManager,
                                  $LeaveApproval, $SectorID, $Email, $Phone,
                                  $follow_up_complaints, $complaints_manager_rights, $clinic_rights, $warehouse_rights, $super_admin_rights,
                                  $emp_id_post
                              );
                              $update_success = $stmt->execute();
                              $stmt->close();
                          }
                      }
                  } else {
                      if (!empty($profile_image)) {
                          // UPDATE بدون كلمة مرور مع صورة: 23 param (sssssiiiiiiisiss siiii -> مصحح)
                          $stmt = $conn->prepare("UPDATE Users
                              SET EmpName=?, JobTitle=?, Username=?, Department=?, Division=?,
                                  CanAdd=?, CanEdit=?, CanDelete=?, CanSendWhatsApp=?, Active=?, IsAdmin=?, IsLicenseManager=?,
                                  LeaveApproval=?, SectorID=?, Email=?, Phone=?, profile_image=?,
                                  follow_up_complaints=?, complaints_manager_rights=?, clinic_rights=?, warehouse_rights=?, super_admin_rights=?
                              WHERE EmpID=?");
                          if ($stmt) {
                              $stmt->bind_param(
                                  "sssssiiiiiiisiss siiii",  // مصحح: sssss(5) + iiiiiii(7) + s i s s s(image) + iiiii(5) + i(1) = 23
                                  $EmpName, $JobTitle, $Username, $Department, $Division,
                                  $CanAdd, $CanEdit, $CanDelete, $CanSendWhatsApp, $Active, $IsAdmin, $IsLicenseManager,
                                  $LeaveApproval, $SectorID, $Email, $Phone, $profile_image,
                                  $follow_up_complaints, $complaints_manager_rights, $clinic_rights, $warehouse_rights, $super_admin_rights,
                                  $emp_id_post
                              );
                              $update_success = $stmt->execute();
                              $stmt->close();
                          }
                      } else {
                          // UPDATE بدون كلمة مرور بدون صورة: 22 param (sssssiiiiiiisissiiiii -> مصحح)
                          $stmt = $conn->prepare("UPDATE Users
                              SET EmpName=?, JobTitle=?, Username=?, Department=?, Division=?,
                                  CanAdd=?, CanEdit=?, CanDelete=?, CanSendWhatsApp=?, Active=?, IsAdmin=?, IsLicenseManager=?,
                                  LeaveApproval=?, SectorID=?, Email=?, Phone=?,
                                  follow_up_complaints=?, complaints_manager_rights=?, clinic_rights=?, warehouse_rights=?, super_admin_rights=?
                              WHERE EmpID=?");
                          if ($stmt) {
                              $stmt->bind_param(
                                  "sssssiiiiiiisissiiiii",  // مصحح: sssss(5) + iiiiiii(7) + s i s s + iiiii(5) + i(1) = 22
                                  $EmpName, $JobTitle, $Username, $Department, $Division,
                                  $CanAdd, $CanEdit, $CanDelete, $CanSendWhatsApp, $Active, $IsAdmin, $IsLicenseManager,
                                  $LeaveApproval, $SectorID, $Email, $Phone,
                                  $follow_up_complaints, $complaints_manager_rights, $clinic_rights, $warehouse_rights, $super_admin_rights,
                                  $emp_id_post
                              );
                              $update_success = $stmt->execute();
                              $stmt->close();
                          }
                      }
                  }
                  if ($update_success) {
                      $success = "تم تحديث بيانات الموظف بنجاح.";
                      header("Location: employee_form.php?EmpID=" . urlencode($EmpID_post_str));
                      exit;
                  } else {
                      $error = "خطأ أثناء التحديث: " . $conn->error;
                  }
              } else {
                  // إضافة جديدة
                  if (empty($Password)) {
                      $error = "كلمة المرور مطلوبة لإضافة موظف جديد.";
                  } else {
                      $hashed_password = password_hash($Password, PASSWORD_DEFAULT);
                      $insert_success = false;
                      if (!empty($profile_image)) {
                          // INSERT مع صورة: 24 param (issssssiiiiiiisiss siiii -> مصحح)
                          $stmt = $conn->prepare("INSERT INTO Users
                              (EmpID, EmpName, JobTitle, Username, Password, Department, Division,
                               CanAdd, CanEdit, CanDelete, CanSendWhatsApp, Active, IsAdmin, IsLicenseManager,
                               LeaveApproval, SectorID, Email, Phone, profile_image,
                               follow_up_complaints, complaints_manager_rights, clinic_rights, warehouse_rights, super_admin_rights)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                          if ($stmt) {
                              $stmt->bind_param(
                                  "issssssiiiiiiisiss siiii",  // مصحح: i + ssssss(6) + iiiiiii(7) + s i s s s(image) + iiiii(5) = 24
                                  $emp_id_post, $EmpName, $JobTitle, $Username, $hashed_password,
                                  $Department, $Division, $CanAdd, $CanEdit, $CanDelete, $CanSendWhatsApp, $Active, $IsAdmin, $IsLicenseManager,
                                  $LeaveApproval, $SectorID, $Email, $Phone, $profile_image,
                                  $follow_up_complaints, $complaints_manager_rights, $clinic_rights, $warehouse_rights, $super_admin_rights
                              );
                              $insert_success = $stmt->execute();
                              $stmt->close();
                          }
                      } else {
                          // INSERT بدون صورة: 23 param (issssssiiiiiiisissiiiii -> مصحح)
                          $stmt = $conn->prepare("INSERT INTO Users
                              (EmpID, EmpName, JobTitle, Username, Password, Department, Division,
                               CanAdd, CanEdit, CanDelete, CanSendWhatsApp, Active, IsAdmin, IsLicenseManager,
                               LeaveApproval, SectorID, Email, Phone,
                               follow_up_complaints, complaints_manager_rights, clinic_rights, warehouse_rights, super_admin_rights)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                          if ($stmt) {
                              $stmt->bind_param(
                                  "issssssiiiiiiisissiiiii",  // مصحح: i + ssssss(6) + iiiiiii(7) + s i s s + iiiii(5) = 23
                                  $emp_id_post, $EmpName, $JobTitle, $Username, $hashed_password,
                                  $Department, $Division, $CanAdd, $CanEdit, $CanDelete, $CanSendWhatsApp, $Active, $IsAdmin, $IsLicenseManager,
                                  $LeaveApproval, $SectorID, $Email, $Phone,
                                  $follow_up_complaints, $complaints_manager_rights, $clinic_rights, $warehouse_rights, $super_admin_rights
                              );
                              $insert_success = $stmt->execute();
                              $stmt->close();
                          }
                      }
                      if ($insert_success) {
                          $success = "تمت إضافة الموظف بنجاح.";
                          header("Location: employee_form.php?EmpID=" . urlencode($EmpID_post_str));
                          exit;
                      } else {
                          $error = "خطأ أثناء الإضافة: " . $conn->error;
                      }
                  }
              }
          }
      }
      $EmpID = $EmpID_post_str;
  }
  // --- جلب بيانات الموظف الحالي لعرضها في النموذج ---
  if (!empty($EmpID)) {
      $emp_id = intval($EmpID);
      $stmt = $conn->prepare("SELECT * FROM Users WHERE EmpID = ?");
      if (!$stmt) {
          $error = "خطأ في تحضير استعلام جلب بيانات الموظف: " . $conn->error;
      } else {
          $stmt->bind_param("i", $emp_id);
          $stmt->execute();
          $result = $stmt->get_result();
          if ($result->num_rows > 0) {
              $currentEmployee = $result->fetch_assoc();
              // هنا لا نعرض كلمة المرور المشفرة. نترك الحقل فارغًا أو نضع placeholder.
              // كلمة المرور المخزنة (الهاش) لا يجب أن تظهر للمستخدم.
              $currentEmployee['Password'] = ''; // تفريغ القيمة لضمان عدم عرض الهاش
          } else {
              $error = "الموظف بالرقم الإداري ($EmpID) غير موجود.";
              $currentEmployee = null; // لا يوجد موظف مطابق، اجعلها فارغة
              $EmpID = ''; // امسح الـ EmpID لفتح نموذج جديد
          }
          $stmt->close();
      }
  }
  // --- جلب الموظف السابق والتالي للتنقل ---
  $prevID = null;
  $nextID = null;
  if (!empty($EmpID)) {
      $emp_id = intval($EmpID);
      // الموظف السابق
      $stmt = $conn->prepare("SELECT EmpID FROM Users WHERE EmpID < ? ORDER BY EmpID DESC LIMIT 1");
      if (!$stmt) { $error .= " خطأ في تحضير استعلام السابق: " . $conn->error; } else {
          $stmt->bind_param("i", $emp_id);
          $stmt->execute();
          $result = $stmt->get_result();
          if ($result->num_rows > 0) {
              $prevID = $result->fetch_assoc()['EmpID'];
          }
          $stmt->close();
      }
      // الموظف التالي
      $stmt = $conn->prepare("SELECT EmpID FROM Users WHERE EmpID > ? ORDER BY EmpID ASC LIMIT 1");
      if (!$stmt) { $error .= " خطأ في تحضير استعلام التالي: " . $conn->error; } else {
          $stmt->bind_param("i", $emp_id);
          $stmt->execute();
          $result = $stmt->get_result();
          if ($result->num_rows > 0) {
              $nextID = $result->fetch_assoc()['EmpID'];
          }
          $stmt->close();
      }
  }
  // جلب القيم الفريدة للفلاتر من قاعدة البيانات
  $uniqueEmpIDs = [];
  $uniqueEmpNames = [];
  $uniqueJobTitles = [];
  $uniqueDepartments = [];
  $uniqueDivisions = [];
  $uniqueLeaveApprovals = [];
  // جلب البيانات للفلاتر
  $filter_sql = "SELECT DISTINCT EmpID, EmpName, JobTitle, Department, Division, LeaveApproval FROM Users ORDER BY EmpID";
  $filter_result = $conn->query($filter_sql);
  if ($filter_result && $filter_result->num_rows > 0) {
      while ($row = $filter_result->fetch_assoc()) {
          $uniqueEmpIDs[] = $row['EmpID'];
          $uniqueEmpNames[] = $row['EmpName'];
          $uniqueJobTitles[] = $row['JobTitle'];
          $uniqueDepartments[] = $row['Department'];
          $uniqueDivisions[] = $row['Division'];
          $uniqueLeaveApprovals[] = $row['LeaveApproval'];
      }
  }
  // إزالة القيم المكررة
  $uniqueEmpIDs = array_unique($uniqueEmpIDs);
  $uniqueEmpNames = array_unique($uniqueEmpNames);
  $uniqueJobTitles = array_unique($uniqueJobTitles);
  $uniqueDepartments = array_unique($uniqueDepartments);
  $uniqueDivisions = array_unique($uniqueDivisions);
  $uniqueLeaveApprovals = array_unique($uniqueLeaveApprovals);
  $conn->close(); // إغلاق الاتصال بقاعدة البيانات في نهاية السكريبت
  ?>
  <!DOCTYPE html>
  <html lang="ar" dir="rtl">
  <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>نموذج إدارة الموظفين</title>
      <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
      <style>
          /* نفس الـ CSS السابق، لم يتغير */
          :root {
              --primary-color: #2e7d32;
              --primary-light: #4caf50;
              --primary-dark: #1b5e20;
              --secondary-color: #f5f5f5;
              --text-color: #333;
              --text-light: #666;
              --border-color: #ddd;
              --error-color: #d32f2f;
              --success-color: #388e3c;
              --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
              --transition: all 0.3s ease;
          }
          * {
              box-sizing: border-box;
              margin: 0;
              padding: 0;
          }
          body {
              font-family: 'Tajawal', sans-serif;
              background-color: #f9f9f9;
              color: var(--text-color);
              line-height: 1.6;
              direction: rtl;
              padding: 0;
              margin: 0;
          }
          .top-nav {
              background-color: white;
              padding: 10px 20px;
              box-shadow: var(--shadow);
              text-align: left;
          }
          .top-nav a {
              background: #2196f3;
              color: white;
              padding: 8px 16px;
              text-decoration: none;
              border-radius: 4px;
              font-weight: 500;
              transition: var(--transition);
          }
          .top-nav a:hover {
              background: #1976d2;
          }
          .main-container {
              display: flex;
              flex-direction: column;
              max-width: 1400px;
              margin: 30px auto;
              gap: 20px;
              padding: 0 20px;
          }
          .form-container {
              background: white;
              padding: 25px;
              border-radius: 10px;
              box-shadow: var(--shadow);
              position: relative;
              overflow: hidden;
          }
          .table-container {
              background: white;
              padding: 25px;
              border-radius: 10px;
              box-shadow: var(--shadow);
              overflow: auto;
              max-height: 80vh;
          }
          h2 {
              text-align: center;
              color: var(--primary-color);
              margin-bottom: 20px;
              padding-bottom: 15px;
              border-bottom: 1px solid var(--border-color);
              font-size: 1.5rem;
              font-weight: 700;
              position: relative;
          }
          h2::after {
              content: '';
              position: absolute;
              bottom: -1px;
              right: 0;
              width: 100px;
              height: 2px;
              background: var(--primary-color);
          }
          .search-section {
              background: #f8f9fa;
              padding: 15px;
              border-radius: 8px;
              margin-bottom: 20px;
              display: flex;
              gap: 10px;
              border: 1px solid var(--border-color);
          }
          .search-section input, .search-section select {
              flex: 1;
              padding: 10px 12px;
              border: 1px solid var(--border-color);
              border-radius: 6px;
              font-size: 0.9rem;
              transition: var(--transition);
          }
          .search-section input:focus, .search-section select:focus {
              border-color: var(--primary-color);
              outline: none;
              box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2);
          }
          .search-section button {
              padding: 10px 20px;
              background: var(--primary-color);
              color: white;
              border: none;
              border-radius: 6px;
              cursor: pointer;
              font-size: 0.9rem;
              font-weight: 500;
              transition: var(--transition);
          }
          .search-section button:hover {
              background: var(--primary-dark);
              transform: translateY(-2px);
          }
          .form-grid {
              display: grid;
              grid-template-columns: 1fr 1fr;
              gap: 15px;
              margin-bottom: 15px;
          }
          .form-group {
              margin-bottom: 12px;
          }
          label {
              display: block;
              margin-bottom: 6px;
              font-weight: 500;
              color: var(--text-color);
              font-size: 0.9rem;
          }
          input, select {
              width: 100%;
              padding: 10px 12px;
              border: 1px solid var(--border-color);
              border-radius: 6px;
              font-size: 0.9rem;
              font-family: 'Tajawal', sans-serif;
              transition: var(--transition);
          }
          input:focus, select:focus {
              border-color: var(--primary-color);
              outline: none;
              box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2);
          }
          .password-group {
              display: flex;
              align-items: center;
              gap: 5px;
          }
          .password-group input {
              flex-grow: 1;
          }
          .password-group button {
              padding: 8px 12px;
              background: #607d8b;
              color: white;
              border: none;
              border-radius: 6px;
              cursor: pointer;
              font-size: 0.8rem;
              transition: var(--transition);
              white-space: nowrap;
          }
          .password-group button:hover {
              background: #455a64;
              transform: translateY(-1px);
          }
          .profile-image-section {
              grid-column: 1 / -1;
              text-align: center;
              margin-bottom: 15px;
          }
          .profile-image-preview {
              max-width: 150px;
              max-height: 150px;
              border-radius: 50%;
              border: 3px solid var(--border-color);
              margin-bottom: 10px;
          }
          .profile-image-upload {
              padding: 10px;
              background: var(--secondary-color);
              border: 2px dashed var(--border-color);
              border-radius: 8px;
              text-align: center;
          }
          .checkbox-section {
              background: #f8f9fa;
              padding: 15px;
              border-radius: 8px;
              margin: 20px 0;
              border: 1px solid var(--border-color);
          }
          .checkbox-group {
              display: flex;
              flex-wrap: wrap;
              gap: 15px;
          }
          .checkbox-item {
              display: flex;
              align-items: center;
              gap: 6px;
          }
          .checkbox-item input[type="checkbox"] {
              width: 16px;
              height: 16px;
              accent-color: var(--primary-color);
          }
          .checkbox-item label {
              margin: 0;
              font-weight: normal;
              cursor: pointer;
              font-size: 0.9rem;
          }
          .button-group {
              display: flex;
              justify-content: space-between;
              margin-top: 20px;
              flex-wrap: wrap;
              gap: 10px;
          }
          .nav-buttons {
              display: flex;
              gap: 8px;
          }
          button {
              padding: 10px 18px;
              border: none;
              border-radius: 6px;
              cursor: pointer;
              font-size: 0.9rem;
              font-weight: 500;
              transition: var(--transition);
              display: inline-flex;
              align-items: center;
              justify-content: center;
              gap: 6px;
          }
          button:hover {
              transform: translateY(-2px);
              box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
          }
          .save-btn {
              background: var(--primary-color);
              color: white;
          }
          .save-btn:hover {
              background: var(--primary-dark);
          }
          .nav-btn {
              background: var(--primary-light);
              color: white;
          }
          .nav-btn:hover {
              background: var(--primary-color);
          }
          .nav-btn:disabled {
              background: #b0bec5;
              cursor: not-allowed;
              transform: none;
              box-shadow: none;
          }
          .new-btn {
              background: #f57c00;
              color: white;
          }
          .new-btn:hover {
              background: #e65100;
          }
       
          .delete-btn {
              background: var(--error-color);
              color: white;
              margin-left: 10px;
          }
          .delete-btn:hover {
              background: #c62828;
          }
          .alert {
              padding: 12px;
              margin-bottom: 20px;
              border-radius: 6px;
              text-align: center;
              font-weight: 500;
              position: relative;
              padding-right: 40px;
              font-size: 0.9rem;
          }
          .alert::before {
              content: '!';
              position: absolute;
              right: 15px;
              top: 50%;
              transform: translateY(-50%);
              width: 22px;
              height: 22px;
              background: white;
              border-radius: 50%;
              display: flex;
              align-items: center;
              justify-content: center;
              font-weight: bold;
          }
          .error {
              background: #ffebee;
              color: var(--error-color);
              border: 1px solid #ffcdd2;
          }
          .error::before {
              color: var(--error-color);
          }
          .success {
              background: #e8f5e9;
              color: var(--success-color);
              border: 1px solid #c8e6c9;
          }
          .success::before {
              content: '✓';
              color: var(--success-color);
          }
          .employees-table {
              width: 100%;
              border-collapse: collapse;
              margin-top: 15px;
          }
          .employees-table th,
          .employees-table td {
              padding: 10px;
              text-align: right;
              border-bottom: 1px solid var(--border-color);
              font-size: 0.85rem;
          }
          .employees-table th {
              background-color: var(--primary-light);
              color: white;
              position: sticky;
              top: 0;
          }
          .employees-table tr:nth-child(even) {
              background-color: #f8f9fa;
          }
          .employees-table tr:hover {
              background-color: #e8f5e9;
          }
          .employees-table a {
              color: var(--primary-color);
              text-decoration: none;
              font-weight: 500;
          }
          .employees-table a:hover {
              text-decoration: underline;
          }
          .active-status {
              display: inline-block;
              width: 10px;
              height: 10px;
              border-radius: 50%;
              margin-left: 5px;
          }
          .active-true {
              background-color: var(--success-color);
          }
          .active-false {
              background-color: var(--error-color);
          }
          .profile-image-table {
              max-width: 50px;
              max-height: 50px;
              border-radius: 50%;
              border: 1px solid var(--border-color);
          }
          .table-filter {
              display: flex;
              gap: 10px;
              margin-bottom: 15px;
              align-items: center;
              flex-wrap: wrap;
          }
          .table-filter select {
              padding: 8px 12px;
              border: 1px solid var(--border-color);
              border-radius: 6px;
              font-size: 0.9rem;
              min-width: 150px;
          }
          .table-filter button {
              padding: 8px 15px;
          }
          .filter-group {
              display: flex;
              flex-direction: column;
              gap: 5px;
          }
          .filter-group label {
              font-size: 0.8rem;
              font-weight: 500;
              color: var(--text-light);
          }
          @media (max-width: 1024px) {
              .form-grid {
                  grid-template-columns: 1fr;
              }
          }
          @media (max-width: 768px) {
              .main-container {
                  padding: 0 15px;
                  margin: 15px auto;
              }
              .form-container, .table-container {
                  padding: 20px;
              }
              h2 {
                  font-size: 1.3rem;
              }
              .checkbox-group {
                  flex-direction: column;
                  gap: 10px;
              }
              .button-group {
                  flex-direction: column-reverse;
              }
              .nav-buttons {
                  width: 100%;
                  justify-content: space-between;
              }
              .button-group > div {
                  width: 100%;
              }
              button {
                  width: 100%;
                  margin-bottom: 5px;
              }
           
              .employees-table {
                  font-size: 0.8rem;
              }
           
              .employees-table th,
              .employees-table td {
                  padding: 8px 5px;
              }
              .table-filter {
                  flex-direction: column;
                  align-items: stretch;
              }
          }
          @media (max-width: 480px) {
              .form-container, .table-container {
                  padding: 15px;
              }
              h2 {
                  font-size: 1.2rem;
              }
          }
      </style>
  </head>
  <body>
      <div class="top-nav">
          <a href="/health_vet/public/index.php">الرئيسية</a>
      </div>
      <div class="main-container">
          <div class="form-container">
              <h2>نموذج إدارة الموظفين</h2>
           
              <?php if ($error): ?>
                  <div class="alert error"><?= htmlspecialchars($error) ?></div>
              <?php endif; ?>
           
              <?php if ($success): ?>
                  <div class="alert success"><?= htmlspecialchars($success) ?></div>
              <?php endif; ?>
           
              <div class="search-section">
                  <form method="get" style="display: flex; width: 100%; gap: 10px;">
                      <input type="text" name="EmpID_search" placeholder="🔍 ابحث بالرقم الإداري..." value="<?= htmlspecialchars($EmpID) ?>">
                      <button type="submit" name="search">بحث</button>
                  </form>
              </div>
           
              <form id="employeeForm" method="post" enctype="multipart/form-data">
                  <input type="hidden" name="action" value="save">
               
                  <div class="form-grid">
                      <div class="form-group">
                          <label for="EmpID">الرقم الإداري</label>
                          <input type="text" id="EmpID" name="EmpID" required value="<?= htmlspecialchars($currentEmployee['EmpID'] ?? '') ?>">
                      </div>
                   
                      <div class="form-group">
                          <label for="EmpName">اسم الموظف</label>
                          <input type="text" id="EmpName" name="EmpName" required value="<?= htmlspecialchars($currentEmployee['EmpName'] ?? '') ?>">
                      </div>
                   
                      <div class="form-group">
                          <label for="JobTitle">المسمى الوظيفي</label>
                          <select id="JobTitle" name="JobTitle" required>
                              <option value="">-- اختر --</option>
                              <?php foreach ($jobTitles as $title): ?>
                                  <option value="<?= htmlspecialchars($title) ?>" <?= ($currentEmployee['JobTitle'] ?? '') === $title ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($title) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                   
                      <div class="form-group">
                          <label for="Username">اسم المستخدم</label>
                          <input type="text" id="Username" name="Username" autocomplete="off" required value="<?= htmlspecialchars($currentEmployee['Username'] ?? '') ?>">
                      </div>
                   
                      <div class="form-group">
                          <label for="Password">كلمة المرور</label>
                          <div class="password-group">
                              <input type="password" id="Password" name="Password" autocomplete="new-password"
                                     value="" placeholder="أدخل كلمة مرور جديدة">
                              <button type="button" id="togglePassword">إظهار</button>
                          </div>
                          <small style="display: block; margin-top: 5px; color: var(--text-light); font-size: 0.8rem;">
                              كلمة المرور الحالية مشفرة ولا يمكن عرضها. لإعادة تعيين كلمة المرور، أدخل كلمة جديدة هنا.
                          </small>
                      </div>
                   
                      <div class="form-group">
                          <label for="Department">القسم</label>
                          <select id="Department" name="Department" required>
                              <option value="">-- اختر --</option>
                              <?php foreach ($departments as $dept): ?>
                                  <option value="<?= htmlspecialchars($dept) ?>" <?= ($currentEmployee['Department'] ?? '') === $dept ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($dept) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                   
                      <div class="form-group">
                          <label for="Division">الشعبة</label>
                          <select id="Division" name="Division">
                              <option value="">-- اختر --</option>
                              <?php foreach ($divisions as $div): ?>
                                  <option value="<?= htmlspecialchars($div) ?>" <?= ($currentEmployee['Division'] ?? '') === $div ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($div) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                   
                      <div class="form-group">
                          <label for="SectorID">معرف القطاع</label>
                          <input type="number" id="SectorID" name="SectorID" value="<?= htmlspecialchars($currentEmployee['SectorID'] ?? '') ?>">
                      </div>
                   
                      <div class="form-group">
                          <label for="Email">البريد الإلكتروني</label>
                          <input type="email" id="Email" name="Email" value="<?= htmlspecialchars($currentEmployee['Email'] ?? '') ?>">
                      </div>
                   
                      <div class="form-group">
                          <label for="Phone">التليفون</label>
                          <input type="text" id="Phone" name="Phone" value="<?= htmlspecialchars($currentEmployee['Phone'] ?? '') ?>">
                      </div>
                      <div class="profile-image-section">
                          <label for="profile_image">صورة الملف الشخصي</label>
                          <?php if (!empty($currentEmployee['profile_image'])): ?>
                              <img src="<?= htmlspecialchars($currentEmployee['profile_image']) ?>" alt="صورة الموظف" class="profile-image-preview">
                              <p>الصورة الحالية: <?= htmlspecialchars(basename($currentEmployee['profile_image'])) ?></p>
                          <?php else: ?>
                              <div class="profile-image-preview" style="background: var(--secondary-color); display: flex; align-items: center; justify-content: center; margin-bottom: 10px;">لا توجد صورة</div>
                          <?php endif; ?>
                          <input type="file" id="profile_image" name="profile_image" accept="image/*">
                          <small style="display: block; margin-top: 5px; color: var(--text-light); font-size: 0.8rem;">
                              رفع صورة جديدة (JPG, PNG, GIF) سيحل محل الصورة الحالية.
                          </small>
                      </div>
                  </div>
               
                  <div class="checkbox-section">
                      <h3 style="margin-bottom: 15px; color: var(--primary-color);">الصلاحيات</h3>
                      <div class="checkbox-group">
                          <div class="checkbox-item">
                              <input type="checkbox" name="CanAdd" id="CanAdd" <?= !empty($currentEmployee['CanAdd']) ? 'checked' : '' ?>>
                              <label for="CanAdd">صلاحية الإضافة</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="CanEdit" id="CanEdit" <?= !empty($currentEmployee['CanEdit']) ? 'checked' : '' ?>>
                              <label for="CanEdit">صلاحية التعديل</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="CanDelete" id="CanDelete" <?= !empty($currentEmployee['CanDelete']) ? 'checked' : '' ?>>
                              <label for="CanDelete">صلاحية الحذف</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="CanSendWhatsApp" id="CanSendWhatsApp" <?= !empty($currentEmployee['CanSendWhatsApp']) ? 'checked' : '' ?>>
                              <label for="CanSendWhatsApp">صلاحية إرسال واتساب</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="Active" id="Active" <?= !empty($currentEmployee['Active']) ? 'checked' : '' ?>>
                              <label for="Active">موظف نشط</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="IsAdmin" id="IsAdmin" <?= !empty($currentEmployee['IsAdmin']) ? 'checked' : '' ?>>
                              <label for="IsAdmin">صلاحيات مدير</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="IsLicenseManager" id="IsLicenseManager" <?= !empty($currentEmployee['IsLicenseManager']) ? 'checked' : '' ?>>
                              <label for="IsLicenseManager">مدير التراخيص</label>
                          </div>
                          <div class="checkbox-item">
                              <label for="LeaveApproval">صلاحية الموافقة على الإجازات:</label>
                              <select id="LeaveApproval" name="LeaveApproval">
                                  <?php foreach ($leaveApprovals as $la): ?>
                                      <option value="<?= htmlspecialchars($la) ?>" <?= ($currentEmployee['LeaveApproval'] ?? 'No') === $la ? 'selected' : '' ?>>
                                          <?= htmlspecialchars($la) ?>
                                      </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="follow_up_complaints" id="follow_up_complaints" <?= !empty($currentEmployee['follow_up_complaints']) ? 'checked' : '' ?>>
                              <label for="follow_up_complaints">متابعة الشكاوى</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="complaints_manager_rights" id="complaints_manager_rights" <?= !empty($currentEmployee['complaints_manager_rights']) ? 'checked' : '' ?>>
                              <label for="complaints_manager_rights">صلاحيات مدير الشكاوى</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="clinic_rights" id="clinic_rights" <?= !empty($currentEmployee['clinic_rights']) ? 'checked' : '' ?>>
                              <label for="clinic_rights">صلاحيات العيادة</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="warehouse_rights" id="warehouse_rights" <?= !empty($currentEmployee['warehouse_rights']) ? 'checked' : '' ?>>
                              <label for="warehouse_rights">صلاحيات المستودع</label>
                          </div>
                          <div class="checkbox-item">
                              <input type="checkbox" name="super_admin_rights" id="super_admin_rights" <?= !empty($currentEmployee['super_admin_rights']) ? 'checked' : '' ?>>
                              <label for="super_admin_rights">صلاحيات الإدارة العليا</label>
                          </div>
                      </div>
                  </div>
               
                  <div class="button-group">
                      <div class="nav-buttons">
                          <a href="?EmpID=<?= urlencode($prevID ?? '') ?>">
                              <button type="button" class="nav-btn" <?= empty($prevID) ? 'disabled' : '' ?>>السابق</button>
                          </a>
                          <a href="?EmpID=<?= urlencode($nextID ?? '') ?>">
                              <button type="button" class="nav-btn" <?= empty($nextID) ? 'disabled' : '' ?>>التالي</button>
                          </a>
                      </div>
                   
                      <div style="display: flex; gap: 8px;">
                          <button type="submit" class="save-btn">حفظ البيانات</button>
                          <a href="employee_form.php">
                              <button type="button" class="new-btn">موظف جديد</button>
                          </a>
                          <?php if (!empty($currentEmployee['EmpID'])): // يظهر زر الحذف فقط إذا كان الموظف موجودًا ?>
                              <button type="button" class="delete-btn" id="deleteEmployeeBtn">حذف الموظف</button>
                          <?php endif; ?>
                      </div>
                  </div>
              </form>
          </div>
       
          <div class="table-container">
              <h2>قائمة الموظفين</h2>
           
              <div class="table-filter">
                  <form method="get" style="display: flex; gap: 10px; width: 100%; align-items: center; flex-wrap: wrap;">
                      <input type="hidden" name="EmpID" value="<?= htmlspecialchars($EmpID) ?>">
                   
                      <div class="filter-group">
                          <label>الرقم الإداري</label>
                          <select name="filter_empid">
                              <option value="">-- الكل --</option>
                              <?php foreach ($uniqueEmpIDs as $id): ?>
                                  <option value="<?= htmlspecialchars($id) ?>" <?= ($_GET['filter_empid'] ?? '') == $id ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($id) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                   
                      <div class="filter-group">
                          <label>اسم الموظف</label>
                          <select name="filter_empname">
                              <option value="">-- الكل --</option>
                              <?php foreach ($uniqueEmpNames as $name): ?>
                                  <option value="<?= htmlspecialchars($name) ?>" <?= ($_GET['filter_empname'] ?? '') == $name ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($name) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                   
                      <div class="filter-group">
                          <label>المسمى الوظيفي</label>
                          <select name="filter_jobtitle">
                              <option value="">-- الكل --</option>
                              <?php foreach ($uniqueJobTitles as $title): ?>
                                  <option value="<?= htmlspecialchars($title) ?>" <?= ($_GET['filter_jobtitle'] ?? '') == $title ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($title) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                   
                      <div class="filter-group">
                          <label>القسم</label>
                          <select name="filter_department">
                              <option value="">-- الكل --</option>
                              <?php foreach ($uniqueDepartments as $dept): ?>
                                  <option value="<?= htmlspecialchars($dept) ?>" <?= ($_GET['filter_department'] ?? '') == $dept ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($dept) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                   
                      <div class="filter-group">
                          <label>الشعبة</label>
                          <select name="filter_division">
                              <option value="">-- الكل --</option>
                              <?php foreach ($uniqueDivisions as $div): ?>
                                  <option value="<?= htmlspecialchars($div) ?>" <?= ($_GET['filter_division'] ?? '') == $div ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($div) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                   
                      <div class="filter-group">
                          <label>صلاحية الإجازات</label>
                          <select name="filter_leaveapproval">
                              <option value="">-- الكل --</option>
                              <?php foreach ($uniqueLeaveApprovals as $la): ?>
                                  <option value="<?= htmlspecialchars($la) ?>" <?= ($_GET['filter_leaveapproval'] ?? '') == $la ? 'selected' : '' ?>>
                                      <?= htmlspecialchars($la) ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                   
                      <div class="filter-group" style="align-self: flex-end;">
                          <label>&nbsp;</label>
                          <button type="submit" name="filter">تصفية</button>
                      </div>
                   
                      <div class="filter-group" style="align-self: flex-end;">
                          <label>&nbsp;</label>
                          <a href="employee_form.php"><button type="button">إعادة تعيين</button></a>
                      </div>
                  </form>
              </div>
           
              <table class="employees-table">
                  <thead>
                      <tr>
                          <th>الصورة</th>
                          <th>الرقم الإداري</th>
                          <th>اسم الموظف</th>
                          <th>المسمى الوظيفي</th>
                          <th>القسم</th>
                          <th>الشعبة</th>
                          <th>صلاحية الموافقة على الإجازات</th>
                          <th>الحالة</th>
                          <th>الإجراءات</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php if (count($employees) > 0): ?>
                          <?php foreach ($employees as $employee): ?>
                              <tr>
                                  <td>
                                      <?php if (!empty($employee['profile_image'])): ?>
                                          <img src="<?= htmlspecialchars($employee['profile_image']) ?>" alt="صورة" class="profile-image-table">
                                      <?php else: ?>
                                          <span style="color: var(--text-light);">لا صورة</span>
                                      <?php endif; ?>
                                  </td>
                                  <td><?= htmlspecialchars($employee['EmpID']) ?></td>
                                  <td><?= htmlspecialchars($employee['EmpName']) ?></td>
                                  <td><?= htmlspecialchars($employee['JobTitle']) ?></td>
                                  <td><?= htmlspecialchars($employee['Department']) ?></td>
                                  <td><?= htmlspecialchars($employee['Division'] ?? '') ?></td>
                                  <td><?= htmlspecialchars($employee['LeaveApproval'] ?? 'No') ?></td>
                                  <td>
                                      <span class="active-status <?= $employee['Active'] ? 'active-true' : 'active-false' ?>"></span>
                                      <?= $employee['Active'] ? 'نشط' : 'غير نشط' ?>
                                  </td>
                                  <td>
                                      <a href="employee_form.php?EmpID=<?= urlencode($employee['EmpID']) ?>">تعديل</a>
                                  </td>
                              </tr>
                          <?php endforeach; ?>
                      <?php else: ?>
                          <tr>
                              <td colspan="9" style="text-align: center;">لا توجد بيانات متاحة</td>
                          </tr>
                      <?php endif; ?>
                  </tbody>
              </table>
          </div>
      </div>
      <script>
          document.addEventListener('DOMContentLoaded', function() {
              const deleteEmployeeBtn = document.getElementById('deleteEmployeeBtn');
              const passwordField = document.getElementById('Password');
              const togglePasswordBtn = document.getElementById('togglePassword');
              // JavaScript for password visibility toggle
              if (togglePasswordBtn) {
                  togglePasswordBtn.addEventListener('click', function() {
                      if (passwordField.type === 'password') {
                          passwordField.type = 'text';
                          togglePasswordBtn.textContent = 'إخفاء';
                      } else {
                          passwordField.type = 'password';
                          togglePasswordBtn.textContent = 'إظهار';
                      }
                  });
              }
              if (deleteEmployeeBtn) {
                  deleteEmployeeBtn.addEventListener('click', function() {
                      const empIDToDelete = document.getElementById('EmpID').value;
                      if (empIDToDelete && confirm('هل أنت متأكد من حذف هذا الموظف؟ لا يمكن التراجع عن هذا الإجراء.')) {
                          deleteEmployee(empIDToDelete);
                      }
                  });
              }
              async function deleteEmployee(empID) {
                  const formData = new FormData();
                  formData.append('action', 'delete');
                  formData.append('EmpID', empID);
                  try {
                      const response = await fetch(window.location.href, {
                          method: 'POST',
                          body: formData
                      });
                      const data = await response.json();
                      if (data.success) {
                          alert(data.msg);
                          window.location.href = 'employee_form.php';
                      } else {
                          alert('خطأ: ' + data.msg);
                      }
                  } catch (error) {
                      console.error('Error during deletion:', error);
                      alert('حدث خطأ أثناء الاتصال بالخادم للحذف.');
                  }
              }
          });
      </script>
  </body>
  </html>